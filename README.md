# UFSC Licence Competition

Plugin WordPress institutionnel (add-on) développé par **StudioReactiv** pour l’UFSC (Union Française des Sports de Combat).

Il couvre la **gestion des licences**, l’**import ASPTT**, les **documents PDF nominatives**, l’**accès clubs** et le module **UFSC – Compétitions** (administration complète des compétitions, catégories, inscriptions et combats).

---

## Description

**Objectif** : centraliser et fiabiliser les données de licences UFSC dans WordPress, tout en outillant la préparation et la gestion des compétitions.

**Périmètre** :
- Gestion des licences UFSC.
- Import / synchronisation ASPTT + suivi qualité.
- Consultation des licences par les clubs via shortcodes.
- Gestion des documents PDF nominatives.
- Module **UFSC – Compétitions** (back-office complet).

---

## Fonctionnalités principales

### Licences & clubs
- Import et synchronisation des licences issues d’exports ASPTT (CSV).
- Raccordement des licences aux adhérents et aux clubs (via données CSV, notamment **Note** et alias).
- Consultation des licences par les clubs via une interface dédiée (shortcode).
- Gestion des documents PDF nominatives (association, téléchargement sécurisé).
- Filtres, tri, pagination côté club.
- Outils d’administration pour le suivi des imports et la qualité des données (Import / Review).

### UFSC – Compétitions (module admin)
- Gestion des compétitions (CRUD, statuts, discipline obligatoire, forclusion).
- Gestion des catégories et formats (âge/poids/sexe/niveau, poules/élimination).
- Gestion des inscriptions (création, validation, contrôle des doublons).
- Contrôles qualité (détection des inscriptions incomplètes ou en attente).
- Combats (génération simple + suivi des résultats).
- Impression (listes imprimables par compétition).
- Paramètres : disciplines, référentiel catégories UFSC, tolérance pesée, âge de référence, formats autorisés.

---

## Installation / Mise à jour

1. Téléverser le plugin dans `wp-content/plugins/ufsc-licence-competition/`.
2. Activer le plugin dans WordPress.
3. **En cas de mise à jour majeure (caps/menus)** :
   - Désactiver puis réactiver le plugin pour (ré)appliquer les capabilities.
4. Vérifier la présence des tables sources **UFSC** (`ufsc_licences`, `ufsc_clubs`).

---

## Rôles & capabilities

Capabilities ajoutées à l’activation (rôle Administrateur par défaut) :
- `ufsc_manage_competitions`
- `ufsc_manage_competition_results`
- `ufsc_club_manage_entries`

Autres capabilities internes utilisées par le plugin :
- `ufsc_competitions_manage` (cap d’accès au menu **UFSC – Compétitions**).
- `ufsc_lc_manage`, `ufsc_lc_import`, `ufsc_lc_export` (licences/import/export).

> Remarque : si le menu **UFSC – Compétitions** n’apparaît pas, réactivez le plugin ou vérifiez la cap `ufsc_competitions_manage`.

---

## Module Admin **UFSC – Compétitions**

### Menus & slugs
Menu principal : **UFSC – Compétitions** (slug `ufsc_competitions`).

Sous-menus :
- **Compétitions** (`ufsc_competitions_competitions`)
- **Catégories & formats** (`ufsc_competitions_categories`)
- **Inscriptions** (`ufsc_competitions_registrations`)
- **Contrôles qualité** (`ufsc_competitions_quality`)
- **Impression** (`ufsc_competitions_print`)
- **Combats** (`ufsc_competitions_bouts`)
- **Paramètres** (`ufsc_competitions_settings`)
- **Aide & Guide** (`ufsc_competitions_guide`)

### Compétitions (CRUD + pilotage)
- Champs obligatoires : **Nom**, **Discipline**, **Type**, **Saison**.
- Statuts gérés : `draft`, `preparing`, `open`, `running`, `closed`, `archived`.
- Forclusion : **Date limite d’inscription** (`registration_deadline`).
- Paramètres competition :
  - Référence d’âge (`age_reference`) : 31/12 ou 31/08.
  - Tolérance pesée (`weight_tolerance`).
  - Formats autorisés (`allowed_formats`, ex. `pool, single_elim`).
- Pilotage : KPI + actions rapides (ouvrir inscriptions, appliquer référentiel, générer combats, archiver).

### Catégories & formats
- Champs : nom, discipline, compétition (optionnel), âge min/max, poids min/max, sexe, niveau, format.
- Format libre (ex. « poule », « élimination »).
- Discipline héritée automatiquement si la catégorie est rattachée à une compétition.

### Inscriptions
- Création/modification des inscriptions par compétition.
- Statuts : `draft`, `submitted`, `validated`, `rejected`, `withdrawn`.
- Vérification des doublons : **unicité competition + licencié**.
- Champ **club_id** disponible pour rattacher un club.

### Contrôles qualité
- Détection automatique :
  - Inscriptions sans catégorie.
  - Inscriptions en attente de validation.

