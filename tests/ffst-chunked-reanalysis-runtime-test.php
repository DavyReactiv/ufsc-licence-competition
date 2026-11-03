<?php
/**
 * Standalone: php tests/ffst-chunked-reanalysis-runtime-test.php
 * 205 unresolved licences across three requests, with a stable keyset cursor.
 */
define( 'ABSPATH', __DIR__ );
function __( $text, $domain = '' ) { return $text; }
function absint( $number ) { return abs( (int) $number ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
    private $code;
    public function __construct( $code, $message ) { $this->code = $code; }
}
class FFSTChunkDB {
    public $prefix = 'wp_';
    public $writes = array();
    public $selected = array();
    public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
    private function params( $sql ) { return is_array( $sql ) ? $sql['args'] : array(); }
    private function query_text( $sql ) { return is_array( $sql ) ? $sql['sql'] : $sql; }
    public function get_row( $sql ) {
        $query = $this->query_text( $sql );
        if ( strpos( $query, 'ffst_import_batches' ) !== false ) {
            return (object) array( 'id' => 8, 'total_rows' => 205, 'season_end_year' => 2027 );
        }
        if ( strpos( $query, 'ffst_import_rows' ) !== false && strpos( $query, 'id>%d' ) !== false ) {
            return (object) array( 'id' => 175, 'match_status' => 'not_found' );
        }
        if ( strpos( $query, 'ffst_import_rows' ) !== false ) {
            return (object) array( 'id' => 4, 'match_status' => 'not_found' );
        }
        return null;
    }
    public function get_results( $sql ) {
        $query = $this->query_text( $sql );
        if ( strpos( $query, 'ffst_import_rows' ) !== false && strpos( $query, 'id>%d ORDER BY id ASC LIMIT 100' ) !== false ) {
            $args = $this->params( $sql );
            $after_id = (int) $args[2];
            $this->selected[] = $after_id;
            $rows = array();
            for ( $id = $after_id + 1; $id <= min( $after_id + 100, 205 ); $id++ ) {
                $rows[] = (object) array( 'id' => $id, 'ffst_number' => 'F' . $id,
                    'last_name' => 'SAMPLE', 'first_name' => 'Test', 'birthdate' => '2001-01-01', 'club_name' => 'TEST CLUB' );
            }
            return $rows;
        }
        // No master UFSC licence matches: all staged rows remain 'not_found'.
        return array();
    }
    public function get_var( $sql ) {
        $query = $this->query_text( $sql );
        $args = $this->params( $sql );
        if ( strpos( $query, 'SHOW COLUMNS' ) !== false ) { return false; }
        if ( strpos( $query, 'ffst_import_rows' ) !== false && strpos( $query, 'COUNT(*)' ) !== false ) {
            if ( strpos( $query, 'id>%d' ) !== false ) {
                return max( 0, 205 - (int) $args[2] );
            }
            return isset( $args[1] ) && $args[1] === 'matched' ? 0 : 205;
        }
        return 0;
    }
    public function update( $table, $values, $where ) {
        $this->writes[] = $table;
        if ( strpos( $table, 'ffst_import_batches' ) === false ) {
            throw new Exception( 'Unexpected staging or master UFSC licence mutation' );
        }
        return 1;
    }
}
$wpdb = new FFSTChunkDB();
require dirname( __DIR__ ) . '/includes/import/class-ufsc-lc-ffst-import-service.php';
$service = new UFSC_LC_FFST_Import_Service();
$cursor = 0;
$counts = array();
for ( $i = 0; $i < 3; $i++ ) {
    $result = $service->reanalyse_not_found_chunk( 8, $cursor );
    if ( is_wp_error( $result ) ) { throw new Exception( 'Chunk returned error' ); }
    $counts[] = $result['processed'];
    if ( (bool) $result['has_more'] !== ( $i < 2 ) || (int) $result['matched'] !== 0 ) {
        throw new Exception( 'Wrong completion or match count' );
    }
    $cursor = $result['next_cursor'];
}
if ( $counts !== array( 100, 100, 5 ) || $wpdb->selected !== array( 0, 100, 200 ) || $cursor !== 205 ) {
    throw new Exception( 'Cursor skipped or duplicated unresolved rows' );
}
foreach ( $wpdb->writes as $table ) {
    if ( strpos( $table, 'ffst_import_batches' ) === false ) { throw new Exception( 'Master UFSC data was changed' ); }
}
$next = $service->get_next_unresolved_row( 8, 120 );
if ( ! $next || (int) $next->id !== 175 ) {
    throw new Exception( 'Next unresolved cursor failed' );
}
echo "FFST chunked reanalysis and next unresolved: OK\n";
