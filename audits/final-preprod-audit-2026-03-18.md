# Audit final pré-production — UFSC Licence Competitions

**Date d’audit :** 18/03/2026  
**Portée auditée :** plugin `ufsc-licence-competition` (licences + compétitions + inscriptions + combats + tableaux + impressions/exports + espaces club/admin).  
**Méthode :** audit technique de code réel + vérifications syntaxiques CLI + revue de flux métiers critiques + revue sécurité/scope/ownership + revue inter-modules.

---

## 1) Diagnostic global de readiness production

### Verdict global
**PRÊT SOUS RÉSERVE DE CORRECTIONS MINEURES**.

### Raisons
- Le bootstrap principal est défensif (guard ABSPATH, singleton de chargement, constantes définies tôt, dépendances chargées explicitement).  
- Les contrôles de sécurité (nonce/capabilities/scope club) sont globalement présents sur les actions sensibles admin/front (exports, actions inscriptions, AJAX).  
- Les flux critiques licences/inscriptions/exports sont codés avec garde-fous métier (ownership club, anti-doublon, statuts normalisés, filtres d’éligibilité).  
- Aucune erreur de syntaxe PHP/JS détectée lors des checks CLI.

**Réserves** : quelques points de robustesse pré-prod doivent être traités avant release pour réduire le risque de régression silencieuse sur installations hétérogènes.

---

## 2) Contrôles réalisés (concrets)

### Contrôles automatiques exécutés
1. **Lint PHP complet** (tous les fichiers `.php`) : OK.
2. **Syntax check JS** (tous les fichiers `.js`) : OK.
3. **Scan ciblé sécurité** sur nonces/capabilities/prepare SQL/superglobales : résultats cohérents globalement.
4. **Revue manuelle ciblée** des composants critiques :
   - bootstrap plugin,
   - bootstrap compétitions,
   - sécurité d’accès compétition,
   - actions front inscriptions,
   - export club/front,
   - migrations DB entrées.

### Contrôles non exécutables ici (à faire sur environnement WordPress de pré-prod)
- Flux E2E réels navigateur (admin + club).
- Exécution AJAX réelle (`admin-ajax.php`) avec session WP.
- Contrôle logs runtime PHP/JS/AJAX sur scénarios métier volumétriques.
- Contrôle SQL runtime (temps de réponse / plans d’exécution / charge).

---

## 3) Points validés

1. **Chargement plugin et bootstrap défensif** validés (protection double chargement + constantes + init centralisé).  
2. **Gestion des dépendances critiques** (`ufsc_licences`, `ufsc_clubs`) et notice admin en cas d’absence : validée.  
3. **Mécanisme de migration/versionning DB** présent pour le module compétitions + index essentiels : validé.  
4. **Actions sensibles front inscriptions** : vérification login + capability + club + nonce + ownership entrée : validé.  
5. **Exports club/front** : verrouillage anti-IDOR (`club_id` externe bloqué) + nonce + vérification club courant : validé.  
6. **Sécurité SQL** : usage majoritaire de requêtes préparées et sanitization entrée utilisateur : validé sur zones critiques revues.  
7. **Anti-doublon inscriptions** : service dédié + index unique géré sous conditions + gestion conflits duplicate key : validé côté design.

---

## 4) Anomalies restantes (classées)

## A. Bloquant prod
**Aucun bloquant strict identifié dans cette passe statique.**

## B. Majeur
### M-01 — Risque de dérive schéma non auto-réparée si option DB déjà à jour
- **Description** : la migration entrées (`maybe_upgrade_entries_table`) n’est exécutée que si `DB_VERSION_OPTION` diffère de `DB_VERSION`. En cas d’installation partiellement divergente mais option déjà positionnée à la version courante, la correction de schéma peut ne pas se rejouer automatiquement.  
- **Impact métier** : risque de comportements incohérents sur certaines instances (colonnes/index manquants ⇒ fonctionnalités partielles ou erreurs SQL contextuelles).  
- **Impact technique** : régression silencieuse selon historique des bases (multi-sites/legacy/restore).  
- **Zone/fichier** : `includes/competitions/Db.php`.
- **Risque de régression** : **élevé** sur environnements hétérogènes.
- **Recommandation** : ajouter un mode “self-heal safe” (ex. vérification minimale structurelle au boot admin 1x/jour même si version égale, sans opération destructive).

## C. Mineur
### m-01 — Verrou de migration trop court (10s)
- **Description** : le transient lock d’upgrade DB est fixé à 10 secondes.
- **Impact métier** : faible en environnement normal, mais sur infra lente/DB chargée, possible chevauchement d’exécutions concurrentes.
- **Impact technique** : bruit logs / double tentative DDL sur fortes charges.
- **Zone/fichier** : `includes/competitions/Db.php`.
- **Risque de régression** : faible à modéré.
- **Recommandation** : passer lock à 60–120s et conserver le garde-fou idempotent.