### Combats
- Statuts : `scheduled`, `running`, `completed`.
- Génération simple depuis les inscriptions validées (par catégorie, 2 par 2).
- Saisie manuelle possible (ring/tatami, round, scores, vainqueur, méthode).

### Impression
- Impression des listes par compétition :
  - Liste des inscriptions.
  - Liste des catégories.

### Paramètres
- **Disciplines** : libellés + type (Tatami / Ring / Autre).
- **Référentiel catégories UFSC** : chargement/mise à jour (package UFSC 2024/2025).

---

## Inscriptions clubs (Front-end)

Le module **UFSC – Compétitions** est **back-office uniquement**.

- **Aucun shortcode front-end n’est fourni** pour la saisie des inscriptions aux compétitions.
- Les inscriptions sont gérées par l’admin via **UFSC – Compétitions → Inscriptions**.
- La capability `ufsc_club_manage_entries` est ajoutée à l’activation mais **aucune page front-end ne l’exploite actuellement**.

### Pré-requis côté clubs (pour l’espace licences)
Ces prérequis servent à l’accès **front-end** existant (licences) :
- L’utilisateur doit être **rattaché à un club** via :
  - `ufsc_clubs.responsable_id` **ou**
  - `user_meta` `club_id`.
- Les licenciés doivent être reliés au club (colonne `club_id` dans `ufsc_licences`).

### Règles d’accès (espace club licences)
- Accès réservé aux utilisateurs connectés.
- Restriction possible via la capability définie dans les paramètres « Accès clubs ».
- Les PDF sont servis via un endpoint sécurisé avec vérification d’appartenance au club.

---

## Shortcodes

### Disponibles
- `[ufsc_lc_club_licences_asptt]`
- `[ufsc_lc_licences]` (alias recommandé)

### Legacy (compatibilité ascendante)
- `[ufsc_club_licences_asptt]`
- `[ufsc_licences]`

### Paramètres (query string)
Les shortcodes licences acceptent des paramètres via l’URL :
- `q` : recherche texte (nom, prénom, N° ASPTT).
- `statut`
- `categorie`
- `competition`
- `pdf` : `1` ou `0`.
- `orderby` : `nom_licence`, `date_naissance`, `date_asptt`.
- `order` : `asc` / `desc`.
- `per_page` : `10`, `25`, `50`, `100`.

### Exemple Elementor
1. Créer une page « Espace Club ».
2. Ajouter un widget **Shortcode**.
3. Coller : `[ufsc_lc_licences]`.

---

## Données / Base de données

Tables **licences** :
- `wp_ufsc_licence_documents` : documents PDF nominatives.
- `wp_ufsc_licence_documents_meta` : meta documents.
- `wp_ufsc_asptt_aliases` : alias ASPTT → club.
- `wp_ufsc_asptt_import_logs` : logs d’import.

Tables **compétitions** :
- `wp_ufsc_competitions` : compétitions.
- `wp_ufsc_competition_categories` : catégories.
- `wp_ufsc_competition_entries` : inscriptions.
- `wp_ufsc_fights` : combats.
- `wp_ufsc_competition_logs` : journal d’actions.

> Les tables `wp_ufsc_licences` et `wp_ufsc_clubs` doivent déjà exister (dépendance UFSC).

---

## Workflow A→Z (résumé)

1. **Créer la compétition** (type, discipline, saison, dates, forclusion).
2. **Configurer les disciplines** et charger le référentiel UFSC si besoin.
3. **Appliquer le référentiel catégories** puis ajuster.
4. **Ouvrir les inscriptions** (statut `open`).
5. **Contrôler/valider les inscriptions**.
6. **Générer les combats** (inscriptions validées + catégories).
7. **Planifier et saisir les résultats**.
8. **Imprimer** les listes nécessaires.
9. **Clôturer** puis **archiver**.

---

## FAQ / Dépannage

**Le menu “UFSC – Compétitions” est invisible**
- Vérifier la capability `ufsc_competitions_manage`.
- Réactiver le plugin pour (ré)appliquer les caps.

**Menus en double**
- Vérifier qu’une seule copie du plugin est active.
- Éviter de charger manuellement `includes/competitions/bootstrap.php` dans un autre plugin/thème.

**Forclusion / inscriptions closes trop tôt**
- Vérifier la **Date limite d’inscription** sur la compétition.
- Le statut doit être **Ouvert** (`open`) pour accepter les inscriptions.

**Le club ne voit pas ses licenciés**
- Vérifier le rattachement club/licenciés (`club_id` dans `ufsc_licences`).
- Vérifier le lien utilisateur → club (`responsable_id` ou `user_meta` `club_id`).

---

## Changelog

### 1.5.0
- Ajout du module **UFSC – Compétitions** (admin complet, catégories, inscriptions, combats, impression).
- Ajout des tables compétitions et des capabilities associées.

---

## Auteur

**StudioReactiv**

---

## Licence

Plugin propriétaire – usage réservé à l’UFSC.
Toute réutilisation, reproduction ou diffusion sans autorisation est interdite.
