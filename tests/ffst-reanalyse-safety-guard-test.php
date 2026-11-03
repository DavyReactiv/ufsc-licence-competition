<?php
/**
 * Regression guard for reanalysis of an already staged FFST batch.
 * php tests/ffst-reanalyse-safety-guard-test.php
 */
$root=dirname(__DIR__);
$service=file_get_contents($root.'/includes/import/class-ufsc-lc-ffst-import-service.php');
$page=file_get_contents($root.'/includes/admin/class-ufsc-lc-ffst-import-page.php');
$checks=array(
    'Service entry point'=>strpos($service,'public function reanalyse_not_found(')!==false,
    'Staged not_found only'=>strpos($service,'WHERE batch_id=%d AND match_status=%s ORDER BY id ASC LIMIT 501')!==false,
    'Recheck live identity'=>strpos($service,'$this->resolve_match($d,absint($batch->season_end_year))')!==false,
    'Reject duplicate FFST numbers'=>strpos($service,'$conflicting_number||$conflicting_person')!==false,
    'Only staging rows mutated'=>strpos($service,"'match_status'=>self::MATCHED")!==false,
    'Old applied batch allows new confirmed matches'=>strpos($service, "'applied'===" . '$batch->status' . " && 0===" . '$this->get_batch_status_count')!==false,
    'Admin handler'=>strpos($page,'handle_reanalyse')!==false,
    'Permission check'=>strpos($page,"UFSC_LC_Capabilities::user_can_import()")!==false,
    'Nonce'=>strpos($page,"check_admin_referer('ufsc_lc_ffst_reanalyse_'")!==false,
    'Existing batch action'=>strpos($page,'Relancer le rapprochement des introuvables')!==false,
    'No auto-validation'=>false===strpos($service,'UFSC_Licence_Status::update_status_columns'),
);
$failed=array_keys(array_filter($checks,static function($x){return !$x;}));
if($failed){fwrite(STDERR,"FFST reanalysis safety failed: ".implode('; ',$failed)."\n");exit(1);}
echo "FFST reanalysis safety guard: OK\n";
