# Checklist — filtres catégories et diagnostic non destructif

## Garanties production
- [ ] Ne jamais supprimer d'inscriptions, combats, catégories, pesées, résultats ou clubs pendant ces tests.
- [ ] Ne jamais lancer de régénération automatique de combats pour valider les filtres.
- [ ] Vérifier que les filtres ne font que limiter l'affichage des listes.

## Tests Inscriptions
- [ ] Affichage sans filtre catégorie : comportement identique à l'existant.
- [ ] Filtre par catégorie : seuls les inscrits de la catégorie sélectionnée apparaissent.
- [ ] Filtre catégorie + club / affiliation club.
- [ ] Filtre catégorie + recherche nom, prénom ou licence.
- [ ] Filtre catégorie + compétition.
- [ ] Pagination conservée avec le filtre catégorie.
- [ ] Aucun inscrit supprimé ou modifié.

## Tests Combats
- [ ] Affichage sans filtre catégorie : comportement identique à l'existant.
- [ ] Filtre par catégorie : seuls les combats de la catégorie sélectionnée apparaissent.
- [ ] Les résultats existants restent inchangés.
- [ ] Les surfaces / rings restent inchangés.
- [ ] L'ordre des combats reste inchangé.
- [ ] Aucun combat supprimé ou modifié.
- [ ] Aucun combat régénéré automatiquement.

## Tests diagnostic catégories
- [ ] Les catégories avec même poids mais sexe, âge, discipline ou niveau différent sont affichées comme « Même poids, catégorie différente » et non comme erreur critique.
- [ ] Les catégories partageant discipline, sexe, âge, niveau et poids/plage de poids sont signalées comme « Doublon possible ».
- [ ] Aucun doublon n'est fusionné automatiquement.
- [ ] Le diagnostic reste lisible sur une compétition avec beaucoup de catégories.

## Tests sécurité techniques
- [ ] `php -l` sur tous les fichiers PHP modifiés.
- [ ] `git diff --check`.
- [ ] Vérifier qu'aucune requête DELETE, TRUNCATE, DROP ou UPDATE massive n'a été ajoutée.
- [ ] Vérifier que les entrées GET/POST sont sécurisées via `sanitize_text_field()`, `sanitize_key()` ou `absint()` selon le type.
- [ ] Vérifier que les requêtes SQL dynamiques utilisent `$wpdb->prepare()`.
- [ ] Vérifier que le plugin s'active sans erreur fatale dans WordPress.
