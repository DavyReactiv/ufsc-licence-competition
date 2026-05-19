# UFSC Licence Competition

Plugin WordPress métier UFSC pour piloter **deux modules complémentaires** :

1. **Module Licences / UFSC Licences**
2. **Module Compétitions / Licence Competition**

Le plugin est conçu pour un usage opérationnel réel (administration UFSC, organisateurs, officiels, maintenance technique), avec garde-fous de sécurité, audit et workflows prudents.

---

## Présentation générale

Le plugin permet de gérer un cycle complet :

- licences UFSC ;
- imports ASPTT ;
- statuts et paramètres licences ;
- compétitions et catégories ;
- inscriptions (saisie/import/validation) ;
- pesées et contrôles ;
- génération sécurisée des combats (diagnostic, brouillon, preview, snapshot, rollback, verrou) ;
- pilotage terrain via **Plateau jour J** ;
- saisie/correction sécurisée des résultats ;
- impressions et documents de synthèse ;
- logs d’audit des actions sensibles.

Le module conserve des mécanismes de fallback pour rester compatible avec des schémas UFSC historiques.

---

## Module Licences / UFSC Licences

Le module Licences permet de consulter, importer, suivre et administrer les licences liées à l’écosystème UFSC. Il sert de base opérationnelle pour les licenciés, les imports ASPTT, les statuts et les passerelles avec le module compétition.

### Sous-menus (Licences)

- **UFSC Licences** : vue d’ensemble du module licences et accès aux outils principaux.
- **Import ASPTT** : import/synchronisation des données ASPTT avec contrôle des colonnes et intégration des données.
- **UFSC LC — Status** : page de statut technique (configuration, tables, dépendances, anomalies).
- **Paramètres** : configuration générale du module licences.
- **Licences** : consultation/gestion des licences et données licenciés utiles au module compétition.

### Fonctionnalités du module Licences

- consultation des licences ;
- import ASPTT ;
- contrôle des données importées ;
- statuts de licences ;
- page de diagnostic/status ;
- paramètres dédiés ;
- compatibilité avec données historiques ;
- pont avec les inscriptions compétition ;
- récupération des informations licenciés ;
- récupération de date de naissance (si utilisée en compétition) ;
- données de vérification pour participants/combattants.

---

## Relation entre Licences et Compétitions

Le module compétition peut s’appuyer sur les données licences pour préremplir ou vérifier certaines informations des inscrits : identité, licence, club, date de naissance, sexe, catégorie, statut, etc.

Lorsque certaines informations ne sont pas disponibles directement sur une inscription compétition, le plugin utilise des fallbacks vers la licence liée afin d’améliorer la fiabilité des diagnostics, des impressions et de la génération.

Ces fallbacks sont pensés pour rester compatibles avec les schémas historiques UFSC.

---

## Module Compétitions / Licence Competition

### Sous-menus (Compétitions)

- **Compétitions** : création, configuration et suivi des compétitions.
- **Catégories** : gestion des catégories sportives (poids, âge, sexe, niveau, format).
- **Inscriptions** : gestion des participants inscrits.
- **Import CSV** : import des fichiers d’inscription.
- **Pesées** : contrôle poids/statuts de pesée, numéro combattant, reclassement.
- **Combats** : gestion, suivi, génération et résultats des combats.
- **Timing Profiles** : profils de timing de planification.
- **Qualité** : contrôles de cohérence et diagnostics métier.
- **Impression** : documents imprimables (inscrits, catégories, surfaces, résultats, podiums provisoires).
- **Officiels** : gestion/affichage des officiels (selon configuration disponible).
- **Actions sensibles** : opérations protégées (régénération, rollback, actions à risque).
- **Estimation** : estimation de durée/volumes d’organisation.
- **Paramètres** : configuration du module compétitions.
- **Logs** : journal d’audit des actions importantes.
- **Aide** : aide d’utilisation et documentation interne.
- **Diagnostic Accès** : vérification des permissions et accès.
- **Inscriptions (Validation)** : validation administrative des inscriptions.

### Fonctionnalités actuellement présentes (Compétitions)

- CRUD compétitions (discipline, saison, dates, statuts, tolérance pesée, formats autorisés) ;
- inscriptions front/admin (licencié UFSC + participant externe) ;
- import CSV avec dry-run et rollback du lot ;
- pesées + numéro combattant + contrôle unicité applicatif ;
- statuts pesée et reclassement ;
- génération de combats par brouillon/preview (tableaux, BYE, placeholders, poules) ;
- correction supervisée de résultats avec audit ;
- plateau jour J par surface ;
- impressions opérationnelles ;
- logs/audit structurés.

