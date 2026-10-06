<?php
/**
 * FFST import V2 static safety guard.
 */

$root=dirname(__DIR__);
$service=$root.'/includes/import/class-ufsc-lc-ffst-import-service.php';
$page=$root.'/includes/admin/class-ufsc-lc-ffst-import-page.php';
$plugin=$root.'/includes/class-ufsc-lc-plugin.php';
$composer=$root.'/composer.json';

$errors=array();
foreach(array($service,$page,$plugin,$composer) as $file){if(!is_file($file))$errors[]='missing '.basename($file);}
if(!$errors){
	$s=file_get_contents($service);$p=file_get_contents($page);$boot=file_get_contents($plugin);$c=file_get_contents($composer);
	$required=array(
		'ufsc_lc_ffst_import_batches','ufsc_lc_ffst_import_rows','stage_upload','apply_batch',
		'numero_licence_ffst','START TRANSACTION','ROLLBACK','ufsc_lc_ffst_import_synced',
		'Smalot\\PdfParser\\Parser','ZipArchive','CSV, XLSX ou PDF'
	);
	foreach($required as $needle){if(false===strpos($s,$needle))$errors[]='service missing '.$needle;}
	foreach(array('Import FFST','Analyser sans modifier les licences','Appliquer uniquement les lignes sûres','Saison active') as $needle){if(false===strpos($p,$needle))$errors[]='page missing '.$needle;}
	foreach(array("class-ufsc-lc-ffst-import-service.php","class-ufsc-lc-ffst-import-page.php","1.5.3") as $needle){if(false===strpos($boot,$needle))$errors[]='bootstrap missing '.$needle;}
	if(false===strpos($c,'smalot/pdfparser'))$errors[]='composer missing pdf parser';

	$forbidden=array('DROP TABLE','TRUNCATE TABLE','DELETE FROM wp_ufsc_licences','ALTER TABLE {$lt}');
	foreach($forbidden as $needle){if(false!==stripos($s,$needle))$errors[]='destructive token '.$needle;}

	// The new import must never create master UFSC licences automatically.
	if(false!==strpos($s,"INSERT INTO {$lt}"))$errors[]='master licence insert forbidden';
}
if($errors){fwrite(STDERR,"FFST import V2 guard failed:\n - ".implode("\n - ",$errors)."\n");exit(1);}
echo "FFST import V2 safety guard: OK\n";
