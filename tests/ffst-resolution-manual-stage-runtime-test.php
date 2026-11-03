<?php
/**
 * Deterministic runtime test of FFST manual matching. Standalone:
 * php tests/ffst-resolution-manual-stage-runtime-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
function __( $s, $d = '' ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function remove_accents( $v ) { return strtr( $v, array( 'é' => 'e', 'è' => 'e', 'É' => 'E' ) ); }
function sanitize_title( $v ) { return strtolower( str_replace( ' ', '-', remove_accents( trim( $v ) ) ) ); }
function sanitize_key( $v ) { return strtolower( (string) $v ); }
function get_option( $key, $default = false ) { return $GLOBALS['test_options'][$key] ?? $default; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class FFSTTestDb {
    public $prefix = 'wp_'; public $writes = array(); public $duplicate = false;
    public $batch; public $row; public $person;
    public function __construct() {
        $this->batch = (object) array( 'id' => 21, 'total_rows' => 1, 'season_end_year' => 2027 );
        $this->row = (object) array( 'id' => 3, 'batch_id' => 21, 'match_status' => 'not_found',
            'ffst_number' => 'F211404', 'first_name' => 'Pascale', 'last_name' => 'LE BACCON',
            'birthdate' => '1970-11-28', 'club_name' => 'ASSO SPORTIVE CULTURELLE ET EDUCATIVE DE VINCENDO' );
        $this->person = (object) array( 'id' => 99, 'club_id' => 4, 'prenom' => 'Pascale', 'nom' => 'LE BACCON',
            'date_naissance' => '1970-11-28', 'saison' => '2026-2027', 'numero_licence_ffst' => '' );
    }
    public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
    public function sql( $q ) { return is_array( $q ) ? $q['query'] : $q; }
    public function get_row( $q ) {
        $sql = $this->sql( $q );
        if ( strpos( $sql, 'ufsc_lc_ffst_import_batches' ) !== false ) { return $this->batch; }
        if ( strpos( $sql, 'ufsc_lc_ffst_import_rows' ) !== false ) { return $this->row; }
        return null;
    }
    public function get_results( $q ) {
        $sql = $this->sql( $q );
        $args = is_array( $q ) ? $q['args'] : array();
        if ( strpos( $sql, 'FROM wp_ufsc_licences' ) !== false ) {
            if ( (int) $args[0] !== (int) $this->person->club_id || (string) $args[1] !== $this->person->date_naissance ) { return array(); }
            return array( clone $this->person );
        }
        if ( strpos( $sql, 'FROM wp_ufsc_clubs' ) !== false ) {
            return array( (object) array( 'id' => 4, 'club_name' => 'ASCE DE VINCENDO' ) );
        }
        return array();
    }
    public function get_col( $q, $col = 0 ) { return array( 'id', 'nom' ); }
    public function get_var( $q ) {
        $sql = $this->sql( $q );
        if ( strpos( $sql, 'SHOW COLUMNS' ) !== false ) { return 1; }
        if ( strpos( $sql, 'COUNT(*)' ) !== false ) {
            if ( strpos( $sql, 'UPPER(numero_licence_ffst)' ) !== false ) { return $this->duplicate ? 1 : 0; }
            if ( strpos( $sql, 'match_licence_id' ) !== false ) { return $this->duplicate ? 1 : 0; }
            if ( strpos( $sql, 'match_status=%s' ) !== false ) {
                $args = is_array( $q ) ? $q['args'] : array();
                return ( $this->row->match_status === ( $args[1] ?? '' ) ) ? 1 : 0;
            }
            return 0;
        }
        return 0;
    }
    public function update( $table, $fields, $where, $types = array(), $where_types = array() ) {
        $this->writes[] = $table;
        if ( strpos( $table, 'ffst_import_rows' ) !== false ) {
            if ( ( $where['match_status'] ?? '' ) !== $this->row->match_status ) { return 0; }
            foreach ( $fields as $k => $v ) { $this->row->{$k} = $v; }
            return 1;
        }
        return 1;
    }
}
$wpdb = new FFSTTestDb();
require dirname( __DIR__ ) . '/includes/import/class-ufsc-lc-ffst-import-service.php';
$service = new UFSC_LC_FFST_Import_Service();
$matches = $service->get_manual_candidates( 21, 3, 4 );
if ( is_wp_error( $matches ) || count( $matches ) !== 1 || ! $matches[0]->eligible ) {
    fwrite( STDERR, "FAIL correct identity not suggested\n" ); exit( 1 );
}
$rejected = $service->manual_stage_match( 21, 3, 99, 5 );
if ( ! is_wp_error( $rejected ) ) { fwrite( STDERR, "FAIL wrong club was accepted\n" ); exit( 1 ); }
$wpdb->person->prenom = 'Pauline';
$rejected = $service->manual_stage_match( 21, 3, 99, 4 );
if ( ! is_wp_error( $rejected ) ) { fwrite( STDERR, "FAIL wrong identity was accepted\n" ); exit( 1 ); }
$wpdb->person->prenom = 'Pascale';
$wpdb->duplicate = true;
$rejected = $service->manual_stage_match( 21, 3, 99, 4 );
if ( ! is_wp_error( $rejected ) ) { fwrite( STDERR, "FAIL duplicate FFST number was accepted\n" ); exit( 1 ); }
$wpdb->duplicate = false;
$accepted = $service->manual_stage_match( 21, 3, 99, 4 );
if ( is_wp_error( $accepted ) || 'matched' !== $wpdb->row->match_status || 99 !== $wpdb->row->match_licence_id ) {
    fwrite( STDERR, "FAIL manual staging write\n" ); exit( 1 );
}
if ( '' !== $wpdb->person->numero_licence_ffst || in_array( 'wp_ufsc_licences', $wpdb->writes, true ) ) {
    fwrite( STDERR, "FAIL master UFSC licence was modified before Apply\n" ); exit( 1 );
}
echo "FFST manual stage runtime safety: OK\n";