---

## Workflow global recommandé

1. Importer ou vérifier les licences.
2. Contrôler les statuts licences.
3. Configurer les paramètres licences.
4. Créer une compétition.
5. Importer ou saisir les inscriptions.
6. Vérifier les données licenciés / participants.
7. Valider les inscriptions.
8. Réaliser les pesées.
9. Générer le brouillon des combats.
10. Vérifier la preview.
11. Valider la génération réelle.
12. Utiliser le Plateau jour J.
13. Saisir les résultats.
14. Imprimer les documents.
15. Vérifier les podiums provisoires.
16. Archiver / clôturer.

---

## Sécurité et garde-fous

- nonces sur actions sensibles ;
- capabilities fines (génération, régénération, pesées, résultats, plateau, suppressions sensibles) ;
- snapshots avant opérations destructrices ;
- rollback ciblé en cas d’échec ;
- soft-delete pour éviter les pertes irréversibles ;
- logs d’audit structurés ;
- corrections de résultats supervisées ;
- génération directe non previewée désactivée ;
- preview/brouillon obligatoire ;
- podiums affichés en mode provisoire quand les données sont incertaines.

### Module Compétitions — Génération sécurisée

- Diagnostic avant génération (bloquants + warnings).
- Preview / brouillon obligatoire avant création réelle.
- Snapshot avant application réelle du brouillon.
- Rollback ciblé tenté en cas d’échec SQL partiel.
- Verrou post-génération pour éviter des modifications dangereuses.
- BYE et placeholders lisibles en preview (ex: « Vainqueur combat X »).
- Poules round-robin simples (3 à 6 combattants) avec warnings métier.
- Warnings même club (best effort, non bloquant).
- Warnings repos athlète quand des combats sont rapprochés.
- Sandbox de test maintenue pour scénarios de génération.
- Aucune génération directe non prévisualisée.

### Formats actuellement pris en charge

- Combat simple.
- Tableau avec BYE.
- Tableau multi-tours avec placeholders.
- Poule round-robin simple.
- Formats supérieurs/avancés signalés par warning si non supportés.

### Sécurité des données

- Les combats réels sont créés uniquement après validation d’un brouillon.
- Un snapshot est créé avant application.
- Un rollback ciblé est tenté en cas d’erreur.
- Les suppressions définitives sont protégées par capability + confirmation.
- Les pesées validées et résultats terminés sont protégés par des garde-fous dédiés.

### Régénération contrôlée

Le module permet de préparer une régénération ciblée par catégorie ou groupe. Cette action est protégée par capability, preview obligatoire, snapshot ciblé, confirmation forte, motif obligatoire et rollback ciblé. Les combats terminés, en cours ou verrouillés bloquent la régénération.

La régénération globale reste protégée et ne doit pas être utilisée sans action sensible explicite.

En cas d’échec d’une tentative ciblée, le rollback tente de restaurer les anciens combats mis en corbeille pendant cette tentative et d’annuler les nouveaux combats éventuellement insérés. Les logs d’audit détaillent les IDs planifiés/trashed/restaurés/insérés, le motif, le snapshot et le scope utilisé (category_id, avec préparation group_key si disponible). Le scope applicatif reste category_id tant que group_key n’est pas fiable partout.

La régénération ciblée complète insère désormais les nouveaux combats du scope après mise en corbeille des anciens combats candidats. Les IDs insérés sont trackés ; en cas d’échec, ces nouveaux combats sont annulés et les anciens combats de la tentative sont restaurés.

---

## Résultats sécurisés

Le module propose une saisie de résultats encadrée et une correction supervisée. Les combats BYE, placeholders, supprimés (trashed) ou verrouillés sont protégés. Toute correction d’un combat terminé nécessite une capability dédiée, un motif et un log d’audit.

Les actions de résultat sont traitées via un service central (`ResultService`) qui valide les payloads, enregistre/corrige les résultats, peut verrouiller un résultat terminé, et produit des traces d’audit structurées.

## Podiums provisoires et classements

Le module peut produire des synthèses de résultats, classements de poules et podiums provisoires. Les données sont affichées avec prudence : en cas de résultat manquant, litige, égalité ou bracket incomplet, le document indique “à vérifier” ou “provisoire”. Les podiums ne doivent être considérés comme officiels qu’après validation manuelle.

## Documents résultats

Des documents HTML imprimables peuvent être générés : résultats par catégorie, podiums provisoires, classements de poules, rapport des litiges, absences, forfaits et combats sans résultat.

---

## Limites connues

