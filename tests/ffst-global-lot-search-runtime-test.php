<?php
/**
 * php tests/ffst-global-lot-search-runtime-test.php
 * Read-only FFST staging search; safe wildcard handling, status scoping, paging.
 */
define( 'ABSPATH', __DIR__ . '/' );
function __( $v, $domain = '' ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( (string) $v ); }
class FFSTSearchDB {
    public $prefix = 'wp_';
    public $queries = array();
    public $writes = 0;
    public function esc_like( $v ) { return addcslashes( $v, '_%\\' ); }
    public function prepare( $sql, ...$params ) {
        if ( count( $params ) === 1 && is_array( $params[0] ) ) { $params = $params[0]; }
        $this->queries[] = array( 'sql' => $sql, 'params' => $params );
        return array( 'sql' => $sql, 'params' => $params );
    }
    public function get_results( $statement ) {
        if ( strpos( $statement['sql'], 'SELECT *' ) === false ) { throw new Exception( 'Unexpected SQL' ); }
        return array( (object) array( 'id' => 102, 'ffst_number' => 'M211234' ) );
    }
    public function get_var( $statement ) {
        if ( strpos( $statement['sql'], 'COUNT(*)' ) === false ) { throw new Exception( 'Unexpected count SQL' ); }
        return 26;
    }
    public function update() { $this->writes++; throw new Exception( 'Search attempted a database mutation' ); }
    public function insert() { $this->writes++; throw new Exception( 'Search attempted a database mutation' ); }
    public function query() { $this->writes++; throw new Exception( 'Search attempted a database mutation' ); }
}
$wpdb = new FFSTSearchDB();
require dirname( __DIR__ ) . '/includes/import/class-ufsc-lc-ffst-import-service.php';
$svc = new UFSC_LC_FFST_Import_Service();
$rows = $svc->get_batch_rows_filtered( 20, 'not_found', 'Jean_%', 100, 100 );
if ( count( $rows ) !== 1 || (int) $rows[0]->id !== 102 ) { fwrite( STDERR, "Rows missing\n" ); exit( 1 ); }
$query = $wpdb->queries[0];
if ( strpos( $query['sql'], 'batch_id=%d AND match_status=%s' ) === false
    || strpos( $query['sql'], 'LIMIT %d OFFSET %d' ) === false
    || strpos( $query['sql'], 'ffst_number LIKE %s' ) === false
    || strpos( $query['sql'], 'club_name LIKE %s' ) === false
    || count( $query['params'] ) !== 10
    || $query['params'][0] !== 20
    || $query['params'][1] !== 'not_found'
    || $query['params'][2] !== '%Jean\_\%%'
    || $query['params'][8] !== 100
    || $query['params'][9] !== 100
) {
    fwrite( STDERR, "Search SQL scope or escape mismatch: " . print_r( $query, true ) );
    exit( 1 );
}
$count = $svc->count_batch_rows_filtered( 20, 'not_found', 'Jean_%' );
$countSql = $wpdb->queries[1];
if ( $count !== 26 || count( $countSql['params'] ) !== 8
    || $countSql['params'][2] !== $query['params'][2] ) {
    fwrite( STDERR, "Filter count does not match row query\n" ); exit( 1 );
}
$svc->get_batch_rows_filtered( 21, 'arbitrary_status', '', 2000, 0 );
$unfiltered = $wpdb->queries[2];
if ( count( $unfiltered['params'] ) !== 3 || $unfiltered['params'][1] !== 500
    || strpos( $unfiltered['sql'], 'match_status=%s' ) !== false ) {
    fwrite( STDERR, "Allowlist or pagination limit missing\n" ); exit( 1 );
}
if ( $wpdb->writes !== 0 ) { fwrite( STDERR, "Search wrote to database\n" ); exit( 1 ); }
echo "FFST global lot search runtime: OK\n";
