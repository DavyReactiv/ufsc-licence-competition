<?php
/**
 * Read-only static contract test for import season and PDF synchronization.
 * php tests/ffst-import-season-pdf-sync-guard-test.php
 */
$import = file_get_contents( __DIR__ . '/../includes/import/class-ufsc-lc-ffst-import-service.php' );
$pdf = file_get_contents( __DIR__ . '/../includes/class-ufsc-lc-licence-pdf-generator.php' );
$checks = array(
    'batch year validated before writes' => strpos( $import, "ffst_invalid_batch_season" ) !== false,
    'season columns inspected' => strpos( $import, "season_end_year','saison','season" ) !== false,
    'cross-season import blocked' => strpos( $import, "ffst_batch_season_mismatch" ) !== false,
    'import keeps duplicate number validation' => strpos( $import, "ffst_existing_duplicate" ) !== false,
    'partial batches can resume' => strpos( $import, "'partial':'applied'" ) !== false,
    'pdf ffst number cache checked' => strpos( $pdf, '$saved_ffst_number === $ffst_number' ) !== false,
    'manual pdf remains protected' => strpos( $pdf, "ufsc_lc_pdf_manual_preserved" ) !== false,
);
$failed = false;
foreach ( $checks as $name => $ok ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . PHP_EOL;
    if ( ! $ok ) { $failed = true; }
}
exit( $failed ? 1 : 0 );