- Certains podiums restent provisoires et nécessitent validation humaine.
- Les égalités de poule complexes ne sont pas encore toutes départagées automatiquement.
- La propagation bracket complète reste prudente.
- `group_key` est préparé mais le scope applicatif principal reste `category_id`.
- Certains champs historiques nécessitent des fallbacks.
- Les tests runtime WordPress restent indispensables avant production.

---

## Déploiement recommandé

1. Déployer d’abord en préproduction.
2. Tester le module licences.
3. Tester le module compétitions.
4. Tester Sandbox / OPEN150.
5. Tester les rôles et permissions.
6. Tester les impressions.
7. Tester les résultats.
8. Passer en production seulement après recette complète.

---

## Notes de mise en production

### Prérequis
- WordPress >= 6.x.
- PHP >= 7.4 (8.x recommandé).
- Tables UFSC licences/clubs disponibles si pont licences activé.

### Vérifications pré-prod recommandées
- Unicité numéro combattant en pesée.
- Présence numéro combattant sur vues combats/impressions.
- Lisibilité placeholders avant résultat.
- Remplacement cohérent après saisie/correction résultat.
- Contrôle des logs d’audit sur actions sensibles.
- Vérification de non-régression des impressions existantes.

### Vigilances
- Éviter les modifications de structure lourdes juste avant événement.
- Prioriser les correctifs additifs/reversibles.
- Conserver les conventions de nommage/status existantes pour éviter les ruptures de workflow terrain.

---

## FAQ technique rapide

**Où gérer la pesée ?**
- Admin `Compétitions > Pesées`.

**Comment se propage le numéro combattant ?**
- Résolution prioritaire sur l’objet courant (`fighter_number` / `competition_number`), fallback via pesée (`weighins.notes`), puis fallback d’affichage explicite selon la vue.

**Comment fonctionnent les placeholders de combats futurs ?**
- `FightDisplayService` calcule la phase et les références de combats précédents pour afficher « Vainqueur combat X » et variantes.

**Comment corriger un résultat ?**
- Action `Corriger le résultat` dans la liste des combats, motif obligatoire, supervision si impacts joués, journalisation automatique.

**Qu’est-ce qui est strict vs heuristique ?**
- Strict : permissions, nonces, contrôle saisie, unicité numéro combattant.
- Heuristique contrôlée : certaines dépendances bracket déduites par `round_no/fight_no`.

## Plateau jour J

Le module propose une vue admin dédiée au pilotage live des combats par surface. Les organisateurs peuvent suivre les combats prévus, appelés, en cours, terminés, retardés, absents ou en litige, puis déclencher des actions rapides (appel, lancement, clôture, retard, absence, litige, annulation, changement de surface).

Toutes les actions passent par nonce + capability + contrôles de cohérence métier et sont journalisées dans l’audit. Les combats terminés/verrouillés/trashed, ainsi que les BYE et placeholders, sont protégés contre les transitions incohérentes.

## Statuts plateau

- `scheduled` : combat prévu ;
- `called` : combat appelé ;
- `running` : combat en cours ;
- `completed` : combat terminé ;
- `delayed` : combat retardé ;
- `absent` : combattant absent ;
- `disputed` : litige ;
- `cancelled` : combat annulé ;
- `locked` : combat verrouillé.

## Résultats sécurisés

Le module propose une saisie de résultats encadrée et une correction supervisée. Les combats BYE, placeholders, supprimés (trashed) ou verrouillés sont protégés. Toute correction d’un combat terminé nécessite une capability dédiée, un motif et un log d’audit.

Les actions de résultat sont traitées via un service central (`ResultService`) qui valide les payloads, enregistre/corrige les résultats, peut verrouiller un résultat terminé, et produit des traces d’audit structurées.

## Podiums et documents

Les podiums et documents officiels sont générés de manière prudente. Les sorties peuvent être marquées provisoires quand les données de bracket/poule nécessitent une vérification manuelle (propagation incomplète, litiges, absences).

Les impressions existantes restent compatibles; la consolidation automatique avancée des podiums reste progressive par lots.

## Podiums provisoires et classements

Le module peut produire des synthèses de résultats, classements de poules et podiums provisoires. Les données sont affichées avec prudence : en cas de résultat manquant, litige, égalité ou bracket incomplet, le document indique “à vérifier” ou “provisoire”. Les podiums ne doivent être considérés comme officiels qu’après validation manuelle.

## Documents résultats

Des documents HTML imprimables peuvent être générés : résultats par catégorie, podiums provisoires, classements de poules, rapport des litiges, absences, forfaits et combats sans résultat.
