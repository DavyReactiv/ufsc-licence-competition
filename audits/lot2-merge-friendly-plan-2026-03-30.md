# Lot 2 — Plan de mise en place du référentiel UFSC (merge-friendly)

## A) Liste prévisionnelle des fichiers à modifier (ciblés)

1. `includes/competitions/bootstrap.php`
   - **Pourquoi**: charger les nouveaux services Référentiel UFSC de façon additive (require_once), sans changer les flux existants.
2. `includes/competitions/Services/CategoryAssigner.php`
   - **Pourquoi**: point de branchement minimal pour utiliser le référentiel âge/catégorie si disponible, avec fallback logique actuelle.
3. `includes/competitions/Services/WeightCategoryResolver.php`
   - **Pourquoi**: brancher le référentiel poids officiel UFSC en priorité, fallback sur résolution existante.
4. `includes/competitions/Services/CompetitionScheduleEstimator.php`
   - **Pourquoi**: intégrer timings référentiel (rounds/pauses) quand disponibles, fallback sur profils actuels.
5. `includes/competitions/Entries/EntryEligibility.php`
   - **Pourquoi**: brancher contrôles obligations/éligibilité référentiel (sans casser les règles existantes).

> Note: ces fichiers sont les seuls points métier centraux déjà existants où le référentiel peut être branché sans toucher à l’UI stabilisée.

## B) Liste des nouveaux fichiers à créer

1. `includes/competitions/Services/UfscReference/UfscReferenceRepository.php`
   - chargement des tables/règles référentiel (sources statiques PHP).
2. `includes/competitions/Services/UfscReference/UfscAgeCategoryRules.php`
   - tranches âge/catégories officielles.
3. `includes/competitions/Services/UfscReference/UfscWeightRules.php`
   - catégories de poids officielles par discipline/groupe.
4. `includes/competitions/Services/UfscReference/UfscTimingRules.php`
   - rounds/durées/pauses par discipline/catégorie.
5. `includes/competitions/Services/UfscReference/UfscObligationRules.php`
   - obligations médicales/équipements/pratique.
6. `includes/competitions/Services/UfscReference/UfscReferenceFacade.php`
   - façade unique consommée par les services existants.
7. `includes/competitions/Services/UfscReference/UfscReferenceFallback.php`
   - fallback explicite pour garantir zéro régression.

## C) Points de branchement exacts sur l’existant

1. **Bootstrap**
   - ajouter les nouveaux `require_once` UFSC dans `load_competitions_core_dependencies()`.
2. **CategoryAssigner**
   - au moment de déterminer la catégorie: `UfscReferenceFacade::resolve_age_category(...)` si enabled, sinon logique actuelle.
3. **WeightCategoryResolver**
   - avant la résolution actuelle: tentative `UfscReferenceFacade::resolve_weight_category(...)`, sinon fallback resolver existant.
4. **CompetitionScheduleEstimator**
   - lors de l’estimation d’un combat: `UfscReferenceFacade::resolve_timing(...)` puis fallback timing profile actuel.
5. **EntryEligibility**
   - en validation: `UfscReferenceFacade::resolve_obligations(...)` puis enrichissement non bloquant (mode warning) avant durcissement futur.

## D) Fichiers interdits (sauf nécessité absolue)

### UI stabilisée (ne pas toucher pour Lot 2)
- `includes/competitions/assets/admin.css`
- `includes/competitions/assets/front.css`
- `includes/competitions/Front/Entries/EntryFormRenderer.php`
- `includes/competitions/Front/Shortcodes/CompetitionDetailsShortcode.php`
- `includes/competitions/Front/Shortcodes/CompetitionsListShortcode.php`
- `includes/competitions/Admin/Pages/Competitions_Page.php`
- `includes/competitions/Admin/Pages/Entries_Page.php`
- `includes/competitions/Admin/Pages/Bouts_Page.php`
- `includes/competitions/Admin/Pages/Timing_Profiles_Page.php`
- `includes/competitions/Admin/Pages/Quality_Page.php`

### Flux critiques à préserver strictement
- endpoints `admin_post_*` existants
- hooks/ajax existants
- shortcodes existants
- structure DB existante (pas de migration destructive)

## E) Stratégie anti-conflit (obligatoire)

1. **Nouveaux fichiers dédiés d’abord** (référentiel encapsulé).
2. **Branchements minimaux** dans les services existants (ajouts ponctuels, pas de réécriture).
3. **Feature flag/fallback systématique**
   - `apply_filters('ufsc_competitions_reference_enabled', false)` par défaut.
   - si désactivé/indisponible => comportement actuel inchangé.
4. **Aucune modification UI** dans Lot 2.
5. **Diffs courts**
   - 1 commit par sous-sujet (bootstrap, âge/poids, timing, obligations).
6. **Compat ascendante stricte**
   - aucune suppression/renommage de classes, hooks, méthodes existantes.
7. **Validation anti-régression**
   - lint PHP des fichiers touchés.
   - check rapide des flux critiques non modifiés.

## Synthèse
Le Lot 2 sera implémenté en “surcouche référentiel” : services nouveaux + points de branchement minimaux dans 4–5 services coeur déjà en place, avec fallback total vers l’existant.
