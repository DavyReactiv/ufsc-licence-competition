# Lot 2 — Alignement table combats / surfaces

## Problème identifié

L'audit Lot 1 a relevé que `ufsc_competition_assign_surfaces_and_times()` utilisait directement une table construite avec `$wpdb->prefix . 'ufsc_competitions_fights'`.

Le reste du périmètre combats s'appuie sur la table exposée par `UFSC\Competitions\Db::fights_table()`. Cette divergence pouvait empêcher l'affectation des surfaces et de l'ordre de passage sur les combats réellement lus par le repository, la vue plateau et les impressions.

## Table canonique retenue

La table canonique des combats est celle retournée par :

```php
\UFSC\Competitions\Db::fights_table()
```

Dans le code existant, cette méthode retourne la table WordPress préfixée `ufsc_fights`. Elle est utilisée par `FightRepository` pour lire, insérer, mettre à jour et supprimer les combats, ainsi que par les workflows de génération.

## Fichiers modifiés

- `includes/ufsc-lc-helpers.php`
  - Alignement de `ufsc_competition_assign_surfaces_and_times()` sur la table canonique `Db::fights_table()`.
  - Fallback prudent vers `$wpdb->prefix . 'ufsc_fights'` uniquement si la classe `Db` n'est pas disponible au moment de l'appel.
- `docs/LOT2-SURFACES-TABLE-COMBATS.md`
  - Note de documentation du lot.

## Garde-fous ajoutés

La fonction d'affectation des surfaces et horaires vérifie maintenant avant toute mise à jour :

- que la compétition demandée est valide ;
- que la table canonique existe ;
- que les colonnes minimales `id`, `competition_id` et `status` existent ;
- qu'au moins une colonne d'affectation de surface/ordre existe ;
- que les mises à jour restent limitées à `competition_id` ;
- que les combats supprimés (`deleted_at` non nul), terminés/verrouillés ou avec payload résultat ne sont pas sélectionnés lorsque les colonnes correspondantes existent ;
- que le diagnostic retourné contient notamment la table utilisée, le nombre total de combats trouvés, le nombre de combats modifiables, le nombre mis à jour et le nombre ignoré comme sensible.

## Références de tables observées

| Fichier | Ligne approximative | Usage | Table utilisée | Risque |
|---|---:|---|---|---|
| `includes/competitions/Db.php` | 46-48 | Définition canonique | `Db::fights_table()` → `ufsc_fights` | Aucun, source de vérité. |
| `includes/competitions/Db.php` | 185, 511, 642 | Création/upgrade/backfill combats | `self::fights_table()` | Aucun, migrations existantes non modifiées. |
| `includes/competitions/Repositories/FightRepository.php` | 45 | Lecture d'un combat | `Db::fights_table()` | Aucun. |
| `includes/competitions/Repositories/FightRepository.php` | 374, 386 | Liste/compte combats | `Db::fights_table()` | Aucun. |
| `includes/competitions/Repositories/FightRepository.php` | 399, 487 | Insertion/update combat | `Db::fights_table()` | Aucun pour l'alignement; mutations métier déjà existantes. |
| `includes/competitions/Repositories/FightRepository.php` | 510, 521 | Suppression combat / compétition | `Db::fights_table()` | Sensible mais hors lot; déjà identifié Lot 1. |
| `includes/competitions/Repositories/FightRepository.php` | 545, 670, 795-802 | Ordre, soft delete, introspection colonnes | `Db::fights_table()` | Aucun. |
| `includes/competitions/Services/FightAutoGenerationService.php` | 797 | Diagnostic de validation/insertion | `Db::fights_table()` | Aucun. |
| `includes/competitions/Admin/Pages/Bouts_AutoGeneration.php` | 1214, 1246, 1313, 1404 | Diagnostics et fixtures `[TEST]` | `Db::fights_table()` | Aucun pour table; fixtures à garder `[TEST]`. |
| `includes/competitions/Admin/Pages/Bouts_AutoGeneration.php` | 1518 | Suppression fixture `[TEST]` | `Db::fights_table()` | Sensible mais limité aux fixtures suivies. |
| `includes/competitions/Admin/Pages/Access_Diagnostic_Page.php` | 265, 292 | Diagnostic accès / colonnes | `Db::fights_table()` | Aucun. |
| `includes/competitions/Admin/Pages/Entries_Import_Page.php` | 1743 | Contrôle d'import lié aux combats | `Db::fights_table()` | Aucun. |
| `includes/competitions/helpers.php` | 277-278 | Statistiques statuts combats | `Db::fights_table()` | Aucun. |
| `includes/ufsc-lc-helpers.php` | 422 | Affectation surfaces et ordre | `Db::fights_table()` avec fallback `ufsc_fights` | Corrigé dans ce lot. |
| `includes/competitions/Admin/Tables/Fights_Table.php` | 60 | Option écran WP, pas table SQL | `ufsc_competition_fights_per_page` | Aucun, pas une table. |
| `includes/competitions/Db.php` | 748 | Option de backfill, pas table SQL | `ufsc_competitions_fights_fight_no_backfill_done` | Aucun, pas une table. |

## Tests manuels à effectuer sur compétition `[TEST]`

Ne pas utiliser une compétition réelle pour ces tests.

- [ ] Vérifier que le plugin charge sans erreur fatale.
- [ ] Vérifier que l'affectation utilise la table canonique `ufsc_fights`.
- [ ] Vérifier qu'une compétition `[TEST]` sans combat ne provoque pas d'erreur.
- [ ] Vérifier qu'une compétition `[TEST]` avec combats programmés reçoit bien surface et ordre.
- [ ] Vérifier qu'un combat terminé n'est pas modifié.
- [ ] Vérifier qu'un combat verrouillé n'est pas modifié.
- [ ] Vérifier qu'un combat avec vainqueur, méthode de résultat ou score n'est pas modifié.
- [ ] Vérifier que la page plateau affiche les surfaces attendues.
- [ ] Vérifier que l'impression par surface affiche les combats attendus.
- [ ] Vérifier que le diagnostic retourné est lisible en cas de table absente, colonnes absentes ou absence de combat modifiable.
