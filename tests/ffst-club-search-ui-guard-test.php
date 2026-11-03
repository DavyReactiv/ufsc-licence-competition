<?php
/**
 * Static guard for accessible and safe client-side club searching.
 * php tests/ffst-club-search-ui-guard-test.php
 */
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/includes/import/class-ufsc-lc-ffst-resolution-admin.php' );
$js = file_get_contents( $root . '/assets/js/ffst-club-search.js' );
$css = file_get_contents( $root . '/assets/css/ffst-resolution-center.css' );
$checks = array(
    'Admin-only AJAX endpoint' => false !== strpos( $admin, "wp_ajax_ufsc_lc_ffst_club_search" ),
    'Nonce enforced' => false !== strpos( $admin, "check_ajax_referer( 'ufsc_ffst_search_clubs', 'nonce' )" ),
    'Capability gate' => false !== strpos( $admin, 'if ( ! self::allowed() )' ),
    'Bounded prepared SQL' => false !== strpos( $admin, 'LIKE %s ORDER BY' )
        && false !== strpos( $admin, 'LIMIT 30' ),
    'Escaped LIKE pattern' => false !== strpos( $admin, '$wpdb->esc_like( $query )' ),
    'Canonical ID verification' => false !== strpos( $admin, 'WHERE id=%d LIMIT 1' ),
    'Remote mapping search field' => false !== strpos( $admin, 'id="ffst-target-club"' ),
    'Remote manual search field' => false !== strpos( $admin, 'id="ffst-manual-club"' ),
    'Selected club is preserved' => false !== strpos( $js, 'var selectedId = select.value' ),
    'Fallback when remote search fails' => false !== strpos( $js, 'utilisez la liste existante' ),
    'No HTML injection' => false === strpos( $js, '.innerHTML' )
        && false !== strpos( $js, 'element.textContent = String(label)' ),
    'Accessible live feedback' => false !== strpos( $admin, 'aria-live="polite"' ),
    'UI stylesheet scoped' => false !== strpos( $css, '.ufsc-lc-admin .ufsc-ffst-club-search' ),
);
$failed = array_keys( array_filter( $checks, static function( $ok ) { return ! $ok; } ) );
if ( $failed ) {
    fwrite( STDERR, "FFST club search UI guard failed: " . implode( ', ', $failed ) . "\n" );
    exit( 1 );
}
echo "FFST club search UI guard: OK\n";
