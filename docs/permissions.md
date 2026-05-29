# Droits UFSC Licences / Compétitions

Ce plugin consomme désormais les droits UFSC centralisés qui doivent idéalement être fournis par **UFSC Gestion**. Il ne fournit pas d’interface de gestion des rôles et ne doit pas devenir le centre de pilotage des habilitations.

## Capabilities consommées

### UFSC Licences

- `ufsc_licences_read` : accès lecture aux listes et fiches de licences.
- `ufsc_licences_manage` : création, modification, validation, suppression, import/export sensible et actions de masse sur les licences.

### Compétitions

- `ufsc_competitions_read` : consultation des compétitions, inscriptions, combats, tableaux et résultats.
- `ufsc_competitions_manage` : création, modification, suppression/restauration, génération des combats, validations, recalculs, résultats et organisation.

### Régions

- `ufsc_all_regions_access` : accès sans restriction régionale.
- `_ufsc_allowed_regions` : user meta utilisée en fallback si UFSC Gestion ne fournit pas ses helpers globaux.

## Couche de compatibilité

Le fichier `includes/permissions/ufsc-licences-permissions.php` expose des wrappers internes :

- `ufsc_lc_user_can( $capability, $user_id = null )`
- `ufsc_lc_user_can_access_region( $region, $user_id = null )`
- `ufsc_lc_current_user_allowed_regions()`
- `ufsc_lc_is_readonly_context( $module )`
- `ufsc_lc_get_license_region( $license_id )`
- `ufsc_lc_get_competition_region( $competition_id )`
- `ufsc_lc_current_user_can_access_license( $license_id )`
- `ufsc_lc_current_user_can_access_competition( $competition_id )`

Quand UFSC Gestion fournit `ufsc_user_can()`, `ufsc_user_can_access_region()` ou `ufsc_current_user_allowed_regions()`, ces fonctions sont utilisées. Sinon, le plugin applique un fallback sécurisé : les administrateurs WordPress restent autorisés, les capabilities WordPress natives sont respectées, et les régions sont lues depuis `_ufsc_allowed_regions`.

## Lecture seule

Un utilisateur ayant `ufsc_licences_read` sans `ufsc_licences_manage` peut consulter les licences, mais les formulaires, boutons et endpoints de modification restent protégés côté serveur.

Un utilisateur ayant `ufsc_competitions_read` sans `ufsc_competitions_manage` peut consulter les compétitions, les inscriptions et les combats, mais les actions de création, génération, modification, suppression et validation sont masquées et restent protégées côté serveur.

## Fallback UFSC Gestion désactivé

À l’activation, ce plugin ajoute uniquement les capabilities minimales au rôle `administrator` :

- `ufsc_licences_read`
- `ufsc_licences_manage`
- `ufsc_competitions_read`
- `ufsc_competitions_manage`
- `ufsc_all_regions_access`

Aucun rôle métier n’est créé par ce plugin. Les administrateurs WordPress restent autorisés via `manage_options`, même si UFSC Gestion est désactivé.

## Restrictions régionales

Les wrappers vérifient l’accès régional via UFSC Gestion si disponible. En fallback, `ufsc_all_regions_access` donne un accès global ; sinon `_ufsc_allowed_regions` est comparé à la région réelle de l’objet quand elle est disponible.

Points connus : les licences récupèrent leur région via le club lié (`ufsc_clubs.region`). Les compétitions utilisent `organizer_region` puis `venue_region` si ces colonnes existent. Si une région est absente, les administrateurs sont autorisés ; pour un utilisateur limité, les helpers d’accès direct refusent par défaut les opérations sensibles.

## Tests manuels conseillés

1. Administrateur WordPress : vérifier l’accès complet aux licences et compétitions.
2. Compte `ufsc_licences_read` seul : vérifier la liste licences, l’absence d’actions de modification, et le blocage des POST sensibles.
3. Compte `ufsc_competitions_read` + `ufsc_competitions_manage` : vérifier création/modification, génération des combats et résultats.
4. Compte combiné `ufsc_licences_read` + `ufsc_competitions_read` + `ufsc_competitions_manage` : licences en lecture seule, compétitions modifiables.
5. Compte régional avec `_ufsc_allowed_regions` : vérifier le filtrage et le refus d’accès direct hors région quand les données portent une région.
6. UFSC Gestion désactivé : vérifier l’absence d’erreur fatale et l’accès complet administrateur.
