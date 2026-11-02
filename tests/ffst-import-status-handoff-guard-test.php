<?php
/**
 * Static guard for explicit status-validation handoff after FFST rapprochement.
 * Executable independently: php tests/ffst-import-status-handoff-guard-test.php
 */
$page = file_get_contents( dirname( __DIR__ ) . '/includes/admin/class-ufsc-lc-ffst-import-page.php' );
$service = file_get_contents( dirname( __DIR__ ) . '/includes/import/class-ufsc-lc-ffst-import-service.php' );
$required = array(
    "class_exists( 'UFSC_FFST_Batch_Validation', false )",
    "current_user_can( 'manage_options' )",
    "'page' => 'ufsc-ffst-validation'",
    "'batch_id' => " . '$batch_id',
    "'ufsc_ffst_synced'",
    "wp_safe_redirect(",
    "Le statut des licences UFSC reste inchangé",
    "Étape suivante : passer les statuts UFSC à",
    "Le centre de validation UFSC Gestion n’est pas chargé",
);
$failures = array();
foreach ( $required as $needle ) {
    if ( false === strpos( $page, $needle ) ) { $failures[] = 'Absent: ' . $needle; }
}
if ( false === strpos( $service, "do_action('ufsc_lc_ffst_import_synced'" ) ) {
    $failures[] = 'Import FFST sync hook missing';
}
if ( $failures ) {
    fwrite( STDERR, implode( "\n", $failures ) . "\n" );
    exit( 1 );
}
echo "FFST status handoff guard: OK\n";
