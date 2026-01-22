# UFSC Licence Competition — Front Phase 1 & 2

Objectif de la Phase 1 :
- Exposer une **liste** et une **fiche détail** des compétitions côté front via **shortcodes**
- Gérer les **droits d’accès** (connecté / club)
- Ne provoquer **aucune régression** sur l’admin existant
- Préparer proprement la **Phase 2 – Inscriptions** (hooks uniquement)

---

## Shortcodes

### Liste des compétitions

[ufsc_competitions]


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
- Si `require_login=1`, l’utilisateur doit être connecté
- Si `require_club=1`, l’utilisateur doit être rattaché à un club
- Par défaut, seules les compétitions **open** sont visibles

---

### Détail d’une compétition

[ufsc_competition id="123"]


Ou via query param moderne :
?competition_id=123


Compatibilité legacy (anciens liens conservés) :
?ufsc_competition_id=123


Attributs :
- `require_login="1|0"` (défaut : `1`)
- `require_club="1|0"` (défaut : `0`)

Comportement :
- Si aucun `id` n’est passé, le shortcode tente de résoudre l’ID via l’URL
- Les compétitions archivées ou supprimées ne sont jamais visibles
- La zone d’inscription est volontairement **inactive en Phase 1**

---

## URL “pretty” optionnelle (rewrite)

URL cible :
/competitions/competition/{id}/


### Activer le rewrite

