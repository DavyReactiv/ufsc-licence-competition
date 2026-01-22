# UFSC Licence Competition — Front Phase 1

Objectif :
- Exposer une **liste** et une **fiche détail** des compétitions côté front via **shortcodes**.
- Gérer les **droits d’accès** (connecté / club).
- Ne provoquer **aucune régression** sur l’admin existant.
- Préparer proprement la **Phase 2 – Inscriptions** (hooks uniquement).

---

## Shortcodes

### Liste des compétitions

```
[ufsc_competitions]
```

Attributs :
- `view="open|all"` (défaut : `open`)
- `season="2025-2026"` (optionnel)
- `discipline="k1"` (optionnel)
- `type="gala|selection"` (optionnel)
- `per_page="10"` (défaut : `10`)
- `show_filters="1|0"` (défaut : `1`)
- `require_login="1|0"` (défaut : `1`)
- `require_club="1|0"` (défaut : `0`)

Filtres GET (si `show_filters=1`) :
- `ufsc_season`
- `ufsc_discipline`
- `ufsc_type`
- `s` (recherche texte)

Pagination :
- `ufsc_page` (ex: `?ufsc_page=2`)

Comportement :
- Si `require_login=1`, l’utilisateur doit être connecté.
- Si `require_club=1`, l’utilisateur doit être rattaché à un club.
- Par défaut, seules les compétitions **open** sont visibles.

---

### Détail d’une compétition

```
[ufsc_competition id="123"]
```

Ou via query param moderne :
```
?competition_id=123
```

Compatibilité legacy (anciens liens conservés) :
```
?ufsc_competition_id=123
```

Attributs :
- `require_login="1|0"` (défaut : `1`)
- `require_club="1|0"` (défaut : `0`)

Comportement :
- Si aucun `id` n’est passé, le shortcode tente de résoudre l’ID via l’URL.
- Les compétitions archivées ou supprimées ne sont jamais visibles.
- La zone d’inscription est volontairement **inactive en Phase 1**.

---

## URL “pretty” optionnelle (rewrite)

URL cible :
```
/competitions/competition/{id}/
```

### Activation

1) Créer une page WordPress dédiée au détail (ex: “Détail compétition”).
2) Ajouter le shortcode suivant dans la page :
```
[ufsc_competition]
```
3) Déclarer l’ID de la page détail :
```php
add_filter( 'ufsc_competitions_front_details_page_id', function() {
	return 123; // Remplacer par l’ID réel de la page.
} );
```
4) Activer la réécriture :
```php
add_filter( 'ufsc_competitions_front_enable_rewrite', '__return_true' );
```
5) Réactiver le plugin **ou** aller dans **Réglages → Permaliens → Enregistrer**.

Notes importantes :
- Aucun flush de rewrite ne doit être exécuté en runtime.
- Le flush doit uniquement se faire à l’activation du plugin ou manuellement via l’écran Permaliens.

---

## Accès club (Phase 1)

La résolution du club utilisateur se fait par priorité :

Filtre (recommandé) :
```
apply_filters( 'ufsc_competitions_get_club_id_for_user', null, $user_id );
```

Fallback :
- `user_meta` : `ufsc_club_id`

Cela permet une intégration propre avec :
- Ultimate Member
- WooCommerce
- Table clubs UFSC
- Autre système externe

---

## Hooks Phase 2 (Inscriptions)

Ces hooks sont déjà actifs mais n’affichent rien par défaut :
```
do_action( 'ufsc_competitions_front_after_details', $competition );
do_action( 'ufsc_competitions_front_registration_box', $competition );
```

Ils serviront à :
- Afficher les formulaires d’inscription.
- Gérer quotas / validations.
- Connecter paiements & licences.

---

## Plan de test manuel (Phase 1)

- Sans rewrite : page liste + “Voir” → `?competition_id=ID`.
- Détail : `[ufsc_competition]` + `?competition_id=ID`.
- Legacy : `?ufsc_competition_id=ID`.
- Avec rewrite : `/competitions/competition/{id}/` route vers la page détail (avec `competition_id`).
