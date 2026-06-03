# Lot 5 — Cas particuliers de génération des combats

## 1. Objectif du lot

Le Lot 5 consolide la lecture des cas particuliers avant validation du brouillon de combats : participant seul, finale directe, poule de 3, tableau simple, groupes impairs et BYE.

Le périmètre reste volontairement limité : aucune donnée réelle n'est modifiée, aucune génération réelle n'est lancée, aucune structure SQL n'est changée et les impressions/résultats ne sont pas touchés.

## 2. Règles observées et retenues par taille de groupe

| Taille du groupe | Règle retenue | Combat(s) attendus | Diagnostic attendu |
|---|---|---:|---|
| 0 | Groupe insuffisant | 0 | `insufficient_participants` |
| 1 | Participant isolé | 0 | `isolated_participant`, `insufficient_participants` |
| 2 | Combat direct / finale directe | 1 | `direct_final` |
| 3 avec poule activée | Poule de 3 | 3 | `pool_3` |
| 3 sans poule activée | Tableau avec BYE | 3 éléments de brouillon : combat, BYE, finale placeholder | `bracket_with_bye`, `odd_participant_count`, `bye_required` |
| 4 | Tableau simple | 3 éléments de brouillon : deux demi-finales + finale placeholder | `bracket` |
| 5 | Tableau avec BYE | Tableau puissance de 2 avec BYE | `bracket_with_bye`, `odd_participant_count`, `bye_required` |
| 6 avec poule activée | Poule complète | 15 combats | `pool` |
| 6 sans poule activée | Tableau avec BYE | Tableau puissance de 2 avec BYE | `bracket_with_bye`, `bye_required` |

## 3. Stratégie technique retenue

- Centraliser l'explication d'un groupe dans `describe_group_generation_case()` : type de cas, libellé, format recommandé, nombre de combats estimés, nombre de BYE et warnings.
- Réutiliser cette description dans la preview, les statistiques et le choix de format avant génération.
- Ne pas générer de faux combat pour un participant seul : le groupe reste visible et signalé comme insuffisant.
- Marquer explicitement les combats de deux participants comme `direct_final` dans le payload de preview.
- Marquer les poules de trois participants comme `pool_3`.
- Marquer les tableaux incomplets comme `bracket_with_bye` et conserver les `winner_entry_id` automatiques des BYE.
- Propager `group_key` dans les payloads du planner premium, des poules, des tableaux, des BYE et des placeholders.

## 4. Fichiers modifiés

- `includes/competitions/Services/FightAutoGenerationService.php`
- `includes/competitions/Services/FightGenerationPremiumPlanner.php`
- `includes/competitions/Services/GenerationReadinessDiagnostic.php`
- `includes/competitions/Admin/Pages/Bouts_AutoGeneration.php`
- `docs/LOT5-CAS-PARTICULIERS-GENERATION.md`
- `tests/lot5-cas-particuliers-generation-checklist.md`

## 5. Diagnostics ajoutés

### Preview admin

La preview expose désormais clairement :

- `bye_count` ;
- `isolated_count` ;
- `insufficient_groups_count` ;
- `case_type` par groupe ;
- `case_label` par groupe ;
- warnings par groupe, dont `isolated_participant`, `insufficient_participants`, `odd_participant_count`, `bye_required`.

### Brouillon et validation

`GenerationReadinessDiagnostic` analyse maintenant les cas particuliers du brouillon :

- groupes sans combat ;
- participants isolés ;
- groupes impairs ;
- BYE présents ;
- placeholders ;
- combats sans `group_key` ;
- combats sans `category_id` ;
- combats non-BYE/non-placeholder sans deux adversaires réels.

Les problèmes sont classés en information, warning ou bloquant :

- BYE et placeholders : information / contrôle ;
- participant isolé, groupe sans combat, groupe impair, `group_key` absent : warning ;
- `category_id` absent ou combat réel sans adversaire réel : bloquant.

## 6. Points volontairement non modifiés

- Pas de modification des impressions.
- Pas de modification des résultats.
- Pas de migration SQL.
- Pas de changement des règles de classement des poules.
- Pas de génération réelle pendant ce lot.
- Pas de refonte globale du planner : seules les métadonnées de cas et les placeholders de tableau nécessaires à la lisibilité du brouillon sont consolidés.

## 7. Tests manuels `[TEST]` à réaliser

Voir `tests/lot5-cas-particuliers-generation-checklist.md`.

Points prioritaires :

- vérifier qu'un participant seul ne crée aucun combat ;
- vérifier qu'un groupe de 2 affiche `direct_final` ;
- vérifier qu'un groupe de 3 affiche soit `pool_3`, soit `bracket_with_bye` selon le réglage ;
- vérifier que les groupes impairs affichent le nombre de BYE ;
- vérifier que les BYE ont un gagnant automatique ;
- vérifier que la validation du brouillon signale les cas particuliers sans les confondre avec des erreurs fatales lorsque ce sont des warnings.

## 8. Risques restants pour le Lot 6

- Validation contrôlée des combats générés, notamment acceptation explicite des BYE et participants isolés.
- Affichage plus ergonomique des choix par groupe si plusieurs dizaines de groupes existent.
- Décision métier définitive pour poule de 3 vs tableau avec BYE selon discipline/classe.
- Tests automatisés du planner et du diagnostic de brouillon.