```php
add_filter( 'ufsc_competitions_front_enable_rewrite', '__return_true' );
Créer la page de détail (obligatoire pour le rewrite)
Créer une page WordPress
Exemple : Détail compétition

Ajouter dans la page :

[ufsc_competition]
Déclarer l’ID de cette page :

add_filter( 'ufsc_competitions_front_details_page_id', function() {
	return 123; // Remplacer par l’ID réel de la page
} );
Réactiver le plugin ou aller dans
Réglages → Permaliens → Enregistrer

⚠️ Important :

Aucun flush de rewrite ne doit être exécuté en runtime

Le flush doit uniquement se faire à l’activation du plugin ou manuellement

Accès Club (Phase 1)
La résolution du club utilisateur se fait par priorité :

Filtre (recommandé) :

apply_filters( 'ufsc_competitions_get_club_id_for_user', null, $user_id );
Fallback :

user_meta: ufsc_club_id
➡️ Cela permet une intégration propre avec :

Ultimate Member

WooCommerce

Table clubs UFSC

Autre système externe

Hooks Phase 2 (Inscriptions)
Ces hooks sont déjà actifs mais n’affichent rien par défaut :

do_action( 'ufsc_competitions_front_after_details', $competition );
do_action( 'ufsc_competitions_front_registration_box', $competition );
Ils serviront à :

afficher les formulaires d’inscription

gérer quotas / validations

connecter paiements & licences

---

## Phase 2 — Module inscriptions front

Objectif :
- Ajouter un bloc d’inscriptions côté front **via les hooks** sans impacter l’admin.
- Utiliser `admin-post.php` pour les actions create/update/delete.

### Rendu front

Le module s’insère automatiquement dans :

```
do_action( 'ufsc_competitions_front_registration_box', $competition );
```

Comportement :
- Utilisateur non connecté : message “Vous devez être connecté pour vous inscrire.”
- Utilisateur connecté sans club : message “Accès réservé aux clubs affiliés.”
- Club connecté : liste + formulaire.

### Champs gérés (mapping défensif)

Le module essaye d’écrire dans les colonnes disponibles :
- prénom : `first_name`, `firstname`, `prenom`
- nom : `last_name`, `lastname`, `nom`
- nom complet : `athlete_name`, `full_name`, `name`, `licensee_name`
- date naissance : `birth_date`, `birthdate`, `date_of_birth`, `dob`
- sexe : `sex`, `gender`
- poids : `weight`, `weight_kg`, `poids`
- catégorie : `category`, `category_name`
- niveau/classe : `level`, `class`, `classe`

### Hooks d’extension

- `do_action( 'ufsc_competitions_entry_before_create', $payload, $competition, $club_id );`
- `do_action( 'ufsc_competitions_entry_after_create', $entry_id, $payload, $competition, $club_id );`
- `apply_filters( 'ufsc_competitions_entry_payload', $payload, $competition, $club_id );`
- `apply_filters( 'ufsc_competitions_entry_fields_schema', $schema, $competition );`

### Actions admin-post (front)

Les formulaires utilisent :
- `admin-post.php?action=ufsc_competitions_entry_create`
- `admin-post.php?action=ufsc_competitions_entry_update`
- `admin-post.php?action=ufsc_competitions_entry_delete`

### Plan de test manuel (Phase 2)

1) Créer 1 compétition **open**.
2) Se connecter avec un user club (`ufsc_club_id=XX`).
3) Ajouter 2 inscriptions.
4) Vérifier la liste.
5) Modifier 1 inscription.
6) Supprimer 1 inscription.
7) Vérifier qu’un autre club ne voit rien.
8) Vérifier qu’une compétition **archived** refuse la création.

---

## Phase 2.1 — UX clubs, licences & verrouillage inscriptions

Objectif :
- Améliorer l’expérience club (pré-remplissage depuis licenciés).
- Préparer la future connexion “licences/paiement” via filtres.
- Ajouter un verrouillage clair côté UI et serveur.

### Filtres “licences”

Recherche (liste) :
```
apply_filters( 'ufsc_competitions_front_license_search_results', array $results, string $term, int $club_id );
```

Format attendu :
```
[
  [
    'id' => 123,
    'label' => 'NOM Prénom — 2008-02-10',
    'first_name' => 'Prénom',
    'last_name' => 'Nom',
    'birthdate' => '2008-02-10',
    'sex' => 'm'
  ],
  ...
]
```

Sélection par ID :
```
apply_filters( 'ufsc_competitions_front_license_by_id', ?array $license, int $license_id, int $club_id );
```

### Catégorie automatique (optionnel)

- Si `Services/CategoryAssigner.php` est dispo, le front tente d’assigner une catégorie.
- Filtre d’override :
```
apply_filters( 'ufsc_competitions_front_category_from_birthdate', string $category, string $birthdate, object $competition );
```

### Verrouillage inscriptions

Logique :
- `status != open` → fermé
- `registration_deadline` (si présent) → fermé après la date

Filtre :
```
apply_filters( 'ufsc_competitions_front_registration_is_open', bool $is_open, object $competition, int $club_id );
```

### Notices front (PRG)

Le front utilise `ufsc_notice` :
- `entry_created` → “Inscription ajoutée.”
- `entry_updated` → “Inscription modifiée.”
- `entry_deleted` → “Inscription supprimée.”
- `entry_submitted` → “Inscription soumise.”
- `entry_withdrawn` → “Inscription retirée.”
- `entry_cancelled` → “Inscription annulée.”
- `error_invalid_fields` → “Champs invalides.”
- `error_closed` → “Compétition fermée.”
- `error_forbidden` → “Action non autorisée.”

### Mode brouillon (préparation validation)

- Si la colonne `status` existe : création en `draft`.
- Sinon si colonnes `notes/meta` : ajout d’un marqueur `status:draft`.
- Hook :
```
do_action( 'ufsc_competitions_entry_status_changed', $entry_id, $old, $new, $competition, $club_id );
```

### Plan de test manuel (Phase 2.1)

1) Club connecté : badge ouvert, ajout inscription OK, redirect vers `#ufsc-inscriptions`.
2) Sélection licence via filtre : pré-remplissage OK.
3) Catégorie auto (si helper dispo) : catégorie remplie.
4) Compétition fermée : formulaire désactivé + handlers refusent.
5) Ownership : un club B ne peut ni voir ni modifier les entries du club A.

---

## Phase 2.2 — Workflow inscriptions (validation UFSC)

Objectif :
- Ajouter un **workflow complet** côté front (statuts + actions).
- Isoler une **page admin dédiée** aux validations UFSC (sans toucher à l’admin existant).
- Préparer l’extension paiement/quota via filtres stables.

### Statuts & transitions

Statuts :
- `draft` : saisie en cours (modifiable par le club).
- `submitted` : soumis à validation UFSC (verrouillé côté club).
- `validated` : validé (verrouillé).
- `rejected` : refusé (motif requis).
- `cancelled` : annulé par le club avant validation (optionnel).

