# Lot 8 — Feuilles de combat imprimables

## Objectif

Le Lot 8 améliore les impressions de compétition en lecture seule pour fournir des documents A4 exploitables le jour J par les arbitres, juges, officiels, responsables de surface et organisateurs.

Ce lot ne modifie pas la génération, les brouillons, les surfaces, l'ordre de passage, les résultats ni la structure SQL. Les pages d'impression restent des vues administrateur non mutantes.

## Impressions existantes observées

La page d'impression admin centralise déjà plusieurs sorties via `print_type` :

- `entries` : liste détaillée des inscrits ;
- `categories` : référentiel des catégories ;
- `fights_by_surface` : répartition des combats par surface ;
- `surface_sheet` : feuille de surface historique ;
- `surface_overview` : synthèse organisation ;
- `weighins` : liste des pesées ;
- `results_sheet` : feuille de résultats vierge ;
- `results_entered` : résultats déjà saisis ;
- `lone_fighters` : combattants sans adversaire.

Les impressions étaient déjà protégées par capability administrateur et utilisaient majoritairement des échappements WordPress. Les améliorations Lot 8 conservent ces protections et ajoutent des filtres de lecture seule.

## Impressions ajoutées ou améliorées

### Liste générale des combats

Nouveau type `fights_list`.

Objectif : fournir une vue globale à l'organisation, triée de manière stable par surface, ordre, horaire, numéro de combat puis identifiant.

Colonnes principales :

- ordre de passage ;
- numéro de combat ;
- horaire si disponible ;
- discipline ;
- catégorie / niveau / poids ;
- combattant rouge et club rouge ;
- combattant bleu et club bleu ;
- statut et type de cas particulier ;
- zone résultat rapide ;
- zone observation / signature.

### Feuille de combats par surface

Types améliorés : `fights_by_surface` et `surface_sheet`.

Objectif : donner à chaque responsable de surface la liste des combats à gérer, avec zones papier pour résultat rapide, observation et signature.

Chaque surface est imprimée dans une section distincte avec saut de page à l'impression lorsque le navigateur le supporte.

### Feuilles arbitres / juges par combat

Nouveau type `judge_sheets`.

Objectif : fournir une feuille claire pour suivre ou noter un combat individuel.

Données affichées :

- compétition via l'en-tête commun ;
- surface ;
- numéro de combat ;
- ordre ;
- discipline ;
- catégorie / format ;
- durée si disponible ;
- rouge et club rouge ;
- bleu et club bleu ;
- cases vainqueur rouge / bleu ;
- cases décision, forfait, abandon, disqualification, KO/TKO ;
- zones score, observations et signature.

Les combats `bye` ou `placeholder` sont signalés comme cas d'organisation et ne produisent pas une feuille arbitre individuelle de combat réel.

### Feuille résultats vierge

Type amélioré : `results_sheet`.

La feuille présente maintenant l'ordre, la surface, le numéro de combat, les deux combattants, le vainqueur, la méthode, le score, une observation et une signature.

## Filtres disponibles

Les filtres GET suivants sont disponibles et restent strictement en lecture seule :

- `competition_id` : identifiant de compétition, sanitisé par `absint` ;
- `print_type` : type d'impression, sanitisé par `sanitize_key` ;
- `surface` : libellé ou partie de libellé de surface, sanitisé par `sanitize_text_field` ;
- `category_id` : identifiant de catégorie, sanitisé par `absint` ;
- `discipline` : discipline ou libellé de discipline, sanitisé par `sanitize_text_field` ;
- `status` : statut du combat, sanitisé par `sanitize_key` ;
- `format` : format papier `a4`, `a3` ou `a2`.

Les filtres sont appliqués uniquement sur la liste récupérée depuis le repository des combats. Ils ne déclenchent aucune écriture.

## Protections de sécurité

- Accès à la page d'impression conditionné par la capability de lecture compétition existante.
- Aucune mutation déclenchée par les impressions.
- Données GET sanitisées avant usage.
- Sorties HTML échappées via `esc_html`, `esc_attr` ou `wp_kses_post` lorsque des retours ligne contrôlés sont nécessaires.
- Les boutons d'impression utilisent `window.print()` côté navigateur sans action serveur destructive.
- Aucun nonce ajouté car l'affichage est en lecture seule ; cela évite de transformer une impression en action mutante.

## Gestion des BYE et placeholders

Les cas `bye` et `placeholder` sont affichés explicitement :

- dans les listes et feuilles de surface, ils restent visibles pour l'organisation ;
- le statut / type signale BYE ou attente / placeholder ;
- les feuilles arbitres individuelles les affichent sous forme de notice et non comme combat normal ;
- aucun faux adversaire n'est généré dans l'affichage.

## CSS print

Le CSS print ajoute ou renforce :

- format blanc, lisible et sobre ;
- tableaux avec bordures fines ;
- zones résultat, observation et signature ;
- sections de surface avec saut de page ;
- feuilles arbitres avec saut de page individuel ;
- masquage des menus WordPress et boutons à l'impression ;
- mise en page A4 exploitable sans dépendance PDF externe.

## Limites volontaires

- Pas de génération PDF serveur ajoutée.
- Pas de verrouillage automatique après impression.
- Pas de journal d'impression ajouté.
- Pas de saisie de résultats dans ce lot.
- Pas de modification de la page plateau.
- Pas de modification de la répartition des surfaces ou de l'ordre de passage.

## Tests manuels sur compétition `[TEST]`

- Imprimer la liste générale des combats.
- Imprimer par surface avec une, deux puis quatre surfaces.
- Filtrer par surface, catégorie, discipline et statut.
- Vérifier une feuille arbitre de combat direct.
- Vérifier une feuille arbitre sur poule ou tableau.
- Vérifier l'affichage BYE / placeholder.
- Vérifier une feuille résultats vierge.
- Vérifier que l'impression ne modifie aucune donnée.
- Vérifier l'accès avec un utilisateur autorisé puis non autorisé.

## Points à traiter au Lot 9 ou Lot 10

- Lot 9 : feuilles officielles organisation plus spécialisées, log ou avertissement après impression officielle, éventuel verrouillage d'ordre imprimé.
- Lot 10 : saisie sécurisée des résultats en admin à partir des combats existants et rapprochement avec les feuilles papier.
