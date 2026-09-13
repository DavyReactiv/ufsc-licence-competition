# Checklist QA — licence PDF automatique UFSC / FFST

Cette checklist valide le gabarit PDF automatique sans modifier les tables maîtres de `UFSC Gestion` et sans régression sur l'association manuelle historique des PDF.

## Pré-requis

- Plugin `UFSC Licence Competition` actif.
- Plugin maître `UFSC Gestion` actif.
- Dépendances Composer installées (`composer install --no-dev`) afin que Dompdf soit disponible.
- Tables `wp_ufsc_licences`, `wp_ufsc_clubs`, `wp_ufsc_licence_documents` et `wp_ufsc_licence_documents_meta` présentes.
- Utiliser une licence de test et un club de test avant recette sur des données réelles.

## Design / contenu

- Ouvrir `UFSC Licences > Gabarit licence PDF` et vérifier l'aperçu A6 paysage.
- Vérifier l'identité visuelle : fond anthracite/noir, accent rouge, hiérarchie claire, photo à gauche, numéro UFSC très visible.
- Vérifier la présence de `Union Française des Sports de Combat` et `Sous l’égide de la FFST`.
- Vérifier qu'aucune mention `ASPTT`, `FSASPTT` ou `FSGT` n'est affichée sur le gabarit.
- Vérifier qu'aucune adresse, aucun e-mail et aucun téléphone du licencié n'est affiché.
- Vérifier qu'un titulaire sans photo obtient le fallback avec ses initiales.
- Vérifier qu'une photo d'identité stockée comme pièce jointe WordPress est intégrée dans le PDF.

## Déclenchement automatique

### Licence non validée

1. Créer une licence avec numéro UFSC mais statut `brouillon` ou `en_attente`.
2. Vérifier qu'aucun PDF automatique n'est généré.

### Licence validée sans numéro UFSC

1. Utiliser une licence avec statut `valide` mais `numero_licence_ufsc` vide.
2. Vérifier qu'aucun PDF n'est généré.
3. Vérifier que la validation de la licence reste intacte : la génération PDF ne doit jamais bloquer le métier du plugin maître.

### Licence validée avec numéro UFSC

1. Renseigner `numero_licence_ufsc` avec un numéro unique.
2. Passer la licence à `valide` via le parcours de validation habituel.
3. Vérifier la génération d'un fichier PDF dans la médiathèque.
4. Vérifier une ligne `source = UFSC` dans `wp_ufsc_licence_documents` avec l'attachment généré.
5. Vérifier les métadonnées add-on : `pdf_generator`, `pdf_template_version`, `pdf_generated_at`, `pdf_snapshot_hash`.
6. Vérifier que le bouton/téléchargement PDF existant du plugin continue d'utiliser l'association habituelle.

## Compatibilité des statuts

Tester au minimum les valeurs historiques `validée`, `validated`, `approved` et `active` sur une copie de test. Elles doivent être normalisées vers la licence valide lorsque le helper du plugin maître est disponible.

## Numéro de licence

- Quand la colonne canonique `numero_licence_ufsc` existe, elle est la seule référence officielle utilisée pour le nouveau gabarit.
- Si cette colonne existe mais est vide, le générateur ne doit pas reprendre silencieusement un ancien numéro ASPTT/délégataire.
- Sur un ancien schéma ne possédant pas encore `numero_licence_ufsc`, vérifier uniquement la compatibilité de secours avec les anciens champs de numéro UFSC/licence.

## PDF manuel existant

1. Associer manuellement un PDF à une licence via le système historique.
2. Valider/revalider la licence.
3. Vérifier que le PDF manuel n'est pas remplacé automatiquement.
4. Vérifier qu'aucun fichier historique n'est supprimé.

## Régénération

- Régénérer un PDF automatique après correction du nom, de la photo ou du club.
- Vérifier qu'un nouvel attachment est associé.
- Par défaut, l'ancien fichier généré doit être conservé pour traçabilité.
- Vérifier que la licence, le panier, la commande WooCommerce, le paiement, l'affiliation et la saison ne sont jamais modifiés par cette action.

## Saisons / archives

- Tester une licence 2026-2027 et une licence historique.
- Vérifier que la saison affichée correspond à la ligne de licence, et non à la saison active du jour lorsqu'une saison est déjà enregistrée.
- Vérifier qu'une régénération d'une licence historique ne modifie aucune donnée historique du plugin maître.

## Dépendance PDF indisponible

1. Tester sur une copie sans `vendor/autoload.php` / Dompdf.
2. Vérifier que le plugin WordPress se charge normalement.
3. Vérifier l'avertissement admin clair sur les pages PDF.
4. Vérifier qu'une validation de licence reste possible et qu'aucune erreur fatale n'est produite.

## Non-régression globale

- Recherche de licences : OK.
- Association / remplacement / détachement manuel d'un PDF : OK.
- Téléchargement d'un PDF existant : OK.
- Panier licence / renouvellement : inchangé.
- Paiement / commandes WooCommerce : inchangé.
- Affiliation club : inchangée.
- Données des tables maîtres UFSC : aucune écriture ajoutée par le générateur PDF.
