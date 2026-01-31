# UFSC – Licences, ASPTT, Documents, Compétitions & Inscriptions

Add‑on WordPress institutionnel pour l’UFSC : centralisation des **licences**, import **ASPTT**, gestion des **documents PDF**, **espace clubs**, et module **Compétitions** (admin + front).

---

## 1) Titre + pitch

Le plugin **UFSC Licence Competition** fournit un socle fiable pour :
- la **gestion des licences UFSC** côté admin,
- la **synchronisation ASPTT** (prévisualisation, import incrémental, rollback),
- l’**association sécurisée des PDF nominatives**,
- un **espace clubs** (consultation licences + PDF),
- un **module Compétitions** complet (back‑office + front + exports).

---

## 2) Prérequis

- **WordPress** ≥ 6.0.
- **PHP** ≥ 7.4 (PHP 8.0+ recommandé).
- **Tables UFSC existantes** : `wp_ufsc_licences`, `wp_ufsc_clubs` (dépendances indispensables).
- **Optionnel** (exports PDF Compétitions) : **Dompdf** côté serveur.

> Aucune dépendance WooCommerce / Elementor n’est requise. Les shortcodes peuvent simplement être placés dans une page WordPress.

---

## 3) Installation

1. Téléverser le plugin dans `wp-content/plugins/ufsc-licence-competition/`.
2. Activer le plugin.
3. À l’activation, le plugin :
   - crée ses tables (documents, import, compétitions),
   - ajoute les colonnes manquantes sur `ufsc_licences`,
   - reconstruit les index nécessaires.
4. Vérifier l’état via **UFSC LC — Status** (menu admin) : tables, compteurs, dernier import.

---

## 4) Fonctionnalités principales (exhaustif)

### A) Gestion licences (admin)

**Menu :** **UFSC Licences → Licences**

- **Liste** avec filtres : club, statut, catégorie, saison, compétition, PDF.
- **Tri** (N° licence, Nom, Date de naissance) + **pagination** (25/50/100).
- **Export CSV** des licences (filtres actifs).
- **Colonnes affichées** (adaptées aux schémas historiques) :
  - N° licence, N° ASPTT, Nom (nom_affiche), Prénom, Club, Statut,
  - Saison (année de fin), Catégorie, Date de naissance.
- **Normalisation nom / nom_licence** → `nom_affiche` (COALESCE + compat legacy).
- **Catégorie** : `categorie_affiche` (COALESCE `categorie` / `category` / `legacy_category`) + **fallback** calculé depuis la date de naissance + saison.
- **Actions massives** :
  - marquer “À vérifier”,
  - supprimer association PDF,
  - recalculer catégories,
  - changer saison (si colonne saison disponible).
- **Gestion manuelle N° ASPTT** : écran dédié + action rapide “Modifier N° ASPTT”.

**Paramètres & maintenance (admin)**

**Menus :**
- **UFSC Licences → Paramètres** :
  - onglets Général, Saisons & Catégories, Import ASPTT, Licences, Clubs, Sécurité & droits, PDF & documents, Journal & maintenance.
- **UFSC LC — Status** : état des tables, compteurs, dernier import + actions “Rebuild indexes” / “Recreate tables”.

### B) Import ASPTT (admin)

**Menu :** **UFSC Licences → Import ASPTT**

- Téléversement CSV avec contrôle taille/type.
- **Prévisualisation** + mapping des colonnes (limite configurable).
- **Simulation (dry‑run)** avec rapport d’erreurs.
- **Import réel** avec options :
  - **incrémental** (hash des lignes, anti‑double import),
  - **auto‑validation** selon seuil configuré,
  - **forçage club** + **alias automatique** depuis la colonne NOTE,
  - **override saison**.
- **Rollback** du dernier import (option activable).
- **Exports** : CSV d’erreurs + CSV “delta”.
- **Matching strict** : association par numéro de licence / club, sinon statut “needs_review” ou rejet.
- **Règles de non‑écrasement** : N° ASPTT existant préservé si colonne dédiée déjà renseignée.

**Onglet Review** :
- Validation/rejet des associations,
- Corbeille/restauration,
- Assignation manuelle d’un club,
- Enregistrement d’alias depuis NOTE.

### C) Documents licences (PDF)

**Menu :** **UFSC Licences → UFSC Licences**

- Écran **“Associer un PDF de licence”**.
- Recherche **simple** (texte libre) + **mode avancé** (nom/prénom/date).
- **Autocomplete clubs** via AJAX.
- Association PDF nominatif à la bonne licence.
- Statut PDF : **Associé / Manquant**.

