<?php
/**
 * Standalone: php tests/ffst-club-workqueue-runtime-test.php
 * Cross-batch isolation, exact source club, status + full-text filter,
 * bounded SQL, read-only totals, and backward-compatible unfiltered API.
 */
define( 'ABSPATH', __DIR__ . '/' );
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( (string) $value ); }
class FFSTClubWorkqueueDB {
    public $prefix = 'wp_';
    public $queries = array();
    public $writes = 0;
    public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
    public function prepare( $query, ...$args ) {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; }
        $this->queries[] = array( 'sql' => $query, 'args' => $args );
        return end( $this->queries );
    }
    public function get_results( $prepared ) {
        if ( strpos( $prepared['sql'], 'GROUP BY club_name' ) !== false ) {
            return array(
                (object) array( 'club_name' => 'ASCE DE VINCENDO', 'total_rows' => 20, 'not_found_rows' => 4, 'ambiguous_rows' => 2, 'conflict_rows' => 1, 'matched_rows' => 10, 'applied_rows' => 3 ),
                (object) array( 'club_name' => 'SAINT GILLES', 'total_rows' => 9, 'not_found_rows' => 1, 'ambiguous_rows' => 0, 'conflict_rows' => 0, 'matched_rows' => 5, 'applied_rows' => 3 )
            );
        }
        return array( (object) array( 'id' => 9 ) );
    }
    public function get_var( $prepared ) { return 1; }
    public function update() { ++$this->writes; throw new Exception( 'Unexpected write' ); }
    public function insert() { ++$this->writes; throw new Exception( 'Unexpected write' ); }
    public function query() { ++$this->writes; throw new Exception( 'Unexpected write' ); }
}
$wpdb = new FFSTClubWorkqueueDB();
require dirname( __DIR__ ) . '/includes/import/class-ufsc-lc-ffst-import-service.php';
$service = new UFSC_LC_FFST_Import_Service();
$summary = $service->get_batch_club_summary( 21, 250 );
if ( count( $summary ) !== 2 || ! $service->batch_club_in_summary( $summary, 'ASCE DE VINCENDO' )
    || $service->batch_club_in_summary( $summary, 'ANOTHER CLUB' ) ) {
    throw new Exception( 'Source club choices not properly isolated' );
}
$group = $wpdb->queries[0];
if ( strpos( $group['sql'], 'WHERE batch_id=%d' ) === false
    || strpos( $group['sql'], 'GROUP BY club_name' ) === false
    || strpos( $group['sql'], 'LIMIT %d' ) === false
    || (int) $group['args'][5] !== 21 || (int) $group['args'][6] !== 250 ) {
    throw new Exception( 'Summary must be scoped to one batch and capped' );
}
$service->get_batch_rows_filtered( 21, 'not_found', 'Jean_%', 100, 0, 'ASCE DE VINCENDO' );
$first = $wpdb->queries[1];
if ( strpos( $first['sql'], 'batch_id=%d AND match_status=%s AND club_name=%s AND (' ) === false
    || $first['args'][0] !== 21 || $first['args'][1] !== 'not_found'
    || $first['args'][2] !== 'ASCE DE VINCENDO' || $first['args'][3] !== '%Jean\_\%%'
    || (int) $first['args'][9] !== 100 || (int) $first['args'][10] !== 0 ) {
    throw new Exception( 'Batch + club + state + name filters not isolated or escaped' );
}
$service->count_batch_rows_filtered( 21, 'not_found', 'Jean_%', 'ASCE DE VINCENDO' );
$second = $wpdb->queries[2];
if ( strpos( $second['sql'], 'club_name=%s' ) === false
    || array_slice( $second['args'], 0, 4 ) !== array_slice( $first['args'], 0, 4 ) ) {
    throw new Exception( 'Count and pagination filters diverged' );
}
$service->get_batch_rows_filtered( 21, 'not_found', '', 100, 0 );
$legacy = $wpdb->queries[3];
if ( strpos( $legacy['sql'], 'club_name=%s' ) !== false || $legacy['args'][0] !== 21 ) {
    throw new Exception( 'Old search API behavior changed' );
}
if ( $wpdb->writes ) { throw new Exception( 'Read-only work queue modified data' ); }
echo "FFST club workqueue runtime: OK\n";
