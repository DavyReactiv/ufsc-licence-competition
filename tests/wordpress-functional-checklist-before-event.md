# Checklist fonctionnelle WordPress avant compétition réelle

Objectif : vérifier que le plugin **UFSC Licences Compétitions** est prêt pour une compétition officielle, sans modifier ni supprimer de données réelles.

> Règle absolue : sur une compétition réelle, ne pas cliquer sur une action qui génère, valide, régénère, supprime, restaure, importe, corrige ou verrouille des données. Le parcours réel ci-dessous est en lecture seule.

## Préparation commune

- Travailler connecté avec un compte ayant accès aux menus Compétitions.
- Ouvrir l'administration WordPress dans un navigateur standard.
- Préparer un document de relevé avec : date/heure du test, utilisateur connecté, navigateur, URL, ID de compétition, captures d'écran.
- Identifier clairement :
  - **Compétition réelle** : compétition avec inscriptions existantes, à ne pas modifier.
  - **Compétition test** : compétition explicitement nommée `[TEST] ...`, destinée aux actions complètes.
- Si une erreur apparaît, faire une capture de l'écran complet avec l'URL visible et noter l'heure exacte.

## Ne jamais faire sur une compétition réelle

- Ne pas lancer de génération directe.
- Ne pas valider un brouillon de combats.
- Ne pas régénérer un brouillon existant.
- Ne pas supprimer, restaurer ou supprimer définitivement une compétition, une inscription ou un combat.
- Ne pas utiliser les actions en masse destructrices.
- Ne pas importer un CSV sur la compétition réelle pendant ce test.
- Ne pas modifier une pesée déjà validée.
- Ne pas saisir, corriger ou verrouiller un résultat.
- Ne pas lancer de fixture, nettoyage de données test ou action sensible.

## Parcours A — Compétition réelle : lecture seule / vérification uniquement

### A1. Tableau de bord Compétitions

- Page : **Compétitions**.
- Ce que je dois voir :
  - Sélecteur de compétition.
  - KPIs : inscrits, pesées validées/restantes, combats générés, résultats saisis.
  - Alertes opérationnelles et liens rapides contextualisés.
- Ce que je dois vérifier :
  - Sélectionner la compétition réelle sans cliquer sur action de mutation.
  - Les compteurs correspondent globalement aux données attendues.
  - Les liens rapides ouvrent les pages attendues sans changer les données.
- Ne surtout pas cliquer : suppression, génération, régénération, actions sensibles.
- Signes d'erreur : compteur à zéro alors que des données existent, page blanche, erreur PHP, mauvaise compétition sélectionnée.
- Captures/relevés si problème : URL, ID de compétition, KPIs affichés, message d'erreur.
- Résultat attendu : le tableau de bord s'affiche sans erreur et donne une lecture cohérente de la compétition réelle.

### A2. Inscriptions

- Page : **Compétitions > Inscriptions**.
- Ce que je dois voir : liste des inscrits, statuts, club, catégorie, filtres/recherche.
- Ce que je dois vérifier :
  - Les inscriptions réelles sont visibles.
  - Les filtres par compétition ne mélangent pas deux compétitions.
  - Les statuts attendus sont compréhensibles.
- Ne surtout pas cliquer : créer, modifier, supprimer, actions en masse, import CSV.
- Signes d'erreur : inscriptions absentes, doublons évidents, inscription d'une autre compétition, message SQL/PHP.
- Captures/relevés si problème : ID compétition, nombre affiché, filtres actifs, ligne exemple anonymisée.
- Résultat attendu : les inscriptions réelles sont consultables sans modification.

### A3. Pesées

- Page : **Compétitions > Pesées**.
- Ce que je dois voir : liste des inscrits, poids prévu/réel, statut pesée, filtres, compteurs validées/restantes.
- Ce que je dois vérifier :
  - La compétition sélectionnée est la bonne.
  - Les compteurs de pesées sont plausibles.
  - Les filtres club/catégorie/statut fonctionnent en lecture.
- Ne surtout pas cliquer : enregistrer une pesée, modifier poids, valider/refuser/absent sur compétition réelle.
- Signes d'erreur : formulaire qui sauvegarde sans action explicite, poids manquant inattendu, erreur de permission.
- Captures/relevés si problème : compteur validées/restantes, filtre utilisé, message d'erreur.
- Résultat attendu : consultation fluide des pesées sans écriture.

### A4. Qualité

- Page : **Compétitions > Qualité**.
- Ce que je dois voir : anomalies, filtre compétition, section **Protection des données réelles**.
- Ce que je dois vérifier :
  - La compétition réelle est sélectionnable.
  - Les compteurs protection affichent inscriptions réelles, combats existants, résultats saisis, combats verrouillés.
  - Les actions sensibles bloquées/renforcées sont listées si des données existent.
  - Les anomalies bloquantes ou avertissements sont compréhensibles.
- Ne surtout pas cliquer : aucun bouton menant à correction de masse ou action sensible.
- Signes d'erreur : section absente, compteurs incohérents, page inaccessible, warning PHP.
- Captures/relevés si problème : section Protection complète, anomalies visibles, ID compétition.
- Résultat attendu : la page Qualité résume correctement les risques sans modifier les données.

