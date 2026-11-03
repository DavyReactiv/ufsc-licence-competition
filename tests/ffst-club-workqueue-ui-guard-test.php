<?php
/**
 * Standalone: php tests/ffst-club-workqueue-ui-guard-test.php
 * Make sure club queue shortcuts and global search stay in sync.
 */
$root = dirname( __DIR__ );
$page = file_get_contents( $root . '/includes/admin/class-ufsc-lc-ffst-import-page.php' );
$service = file_get_contents( $root . '/includes/import/class-ufsc-lc-ffst-import-service.php' );
$css = file_get_contents( $root . '/assets/css/ffst-resolution-center.css' );
$checks = array(
    'Club listing scoped to batch' => strpos( $page, 'get_batch_club_summary($batch_id,250)' ) !== false,
    'Club filter sanitized' => strpos( $page, "sanitize_text_field(wp_unslash(\$_GET['ffst_club']))" ) !== false,
    'Club filter verified in current lot' => strpos( $page, 'batch_club_in_summary($club_summary,$club_filter)' ) !== false,
    'Same filter in row and count queries' => strpos( $page, 'count_batch_rows_filtered($batch_id,$status_filter,$lot_search,$club_filter)' ) !== false
        && strpos( $page, 'get_batch_rows_filtered($batch_id,$status_filter,$lot_search,$per_page,$offset,$club_filter)' ) !== false,
    'Status and pagination preserve club' => strpos( $page, "'ffst_club'=>\$club_filter,'ffst_page'=>1" ) !== false
        && strpos( $page, "'ffst_club'=>\$club_filter,'ffst_page'=>\$page_number" ) !== false,
    'Club selector in main search form' => strpos( $page, 'id="ffst-source-club"' ) !== false,
    'Workqueue UI' => strpos( $page, 'ufsc-ffst-club-workqueue' ) !== false,
    'Per-club counters visible' => strpos( $page, 'conflict_rows' ) !== false && strpos( $page, 'matched_rows' ) !== false,
    'Legacy methods preserved' => strpos( $service, 'function get_batch_rows_filtered(' ) !== false
        && strpos( $service, 'function count_batch_rows_filtered(' ) !== false,
    'Responsive local styling' => strpos( $css, '.ufsc-lc-admin .ufsc-ffst-club-workqueue' ) !== false,
);
$failed = array_keys( array_filter( $checks, static function( $valid ) { return ! $valid; } ) );
if ( $failed ) {
    fwrite( STDERR, 'Club workqueue UI guard failed: ' . implode( ', ', $failed ) . "\n" );
    exit( 1 );
}
echo "FFST club workqueue UI: OK\n";
