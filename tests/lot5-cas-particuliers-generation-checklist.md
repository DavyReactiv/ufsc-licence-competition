# Checklist Lot 5 — Cas particuliers de génération

> À exécuter uniquement sur une compétition `[TEST]`. Ne pas générer ni valider sur une compétition réelle.

| Cas | Résultat attendu en preview | Résultat attendu en brouillon | Warning attendu | Blocage attendu | Validation attendue |
|---|---|---|---|---|---|
| 1 participant dans une catégorie | `case_type=isolated`, `estimated_fights=0`, participant visible | Aucun combat pour ce groupe | `isolated_participant`, `insufficient_participants` | Non, sauf mode strict futur | Validation possible uniquement si l'admin accepte qu'aucun combat ne soit créé pour ce groupe |
| 2 participants même catégorie | `case_type=direct_final`, 1 combat estimé | 1 combat `scheduled`, phase finale directe si disponible | Aucun sauf même club | Non | Combat direct validable |
| 3 participants même catégorie avec poule activée | `case_type=pool_3`, 3 combats estimés | 3 combats de poule | Éventuel repos rapproché / même club | Non | Poule validable après contrôle |
| 3 participants même catégorie sans poule activée | `case_type=bracket_with_bye`, BYE indiqué | Combat + BYE + finale placeholder | `odd_participant_count`, `bye_required` | Non | BYE accepté si admin confirme le brouillon |
| 4 participants même catégorie | `case_type=bracket`, 3 combats estimés | 2 demi-finales + finale placeholder | Éventuel même club | Non | Tableau validable |
| 5 participants même catégorie | `case_type=bracket_with_bye`, BYE indiqué | Tableau puissance de 2 avec BYE et placeholders | `odd_participant_count`, `bye_required` | Non | Validation après contrôle des BYE |
| 6 participants même catégorie avec poule activée | `case_type=pool`, 15 combats estimés | Poule complète | Timing à contrôler | Non | Validation possible si timing/surfaces OK |
| 6 participants même catégorie sans poule activée | `case_type=bracket_with_bye`, BYE indiqué | Tableau avec BYE | `bye_required` | Non | Validation après contrôle |
| Catégorie impaire avec BYE | `bye_count` supérieur à 0 | BYE avec `winner_entry_id` automatique | `bye_required` | Non | BYE ne doit pas être traité comme adversaire réel |
| Groupe avec données complètes | Groupe générable avec `group_key` lisible | Combats avec `category_id` et `group_key` | Aucun bloquant | Non | Validation possible |
| Groupe avec `group_key` absent ou incohérent | Diagnostic de regroupement à contrôler | Diagnostic `fight_missing_group_key` si le brouillon contient un combat sans clé | `fight_missing_group_key` | Non sauf politique future | À corriger avant production si possible |
| Groupe avec `category_id` manquant | Catégorie non exploitable ou rejetée | Diagnostic `fight_missing_category_id` si présent dans brouillon | Warning préalable possible | Oui | Validation bloquée |
| Participant isolé | Visible dans le groupe | Aucun combat | `isolated_participant` | Non | Admin doit décider reclassement/attente |
| Combat direct | 1 combat estimé | 1 combat réel planifié au brouillon | Aucun | Non | Validation possible |
| Poule de 3 ou tableau avec BYE | Le choix réel est affiché dans `case_label` | Les combats correspondent au choix affiché | Selon cas | Non | Validation uniquement après contrôle admin |
| Compétition réelle non `[TEST]` | Ne pas exécuter de test destructif | Aucun combat réel généré | Protection production conservée | Oui si workflow non autorisé | Aucune génération réelle |
| Résultats existants | Non concernés | Non modifiés | Aucun | Les protections existantes doivent bloquer une génération dangereuse | Aucun résultat modifié |

## Vérifications complémentaires

- [ ] `bye_count` global correspond au nombre de combats BYE du brouillon.
- [ ] `isolated_count` correspond aux participants seuls visibles.
- [ ] `insufficient_groups_count` correspond aux groupes sans combat possible.
- [ ] Les placeholders ne sont pas affichés comme des combats avec deux adversaires réels.
- [ ] Les BYE possèdent un seul combattant et un `winner_entry_id` automatique.
- [ ] Les combats non-BYE/non-placeholder ont deux adversaires réels.
- [ ] Tous les combats validables ont un `category_id`.
- [ ] Tous les combats de brouillon ont un `group_key` ou produisent un warning clair.
- [ ] La validation ne modifie pas les résultats existants.
