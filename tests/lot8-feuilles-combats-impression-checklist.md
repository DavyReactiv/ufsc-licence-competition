# Checklist Lot 8 — Feuilles de combats imprimables

Toutes les vérifications fonctionnelles doivent être réalisées sur une compétition préfixée `[TEST]`.

## Préconditions

- [ ] Utiliser une compétition `[TEST]`.
- [ ] Vérifier que la compétition possède des combats déjà générés et validés en test.
- [ ] Vérifier que les surfaces et l'ordre de passage sont déjà définis.
- [ ] Ne lancer aucune génération réelle.
- [ ] Ne modifier aucun résultat réel.

## Impressions générales

- [ ] Imprimer `fights_list` : la liste générale affiche tous les combats triés par surface puis ordre.
- [ ] Imprimer `fights_by_surface` : les combats sont regroupés par surface.
- [ ] Imprimer `surface_sheet` : chaque responsable de surface dispose d'une liste exploitable.
- [ ] Imprimer `results_sheet` : la feuille contient vainqueur, méthode, score, observation et signature.
- [ ] Imprimer `judge_sheets` : une feuille individuelle claire est produite pour chaque combat réel.

## Surfaces

- [ ] Tester avec une seule surface : aucun combat ne disparaît.
- [ ] Tester avec deux surfaces : les groupes par surface sont cohérents.
- [ ] Tester avec quatre surfaces : les sauts de page restent lisibles.
- [ ] Filtrer sur surface 1 : seuls les combats correspondants apparaissent.
- [ ] Filtrer sur surface 2 : seuls les combats correspondants apparaissent.

## Filtres

- [ ] Filtrer par `category_id` : seuls les combats de cette catégorie apparaissent.
- [ ] Filtrer par `discipline` : les combats de discipline correspondante apparaissent.
- [ ] Filtrer par `status` : seuls les statuts demandés apparaissent.
- [ ] Combiner surface + statut : la page reste en lecture seule et lisible.

## Cas particuliers

- [ ] Combat direct : la feuille arbitre contient rouge, bleu, clubs et cases résultat.
- [ ] Poule : les combats de poule restent imprimables et identifiables.
- [ ] BYE : le cas est signalé comme BYE et non comme adversaire réel.
- [ ] Placeholder : le cas est signalé comme attente / placeholder.
- [ ] Aucun faux adversaire n'est imprimé pour un BYE ou placeholder.

## Résultats existants

- [ ] Impression sans résultat : la feuille résultats reste vierge et exploitable.
- [ ] Impression avec résultat existant : le résultat existant reste affiché sans modification.
- [ ] Vérifier que l'impression ne change ni vainqueur, ni score, ni statut.

## Mise en page

- [ ] Vérifier A4 portrait pour les feuilles arbitres.
- [ ] Vérifier A4 paysage pour les listes larges si nécessaire.
- [ ] Vérifier les bordures et la lisibilité noir/blanc.
- [ ] Vérifier les sauts de page entre surfaces.
- [ ] Vérifier les zones signature.
- [ ] Vérifier les zones observation.
- [ ] Vérifier que les boutons et menus WordPress ne sortent pas à l'impression.

## Sécurité

- [ ] Utilisateur autorisé : la page d'impression est accessible.
- [ ] Utilisateur non autorisé : l'accès est refusé.
- [ ] Les paramètres GET malformés ne provoquent pas d'erreur fatale.
- [ ] L'impression ne modifie aucune donnée.
- [ ] `git status --short` ne montre que les fichiers attendus pendant la validation technique.
