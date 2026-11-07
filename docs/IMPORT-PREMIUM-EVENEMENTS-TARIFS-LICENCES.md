# Import premium — événements, tarifs multiples et recherche de licences

## Portée du lot
Ce lot est empilé sur la branche de la PR #559. **Ne pas fusionner avant #559**.

### Gestion événement
Menu **Compétitions → Import événements premium** :
- Télécharger modèle CSV UTF-8 BOM (séparateur point-virgule).
- Importer une fiche contenant **une seule manifestation**.
- Prévisualiser et contrôler avant toute création.
- Créer seulement un **brouillon** et refuser le même titre + saison + date/heure pour éviter les doublons.
- Réexporter une fiche au même format.
- Champs : nom, type, discipline, saison, date/heure début et fin, salle, adresse, code postal, ville, région, club organisateur/ID, contact club (nom/téléphone/email), ouverture/clôture inscriptions, date de forclusion, pesée début/fin, description, notes complémentaires, catégories de combats et statut (forcé à draft lors de la création).
- Données additionnelles événement : option `ufsc_competition_premium_meta_{id}` ; les champs existants vont dans `ufsc_competitions` après vérification du schéma. **Ne pas utiliser le flux standard de sauvegarde pour rééditer ces données sans vérifier sa compatibilité**, car il pourrait les écraser.

### Tarifs multiples (dissociés de l'import événement)
Menu **Compétitions → Tarifs des événements** :
- Lier la grille à un ID événement ; importer et exporter la grille CSV séparément.
- 1 ligne = 1 tarif ; types autorisés : `normal`, `reduit`, `gratuit`, `enfant`, `jeune`, `etudiant`, `senior`, `licencie`, `club`, `groupe`, `famille`, `prevente`, `sur_place`, `vip`, `invitation`, `autre`.
- Colonnes : `tariff_code;tariff_type;tariff_label;price_eur;currency;condition_note;min_age;max_age;valid_from;valid_until;quantity_limit;is_active;additional_note`.
- Exemple « Gratuit moins de 12 ans » : `MOINS12;gratuit;Enfants de moins de 12 ans;0.00;EUR;Gratuit sur justificatif d'âge;0;11;;;;1;Selon conditions du club`.
- Tous les montants en EUR, zéro imposé au tarif `gratuit`. Dates AAAA-MM-JJ ; quota, bornes d'âge, activation, doublons vérifiés.
- L'import remplace **seulement** la grille de l'événement après confirmation explicite ; sauvegarde de la version précédente dans une option d'archive.
- Les tarifs sont actuellement des paramètres d'information, **pas** un système de billetterie ou d'encaissement. Pas de calcul automatique d'âge sans la date de naissance des participants.

### Recherche licences
Menu **Compétitions → Recherche licences** :
- Recherche administrative en lecture seule sur nom, prénom, numéro, club.
- Filtre statut et saison quand colonnes correspondantes présentes.
- Consultation transversale plafonnée à 50 résultats, accès limité aux utilisateurs autorisés à modifier les compétitions.
- Pas d'autosélection d'une licence dans un combat dans ce lot : raccordement au plateau à développer dans une PR distincte.
- La base de licences reste source de vérité ; aucune création ou correction automatique de statut/licence.

## À contrôler avant fusion
- PHP syntax lint sur les 4 fichiers modifiés et `bootstrap.php`.
- Menus et permissions administrateur / gestionnaire / club non autorisé.
- CSV BOM, délimiteur ; et ,, noms accentués ; volume limite, données invalides, entête incorrecte.
- Créer un gala de test : statut draft ; export ; refus doublon ; vérifier les champs et la date de forclusion.
- Grille tarifaire : normal + gratuit -12 + prévente + VIP ; exporter / réimporter ; contrôle zéro pour gratuit, contrôle dates / âge, backup.
- Recherche licence : correspondance exacte/partielle, filtre statut et saison, absence de résultat, table absente, aucune fuite de champs privés.
- Retester création/édition existantes, gestion combat, pesées, PDF et flux de licences, **aucune régression, aucune perte de données**.
- Vérifier compatibilité sur WordPress PHP installé, notamment options persistantes, schéma colonne et scopes.
- La prise en charge native .xlsx, l'import de combats en base et l'autocomplete des combattants ne sont pas inclus dans ce lot.