### A5. Combats

- Page : **Compétitions > Combats**.
- Ce que je dois voir : liste des combats, statuts, surfaces/plateaux, éventuelle zone de prévisualisation/génération.
- Ce que je dois vérifier :
  - Les combats existants sont listés pour la bonne compétition.
  - Les résultats déjà saisis ne sont pas présentés comme modifiables directement.
  - Les messages de génération indiquent une prévisualisation avant validation officielle.
- Ne surtout pas cliquer : générer brouillon, régénérer, valider brouillon, génération directe, supprimer combat, saisir/corriger/verrouiller résultat.
- Signes d'erreur : bouton destructeur trop accessible, succès après simple consultation, combats d'une autre compétition.
- Captures/relevés si problème : boutons visibles, notices, ID combat, statut affiché.
- Résultat attendu : consultation des combats sans action de mutation.

### A6. Plateau jour J

- Page : **Compétitions > Plateau jour J**.
- Ce que je dois voir : combats par surface/plateau, statuts opérationnels, accès de suivi.
- Ce que je dois vérifier :
  - Les plateaux/surfaces sont lisibles.
  - Les horaires ou ordres sont cohérents si disponibles.
  - Les combats terminés/verrouillés sont clairement identifiables.
- Ne surtout pas cliquer : changer statut, changer surface, démarrer/terminer combat sur compétition réelle.
- Signes d'erreur : surface vide alors que combats existent, statut incohérent, page lente ou illisible.
- Captures/relevés si problème : surface, combat, statut, URL.
- Résultat attendu : lecture opérationnelle correcte du plateau.

### A7. Impression

- Page : **Compétitions > Impression**.
- Ce que je dois voir : choix de compétition, type de document, format A4/A3/A2, bouton afficher/imprimer.
- Ce que je dois vérifier :
  - Documents à tester en lecture : liste des inscrits, liste des pesées, combats par surface/tous les plateaux, feuille de résultats.
  - Le rendu imprimable ne contient pas l'interface WordPress inutile dans la zone imprimée.
  - Les documents sont lisibles en A4 ou indiquent clairement qu'un format paysage est recommandé.
- Ne surtout pas cliquer : aucune action destructive n'est attendue ici ; ne pas modifier la compétition depuis un lien annexe.
- Signes d'erreur : document vide, mauvais noms/plateaux, colonnes coupées, erreur PHP.
- Captures/relevés si problème : aperçu avant impression, type de document, format, compétition.
- Résultat attendu : les impressions s'affichent sans modifier les données.

### A8. Résultats

- Page : généralement **Combats** ou **Plateau jour J** selon l'interface disponible.
- Ce que je dois voir : résultats existants, statut du combat, vainqueur/méthode si saisis.
- Ce que je dois vérifier :
  - Les résultats déjà saisis sont lisibles.
  - Les résultats verrouillés sont clairement protégés.
  - Les corrections nécessitent un workflow supervisé/motif.
- Ne surtout pas cliquer : saisir résultat, corriger, verrouiller, propager résultat.
- Signes d'erreur : résultat absent alors qu'attendu, bouton de modification directe sur résultat verrouillé, succès inattendu.
- Captures/relevés si problème : ID combat, statut, résultat affiché, boutons visibles.
- Résultat attendu : résultats consultables, pas de mutation.

### A9. Logs

- Page : **Compétitions > Logs**.
- Ce que je dois voir : historique des actions et audits, filtres éventuels.
- Ce que je dois vérifier :
  - Les actions sensibles précédentes sont visibles.
  - Les blocages récents apparaissent avec action, compétition, objet et raison.
- Ne surtout pas cliquer : purge des logs, nettoyage, suppression.
- Signes d'erreur : logs vides alors que des actions existent, erreur d'accès, horodatage incohérent.
- Captures/relevés si problème : filtre utilisé, plage horaire, message exact.
- Résultat attendu : consultation des logs possible sans purge.

### A10. Actions sensibles

- Page : **Compétitions > Actions sensibles**.
- Ce que je dois voir : actions protégées, avertissements, confirmations, simulations éventuelles.
- Ce que je dois vérifier :
  - Les actions dangereuses indiquent clairement leurs impacts.
  - Les confirmations fortes sont requises.
  - Les actions de régénération/remplacement ne sont pas exécutables par erreur.
- Ne surtout pas cliquer : exécuter, confirmer, réintégrer, régénérer, supprimer, nettoyer, simuler si la simulation écrit des données.
- Signes d'erreur : bouton destructeur sans confirmation, message de succès sans action voulue, absence d'avertissement.
- Captures/relevés si problème : libellé du bouton, avertissement affiché, droits utilisateur.
- Résultat attendu : page informative, aucune action lancée sur réel.

### A11. Diagnostic Accès

- Page : **Compétitions > Diagnostic Accès**.
- Ce que je dois voir : diagnostic des droits et périmètres d'accès.
- Ce que je dois vérifier :
  - Le compte connecté voit seulement les compétitions autorisées.
  - Les capabilities importantes sont cohérentes.
  - Aucune compétition d'une autre région/club n'est exposée par erreur.
