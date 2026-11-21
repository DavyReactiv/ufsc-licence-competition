<?php
/**
 * Guard server-authoritative FFST selection across pagination.
 * Run: php tests/ffst-global-selection-safety-static-test.php
 */
$root = dirname( __DIR__ );
$page = file_get_contents( $root . '/includes/admin/class-ufsc-lc-ffst-import-page.php' );
$service = file_get_contents( $root . '/includes/import/class-ufsc-lc-ffst-import-service.php' );
$checks = array(
    'Selection scope always explicit' => strpos( $page, "ffst_selection_scope" ) !== false
        && strpos( $page, "value=\"all_filtered\"" ) !== false,
    'Large lots supported by bounded explicit chunks' => strpos( $page, "value=\"next_chunk\"" ) !== false
        && strpos( $page, "'next_chunk'===\$selection_scope?100:500" ) !== false
        && strpos( $page, "min(\$count,\$chunk_size)" ) !== false,
    'Applied rows measured from actual staged rows' => strpos( $page, "get_batch_status_count(\$batch_id,UFSC_LC_FFST_Import_Service::APPLIED)" ) !== false,
    'Original page selection retained' => strpos( $page, "name=\"selected_row_ids[]\"" ) !== false,
    'Server selects verified matches only' => strpos( $page, "get_batch_rows_filtered(\$batch_id,UFSC_LC_FFST_Import_Service::MATCHED" ) !== false,
    'Global count checked before selecting rows' => strpos( $page, "count_batch_rows_filtered(\$batch_id,UFSC_LC_FFST_Import_Service::MATCHED" ) !== false,
    'Global selection bounded to 500' => strpos( $page, "if(\$count<1 || \$count>500)" ) !== false,
    'Global lot integrity checked' => strpos( $page, "(int)\$batch->total_rows!==\$source_total" ) !== false,
    'Club filter verified in batch' => strpos( $page, 'batch_club_in_summary' ) !== false,
    'Global filter uses server side search' => strpos( $page, "sanitize_text_field(wp_unslash(\$_POST['ffst_search']))" ) !== false,
    'Existing apply method preserved' => strpos( $page, '$service->apply_batch($batch_id,$ids)' ) !== false,
    'Match recheck before writes' => strpos( $service, 'ffst_recheck_mismatch' ) !== false,
    'Exception not silently retried' => strpos( $page, 'catch(Throwable $error)' ) !== false
        && strpos( $page, 'Vérifiez les résultats déjà enregistrés' ) !== false,
    'No new status/club/payment/quotas writes' => strpos( $page, 'update_status_columns(' ) === false
        && strpos( $page, 'update_option(' ) === false,
);
foreach ( $checks as $name => $ok ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . PHP_EOL;
    if ( ! $ok ) { exit( 1 ); }
}
