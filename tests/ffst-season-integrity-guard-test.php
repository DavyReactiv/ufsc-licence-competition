<?php
/**
 * Guard season-aware FFST matching and staging row integrity.
 */
$service=dirname(__DIR__).'/includes/import/class-ufsc-lc-ffst-import-service.php';
if(!is_file($service)){fwrite(STDERR,"Missing FFST import service\n");exit(1);}
$s=file_get_contents($service);
$required=array(
	'season_end_year',
	"Plusieurs licences correspondent encore après filtrage saison + club.",
	"Correspondance sûre identité + naissance + saison + club.",
	'ffst_stage_integrity_mismatch',
	'COUNT(*) qty',
	'analysée(s)',
	'enregistrée(s)'
);
$errors=array();
foreach($required as $needle){if(false===strpos($s,$needle))$errors[]=$needle;}
if($errors){fwrite(STDERR,"FFST season/integrity guard failed:\n - ".implode("\n - ",$errors)."\n");exit(1);}
echo "FFST season/integrity guard: OK\n";
