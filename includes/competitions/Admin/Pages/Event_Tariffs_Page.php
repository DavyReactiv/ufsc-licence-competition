<?php
namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Standalone pricing manager, associated with a competition but not a combat.
 * Data are stored as an event-scoped option. No existing DB schema changes.
 */
final class Event_Tariffs_Page {
    const SLUG = 'ufsc-competitions-tariffs';
    const LIMIT_BYTES = 2097152;
    const ROW_LIMIT = 250;
    const TYPES = array( 'normal', 'reduit', 'gratuit', 'enfant', 'jeune', 'etudiant', 'senior', 'licencie', 'club', 'groupe', 'famille', 'prevente', 'sur_place', 'vip', 'invitation', 'autre' );

    private static function headers(): array {
        return array( 'tariff_code', 'tariff_type', 'tariff_label', 'price_eur', 'currency',
            'condition_note', 'min_age', 'max_age', 'valid_from', 'valid_until',
            'quantity_limit', 'is_active', 'additional_note' );
    }
    private static function key( int $id ): string { return 'ufsc_competition_tariffs_' . $id; }

    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 32 );
        add_action( 'admin_post_ufsc_tariffs_template', array( __CLASS__, 'template' ) );
        add_action( 'admin_post_ufsc_tariffs_import', array( __CLASS__, 'import' ) );
        add_action( 'admin_post_ufsc_tariffs_export', array( __CLASS__, 'export' ) );
    }
    private static function auth( int $event_id = 0 ): void {
        if ( ! current_user_can( \UFSC\Competitions\Capabilities::get_edit_capability() ) ) {
            wp_die( 'Accès refusé.', '', array( 'response' => 403 ) );
        }
        if ( $event_id ) {
            if ( function_exists( 'ufsc_lc_current_user_can_access_competition' ) &&
                ! ufsc_lc_current_user_can_access_competition( $event_id ) ) {
                wp_die( 'Compétition hors périmètre.', '', array( 'response' => 403 ) );
            }
            global $wpdb;
            $table = Db::competitions_table();
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d AND deleted_at IS NULL LIMIT 1", $event_id ) );
            if ( ! $exists ) { wp_die( 'Événement introuvable.', '', array( 'response' => 404 ) ); }
        }
    }
    public static function menu(): void {
        add_submenu_page( 'ufsc-competitions', 'Tarifs des événements',
            'Tarifs des événements', \UFSC\Competitions\Capabilities::get_edit_capability(),
            self::SLUG, array( __CLASS__, 'render' ) );
    }
    private static function link( string $action, int $event_id = 0 ): string {
        $args = array( 'action' => $action );
        if ( $event_id ) { $args['competition_id'] = $event_id; }
        return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), $action );
    }
    public static function render(): void {
        self::auth();
        $event_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
        if ( $event_id ) { self::auth( $event_id ); }
        $status = get_transient( 'ufsc_tariffs_notice_' . get_current_user_id() );
        delete_transient( 'ufsc_tariffs_notice_' . get_current_user_id() );
        echo '<div class="wrap"><h1>Grille tarifaire de l’événement</h1>';
        echo '<p>Plusieurs tarifs par manifestation : normal, prévente, réduit, gratuit, moins de 12 ans, VIP, groupes, etc. Le plateau des combats est indépendant.</p>';
        if ( $status ) { echo '<div class="notice notice-info inline"><p>' . esc_html( $status ) . '</p></div>'; }
        echo '<p><a class="button" href="' . esc_url( self::link( 'ufsc_tariffs_template' ) ) . '">Télécharger le modèle tarifs CSV</a></p>';
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
        echo '<label for="ufsc-tariffs-event">ID événement</label> ';
        echo '<input id="ufsc-tariffs-event" type="number" name="competition_id" min="1" value="' . esc_attr( $event_id ?: '' ) . '" required> ';
        echo '<button class="button">Charger ses tarifs</button></form>';
        if ( $event_id ) {
            $rows = get_option( self::key( $event_id ), array() );
            echo '<h2>Tarifs enregistrés</h2><table class="widefat striped"><thead><tr>';
            foreach ( self::headers() as $h ) { echo '<th>' . esc_html( $h ) . '</th>'; }
            echo '</tr></thead><tbody>';
            foreach ( (array) $rows as $row ) {
                echo '<tr>';
                foreach ( self::headers() as $h ) { echo '<td>' . esc_html( (string) ( $row[$h] ?? '' ) ) . '</td>'; }
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<p><a class="button" href="' . esc_url( self::link( 'ufsc_tariffs_export', $event_id ) ) . '">Exporter les tarifs CSV</a></p>';
            echo '<h2>Importer ou remplacer la grille tarifaire</h2><p><strong>Attention :</strong> le fichier remplace seulement la grille tarifaire de cet événement, jamais les combats, inscriptions ou résultats. Conserver un export avant remplacement.</p>';
            echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" enctype="multipart/form-data">';
            wp_nonce_field( 'ufsc_tariffs_import_' . $event_id );
            echo '<input type="hidden" name="action" value="ufsc_tariffs_import"><input type="hidden" name="competition_id" value="' . esc_attr( $event_id ) . '">';
            echo '<p><input type="file" name="tariff_csv" accept=".csv,text/csv" required></p>';
            echo '<p><label><input type="checkbox" name="confirm_replace" value="yes" required> Je confirme le remplacement de la grille tarifaire de cet événement.</label></p>';
            submit_button( 'Contrôler et importer les tarifs' );
            echo '</form>';
        }
        echo '</div>';
    }
    private static function output( string $name, array $rows ): void {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        echo "\xEF\xBB\xBF";
        $out = fopen( 'php://output', 'w' );
        foreach ( $rows as $row ) { fputcsv( $out, $row, ';' ); }
        fclose( $out ); exit;
    }
    public static function template(): void {
        self::auth(); check_admin_referer( 'ufsc_tariffs_template' );
        self::output( 'ufsc-modele-tarifs-evenement.csv', array(
            self::headers(),
            array('GENERAL','normal','Entrée générale','15.00','EUR','Plein tarif','','','2026-11-01','2026-11-07','','1','Billet standard'),
            array('MOINS12','gratuit','Enfants de moins de 12 ans','0.00','EUR','Gratuit sur présentation d’un justificatif d’âge','0','11','','','','1','Mineurs accompagnés selon conditions de l’organisateur'),
            array('PREVENTE','prevente','Prévente','12.00','EUR','Achat avant le gala','','','2026-10-01','2026-11-06','250','1',''),
            array('VIP','vip','Place VIP','35.00','EUR','Selon disponibilité','','','','','30','1',''),
        ) );
    }
    public static function export(): void {
        $id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
        self::auth( $id );
        if ( ! $id ) { wp_die( 'Événement manquant.', '', array( 'response' => 400 ) ); }
        check_admin_referer( 'ufsc_tariffs_export' );
        $rows = array( self::headers() );
        foreach ( (array) get_option( self::key( $id ), array() ) as $item ) {
            $line = array();
            foreach ( self::headers() as $h ) { $line[] = (string) ( $item[$h] ?? '' ); }
            $rows[] = $line;
        }
        self::output( 'ufsc-tarifs-evenement-' . $id . '.csv', $rows );
    }
    private static function date_ok( string $str ): bool {
        if ( $str === '' ) { return true; }
        $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $str );
        return $date && $date->format( 'Y-m-d' ) === $str;
    }
    public static function import(): void {
        $id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
        self::auth( $id );
        if ( ! $id ) { wp_die( 'Événement manquant.', '', array( 'response' => 400 ) ); }
        check_admin_referer( 'ufsc_tariffs_import_' . $id );
        if ( ( $_POST['confirm_replace'] ?? '' ) !== 'yes' ) { wp_die( 'Confirmation requise.', '', array( 'response' => 400 ) ); }
        $file = $_FILES['tariff_csv'] ?? array();
        if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ||
            (int) ( $file['size'] ?? 0 ) > self::LIMIT_BYTES || (int) ( $file['size'] ?? 0 ) < 1 ||
            ! is_uploaded_file( $file['tmp_name'] ?? '' ) ||
            strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) ) !== 'csv' ) {
            wp_die( 'Fichier CSV invalide.', '', array( 'response' => 400 ) );
        }
        $fh = fopen( $file['tmp_name'], 'rb' );
        $first = $fh ? fgets( $fh ) : false;
        if ( ! $first ) { if ( $fh ) { fclose( $fh ); } wp_die( 'CSV vide.', '', array( 'response' => 400 ) ); }
        $sep = substr_count( $first, ';' ) >= substr_count( $first, ',' ) ? ';' : ',';
        rewind( $fh );
        $heads = fgetcsv( $fh, 0, $sep );
        $heads = is_array( $heads ) ? array_map( 'trim', $heads ) : array();
        if ( isset( $heads[0] ) ) { $heads[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $heads[0] ); }
        if ( $heads !== self::headers() ) {
            fclose( $fh ); wp_die( 'Colonnes de tarifs incorrectes. Télécharger le modèle officiel.', '', array( 'response' => 400 ) );
        }
        $rows = array(); $codes = array();
        while ( ( $values = fgetcsv( $fh, 0, $sep ) ) !== false ) {
            if ( count( $values ) === 1 && trim( (string) $values[0] ) === '' ) { continue; }
            if ( count( $rows ) >= self::ROW_LIMIT || count( $values ) !== count( $heads ) ) {
                fclose( $fh ); wp_die( 'CSV trop long ou ligne incomplète.', '', array( 'response' => 400 ) );
            }
            $row = array_combine( $heads, array_map( 'sanitize_text_field', $values ) );
            $code = sanitize_key( $row['tariff_code'] );
            if ( $code === '' || isset( $codes[$code] ) ||
                ! in_array( $row['tariff_type'], self::TYPES, true ) ||
                ! preg_match( '/^\d{1,6}(\.\d{1,2})?$/', $row['price_eur'] ) ||
                $row['currency'] !== 'EUR' ||
                ! in_array( $row['is_active'], array('0','1'), true ) ||
                ! self::date_ok( $row['valid_from'] ) || ! self::date_ok( $row['valid_until'] ) ||
                ($row['valid_from'] !== '' && $row['valid_until'] !== '' && $row['valid_from'] > $row['valid_until']) ||
                ($row['min_age'] !== '' && ! ctype_digit( $row['min_age'] )) ||
                ($row['max_age'] !== '' && ! ctype_digit( $row['max_age'] )) ||
                ($row['quantity_limit'] !== '' && (!ctype_digit( $row['quantity_limit'] ) || (int) $row['quantity_limit'] < 1)) ||
                ($row['min_age'] !== '' && $row['max_age'] !== '' && (int) $row['min_age'] > (int) $row['max_age']) ||
                trim( $row['tariff_label'] ) === '' ) {
                fclose( $fh ); wp_die( 'Anomalie dans la grille tarifaire (code, type, montant, dates, âges, quota ou doublon).', '', array( 'response' => 400 ) );
            }
            $codes[$code] = true;
            $rows[] = $row;
        }
        fclose( $fh );
        if ( ! $rows ) { wp_die( 'Aucun tarif.', '', array( 'response' => 400 ) ); }
        $existing = (array) get_option( self::key( $id ), array() );
        $backup = array( 'saved_at' => current_time('mysql'), 'user_id' => get_current_user_id(), 'rows' => $existing );
        update_option( 'ufsc_competition_tariffs_backup_' . $id, $backup, false );
        update_option( self::key( $id ), $rows, false );
        set_transient( 'ufsc_tariffs_notice_' . get_current_user_id(), count($rows) . ' tarifs importés. Une sauvegarde de la grille précédente a été conservée.', 120 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&competition_id=' . $id ) ); exit;
    }
}
