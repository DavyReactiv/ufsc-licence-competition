# Lot 6 — Validation contrôlée du brouillon de génération des combats

## Objectif

Ce lot sécurise la phase **validation contrôlée → application** du brouillon de génération des combats, sans modifier l’algorithme de génération ni la structure SQL.

Le workflow cible reste :

1. prévisualisation ;
2. génération du brouillon ;
3. diagnostic ;
4. validation contrôlée ;
5. application des combats ;
6. snapshot / verrouillage / rollback ciblé en cas d’échec.

## Workflow actuel observé

- `FightAutoGenerationService::generate_draft()` récupère les inscriptions, filtre les participants éligibles, construit les groupes, génère les payloads de combats et stocke un brouillon via `FightRepository::save_draft()`.
- Le brouillon contient notamment `draft_id`, `competition_id`, `generated_at`, `settings`, `stats`, `groups`, `fights_preview`, `fights`, `surfaces`, `diagnostic_hash` et `draft_hash`.
- `FightAutoGenerationService::validate_and_apply_draft()` est le point d’application : il relit le brouillon, lance le diagnostic de readiness, crée un snapshot, insère les combats via `FightRepository::insert()`, assigne surfaces/horaires et verrouille la génération.
- `GenerationReadinessDiagnostic` signale déjà les problèmes de compétition, inscriptions, pesées, catégories, surfaces, combats existants et cas particuliers de brouillon.
- `GenerationSnapshotService` crée un snapshot avant l’application réelle.
- `GenerationLockService` verrouille la génération après application.

## Garde-fous ajoutés

### Contexte de validation du brouillon

Un `validation_context` est ajouté au brouillon au moment de sa création. Il contient des empreintes non destructives de l’état analysé :

- inscriptions ;
- pesées ;
- catégories ;
- surfaces ;
- paramètres de génération ;
- compteurs attendus (`total_entries`, `eligible_entries`, `groups`, `fights`, BYE, isolés, groupes insuffisants).

Ces empreintes permettent de refuser un brouillon devenu obsolète avant insertion définitive.

### Détection des brouillons obsolètes

La validation refuse désormais un brouillon lorsque :

- `competition_id` ne correspond pas ;
- `draft_id`, `generated_at`, `draft_hash` ou `diagnostic_hash` est absent ;
- `draft_hash` ne correspond plus au contenu stocké ;
- le contexte de validation est absent ;
- les inscriptions ont changé ;
- les pesées ont changé ;
- les catégories ont changé ;
- les surfaces ont changé ;
- les paramètres de génération ont changé ;
- les compteurs internes du brouillon ne correspondent plus au payload à appliquer.

Les messages admin restent lisibles, par exemple :

- “Le brouillon est obsolète : les inscriptions ont changé depuis sa création.”
- “Le brouillon est obsolète : les pesées ont changé depuis sa création.”
- “Le brouillon est obsolète : les catégories ont changé depuis sa création.”
- “Le brouillon est obsolète : les surfaces ont changé depuis sa création.”

### Validation du payload à appliquer

Avant snapshot et insertion, le payload de combats est contrôlé :

- présence d’un `group_key` ;
- présence d’un `category_id` ;
- vrais combats avec deux adversaires réels ;
- BYE avec exactement un participant réel et un `winner_entry_id` cohérent ;
- placeholders explicitement tolérés comme placeholders uniquement.

Les erreurs bloquantes renvoient des messages explicites :

- “Validation refusée : une catégorie ne possède pas de clé de groupe.”
- “Validation refusée : une catégorie ne possède pas de category_id.”
- “Validation refusée : un ou plusieurs combats du brouillon sont incomplets.”

### Protection contre les combats/résultats existants

Avant toute insertion :

- la génération verrouillée est refusée ;
- le mode `replace` est refusé ;
- les résultats existants sont refusés ;
- les combats existants/sensibles sont refusés ;
- le diagnostic de readiness reste exécuté pour confirmer l’état global.

Le message utilisateur ne contient plus de détails SQL bruts en cas d’échec d’insertion. Les détails techniques restent dans le diagnostic et les logs.

## Comportement de `apply_mode`

- `append` reste le mode accepté.
- `replace` est explicitement refusé par défaut.
- Aucun mode destructif n’est introduit dans ce lot.
- Toute stratégie de remplacement doit rester dans un workflow d’action sensible dédié.

## Snapshot et rollback

- Le snapshot est créé avant insertion définitive avec `draft_id`, `draft_hash`, `diagnostic_hash` et `apply_mode`.
- Si une insertion échoue, seuls les IDs insérés pendant cette validation sont rollbackés via `rollback_inserted_fights()`.
- Les combats existants avant validation ne sont pas supprimés.
- Les résultats ne sont pas modifiés.
- Une entrée d’audit est produite avec les IDs insérés et le nombre rollbacké.

## Fichiers modifiés

- `includes/competitions/Services/FightAutoGenerationService.php`
- `docs/LOT6-VALIDATION-BROUILLON-GENERATION.md`
- `tests/lot6-validation-brouillon-checklist.md`

## Tests manuels à réaliser sur compétition `[TEST]`

1. Générer un brouillon sain et vérifier que le contexte de validation est présent.
2. Valider le brouillon sain en mode `append`.
3. Régénérer un brouillon puis modifier une inscription : la validation doit refuser le brouillon comme obsolète.
4. Régénérer un brouillon puis modifier une pesée : la validation doit refuser le brouillon comme obsolète.
5. Régénérer un brouillon puis modifier une catégorie : la validation doit refuser le brouillon comme obsolète.
6. Régénérer un brouillon puis modifier les surfaces ou paramètres de génération : la validation doit refuser le brouillon comme obsolète.
7. Injecter volontairement un brouillon de test sans `group_key` : la validation doit refuser le payload.
8. Injecter volontairement un brouillon de test sans `category_id` : la validation doit refuser le payload.
9. Tenter `apply_mode=replace` : la validation doit être refusée.
10. Vérifier qu’aucune compétition réelle n’est utilisée pendant ces tests.

## Points restants pour le Lot 7

- Répartition contrôlée par surface après validation.
- Stabilisation de l’ordre de passage après impression.
- Diagnostics jour J par surface.
- Protection des surfaces/horaires des combats terminés ou verrouillés.
