# Checklist Lot 4 — Groupement catégories avant génération

> À exécuter uniquement sur une compétition préfixée `[TEST]`. Ne pas lancer de génération réelle sur une compétition de production.

## Préparation

- [ ] Vérifier que la compétition s'appelle `[TEST] ...`.
- [ ] Vérifier que les inscriptions utilisées sont des données de test.
- [ ] Vérifier que les combats existants de production ne sont pas concernés.
- [ ] Ouvrir la preview/brouillon de génération sans valider définitivement si ce n'est pas nécessaire.

## Cas de groupement attendus

| Cas | Données de test | Comportement attendu |
|---|---|---|
| 2 participants même discipline / sexe / âge / poids | Deux inscrits complets dans la même catégorie sportive | Générable ; un groupe avec au moins un combat estimé |
| 3 participants même catégorie | Trois inscrits complets dans la même clé de groupe | Groupe générable avec warning `odd_participant_count` ou format adapté |
| 1 participant seul | Un seul inscrit complet dans une catégorie | Participant isolé ; warning `isolated_participant` et `insufficient_participants` |
| Participants de sexe différent | Deux inscrits identiques sauf sexe | Deux groupes distincts ou rejet si sexe manquant ; aucun mélange |
| Participants de poids différent | Deux inscrits identiques sauf catégorie de poids | Deux groupes distincts ; aucun mélange |
| Participants d'âge différent | Deux inscrits dans deux catégories d'âge différentes | Deux groupes distincts ; aucun mélange |
| Participant sans poids | Poids/catégorie de poids absent | Rejet `weight_class_missing` ou diagnostic poids/catégorie manquant |
| Participant sans date de naissance | Pas de date de naissance et catégorie non résolue | Rejet `birthdate_missing` avec `category_unresolved` si aucun `category_id`/libellé fiable |
| Participant sans discipline | Discipline absente | Rejet `discipline_missing` |
| Participant avec pesée manquante | Pesée obligatoire activée, aucune pesée valide | Rejet `weighin_missing` ; aucune création de combat |
| Participant avec pesée invalide | Pesée hors tolérance ou invalide | Rejet `weighin_missing` ou `reclass_pending` selon le statut |
| Participant externe incomplet | Identité ou données sportives externes incomplètes | Rejet existant d'éligibilité externe ou rejet de regroupement explicite |
| Participant déjà affecté à un combat | Inscrit déjà dans un combat actif/non supprimé | Rejet `already_assigned_fight` ; aucun doublon |
| Doublon de numéro combattant | Deux inscrits avec même numéro combattant | Blocage de génération avec diagnostic doublon |
| Catégorie introuvable | Aucun `category_id`, aucun libellé et assignation impossible | Rejet `category_unresolved` |
| Niveau/classe non défini avec `use_level_split` actif | Niveau vide lorsque la séparation par niveau est activée | Rejet `level_missing` ; correction admin nécessaire |

## Vérifications de preview

- [ ] Le total des inscrits analysés est cohérent.
- [ ] Le nombre d'éligibles est cohérent.
- [ ] Le nombre de rejetés est cohérent.
- [ ] Les raisons de rejet sont visibles et compréhensibles.
- [ ] `groups_generable` reflète les groupes avec au moins deux participants.
- [ ] `groups_insufficient` reflète les groupes avec moins de deux participants.
- [ ] `isolated_participants` augmente pour les participants seuls.
- [ ] `odd_groups` augmente pour les groupes impairs de plus d'un participant.
- [ ] Chaque groupe affiche un `group_key` et des `group_components` compréhensibles.

## Vérifications de non-régression

- [ ] Le workflow brouillon → validation reste disponible.
- [ ] Aucun combat réel n'est créé pendant les tests de preview.
- [ ] Les combats existants ne sont pas modifiés.
- [ ] Les résultats existants ne sont pas modifiés.
- [ ] Les impressions ne sont pas modifiées par ce lot.
