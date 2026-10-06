<?php
/**
 * Guard the official FFST PDF text layout used by federation licence pages.
 */
define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/import/class-ufsc-lc-ffst-import-service.php';

$service=new UFSC_LC_FFST_Import_Service();
$method=new ReflectionMethod('UFSC_LC_FFST_Import_Service','parse_ffst_native_page');
$method->setAccessible(true);

$fixtures=array(
array(
'text'=>"MFC MONTLUCON FIGHT CLUB
3 03 3091 9908 M211074
UFSC - KICK BOXING et DA
PRESIDENT ENTRAINEUR
M né le 11/09/1983
PHOTO
Signature
CRUSOE Davy
6 Rue Anna de Noailles
03100 MONTLUCON
Je soussigné, certifie que M
Porteur de cette licence, ne présente aucune contre
indication à la pratique sportive de la discipline
susvisée (Article L 231-3 du Code du Sport)
Fait à Le
LICENCE FFST N° M211074 Délivrée le : 01/10/2026
Docteur :
Cachet et signature :
M F C 03100
D.CRUSOE
2026-2027 U
Licence : M211074",
'expect'=>array('N° FFST'=>'M211074','Nom'=>'CRUSOE','Prénom'=>'Davy','Date de naissance'=>'11/09/1983','Sexe'=>'M','Club'=>'MFC MONTLUCON FIGHT CLUB','Fonction'=>'PRESIDENT ENTRAINEUR','Code postal'=>'03100','Ville'=>'MONTLUCON','Délivrée le'=>'01/10/2026')
),
array(
'text'=>"MFC MONTLUCON FIGHT CLUB
3 03 3091 9908 F211075
UFSC - KICK BOXING et DA
SECRETAIRE
F née le 12/05/1988
PHOTO
Signature
BESSEIGE Anaïs
4 Rue des Ormes
03420 MARCILLAT EN COMBRAILLE
LICENCE FFST N° F211075 Délivrée le : 01/10/2026
A.BESSEIGE
2026-2027 U
Licence : F211075",
'expect'=>array('N° FFST'=>'F211075','Nom'=>'BESSEIGE','Prénom'=>'Anaïs','Date de naissance'=>'12/05/1988','Sexe'=>'F')
),
array(
'text'=>"MFC MONTLUCON FIGHT CLUB
3 03 3091 9908 M211076
UFSC - KICK BOXING et DA
TRESORIER
M né le 24/09/1978
PHOTO
Signature
PHILIPPON Fabien
310 Chemin des Verpis
03310 VILLEBRET
LICENCE FFST N° M211076 Délivrée le : 01/10/2026
F.PHILIPPON
2026-2027 U
Licence : M211076",
'expect'=>array('N° FFST'=>'M211076','Nom'=>'PHILIPPON','Prénom'=>'Fabien','Date de naissance'=>'24/09/1978','Sexe'=>'M')
)
);

$errors=array();
foreach($fixtures as $index=>$fixture){
	$row=$method->invoke($service,$fixture['text']);
	foreach($fixture['expect'] as $key=>$expected){
		$actual=isset($row[$key])?$row[$key]:null;
		if($actual!==$expected)$errors[]='fixture '.($index+1).' '.$key.' expected '.var_export($expected,true).' got '.var_export($actual,true);
	}
}
if($errors){fwrite(STDERR,"FFST native PDF parser guard failed:\n - ".implode("\n - ",$errors)."\n");exit(1);}
echo "FFST native PDF parser guard: OK\n";