Transitions :
- draft → submitted (submit club).
- submitted → validated (validation UFSC).
- submitted → rejected (rejet UFSC + reason).
- submitted → draft (withdraw club, si autorisé).
- rejected → draft (ré-édition autorisée).
- draft/submitted → cancelled (cancel club, optionnel).

### Sécurité & règles d’accès

- Chaque action vérifie ownership (club_id) + nonce.
- Les actions club sont refusées si `registration_open` est faux.
- Les actions admin sont réservées à la capacité `ufsc_competitions_validate_entries` (fallback manage_options).
- PRG : redirect vers la fiche compétition + `#ufsc-inscriptions` pour les actions club.

### Admin minimal (validation dédiée)

- Nouvelle page “Inscriptions (Validation)” sous le menu Compétitions si dispo, sinon sous Outils.
- Liste filtrable : compétition, club_id, statut, recherche.
- Actions en ligne :
  - Valider
  - Rejeter (motif requis)
  - Ré-ouvrir (rejected → draft)

### Hooks & filtres Phase 2.2

```
apply_filters( 'ufsc_entries_allowed_statuses', array $statuses );
apply_filters( 'ufsc_entries_can_submit', bool $can, object $competition, int $club_id );
apply_filters( 'ufsc_entries_can_withdraw', bool $can, object $entry, object $competition, int $club_id );
do_action( 'ufsc_entries_after_submit', int $entry_id, object $entry, object $competition, int $club_id );
do_action( 'ufsc_entries_after_validate', int $entry_id, object $entry, object $competition, int $club_id );
do_action( 'ufsc_entries_after_reject', int $entry_id, object $entry, object $competition, int $club_id, string $reason );
apply_filters( 'ufsc_entries_lock_fields_on_submitted', array $locked_fields, object $entry, object $competition );
apply_filters( 'ufsc_entries_payment_required', bool $required, object $competition, int $club_id );
apply_filters( 'ufsc_entries_quota_check', array $result, object $competition, int $club_id );
```

### Exemples de filtres (paiement / quota / retrait)

Autoriser le retrait (par défaut true) :
```
add_filter( 'ufsc_entries_can_withdraw', function( $can, $entry, $competition, $club_id ) {
	return $can;
}, 10, 4 );
```

Bloquer la soumission si quota atteint :
```
add_filter( 'ufsc_entries_quota_check', function( $result, $competition, $club_id ) {
	return array(
		'ok' => false,
		'message' => 'Quota atteint pour ce club.',
	);
}, 10, 3 );
```

Forcer un paiement avant soumission :
```
add_filter( 'ufsc_entries_payment_required', function( $required, $competition, $club_id ) {
	return true;
}, 10, 3 );
```

### Plan de test manuel (Phase 2.2)

1) Club connecté :
   - créer 1 entry (draft).
   - soumettre → status submitted + badge.
2) Verrouillage :
   - soumise : formulaire et actions de modification bloquées.
3) Retrait (withdraw autorisé) :
   - submitted → draft.
4) Validation UFSC :
   - admin → valider → status validated (plus d’actions club).
5) Rejet UFSC :
   - admin → rejeter (motif requis) → status rejected + reason visible côté front.
6) Ré-édition (rejected → draft) :
   - bouton “Ré-éditer” côté club (si autorisé).
7) Sécurité IDOR :
   - club B ne peut pas soumettre/retirer les entries du club A.
8) Actions invalides :
   - soumettre une entry déjà submitted → erreur.
   - valider une entry draft → erreur.
9) Compétition fermée :
   - submit/withdraw/cancel refusés.

Plan de test manuel (Phase 1)
Sans rewrite
Créer une page avec :

[ufsc_competitions]
Vérifier :

affichage de la liste

filtres actifs

pagination fonctionnelle

bouton “Voir” → ?competition_id=ID

Détail simple
[ufsc_competition]
Puis :

?page-detail/?competition_id=ID
Compatibilité legacy
?page-detail/?ufsc_competition_id=ID
Avec rewrite
/competitions/competition/ID/
