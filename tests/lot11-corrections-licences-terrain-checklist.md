# Checklist Lot 11 — Corrections licences terrain

> À exécuter dans WordPress. Ne modifier aucune donnée réelle sauf action volontaire et contrôlée de mise à jour du N° ASPTT.

## Pré-requis

- [ ] Disposer d’un compte admin autorisé.
- [ ] Disposer d’un compte club de test relié à un club connu.
- [ ] Identifier une compétition de test ou une compétition dont l’export peut être consulté sans modification.
- [ ] Confirmer qu’aucune génération de combats, résultat, surface ou impression de combat n’est testée dans ce lot.

## Page modification N° ASPTT

- [ ] Ouvrir une licence existante via l’action **Modifier le N° licence ASPTT**.
- [ ] Vérifier qu’un seul titre **Modifier le N° licence ASPTT** apparaît.
- [ ] Vérifier qu’un seul lien **← Retour aux licences** apparaît.
- [ ] Vérifier qu’un seul formulaire apparaît.
- [ ] Vérifier que la licence affichée correspond à la licence sélectionnée.
- [ ] Vérifier que la valeur ASPTT existante est préremplie.
- [ ] Effectuer une mise à jour volontaire sur une licence de test uniquement.
- [ ] Vérifier que le nonce et la capability refusent l’action pour un utilisateur non autorisé.

## Page admin Licences

- [ ] Ouvrir la page admin **Licences**.
- [ ] Vérifier qu’un seul titre **Licences** apparaît.
- [ ] Vérifier qu’un seul bouton **Exporter CSV (filtres actifs)** apparaît.
- [ ] Vérifier qu’un seul bloc de filtres apparaît.
- [ ] Vérifier qu’une seule table apparaît.
- [ ] Vérifier qu’un seul footer / une seule pagination apparaît.
- [ ] Vérifier que les actions groupées restent disponibles selon les droits.

## Recherche admin licences

- [ ] Rechercher `mallet`.
- [ ] Vérifier que le résultat proche `MALET Ludovic` est retrouvé si présent dans les données.
- [ ] Rechercher `MALET`.
- [ ] Rechercher `Ludovic`.
- [ ] Rechercher le N° ASPTT `410595300`.
- [ ] Rechercher le N° licence `1276`.
- [ ] Rechercher le nom de club `TEAM PAYET`.
- [ ] Vérifier que les filtres actifs sont visibles et ne masquent pas silencieusement les résultats.
- [ ] Réinitialiser les filtres et confirmer que la liste complète revient.

## Filtres club / saison / statut

- [ ] Sélectionner le club `TEAM PAYET MIXED MARTIAL ARTS REUNION ISLAND`.
- [ ] Sélectionner la saison `2026` si disponible.
- [ ] Sélectionner le statut `validé` ou son équivalent affiché.
- [ ] Vérifier que les licences connues du club apparaissent.
- [ ] Retirer le filtre statut et vérifier si le nombre de résultats évolue logiquement.
- [ ] Retirer le filtre saison et vérifier si le nombre de résultats évolue logiquement.
- [ ] Vérifier que le message d’absence de résultats reste compréhensible si aucun résultat n’existe réellement.

## Export CSV admin

- [ ] Appliquer une recherche ou un filtre.
- [ ] Cliquer sur **Exporter CSV (filtres actifs)**.
- [ ] Vérifier que le CSV se télécharge.
- [ ] Vérifier que le CSV respecte les filtres actifs.
- [ ] Vérifier qu’un utilisateur non autorisé ne peut pas exporter.

## Front club — Télécharger CSV des engagés

- [ ] Se connecter avec un compte club.
- [ ] Ouvrir la page front d’une compétition accessible.
- [ ] Vérifier que le bouton **Télécharger CSV des engagés** est visible uniquement si le club y a droit.
- [ ] Cliquer sur **Télécharger CSV des engagés**.
- [ ] Vérifier que le clic déclenche un téléchargement CSV.
- [ ] Vérifier que le clic ne renvoie plus vers la page blog.
- [ ] Vérifier que le CSV concerne la compétition affichée.
- [ ] Vérifier que le CSV ne contient que les engagés du club connecté.
- [ ] Tester avec un utilisateur non connecté : l’accès doit être refusé proprement.
- [ ] Tester avec un `club_id` modifié dans l’URL : l’accès doit être refusé.

## Non-régression

- [ ] Vérifier qu’aucun combat n’est généré.
- [ ] Vérifier qu’aucun résultat n’est modifié.
- [ ] Vérifier qu’aucune surface n’est modifiée.
- [ ] Vérifier qu’aucune impression de combat n’est modifiée.
- [ ] Vérifier qu’aucune donnée réelle n’est modifiée sauf mise à jour ASPTT volontaire.
