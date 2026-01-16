# ufsc-licence-competition

## Description
Plugin WordPress institutionnel destiné à l’UFSC pour structurer la gestion des licences officielles, la synchronisation ASPTT, la production de licences PDF nominatives et l’accès clubs, avec une trajectoire vers la gestion des compétitions (championnats, open, galas).

**Objectif** : centraliser et fiabiliser les données de licences UFSC dans WordPress pour les usages fédéraux et clubs.

**Périmètre** : gestion des licences, import/synchronisation ASPTT, consultation par clubs, génération et suivi des documents PDF.

**Vision** : disposer d’un socle de données homogène, extensible et sécurisé pour préparer l’évolution vers la gestion complète des compétitions.

## Fonctionnalités principales
- Synchronisation et import des licences issues des sources ASPTT.
- Consultation des licences par les clubs via une interface dédiée.
- Gestion des licences PDF nominatives et des indicateurs associés.
- Filtrage, tri et pagination des licences côté club.
- Outils d’administration pour le suivi des imports et la qualité des données.

## Shortcodes
### Recommandés
- `[ufsc_lc_club_licences_asptt]` : affiche l’espace club pour consulter les licences et télécharger les PDF associés.

### Legacy (conservés)
> Ces shortcodes restent disponibles lorsque le mode legacy est activé. Ils ne doivent pas être supprimés.

- `[ufsc_club_licences_asptt]`
- `[ufsc_licences]`

## Paramètres
Les pages rendues par le shortcode club acceptent des paramètres de filtrage via l’URL (query string) :

- `q` : recherche texte (nom, prénom, N° ASPTT).
- `statut` : filtrer par statut de licence.
- `categorie` : filtrer par catégorie.
- `competition` : filtrer par type de compétition.
- `pdf` : filtrer la présence d’un PDF (`1` avec PDF, `0` sans PDF).
- `orderby` : ordre de tri (`nom_licence`, `date_naissance`, `date_asptt`).
- `order` : sens du tri (`asc`, `desc`).
- `per_page` : nombre d’éléments par page (`10`, `25`, `50`).

## Sécurité
- L’accès aux vues clubs est réservé aux utilisateurs authentifiés rattachés à un club.
- Le plugin s’appuie sur les mécanismes WordPress (sanitization, nonces, validation côté serveur) pour les opérations sensibles.

## Architecture
- **Fichier principal** : `ufsc-licence-competition.php`.
- **Logique applicative** : `includes/` (admin, import, export, shortcodes, documents).
- **Ressources** : `assets/` pour les éléments statiques et `languages/` pour les traductions.

## Auteur
StudioReactiv