### m-02 — Couverture anti-régression automatisée insuffisante
- **Description** : absence de suite automatisée (PHPUnit/e2e) exploitable dans le repo courant ; la validation est principalement manuelle (checklists markdown).
- **Impact métier** : augmentation du risque de régression silencieuse entre lots correctifs.
- **Impact technique** : détection tardive en pré-prod/prod.
- **Zone/fichier** : `tests/competitions-entries-checklist.md`, `tests/lot2-dedup-manual-checklist.md`.
- **Risque de régression** : modéré.
- **Recommandation** : au minimum, script CI de smoke tests WP-CLI + assertions DB clés + scénarios export/inscription.

## D. Amélioration confort
### c-01 — Journalisation accès potentiellement verbeuse
- **Description** : logs d’accès détaillés via `error_log` peuvent devenir volumineux si activés sur forts volumes.
- **Impact métier** : faible.
- **Impact technique** : bruit d’exploitation / rotation logs.
- **Zone/fichier** : `includes/competitions/Access/CompetitionAccess.php`.
- **Risque de régression** : faible.
- **Recommandation** : conditionner davantage la verbosité (feature flag environnement + sampling).

---

## 5) Audit inter-modules (résumé)

- **Licences ↔ Inscriptions** : pont licence présent, recherche/licence liée/strict mode gérés, fallback prudent côté création entrée.
- **Inscriptions ↔ Combats/Tableaux** : structure de données et repos séparés cohérents ; vigilance à garder sur intégrité `competition_id`, `category_id`, `licensee_id` lors imports legacy.
- **Statuts ↔ Exports/Impressions** : filtrages de statuts et eligibility hooks présents ; sécurisation ownership club côté export front correcte.
- **Admin ↔ Club** : séparation des actions et contrôles capability/club globalement robuste.

Aucun conflit croisé bloquant détecté à la lecture, mais revalidation E2E indispensable sur pré-prod avec base réaliste.

---

## 6) Tests critiques réussis / à refaire

### Réussis (ici)
- Lint PHP global : réussi.
- Vérification syntaxe JS : réussi.
- Audit statique sécurité (nonce/cap/scope/prepare) : réussi globalement.

### À refaire impérativement (pré-prod WP réel)
- Parcours E2E complet licence → inscription → validation → combat → tableau → impression/export.
- Cas limites métier (licence expirée/rejetée, catégorie absente, poids absent, compétition sans catégories, clubs sans licenciés actifs).
- Tests de charge raisonnable sur recherche licenciés, exports, génération combats/tableaux.
- Test d’isolement cross-club (lecture/modification/export).

---

## 7) Risques résiduels

1. **Schémas legacy partiellement divergents** malgré version DB “à jour” (risque principal).  
2. **Régressions silencieuses inter-lots** faute d’automatisation de non-régression.  
3. **Risque volumétrique** non mesuré en runtime réel (exports/génération combats).

---

## 8) Checklist de validation avant prod (go/no-go)

- [x] Activation / chargement plugin (audit code + lint)
- [x] Bootstrap + dépendances de base
- [x] Menus/pages admin déclarés
- [x] Front club (shortcodes/modules) branché
- [x] Contrôles nonce/capability sur actions sensibles principales
- [x] Ownership club sur exports front
- [x] Anti-doublon inscriptions (design + index)
- [x] Aucune erreur syntaxique PHP/JS détectée
- [ ] Validation E2E admin réelle (à exécuter)
- [ ] Validation E2E club réelle (à exécuter)
- [ ] Vérification runtime sans erreurs PHP/JS/AJAX sur pré-prod
- [ ] Vérification perfs charge raisonnable sur base réaliste
- [ ] Vérification impressions jour J (lisibilité opérationnelle)
- [ ] Vérification anti-fuite cross-club sur scénarios malveillants

---

## 9) Plan de patchs sûrs proposé (petits lots, sans refonte)

1. **Patch 1 (prioritaire)** : self-heal DB léger si schéma entrées incomplet même version égale.
2. **Patch 2** : allonger lock transient migration DB.
3. **Patch 3** : ajouter smoke test WP-CLI minimal (audit schéma + 2 parcours critiques).

---

## 10) Verdict final

### **Prêt sous réserve de corrections mineures**

- Pas de signal bloquant immédiat dans cette passe d’audit technique.
- Faire **Patch 1 + Patch 2** avant release.
- Exécuter la checklist E2E pré-prod sur base réaliste puis GO prod.
