<?php
/**
 * Regression test for the separate federation PDF format (one member/page).
 * Synthetic names and contact details; no private licence data committed.
 * php tests/ffst-avlh-multipage-pdf-layout-runtime-test.php
 */
define('ABSPATH', __DIR__ . '/');
if(!function_exists('remove_accents')){
    function remove_accents($value){
        return strtr((string)$value,array('é'=>'e','è'=>'e','ê'=>'e','É'=>'E','à'=>'a','ç'=>'c'));
    }
}
require dirname(__DIR__) . '/includes/import/class-ufsc-lc-ffst-import-service.php';
$service = new UFSC_LC_FFST_Import_Service();
$parse = new ReflectionMethod('UFSC_LC_FFST_Import_Service', 'parse_ffst_native_page');
$normalize = new ReflectionMethod('UFSC_LC_FFST_Import_Service', 'normalize_record');
$examples = array(
    array('M212346', 'M', '20/02/2018', 'DUPONT MARTIN', 'Aydan', 'A.DUPONT MARTIN'),
    array('M212347', 'M', '08/05/1994', 'AURE', 'Julien', 'J.AURE'),
    array('M212348', 'M', '31/05/1994', 'CRESCENCE', 'Mathieu Jean Julien', 'M.CRESCENCE'),
    array('M212349', 'M', '23/11/2016', 'DUPONT BOURGOGNE', 'Baptiste', 'B.DUPONT BOURGOGNE'),
    array('F212350', 'F', '19/06/2011', 'DUPONT BOURGOGNE', 'Maëlle', 'M.DUPONT BOURGOGNE'),
);
foreach ($examples as $i => $e) {
    list($num,$sex,$dob,$last,$first,$ref)=$e;
    $text="ALLONS VERS LES HAUTS - AVLH\n97 974 2963 9908 $num\nUFSC - KICK BOXING et DA\nPRATIQUANT\nPHOTO\n$sex né le $dob\nSignature\n$last                           $first\n88 Rue Exemple\n97460 SAINT PAUL\n$ref\nLicence : $num\n2026-2027 U\nJe soussigné, certifie que M\nPorteur de cette licence, ne présente aucune contre indication\nLICENCE FFST N° $num          Délivrée le :   23/10/2026";
    $parsed=$parse->invoke($service,$text);
    if (!$parsed || ($parsed['N° FFST']??'')!==$num || ($parsed['Nom']??'')!==$last || ($parsed['Prénom']??'')!==$first || ($parsed['Date de naissance']??'')!==$dob || ($parsed['Sexe']??'')!==$sex) {
        fwrite(STDERR, "AVLH native PDF page ".($i+1)." not parsed safely: ".json_encode($parsed)."\n"); exit(1);
    }
    $normalized=$normalize->invoke($service,$parsed);
    if ($normalized['ffst_number']!==$num || $normalized['last_name']!==$last || $normalized['first_name']!==$first || $normalized['sex']!==$sex) {
        fwrite(STDERR, "AVLH PDF normalization ".($i+1)." mismatch: ".json_encode($normalized)."\n"); exit(1);
    }
}
echo "AVLH 5-page FFST PDF layout and M/F normalization OK\n";
