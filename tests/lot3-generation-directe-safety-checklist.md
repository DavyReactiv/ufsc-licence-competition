# Checklist Lot 3 — Sécurité génération directe

Cette checklist est manuelle et non destructive. Ne jamais l'exécuter sur une compétition réelle avec données de production.

## Préconditions

- [ ] Base de données sauvegardée si un environnement de test WordPress est utilisé.
- [ ] Git status propre avant test.
- [ ] Compétition réelle identifiée mais non modifiée.
- [ ] Compétition `[TEST]` disponible ou créée via fixture dédiée.
- [ ] Aucun test ne lance de suppression, régénération ou saisie résultat sur compétition réelle.

## Contrôles génération directe

- [ ] Appel direct sur compétition réelle : refus attendu avec raison `direct_fallback_test_fixture_only`.
- [ ] Appel direct sur compétition `[TEST]` sans `sandbox_generation` : refus attendu.
- [ ] Appel direct sur compétition `[TEST]` sans `direct_generation_context = test_fixture` : refus attendu.
- [ ] Appel direct sur compétition `[TEST]` verrouillée : refus attendu avec raison `generation_locked`.
- [ ] Appel direct sur compétition `[TEST]` avec résultats existants : refus attendu par `CompetitionSafetyService`.
- [ ] Appel fixture Open 150 : fallback direct autorisé uniquement si le brouillon/apply échoue.

## Contrôles workflow principal

- [ ] La prévisualisation de génération reste disponible.
- [ ] La génération de brouillon reste disponible.
- [ ] La validation de brouillon reste protégée par nonce/capability/safety.
- [ ] Aucun combat réel n'est créé pendant ces tests.
- [ ] Aucun combat existant réel n'est modifié.
- [ ] Aucun résultat existant réel n'est modifié.

## Contrôles post-test

- [ ] Les logs d'audit contiennent les blocages attendus.
- [ ] Les fixtures `[TEST]` peuvent être supprimées par le workflow de test existant.
- [ ] Git status ne contient que les fichiers attendus si la checklist a été modifiée.
