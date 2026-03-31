# UFSC Compétitions — Audit d’architecture réelle + plan d’évolution sans régression

**Date :** 30/03/2026  
**Périmètre :** module `includes/competitions/*` du plugin `ufsc-licence-competition`.

---

## A) Diagnostic précis de l’existant

### 1) Architecture réelle identifiée (et déjà avancée)

#### Bootstrap et composition modulaire
- Le module compétitions charge explicitement ses dépendances coeur (Db, repos, services, front) puis ses dépendances admin (pages, tables, assets) via un bootstrap idempotent.  
  Fichiers clés : `includes/competitions/bootstrap.php`.
- Les upgrades DB et tâches cron d’archivage sont déjà branchées tôt (`plugins_loaded`, `admin_init`) avec garde-fous.  
  Fichiers clés : `includes/competitions/bootstrap.php`.

#### Back-office existant (menus/pages réellement présents)
Le menu admin expose déjà les pages demandées :
- Compétitions
- Catégories
- Inscriptions
- Import CSV
- Combats
- Timing Profiles
- Qualité
- Impression
- Estimation
- Paramètres
- Logs
- Guide
- Diagnostic Accès
- Inscriptions (Validation)

Ces pages sont enregistrées via `UFSC\Competitions\Admin\Menu` et classes `Admin/Pages/*`.  
Fichiers clés : `includes/competitions/Admin/Menu.php`, `includes/competitions/Admin/Pages/*.php`.

#### Front-office existant (réel, non théorique)
- Shortcodes :
  - `[ufsc_competitions]` (liste + filtres)
  - `[ufsc_competition]` (détail)
- Module d’inscriptions front branché via hook `ufsc_competitions_front_registration_box`.
- Gestion accès club + exports club/engagés + formulaires + actions `admin-post`.

Fichiers clés :
- `includes/competitions/Front/Front.php`
- `includes/competitions/Front/Shortcodes/CompetitionsListShortcode.php`
- `includes/competitions/Front/Shortcodes/CompetitionDetailsShortcode.php`
- `includes/competitions/Front/Entries/EntriesModule.php`
- `includes/competitions/Front/Entries/EntryFormRenderer.php`
- `includes/competitions/Front/Entries/EntryActions.php`

#### Services métier déjà existants (à réutiliser impérativement)
- Catégories/règles : `CategoryAssigner`, `CategoryPresetRegistry`, `WeightCategoryResolver`
- Combats/tableaux : `FightAutoGenerationService`, `BracketGenerator`, `PoolGenerator`, `StandingsCalculator`
- Timing/estimation : `CompetitionScheduleEstimator`, `TimingProfilePresetSeeder`
- Impression : `PrintRenderer`, `Plateau_Pdf_Renderer`, `Entries_Pdf_Renderer`
- Disciplines : `DisciplineRegistry`
- Qualité/log : `AuditLogger`, `LogService`

Fichiers clés : `includes/competitions/Services/*.php`.

#### Repositories existants
- `CompetitionRepository`, `CategoryRepository`, `EntryRepository`, `FightRepository`, `TimingProfileRepository`, `WeighInRepository`, `LogRepository`, `ClubRepository`.
- Front : `CompetitionReadRepository`, `EntryFrontRepository`.

Fichiers clés : `includes/competitions/Repositories/*.php`, `includes/competitions/Front/Repositories/*.php`.

### 2) Flux critiques existants à préserver (zéro régression)

1. **Flux inscriptions front** : recherche licencié UFSC → préremplissage → création/édition/suppression → soumission/retrait.  
   Points d’entrée : `EntriesModule`, `EntryActions`, `EntryFormRenderer`.
2. **Validation admin inscriptions** : statuts workflow (`draft`, `submitted/review_queue`, `approved`, `rejected`, etc.) et écrans de validation.
3. **Import CSV** : page dédiée + parsing + insertion via repo.
4. **Combats** : génération draft / validation / application / scheduling / surfaces.
5. **Impression/exports** : admin + front, avec nonces/capabilities.
6. **Filtres front** : saison, discipline, type, statut, recherche texte.
7. **Compat URL/front** : `competition_id` + compat `ufsc_competition_id`.

### 3) Couplages sensibles (zones à haut risque)
- Schéma DB entrées encore historiquement centré sur `licensee_id` non-null dans la création initiale de table (legacy), avec upgrades incrémentaux : risque si extension non licenciés sans migration prudente.  
  Fichier clé : `includes/competitions/Db.php`.
- Logique d’accès et validation inscriptions fortement couplée aux contrôles licence (front + access).  
  Fichiers clés : `Access/CompetitionAccess.php`, `Front/Entries/EntryActions.php`.
- Génération combats dépend des données normalisées (catégorie/poids/niveau/statut) : toute évolution des champs doit garder un mapping rétro-compatible.
- Front déjà consommé par shortcodes existants : pas de rupture de markup/identifiants clés sans fallback CSS/JS.

### 4) Ce qui est déjà bien en place
- Structure modulaire claire (Admin/Front/Services/Repositories).
- Garde-fous sécurité WordPress largement présents (nonce/capabilities/sanitize/escape/admin-post sécurisé).
- Services métiers existants exploitables immédiatement pour la montée en gamme.
- Assets CSS/JS déjà séparés admin/front (base utile pour refonte visuelle progressive).

