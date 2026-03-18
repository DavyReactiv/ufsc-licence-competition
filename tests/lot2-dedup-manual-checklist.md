# Lot 2 — Intégrité des données & anti-doublon (checklist manuelle)

## 1) Audit initial (sans modification)
- Exécuter `wp ufsc competitions entries-dedup-audit`.
- Vérifier `duplicated_groups` et `extra_rows`.
- Contrôler la clé détectée (`key.licensee_column`) selon le schéma réel.

## 2) Simulation de résolution (dry-run)
- Exécuter `wp ufsc competitions entries-dedup-resolve`.
- Vérifier que chaque groupe propose :
  - un `keep_id` conservé,
  - des `target_ids` à traiter,
  - la stratégie (`soft_delete_newer` si `deleted_at` existe).

## 3) Nettoyage assisté (application)
- Sauvegarder la base.
- Exécuter `wp ufsc competitions entries-dedup-resolve --apply`.
- Vérifier l’option `ufsc_competitions_entries_dedup_last_resolution`.

## 4) Vérification post-nettoyage
- Relancer `wp ufsc competitions entries-dedup-audit`.
- Attendu : `duplicated_groups = 0`.
- Vérifier la présence de l’index unique `uniq_competition_licensee`.

## 5) Validation fonctionnelle anti-régression
- Front club : tenter une double inscription du même licencié sur la même compétition.
- Attendu : message « déjà inscrit » sans erreur fatale.
- Admin : idem via écran inscriptions.
- Attendu : blocage doublon (notice `duplicate`).

## 6) Rollback
- Exécuter `wp ufsc competitions entries-dedup-rollback`.
- Vérifier que l’index `uniq_competition_licensee` a bien disparu.

## 7) Journalisation
- Activer `WP_DEBUG` pour tracer les cas de blocage index/doublons.
- Vérifier que les logs spécifiques n’apparaissent pas lorsque `WP_DEBUG` est à `false`.
