<?php
/**
 * Validate FFST staging formats and immutable safe-import preflight.
 *
 * The historical issue was 23 inserted fields but only 22 format specifiers.
 * The mismatch silently coerced match_status into numeric zero.
 */
$path=dirname(__DIR__).'/includes/import/class-ufsc-lc-ffst-import-service.php';
$s=file_get_contents($path);
if(false===$s){fwrite(STDERR,"Cannot read FFST importer\n");exit(1);}

$start=strpos($s,"$"."inserted=$"."wpdb->insert($"."this->rows_table()");
$end=$start===false?false:strpos($s,"if(false===$"."inserted)",$start);
if(false===$start||false===$end){fwrite(STDERR,"FFST row insert not found\n");exit(1);}
$block=substr($s,$start,$end-$start);

$keys=array();
preg_match_all("/'([a-z_]+)'\\s*=>/", $block, $matches);
$keys=$matches[1];
$format_start=strrpos($block,'),array(');
$formats=array();
if(false!==$format_start){
	preg_match_all("/'%[dsf]'/",substr($block,$format_start),$fm);
	$formats=$fm[0];
}
$errors=array();
if(count($keys)!==count($formats))$errors[]='fields='.count($keys).' formats='.count($formats);
$expected=array('match_status'=>'%s','match_licence_id'=>'%d','raw_payload'=>'%s','birthdate'=>'%s');
foreach($expected as $key=>$format){
	$index=array_search($key,$keys,true);
	if(false===$index||!isset($formats[$index])||trim($formats[$index],"'")!==$format){
		$errors[]=$key.' must use '.$format;
	}
}
foreach(array('ffst_staging_row_verification_failed','ffst_invalid_staged_status','ffst_staging_counters_corrupt','ffst_stage_integrity_mismatch') as $needle){
	if(false===strpos($s,$needle))$errors[]='missing safety check: '.$needle;
}
if($errors){fwrite(STDERR,"FFST staging format guard failed: ".implode('; ',$errors)."\n");exit(1);}
echo "FFST staging SQL formats and safety checks: OK\n";
