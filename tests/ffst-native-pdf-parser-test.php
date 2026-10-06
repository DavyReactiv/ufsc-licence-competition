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
30330919908M211074
UFSC - KICK BOXING et DA
PHOTO
PRESIDENT ENTRAINEUR
Signature
CRUSOEDavy
6 Rue Anna de Noailles
03100 MONTLUCON
M11/09/1983né le
Je soussigné, certifie que M
Porteur de cette licence, ne présente aucune contre
indication à la pratique sportive de la discipline
susvisée (Article L 231-3 du Code du Sport)
Fait à                                              Le 
Délivrée le : 01/10/2026LICENCE FFST N° M211074
 Docteur :
 Cachet et signature : 
M M F C 03100D.CRUSOE2026-2027 ULicence : M211074",
'expect'=>array('N° FFST'=>'M211074','Nom'=>'CRUSOE','Prénom'=>'Davy','Date de naissance'=>'11/09/1983','Sexe'=>'M','Club'=>'MFC MONTLUCON FIGHT CLUB','Fonction'=>'PRESIDENT ENTRAINEUR','Code postal'=>'03100','Ville'=>'MONTLUCON','Délivrée le'=>'01/10/2026')
),
array(
'text'=>"MFC MONTLUCON FIGHT CLUB
30330919908F211075
UFSC - KICK BOXING et DA
PHOTO
SECRETAIRE
Signature
BESSEIGEAnaïs
4 Rue des Ormes
03420 MARCILLAT EN COMBRAILLE
F12/05/1988née le
Je soussigné, certifie que M
Porteur de cette licence, ne présente aucune contre
indication à la pratique sportive de la discipline
susvisée (Article L 231-3 du Code du Sport)
Fait à                                              Le 
Délivrée le : 01/10/2026LICENCE FFST N° F211075
 Docteur :
 Cachet et signature : 
M M F C 03420A.BESSEIGE2026-2027 ULicence : F211075",
'expect'=>array('N° FFST'=>'F211075','Nom'=>'BESSEIGE','Prénom'=>'Anaïs','Date de naissance'=>'12/05/1988','Sexe'=>'F','Club'=>'MFC MONTLUCON FIGHT CLUB','Fonction'=>'SECRETAIRE','Code postal'=>'03420','Ville'=>'MARCILLAT EN COMBRAILLE','Délivrée le'=>'01/10/2026')
),
array(
'text'=>"MFC MONTLUCON FIGHT CLUB
30330919908M211076
UFSC - KICK BOXING et DA
PHOTO
TRESORIER
Signature
PHILIPPONFabien
310 Chemin des Verpis
03310 VILLEBRET
M24/09/1978né le
Je soussigné, certifie que M
Porteur de cette licence, ne présente aucune contre
indication à la pratique sportive de la discipline
susvisée (Article L 231-3 du Code du Sport)
Fait à                                              Le 
Délivrée le : 01/10/2026LICENCE FFST N° M211076
 Docteur :
 Cachet et signature : 
M M F C 03310F.PHILIPPON2026-2027 ULicence : M211076",
'expect'=>array('N° FFST'=>'M211076','Nom'=>'PHILIPPON','Prénom'=>'Fabien','Date de naissance'=>'24/09/1978','Sexe'=>'M','Club'=>'MFC MONTLUCON FIGHT CLUB','Fonction'=>'TRESORIER','Code postal'=>'03310','Ville'=>'VILLEBRET','Délivrée le'=>'01/10/2026')
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
