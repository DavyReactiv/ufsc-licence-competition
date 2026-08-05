# Checklist de non-régression — saisons des licences

## Front-office club

- [ ] Sans paramètre `ufsc_season`, seules les licences de la saison active sont affichées.
- [ ] `ufsc_season=all` affiche toutes les saisons du club connecté.
- [ ] `ufsc_season=previous` affiche uniquement les saisons antérieures à la saison active.
- [ ] `ufsc_season=unspecified` affiche uniquement les licences sans saison.
- [ ] Une saison précise (`ufsc_season=2027`) fonctionne avec recherche, statut, catégorie, compétition, PDF, tri et pagination.
- [ ] Les compteurs correspondent à la saison sélectionnée.
- [ ] Le bouton Réinitialiser revient à la saison active et supprime les autres filtres.
- [ ] Un club ne voit jamais les licences d’un autre club.
- [ ] L’affichage mobile conserve les informations essentielles avec défilement horizontal propre.

## Administration licences

- [ ] La saison active est sélectionnée par défaut sur la liste des licences.
- [ ] Les filtres `Toutes les saisons`, `Saisons précédentes` et `Sans saison` fonctionnent.
- [ ] Les filtres sont conservés pendant pagination, tris, export et actions.
- [ ] La colonne Saison affiche un libellé humain (`2026–2027`) ou `Non renseignée`.
- [ ] Les anciennes saisons sont indiquées comme archivées sans modifier le statut métier.
- [ ] Les diagnostics non destructifs affichent les compteurs : sans saison, saison non normalisable, sans catégorie, sans ASPTT, sans PDF.
- [ ] Les diagnostics ne modifient aucune donnée.

## Documents PDF

- [ ] La recherche sans saison explicite privilégie la saison active.
- [ ] Une ancienne saison peut être recherchée explicitement.
- [ ] Des homonymes sur plusieurs saisons restent distinguables par le libellé de saison.
- [ ] Les associations PDF existantes sont conservées.
- [ ] Aucun document n’est régénéré automatiquement.

## Base de données

- [ ] Aucune nouvelle table n’est créée.
- [ ] Aucune nouvelle colonne n’est créée.
- [ ] Aucune licence n’est supprimée, dupliquée ou changée de statut.
- [ ] Le plugin reste compatible si `season_end_year` est absente et si seules les colonnes historiques existent.

## Calcul central de saison active

- [x] `php tests/season-active-calculation-test.php` couvre les dates de bascule UFSC au 1er août.
- [x] Le calcul central ignore une valeur UFSC Gestion simulée à `2026` au 5 août 2026 lorsque la règle UFSC locale calcule `2027`.
- [ ] Vérifier sur site que l’ancienne valeur enregistrée `season_start_month=9` a bien été migrée vers `8` lorsqu’elle correspond à l’ancien défaut.
