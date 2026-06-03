# Lot 10 — Validation complète du parcours compétition `[TEST]`

## Objectif du lot

Le Lot 10 stabilise le parcours compétition complet du plugin UFSC Licence & Compétition en préparant une validation méthodique sur environnement `[TEST]`.

Ce lot ne lance aucune génération réelle, ne modifie aucune donnée réelle, ne crée aucune migration SQL et ne refond pas l'architecture. Il formalise le contrôle de bout en bout des lots 2 à 9 et les blocants qui doivent empêcher une utilisation sur compétition réelle.

## Résumé des lots précédents

- Lot 2 : alignement de l'affectation des surfaces sur la table canonique `ufsc_fights`.
- Lot 3 : sécurisation du chemin historique de génération directe et restriction aux fixtures `[TEST]`.
- Lot 4 : fiabilisation du regroupement des participants par catégorie et diagnostics de rejet.
- Lot 5 : gestion visible des cas particuliers : participant seul, nombre impair, BYE, poule de 3 et finale directe.
- Lot 6 : validation contrôlée du brouillon, contexte de validation, refus des brouillons obsolètes et refus du mode `replace`.
- Lot 7 : stabilisation surfaces / ordre de passage et protections sur combats terminés, verrouillés ou avec résultat.
- Lot 8 : feuilles imprimables de combats, feuilles arbitres / juges, filtres d'impression et CSS print.
- Lot 9 : page `Résultats jour J`, saisie rapide, correction contrôlée et verrouillage via `ResultService`.

## Parcours complet à valider

Le parcours cible est :

1. Créer ou sélectionner une compétition `[TEST]`.
2. Configurer les catégories et surfaces.
3. Ajouter/importer les inscriptions test.
4. Contrôler et valider les inscriptions.
5. Contrôler les pesées.
6. Prévisualiser la génération automatique.
7. Créer un brouillon de combats.
8. Vérifier le diagnostic du brouillon.
9. Valider le brouillon uniquement si sain.
10. Affecter les surfaces et contrôler l'ordre.
11. Vérifier le plateau jour J.
12. Imprimer les feuilles nécessaires.
13. Saisir les résultats sur la page Résultats jour J.
14. Corriger un résultat avec motif si nécessaire.
15. Verrouiller les résultats terminés.
16. Vérifier logs, impressions et absence de régression.

## Cartographie des pages et liens admin

Pages clés vérifiées statiquement :

| Étape | Page / service | Rôle | Lien attendu |
|---|---|---|---|
| Compétitions | `Competitions_Page` | création et fiche compétition | menu Compétitions |
| Inscriptions | `Entries_Page` / `Entries_Validation_Page` | saisie, import, validation | menu Inscriptions |
| Pesées | `WeighIns_Page` | pesées et statuts | menu Pesées |
| Génération | `Bouts_AutoGeneration` | preview, brouillon, validation | panneau dans Combats |
| Combats | `Bouts_Page` | liste et gestion combats | menu Combats |
| Plateau | `Plateau_Page` | surfaces et jour J | menu Plateau jour J |
| Impressions | `Print_Page` | feuilles papier lecture seule | menu Impression |
| Résultats | `Results_Page` | saisie jour J | menu Résultats jour J |

Liens contrôlés statiquement :

- Menu Compétitions vers `Résultats jour J` via `Menu::PAGE_RESULTS`.
- Page Combats vers `Résultats jour J` avec `competition_id` conservé.
- Dashboard compétition vers `Résultats jour J` avec `competition_id` conservé.
- Page Résultats vers `Feuille résultats` et `Plateau jour J` avec `competition_id` conservé.
- Bootstrap charge `Results_Page.php` avant l'enregistrement des actions admin.

Aucun lien cassé évident n'a été corrigé dans ce lot documentaire.

## Contrôles sécurité et droits à valider

| Action sensible | Capability attendue | Nonce | Vérification compétition/scope | Refus attendus |
|---|---|---|---|---|
| Génération brouillon | `user_can_generate_fights()` / gestion combats | oui | oui | compétition non prête, données incomplètes, réelle hors garde-fous |
| Validation brouillon | gestion/génération combats | oui | oui | brouillon obsolète, payload incomplet, combats/résultats existants, `replace` |
| Changement surface | gestion combats / plateau | oui | oui | combat terminé, verrouillé, avec résultat, BYE/placeholder |
| Recalcul / affectation ordre | gestion combats | oui si action admin | oui | combats sensibles, table/colonnes manquantes |
| Impression | lecture compétition | non requis car lecture seule | oui via compétition sélectionnée | utilisateur non autorisé |
| Saisie résultat | `user_can_record_results()` | oui | oui | BYE, placeholder, vainqueur invalide, résultat déjà présent |
| Correction résultat | `user_can_correct_results()` | oui | oui | verrouillé, motif absent, vainqueur invalide |
| Verrouillage résultat | `user_can_correct_results()` | oui | oui | combat non terminé, BYE/placeholder |
| Suppression / archivage combat | gestion combats | oui | oui | combat sensible ou résultat existant |
| Opérations sensibles | capability opérations sensibles | oui | oui | compétition réelle sans garde-fou |

## Parcours `[TEST]` détaillé

### A. Préparation compétition

- Créer une compétition dont le nom commence par `[TEST]`.
- Configurer 1 surface, puis répéter le test avec 2 et 4 surfaces.
- Vérifier les disciplines disponibles.
- Vérifier les catégories d'âge, poids, sexe, niveau/classe si applicable.
- Vérifier les paramètres de génération : formats, niveau/classe, pesées requises si activées.

