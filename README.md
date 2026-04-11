# UFSC Licence Competition

Plugin WordPress métier pour la gestion opérationnelle des compétitions UFSC (inscriptions, pesées, génération de combats, résultats, impressions, supervision admin).

## 1) Présentation

Le plugin couvre le cycle admin de production d’une compétition :
- préparation et configuration de la compétition,
- gestion des inscriptions (front/admin),
- contrôle terrain (pesées + numéro combattant),
- génération et pilotage des combats,
- saisie / correction supervisée des résultats,
- sorties imprimables (inscrits, catégories, combats par surface),
- journalisation des actions sensibles.

Le module est conçu pour rester compatible avec des schémas UFSC historiques (fallbacks colonnes/labels) et limiter les ruptures en production.

## 2) Fonctionnalités actuellement présentes

### Compétitions
- CRUD compétitions (discipline, saison, dates, statuts, tolérance pesée, formats autorisés).
- Vues admin par scope/région avec fallback sécurisé.

### Inscriptions
- Inscriptions front et admin (licencié UFSC + participant externe non licencié).
- Validation de statuts, bulk actions, group labels.
- Import CSV ASPTT + dry-run + rollback du dernier lot.

### Pesées & numéro combattant
- Saisie pesée par inscrit approuvé.
- Saisie/édition du numéro combattant lors de la pesée.
- Contrôle d’unicité applicatif du numéro combattant à l’échelle compétition.
- Statuts pesée (pending, weighed, validated, out_of_limit, awaiting_reclassification, reclassified, refused).
- Reclassement catégorie piloté depuis la pesée.

### Combats & bracket
- Génération auto de brouillons (formats 2 / 3 / 4 / 5-8 / 9-16 selon paramétrage et services de génération).
- BYE visibles et placeholders métier lisibles (ex: « Vainqueur combat X », « Vainqueur demi-finale 1 »).
- Affichage des phases à partir de la structure des rounds (Qualification → Finale).
- Saisie de résultat (vainqueur, statut, méthode, scores, horaire).
- Correction de résultat supervisée (motif obligatoire, confirmation superviseur si impacts joués).
- Propagation prudente du vainqueur corrigé vers le combat suivant quand le slot est encore modifiable.

### Impressions / sorties terrain
- Liste des inscrits.
- Référentiel catégories.
- Répartition des combats par surface (A4/A3/A2).
- Vue organisation « surface overview ».
- Intégration du numéro combattant sur les sorties combats (avec fallback explicite).

### Journalisation / audit
- Logs de création/modification/suppression pour les entités clés.
- Trace dédiée des corrections de résultats (ancien/nouveau vainqueur, raison, impacts, supervision, propagation).

## 3) Workflow métier recommandé (production)

1. **Créer/configurer** la compétition (discipline, saison, dates, tolérance, formats).
2. **Importer/contrôler** les inscriptions (CSV + vérification anomalies).
3. **Valider les inscriptions** à engager.
4. **Passer les pesées** et attribuer le numéro combattant.
5. **Traiter les hors-limite / reclassements**.
6. **Générer les combats** puis vérifier les affectations surfaces/horaires.
7. **Imprimer** les documents terrain (surface / catégories / listes).
8. **Saisir les résultats** au fil de l’événement.
9. **Corriger sous supervision** uniquement si nécessaire.
10. **Contrôler logs + cohérence bracket** avant clôture.

## 4) Architecture fonctionnelle (résumé)

### Pages admin principales
- `Compétitions`, `Catégories`, `Inscriptions`, `Pesées`, `Combats`, `Timing Profiles`, `Impression`, `Opérations sensibles`, `Logs`, `Guide`.

### Services principaux
- `FightAutoGenerationService` (sélection/éligibilité/génération).
- `FightDisplayService` (phases + placeholders lisibles + labels coins).
- `FighterNumberService` (résolution cohérente du numéro combattant).
- `LogService` / `AuditLogger` (journalisation).

### Repositories principaux
- `CompetitionRepository`, `EntryRepository`, `FightRepository`, `CategoryRepository`, `WeighInRepository`, `LogRepository`.

### Points d’entrée utiles
- `includes/competitions/bootstrap.php` (chargement module).
- Pages admin `includes/competitions/Admin/Pages/*`.
- Tables admin `includes/competitions/Admin/Tables/*`.

## 5) Contraintes et limites connues (honnêtes)

- Le **numéro combattant** est stocké aujourd’hui dans `weighins.notes` (JSON) pour compatibilité historique ; il n’existe pas encore de colonne dédiée normalisée.
- La **progression bracket** est pilotée de manière prudente : la propagation se fait quand le combat suivant reste modifiable ; les cas déjà joués restent sous supervision humaine.
- Certaines associations de combats futurs reposent sur l’ordre `round_no + fight_no` (heuristique métier contrôlée), pas sur une table dédiée de dépendances explicites.
- Le fallback final d’affichage peut utiliser `#entry_id` dans certaines impressions pour éviter les trous terrain quand aucun numéro combattant n’est disponible.

## 6) Sécurité / garde-fous

- Vérification systématique de capabilities (manage, validate, delete, etc.).
- Nonces sur actions admin sensibles.
- Sanitization/escaping des entrées/sorties.
- Requêtes SQL préparées (`$wpdb->prepare`) sur filtres dynamiques.
- Soft delete sur entités concernées.
- Supervision explicite pour correction de résultat avec impacts aval déjà joués.

## 7) Notes de mise en production

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

## 8) FAQ technique rapide

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
