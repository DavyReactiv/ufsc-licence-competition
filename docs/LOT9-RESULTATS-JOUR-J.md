# Lot 9 — Résultats jour J

## Objectif

Le Lot 9 ajoute une page admin dédiée à la saisie rapide et contrôlée des résultats pendant une compétition.

Cette page est pensée pour être utilisée après impression des feuilles de combat : elle affiche les combats générés dans un ordre stable, permet de filtrer le plateau et réutilise les services de résultat existants sans modifier la génération, les impressions, les surfaces ou la structure SQL.

## Logique résultat existante

L'existant résultat repose principalement sur :

- `Bouts_Page::handle_record_result()` pour enregistrer un résultat depuis les combats ;
- `Bouts_Page::handle_correct_result()` pour corriger un résultat avec motif ;
- `Bouts_Page::handle_lock_result()` pour verrouiller un résultat ;
- `ResultService::record_result()` pour valider et enregistrer le résultat ;
- `ResultService::correct_result()` pour corriger via le flux supervisé ;
- `ResultService::lock_result()` pour verrouiller un combat terminé ;
- `FightRepository` pour lire les combats, calculer le statut effectif et détecter BYE / résultat existant ;
- `LogService` via `ResultService` pour journaliser les actions.

Les capabilities déjà utilisées sont conservées :

- lecture page : capability de lecture compétition ;
- saisie : `Capabilities::user_can_record_results()` ;
- correction et verrouillage : `Capabilities::user_can_correct_results()`.

## Page ajoutée

Une nouvelle page admin `Résultats jour J` est ajoutée au menu Compétitions.

Elle permet :

- de sélectionner une compétition ;
- de filtrer par surface ;
- de filtrer par statut ;
- de filtrer par catégorie ;
- de filtrer par discipline ;
- d'afficher les combats dans l'ordre fourni par le repository ;
- de saisir un résultat si aucun résultat n'existe ;
- de corriger un résultat existant avec motif ;
- de verrouiller un résultat terminé si autorisé ;
- d'accéder aux impressions de feuille résultats et au plateau jour J.

La page ne supprime pas la saisie historique depuis la page Combats.

## Affichage par combat

Chaque ligne affiche :

- ordre de passage ;
- numéro de combat ;
- surface ;
- horaire si disponible ;
- discipline ;
- catégorie ;
- coin rouge et club ;
- coin bleu et club ;
- statut ;
- résultat actuel ;
- action disponible selon les droits et l'état du combat.

Les BYE, placeholders et combats supprimés sont signalés et ne proposent pas de saisie classique.

## Formulaire de saisie

Le formulaire jour J poste vers des actions admin dédiées à la page résultats.

Contrôles appliqués :

- nonce par combat ;
- capability de saisie ou correction ;
- `fight_id` en `absint` ;
- vérification que le combat existe ;
- vérification de la compétition transmise ;
- contrôle d'accès compétition ;
- refus des BYE / placeholders ;
- validation métier déléguée à `ResultService` ;
- sanitization du vainqueur, de la méthode, des scores, observations et motifs ;
- messages admin non techniques en cas de refus.

## Méthodes de résultat disponibles

La page expose les méthodes déjà supportées par `ResultService` :

- `points` — décision / points ;
- `forfait` ;
- `abandon` ;
- `disqualification` ;
- `arret_arbitre` — arrêt arbitre / KO-TKO selon règlement ;
- `absence` ;
- `no_contest` ;
- `litige` ;
- `annule`.

Aucune nouvelle règle sportive n'est inventée dans ce lot.

## Correction contrôlée

Si un résultat existe déjà :

- le résultat actuel est affiché ;
- le formulaire bascule en mode correction uniquement pour un utilisateur autorisé ;
- un motif est demandé ;
- la correction est déléguée à `ResultService::correct_result()` ;
- les résultats verrouillés ne sont pas modifiables.

## Verrouillage

Si un résultat existe :

- un utilisateur autorisé peut demander le verrouillage ;
- `ResultService::lock_result()` vérifie que le combat est terminé ;
- le verrouillage est journalisé par le service existant ;
- aucune action de déverrouillage n'est ajoutée dans ce lot.

## Sécurité

- Aucune mutation en GET.
- Chaque action POST est protégée par nonce.
- Les droits sont vérifiés avant mutation.
- Les sorties HTML sont échappées.
- Les paramètres de filtre sont sanitisés.
- Les BYE / placeholders sont exclus de la saisie classique.
- Les résultats verrouillés ne sont pas modifiables.
- La logique métier de validation reste centralisée dans `ResultService`.

## Limites volontaires

- Pas de modification SQL.
- Pas de migration.
- Pas de déverrouillage de résultat.
- Pas de refonte de `ResultService`.
- Pas de modification des impressions, sauf liens de navigation vers les feuilles existantes.
- Pas de création de résultat de test automatisée.

## Tests manuels `[TEST]`

- Ouvrir la page Résultats jour J sur une compétition `[TEST]`.
- Filtrer par surface, statut, catégorie et discipline.
- Saisir un résultat rouge gagnant.
- Saisir un résultat bleu gagnant.
- Saisir un résultat par forfait, abandon, disqualification et arrêt arbitre.
- Tenter un vainqueur invalide.
- Tenter une saisie sur BYE / placeholder.
- Corriger un résultat existant avec motif.
- Verrouiller un résultat terminé.
- Vérifier qu'une correction après verrouillage est refusée.
- Vérifier les logs/audits.
- Vérifier que les impressions restent en lecture seule.

## Points restants pour le Lot 10

- Affiner les règles sportives par discipline si nécessaire.
- Ajouter une confirmation visuelle plus forte avant correction si l'UX le demande.
- Préparer un flux de déverrouillage contrôlé si une procédure fédérale est validée.
- Ajouter des exports ou vues de synthèse des résultats saisis.
