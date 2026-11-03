<?php
/**
 * Standalone: php tests/ffst-club-search-runtime-test.php
 * Tests >500 club lookup and secure AJAX response with an isolated WP stub.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['ffst_test_authorized'] = true;
$GLOBALS['ffst_test_nonce_checks'] = 0;
$GLOBALS['ffst_test_options'] = array(
    'ufsc_lc_ffst_club_alias_v1' => array( 'ffst-vincendo' => array( 'club_id' => 921 ) ),
);
function add_action() {}
function is_user_logged_in() { return $GLOBALS['ffst_test_authorized']; }
function current_user_can( $cap ) { return $GLOBALS['ffst_test_authorized'] && 'manage_options' === $cap; }
function absint( $v ) { return abs( (int) $v ); }
function get_option( $key, $fallback = false ) { return $GLOBALS['ffst_test_options'][ $key ] ?? $fallback; }
function sanitize_title( $text ) { return strtolower( trim( preg_replace( '/\s+/', '-', $text ) ) ); }
function remove_accents( $text ) { return $text; }
function sanitize_text_field( $text ) { return strip_tags( (string) $text ); }
function wp_unslash( $text ) { return stripslashes( $text ); }
function check_ajax_referer( $name, $field ) {
    if ( 'ufsc_ffst_search_clubs' !== $name || 'nonce' !== $field ) { throw new Exception( 'Wrong nonce check' ); }
    ++$GLOBALS['ffst_test_nonce_checks'];
}
class FFSTAjaxResult extends Exception {
    public $payload; public $status; public $success;
    public function __construct( $payload, $status, $success ) {
        $this->payload = $payload; $this->status = $status; $this->success = $success;
        parent::__construct( $success ? 'success' : 'error' );
    }
}
function wp_send_json_error( $payload, $status = 200 ) { throw new FFSTAjaxResult( $payload, $status, false ); }
function wp_send_json_success( $payload, $status = 200 ) { throw new FFSTAjaxResult( $payload, $status, true ); }
class UFSC_LC_Capabilities {
    public static function user_can_import() { return $GLOBALS['ffst_test_authorized']; }
}
class FFSTClubDB {
    public $prefix = 'wp_';
    public $last_prepared_args = array();
    public function get_col( $query, $index = 0 ) {
        return array( 'id', 'nom' );
    }
    public function esc_like( $value ) {
        return addcslashes( $value, '_%\\' );
    }
    public function prepare( $query, ...$args ) {
        $this->last_prepared_args = $args;
        return array( 'query' => $query, 'args' => $args );
    }
    public function get_row( $prepared ) {
        if ( ! is_array( $prepared ) || strpos( $prepared['query'], 'WHERE id=%d' ) === false ) {
            throw new Exception( 'Club lookup must be by canonical ID' );
        }
        return (int) $prepared['args'][0] === 921 ? (object) array( 'id' => 921, 'club_name' => 'ASCE DE VINCENDO' ) : null;
    }
    public function get_results( $prepared ) {
        if ( ! is_array( $prepared ) || strpos( $prepared['query'], 'LIKE %s' ) === false
            || strpos( $prepared['query'], 'LIMIT 30' ) === false ) {
            throw new Exception( 'Club search must be parameterized and bounded' );
        }
        if ( '%Vincendo%' === $prepared['args'][0] ) {
            return array( (object) array( 'id' => 921, 'club_name' => 'ASCE DE VINCENDO' ) );
        }
        return array();
    }
}
$wpdb = new FFSTClubDB();
require dirname( __DIR__ ) . '/includes/import/class-ufsc-lc-ffst-resolution-admin.php';
function check_ffst_search( $value, $label ) {
    if ( ! $value ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); }
}
check_ffst_search( UFSC_LC_FFST_Resolution_Admin::club_exists( 921 ), 'club beyond first 500 exists by id' );
check_ffst_search( ! UFSC_LC_FFST_Resolution_Admin::club_exists( 1002 ), 'unknown club rejected' );
check_ffst_search( 921 === UFSC_LC_FFST_Resolution_Admin::target_club_id( 'FFST VINCENDO' ), 'saved alias resolves beyond first 500' );
$_POST = array( 'term' => 'Vincendo', 'nonce' => 'test' );
try {
    UFSC_LC_FFST_Resolution_Admin::ajax_club_search();
    check_ffst_search( false, 'JSON response was not sent' );
} catch ( FFSTAjaxResult $result ) {
    check_ffst_search( $result->success && isset( $result->payload['clubs'][0]['id'] )
        && 921 === $result->payload['clubs'][0]['id'], 'remote matching club returned' );
}
check_ffst_search( 1 === $GLOBALS['ffst_test_nonce_checks'], 'nonce verified for authorized lookup' );
$GLOBALS['ffst_test_authorized'] = false;
try {
    UFSC_LC_FFST_Resolution_Admin::ajax_club_search();
    check_ffst_search( false, 'unauthorized lookup not rejected' );
} catch ( FFSTAjaxResult $result ) {
    check_ffst_search( ! $result->success && 403 === $result->status, 'unauthorized remote lookup is denied' );
}
check_ffst_search( 1 === $GLOBALS['ffst_test_nonce_checks'], 'unauthorized lookup rejected before nonce check' );
echo "FFST remote club search runtime: OK\n";
