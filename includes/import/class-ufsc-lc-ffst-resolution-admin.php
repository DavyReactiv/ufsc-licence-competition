<?php
/**
 * FFST staged import assistance. Never creates or overwrites UFSC licences.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class UFSC_LC_FFST_Resolution_Admin {
    const OPTION_KEY = 'ufsc_lc_ffst_club_alias_v1';

    public static function register() {
        add_action( 'admin_post_ufsc_lc_ffst_club_alias', array( __CLASS__, 'save_alias' ) );
        add_action( 'admin_post_ufsc_lc_ffst_remove_club_alias', array( __CLASS__, 'remove_alias' ) );
        add_action( 'admin_post_ufsc_lc_ffst_manual_match', array( __CLASS__, 'save_match' ) );
        add_action( 'admin_post_ufsc_lc_ffst_export_unmatched', array( __CLASS__, 'export_unmatched' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_css' ) );
        add_action( 'wp_ajax_ufsc_lc_ffst_club_search', array( __CLASS__, 'ajax_club_search' ) );
        add_action( 'admin_footer', array( __CLASS__, 'draft_prefill' ) );
    }

    public static function enqueue_css() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( UFSC_LC_FFST_Import_Page::PAGE_SLUG !== $page ) { return; }
        wp_enqueue_style( 'ufsc-lc-ffst-resolution', UFSC_LC_URL . 'assets/css/ffst-resolution-center.css', array(), '1.1.0' );
        wp_enqueue_script( 'ufsc-lc-ffst-club-search', UFSC_LC_URL . 'assets/js/ffst-club-search.js', array(), '1.1.0', true );
        wp_localize_script( 'ufsc-lc-ffst-club-search', 'UFSCFFSTClubSearch', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ufsc_ffst_search_clubs' ),
        ) );
    }

    public static function allowed() {
        return is_user_logged_in() && current_user_can( 'manage_options' ) && UFSC_LC_Capabilities::user_can_import();
    }

    private static function key( $source ) {
        return sanitize_title( remove_accents( trim( (string) $source ) ) );
    }

    private static function name_column() {
        static $name_col = null;
        if ( null !== $name_col ) { return $name_col; }
        global $wpdb;
        $table = $wpdb->prefix . 'ufsc_clubs';
        $columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        $name_col = in_array( 'nom', $columns, true ) ? 'nom' : ( in_array( 'name', $columns, true ) ? 'name' : '' );
        return $name_col;
    }

    public static function club_options() {
        static $cache = null;
        if ( null !== $cache ) { return $cache; }
        global $wpdb;
        $table = $wpdb->prefix . 'ufsc_clubs';
        $name_col = self::name_column();
        if ( ! $name_col ) { return array(); }
        // Keep existing no-JS fallback and first-500 ordering unchanged.
        // Remote search below allows accessing EVERY club beyond this list.
        $cache = $wpdb->get_results( "SELECT id, {$name_col} AS club_name FROM {$table} ORDER BY {$name_col} ASC LIMIT 500" );
        return (array) $cache;
    }

    public static function club_by_id( $club_id ) {
        global $wpdb;
        $club_id = absint( $club_id );
        $name_col = self::name_column();
        if ( ! $club_id || ! $name_col ) { return null; }
        $table = $wpdb->prefix . 'ufsc_clubs';
        // Unlike the previous first-500 check, look up the canonical ID
        // directly so remote-search results beyond 500 remain selectable.
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, {$name_col} AS club_name FROM {$table} WHERE id=%d LIMIT 1",
            $club_id
        ) );
    }

    public static function club_exists( $club_id ) {
        return (bool) self::club_by_id( $club_id );
    }

    public static function ajax_club_search() {
        if ( ! self::allowed() ) {
            wp_send_json_error( array( 'message' => 'Accès réservé aux administrateurs UFSC.' ), 403 );
        }
        check_ajax_referer( 'ufsc_ffst_search_clubs', 'nonce' );
        $query = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
        $query = trim( $query );
        if ( strlen( $query ) < 2 ) {
            wp_send_json_success( array( 'clubs' => array(), 'message' => 'Saisissez au moins deux caractères.' ) );
        }
        $query = substr( $query, 0, 80 );
        $col = self::name_column();
        if ( ! $col ) {
            wp_send_json_error( array( 'message' => 'La table UFSC Clubs est indisponible.' ), 503 );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'ufsc_clubs';
        $like = '%' . $wpdb->esc_like( $query ) . '%';
        $found = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, {$col} AS club_name FROM {$table} WHERE {$col} LIKE %s ORDER BY {$col} ASC LIMIT 30",
            $like
        ) );
        $clubs = array();
        foreach ( (array) $found as $club ) {
            $clubs[] = array( 'id' => absint( $club->id ), 'name' => (string) $club->club_name );
        }
        wp_send_json_success( array( 'clubs' => $clubs, 'message' => $clubs ? '' : 'Aucun club UFSC correspondant.' ) );
    }

    private static function render_club_options( $selected_id ) {
        $selected_id = absint( $selected_id );
        $found = false;
        foreach ( self::club_options() as $club ) {
            $id = absint( $club->id );
            if ( $id === $selected_id ) { $found = true; }
            echo '<option value="' . esc_attr( $id ) . '"' . selected( $selected_id, $id, false ) . '>' . esc_html( $club->club_name ) . '</option>';
        }
        if ( $selected_id && ! $found ) {
            // Retain aliases targeting clubs beyond the initial 500.
            $selected = self::club_by_id( $selected_id );
            if ( $selected ) {
                echo '<option selected value="' . esc_attr( $selected_id ) . '">' . esc_html( $selected->club_name ) . '</option>';
            }
        }
    }

    public static function target_club_id( $name ) {
        $map = get_option( self::OPTION_KEY, array() );
        $id = absint( $map[ self::key( $name ) ]['club_id'] ?? 0 );
        return $id && self::club_exists( $id ) ? $id : 0;
    }

    private static function url( $batch, $extra = array() ) {
        return add_query_arg( array_merge( array( 'page' => UFSC_LC_FFST_Import_Page::PAGE_SLUG, 'batch_id' => absint( $batch ) ), $extra ), admin_url( 'admin.php' ) );
    }

    private static function row( $batch, $row_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ufsc_lc_ffst_import_rows';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id=%d AND id=%d LIMIT 1", absint( $batch ), absint( $row_id ) ) );
    }

    public static function save_alias() {
        if ( ! self::allowed() ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
        $batch_id = absint( $_POST['batch_id'] ?? 0 );
        $row_id = absint( $_POST['row_id'] ?? 0 );
        $club_id = absint( $_POST['club_id'] ?? 0 );
        check_admin_referer( 'ufsc_ffst_club_' . $batch_id );
        $service = new UFSC_LC_FFST_Import_Service();
        $row = $service->get_batch( $batch_id ) ? self::row( $batch_id, $row_id ) : null;
        if ( ! $row || ! self::club_exists( $club_id ) || ! trim( (string) $row->club_name ) ) {
            wp_die( 'Lot, club ou nom source invalide.', '', array( 'response' => 400 ) );
        }
        $key = self::key( $row->club_name );
        if ( ! $key ) { wp_die( 'Nom FFST inexploitable.', '', array( 'response' => 400 ) ); }
        $map = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $map ) ) { $map = array(); }
        $map[ $key ] = array( 'club_id' => $club_id, 'source' => (string) $row->club_name, 'actor' => get_current_user_id(), 'date' => current_time( 'mysql' ) );
        update_option( self::OPTION_KEY, $map, false );
        $verified = get_option( self::OPTION_KEY, array() );
        if ( absint( $verified[ $key ]['club_id'] ?? 0 ) !== $club_id ) { wp_die( 'Association non enregistrée.', '', array( 'response' => 500 ) ); }
        // Reanalysis only touches the staged FFST import.
        $res = $service->reanalyse_not_found( $batch_id );
        wp_safe_redirect( self::url( $batch_id, array( 'ffst_resolved' => is_wp_error( $res ) ? 'club_saved' : 'rescanned', 'ffst_found' => is_wp_error( $res ) ? 0 : absint( $res ) ) ) );
        exit;
    }

    /**
     * An incorrect club alias can always be removed without touching any
     * source PDF rows or UFSC Gestion records.
     */
    public static function remove_alias() {
        if ( ! self::allowed() ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
        $batch_id = absint( $_POST['batch_id'] ?? 0 );
        $row_id = absint( $_POST['row_id'] ?? 0 );
        check_admin_referer( 'ufsc_ffst_remove_club_' . $batch_id . '_' . $row_id );
        $service = new UFSC_LC_FFST_Import_Service();
        $row = $service->get_batch( $batch_id ) ? self::row( $batch_id, $row_id ) : null;
        if ( ! $row || ! trim( (string) $row->club_name ) ) {
            wp_die( 'Ligne ou lot FFST introuvable.', '', array( 'response' => 404 ) );
        }
        $key = self::key( $row->club_name );
        $map = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $map ) ) { $map = array(); }
        if ( isset( $map[ $key ] ) ) {
            unset( $map[ $key ] );
            update_option( self::OPTION_KEY, $map, false );
        }
        $current = get_option( self::OPTION_KEY, array() );
        if ( isset( $current[ $key ] ) ) { wp_die( 'Impossible de retirer la correspondance.', '', array( 'response' => 500 ) ); }
        wp_safe_redirect( self::url( $batch_id, array( 'ffst_club_row' => $row_id, 'ffst_resolved' => 'alias_removed' ) ) . '#ufsc-ffst-club-tools' );
        exit;
    }

    private static function match_error( $batch_id, $row_id, $message ) {
        $key = 'ufsc_ffst_match_error_' . get_current_user_id() . '_' . absint( $batch_id ) . '_' . absint( $row_id );
        set_transient( $key, sanitize_text_field( (string) $message ), 300 );
        wp_safe_redirect( self::url( $batch_id, array( 'ffst_resolve_row' => $row_id, 'ffst_resolved' => 'error' ) ) . '#ffst-resolve-panel' );
        exit;
    }

    public static function save_match() {
        if ( ! self::allowed() ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
        $batch_id = absint( $_POST['batch_id'] ?? 0 );
        $row_id = absint( $_POST['row_id'] ?? 0 );
        $licence_id = absint( $_POST['licence_id'] ?? 0 );
        $club_id = absint( $_POST['club_id'] ?? 0 );
        check_admin_referer( 'ufsc_ffst_match_' . $batch_id . '_' . $row_id );
        if ( empty( $_POST['identity_confirmed'] ) || ! self::club_exists( $club_id ) ) {
            self::match_error( $batch_id, $row_id, 'Confirmez la vérification de l’identité et choisissez le club avant de poursuivre.' );
        }
        $service = new UFSC_LC_FFST_Import_Service();
        $row = self::row( $batch_id, $row_id );
        if ( ! $row ) { self::match_error( $batch_id, $row_id, 'Ligne FFST introuvable : rechargez le lot.' ); }
        $audit_key = 'ufsc_ffst_manual_link_' . $batch_id . '_' . $row_id;
        $previous = get_option( $audit_key, null );
        $audit = array(
            'actor' => get_current_user_id(),
            'date' => current_time( 'mysql' ),
            'batch' => $batch_id,
            'licence' => $licence_id,
            'ffst' => (string) $row->ffst_number,
        );
        // Persist and verify the operator before changing even staging state.
        update_option( $audit_key, $audit, false );
        if ( get_option( $audit_key, null ) !== $audit ) {
            self::match_error( $batch_id, $row_id, 'Traçabilité indisponible : aucun rapprochement effectué.' );
        }
        $match = $service->manual_stage_match( $batch_id, $row_id, $licence_id, $club_id );
        if ( is_wp_error( $match ) ) {
            if ( null === $previous ) { delete_option( $audit_key ); }
            else { update_option( $audit_key, $previous, false ); }
            self::match_error( $batch_id, $row_id, $match->get_error_message() );
        }
        // Continue to the next unresolved case without making another manual
        // selection or changing any other licence.
        $next = $service->get_next_unresolved_row( $batch_id, $row_id );
        $args = array( 'ffst_resolved' => 'linked' );
        if ( $next ) { $args['ffst_resolve_row'] = absint( $next->id ); }
        wp_safe_redirect( self::url( $batch_id, $args ) . ( $next ? '#ffst-resolve-panel' : '#ufsc-ffst-club-tools' ) );
        exit;
    }

    public static function render_tools( $batch, $rows ) {
        if ( ! self::allowed() || ! $batch || ! $rows ) { return; }
        $source = null;
        $requested_row = absint( $_GET['ffst_club_row'] ?? 0 );
        if ( $requested_row ) {
            $requested = self::row( absint( $batch->id ), $requested_row );
            if ( $requested && trim( (string) $requested->club_name ) ) { $source = $requested; }
        }
        if ( ! $source ) {
            foreach ( $rows as $row ) {
                if ( trim( (string) $row->club_name ) && 'not_found' === $row->match_status ) { $source = $row; break; }
            }
        }
        if ( ! $source ) {
            foreach ( $rows as $row ) { if ( trim( (string) $row->club_name ) ) { $source = $row; break; } }
        }
        if ( ! $source ) { return; }
        $mapped = self::target_club_id( $source->club_name );
        echo '<section class="ufsc-ffst-resolve-tools" id="ufsc-ffst-club-tools"><div class="ufsc-ffst-tool-head"><div><h3>Résoudre les licences introuvables</h3><p>Associez le nom FFST au club UFSC pour reprendre la recherche dans le bon club.</p></div>';
        echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ufsc_lc_ffst_export_unmatched&batch_id=' . absint( $batch->id ) ), 'ufsc_ffst_export_' . absint( $batch->id ) ) ) . '">Exporter les introuvables (CSV)</a></div>';
        $messages = array(
            'rescanned' => absint( $_GET['ffst_found'] ?? 0 ) . ' correspondance(s) supplémentaire(s) trouvée(s). Vérifiez avant application.',
            'linked' => 'Correspondance préparée dans le lot : aucune licence UFSC encore modifiée.',
            'club_saved' => 'Association du club enregistrée. Relancez la recherche si nécessaire.',
            'alias_removed' => 'Correspondance de club retirée. Les licences et numéros FFST existants sont inchangés.',
        );
        $message = isset( $_GET['ffst_resolved'] ) ? sanitize_key( wp_unslash( $_GET['ffst_resolved'] ) ) : '';
        if ( isset( $messages[ $message ] ) ) { echo '<p class="ufsc-ffst-info">' . esc_html( $messages[ $message ] ) . '</p>'; }
        echo '<form class="ufsc-ffst-club-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'ufsc_ffst_club_' . absint( $batch->id ) );
        echo '<input type="hidden" name="action" value="ufsc_lc_ffst_club_alias"><input type="hidden" name="batch_id" value="' . esc_attr( $batch->id ) . '"><input type="hidden" name="row_id" value="' . esc_attr( $source->id ) . '">';
        echo '<div><label>Club indiqué sur le PDF</label><strong>' . esc_html( $source->club_name ) . '</strong></div>';
        echo '<div class="ufsc-ffst-club-search"><label for="ffst-target-club">Club UFSC correspondant</label>';
        echo '<input type="search" class="ufsc-ffst-remote-club-search" aria-label="Rechercher un club UFSC" placeholder="Rechercher par nom de club (tous les clubs)" autocomplete="off">';
        echo '<span class="ufsc-ffst-club-feedback" role="status" aria-live="polite"></span>';
        echo '<select id="ffst-target-club" name="club_id" required><option value="">Rechercher et sélectionner…</option>';
        self::render_club_options( $mapped );
        echo '</select></div><button class="button button-primary" type="submit">Associer le club et rechercher</button></form>';
        if ( $mapped ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ufsc-ffst-unmap-form">';
            wp_nonce_field( 'ufsc_ffst_remove_club_' . absint( $batch->id ) . '_' . absint( $source->id ) );
            echo '<input type="hidden" name="action" value="ufsc_lc_ffst_remove_club_alias"><input type="hidden" name="batch_id" value="' . esc_attr( $batch->id ) . '"><input type="hidden" name="row_id" value="' . esc_attr( $source->id ) . '">';
            echo '<button type="submit" class="button button-secondary" onclick="return confirm(\'Retirer cette association de club pour les prochains rapprochements ? Les licences UFSC déjà rapprochées ne seront pas modifiées.\')">Retirer cette association de club</button></form>';
        }
        echo '<p class="description">Association mémorisée pour les prochains imports. Aucune identité, date de naissance ou licence n’est écrasée.</p></section>';
    }

    public static function render_row_link( $batch, $row ) {
        if ( ! self::allowed() || ! in_array( $row->match_status, array( 'not_found', 'ambiguous' ), true ) ) { return; }
        echo '<div class="ufsc-ffst-inline-actions"><a class="button button-small" href="' . esc_url( self::url( $batch->id, array( 'ffst_resolve_row' => absint( $row->id ) ) ) . '#ffst-resolve-panel' ) . '">Rechercher / associer</a> ';
        echo '<a class="button button-small" href="' . esc_url( self::url( $batch->id, array( 'ffst_club_row' => absint( $row->id ) ) ) . '#ufsc-ffst-club-tools' ) . '">Associer ce club</a></div>';
    }

    public static function render_panel( $batch ) {
        if ( ! self::allowed() || ! $batch ) { return; }
        $row_id = absint( $_GET['ffst_resolve_row'] ?? 0 );
        $row = $row_id ? self::row( $batch->id, $row_id ) : null;
        if ( ! $row || ! in_array( $row->match_status, array( 'not_found', 'ambiguous' ), true ) ) { return; }
        $club_id = absint( $_GET['ffst_target_club'] ?? self::target_club_id( $row->club_name ) );
        echo '<section class="ufsc-ffst-resolve-panel" id="ffst-resolve-panel"><h3>Rapprochement assisté : ' . esc_html( $row->first_name . ' ' . $row->last_name ) . '</h3>';
        $service = new UFSC_LC_FFST_Import_Service();
        $next_unresolved = $service->get_next_unresolved_row( $batch->id, $row_id );
        if ( $next_unresolved && absint( $next_unresolved->id ) !== $row_id ) {
            echo '<p><a class="button button-secondary" href="' . esc_url( self::url( $batch->id, array( 'ffst_resolve_row' => absint( $next_unresolved->id ) ) ) . '#ffst-resolve-panel' ) . '">Passer au prochain dossier introuvable ou ambigu</a></p>';
        }
        $error_key = 'ufsc_ffst_match_error_' . get_current_user_id() . '_' . absint( $batch->id ) . '_' . $row_id;
        $error = get_transient( $error_key );
        if ( $error ) {
            echo '<div class="ufsc-ffst-warning" role="alert"><strong>Association non effectuée :</strong> ' . esc_html( $error ) . '</div>';
            delete_transient( $error_key );
        }
        echo '<p>FFST <strong>' . esc_html( $row->ffst_number ) . '</strong> · Naissance ' . esc_html( $row->birthdate ) . ' · Saison ' . esc_html( ( $batch->season_end_year - 1 ) . '–' . $batch->season_end_year ) . '</p>';
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( UFSC_LC_FFST_Import_Page::PAGE_SLUG ) . '"><input type="hidden" name="batch_id" value="' . esc_attr( $batch->id ) . '"><input type="hidden" name="ffst_resolve_row" value="' . esc_attr( $row->id ) . '">';
        echo '<div class="ufsc-ffst-club-search"><label for="ffst-manual-club">Rechercher dans le club UFSC</label>';
        echo '<input type="search" class="ufsc-ffst-remote-club-search" aria-label="Rechercher un club pour le rapprochement" placeholder="Rechercher tous les clubs…" autocomplete="off">';
        echo '<span class="ufsc-ffst-club-feedback" role="status" aria-live="polite"></span>';
        echo '<select name="ffst_target_club" id="ffst-manual-club" required><option value="">Sélectionner le club…</option>';
        self::render_club_options( $club_id );
        echo '</select></div> <button type="submit" class="button">Chercher les candidats</button></form>';
        if ( $club_id && self::club_exists( $club_id ) ) {
            $candidates = $service->get_manual_candidates( $batch->id, $row->id, $club_id );
            if ( is_wp_error( $candidates ) ) {
                echo '<p class="ufsc-ffst-warning">' . esc_html( $candidates->get_error_message() ) . '</p>';
            } elseif ( ! $candidates ) {
                echo '<p class="ufsc-ffst-warning">Aucune fiche avec cette date de naissance dans ce club pour la saison choisie. Vérifiez le club ou préparez un brouillon.</p>';
            } else {
                $exact_count = count( array_filter( $candidates, static function ( $candidate ) {
                    return ! empty( $candidate->eligible );
                } ) );
                if ( $exact_count > 1 ) {
                    echo '<div class="ufsc-ffst-warning" role="alert"><strong>Doublon d’identité :</strong> plusieurs licences UFSC sont identiques pour ce club et cette saison. Corrigez le doublon avant toute association ; aucun numéro ne sera écrit.</div>';
                } elseif ( 0 === $exact_count ) {
                    echo '<div class="ufsc-ffst-warning">Date de naissance commune, mais aucun nom et prénom exactement concordants. Ne forcez pas une identité différente : vérifiez ou corrigez la fiche UFSC.</div>';
                }
                echo '<div class="ufsc-ffst-candidate-table"><table class="widefat striped"><thead><tr><th>UFSC</th><th>Nom et prénom</th><th>Naissance</th><th>Contrôle</th><th>Action</th></tr></thead><tbody>';
                foreach ( $candidates as $person ) {
                    echo '<tr><td>#' . esc_html( $person->id ) . '</td><td>' . esc_html( $person->first_name . ' ' . $person->last_name ) . '</td><td>' . esc_html( $person->date_naissance ) . '</td><td>' . ( $person->eligible ? 'Identité concordante' : 'Identité différente' ) . '</td><td>';
                    if ( $person->eligible && 1 === $exact_count ) {
                        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                        wp_nonce_field( 'ufsc_ffst_match_' . absint( $batch->id ) . '_' . absint( $row->id ) );
                        echo '<input type="hidden" name="action" value="ufsc_lc_ffst_manual_match"><input type="hidden" name="batch_id" value="' . esc_attr( $batch->id ) . '"><input type="hidden" name="row_id" value="' . esc_attr( $row->id ) . '"><input type="hidden" name="licence_id" value="' . esc_attr( $person->id ) . '"><input type="hidden" name="club_id" value="' . esc_attr( $club_id ) . '"><label><input type="checkbox" name="identity_confirmed" value="1" required> Identité et club vérifiés</label> <button type="submit" class="button button-primary">Associer</button></form>';
                    } else { echo 'Corriger la fiche UFSC avant association'; }
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            $draft_url = add_query_arg( array( 'page' => 'ufsc_lc_licences', 'action' => 'new', 'ffst_draft_batch' => absint( $batch->id ), 'ffst_draft_row' => absint( $row->id ), 'ffst_target_club' => $club_id, 'ffst_draft_nonce' => wp_create_nonce( 'ffst_draft_' . absint( $batch->id ) . '_' . absint( $row->id ) ) ), admin_url( 'admin.php' ) );
            echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( $draft_url ) . '">Préparer une nouvelle licence brouillon</a></p>';
            echo '<p class="description">Ouvre le formulaire officiel UFSC Gestion prérempli. Aucune licence n’est créée avant vérification et enregistrement par l’administrateur.</p>';
        }
        echo '<p><a href="' . esc_url( self::url( $batch->id ) ) . '">Fermer cette recherche</a></p></section>';
    }
    public static function escape_csv_cell( $value ) {
        $value = (string) $value;
        // Prevent formula injection when CSV is opened with spreadsheet apps.
        return preg_match( '/^[\s]*[=+\-@]/u', $value ) ? "'" . $value : $value;
    }

    public static function export_unmatched() {
        if ( ! self::allowed() ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
        $batch_id = absint( $_GET['batch_id'] ?? 0 );
        check_admin_referer( 'ufsc_ffst_export_' . $batch_id );
        $service = new UFSC_LC_FFST_Import_Service();
        if ( ! $service->get_batch( $batch_id ) ) {
            wp_die( 'Lot introuvable.', '', array( 'response' => 404 ) );
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="ufsc-ffst-introuvables-' . $batch_id . '.csv"' );
        $stream = fopen( 'php://output', 'w' );
        fputcsv( $stream, array( 'Lot', 'Numero FFST', 'Nom', 'Prenom', 'Naissance', 'Club FFST', 'Etat', 'Controle' ), ';' );
        global $wpdb;
        $table = $wpdb->prefix . 'ufsc_lc_ffst_import_rows';
        $offset = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE batch_id=%d AND match_status IN (%s,%s,%s) ORDER BY row_index ASC LIMIT 250 OFFSET %d",
                $batch_id, UFSC_LC_FFST_Import_Service::NOT_FOUND,
                UFSC_LC_FFST_Import_Service::AMBIGUOUS,
                UFSC_LC_FFST_Import_Service::CONFLICT, $offset
            ) );
            foreach ( $rows as $row ) {
                $fields = array( $batch_id, $row->ffst_number, $row->last_name, $row->first_name, $row->birthdate, $row->club_name, $row->match_status, $row->match_message );
                fputcsv( $stream, array_map( array( __CLASS__, 'escape_csv_cell' ), $fields ), ';' );
            }
            $offset += count( $rows );
        } while ( count( $rows ) === 250 && $offset < 20000 );
        fclose( $stream );
        exit;
    }

    /**
     * Pre-fill existing UFSC Gestion native "new licence" screen. A separate
     * user confirmation and its own validation are still required to create.
     */
    public static function draft_prefill() {
        if ( ! self::allowed() ) { return; }
        $page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
        $action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );
        if ( 'ufsc_lc_licences' !== $page || 'new' !== $action ) { return; }
        $batch_id = absint( $_GET['ffst_draft_batch'] ?? 0 );
        $row_id = absint( $_GET['ffst_draft_row'] ?? 0 );
        $club_id = absint( $_GET['ffst_target_club'] ?? 0 );
        $nonce = sanitize_text_field( wp_unslash( $_GET['ffst_draft_nonce'] ?? '' ) );
        if ( ! $batch_id || ! $row_id || ! self::club_exists( $club_id )
            || ! wp_verify_nonce( $nonce, 'ffst_draft_' . $batch_id . '_' . $row_id ) ) { return; }
        $service = new UFSC_LC_FFST_Import_Service();
        $batch = $service->get_batch( $batch_id );
        $row = $batch ? self::row( $batch_id, $row_id ) : null;
        if ( ! $row || ! in_array( $row->match_status, array( 'not_found', 'ambiguous' ), true ) ) { return; }
        $data = array(
            'club_id' => $club_id,
            'nom' => (string) $row->last_name,
            'prenom' => (string) $row->first_name,
            'date_naissance' => (string) $row->birthdate,
            'statut' => 'brouillon',
        );
        echo '<script>(function(){var fields=' . wp_json_encode( $data ) . ';';
        echo 'Object.keys(fields).forEach(function(k){var el=document.querySelector("[name="+JSON.stringify(k)+"]");';
        echo 'if(!el)return;if(el.value && k!=="club_id" && k!=="statut")return;';
        echo 'el.value=fields[k];el.dispatchEvent(new Event("change",{bubbles:true}));});';
        echo 'var root=document.querySelector(".wrap");if(root){var note=document.createElement("div");note.className="notice notice-warning";';
        echo 'note.textContent="Proposition basée sur le PDF FFST : vérifiez soigneusement la fiche avant toute création en brouillon. Aucun dossier, paiement ni contrôle réglementaire n’a été validé automatiquement.";root.insertBefore(note,root.firstChild);}})();</script>';
    }

}
