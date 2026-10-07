<?php
/**
 * FFST SQL and selection regression guard (PHP 7.4+).
 */
$root=dirname(__DIR__);
$service=file_get_contents($root.'/includes/import/class-ufsc-lc-ffst-import-service.php');
$page=file_get_contents($root.'/includes/admin/class-ufsc-lc-ffst-import-page.php');
$fail=array();
$start=strpos($service,'$inserted=$wpdb->insert');
$end=strpos($service,'if(false===$inserted)',$start?:0);
$insert=substr($service,$start,($end?:strlen($service))-$start);
preg_match_all("/'([a-z_]+)'\s*=>/", $insert, $fields);
preg_match("/\),array\((.*?)\)\);/s",$insert,$formats);
preg_match_all("/'%[dsf]'/",$formats[1]??'', $types);
if(count($fields[1])!==23)$fail[]='expected 23 staging fields';
if(count($fields[1])!==count($types[0]))$fail[]='SQL format count mismatch';
$indexed=array_combine($fields[1],array_map(function($v){return trim($v,"'");},$types[0]));
if(!is_array($indexed)||($indexed['match_status']??'')!=='%s')$fail[]='match_status must be stored as text';
if(($indexed['ffst_number']??'')!=='%s')$fail[]='alphanumeric FFST number must be text';
if(($indexed['match_licence_id']??'')!=='%d')$fail[]='linked licence id must be integer';
foreach(array(
	'selected_row_ids','ffst_selection_invalid','ffst_selection_duplicate',
	'ffst_existing_duplicate','ffst_identity_changed','get_batch_unknown_status_count',
	'Rapprocher les lignes sélectionnées','Ancien statut invalide'
) as $needle){
	if(false===strpos($service.$page,$needle))$fail[]='missing '.$needle;
}
foreach(array('DROP TABLE','TRUNCATE TABLE')as $danger){if(false!==strpos($service,$danger))$fail[]='unsafe SQL '.$danger;}
if($fail){fwrite(STDERR,"FFST selection and SQL guard failed:\n".implode("\n",$fail)."\n");exit(1);}
echo "FFST selection and SQL guard: OK\n";
