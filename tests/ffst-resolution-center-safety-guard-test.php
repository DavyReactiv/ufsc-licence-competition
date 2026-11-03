<?php
/**
 * FFST admin resolution centre - static safety and UX regression guard.
 * Standalone: php tests/ffst-resolution-center-safety-guard-test.php
 */
$root = dirname( __DIR__ );
$service = file_get_contents( $root . '/includes/import/class-ufsc-lc-ffst-import-service.php' );
$ui = file_get_contents( $root . '/includes/admin/class-ufsc-lc-ffst-import-page.php' );
$helper = file_get_contents( $root . '/includes/import/class-ufsc-lc-ffst-resolution-admin.php' );
$boot = file_get_contents( $root . '/includes/class-ufsc-lc-plugin.php' );
$css = file_get_contents( $root . '/assets/css/ffst-resolution-center.css' );
$checks = array(
    'New module loaded' => strpos( $boot, 'class-ufsc-lc-ffst-resolution-admin.php' ) !== false,
    'Only admin role' => strpos( $helper, "current_user_can( 'manage_options' )" ) !== false,
    'Import capability checked' => strpos( $helper, 'UFSC_LC_Capabilities::user_can_import()' ) !== false,
    'Club alias nonce' => strpos( $helper, "check_admin_referer( 'ufsc_ffst_club_'" ) !== false,
    'Manual match nonce' => strpos( $helper, "check_admin_referer( 'ufsc_ffst_match_'" ) !== false,
    'CSV nonce' => strpos( $helper, "check_admin_referer( 'ufsc_ffst_export_'" ) !== false,
    'Club mapping persisted' => strpos( $helper, "ufsc_lc_ffst_club_alias_v1" ) !== false,
    'Club mapping filters candidates only' => strpos( $service, 'if ( $mapped_club )' ) !== false,
    'Manual staging service' => strpos( $service, 'public function manual_stage_match' ) !== false,
    'Manual identity and year verified' => strpos( $service, '$candidate->eligible' ) !== false && strpos( $service, '$season_ok' ) !== false,
    'Manual number collision check' => strpos( $service, 'ffst_manual_collision' ) !== false,
    'Manual stage transition' => strpos( $service, "'match_status' => self::MATCHED" ) !== false,
    'Manual audit' => strpos( $helper, "'ufsc_ffst_manual_link_'" ) !== false,
    'Draft prepared in native form' => strpos( $helper, " 'action' => 'new' " ) !== false,
    'Draft uses nonce' => strpos( $helper, "wp_verify_nonce( $nonce, 'ffst_draft_'" ) !== false,
    'No automatic draft insertion' => strpos( $helper, 'Aucune licence n’est créée avant' ) !== false,
    'Filtered pagination' => strpos( $service, 'get_batch_rows_by_status' ) !== false && strpos( $ui, "ffst_status" ) !== false,
    'Search and select within page' => strpos( $ui, 'ffst-live-search' ) !== false && strpos( $ui, 'ffst-select-all' ) !== false,
    'Hidden row CSS' => strpos( $css, 'ffst-import-record[hidden]' ) !== false,
    'CSV formula guard' => strpos( $helper, "escape_csv_cell" ) !== false,
);
$failed = array_keys( array_filter( $checks, static function( $ok ) { return ! $ok; } ) );
if ( strpos( $helper, 'INSERT INTO wp_ufsc_licences' ) !== false || strpos( $helper, "DELETE FROM wp_ufsc_licences" ) !== false ) {
    $failed[] = 'Unexpected direct licence mutation';
}
if ( $failed ) {
    fwrite( STDERR, "FFST resolution guard FAILED:\n - " . implode( "\n - ", $failed ) . "\n" );
    exit( 1 );
}
echo "FFST resolution centre safety: OK\n";