### D) Front – espace clubs (licences)

**Shortcodes licences**
- `[ufsc_lc_licences]` (recommandé)
- `[ufsc_lc_club_licences_asptt]`
- Legacy : `[ufsc_licences]`, `[ufsc_club_licences_asptt]`

**Paramètres (query string)**
- `q`, `statut`, `categorie`, `competition`, `pdf`,
- `orderby` (`nom_licence`, `date_naissance`, `date_asptt`),
- `order` (`asc`/`desc`), `per_page` (10/25/50/100).

**Fonctionnalités**
- Liste licences du club avec filtres + pagination.
- Statistiques : total, avec/sans PDF, avec/sans N° ASPTT.
- PDF : **voir / télécharger** (si autorisé) avec endpoint sécurisé.

**Accès club**
- Utilisateur connecté obligatoire.
- Capability optionnelle (Paramètres → Accès clubs).
- Résolution club :
  - `ufsc_clubs.responsable_id` ou `user_meta` `club_id` (module Licences),
  - `user_meta` `ufsc_club_id` ou filtre `ufsc_competitions_get_club_id_for_user` (module Compétitions).

### E) Module Compétitions (admin)

**Menu principal :** **Compétitions** (`ufsc-competitions`)

Sous‑menus : Compétitions, Catégories, Inscriptions, Combats, Timing Profiles, Qualité, Impression, Paramètres, Logs, Guide.

**Compétitions (CRUD)**
- Champs : nom, discipline, type, saison, statut, dates, contact, lieu.
- Statuts : `open`, `draft`, `closed`, `archived`.
- Forclusion : `registration_deadline`.
- Paramètres d’évènement : `age_reference`, `weight_tolerance`, `allowed_formats`.

**Catégories**
- Âge min/max, poids min/max, sexe, niveau, format, discipline.

**Inscriptions (admin)**
- Création/édition : `club_id` + `licence_id` (recherche AJAX nom/prénom/DDN).
- Statuts : `draft`, `submitted`, `validated`, `rejected`, `cancelled`, `withdrawn`.
- Anti‑doublon : unicité `competition_id + licence_id`.
- **Inscriptions (Validation)** : écran dédié via capability spécifique.

**Combats / Bouts**
- Génération automatique + édition manuelle.
- Statuts : `scheduled`, `running`, `completed`.
- Champs : ring/tatami, round, scores, vainqueur, méthode.

### F) Module Compétitions (front)

**Shortcodes**
- `[ufsc_competitions]` : liste des compétitions (filtres + pagination).
  - Attributs : `view` (open|all), `season`, `discipline`, `type`, `per_page`, `show_filters`, `require_login`, `require_club`.
  - Filtres GET (si `show_filters=1`) : `ufsc_season`, `ufsc_discipline`, `ufsc_type`, `s`, `ufsc_page`.
- `[ufsc_competition]` : fiche détail + bloc “Inscriptions”.
  - Attributs : `id`, `require_login`, `require_club`.
  - URL : `?competition_id=123` (ou legacy `?ufsc_competition_id=123`).
  - Rewrite optionnel via filtres `ufsc_competitions_front_details_page_id` + `ufsc_competitions_front_enable_rewrite`.

**Inscriptions clubs (front)**
- Accessible aux utilisateurs connectés et rattachés à un club.
- Recherche licenciés via **pont UFSC Licences** (nom/prénom/n° licence).
- Création / mise à jour / soumission / retrait / annulation.
- **Calcul automatique catégorie** via AJAX (poids + DDN + paramètres compétition).
- **Export CSV club** (inscriptions validées uniquement, nonce + anti‑IDOR).

### G) Exports

- **Licences (admin)** : export CSV (filtres actifs) avec colonnes :
  `club`, `nom`, `prenom`, `dob`, `statut`, `categorie`, `saison`, `age_ref`, `competition`, `n_asptt`, `date_asptt`, `has_pdf`.
- **Compétitions (admin)** :
  - export CSV plateau,
  - export PDF plateau / contrôle / fiche / fiche complète (Dompdf requis).
- **Compétitions (front club)** : export CSV des **inscriptions validées** du club.

---

## 5) Structure technique (pour dev)

