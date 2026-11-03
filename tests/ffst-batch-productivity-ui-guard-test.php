<?php
/**
 * php tests/ffst-batch-productivity-ui-guard-test.php
 */
$root = dirname( __DIR__ );
$page = file_get_contents( $root . '/includes/admin/class-ufsc-lc-ffst-import-page.php' );
$service = file_get_contents( $root . '/includes/import/class-ufsc-lc-ffst-import-service.php' );
$admin = file_get_contents( $root . '/includes/import/class-ufsc-lc-ffst-resolution-admin.php' );
$tests = array(
    'Cursor accepted safely' => strpos( $page, "absint(\$_POST['after_id'])" ) !== false,
    'Resume option present' => strpos( $page, 'Continuer la réanalyse (100 lignes suivantes)' ) !== false,
    'Secure nonce retained' => strpos( $page, "check_admin_referer('ufsc_lc_ffst_reanalyse_'") !== false,
    'Progress visible' => strpos( $page, 'ffst_reanalyse_processed' ) !== false,
    'Restart link visible' => strpos( $page, 'Recommencer depuis le début' ) !== false,
    'Keyset bounded by 100' => strpos( $service, 'AND id>%d ORDER BY id ASC LIMIT 100' ) !== false,
    'Stage only write' => strpos( $service, "'match_status'=>self::MATCHED" ) !== false,
    'Next unresolved lookup' => strpos( $service, 'public function get_next_unresolved_row(' ) !== false,
    'Next case navigation' => strpos( $admin, 'Passer au prochain dossier introuvable ou ambigu' ) !== false,
    'Continue after manual association' => strpos( $admin, '$service->get_next_unresolved_row( $batch_id, $row_id )' ) !== false,
    'Club alias chunk continuation' => strpos( $admin, '$service->reanalyse_not_found_chunk( $batch_id, 0 )' ) !== false,
    'Selected row counter' => strpos( $page, 'id="ffst-selected-count"' ) !== false,
    'No auto status validation' => strpos( $service, 'UFSC_Licence_Status::update_status_columns' ) === false,
);
$failed = array_keys( array_filter( $tests, static function ( $valid ) { return ! $valid; } ) );
if ( $failed ) {
    fwrite( STDERR, 'FFST batch productivity guard failed: ' . implode( ', ', $failed ) . "\n" );
    exit( 1 );
}
echo "FFST batch productivity UI guard: OK\n";
