# Checklist i18n

- [x] Text domain unique et cohérent (`ufsc-licence-competition`).
- [x] Chargement des traductions via `load_plugin_textdomain` sur `init`.
- [x] Chaînes visibles par l’utilisateur enveloppées dans `__()`, `esc_html__()` ou `esc_attr__()`.
- [x] Chaînes JavaScript exposées via `wp_localize_script`.
- [x] Fichier `.pot` généré dans `languages/`.
