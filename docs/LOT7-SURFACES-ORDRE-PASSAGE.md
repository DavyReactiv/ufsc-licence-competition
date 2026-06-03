# Lot 7 — Surfaces, ordre de passage et stabilité avant impression

## Objectif

Ce lot fiabilise la répartition des combats par surface et l’ordre de passage sans refondre la génération, sans modifier les résultats et sans changer la structure SQL.

L’objectif est que l’admin puisse comprendre :

- sur quelle surface chaque combat est prévu ;
- dans quel ordre il passe ;
- quels combats ont été ignorés par sécurité ;
- si les données sont assez stables pour préparer les impressions du Lot 8.

## Flux actuel des surfaces

- Les surfaces sont normalisées et sauvegardées par les helpers `ufsc_competition_normalize_surfaces()`, `ufsc_competition_save_surfaces()` et `ufsc_competition_get_surfaces()`.
- L’affectation automatique est réalisée par `ufsc_competition_assign_surfaces_and_times()`.
- Les combats sont lus via `FightRepository`, qui utilise la table canonique `Db::fights_table()` (`ufsc_fights`).
- La vue plateau groupe les combats par `ring` et s’appuie désormais sur un tri explicite : `scheduled_order`, puis heure planifiée si disponible, puis `fight_no`, puis `id`.
- Les impressions lisent déjà les données de surface (`surface_name`, `surface`, `ring`, `tatami`, `area`, `surface_short_label`, `scheduled_surface`) en lecture seule.

## Table et colonnes utilisées

La table canonique reste `ufsc_fights`, via `\UFSC\Competitions\Db::fights_table()`.

Colonnes utilisées si elles existent :

- `id` ;
- `competition_id` ;
- `status` ;
- `ring` ;
- `surface_uuid` ;
- `surface_index` ;
- `surface_name` ;
- `surface_type` ;
- `surface_short_label` ;
- `scheduled_order` ;
- `scheduled_time` ;
- `fight_no` ;
- `winner_entry_id` / `winner_id` ;
- `result_method`, `result_type`, `result`, `result_note` ;
- `score`, `score_red`, `score_blue` ;
- `deleted_at` ;
- `locked_at` ;
- `updated_at`.

Aucune migration SQL n’est créée dans ce lot. Les mises à jour filtrent les payloads selon les colonnes réellement présentes.

## Logique d’affectation automatique

L’affectation automatique :

1. utilise uniquement la table canonique ;
2. vérifie l’existence de la table ;
3. vérifie les colonnes indispensables ;
4. filtre les colonnes modifiables selon la table réelle ;
5. ne cible que la compétition demandée ;
6. trie explicitement les combats avant affectation ;
7. répartit les groupes sur la surface la moins chargée ;
8. écrit `ring` et les métadonnées de surface disponibles ;
9. maintient `scheduled_order` si la colonne existe ;
10. retourne un diagnostic enrichi.

## Statuts protégés et cas ignorés

L’affectation automatique ignore les combats qui ne sont pas modifiables, notamment :

- combats supprimés (`deleted_at`) ;
- combats terminés (`completed`, `completed_at`) ;
- combats verrouillés (`locked`, `locked_at`) ;
- combats avec vainqueur ;
- combats avec résultat ou score ;
- BYE et placeholders, qui ne doivent pas être traités comme combats réels pour le planning opérationnel.

Le diagnostic expose :

- table utilisée ;
- colonnes disponibles ;
- combats trouvés ;
- combats affectables ;
- combats ignorés ;
- surfaces actives ;
- surfaces ignorées ;
- répartition par surface ;
- premier et dernier ordre affectés ;
- erreurs SQL éventuelles.

## Logique d’ordre

Règle retenue :

- ne pas dépendre d’un ordre SQL implicite ;
- utiliser `scheduled_order` comme ordre opérationnel lorsqu’il existe ;
- conserver `fight_no` comme numéro lisible et fallback d’ordre ;
- utiliser `scheduled_time` seulement si la colonne existe ;
- finir par `id` comme dernier critère stable.

## Changements manuels sécurisés

La vue plateau conserve l’action existante de changement de surface, mais la sécurise davantage :

- capability plateau / gestion combats requise ;
- nonce existant conservé ;
- accès compétition vérifié ;
- compétition verrouillée refusée ;
- combat terminé, verrouillé, supprimé, BYE ou placeholder refusé ;
- combat avec résultat/vainqueur/score refusé ;
- surface cible validée contre les surfaces actives ;
- log d’audit conservé.

Le changement manuel écrit `ring` et, si les colonnes existent, les métadonnées `surface_*`.

## Points non modifiés volontairement

- Pas de modification de l’algorithme de génération des combats.
- Pas de modification de la saisie des résultats.
- Pas de migration SQL.
- Pas de refonte des impressions.
- Pas de nouveau système complet de verrouillage post-impression.

## Recommandations pour le Lot 8 — impressions

Avant de finaliser les feuilles imprimables :

- ajouter un avertissement fort si une surface ou un ordre change après impression officielle ;
- prévoir un futur `printed_at`, `print_hash` ou `schedule_locked_at` ;
- envisager un verrou léger de planning après impression officielle ;
- afficher clairement dans les impressions l’ordre opérationnel (`scheduled_order`) et le numéro lisible (`fight_no`).

## Tests manuels `[TEST]`

- Vérifier une compétition `[TEST]` avec 1, 2 puis 4 surfaces actives.
- Vérifier la répartition par surface après validation du brouillon.
- Vérifier que BYE/placeholders ne reçoivent pas de surface opérationnelle.
- Vérifier qu’un combat terminé, verrouillé ou avec résultat ne peut pas changer de surface.
- Vérifier qu’une surface inactive ou inconnue est refusée.
- Vérifier que la vue plateau affiche un ordre stable.
- Vérifier que les impressions par surface restent en lecture seule.
