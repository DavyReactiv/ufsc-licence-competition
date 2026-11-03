# Import FFST — Centre de résolution des licences introuvables

## Objectif

Permettre à l'administration UFSC de traiter des PDF FFST comportant des clubs nommés différemment dans UFSC Gestion et des licences « Introuvable », sans réimportations inutiles et **sans modification automatique des données de production**.

## Parcours dans WordPress

1. Ouvrir **UFSC Licence & Compétition → Import FFST** et sélectionner la saison.
2. Importer le PDF et utiliser les cartes **Trouvées / Introuvables / Ambiguës / Conflits / Appliquées** pour filtrer les lignes du lot sur toutes ses pages.
3. Pour un nom de club FFST différent du nom UFSC, choisir le **club UFSC cible** dans *Résoudre les licences introuvables*. Cliquer sur **Associer le club et rechercher**. Cette correspondance est mémorisée pour les futurs imports. Elle ne change aucune identité ni licence.
4. Cliquer sur **Relancer le rapprochement des introuvables** si nécessaire. La réanalyse ne modifie que les lignes du lot en attente.
5. Pour une ligne encore introuvable, cliquer sur **Rechercher / associer**, choisir son club puis examiner les fiches ayant la même date de naissance et la saison appropriée. L'association manuelle n'est proposée que si **nom, prénom, naissance, club, saison et numéro existant sont compatibles**.
6. Si la personne n'existe réellement pas, choisir **Préparer une nouvelle licence brouillon**. Le formulaire **natif d'UFSC Gestion** s'ouvre dans un nouvel onglet, prérempli pour faciliter la saisie. Vérifier toutes les informations et cliquer sur son bouton d'enregistrement normal. Cette action n'effectue **aucune création automatique** et ne contourne pas les règles d'UFSC Gestion.
7. Revenir au lot puis relancer la recherche ; sélectionner les lignes trouvées et cliquer sur **Rapprocher les lignes sélectionnées**.
8. Pour les numéros officiellement appliqués, poursuivre dans le centre **Validation FFST** d'UFSC Gestion. Le statut administratif des licences n'est pas modifié pendant les actions de résolution.

## Amélioration du sélecteur de club (PR suivante)

Le sélecteur initial affichait seulement les 500 premiers clubs ; cela empêchait le choix de certains clubs au-delà de cette limite.

- Une **recherche dynamique par nom du club** est désormais disponible au-dessus des deux sélecteurs, dans l’association du club FFST et dans la recherche manuelle.
- Saisir au moins deux caractères : les 30 premières correspondances sont proposées dans la liste. Affiner la recherche si nécessaire.
- La recherche est réservée aux administrateurs, vérifiée par nonce et utilise un `LIKE` préparé et échappé. Le nom du club est inséré comme texte, jamais comme HTML exécutable.
- Le choix est toujours vérifié par **identifiant réel dans la table UFSC**. Les clubs au-delà des 500 premiers sont reconnus par le serveur.
- Un club déjà sélectionné est conservé lorsqu'on recherche un autre terme ; les associations existantes ne sont pas écrasées.
- Si JavaScript ou la recherche AJAX échoue, la première liste classique reste disponible sans modifier de données.
- Aucun changement de schéma ou de numéro FFST : le rapprochement et les validations conservent leurs contrôles existants.

### Recette supplémentaire

- [ ] Tester un club UFSC au-delà des 500 premiers résultats.
- [ ] Vérifier les deux sélecteurs, notamment lorsqu'une association enregistrée pointe vers ce club.
- [ ] Tester les droits refusés pour un non-administrateur et un nonce invalide.
- [ ] Tester la recherche avec accents, apostrophes, pourcentages et underscores.
- [ ] Simuler une erreur réseau et vérifier que le club déjà choisi reste sélectionné.
- [ ] Vérifier le comportement au clavier et sur écran mobile.

## Règles de sécurité

- Les correspondances de clubs sont stockées dans l'option WordPress `ufsc_lc_ffst_club_alias_v1` (pas de modification des noms existants).
- Les liens manuels sont d'abord écrits dans le staging d'import avec l'identité de l'administrateur. Le numéro FFST n'est appliqué qu'après une action explicite via le moteur d'import existant.
- Les collisions de numéros, les changements de saison, les divergences d'identité et les doublons restent bloquants.
- Les nouveaux contrôles sont réservés aux administrateurs WordPress disposant de la permission d'import ; nonce obligatoire sur les mutations.
- Les exportations CSV d'introuvables sont réservées aux administrateurs et neutralisent les valeurs interprétables comme formules.
- Aucune nouvelle migration SQL et aucune suppression, modification de paiement ou validation automatique du statut UFSC.
- Les filtres d'état sont paginés **sur l'ensemble du lot**. La recherche libre et « Tout sélectionner » opèrent **uniquement sur la page affichée**.

## Recette préproduction — avant fusion et déploiement

- [ ] Effectuer une sauvegarde de la base de préproduction.
- [ ] Lot #21 Vincendo : vérifier le mapping entre le club FFST long et le club UFSC « ASCE DE VINCENDO » ; ne pas associer un licencié absent à une autre personne.
- [ ] Vérifier deux cas d'identité avec traits d'union/espaces et mêmes dates de naissance.
- [ ] Vérifier un nom volontairement erroné et une date de naissance différente : association refusée.
- [ ] Vérifier une saison différente, un numéro FFST déjà présent sur une autre licence, deux candidats identiques : aucun appariement risqué.
- [ ] Réanalyser un lot déjà appliqué partiellement sans réécriture des numéros précédemment appliqués.
- [ ] Vérifier le bouton Brouillon et le formulaire natif UFSC Gestion : aucune création sans confirmation et paiement non marqué comme reçu.
- [ ] Vérifier filtres par état, pagination, recherche dans la page, sélection groupée, responsive mobile.
- [ ] Vérifier les permissions pour un club, un gestionnaire sans droits administrateur et un administrateur.
- [ ] Vérifier export CSV et l'échappement des formules.
- [ ] Vérifier une installation avec UFSC Gestion désactivé : aucune écriture et diagnostic approprié pour les fonctions dépendantes.
- [ ] Vérifier les parcours Compétitions, licences, imports historiques, pesées et exports inchangés.

## Retour arrière

Si la recette échoue, ne pas fusionner cette PR. Après déploiement, restaurer le ZIP précédent du plugin sans réinitialisation de base. Les liens de clubs enregistrés dans les options WordPress peuvent être laissés intacts pour un retour ultérieur ; aucun schéma n'a changé.
