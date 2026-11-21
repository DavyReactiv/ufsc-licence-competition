<?php
/** Run: php tests/ffst-official-xlsx-normalization-runtime-test.php */
define( 'ABSPATH', __DIR__ );
function remove_accents($v) {
    return strtr((string)$v, array('é'=>'e','è'=>'e','ê'=>'e','É'=>'E','à'=>'a','ç'=>'c'));
}
require dirname(__DIR__) . '/includes/import/class-ufsc-lc-ffst-import-service.php';
$service = new UFSC_LC_FFST_Import_Service();
$method = new ReflectionMethod('UFSC_LC_FFST_Import_Service', 'normalize_record');
$cases = array(
    array('Club'=>'01977 - NAK MUAY THAI GYM', 'Discipline'=>'9908 - UFSC - KICK BOXING et DA',
        'Nom et prénom'=>'ANGEVIN Olivier', 'Né le'=>'28037', 'N° licence'=>'213058',
        'Date entrée'=>'46336', 'Statut'=>'Active', 'Genre'=>'Masculin', 'Type'=>'Adulte'),
    array('Club'=>'01977 - NAK MUAY THAI GYM', 'Nom et prénom'=>'HENANE Safia',
        'Né le'=>'32232', 'N° licence'=>'213059', 'Statut'=>'Active', 'Genre'=>'Féminin'),
    array('Club'=>'02906 - ASLM NICE KICK-BOXING', 'Nom et prénom'=>'LA RUFFA Barthelemy',
        'Né le'=>'29707', 'N° licence'=>'213063', 'Statut'=>'Active', 'Genre'=>'Masculin'),
);
$r=array_map(static function($c)use($method,$service){return $method->invoke($service,$c);},$cases);
function ffst_assert($ok,$reason){if(!$ok){fwrite(STDERR,"FAIL: $reason\n");exit(1);}}
ffst_assert($r[0]['last_name']==='ANGEVIN' && $r[0]['first_name']==='Olivier','combined name');
ffst_assert($r[0]['ffst_number']==='M213058' && $r[0]['sex']==='M','M prefix');
ffst_assert($r[1]['ffst_number']==='F213059' && $r[1]['sex']==='F','F prefix');
ffst_assert($r[2]['last_name']==='LA RUFFA' && $r[2]['first_name']==='Barthelemy','compound surname');
ffst_assert($r[0]['club_name']==='NAK MUAY THAI GYM' && $r[0]['source_club_code']==='01977','club code split');
ffst_assert((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/',$r[0]['birthdate']),'Excel serial date');
$unknown=$method->invoke($service,array('Nom et prénom'=>'DUPONT Paul','N° licence'=>'213058','Genre'=>''));
ffst_assert($unknown['ffst_number']==='','unknown sex must not invent prefix');
$inactive=$method->invoke($service,array('Nom et prénom'=>'DUPONT Paul','N° licence'=>'213058','Genre'=>'Masculin','Statut'=>'Refusée'));
ffst_assert($inactive['ffst_number']==='','inactive licence must not be applied');
$conflict=$method->invoke($service,array('Nom et prénom'=>'DUPONT Paul','N° licence'=>'M213058','Genre'=>'Féminin'));
ffst_assert($conflict['ffst_number']==='','gender conflict');
$ambiguous=$method->invoke($service,array('Nom et prénom'=>'DUPONT JEAN','N° licence'=>'213060','Genre'=>'Masculin'));
ffst_assert($ambiguous['last_name']==='' && $ambiguous['first_name']==='','all uppercase identity not inferred');
$legacy=$method->invoke($service,array('Nom'=>'Martin','Prénom'=>'Jean','Date de naissance'=>'11/09/1983','N° FFST'=>'M212149','Sexe'=>'M'));
ffst_assert($legacy['last_name']==='MARTIN' && $legacy['first_name']==='Jean' && $legacy['birthdate']==='1983-09-11','legacy fields supported');
echo "FFST official XLSX normalization safeguards OK\n";
