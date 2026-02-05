# UFSC Licence Competition — Dev README

Ce document décrit les fonctionnalités “dev” du plugin **UFSC Licence Competition**, avec focus sur :
- **Phase 1** : Front (liste + détail) via shortcodes, accès clubs, rewrite optionnel
- **Phase 2.3** : Exports admin plateau (CSV) et PDF
- **Phase 2.4** : Exports avancés + outils de contrôle opérationnels

> Objectif clé : **zéro régression** sur l’admin existant et une base extensible via hooks/filters.

---

## Phase 1 — Front competitions (Shortcodes + accès clubs + rewrite optionnel)

### Objectif de la Phase 1
- Exposer une **liste** et une **fiche détail** des compétitions côté front via **shortcodes**.
- Gérer les **droits d’accès** (connecté / club).
- Ne provoquer **aucune régression** sur l’admin existant.
- Préparer proprement les évolutions (Phase 2+) via **hooks** (sans UI obligatoire en Phase 1).

---

## Shortcodes

### 1) Liste des compétitions

```txt
[ufsc_competitions]
Attributs :

view="open|all" (défaut : open)

season="2025-2026" (optionnel)

discipline="k1" (optionnel)

type="gala|selection" (optionnel)

status="open|closed|draft" (optionnel)

per_page="10" (défaut : 10)

show_filters="1|0" (défaut : 1)

require_login="1|0" (défaut : 1)

require_club="1|0" (défaut : 0)

Filtres GET (si show_filters=1) :

ufsc_season

ufsc_discipline

ufsc_type

ufsc_status

s (recherche texte)

Pagination :

ufsc_page (ex: ?ufsc_page=2)

Comportement :

Si require_login=1, l’utilisateur doit être connecté.

Si require_club=1, l’utilisateur doit être rattaché à un club.

Par défaut (view=open), seules les compétitions open sont visibles.

Les compétitions archivées/supprimées ne sont jamais visibles côté front.

2) Détail d’une compétition
[ufsc_competition id="123"]
Ou via query param moderne :

?competition_id=123
Compatibilité legacy (anciens liens conservés) :

?ufsc_competition_id=123
Attributs :

require_login="1|0" (défaut : 1)

require_club="1|0" (défaut : 0)

Comportement :

Si aucun id n’est passé, le shortcode tente de résoudre l’ID via l’URL (competition_id, puis fallback legacy).

Les compétitions archivées ou supprimées ne sont jamais visibles.

La zone “inscriptions” peut exister via hooks, mais n’est pas obligatoire en Phase 1.

URL “pretty” optionnelle (rewrite)
URL cible :

/competitions/competition/{id}/
Activation (optionnelle)
Créer une page WordPress dédiée au détail (ex: “Détail compétition”).

Ajouter le shortcode suivant dans la page :

[ufsc_competition]
Déclarer l’ID de la page détail :

add_filter( 'ufsc_competitions_front_details_page_id', function() {
	return 123; // Remplacer par l’ID réel de la page.
} );
Activer la réécriture :

add_filter( 'ufsc_competitions_front_enable_rewrite', '__return_true' );
Réactiver le plugin ou aller dans Réglages → Permaliens → Enregistrer.

Notes importantes :

Aucun flush_rewrite_rules() ne doit être exécuté en runtime.

Le flush doit uniquement se faire à l’activation du plugin ou via l’écran Permaliens.

Accès club (Phase 1)
La résolution du club utilisateur se fait par priorité :

Filtre (recommandé) :

apply_filters( 'ufsc_competitions_get_club_id_for_user', null, $user_id );
Fallback :

user_meta : ufsc_club_id

Cela permet une intégration propre avec :

Ultimate Member

WooCommerce

Table clubs UFSC

Autre système externe

Hooks (extensibilité)
Ces hooks sont actifs pour permettre des extensions (UI inscriptions, stats, etc.) sans impacter le core :

do_action( 'ufsc_competitions_front_after_details', $competition );
do_action( 'ufsc_competitions_front_registration_box', $competition );
Phase 2.3 — Exports plateau (CSV) & PDF (Admin)
Objectif
Ajouter des exports “plateau” en CSV (UTF-8 BOM + séparateur ;) et en PDF.

Aucun impact sur l’admin existant en dehors des nouveaux boutons d’export.

CSV plateau (admin)
Export admin via admin-post.php :

action : ufsc_competitions_export_plateau_csv

nonce : ufsc_competitions_export_plateau_csv

Colonnes par défaut :

competition_id, competition_name, club_id, club_name, entry_id

fighter_lastname, fighter_firstname, birthdate

category, weight, discipline, type

status, submitted_at, validated_at, rejected_reason

PDF plateau (admin)
Téléchargement via admin-post.php :

action : ufsc_competitions_download_plateau_pdf

nonces :

ufsc_competitions_download_plateau_pdf (modes : plateau / controle)

ufsc_competitions_download_fiche_pdf (modes : fiche / fiche_complete)

mode : plateau | controle | fiche | fiche_complete

Si Dompdf est indisponible, l’export PDF doit échouer proprement (message admin clair).

Hooks & filtres Phase 2.3
apply_filters( 'ufsc_competitions_plateau_csv_columns', array $columns );
apply_filters( 'ufsc_competitions_plateau_entries_filters', array $filters, int $competition_id, string $status );
apply_filters( 'ufsc_competitions_plateau_csv_row', array $row, object $entry, object $competition );

do_action( 'ufsc_competitions_plateau_export_before', object $competition, string $status );
do_action( 'ufsc_competitions_plateau_pdf_before', object $competition, string $mode );
Plan de test manuel
Phase 1 (Front)
Sans rewrite : page liste + “Voir” → ?competition_id=ID

Détail : [ufsc_competition] + ?competition_id=ID

Legacy : ?ufsc_competition_id=ID

Avec rewrite : /competitions/competition/{id}/ route vers la page détail (avec competition_id)

Mini-checklist front compétitions (UX)
- Vérifier l’affichage des filtres (Saison, Discipline, Type, Statut, Recherche) et le bouton “Réinitialiser”.
- Confirmer la persistance des filtres via querystring et la compatibilité des paramètres legacy.
- Contrôler l’affichage des libellés (discipline/type/statut) dans la liste et la fiche détail.
- Confirmer l’affichage du bloc “Règlement & obligations” et l’ouverture des liens en nouvel onglet.

### Front Entries (Inscriptions) — Checklist de tests manuels

> Objectif : vérifier les redirects + notices front (ufsc_notice) sur le module Front Entries, sans notion de paiement.

#### Pré-requis communs
- Une compétition ouverte (dates valides) avec un club associé à l’utilisateur de test.
- Une page front contenant `[ufsc_competition]` et le bloc inscriptions.
- Un utilisateur connecté rattaché à un club (via filtre `ufsc_competitions_get_club_id_for_user` ou meta `ufsc_club_id`).

#### 1) Création / update / delete en draft
1. Ouvrir la page détail compétition (front).
2. Créer une inscription (bouton “Créer” ou formulaire d’ajout).
   - **Attendu** : redirection vers la page détail avec `ufsc_notice=entry_created`, formulaire en mode draft visible.
3. Modifier des champs requis puis sauvegarder.
   - **Attendu** : redirection avec `ufsc_notice=entry_updated`, données mises à jour.
4. Supprimer l’inscription en draft.
   - **Attendu** : redirection avec `ufsc_notice=entry_deleted`, inscription absente de la liste.

#### 2) Submit en conditions OK
1. Depuis une inscription en draft, cliquer “Soumettre”.
   - **Attendu** : redirection avec `ufsc_notice=entry_submitted`, statut non draft, UI de soumission désactivée.

#### 3) Submit quand quota_check renvoie ok=false
1. Activer un mode de test quota (voir “Mode debug quota_check” ci-dessous).
2. Soumettre une inscription en draft.
   - **Attendu** : redirection avec `ufsc_notice=error_quota`, inscription reste en draft.

#### 4) Actions quand inscriptions fermées
1. Fermer les inscriptions (dates dépassées) ou forcer la fermeture via filtre `ufsc_competitions_is_registration_open`.
2. Tenter création / update / submit / delete.
   - **Attendu** : redirection avec `ufsc_notice=error_closed`, UI/CTA bloqués côté front.

#### 5) Nonce invalide/absent
1. Supprimer `_wpnonce` dans la requête (ou ouvrir une ancienne URL de formulaire expirée).
2. Soumettre l’action (create/update/delete/submit/withdraw/cancel).
   - **Attendu** : redirection avec `ufsc_notice=error_forbidden`.

#### 6) Réédition d’une inscription rejected via withdraw/re-edit
1. Forcer une inscription en statut “rejected” (admin).
2. Côté front, utiliser l’action “Retirer” (withdraw) si disponible.
   - **Attendu** : redirection avec `ufsc_notice=entry_withdrawn` (ou notice configurée par le workflow).
3. Repasser en édition (re-edit) et sauvegarder.
   - **Attendu** : redirection avec `ufsc_notice=entry_updated`, inscription repasse en draft/édition.

#### 7) Admin reopen
1. Depuis l’admin, cliquer “Reopen” sur une inscription rejetée.
   - **Attendu** : redirection admin avec `ufsc_notice=entry_reopened`.

#### Mode debug quota_check (optionnel, sans impact prod)
Ajouter temporairement ce snippet dans un mu-plugin ou `functions.php` **uniquement en debug** :

```php
add_filter( 'ufsc_entries_quota_check', function( $result ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		return array( 'ok' => false, 'message' => 'Quota debug' );
	}
	return $result;
} );
```

Phase 2.3 (Exports admin)
Admin → Compétitions : utiliser “Exporter CSV plateau” sur une compétition.

Vérifier l’encodage (BOM + séparation ;) dans Excel.

Tester le filtre status via URL (ex: &status=validated) pour limiter l’export.

Admin → Compétitions : “Télécharger PDF plateau” (mode plateau).

Admin → Compétitions : “Télécharger PDF fiche” (mode fiche).

Vérifier qu’un utilisateur sans capacité validation n’accède pas aux exports.

Phase 2.4 — Exports avancés & contrôle opérationnel
Objectif
Ajouter des exports avancés côté admin (CSV filtré + PDF enrichis).

Permettre un export club CSV limité aux inscriptions validées.

Garder une architecture extensible par hooks (logo, header/footer PDF, colonnes CSV).

Sécurité stricte : nonces + capacités + vérification club (anti-IDOR).

---

## Release checklist (RC)
- Scans : conflits Git (`rg -n "<{7}|={7}|>{7}" .`) + `flush_rewrite_rules` hors activation.
- Lint : `php -l` sur fichiers modifiés + Front.php + Shortcodes.
- Smoke tests :
  - Admin → Compétitions : liste + actions ok.
  - Export CSV plateau : Excel ok (BOM + `;`).
  - Export PDF plateau/controle/fiche/fiche_complete : nonce ok, Dompdf absent => message clair.
  - Front : `[ufsc_competitions]` affiche, “Voir” => ?competition_id.
  - Legacy `?ufsc_competition_id` ok.
  - Export club : non connecté => redirect notice; club => CSV validées seulement; non-club => refus.
- Rewrite optionnel (si activé) : /competitions/competition/{id}/ route vers la page détail.

Exports admin (CSV avancé)
Endpoint admin-post.php :

action : ufsc_competitions_export_plateau_csv

nonce : ufsc_competitions_export_plateau_csv

Filtres GET supplémentaires :

status = draft|submitted|validated|rejected|withdrawn|cancelled

club_id = ID club (numérique)

category = libellé catégorie (string exact)

Les filtres sont optionnels et n’affectent pas l’export par défaut.

Exports admin PDF
Endpoint admin-post.php :

action : ufsc_competitions_download_plateau_pdf

nonces :

ufsc_competitions_download_plateau_pdf (modes : plateau / controle)

ufsc_competitions_download_fiche_pdf (modes : fiche / fiche_complete)

Modes :

plateau | controle | fiche | fiche_complete

Notes :

controle : version compacte (sans colonne statut).

fiche_complete : métadonnées complètes (dates, lieu, contact… si disponibles).

Les modes legacy plateau / fiche restent compatibles.

Export CSV club (validées uniquement)
Endpoint admin-post.php :

action : ufsc_competitions_export_club_csv

nonce : ufsc_competitions_export_club_csv_{competition_id}

Contraintes :

Utilisateur connecté.

Appartenance club obligatoire (ClubAccess).

Export uniquement des inscriptions validées du club courant.

Hooks & filtres Phase 2.4
CSV (admin) :

apply_filters( 'ufsc_competitions_plateau_csv_columns', array $columns );
apply_filters( 'ufsc_competitions_plateau_entries_filters', array $filters, int $competition_id, string $status, int $club_id, string $category );
apply_filters( 'ufsc_competitions_plateau_csv_row', array $row, object $entry, object $competition );
CSV (club) :

apply_filters( 'ufsc_competitions_club_csv_columns', array $columns );
apply_filters( 'ufsc_competitions_club_csv_row', array $row, object $entry, object $competition, int $club_id );
apply_filters( 'ufsc_competitions_club_export_filename', string $filename, object $competition, int $club_id );
do_action( 'ufsc_competitions_club_export_before', object $competition, int $club_id, array $entries );
PDF (plateau) :

apply_filters( 'ufsc_competitions_plateau_pdf_meta', array $meta, object $competition, string $mode );
apply_filters( 'ufsc_competitions_plateau_pdf_mode', string $mode );
apply_filters( 'ufsc_competitions_plateau_pdf_columns', array $columns, object $competition, string $mode );
apply_filters( 'ufsc_competitions_plateau_pdf_logo', string $logo_or_html, object $competition, string $mode );
apply_filters( 'ufsc_competitions_plateau_pdf_header_html', string $header_html, object $competition, string $mode, string $title, array $meta );
apply_filters( 'ufsc_competitions_plateau_pdf_footer_html', string $footer_html, object $competition, string $mode );
apply_filters( 'ufsc_competitions_plateau_pdf_html', string $html, object $competition, array $entries, string $mode );
apply_filters( 'ufsc_competitions_plateau_pdf_fallback', string $fallback, object $competition, array $entries, string $mode, string $html );
Front UI :

apply_filters( 'ufsc_competitions_show_club_export', bool $show, object $competition, int $club_id );
Plan de test manuel Phase 2.4
Admin :

Compétitions → Export CSV plateau avec filtres (ex: ?status=validated&club_id=12&category=Senior).

Compétitions → Télécharger PDF contrôle / fiche complète.

Vérifier qu’un compte sans capacité de validation reçoit un refus (403).

Club :

Ouvrir une compétition côté front, section “Vos inscriptions”.

Cliquer “Exporter CSV validées” et vérifier que seules les inscriptions validées du club sont exportées.

Tester avec un utilisateur non-club : export refusé.

Mini plan de test production :

Exporter un CSV admin (avec filtres status/club/category) et vérifier l’encodage en Excel.

Exporter un PDF plateau / contrôle / fiche / fiche complète.

Tester Dompdf absent : fallback + message d’indisponibilité (pas de PDF vide).

Tester un utilisateur sans capacité de validation : export admin refusé.

Tester un utilisateur non-club : export CSV club refusé.
