# UFSC Licence Competition — Front Phase 1

## Shortcodes

### Liste des compétitions
```
[ufsc_competitions]
```
Attributs:
- `view="open|all"` (défaut: `open`)
- `season="2025-2026"` (optionnel)
- `discipline="k1"` (optionnel)
- `type="gala|selection"` (optionnel)
- `per_page="10"` (défaut: `10`)
- `show_filters="1|0"` (défaut: `1`)
- `require_login="1|0"` (défaut: `1`)
- `require_club="1|0"` (défaut: `0`)

Filtres GET (si `show_filters=1`):
- `ufsc_season`, `ufsc_discipline`, `ufsc_type`, `s`
- Pagination via `ufsc_page`

### Détail d’une compétition
```
[ufsc_competition id="123"]
```
Ou via query param:
```
?competition_id=123
```

Attributs:
- `require_login="1|0"` (défaut: `1`)
- `require_club="1|0"` (défaut: `0`)

## URL “pretty” optionnelle

Un endpoint peut être activé via filtre:
```php
add_filter( 'ufsc_competitions_front_enable_rewrite', '__return_true' );
```
Puis flusher les règles à l’activation:
```php
\UFSC\Competitions\Front\Front::flush_rewrite_rules();
```
L’URL cible est:
```
/competitions/competition/{id}/
```

## Hooks Phase 2

- `do_action( 'ufsc_competitions_front_after_details', $competition );`
- `do_action( 'ufsc_competitions_front_registration_box', $competition );`
