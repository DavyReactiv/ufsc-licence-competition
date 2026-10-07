<?php
/**
 * Regression guard for FFST staging selection and safe import reviews.
 * Deliberately checks the wpdb insert format that corrupted earlier lots.
 */
$root=dirname(__DIR__);
$service=file_get_contents($root.'/includes/import/class-ufsc-lc-ffst-import-service.php');
$page=file_get_contents($root.'/includes/admin/class-ufsc-lc-ffst-import-page.php');
$errors=array();

$start=strpos($service,"'raw_payload'=>wp_json_encode");
$end=$start===false?false:strpos($service,"if(false===$inserted)",$start);
if(false===$start||false===$end){$errors[]='stage insert missing';}
else {
	$snippet=substr($service,$start,$end-$start);
	$expected=array_merge(array('%d','%d'),array_fill(0,17,'%s'),array('%d','%s','%s','%s'));
	if(!preg_match('/\),array\(([^)]*)\)\);/',$snippet,$m)){
		$errors[]='stage formats missing';
	}else{
		preg_match_all("/'%[ds]'/",$m[1],$fm);
		$actual=array_map(function($x){return trim($x,"'");},$fm[0]);
		if($actual!==$expected){
			$errors[]='stage insert formats mismatch: expected '.count($expected).' typed positions, got '.count($actual);
		}
	}
}
$service_checks=array(
	'get_duplicate_flags(',
	'get_candidates_for_row(',
	'confirm_row_match(',
	'apply_selected_rows(',
	'get_legacy_unreviewed_count(',
	'repair_legacy_batch(',
	'GET_LOCK(%s, 8)',
	'RELEASE_LOCK(%s)',
	'ffst_transaction_required',
	'ffst_duplicate',
	'ffst_number_owned',
	"'match_status'=>self::APPLIED"
);
foreach($service_checks as $check){if(false===strpos($service,$check))$errors[]='service: '.$check;}
$page_checks=array(
	'ffst_selected[]',
	'ffst-line-check',
	'ffst-apply-form',
	'ufsc_lc_ffst_review',
	'ufsc_lc_ffst_recheck',
	'Vérifier / lier',
	'Synchroniser les licences sélectionnées',
	'Doublons',
);
foreach($page_checks as $check){if(false===strpos($page,$check))$errors[]='page: '.$check;}
if($errors){fwrite(STDERR,"FFST safe review guard failed:\n - ".implode("\n - ",$errors)."\n");exit(1);}
echo "FFST safe review guard: OK\n";