### B. Inscriptions

Créer les cas suivants :

- 2 participants même discipline / sexe / âge / poids / niveau.
- 3 participants même catégorie.
- 4 participants même catégorie.
- 5 participants même catégorie.
- 1 participant isolé.
- Participants de sexe différent.
- Participants de poids différent.
- Participant sans poids.
- Participant sans date de naissance.
- Participant sans discipline.
- Participant externe incomplet.
- Doublon de numéro combattant.

Résultat attendu : les participants valides deviennent éligibles, les autres sont rejetés ou signalés avec un motif explicite.

### C. Pesées

Vérifier :

- pesée valide ;
- pesée manquante ;
- pesée invalide ;
- reclassement en attente si applicable ;
- impact réel sur l'éligibilité affichée dans la preview.

### D. Preview génération

La preview doit afficher :

- inscrits analysés ;
- éligibles ;
- rejetés ;
- motifs de rejet ;
- groupes générables ;
- groupes insuffisants ;
- participants isolés ;
- groupes impairs ;
- BYE ;
- `group_key` ;
- `category_id` ;
- estimation du nombre de combats.

### E. Brouillon

Le brouillon doit contenir :

- `validation_context` ;
- `group_key` ;
- `category_id` ;
- `case_type` ;
- métadonnées BYE / placeholder si applicable ;
- statistiques cohérentes avec la preview.

Le brouillon doit être refusé si le payload est incomplet.

### F. Validation du brouillon

Scénarios attendus :

- validation d'un brouillon sain sur `[TEST]` ;
- refus si inscriptions modifiées après brouillon ;
- refus si pesées modifiées après brouillon ;
- refus si catégories modifiées après brouillon ;
- refus si surfaces ou paramètres incompatibles ;
- refus si combats existants non maîtrisés ;
- refus si résultats existants ;
- refus du mode `replace` ;
- snapshot créé avant insertion ;
- rollback ciblé en cas d'échec partiel.

### G. Surfaces et ordre

Contrôler :

- affectation sur 1 surface ;
- affectation sur 2 surfaces ;
- affectation sur 4 surfaces ;
- ordre stable par `scheduled_order`, horaire, numéro de combat puis identifiant ;
- changement manuel autorisé uniquement sur combat modifiable ;
- refus sur combat terminé ;
- refus sur combat verrouillé ;
- refus sur combat avec résultat ;
- refus surface inactive ;
- refus surface inexistante.

### H. Impressions

Contrôler :

- liste générale des combats ;
- feuille par surface ;
- feuilles arbitres / juges ;
- feuille résultats vierge ;
- filtres surface, catégorie, discipline, statut ;
- affichage BYE non ambigu ;
- affichage placeholder non ambigu ;
- zones observation ;
- zones signature ;
- CSS print A4 ;
- aucune donnée modifiée par impression.

### I. Résultats jour J

Contrôler :

- accès page Résultats ;
- filtres ;
- ordre stable ;
- saisie rouge gagnant ;
- saisie bleu gagnant ;
- décision / points ;
- forfait ;
- abandon ;
- disqualification ;
- arrêt arbitre / KO-TKO si supporté ;
- refus vainqueur invalide ;
- refus résultat sur BYE ;
- refus résultat sur placeholder ;
- correction contrôlée avec motif ;
- verrouillage ;
- refus correction après verrouillage ;
- journalisation.

## Bugs corrigés dans ce lot

Aucun bug PHP n'a été corrigé dans ce lot. La validation statique n'a pas identifié de lien admin cassé évident nécessitant une modification ciblée.

## Éléments non corrigés volontairement

- Aucun test fonctionnel mutable n'a été exécuté sur base réelle.
- Aucun scénario de génération réelle n'a été lancé.
- Aucun résultat réel n'a été saisi, corrigé ou verrouillé.
- Aucune migration SQL n'a été créée.
- Aucun déverrouillage résultat n'a été ajouté.
- Aucun verrouillage automatique après impression n'a été ajouté.

## Blocants avant compétition réelle

Une compétition réelle ne doit pas être utilisée si :

- catégories non vérifiées ;
- pesées non finalisées ;
- participants avec données critiques manquantes ;
- preview avec erreurs bloquantes ;
- brouillon obsolète ;
- combats existants non maîtrisés ;
- résultats existants sur le périmètre de génération ;
- surfaces non configurées ;
- ordre non vérifié ;
- impressions non testées ;
- page Résultats jour J non testée ;
- droits utilisateurs non vérifiés ;
- sauvegarde base absente ;
- logs/audits inaccessibles ;
- utilisateur opérateur non formé à la procédure de correction/verrouillage.

## Recommandations avant mise en production

1. Exécuter la checklist Lot 10 complète sur une compétition `[TEST]`.
2. Sauvegarder base de données et fichiers avant toute compétition réelle.
3. Vérifier le rôle exact des opérateurs : lecture, génération, plateau, résultats, correction.
4. Imprimer et vérifier les feuilles avant le jour J.
5. Vérifier la page Résultats sur au moins un combat test complet.
6. Verrouiller les résultats test et confirmer que la correction est refusée après verrouillage.
7. Ne pas appliquer un brouillon ancien : régénérer la preview et le brouillon juste avant validation officielle.
8. Conserver le rapport de checklist signé par l'organisateur technique.