### 5) Ce qui doit être amélioré en priorité
- Uniformisation UI premium admin (headers, KPI, badges, densité, tableaux).
- Front premium (liste/détail/zone inscription/“Vos inscriptions”).
- Référentiel UFSC centralisé et strict (âge/poids/timing/obligations/équipements).
- Support non licenciés UFSC sans casser le strict-mode actuel des licenciés.
- Contrôles qualité renforcés et plus actionnables.

---

## B) Plan d’évolution (progressif, anti-régression)

## Principes de migration
1. **Conserver les points d’entrée actuels** (pages, shortcodes, actions admin-post/AJAX).
2. **Introduire des services d’orchestration** derrière l’existant (pas de remplacement brutal).
3. **Feature flags/fallbacks** sur nouveautés structurantes (non licenciés, référentiel strict).
4. **Compat DB ascendante** : migrations additives uniquement, jamais destructives.
5. **Déploiement par lots courts** + checklist anti-régression à chaque lot.

## Lots proposés (ordre recommandé)

### Lot 0 — Baseline sécurité/régression (pré-requis)
- Cartographie des flux existants (ce document) + matrice de tests.
- Ajout d’outils internes de compatibilité (helpers de résolution de champs)
- Aucun changement fonctionnel visible.

### Lot 1 — Design system premium sans rupture
- Introduire un style system commun admin/front :
  - classes utilitaires (`ufsc-card`, `ufsc-kpi`, `ufsc-badge`, `ufsc-toolbar`, `ufsc-empty-state`)
  - skins progressifs sur pages existantes
- Ne pas casser les `wp-list-table` et formulaires WordPress natifs.

### Lot 2 — Référentiel UFSC centralisé
- Ajouter un service noyau (ex: `UFSCReferenceService`) couvrant :
  - tranches âge
  - catégories poids
  - timings
  - obligations médicales
  - équipements
- Brancher ce service dans `CategoryAssigner`, `WeightCategoryResolver`, `CompetitionScheduleEstimator`, `EntryEligibility` en mode **compat + override**.

### Lot 3 — Non licenciés UFSC (mode contrôlé)
- Ajouter type participant (`licensed`, `non_licensed`) + réglage par compétition (`licensed_only`, `mixed`, `open`).
- Extension DB additive (colonnes nullable + valeurs par défaut non cassantes).
- UI/front/admin : badge visuel explicite + validations adaptées.
- Garder le flux licencié strict intact si compétition/licence l’exige.

### Lot 4 — Module combats premium
- Surcouche UX admin de génération (résumé éligibles, aperçus, brouillon/validé/verrouillé).
- Brancher référentiel timings/surface/discipline dans génération et estimation.
- Conserver moteur actuel en backend, ajouter couche orchestration/présentation.

### Lot 5 — Qualité/Impression/Estimation premium
- Quality Center gravité + filtres + liens de correction.
- Templates impression modernisés (sans casser endpoints export/PDF actuels).
- Estimation planning enrichie (surfaces, pauses, entractes, fin estimée).

---

## C) Implémentation (phase exécutée dans ce lot)

### Ce qui est implémenté dans ce commit
- **Aucun changement métier/UX en production** (volontaire).
- Ajout d’un **audit d’architecture réel + plan de migration** pour cadrer les développements sans régression avant toute modification de code.

### Fichier ajouté
- `audits/competition-evolution-audit-plan-2026-03-30.md`

### Pourquoi ce choix
- Votre exigence “**auditer d’abord, coder ensuite**” est respectée.
- Cette base évite une implémentation parallèle qui ignorerait les classes/services/pages déjà présentes.

---

## D) Vérifications anti-régression (pré-codage)

### Matrice minimale à exécuter à chaque lot
1. **Back-office**
   - Création/édition compétition
   - Filtres listes compétitions/inscriptions
   - Import CSV
   - Validation inscriptions
   - Génération combats + brouillon/validation
   - Impression + exports
   - Estimation + timing profiles
2. **Front-office**
   - Liste compétitions + filtres
   - Détail compétition via `competition_id` et `ufsc_competition_id`
   - Bloc “Vos inscriptions”
   - Formulaire ajout inscription avec recherche licencié
   - Exports club/engagés
3. **Sécurité/compat**
   - Nonces admin-post et AJAX
   - Capabilities/scope club
   - Slugs pages/menu inchangés
   - Compat schéma DB legacy

### Fallbacks obligatoires
- Si référentiel UFSC incomplet/non chargé : fallback sur logique existante.
- Si non-licencié non activé sur compétition : comportement actuel strict licencié conservé.
- Si nouveaux champs absents en base : lecture legacy conservée.

---

## E) Résultat UX attendu (cible, sans rupture)

### Back-office
- En-têtes premium homogènes, KPI utiles, tableaux lisibles, badges métier, filtres rapides.
- Pages Combats/Qualité/Estimation transformées en outils d’aide à décision opérationnels.

### Front-office
- Liste et détail compétition modernisés (cartes, badges, sections mieux hiérarchisées).
- Zone inscription plus guidée, rassurante, avec meilleur feedback temps réel.
- “Vos inscriptions” plus exploitable (statuts, actions, export clair).

### Bénéfices métier
- Référentiel UFSC appliqué de manière uniforme et contrôlable.
- Gestion officielle des non-licenciés sans casser la filière licenciés.
- Génération combats plus robuste, pilotable, imprimable “jour J”.

---

## Conclusion opérationnelle
Ce plugin dispose déjà d’une architecture solide et d’un socle fonctionnel riche. La stratégie recommandée est une **refonte progressive guidée par services existants**, avec migrations additives, feature flags et checklists anti-régression systématiques.
