<?php
namespace UFSC\Competitions\Admin\Pages;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Phase 1: downloadable CSV templates and read-only validation.
 * No writes to competitions, entries, fights or licences.
 */
final class Event_Plateau_Import_Page {
    private const SLUG = 'ufsc-competitions-event-plateau-import';
    private const LIMIT = 2097152;
    private const MAX_ROWS = 500;
    private const FIELDS = array(
        'event' => array( 'name', 'discipline', 'type', 'season', 'event_start_datetime', 'event_end_datetime', 'venue_name', 'venue_address1', 'venue_postcode', 'venue_city', 'organizer_club_name', 'contact_email', 'contact_phone' ),
        'plateau' => array( 'fight_no', 'surface_name', 'discipline', 'category', 'weight_class', 'red_lastname', 'red_firstname', 'red_license', 'red_club', 'blue_lastname', 'blue_firstname', 'blue_license', 'blue_club', 'rounds', 'round_duration' ),
    );

    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
        add_action( 'admin_post_ufsc_event_plateau_template', array( __CLASS__, 'template' ) );
        add_action( 'admin_post_ufsc_event_plateau_preview', array( __CLASS__, 'preview' ) );
    }

    private static function allow(): void {
        if ( ! class_exists( '\\UFSC\\Competitions\\Capabilities' ) || ! current_user_can( \UFSC\Competitions\Capabilities::get_edit_capability() ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
        }
    }

    public static function menu(): void {
        add_submenu_page( 'ufsc-competitions', 'Import événement / plateau', 'Import événement / plateau', \UFSC\Competitions\Capabilities::get_edit_capability(), self::SLUG, array( __CLASS__, 'render' ) );
    }

    public static function render(): void {
        self::allow();
        $report = get_transient( 'ufsc_event_plateau_preview_' . get_current_user_id() );
        delete_transient( 'ufsc_event_plateau_preview_' . get_current_user_id() );
        echo '<div class="wrap"><h1>Importer un événement ou un plateau</h1>';
        echo '<p>Phase de contrôle : prévisualisation uniquement. Aucune création ni modification de données.</p>';
        foreach ( self::FIELDS as $kind => $columns ) {
            $link = wp_nonce_url( admin_url( 'admin-post.php?action=ufsc_event_plateau_template&kind=' . $kind ), 'ufsc_event_plateau_template_' . $kind );
            echo '<p><a class="button" href="' . esc_url( $link ) . '">Télécharger le modèle CSV ' . esc_html( $kind ) . '</a></p>';
        }
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="ufsc_event_plateau_preview">';
        wp_nonce_field( 'ufsc_event_plateau_preview' );
        echo '<p><label>Type de fichier <select name="kind"><option value="event">Événement</option><option value="plateau">Plateau de combats</option></select></label></p>';
        echo '<p><input name="csv_file" type="file" accept=".csv,text/csv" required></p>';
        submit_button( 'Analyser le CSV (aucune écriture)' );
        echo '</form>';
        if ( is_array( $report ) ) {
            echo '<h2>Résultat du contrôle</h2>';
            echo '<p>Lignes de données : ' . esc_html( (string) $report['total'] ) . '</p>';
            if ( empty( $report['issues'] ) ) {
                echo '<div class="notice notice-success inline"><p>Structure CSV valide. La création réelle sera disponible après raccordement au workflow de validation.</p></div>';
            } else {
                echo '<div class="notice notice-warning inline"><p>Points à corriger :</p><ul>';
                foreach ( $report['issues'] as $issue ) { echo '<li>' . esc_html( $issue ) . '</li>'; }
                echo '</ul></div>';
            }
            echo '<h3>Aperçu (10 premières lignes)</h3><table class="widefat striped"><thead><tr>';
            foreach ( $report['headers'] as $header ) { echo '<th>' . esc_html( $header ) . '</th>'; }
            echo '</tr></thead><tbody>';
            foreach ( $report['rows'] as $row ) {
                echo '<tr>';
                foreach ( $row as $cell ) { echo '<td>' . esc_html( $cell ) . '</td>'; }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public static function template(): void {
        self::allow();
        $kind = isset( $_GET['kind'] ) && is_string( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
        if ( ! isset( self::FIELDS[ $kind ] ) ) { wp_die( 'Modèle inconnu', '', array( 'response' => 400 ) ); }
        check_admin_referer( 'ufsc_event_plateau_template_' . $kind );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="ufsc-modele-' . $kind . '.csv"' );
        echo "\xEF\xBB\xBF";
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, self::FIELDS[ $kind ], ';' );
        fclose( $out );
        exit;
    }

    public static function preview(): void {
        self::allow();
        check_admin_referer( 'ufsc_event_plateau_preview' );
        $kind = isset( $_POST['kind'] ) && is_string( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
        if ( ! isset( self::FIELDS[ $kind ] ) ) { wp_die( 'Type inconnu', '', array( 'response' => 400 ) ); }
        $file = isset( $_FILES['csv_file'] ) && is_array( $_FILES['csv_file'] ) ? $_FILES['csv_file'] : array();
        if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || (int) ( $file['size'] ?? 0 ) > self::LIMIT || (int) ( $file['size'] ?? 0 ) < 1 || ! is_uploaded_file( $file['tmp_name'] ?? '' ) || strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) ) !== 'csv' ) {
            wp_die( 'Fichier CSV invalide ou supérieur à 2 Mo.', '', array( 'response' => 400 ) );
        }
        $fh = fopen( $file['tmp_name'], 'rb' );
        if ( ! $fh ) { wp_die( 'Impossible de lire le fichier.', '', array( 'response' => 400 ) ); }
        $first = fgets( $fh );
        if ( false === $first ) { fclose( $fh ); wp_die( 'CSV vide.', '', array( 'response' => 400 ) ); }
        $first = preg_replace( '/^\xEF\xBB\xBF/', '', $first );
        $delimiter = substr_count( $first, ';' ) >= substr_count( $first, ',' ) ? ';' : ',';
        rewind( $fh );
        $headers = fgetcsv( $fh, 0, $delimiter );
        $headers = is_array( $headers ) ? array_map( static function( $v ) { return trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $v ) ); }, $headers ) : array();
        $issues = array();
        foreach ( self::FIELDS[ $kind ] as $required ) {
            if ( ! in_array( $required, $headers, true ) ) { $issues[] = 'Colonne manquante : ' . $required; }
        }
        if ( count( $headers ) !== count( array_unique( $headers ) ) ) { $issues[] = 'Colonnes dupliquées.'; }
        $rows = array(); $total = 0; $fight_numbers = array();
        while ( ( $cells = fgetcsv( $fh, 0, $delimiter ) ) !== false ) {
            if ( count( $cells ) === 1 && trim( (string) $cells[0] ) === '' ) { continue; }
            ++$total;
            if ( $total > self::MAX_ROWS ) { $issues[] = 'Limite de 500 lignes dépassée.'; break; }
            if ( count( $cells ) !== count( $headers ) ) { $issues[] = 'Ligne ' . ( $total + 1 ) . ' : nombre de colonnes incorrect.'; }
            $cells = array_map( static function( $v ) { return sanitize_text_field( (string) $v ); }, $cells );
            if ( $kind === 'plateau' && in_array( 'fight_no', $headers, true ) ) {
                $number = $cells[ array_search( 'fight_no', $headers, true ) ] ?? '';
                if ( $number === '' || ! ctype_digit( $number ) || (int) $number < 1 ) { $issues[] = 'Ligne ' . ( $total + 1 ) . ' : numéro de combat invalide.'; }
                elseif ( isset( $fight_numbers[ $number ] ) ) { $issues[] = 'Numéro de combat dupliqué : ' . $number; }
                $fight_numbers[ $number ] = true;
            }
            if ( count( $rows ) < 10 ) { $rows[] = array_slice( array_pad( $cells, count( $headers ), '' ), 0, count( $headers ) ); }
            if ( count( $issues ) >= 100 ) { $issues[] = 'Contrôle interrompu après 100 anomalies.'; break; }
        }
        fclose( $fh );
        if ( $kind === 'event' && $total !== 1 ) { $issues[] = 'Le modèle événement doit contenir exactement une ligne de données.'; }
        set_transient( 'ufsc_event_plateau_preview_' . get_current_user_id(), array( 'headers' => $headers, 'rows' => $rows, 'issues' => $issues, 'total' => $total ), 120 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) ); exit;
    }
}
