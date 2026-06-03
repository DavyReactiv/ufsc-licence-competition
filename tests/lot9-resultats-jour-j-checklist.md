# Checklist Lot 9 — Résultats jour J

Toutes les vérifications fonctionnelles doivent être réalisées sur une compétition préfixée `[TEST]`.

## Accès

- [ ] Accéder à la page Résultats jour J avec un administrateur.
- [ ] Vérifier que l'accès est refusé pour un utilisateur non autorisé.
- [ ] Sélectionner une compétition `[TEST]`.
- [ ] Vérifier que les liens vers Plateau jour J et Feuille résultats conservent `competition_id`.

## Filtres

- [ ] Filtrer par surface.
- [ ] Filtrer par statut.
- [ ] Filtrer par catégorie.
- [ ] Filtrer par discipline.
- [ ] Combiner plusieurs filtres sans erreur fatale.

## Saisie résultat

- [ ] Saisir un résultat rouge gagnant.
- [ ] Saisir un résultat bleu gagnant.
- [ ] Saisir un résultat par décision / points.
- [ ] Saisir un résultat par forfait.
- [ ] Saisir un résultat par abandon.
- [ ] Saisir un résultat par disqualification.
- [ ] Saisir un résultat par arrêt arbitre / KO-TKO si supporté par le règlement.
- [ ] Saisir un no contest avec motif obligatoire.

## Refus attendus

- [ ] Tenter un vainqueur invalide.
- [ ] Tenter un résultat sans vainqueur hors no contest / litige / annulation.
- [ ] Tenter un résultat sur BYE.
- [ ] Tenter un résultat sur placeholder.
- [ ] Tenter un résultat sur combat supprimé.
- [ ] Tenter une action POST sans nonce valide.
- [ ] Tenter une action avec une compétition qui ne correspond pas au combat.

## Correction et verrouillage

- [ ] Corriger un résultat existant avec motif.
- [ ] Vérifier que la correction sans motif est refusée.
- [ ] Verrouiller un résultat terminé.
- [ ] Tenter une correction après verrouillage.
- [ ] Vérifier que l'état verrouillé est visible.
- [ ] Vérifier qu'aucun déverrouillage n'est proposé dans ce lot.

## Journalisation et non-régression

- [ ] Vérifier la journalisation de la saisie.
- [ ] Vérifier la journalisation de la correction.
- [ ] Vérifier la journalisation du verrouillage.
- [ ] Vérifier que les impressions restent en lecture seule.
- [ ] Vérifier que la génération, les surfaces et l'ordre de passage ne sont pas modifiés.
- [ ] Vérifier qu'aucune donnée réelle n'est modifiée.
