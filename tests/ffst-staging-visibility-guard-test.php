<?php
/**
 * FFST import display guard: first row, totals and large batches.
 */
$root=dirname(__DIR__);
$page=file_get_contents($root.'/includes/admin/class-ufsc-lc-ffst-import-page.php');
$service=file_get_contents($root.'/includes/import/class-ufsc-lc-ffst-import-service.php');
$missing=array();
$expected=array(
	array($page,'ufsc-lc-ffst-import-record'),
	array($page,'data-ffst-row='),
	array($page,'foreach($rows as $row)'),
	array($page,'get_batch_row_count($batch_id)'),
	array($page,'$per_page=100'),
	array($page,'$counts_consistent'),
	array($page,'ffst_page'),
	array($page,'Lignes stockées'),
	array($service,'get_batch_row_count($id)'),
	array($service,'LIMIT %d OFFSET %d'),
);
foreach($expected as $e){if(false===strpos($e[0],$e[1]))$missing[]=$e[1];}
if(false!==strpos($page,'<table class="widefat striped">')){
	// Other tables (e.g. history) may remain native; only import preview must
	// be isolated from global WordPress <table> styling.
	$start=strpos($page,'Prévisualisation des licences FFST');
	$end=strpos($page,"<?php if($counts_consistent", $start?:0);
	if(false===$start || false===$end)$missing[]='preview structure markers';
}
if($missing){fwrite(STDERR,"FFST staging visibility guard failed:\n - ".implode("\n - ",$missing)."\n");exit(1);}
echo "FFST staging visibility guard: OK\n";
