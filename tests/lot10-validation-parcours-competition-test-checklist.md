# Checklist finale Lot 10 — Parcours compétition `[TEST]`

Cette checklist est destinée à un organisateur ou administrateur technique. Elle doit être exécutée sur une compétition dont le nom commence par `[TEST]`.

## 1. Prérequis obligatoires

- [ ] La base de données est sauvegardée.
- [ ] Les fichiers du site sont sauvegardés.
- [ ] `git status --short` est propre avant validation.
- [ ] La compétition utilisée commence par `[TEST]`.
- [ ] Aucun test mutable n'est réalisé sur une compétition réelle.
- [ ] Les droits administrateur / gestionnaire / opérateur sont connus.
- [ ] Les tables de combats et inscriptions sont identifiées.

## 2. Création et configuration compétition `[TEST]`

- [ ] Créer ou sélectionner une compétition `[TEST]`.
- [ ] Vérifier la discipline principale.
- [ ] Configurer les catégories nécessaires.
- [ ] Configurer 1 surface et vérifier l'affichage.
- [ ] Configurer 2 surfaces et vérifier l'affichage.
- [ ] Configurer 4 surfaces et vérifier l'affichage.
- [ ] Vérifier les paramètres de génération.
- [ ] Vérifier les droits d'accès à la compétition.

## 3. Inscriptions test

- [ ] 2 participants même catégorie.
- [ ] 3 participants même catégorie.
- [ ] 4 participants même catégorie.
- [ ] 5 participants même catégorie.
- [ ] 1 participant seul.
- [ ] Participants de sexe différent.
- [ ] Participants de poids différent.
- [ ] Participant sans poids.
- [ ] Participant sans date de naissance.
- [ ] Participant sans discipline.
- [ ] Participant externe incomplet.
- [ ] Doublon de numéro combattant.
- [ ] Les statuts d'inscription sont cohérents.

## 4. Pesées test

- [ ] Pesée valide.
- [ ] Pesée manquante.
- [ ] Pesée invalide.
- [ ] Reclassement en attente si applicable.
- [ ] Les pesées impactent l'éligibilité comme attendu.

## 5. Preview génération

- [ ] La preview affiche le nombre d'inscrits analysés.
- [ ] La preview affiche le nombre d'éligibles.
- [ ] La preview affiche le nombre de rejetés.
- [ ] Les motifs de rejet sont compréhensibles.
- [ ] Les groupes générables sont visibles.
- [ ] Les groupes insuffisants sont visibles.
- [ ] Les participants isolés sont visibles.
- [ ] Les groupes impairs sont visibles.
- [ ] Les BYE sont visibles.
- [ ] `group_key` est présent.
- [ ] `category_id` est présent pour les vrais combats.
- [ ] L'estimation de combats est cohérente.

## 6. Brouillon de génération

- [ ] Créer un brouillon sur la compétition `[TEST]`.
- [ ] Vérifier la présence de `validation_context`.
- [ ] Vérifier la présence de `group_key`.
- [ ] Vérifier la présence de `category_id`.
- [ ] Vérifier la présence de `case_type`.
- [ ] Vérifier les métadonnées BYE / placeholder si applicable.
- [ ] Vérifier qu'un payload incomplet est refusé.

## 7. Validation du brouillon

- [ ] Valider un brouillon sain.
- [ ] Vérifier qu'un snapshot est créé avant insertion.
- [ ] Vérifier que le mode `replace` est refusé.
- [ ] Vérifier le refus si inscriptions changées.
- [ ] Vérifier le refus si pesées changées.
- [ ] Vérifier le refus si catégories changées.
- [ ] Vérifier le refus si surfaces ou paramètres changés.
- [ ] Vérifier le refus si combats existants non maîtrisés.
- [ ] Vérifier le refus si résultats existants.
- [ ] Vérifier le rollback ciblé en cas d'erreur simulée ou documentée.

## 8. Surfaces et ordre

- [ ] Affecter les combats à 1 surface.
- [ ] Affecter les combats à 2 surfaces.
- [ ] Affecter les combats à 4 surfaces.
- [ ] Vérifier que l'ordre de passage est stable.
- [ ] Vérifier que le tri est stable entre Combats, Plateau et Impressions.
- [ ] Changer manuellement la surface d'un combat modifiable.
- [ ] Vérifier le refus sur combat terminé.
- [ ] Vérifier le refus sur combat verrouillé.
- [ ] Vérifier le refus sur combat avec résultat.
- [ ] Vérifier le refus d'une surface inactive.
- [ ] Vérifier le refus d'une surface inexistante.

## 9. Impressions

- [ ] Imprimer la liste générale des combats.
- [ ] Imprimer une feuille par surface.
- [ ] Imprimer les feuilles arbitres / juges.
- [ ] Imprimer la feuille résultats vierge.
- [ ] Filtrer par surface.
- [ ] Filtrer par catégorie.
- [ ] Filtrer par discipline.
- [ ] Filtrer par statut.
- [ ] Vérifier BYE sans ambiguïté.
- [ ] Vérifier placeholder sans ambiguïté.
- [ ] Vérifier zones observation.
- [ ] Vérifier zones signature.
- [ ] Vérifier CSS print A4.
- [ ] Vérifier qu'aucune donnée n'est modifiée par impression.

## 10. Résultats jour J

- [ ] Accéder à la page Résultats jour J.
- [ ] Filtrer les combats.
- [ ] Vérifier l'ordre stable.
- [ ] Saisir rouge gagnant.
- [ ] Saisir bleu gagnant.
- [ ] Saisir décision / points.
- [ ] Saisir forfait.
- [ ] Saisir abandon.
- [ ] Saisir disqualification.
- [ ] Saisir arrêt arbitre / KO-TKO si supporté.
- [ ] Vérifier refus vainqueur invalide.
- [ ] Vérifier refus résultat sur BYE.
- [ ] Vérifier refus résultat sur placeholder.
- [ ] Corriger un résultat avec motif.
- [ ] Verrouiller un résultat.
- [ ] Vérifier refus correction après verrouillage.
- [ ] Vérifier la journalisation.

## 11. Points de blocage avant réel

Ne pas utiliser une compétition réelle si au moins un point est coché ci-dessous :

- [ ] Catégories non vérifiées.
- [ ] Pesées non finalisées.
- [ ] Participants avec données critiques manquantes.
- [ ] Preview avec erreur bloquante.
- [ ] Brouillon obsolète.
- [ ] Combats existants non maîtrisés.
- [ ] Résultats existants sur le périmètre.
- [ ] Surfaces non configurées.
- [ ] Ordre non vérifié.
- [ ] Impressions non testées.
- [ ] Page Résultats non testée.
- [ ] Droits utilisateurs non vérifiés.
- [ ] Sauvegarde base absente.

## 12. Validation finale

- [ ] Toutes les étapes `[TEST]` sont validées.
- [ ] Les opérateurs savent saisir et corriger un résultat.
- [ ] Les opérateurs savent verrouiller un résultat.
- [ ] Les impressions papier sont validées.
- [ ] Les logs/audits sont consultables.
- [ ] Aucun blocant réel n'est présent.
- [ ] Le responsable technique autorise le passage en compétition réelle.

Signature responsable technique : ______________________________

Date : ____ / ____ / ______