```
/ufsc-licence-competition
├── ufsc-licence-competition.php
├── includes/
│   ├── admin/                (menus admin, list tables, settings, status)
│   ├── import/               (service import ASPTT)
│   ├── export/               (export CSV licences)
│   ├── competitions/         (module compétitions complet)
│   ├── class-ufsc-*.php       (capabilities, helpers, migrations)
├── assets/                    (assets admin)
└── languages/                 (traductions)
```

**Points d’extension (hooks / filtres)**
- Licences : `ufsc_lc_calculate_category`, `ufsc_lc_register_settings`, `ufsc_lc_enable_legacy_compatibility`, `ufsc_lc_dependencies_met`.
- Compétitions front : `ufsc_competitions_front_after_details`, `ufsc_competitions_front_registration_box`, `ufsc_competitions_front_competition_url`.
- Exports admin : `ufsc_competitions_plateau_csv_columns`, `ufsc_competitions_plateau_entries_filters`, `ufsc_competitions_plateau_csv_row`, `ufsc_competitions_plateau_pdf_*`.
- Exports club : `ufsc_competitions_club_export_before`, `ufsc_competitions_club_csv_columns`, `ufsc_competitions_club_csv_row`, `ufsc_competitions_club_export_filename`.

**Helpers partagés**
- `ufsc_lc_format_birthdate`, `ufsc_lc_get_nom_affiche`, `ufsc_lc_compute_category_from_birthdate`.

---

## 6) Base de données

### Licences / documents
- `wp_ufsc_licences` (dépendance)
- `wp_ufsc_clubs` (dépendance)
- `wp_ufsc_licence_documents`
- `wp_ufsc_licence_documents_meta`
- `wp_ufsc_asptt_aliases`
- `wp_ufsc_asptt_import_logs`
- `wp_ufsc_asptt_import_hashes`

**Champs clés / compat legacy**
- `nom` / `nom_licence` → **nom_affiche**
- `categorie` / `category` / `legacy_category` → **categorie_affiche**
- `season_end_year` (avec fallback `season` / `saison`)
- `numero_licence_asptt` / `asptt_number`

### Compétitions
- `wp_ufsc_competitions`
- `wp_ufsc_competition_categories`
- `wp_ufsc_competition_entries`
- `wp_ufsc_fights`
- `wp_ufsc_competition_logs`
- `wp_ufsc_timing_profiles`

---

## 7) Dépannage / erreurs fréquentes

- **MySQL “Unknown column deleted_at” (fights)**
  - Cause : table `wp_ufsc_fights` non migrée.
  - Action : relancer l’upgrade DB (visite admin + activation) ou utiliser **UFSC LC — Status**.

- **Filtres “Catégorie” = 0 résultat**
  - Cause : valeurs stockées avec espaces/accents/casse.
  - Action : normaliser (TRIM/LOWER) et recalculer via action de masse.

- **Fatal front licences (in_array haystack null)**
  - Cause fréquente : table `ufsc_licences` absente ou colonnes non détectées.
  - Action : vérifier dépendances `ufsc_licences` / `ufsc_clubs`, réactiver le plugin.

- **PDF inaccessible côté club**
  - Vérifier : Paramètres → “PDF & documents” (auth obligatoire, club match, téléchargement autorisé).

---

## 8) Sécurité & bonnes pratiques

- **Capabilities** : toutes les pages admin vérifient les droits (`ufsc_lc_manage`, `ufsc_lc_import`, `ufsc_lc_export`, `ufsc_competitions_validate_entries`).
- **Nonces** sur actions admin, imports, exports, PDF, validations.
- **Sanitization** systématique (`sanitize_text_field`, `absint`, `sanitize_key`).
- **SQL sécurisé** via `$wpdb->prepare`.
- **Exports club** : blocage des overrides `club_id` (anti‑IDOR).

---

## 9) Roadmap (optionnel)

- Améliorer la recherche PDF (fuzzy match).
- Normaliser les catégories (accents, espaces) côté import.
- Tests automatisés + jeux de données.

---

## 10) Changelog (résumé)

- Module Compétitions front : shortcodes `[ufsc_competitions]` + `[ufsc_competition]`.
- Module Inscriptions front + export CSV club validé.
- Exports admin : CSV plateau + PDF plateau/fiche/contrôle/fiche complète.
- Normalisation **nom_affiche / categorie_affiche** + fallback catégorie auto.
- Colonnes saison + gestion manuelle N° ASPTT.
- Import ASPTT : dry‑run, incrémental, delta CSV, verrou anti double‑import.

---

## 11) Licence / Support

Plugin propriétaire – usage réservé à l’UFSC.
Support : via l’admin UFSC.
