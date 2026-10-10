<?php
/** A4 PDF printing safety and data field regression checks. */
$root=dirname(__DIR__);
$generator=file_get_contents($root.'/includes/class-ufsc-lc-licence-pdf-generator.php');
$template=file_get_contents($root.'/templates/licence-sportive-a6.html');
$checks=array(
 'A4 portrait Dompdf' => strpos($generator,"setPaper( 'A4', 'portrait' )")!==false,
 'A4 portrait template' => strpos($template,'@page { size: A4 portrait; margin: 0; }')!==false,
 'two faces on one printable page' => substr_count($template,'class="face ')===2 && strpos($template,'.verso { top:155mm; }')!==false && strpos($template,'page-break-after:always')===false,
 'old version invalidation' => strpos($generator,'ufsc-document-v16-approved-v5-a4')!==false,
 'historic generated files retained' => strpos($generator,"'ufsc_lc_license_pdf_delete_previous_generated_attachment', false")!==false,
 'manual documents preserved' => strpos($generator,'ufsc_lc_pdf_manual_preserved')!==false,
 'official UFSC and FFST fields' => strpos($template,'{{numero_licence_ufsc}}')!==false && strpos($template,'{{numero_licence_ffst}}')!==false,
 'member name and birthdate' => strpos($template,'{{nom}}')!==false && strpos($template,'{{prenom}}')!==false && strpos($template,'{{date_naissance}}')!==false,
 'club and discipline fields' => strpos($template,'{{club}}')!==false && strpos($template,'{{discipline}}')!==false,
 'FFST medical legal statement' => strpos($template,'CERTIFICATION MÉDICALE — À COMPLÉTER PAR LE MÉDECIN')!==false,
 'no CSS grid or flex in Dompdf template' => strpos($template,'display:grid')===false && strpos($template,'display:flex')===false,
);
foreach($checks as $label=>$ok){echo($ok?'PASS ':'FAIL ').$label.PHP_EOL;}
exit(in_array(false,$checks,true)?1:0);
