<?php
/**
 * FFST staged import assistance. Never creates or overwrites UFSC licences.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class UFSC_LC_FFST_Resolution_Admin {
    const OPTION_KEY = 'ufsc_lc_ffst_club_alias_v1';

    public static function register() {
        add_action( 'admin_post_ufsc_lc_ffst_club_alias', array( __CLASS__, 'save_alias' ) );
        add_action( 'admin_post_ufsc_lc_ffst_manual_match', array( __CLASS__, 'save_match' ) );
        add_action( 'admin_post_ufsc_lc_ffst_export_unmatched', array( __CLASS__, 'export_unmatched' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_css' ) );
        add_action( 'admin_footer', array( __CLASS__, 'draft_prefill' ) );
    }

    public static function enqueue_css() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( UFSC_LC_FFST_Import_Page::PAGE_SLUG !== $page ) { return; }
        wp_enqueue_style( 'ufsc-lc-ffst-resolution', UFSC_LC_URL . 'assets/css/ffst-resolution-center.css', array(), '1.0.0' );
    }

    public static function allowed() {
        return is_user_logged_in() && current_user_can( 'manage_options' ) && UFSC_LC_Capabilities::user_can_import();
    }

    private static function key( $source ) {
        return sanitize_title( remove_accents( trim( (string) $source ) ) );
    }

    public static function club_options() {
        static $cache = null;
        if ( null !== $cache ) { return $cache; }
        global $wpdb;
        $table = $wpdb->prefix . 'ufsc_clubs';
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        $name_col = in_array( 'nom', (array) $columns, true ) ? 'nom' : ( in_array( 'name', (array) $columns, true ) ? 'name' : '' );
        if ( ! $name_col ) { return array(); }
        $cache = $wpdb->get_results( "SELECT id, {$name_col} AS club_name FROM {$table} ORDER BY {$name_col} ASC LIMIT 500" );
        return (array) $cache;
    }

    public static function club_exists( $club_id ) {
        foreach ( self::club_options() as $club ) {
            if ( absint( $club->id ) === absint( $club_id ) ) { return true; }
        }
        return false;
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

    public static function save_match() {
        if ( ! self::allowed() ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
        $batch_id = absint( $_POST['batch_id'] ?? 0 );
        $row_id = absint( $_POST['row_id'] ?? 0 );
        $licence_id = absint( $_POST['licence_id'] ?? 0 );
        $club_id = absint( $_POST['club_id'] ?? 0 );
        check_admin_referer( 'ufsc_ffst_match_' . $batch_id . '_' . $row_id );
        if ( empty( $_POST['identity_confirmed'] ) || ! self::club_exists( $club_id ) ) {
            wp_die( 'Identité ou club non confirmé.', '', array( 'response' => 400 ) );
        }
        $service = new UFSC_LC_FFST_Import_Service();
        $row = self::row( $batch_id, $row_id );
        if ( ! $row ) { wp_die( 'Ligne FFST introuvable.', '', array( 'response' => 404 ) ); }
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
            wp_die( 'Traçabilité indisponible : rapprochement non effectué.', '', array( 'response' => 500 ) );
        }
        $match = $service->manual_stage_match( $batch_id, $row_id, $licence_id, $club_id );
        if ( is_wp_error( $match ) ) {
            if ( null === $previous ) { delete_option( $audit_key ); }
            else { update_option( $audit_key, $previous, false ); }
            wp_die( esc_html( $match->get_error_message() ), '', array( 'response' => 409 ) );
        }
        wp_safe_redirect( self::url( $batch_id, array( 'ffst_resolved' => 'linked' ) ) );
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
        $messages = array( 'rescanned' => absint( $_GET['ffst_found'] ?? 0 ) . ' correspondance(s) supplémentaire(s) trouvée(s). Vérifiez avant application.', 'linked' => 'Correspondance préparée dans le lot : aucune licence UFSC encore modifiée.', 'club_saved' => 'Association du club enregistrée. Relancez la recherche si nécessaire.' );
        $message = isset( $_GET['ffst_resolved'] ) ? sanitize_key( wp_unslash( $_GET['ffst_resolved'] ) ) : '';
        if ( isset( $messages[ $message ] ) ) { echo '<p class="ufsc-ffst-info">' . esc_html( $messages[ $message ] ) . '</p>'; }
        echo '<form class="ufsc-ffst-club-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'ufsc_ffst_club_' . absint( $batch->id ) );
        echo '<input type="hidden" name="action" value="ufsc_lc_ffst_club_alias"><input type="hidden" name="batch_id" value="' . esc_attr( $batch->id ) . '"><input type="hidden" name="row_id" value="' . esc_attr( $source->id ) . '">';
        echo '<div><label>Club indiqué sur le PDF</label><strong>' . esc_html( $source->club_name ) . '</strong></div>';
        echo '<div><label for="ffst-target-club">Club UFSC correspondant</label><select id="ffst-target-club" name="club_id" required><option value="">Rechercher et sélectionner…</option>';
        foreach ( self::club_options() as $club ) {
            echo '<option value="' . esc_attr( $club->id ) . '" ' . selected( $mapped, absint( $club->id ), false ) . '>' . esc_html( $club->club_name ) . '</option>';
        }
        echo '</select></div><button class="button button-primary" type="submit">Associer le club et rechercher</button></form>';
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
        echo '<p>FFST <strong>' . esc_html( $row->ffst_number ) . '</strong> · Naissance ' . esc_html( $row->birthdate ) . ' · Saison ' . esc_html( ( $batch->season_end_year - 1 ) . '–' . $batch->season_end_year ) . '</p>';
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( UFSC_LC_FFST_Import_Page::PAGE_SLUG ) . '"><input type="hidden" name="batch_id" value="' . esc_attr( $batch->id ) . '"><input type="hidden" name="ffst_resolve_row" value="' . esc_attr( $row->id ) . '">';
        echo '<label for="ffst-manual-club">Rechercher dans le club UFSC</label> <select name="ffst_target_club" id="ffst-manual-club" required><option value="">Sélectionner le club…</option>';
        foreach ( self::club_options() as $club ) { echo '<option value="' . esc_attr( $club->id ) . '" ' . selected( $club_id, absint( $club->id ), false ) . '>' . esc_html( $club->club_name ) . '</option>'; }
        echo '</select> <button type="submit" class="button">Chercher les candidats</button></form>';
        if ( $club_id && self::club_exists( $club_id ) ) {
            $service = new UFSC_LC_FFST_Import_Service();
            $candidates = $service->get_manual_candidates( $batch->id, $row->id, $club_id );
            if ( is_wp_error( $candidates ) ) {
                echo '<p class="ufsc-ffst-warning">' . esc_html( $candidates->get_error_message() ) . '</p>';
            } elseif ( ! $candidates ) {
                echo '<p class="ufsc-ffst-warning">Aucune fiche avec cette date de naissance dans ce club pour la saison choisie. Vérifiez le club ou préparez un brouillon.</p>';
            } else {
                echo '<div class="ufsc-ffst-candidate-table"><table class="widefat striped"><thead><tr><th>UFSC</th><th>Nom et prénom</th><th>Naissance</th><th>Contrôle</th><th>Action</th></tr></thead><tbody>';
                foreach ( $candidates as $person ) {
                    echo '<tr><td>#' . esc_html( $person->id ) . '</td><td>' . esc_html( $person->first_name . ' ' . $person->last_name ) . '</td><td>' . esc_html( $person->date_naissance ) . '</td><td>' . ( $person->eligible ? 'Identité concordante' : 'Identité différente' ) . '</td><td>';
                    if ( $person->eligible ) {
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
