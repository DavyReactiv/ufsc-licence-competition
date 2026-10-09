<?php
/** Static regression gate: responsible accounts never gain write privileges. */
$src=file_get_contents(dirname(__DIR__).'/includes/permissions/ufsc-licences-permissions.php');
$checks=array(
 'canonical UFSC Gestion profile meta' => strpos($src,"'_ufsc_readonly_access_profile'")!==false,
 'regional and national consultation profiles' => strpos($src,"'regional_readonly', 'national_readonly'")!==false,
 'viewer role required' => strpos($src,"'ufsc_region_viewer'")!==false,
 'both modules readable' => strpos($src,'UFSC_LC_CAP_LICENCES_READ, UFSC_LC_CAP_COMPETITIONS_READ')!==false,
 'managed readonly cannot write' => strpos($src,'return ufsc_lc_responsable_read_capability( $capability );')!==false,
 'WordPress primitive caps remain unchanged' => strpos($src,'\$allcaps[ UFSC_LC_CAP_ALL_REGIONS ] = false;')!==false,
 'existing regional scope retained' => strpos($src,"ufsc_user_can_access_region( $region, $user_id )")!==false,
);
foreach($checks as $name=>$ok){echo($ok?'PASS ':'FAIL ').$name.PHP_EOL;}
exit(in_array(false,$checks,true)?1:0);
