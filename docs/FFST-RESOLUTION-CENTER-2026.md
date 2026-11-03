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

## Recherche globale dans un lot FFST

Le contrôle du lot dispose désormais de deux recherches clairement distinctes :

- **Rechercher dans tout le lot** : recherche serveur en lecture seule dans toutes les lignes du lot, y compris les pages non affichées, sur les champs numéro FFST, nom, prénom, naissance, club FFST et message de contrôle.
- **Affiner les résultats de cette page** : filtre instantané local limité aux lignes affichées sur la page, sans nouvelle requête SQL.

La recherche globale se combine avec les cartes de statut (trouvées, introuvables, ambiguës, conflits, appliquées). Le terme est conservé lorsqu'on change de carte ou de page, et un bouton permet de l'effacer sans réinitialiser le lot. Le compteur et la pagination utilisent la même requête filtrée ; aucune licence absente des résultats n'est sélectionnée automatiquement.

**Sécurité** : terme limité à 100 caractères, motifs LIKE échappés, requêtes SQL préparées, statuts autorisés par liste blanche, lots isolés par identifiant ; aucune écriture ni modification de statut, de paiement ou de numéro FFST.

### Recette recherche de lot

- [ ] Dans un lot d'au moins 101 lignes, retrouver un nom uniquement présent en deuxième page.
- [ ] Conserver le terme et le filtre en changeant de page et de carte.
- [ ] Contrôler un terme avec %, _, apostrophe ou accents et l'absence d'erreur SQL.
- [ ] Vérifier la cohérence du nombre de résultats et de la pagination.
- [ ] Afficher un résultat vide, effacer la recherche puis retrouver toutes les lignes.
- [ ] Contrôler qu'aucune ligne non affichée n'est rapprochée par le bouton de sélection sur page.
- [ ] Tester les lots historiques et les lignes déjà appliquées.

## Gain de temps : réanalyse des grands lots et passage au dossier suivant

- La réanalyse FFST ne bloque plus les lots dépassant 500 lignes introuvables : elle vérifie **100 lignes par opération**, avec un bouton **Continuer la réanalyse**.
- Le compteur indique les correspondances retrouvées sur les étapes déjà parcourues et le nombre de lignes examinées lors de la dernière étape.
- Le parcours reprend à partir de l'identifiant de la dernière ligne examinée, **sans retraiter les lignes précédentes**. Une ligne restée introuvable après vérification peut être revue plus tard en choisissant **Recommencer depuis le début** (notamment après correction d'une fiche UFSC).
- Lors de l'association d'un club FFST, la première étape de réanalyse est exécutée et la reprise proposée automatiquement si des lignes restent à parcourir.
- Après un rapprochement manuel réussi, l'interface présente **le prochain dossier introuvable ou ambigu**. Un raccourci permet également de passer au dossier suivant sans associer le dossier actuel.
- Le nombre de lignes « Trouvées » sélectionnées sur la page est affiché près du bouton de sélection. La sélection n'inclut jamais les lignes non visibles ou celles non éligibles.

### Garanties

- Les étapes de réanalyse ne font **aucun changement de statut UFSC**, aucun paiement et n'attribuent aucun numéro dans la fiche maître : elles changent uniquement l'état provisoire d'import après vérification stricte de l'identité, de la saison, du club et de l'absence de doublon.
- Les lignes déjà appliquées ne sont pas réécrites. Les nouvelles correspondances restent soumises au rapprochement explicite.
- Aucune migration SQL ni opération destructive.
- Les actions restent protégées par les contrôles de capacités et le nonce WordPress.

### Recette préproduction

- [ ] Import de 205+ licences introuvables : vérifier le parcours 100 + 100 + reste sans doublon, perte de ligne ni dépassement de délai.
- [ ] Faire la même vérification sur un lot partiellement appliqué ; comparer numéros, paiements et statuts avant/après.
- [ ] Modifier un club puis relancer la recherche à partir du début ; les correspondances existantes ne doivent pas être dupliquées.
- [ ] Rapprocher manuellement une ligne, vérifier l'affichage du cas suivant sans association automatique.
- [ ] Tester le passage au cas suivant avec un seul introuvable et avec une liste vide.
- [ ] Vérifier le compteur de sélection lors des recherches, cases individuelles et sélection sur page.
- [ ] Tester les droits non administrateur, le nonce invalide et la navigation mobile.

## File de traitement FFST par club

Dans le lot FFST, ouvrir **Voir les clubs du lot et leurs dossiers à traiter** pour obtenir une vue en lecture seule des clubs présents dans le fichier. Chaque club affiche ses licences introuvables, ambiguës, en conflit, trouvées et déjà appliquées.

- Le bouton **Ouvrir ce club** affiche les dossiers du club, sans toucher aux fiches UFSC.
- Dans **Rechercher dans tout le lot**, le filtre **Club FFST du lot** se combine avec le statut et le texte recherché et reste actif à travers les pages et les cartes.
- Le champ club correspond au **nom original enregistré dans le lot FFST**, et non au nom de club UFSC mappé. Le choix est validé parmi les noms réellement présents dans ce lot.
- Le tableau des clubs est borné à 250 clubs pour protéger le temps d'affichage. Les compteurs par club sont calculés à la lecture.
- Les compteurs des cartes de statut restent **globaux au lot** et ne sont pas des compteurs filtrés par club.

### Recette production

- [ ] Lot contenant plusieurs clubs : vérifier la séparation des dossiers et des compteurs.
- [ ] Sélectionner un club puis « Introuvables » ; conserver le club en changeant de page et en effectuant une recherche textuelle.
- [ ] Vérifier que deux noms de club FFST similaires ne mélangent pas les dossiers : sélection par valeur exacte, sans `LIKE` pour le club.
- [ ] Tester un paramètre de club falsifié et un identifiant de lot différent.
- [ ] Tester le cas sans club, le cas avec une seule association de club, et un lot déjà appliqué.
- [ ] Vérifier qu'aucune licence, statut, paiement ni numéro FFST n'a changé avant et après consultation.
- [ ] Tester clavier, lecture sur mobile et préproduction avec UFSC Gestion et UFSC Licence & Compétition actifs.

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
