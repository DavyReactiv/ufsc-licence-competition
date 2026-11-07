# UFSC / FFST — Procès-verbal de gala (spécification fonctionnelle)

Source de vérité : document officiel **UFSC-FFST-Proces-Verbal-Gala-2026-2027.pdf**, 12 pages, fourni le 06/11/2026. **Cette spécification prépare une seconde PR** : le formulaire superviseur et le PDF officiel ne sont pas développés dans la PR d'import CSV.

## Objectif et droits
- Module « Procès-verbal de manifestation » rattaché à un événement / gala (competition_id), avec accès limité au superviseur explicitement affecté et aux administrateurs autorisés.
- Ne jamais supposer qu'un administrateur de club est superviseur.
- Préremplir depuis événement, plateau, licences, officiels, pesées et résultats **uniquement si l'information est fiable**. Garder les champs éditables ; un numéro de licence inconnu doit être signalé.
- Brouillon / à compléter / soumis / visé / transmis ; journal d'audit horodaté (création, modifications, signatures, envoi), verrouillage des versions finalisées avec correction par nouvelle version.
- Envoi à gala@ufsc-france.org dans les **48 h après le gala** ; indiquer échéance, pas d'envoi sans validation explicite. Accès et conservation renforcés pour données médicales. Pas de diagnostic généré automatiquement.

## Reprise exhaustive du PDF (par page)

### Page 1 — Informations générales
- Intitulé de la manifestation sportive
- Date, lieu
- Nom et prénom du superviseur fédéral, n° de licence, téléphone, mail
- 8 lignes « Noms et licence des Officiels » : nom et n° de licence
- Nom et prénom du médecin
- Rappel dossier complété et signé sous 48 h à UFSC, gala@ufsc-france.org
- En-tête UFSC, pied de page officiel UFSC/FFST saison 2026-2027, coordonnées institutionnelles du document source

### Pages 2–3 — Feuilles de pesée (combats 1 à 15)
Par **combat** et par coin rouge / bleu :
- N° combat dans l'ordre du plateau
- Catégorie de poids / classe
- Nom & prénom
- Licence
- POIDS (poids constaté)
- APTE (avis consigné, ne pas l'inférer d'un poids)
- Visa du superviseur sur chaque feuille
- Paginer 1–8, puis 9–15 ; si davantage de combats, ajouter des feuilles identiques sans omettre de combat.

### Page 4 — Feuille de résultats (combats 1 à 15)
Pour chaque combat :
- Nom ARBITRE
- Nom JUGE 1
- Nom JUGE 2
- Nom JUGE 3
- Nom et coin du VAINQUEUR
- Type de victoire : WP / KO / TKO / RSC
- Visa du superviseur
- Étendre la pagination si davantage de 15 combats.

### Page 5 — Questionnaire à remplir par le superviseur
1. Appréciation générale :
  - organisation (respect horaires/déroulé), texte
  - sécurité/respect règlements fédéraux, texte
2. Qualité des oppositions :
  - Niveau équilibré des combattants : OUI/NON
  - Nombre d'arrêts de combat par l'arbitre pour insuffisance technique
  - Nombre de KO (tous types confondus)
  - Autre remarque, texte
  - Rappel : chaque KO, KO technique et arrêt de l'arbitre fait l'objet d'un rapport du médecin ; type de KO et résultat du protocole commotion à déterminer par le médecin.
3. Réclamations : OUI/NON ; si oui nombre et motif(s)
4. Événement grave ou inattendu : OUI/NON ; si oui description

