# Checklist manuelle — sécurité production compétition réelle

Objectif : vérifier qu'aucune action sensible ne supprime, n'écrase ou ne mélange des données entre compétitions.

## Pré-requis

- Travailler sur une copie de production ou une préproduction récente.
- Noter l'ID de la compétition testée.
- Exporter les inscriptions et les combats avant le test.
- Ouvrir **Compétitions > Qualité** et sélectionner la compétition pour contrôler la section **Protection des données réelles**.

## Scénarios obligatoires

1. **Compétition vide**
   - Ouvrir Qualité.
   - Attendu : protection en mode préparation, aucune action bloquée à cause de données réelles.

2. **Compétition avec inscriptions réelles**
   - Ajouter ou importer quelques inscriptions.
   - Ouvrir Qualité.
   - Attendu : compteur inscriptions > 0, suppression directe de compétition signalée comme action renforcée/bloquée.

3. **Compétition avec pesées validées**
   - Valider au moins une pesée.
   - Modifier la pesée avant génération.
   - Attendu : modification acceptée si aucun combat/résultat/verrou ne dépend de l'inscription, log de modification présent.

4. **Compétition avec combats générés sans résultat**
   - Générer un brouillon, vérifier la prévisualisation, valider.
   - Ouvrir Qualité.
   - Attendu : compteur combats > 0, statut de protection élevée, régénération destructive signalée.

5. **Tentative de régénération après combats existants**
   - Tenter une régénération.
   - Attendu : aucun combat existant supprimé; si l'action est autorisée, un snapshot doit être créé; sinon message de blocage clair.

6. **Compétition avec résultats saisis**
   - Saisir un résultat sur un combat valide.
   - Tenter de régénérer/valider un nouveau brouillon.
   - Attendu : action bloquée avec message indiquant que des résultats existent; log `sensitive_action_blocked`.

7. **Compétition avec résultat verrouillé**
   - Verrouiller un résultat terminé.
   - Tenter une saisie/correction directe.
   - Attendu : action bloquée; aucun changement de vainqueur, score ou méthode.

8. **Pesée liée à un résultat**
   - Modifier la pesée d'un combattant ayant déjà un résultat ou un combat verrouillé.
   - Attendu : action bloquée avec message indiquant qu'une procédure sensible est nécessaire.

9. **Combat mauvaise compétition**
   - Tenter de sauvegarder un combat dans une compétition A avec un inscrit de compétition B.
   - Attendu : action bloquée; message “combattant sélectionné ne correspond pas à cette compétition”; log d'audit.

10. **Suppression d'inscription**
    - Supprimer une inscription depuis l'espace club/admin.
    - Attendu : mise en corbeille/soft-delete si disponible; aucune suppression physique silencieuse. Si la corbeille n'existe pas, action refusée.

11. **Actions en masse combats**
    - Tenter une suppression définitive sans confirmation forte.
    - Attendu : blocage et log. Les combats en cours/terminés/verrouillés ne doivent pas être supprimés.

12. **Import CSV avec doublons**
    - Importer un CSV contenant une inscription déjà existante.
    - Attendu : pas d'écrasement silencieux; le bilan doit distinguer lignes créées, ignorées, mises à jour ou en erreur.

13. **Nettoyage de données test**
    - Utiliser les actions fixtures uniquement sur une compétition marquée `[TEST]`.
    - Attendu : aucune compétition réelle touchée; filtres stricts par marqueurs test et competition_id.

## Critères de réussite

- Aucun `DELETE` physique sur données réelles.
- Aucun résultat existant écrasé hors correction tracée.
- Aucun combat d'une autre compétition modifié.
- Tous les blocages sensibles affichent un message français compréhensible.
- Les logs d'audit permettent d'identifier l'action, la compétition, l'objet et la raison du refus.
