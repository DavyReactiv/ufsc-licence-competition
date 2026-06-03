# Checklist Lot 7 — Surfaces et ordre de passage

> À exécuter uniquement sur une compétition préfixée `[TEST]`.

## Préparation

- [ ] Vérifier que la compétition cible commence par `[TEST]`.
- [ ] Vérifier qu’aucun résultat réel ne sera modifié.
- [ ] Vérifier que les combats de production ne sont pas utilisés.

## Scénarios

| Cas | Action | Résultat attendu | Blocage attendu |
|---|---|---|---|
| 1 surface | Configurer 1 surface active puis affecter | Tous les combats réels affectables sont sur la surface 1, ordre stable | Non |
| 2 surfaces | Configurer 2 surfaces actives puis affecter | Répartition lisible entre surfaces, `scheduled_order` renseigné si disponible | Non |
| 4 surfaces | Configurer 4 surfaces actives puis affecter | Répartition sans ordre SQL implicite | Non |
| Combat sans surface | Laisser un combat sans surface puis recalculer | Surface et ordre ajoutés si combat modifiable | Non |
| Combat déjà affecté | Recalculer l’affectation | Surface/ordre recalculés uniquement si combat modifiable | Non |
| Combat terminé | Tenter affectation ou changement manuel | Combat ignoré/refusé | Oui |
| Combat verrouillé | Tenter affectation ou changement manuel | Combat ignoré/refusé | Oui |
| Combat avec résultat | Tenter affectation ou changement manuel | Combat ignoré/refusé | Oui |
| BYE / placeholder | Lancer affectation | BYE/placeholder ignoré comme non réel | Oui pour déplacement manuel |
| Changement manuel | Choisir une surface active depuis plateau | Surface mise à jour + audit | Non |
| Surface inactive | Choisir une surface inactive | Changement refusé | Oui |
| Surface inexistante | Poster une surface inconnue | Changement refusé | Oui |
| Recalcul de l’ordre | Recalculer après ajout de surfaces | Ordre stable : `scheduled_order`, temps, `fight_no`, `id` | Non |
| Vue plateau | Ouvrir la page plateau | Groupement par surface et ordre lisible | Non |
| Impression par surface | Ouvrir une impression existante | Lecture seule, aucune donnée modifiée | Non |
| Données réelles | Vérifier l’absence d’action sur compétition réelle | Aucune donnée réelle modifiée | Oui |

## Vérifications post-test

- [ ] Le diagnostic d’affectation contient table, colonnes, combats trouvés, affectables et ignorés.
- [ ] La répartition par surface est compréhensible.
- [ ] Le premier et dernier ordre sont renseignés quand des combats sont affectés.
- [ ] Les combats terminés/verrouillés/avec résultat n’ont pas changé.
- [ ] Les BYE/placeholders ne sont pas traités comme combats réels.
- [ ] Les impressions restent non destructives.