### Page 6 — Fiche médicale : Examen post combat (information réglementaire à reproduire)
Reproduire la note du document source, sans la convertir en diagnostic logiciel :
- Gestion du KO par le médecin de ring ; examen à la sortie du ring
- Examen médical systématique après défaite par arrêt de l'arbitre sur blessure et KO technique
- Modalités KO technique citées : jet de l'éponge ; arrêt de l'arbitre après décompte (compté 10, trois fois dans le même round, quatre fois sur l'ensemble du combat pro, trois fois sur l'ensemble du combat amateur casqué) ; différence de niveau
- KO vasculaire ; KO neurovégétatif ; KO cérébral avec ébranlement (commotion ou suspicion)
- Diagnostic commotion selon les situations de l'annexe 2a du règlement médical fédéral, cité par la source.

### Page 7 — Tableau officiel arrêt de combat / examen / commotion
Conserver visuellement le tableau de référence, y compris les 8 cas et les 6 décisions qui imposent un diagnostic commotion :
1. KO tête (cérébral, postural ou vasculaire)
2. Insuffisance technique (outclass) après un ou plusieurs coups reçus à la tête
3. Blessure tête (injury head)
4. Cumul de comptes (count limit) après un ou plusieurs coups reçus à la tête
5. Jet de l'éponge après un ou plusieurs coups reçus à la tête
6. Non-reprise du combat après un ou plusieurs coups reçus à la tête

Liste des 8 décisions / cas du PDF : KO tête ; KO corps ; insuffisance technique ; blessure tête ; blessure corps ; cumul de comptes ; jet de l'éponge ; non-reprise du combat. Mentionner la faculté pour le médecin de réaliser un diagnostic lorsqu'il le juge nécessaire.

### Pages 8–12 — Suivi médical (5 feuilles / 15 blocs dans la source)
Chaque fiche de sportif examiné :
- Nom et prénom du combattant examiné
- Club
- N° de téléphone
- Commentaire du médecin et type de KO (le cas échéant)
- Diagnostic Commotion
Chaque page (3 blocs par feuille) :
- Nom et signature du superviseur
- Nom, cachet et signature du médecin
Créer des pages supplémentaires si >15 examens, sans limite métier arbitraire. Autoriser l'absence de cas médicaux sans inventer d'informations.
Attention : « Diagnostic Commotion », commentaires et contact d'un sportif sont des **données sensibles** : écriture exclusivement par le médecin autorisé ou sous sa responsabilité, chiffrement/contrôle d'accès selon architecture retenue, pas de détail médical dans les exports publics, journaux ou emails non protégés.

## Exigences de sortie et production
- PDF officiel paginé A4 suivant le modèle d'origine, logos **UFSC + FFST uniquement** suivant charte institutionnelle, sans logo FFKMDA.
- Reproduction fidèle des titres, champs, tableaux, libellés et mentions du PDF, avec pagination automatique pour >15 combats / examens.
- Apports numériques : état des champs manquants, sauvegarde de brouillons, préremplissage, visa/signature, génération PDF, traçabilité, transmission dans les délais.
- Ne pas modifier ni recalculer un combat clôturé depuis le PV ; lire les résultats existants ; toute divergence remonte une anomalie à résoudre.
- Sécurisation : nonce, permissions par événement, anti-IDOR, contrôle de concurrence, audit, aucune migration destructive, aucune suppression de données. Éviter d'enregistrer le médical dans les tables exposées au club.
- Les textes médicaux règlementaires sont à reproduire fidèlement depuis le PDF fourni, pas à redéfinir en code. Une validation du responsable médical est nécessaire avant mise en production.

## Tests attendus (seconde PR)
- Gala à 1, 8, 15, 16, 30 combats ; pagination et ordre vérifiés.
- Combattants externes, licences inconnues, plusieurs surfaces et arbitres.
- Pesée apte/non renseignée ; WO/résultat absent ; KO et arrêts avec suivi médical ; réclamations et événements graves.
- Un superviseur ne peut accéder au PV d'un autre gala ; aucun club n'accède aux notes médicales.
- Signature, cachet médecin, versionnage, export officiel, échéance 48 h, échec d'envoi, reprise et correction.
- Tests de non-régression de l'import CSV, exports existants et génération des combats.
