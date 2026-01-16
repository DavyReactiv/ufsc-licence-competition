# ufsc-licence-competition

Plugin WordPress institutionnel (add-on) développé par **StudioReactiv** pour l’UFSC (Union Française des Sports de Combat).

Il structure la **gestion des licences officielles**, la **synchronisation ASPTT**, la **production / suivi des licences PDF nominatives**, l’**accès clubs**, avec une trajectoire vers la **gestion des compétitions** (championnats, open, galas).

---

## Description

**Objectif** : centraliser et fiabiliser les données de licences UFSC dans WordPress pour les usages fédéraux et clubs.

**Périmètre** :
- Gestion des licences UFSC
- Import / synchronisation ASPTT
- Consultation par les clubs via shortcodes
- Gestion et suivi des documents PDF (licences nominatives)

**Vision** : disposer d’un socle de données homogène, extensible et sécurisé pour préparer l’évolution vers la gestion complète des compétitions (championnats, open, galas).

---

## Fonctionnalités principales

- Import et synchronisation des licences issues de sources ASPTT (CSV).
- Raccordement des licences aux adhérents et aux clubs (via données CSV, notamment **Note**).
- Consultation des licences par les clubs via une interface dédiée (shortcode).
- Gestion des documents PDF nominatives associés aux licences (affichage / présence / suivi).
- Filtrage, tri et pagination côté club (selon les paramètres existants dans le plugin).
- Outils d’administration pour le suivi des imports et la qualité des données (Import / Review).

> Remarque : certaines fonctionnalités avancées (compétitions, contrôles de conformité, dashboards) sont prévues et seront ajoutées progressivement.

---

## Shortcodes

### Recommandés (actuels)

- `[ufsc_lc_club_licences_asptt]`  
  Affiche l’espace club pour consulter les licences et les informations associées (dont PDF lorsqu’il est disponible).

- `[ufsc_lc_licences]`  
  Alias **préfixé et safe** pour la même vue (recommandé pour éviter les conflits avec d’autres plugins).

### Legacy (compatibilité ascendante)

> Conservés pour compatibilité avec d’anciens contenus.  
> **À éviter** pour toute nouvelle page lorsque le mode legacy est activé.

- `[ufsc_club_licences_asptt]`
- `[ufsc_licences]`

---

## Paramètres (filtres via URL)

Les pages rendues par les shortcodes peuvent accepter des paramètres via l’URL (query string), selon le plugin :

- `q` : recherche texte (nom, prénom, N° ASPTT).
- `statut` : filtrer par statut de licence.
- `categorie` : filtrer par catégorie.
- `competition` : filtrer par type de compétition.
- `pdf` : filtrer la présence d’un PDF (`1` avec PDF, `0` sans PDF).
- `orderby` : champ de tri (ex. `nom_licence`, `date_naissance`, `date_asptt`).
- `order` : sens (`asc`, `desc`).
- `per_page` : nombre d’éléments par page (`10`, `25`, `50`).

> Note : la liste exacte des filtres dépend des versions et de la configuration du plugin.

---

## Interface d’administration (WordPress)

Le plugin ajoute un menu **UFSC Licences** avec notamment :
- Licences (liste / gestion)
- Import ASPTT (Import + Review)
- Statut
- Paramètres (si activé)
- Association sécurisée des PDF de licence (recherche par identité + club + saison, avec mode avancé).

---

## Sécurité

- Les vues clubs sont réservées aux utilisateurs authentifiés rattachés à un club (ou administrateurs).
- Opérations sensibles protégées via mécanismes WordPress (sanitization, nonces, validation serveur).
- Protection contre collisions de shortcodes via `shortcode_exists()` (ne redéclare pas un tag déjà utilisé ailleurs).
- Les PDF de licences sont servis via un endpoint sécurisé (`admin-post.php?action=ufsc_lc_pdf`) avec nonce et contrôle club/licence, sans lien public direct vers les uploads.

---

## Checklist (tests manuels)

- Associer un PDF via recherche (nom/prénom/date + club) → 1 résultat → associer.
- Associer un PDF via recherche → plusieurs résultats → sélectionner puis associer.
- Associer un PDF via recherche → 0 résultat → vérifier message + recherche élargie sans club.
- Associer un PDF via le mode avancé (Licence ID direct) → associer.
- Côté club : boutons "Voir" et "Télécharger" servent le PDF uniquement pour les licences du club connecté.

---

## Architecture

- **Fichier principal** : `ufsc-licence-competition.php`
- **Logique applicative** : `includes/` (admin, import, shortcodes, documents, etc.)
- **Ressources** : `assets/` (statiques) et `languages/` (traductions)

---

## Auteur

**StudioReactiv**

---

## Licence

Plugin propriétaire – usage réservé à l’UFSC.  
Toute réutilisation, reproduction ou diffusion sans autorisation est interdite.
