# Lot 3 — Sécurisation de la génération directe des combats

## Problème identifié

L'audit Lot 1 mentionnait une génération directe alternative nommée `generate_groups_directly`. Dans le code actuel, aucune méthode portant exactement ce nom n'est présente. Le chemin équivalent identifié est :

```php
UFSC\Competitions\Services\FightAutoGenerationService::generate_simple_pairing_fights()
```

Cette méthode est publique, retourne un tableau de diagnostic et contient des insertions directes via `FightRepository::insert()` ainsi que des mises à jour de liens de tableau via `FightRepository::update()`.

## Rôle du fallback direct

`generate_simple_pairing_fights()` sert de fallback historique pour créer des appariements simples lorsque le workflow normal de brouillon/validation échoue dans une fixture de test. Elle ne doit pas être utilisée comme génération principale.

Workflow principal attendu pour toute compétition réelle :

1. prévisualisation ;
2. brouillon ;
3. diagnostic ;
4. snapshot ;
5. validation manuelle ;
6. application.

## Appels trouvés

| Fichier | Ligne approximative | Contexte | Admin | AJAX | admin_post | Fixture `[TEST]` | Workflow principal | Risque |
|---|---:|---|---|---|---|---|---|---|
| `includes/competitions/Services/FightAutoGenerationService.php` | 919 | Définition de `generate_simple_pairing_fights()` | Non | Non | Non | Non | Non | Critique si appelée directement sans garde-fous. |
| `includes/competitions/Admin/Pages/Bouts_AutoGeneration.php` | 1189 | Fallback après tentative brouillon/apply dans `handle_test_fixture_open150_generate()` | Oui | Non | Oui | Oui | Non | Moyen après garde-fous, car réservé à `[TEST]`. |
| `includes/competitions/Admin/Pages/Bouts_AutoGeneration.php` | 1039 | `handle_generate_direct()` | Oui | Non | Oui | Non | Non | Faible : ne lance pas le fallback direct, redirige vers création de brouillon. |

Aucun appel exact à `generate_groups_directly` n'a été trouvé dans `includes`.

## Stratégie retenue

La stratégie appliquée correspond à l'Option B : conservation uniquement pour fixtures `[TEST]`.

La méthode n'est pas supprimée pour éviter de casser les fixtures de diagnostic existantes, mais elle est marquée `@deprecated` et bloquée sauf si toutes les conditions suivantes sont réunies :

- `competition_id` valide ;
- compétition existante ;
- nom de compétition préfixé par `[TEST]` ;
- `sandbox_generation` actif ;
- contexte explicite `direct_generation_context = test_fixture` ;
- génération non verrouillée ;
- `CompetitionSafetyService::guard_fight_generation()` accepte le contexte ;
- aucun combat existant sensible ou déjà généré ne bloque le périmètre.

## Garde-fous ajoutés

- Refus explicite des compétitions réelles, même si un filtre tiers tente d'autoriser le fallback.
- Suppression du contournement par filtre `ufsc_competitions_allow_direct_generation_fallback` pour les compétitions non `[TEST]`.
- Contrôle de l'existence de la compétition via `CompetitionRepository`.
- Contrôle du préfixe `[TEST]` sur le nom de compétition.
- Contrôle du contexte interne `test_fixture`.
- Contrôle du verrou `GenerationLockService`.
- Appel à `CompetitionSafetyService::guard_fight_generation()`.
- Contrôle des combats existants/sensibles via `FightRepository::can_regenerate_scope()` et les blockers existants.
- Journalisation d'audit `generation_blocked` avec raison et source `generate_simple_pairing_fights`.

## Impact sur le workflow principal

Le workflow principal par brouillon reste inchangé : `generate_draft()` puis `validate_and_apply_draft()` continuent d'être utilisés par les actions admin normales.

Le bouton/action de génération directe admin ne valide pas de combats directement : il redirige vers la création de brouillon et demande une validation manuelle.

## Tests manuels à réaliser sur `[TEST]`

- [ ] Créer une compétition `[TEST]` via les fixtures existantes.
- [ ] Vérifier que la génération principale crée un brouillon.
- [ ] Vérifier que la validation du brouillon reste possible uniquement après diagnostic acceptable.
- [ ] Vérifier que le fallback direct n'est appelé que dans la fixture Open 150 lorsque brouillon/apply échoue.
- [ ] Vérifier qu'une compétition réelle sans préfixe `[TEST]` retourne `direct_fallback_test_fixture_only` si la méthode est appelée directement.
- [ ] Vérifier qu'une compétition `[TEST]` sans `direct_generation_context = test_fixture` est refusée.
- [ ] Vérifier qu'une compétition `[TEST]` verrouillée est refusée.
- [ ] Vérifier qu'une compétition `[TEST]` avec résultats existants est refusée par `CompetitionSafetyService`.

## Points à traiter dans le Lot 4

- Consolider l'algorithme de regroupement par discipline, sexe, âge, poids et niveau dans le workflow de brouillon.
- Réduire la dépendance aux fallbacks historiques.
- Ajouter des tests automatisés de génération dry-run si l'environnement WordPress de test le permet.
- Harmoniser les diagnostics de génération entre preview, brouillon et validation.