- Ne surtout pas cliquer : test d'action qui modifie des permissions ou des données.
- Signes d'erreur : accès à une compétition non autorisée, capability manquante pour administrateur légitime, erreur nonce.
- Captures/relevés si problème : utilisateur, rôle, capability, compétition visible/invisible.
- Résultat attendu : diagnostic cohérent sans modification de données.

## Parcours B — Compétition test : actions complètes autorisées

> Utiliser uniquement une compétition clairement nommée `[TEST] ...`. Ne jamais réutiliser une compétition réelle pour ce parcours.

### B1. Créer une compétition test

- Action : créer une compétition `[TEST] Vérification avant événement`.
- Vérifier : statut ouvert/draft selon workflow, discipline, dates, lieu, surfaces si nécessaires.
- Ne pas faire : importer des données réelles.
- Résultat attendu : compétition test visible dans le tableau de bord et Qualité.

### B2. Ajouter quelques inscriptions test

- Action : créer 4 à 8 inscriptions fictives avec clubs/catégories/poids distincts.
- Vérifier : elles apparaissent uniquement dans la compétition test.
- Résultat attendu : KPIs et Qualité reflètent les inscriptions test.

### B3. Valider quelques pesées test

- Action : saisir poids réel et statut validé pour plusieurs inscrits test.
- Vérifier : logs de modification, compteur pesées validées, absence de mélange avec réel.
- Résultat attendu : pesées enregistrées et visibles dans Impression > Liste des pesées.

### B4. Générer un brouillon de combats test

- Action : générer uniquement un brouillon/prévisualisation.
- Vérifier : liste des inclus/exclus, motifs d'exclusion, répartition par plateau, nombre estimé de combats.
- Résultat attendu : brouillon affiché, aucune validation officielle avant contrôle visuel.

### B5. Valider une génération test

- Action : valider officiellement le brouillon test.
- Vérifier : snapshot/log d'application, combats créés, surfaces assignées si configurées.
- Résultat attendu : combats visibles dans Combats et Plateau jour J.

### B6. Saisir un résultat test

- Action : choisir vainqueur rouge/bleu, méthode de victoire, commentaire facultatif.
- Vérifier : statut terminé, audit `result_recorded`, pas de message succès trompeur.
- Résultat attendu : résultat visible en Combats, Impression et éventuellement Plateau.

### B7. Verrouiller un résultat test

- Action : verrouiller un combat terminé avec raison.
- Vérifier : statut verrouillé, audit `result_locked`, impossibilité de modification directe.
- Résultat attendu : verrou visible et correction directe bloquée.

### B8. Vérifier blocage de régénération après résultat/verrou

- Action : tenter de régénérer ou valider un nouveau brouillon sur la compétition test verrouillée.
- Vérifier : message d'erreur clair indiquant résultats/verrous présents, log `sensitive_action_blocked`.
- Résultat attendu : aucun combat existant supprimé ou écrasé.

### B9. Vérifier suppression définitive protégée

- Action : tenter une suppression définitive de la compétition test sans confirmation forte/snapshot.
- Vérifier : action bloquée, notice erreur, log de blocage.
- Résultat attendu : compétition test encore visible, données conservées.

### B10. Vérifier impressions test

- Action : générer les impressions inscrits, pesées, combats par surface, feuille résultats.
- Vérifier : lisibilité A4/paysage, bons noms test, aucun réel mélangé.
- Résultat attendu : documents imprimables propres.

## Bloquant avant compétition

Considérer le plugin **non prêt** tant qu'un des points suivants n'est pas résolu :

- Erreur PHP visible ou page blanche dans wp-admin.
- Inscriptions réelles non visibles ou compteur manifestement faux.
- Page Qualité inaccessible.
- Section **Protection des données réelles** absente ou incohérente.
- Pesées non enregistrées sur compétition test.
- Impression illisible ou document vide malgré données existantes.
- Génération de brouillon impossible en test sans explication claire.
- Génération officielle non prévisualisable.
- Résultat impossible à saisir sur combat test autorisé.
- Verrouillage résultat impossible en test après résultat terminé.
- Régénération non bloquée après résultat/verrou test.
- Logs absents après action sensible ou blocage.
- Message de succès affiché alors qu'une action a été bloquée.
- Données d'une autre compétition visibles dans une page filtrée.

## Informations à relever en cas de problème

- URL complète de la page.
- Heure exacte du test.
- Utilisateur et rôle WordPress.
- ID et nom de compétition.
- ID inscription/combat/pesée si concerné.
- Message affiché à l'écran.
- Capture écran complète.
- Dernières lignes pertinentes des logs WordPress/PHP si disponibles.

## Priorités de test avant jour J

1. Parcours réel A1 à A5 : tableau de bord, inscriptions, pesées, qualité, combats.
2. Parcours réel A7 : impressions principales.
3. Parcours test B4 à B8 : brouillon, validation test, résultat, verrouillage, blocage régénération.
4. Logs après blocage d'une action sensible test.
5. Diagnostic Accès avec le compte qui sera utilisé le jour de la compétition.
