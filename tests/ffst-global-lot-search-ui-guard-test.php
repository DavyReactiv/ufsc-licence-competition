<?php
/**
 * php tests/ffst-global-lot-search-ui-guard-test.php
 * Prevent regression to per-page-only search or loss of filter state.
 */
$root = dirname( __DIR__ );
$page = file_get_contents( $root . '/includes/admin/class-ufsc-lc-ffst-import-page.php' );
$service = file_get_contents( $root . '/includes/import/class-ufsc-lc-ffst-import-service.php' );
$css = file_get_contents( $root . '/assets/css/ffst-resolution-center.css' );
$checks = array(
    'Sanitized global term' => strpos( $page, "sanitize_text_field(wp_unslash(" ) !== false
        && strpos( $page, "ffst_search" ) !== false,
    'Read-only global count' => strpos( $page, 'count_batch_rows_filtered($batch_id,$status_filter,$lot_search)' ) !== false,
    'Read-only global page' => strpos( $page, 'get_batch_rows_filtered($batch_id,$status_filter,$lot_search,$per_page,$offset)' ) !== false,
    'Preserve search in status cards' => strpos( $page, "'ffst_search'=>" . '$lot_search' . ",'ffst_page'=>1" ) !== false,
    'Preserve search during pagination' => strpos( $page, "'ffst_search'=>" . '$lot_search' . ",'ffst_page'=>" . '$page_number' ) !== false,
    'Explicit clear search link' => strpos( $page, 'Effacer la recherche' ) !== false,
    'Empty result guidance' => strpos( $page, 'Aucune licence ne correspond à votre recherche' ) !== false,
    'Search entire lot form' => strpos( $page, 'Rechercher dans tout le lot' ) !== false,
    'Page-only refinement distinguished' => strpos( $page, 'Affiner les résultats de cette page' ) !== false,
    'Read-only prepared SQL' => strpos( $service, '$wpdb->prepare(' ) !== false
        && strpos( $service, "function get_batch_rows_filtered(" ) !== false
        && strpos( $service, "function count_batch_rows_filtered(" ) !== false,
    'Wildcard sanitization' => strpos( $service, '$wpdb->esc_like( $search )' ) !== false,
    'Controlled status filter' => strpos( $service, 'in_array( $status, $allowed, true )' ) !== false,
    'Responsive styling only in FFST' => strpos( $css, '.ufsc-lc-admin .ufsc-ffst-global-search' ) !== false,
);
$failed = array_keys( array_filter( $checks, static function ( $ok ) { return ! $ok; } ) );
if ( $failed ) {
    fwrite( STDERR, 'FFST global search UI guard failed: ' . implode( ', ', $failed ) . "\n" );
    exit( 1 );
}
echo "FFST global search UI guard: OK\n";
