# UFSC Licence Competition

`ufsc-licence-competition` est un plugin WordPress métier pour l’UFSC. Il complète l’écosystème licences et couvre désormais aussi la gestion opérationnelle des compétitions : licences officielles, synchronisation ASPTT, licences PDF nominatives, accès clubs, inscriptions, catégories, pesées, tableau de bord compétition, préparation des combats, suivi jour J, exports et diagnostics.

Le plugin est conçu pour un usage réel par les administrateurs UFSC, les organisateurs, les clubs, les officiels et les développeurs de maintenance. Les diagnostics sont non destructifs et les actions sensibles restent protégées.

---

## Fonctionnalités principales

- gestion des licenciés et données utiles à la compétition ;
- gestion des clubs et accès club ;
- synchronisation / import ASPTT ;
- génération ou gestion de licences PDF nominatives ;
- inscriptions aux compétitions pour licenciés UFSC et participants externes ;
- filtres avancés d’administration : compétition, statut, discipline, type, catégorie, club, groupe / lot quand disponible ;
- catégories par discipline et référentiels centralisés ;
- référentiel officiel **ASSAUT / TATAMI** ;
- prise en charge des données historiques et des anciens schémas UFSC ;
- pesées, numéros combattants, contrôle de poids et reclassification ;
- tableau de bord compétition avec compteurs, catégories détectées, statuts et recommandations ;
- diagnostics de cohérence pour préparer le jour J ;
- préparation et prévisualisation des combats selon les garde-fous disponibles ;
- exports, impressions et documents de synthèse ;
- sécurité, droits d’accès, nonces et logs d’audit.

---

## Modules administratifs

### Licences / UFSC Licences

- consultation et suivi des licences ;
- import / synchronisation ASPTT ;
- statuts et paramètres licences ;
- pages de diagnostic et de statut technique ;
- passerelles avec les inscriptions compétition lorsque les données licenciés sont nécessaires.

### Compétitions / Licence Competition

- **Compétitions** : création, configuration, dates, discipline, lieu, tolérance pesée et suivi ;
- **Catégories & formats** : catégories sportives, formats, prévisualisation et diagnostics ;
- **Inscriptions** : saisie, import, validation, filtres avancés, groupes / lots si la colonne est disponible ;
- **Pesées** : poids pesé, numéro combattant, statut de pesée, reclassification proposée et messages de blocage ;
- **Combats** : préparation, prévisualisation, génération encadrée, suivi et résultats selon les droits ;
- **Plateau jour J** : suivi terrain par surface quand configuré ;
- **Impression / exports** : listes, feuilles, résultats, podiums provisoires et rapports ;
- **Qualité / diagnostics** : contrôles de cohérence, anomalies et logs ;
- **Actions sensibles** : opérations protégées par capability, nonce, confirmation et périmètre explicite.

---

## Référentiel officiel ASSAUT / TATAMI

Le plugin contient un référentiel officiel ASSAUT / TATAMI centralisé. Il couvre notamment :

- pré-poussins ;
- poussins ;
- benjamins ;
- minimes filles / garçons ;
- cadettes / cadets ;
- juniors filles / garçons ;
- seniors femmes / hommes ;
- vétérans féminines / masculins ;
- poids fermés ;
- catégories ouvertes ;
- séparation stricte filles / garçons quand nécessaire.

Ce référentiel est utilisé pour l’aide à la classification, les diagnostics et la cohérence des catégories. Il est **non destructif** : il ne modifie pas automatiquement les inscriptions, les pesées, les catégories historiques ou la discipline d’une compétition existante.

Les compétitions configurées en `light_contact` ou `kick_light` ne sont pas converties automatiquement vers `assaut`. L’administrateur doit vérifier le référentiel réellement applicable avant toute reclassification.

---

## Compatibilité avec les données historiques

Le plugin conserve la compatibilité avec les données UFSC anciennes ou importées :

- inscriptions sans `category_id` ;
- catégories stockées sous forme de texte ;
- poids historiques ou hors référentiel strict ;
- anciennes URLs utilisant `ufsc_category_id` ;
- nouvelles URLs utilisant `ufsc_category_filter` ;
- libellés hérités de disciplines ou d’anciens référentiels ;
- champs de fallback pour sexe, poids, date de naissance, club et catégorie.

Les poids historiques comme `-70`, `-75`, `-85` ou `-90` peuvent rester affichables et filtrables même s’ils sont signalés comme “hors référentiel strict” ou “poids à contrôler”. L’administrateur décide d’une éventuelle reclassification.

---

## Sécurité et garde-fous

- aucune suppression automatique de données réelles ;
- pas de modification automatique des inscriptions existantes ;
- pas de modification automatique des pesées existantes ;
- pas de génération, régénération, suppression ou remplacement automatique des combats ;
- pas de modification automatique des résultats ;
- diagnostics non destructifs ;
- actions sensibles protégées par droits WordPress, nonce, IDs explicites et périmètre compétition ;
- requêtes préparées pour les filtres et lectures SQL directes ;
- actions de groupe limitées aux inscriptions explicitement cochées ;
- conservation des filtres d’administration quand c’est possible ;
- logs d’audit pour les opérations importantes.

---

## Workflow recommandé avant jour J

1. Tester le parcours sur une compétition de test.
2. Vérifier les licences et imports ASPTT.
3. Créer ou contrôler la compétition : discipline, date, lieu, tolérance pesée.
4. Importer ou saisir les inscriptions.
5. Contrôler les filtres et catégories détectées.
6. Valider explicitement les inscriptions prêtes.
7. Réaliser les pesées et enregistrer poids, statut, numéro combattant et reclassification si nécessaire.
8. Consulter les diagnostics de cohérence et les catégories hors référentiel.
9. Prévisualiser la préparation des combats selon les garde-fous disponibles.
10. N’appliquer aucune action sensible sur une compétition réelle sans sauvegarde et validation administrative.

---

## Tests et contrôles développeur

Commandes utiles après modification :

```bash
php -l path/to/modified-file.php
php tests/assaut-tatami-reference-test.php
git diff --check
rg "TRUNCATE|DROP TABLE|DELETE FROM|UPDATE .*inscriptions|UPDATE .*entries|DELETE .*fights|DELETE .*combats" .
git diff --unified=0 | rg "TRUNCATE|DROP TABLE|DELETE FROM|UPDATE .*inscriptions|UPDATE .*entries|DELETE .*fights|DELETE .*combats" || true
```

---

## Notes développeur

Chemins principaux :

- `includes/competitions/` : socle du module compétition ;
- `includes/competitions/Admin/` : pages, tables et contrôleurs admin ;
- `includes/competitions/Repositories/` : accès données et requêtes ;
- `includes/competitions/Services/` : services métier, génération, diagnostics, sécurité ;
- `includes/competitions/Services/UfscReference/` : référentiel ASSAUT / TATAMI et règles UFSC ;
- `tests/` : tests et scripts de vérification.

---

## Limites et précautions

- Toujours tester sur une compétition de test avant une compétition réelle.
- Ne jamais importer, corriger ou reclassifier des catégories sur une compétition réelle sans sauvegarde.
- Vérifier manuellement les catégories hors référentiel avant toute reclassification.
- Ne pas confondre ASSAUT, Light Contact, Kick Light et Tatami.
- Les données historiques peuvent être affichées même si elles ne correspondent pas au référentiel strict actuel.
- Les podiums, résultats ou diagnostics peuvent nécessiter une validation humaine finale.
- Les compétitions réelles avec combats existants doivent être manipulées avec une prudence maximale.
