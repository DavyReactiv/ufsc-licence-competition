# UFSC Compétitions – Tests de non-régression & checklist

## Plan de tests manuel (à exécuter)
1. **Migration DB** : activer le plugin ou incrémenter la version DB, vérifier que `wp_ufsc_competition_entries` contient `assigned_at` + indexes (comp_status, club, licence/licencee, updated).
2. **Admin** : créer une compétition, ajouter une inscription depuis l’admin → pas d’erreur critique, notice de succès, redirection OK.
3. **Admin Inscriptions** : l’inscription apparaît avec nom/prénom, n° licence, date de naissance, club, statut, poids, catégorie poids.
4. **Admin Validation** : l’inscription apparaît si statut `submitted`/`pending`, actions valider/rejeter disponibles.
5. **Compétitions** : la colonne “Inscrits” affiche total + compteurs soumis/en attente/validés/refusés.
6. **Front** : connecté en club A → voit uniquement ses inscrits, colonne “Club” affichée, champs vides remplacés par “—”.
7. **Recherche** : recherche par nom/licence dans les listes admin fonctionne.
8. **Pagination** : listes admin paginées sur >50 entrées.
9. **Logs** : aucun warning deprecated lié au plugin dans `debug.log`.

## Checklist
- [ ] INSERT d’inscription sans colonne absente (notamment `assigned_at`).
- [ ] Migration idempotente + versioning DB.
- [ ] Index DB ajoutés sans casser l’existant.
- [ ] Admin-post sauvegarde : nonce + caps + redirection + notice.
- [ ] Admin “Inscriptions” affiche le licencié (nom/licence).
- [ ] Admin “Validation” affiche le licencié soumis/en attente.
- [ ] “Compétitions” affiche total + compteurs par statut.
- [ ] Front : filtre par club + colonne club visible + fallback “—”.
- [ ] Requêtes préparées sur input user.
- [ ] Perf OK (pagination SQL, COUNT séparés, indexes).
