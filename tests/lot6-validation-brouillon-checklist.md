# Checklist Lot 6 — Validation contrôlée du brouillon

> À exécuter uniquement sur une compétition préfixée `[TEST]`. Ne jamais utiliser une compétition réelle.

## Préparation

- [ ] Vérifier que la compétition cible commence par `[TEST]`.
- [ ] Vérifier que la base de données est sauvegardée.
- [ ] Vérifier que l’utilisateur connecté possède les droits admin requis.
- [ ] Vérifier qu’aucun résultat réel ne sera modifié.

## Scénarios de validation

| Cas | Action | Résultat attendu en preview | Résultat attendu à la validation | Blocage attendu |
|---|---|---|---|---|
| Brouillon sain | Générer puis valider sans modifier les données | Combats et groupes cohérents | Combats appliqués en `append`, snapshot créé, génération verrouillée | Non |
| Inscription modifiée | Générer un brouillon puis modifier une inscription `[TEST]` | Ancien brouillon visible | Message “inscriptions changées”, aucune insertion | Oui |
| Pesée modifiée | Générer un brouillon puis modifier une pesée `[TEST]` | Ancien brouillon visible | Message “pesées changées”, aucune insertion | Oui |
| Catégorie modifiée | Générer un brouillon puis modifier une catégorie `[TEST]` | Ancien brouillon visible | Message “catégories changées”, aucune insertion | Oui |
| Surface modifiée | Générer un brouillon puis modifier le nombre/nom des surfaces | Ancien brouillon visible | Message “surfaces changées”, aucune insertion | Oui |
| Paramètre modifié | Générer un brouillon puis modifier les paramètres de génération | Ancien brouillon visible | Message “paramètres changés”, aucune insertion | Oui |
| `group_key` manquant | Simuler un brouillon de test sans `group_key` | Diagnostic incomplet | Message “clé de groupe” | Oui |
| `category_id` manquant | Simuler un brouillon de test sans `category_id` | Diagnostic incomplet | Message “category_id” | Oui |
| Combat réel incomplet | Simuler un combat sans red ou blue | Diagnostic incomplet | Message “combat incomplet” | Oui |
| BYE incohérent | Simuler un BYE sans gagnant cohérent | Diagnostic BYE | Message BYE incohérent | Oui |
| Compétition verrouillée | Valider après verrou de génération | Brouillon visible | Message “compétition verrouillée” | Oui |
| Combats existants | Ajouter des combats `[TEST]` puis valider un ancien brouillon | Brouillon visible | Message “combats existent déjà” | Oui |
| Résultats existants | Utiliser une compétition `[TEST]` avec résultat | Brouillon visible | Message “résultats existent déjà” | Oui |
| Mode `replace` | Poster `apply_mode=replace` | Sans objet | Message refusant `replace` | Oui |
| Erreur d’insertion simulée | Provoquer une erreur contrôlée sur `[TEST]` | Brouillon visible | Message non technique, rollback ciblé des inserts de l’opération | Oui |

## Vérifications post-test

- [ ] Aucun combat d’une compétition réelle n’a été créé.
- [ ] Aucun combat existant avant validation n’a été supprimé.
- [ ] Aucun résultat existant n’a été modifié.
- [ ] Le snapshot existe pour une validation saine.
- [ ] Le rollback éventuel ne concerne que les IDs insérés pendant l’opération.
- [ ] Les messages admin sont compréhensibles pour un organisateur non développeur.
