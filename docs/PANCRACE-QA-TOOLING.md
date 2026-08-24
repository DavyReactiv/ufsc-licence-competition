# Pancrace 2025 et outillage QA

## Objectif

Cette évolution ajoute un socle de règles Pancrace distinct du référentiel ASSAUT / TATAMI et renforce la recette du plugin avant toute refonte du parcours de génération des combats.

## Référentiel Pancrace

Source métier utilisée : **FFKMDA — Pancrace, Les règles techniques et de sécurité 2025 (V2)**.

Le code conserve explicitement les catégories d'âge sous forme de codes `U9`, `U11`, `U13`, `U15`, `U17`, `U40`, `U50` et `U49` pour les pros. Le document fourni ne définit pas dans son tableau les bornes numériques `age_min/age_max`; le plugin ne les invente donc pas dans ce lot.

Le référentiel encode :

- Assaut vs Combat et présence/absence du KO ;
- catégories de poids par code d'âge ;
- Assaut éducatif, Assaut technique, Combat Junior, Classe B, Classe A, Pro ;
- surfaces Tatami / Ring / Cage selon le profil ;
- durée et nombre de reprises ;
- tolérances de pesée ;
- formats officiels Championnat / Coupe / Open ;
- recommandations de nombre de surfaces pour les niveaux national/régional et les phases pro.

### Protection importante

Pancrace est traité comme un **profil métier hybride** (Tatami en assaut, Ring/Cage en combat), mais reste volontairement de type générique `other` dans l'ancien registre mono-surface. Le moteur générique de poids reçoit une table Pancrace vide afin qu'il **ne retombe jamais silencieusement sur les catégories ASSAUT/TATAMI**. La classification Pancrace doit passer par `PancraceReference2025` et un code d'âge explicite tant que la règle de calcul des U-codes n'a pas été formellement validée.

## Types d'événements

Le plugin distinguait déjà plusieurs types, dont Open et Gala. Le registre métier ajoute :

- Compétition ;
- Tournoi ;
- Coupe ;
- Open ;
- Gala ;
- Championnat régional ;
- Sélection régionale ;
- Championnat national ;
- Interclub ;
- Stage ;
- Autre.

`EventFormatRegistry` sépare le **type d'événement** de la **stratégie de génération** :

- tournoi/open/coupe/championnats → tableau/poule ;
- gala → carte de combats directs ;
- interclub → mixte ;
- stage → aucune génération de combats ;
- autre → manuel.

Ce registre prépare la future simplification du parcours sans modifier automatiquement les combats existants.

## Vérifications ajoutées

### PHP

- lint PHP sur PHP 7.4, 8.2 et 8.4 ;
- tests autonomes ASSAUT/TATAMI ;
- test autonome du référentiel Pancrace ;
- test autonome des types/stratégies d'événements ;
- PHPCS WordPress sur le nouveau périmètre ;
- PHPStan sur le nouveau périmètre ;
- garde CI contre l'ajout de `DROP TABLE` ou `TRUNCATE TABLE` dans une PR.

### WordPress / navigateur

L'environnement `@wordpress/env` crée un WordPress jetable avec le plugin monté localement.

Playwright vérifie :

- connexion à l'administration ;
- chargement des pages Compétitions, Catégories, Inscriptions, Combats, Plateau et Résultats ;
- absence de message d'erreur fatale ;
- présence de Pancrace dans la création d'une compétition ;
- présence des types Compétition, Tournoi, Coupe, Open et Gala ;
- captures visuelles desktop et mobile des principaux écrans.

Les rapports HTML, traces, vidéos d'échec et captures sont conservés comme artifacts GitHub Actions pendant 14 jours.

## Commandes locales

```bash
composer install
composer qa
npm install
npm run wp-env:start
npm run test:e2e
npm run wp-env:stop
```

## Étape suivante recommandée

Utiliser `EventFormatRegistry` et `PancraceReference2025` dans le futur assistant de préparation :

`Compétition → combattants → catégories → génération → surfaces → jour J`.

La refonte devra adapter la génération selon le type d'événement : un gala ne doit pas produire un tableau d'élimination comme un tournoi, et le Pancrace doit appliquer son propre profil de règles.
