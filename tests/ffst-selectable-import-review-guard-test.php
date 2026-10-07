<?php
/**
 * FFST selectable import review regression guard.
 * Fails if the status text is once again stored with the %d formatter.
 */
$root=dirname(__DIR__);
$service=file_get_contents($root.'/includes/import/class-ufsc-lc-ffst-import-service.php');
$page=file_get_contents($root.'/includes/admin/class-ufsc-lc-ffst-import-page.php');
$errors=array();
$start=strpos($service,'$inserted=$wpdb->insert(');
$stop=strpos($service,'if(false===$inserted)',(int)$start);
if(false===$start||false===$stop){
	$errors[]='staging insertion was not found';
}else{
	$part=substr($service,$start,$stop-$start);
	preg_match_all("/'([a-z_]+)'\\s*=>/", $part,$column_matches);
	preg_match_all("/'%[dsf]'/",substr($part,strrpos($part,'),array(')),$type_matches);
	$fields=$column_matches[1];$formats=$type_matches[0];
	$idx=array_search('match_status',$fields,true);
	$licence_idx=array_search('match_licence_id',$fields,true);
	if(false===$idx||!isset($formats[$idx])||"'%s'"!==$formats[$idx])$errors[]='match_status must use %s';
	if(false===$licence_idx||!isset($formats[$licence_idx])||"'%d'"!==$formats[$licence_idx])$errors[]='match_licence_id must use %d';
	if(count($fields)!==count($formats))$errors[]='wpdb insert fields/formats must match: '.count($fields).' fields, '.count($formats).' formats';
}
foreach(array('apply_selected_rows','assign_review_candidate','audit_batch','get_review_candidates','recheck_legacy_batch','START TRANSACTION','ROLLBACK','information_schema.TABLES','get_batch_row_count') as $term){
	if(false===strpos($service,$term))$errors[]='service missing '.$term;
}
foreach(array('ffst_selected[]','ufsc_lc_ffst_assign','ufsc_lc_ffst_recheck','Valider les lignes sélectionnées','Bloqué : doublon','Recalculer les rapprochements du lot') as $term){
	if(false===strpos($page,$term))$errors[]='UI missing '.$term;
}
if($errors){fwrite(STDERR,"FFST import review guard FAILED:\n - ".implode("\n - ",$errors)."\n");exit(1);}
echo "FFST selectable import review guard: OK\n";
