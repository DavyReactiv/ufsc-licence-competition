# Lot 1 — Contrôle de profondeur UI/UX (admin + front)

## 1) Composants UI réellement créés

### Système commun admin
- `ufsc-admin-page-header`: en-tête premium structuré (kicker, titre, description, CTA).
- `ufsc-admin-page-kicker`: sous-titre contexte métier en uppercase.
- `ufsc-admin-page-description`: sous-texte d’aide à la lecture.
- `ufsc-admin-page-actions`: zone CTA dédiée.
- `ufsc-kpis--premium`: grille KPI visuellement renforcée.
- `ufsc-admin-toolbar`: surface filtre/recherche premium.
- `ufsc-admin-surface`: carte de formulaire/back-office.
- `ufsc-empty-state`: état vide qualitatif.
- `ufsc-table-premium`: en-têtes tableaux harmonisés.

### Système commun front
- `ufsc-panel`: carte premium réutilisable (liste/détail/formulaire).
- `ufsc-panel--soft`: variante secondaire.
- `ufsc-pill`, `ufsc-pill--soft`: badges taxonomy (discipline/type/saison).
- `ufsc-status-badge`: statuts visuels unifiés (open/draft/submitted/rejected/etc.).
- `ufsc-competitions-filters` + `__row`: toolbar filtres front modernisée.
- `ufsc-competitions-table-wrapper`: tableau encadré premium.
- `ufsc-competition-hero`: hero de détail compétition.
- `ufsc-section-title`: hiérarchie de section harmonisée.

## 2) Extraits markup/PHP significatifs

### `Compétitions_Page.php`
- Header premium + KPI + toolbar recherchable.

### `Entries_Page.php`
- Header premium + KPI statuts + séparation toolbar/table.

### `Bouts_Page.php`
- Header premium + KPI planifié/en cours/terminé.

### `CompetitionsListShortcode.php`
- Pills discipline/type + status badge + CTA primary.

### `CompetitionDetailsShortcode.php`
- Hero (image + badges) + sections en `ufsc-panel`.

### `EntryFormRenderer.php`
- Bloc “Vos inscriptions” en panel + engaged table panel + form panel + license prefill panel.

## 3) Extraits design system CSS

### `admin.css`
- Définitions de `ufsc-admin-page-header`, `ufsc-kpis--premium`, `ufsc-admin-toolbar`, `ufsc-empty-state`, `ufsc-table-premium`.

### `front.css`
- Définitions de `ufsc-panel`, `ufsc-pill`, `ufsc-status-badge`, `ufsc-competition-hero`, `ufsc-competitions-filters`, responsive mobile.

## 4) Checklist visuelle précise (à vérifier en environnement)

### Admin — attendu visuellement
1. **Compétitions**: bandeau premium + bouton principal + 5 KPI.
2. **Inscriptions**: bandeau premium + KPI statut + zone recherche dédiée.
3. **Combats**: bandeau premium + KPI combats + zone recherche distincte.
4. **Timing Profiles**: bandeau premium + KPI couverture + formulaires en surface cartée.
5. **Qualité**: bandeau premium + KPI anomalies + vrai empty state si aucun issue.

### Front — attendu visuellement
1. **Liste compétitions**: filtres en bloc premium, badges/pills visibles, CTA “Voir” plus fort.
2. **Détail compétition**: hero avec badges + sections infos/obligations/inscriptions en cartes.
3. **Vos inscriptions**: conteneur panel, table mieux isolée visuellement.
4. **Formulaire d’ajout**: zone formulaire panel + préremplissage licences en carte dédiée.

### Lisibilité/qualité perçue
- Distinction nette entre information, statut, action.
- Densité mieux équilibrée (moins “WordPress brut”).
- CTA principaux plus identifiables.
- États vides plus professionnels.

### Responsive
- Vérifier <980px :
  - hero détail repasse en 1 colonne,
  - grille filtres front passe en 2 colonnes,
  - headers admin empilent actions proprement.

## 5) Proposition Mini Lot 1.1 (sans toucher au métier)

1. **Formulaire front (structure)**
   - Ajouter un stepper visuel (Recherche licencié → Identité → Données sportives → Validation).
   - Ajouter barre de progression légère et regroupement des erreurs en haut.
2. **Détail compétition (cartes infos)**
   - Transformer la liste infos pratiques en grille de mini-cartes avec icônes discrètes.
3. **Statuts visuels**
   - Mapper aussi les statuts workflow dans les tableaux d’inscriptions via classes dédiées (`is-draft`, `is-submitted`, etc.).
4. **Tableaux admin/front**
   - Header sticky harmonisé + mode densité compacte optionnel.
5. **Empty states**
   - Ajouter CTA contextuels (ex: “Ajouter une inscription”, “Créer une compétition”).
6. **Hiérarchie CTA**
   - Uniformiser bouton primaire/secondaire sur toutes pages Lot 1.

> Objectif Lot 1.1: renforcer la perception “produit métier premium” sans modifier flux métier, schéma DB ni endpoints.
