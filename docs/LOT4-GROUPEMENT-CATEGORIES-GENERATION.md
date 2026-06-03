# Lot 4 — Fiabilisation du regroupement catégories avant génération

## 1. Problème traité

Le Lot 4 cible la phase de sélection et de regroupement des participants avant la création d'un brouillon de combats. L'objectif est d'éviter qu'un participant dont les données sportives sont incomplètes tombe silencieusement dans un groupe ambigu, ou que des participants de discipline, sexe, catégorie, poids ou niveau différents soient regroupés par erreur.

Cette correction ne modifie pas la structure SQL, ne lance aucune génération réelle, ne supprime aucun combat et ne touche pas aux résultats. Elle reste limitée au diagnostic et au regroupement en mémoire utilisés avant la génération du brouillon.

## 2. Flux actuel de sélection / regroupement

1. `FightAutoGenerationService::generate_draft()` récupère la compétition, les inscriptions détaillées et les paramètres de génération.
2. `select_eligible_entries()` applique les règles d'éligibilité existantes : statut, suppression logique, inscription déjà affectée à un combat actif, éventuelle pesée obligatoire et reclassification en attente.
3. Le Lot 4 ajoute un contrôle intermédiaire des champs nécessaires au regroupement : discipline, sexe, catégorie ou `category_id`, catégorie de poids et niveau si `use_level_split` est actif.
4. Les participants rejetés reçoivent désormais un snapshot enrichi avec `group_key`, `group_components`, motifs de rejet et action recommandée.
5. Les participants éligibles sont regroupés sur une clé lisible et stable avant la construction des combats du brouillon.
6. La preview expose des compteurs de groupes générables, insuffisants, isolés et impairs, ainsi que des warnings par groupe.

## 3. Fichiers analysés

- `includes/competitions/Services/FightAutoGenerationService.php`
- `includes/competitions/Services/CategoryAssigner.php`
- `includes/competitions/Services/WeightCategoryResolver.php`
- `includes/competitions/Services/ExternalParticipantEligibility.php`
- `includes/competitions/Entries/EntryEligibility.php`
- `includes/competitions/Entries/EntryDataNormalizer.php`
- `includes/competitions/Repositories/EntryRepository.php`
- `includes/competitions/Repositories/CategoryRepository.php`
- `includes/competitions/Repositories/WeighInRepository.php`
- `includes/competitions/Services/FightGenerationPremiumPlanner.php`

Le changement de code est volontairement concentré dans `FightAutoGenerationService.php`, car c'est le point d'orchestration où les entrées éligibles deviennent des groupes puis des combats en brouillon.

## 4. Corrections appliquées

### 4.1 Rejet explicite des entrées non groupables

Ajout d'un contrôle de regroupement après l'éligibilité métier existante et avant la pesée :

- `discipline_missing` si la discipline est absente ou non normalisable ;
- `sex_missing` si le sexe ne peut pas être lu depuis l'inscription ou déduit de la catégorie ;
- `weight_class_missing` si la catégorie de poids est absente ;
- `category_unresolved` si aucun `category_id` ni libellé de catégorie n'est disponible ;
- `birthdate_missing` lorsque la catégorie est introuvable et que la date de naissance ne permet pas de diagnostic utile ;
- `level_missing` si `use_level_split` est actif et que le niveau/la classe est vide.

### 4.2 Clé de groupe formalisée

La clé de regroupement utilisée pour la preview et pour le brouillon distingue désormais :

- discipline normalisée ;
- sexe normalisé ;
- `category_id` sous forme `category_123` lorsque disponible ;
- catégorie d'âge ou libellé de catégorie ;
- catégorie de poids ;
- niveau/classe seulement si `use_level_split` est actif.

Cette clé est volontairement lisible dans les diagnostics afin de faciliter les vérifications admin.

### 4.3 Brouillon aligné sur la clé fiable

Lors de la préparation du brouillon, une entrée dont le `category_id` est résolu par `CategoryAssigner` reçoit ce `category_id` en mémoire, puis le groupe est indexé par une clé composite `category_id|group_key`. Cela conserve le `category_id` requis par les fonctions de construction de combats tout en empêchant un regroupement trop large sur le seul identifiant de catégorie.

### 4.4 Diagnostics de preview enrichis

La preview expose maintenant :

- nombre de groupes générables ;
- nombre de groupes insuffisants ;
- nombre de participants isolés ;
- nombre de groupes impairs ;
- `group_diagnostics` global ;
- `group_components` par groupe ;
- warnings `isolated_participant`, `insufficient_participants`, `odd_participant_count`.

### 4.5 Actions recommandées plus explicites

Les motifs de rejet liés au regroupement reçoivent une action recommandée lisible : renseigner discipline, sexe, date de naissance, catégorie sportive, poids ou niveau selon le cas.

## 5. Motifs de rejet / warning utilisés

### Motifs bloquants confirmés ou ajoutés

- `already_assigned_fight`
- `status_not_approved`
- `discipline_missing`
- `sex_missing`
- `weight_class_missing`
- `category_unresolved`
- `birthdate_missing`
- `level_missing`
- `weighin_missing`
- `reclass_pending`

### Warnings de groupe ajoutés

- `isolated_participant` : un seul participant dans le groupe ;
- `insufficient_participants` : moins de deux participants, donc pas de combat possible ;
- `odd_participant_count` : nombre impair supérieur à un, à traiter par BYE/poule selon le Lot 5.

## 6. Garde-fous ajoutés

- Aucun participant avec données de regroupement critiques manquantes n'est ajouté à un groupe silencieusement.
- Les groupes du brouillon ne reposent plus uniquement sur `category_id` lorsque des données sportives plus précises sont disponibles.
- Les combats du brouillon reçoivent `group_key` lorsque la structure de payload le permet, y compris via planner premium, poule ou tableau.
- Les diagnostics contiennent les composants de groupe pour expliquer pourquoi des participants sont ensemble.
- Les participants isolés ou groupes insuffisants restent visibles dans les diagnostics au lieu d'être invisibles.

## 7. Impact sur le workflow principal

Le workflow principal reste inchangé dans son principe :

1. génération de preview ;
2. création de brouillon ;
3. diagnostic ;
4. validation contrôlée ;
5. application via repository.

Le Lot 4 intervient uniquement avant la construction des combats afin de mieux filtrer et expliquer les entrées. Il ne modifie pas les tables SQL, les permissions, les impressions, les résultats ou les migrations.

## 8. Tests manuels à réaliser sur une compétition `[TEST]`

Voir `tests/lot4-groupement-categories-checklist.md` pour la liste détaillée.

Les vérifications principales sont :

- deux participants identiques sportivement doivent produire un groupe générable ;
- un participant seul doit apparaître comme isolé/groupe insuffisant ;
- des participants de sexe, poids, âge, discipline ou niveau différents ne doivent pas être mélangés ;
- les données manquantes doivent produire un rejet explicite ;
- les pesées invalides ou manquantes doivent rester bloquantes si la pesée est obligatoire ;
- le brouillon doit conserver le workflow de validation existant.

## 9. Points restants pour le Lot 5

- Formaliser le traitement sportif des participants seuls.
- Définir les règles BYE pour les groupes impairs.
- Clarifier le choix poule / tableau pour trois participants.
- Mieux exposer les options par groupe dans l'interface admin si nécessaire.
- Ajouter des tests automatisés autour des cas 1, 2, 3, impair et BYE si l'infrastructure de test le permet.
