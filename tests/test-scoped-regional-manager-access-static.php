<?php
$src=file_get_contents(dirname(__DIR__).'/includes/permissions/ufsc-licences-permissions.php');
$checks=array(
 'scoped regional managers' => strpos($src,"'ufsc_region_manager'")!==false,
 'no accidental global bypass' => strpos($src,'! ufsc_lc_is_scoped_regional_manager() && ufsc_lc_user_can( UFSC_LC_CAP_ALL_REGIONS )')!==false,
 'canonical assignment' => strpos($src,'ufsc_get_user_regions( $user_id )')!==false,
 'strict unmatched region rejection' => strpos($src,'return false;')!==false,
 'competition object scope preserved' => strpos($src,'ufsc_lc_current_user_can_access_competition')!==false,
 'licence object scope preserved' => strpos($src,'ufsc_lc_current_user_can_access_license')!==false,
);
foreach($checks as $k=>$v){echo($v?'PASS ':'FAIL ').$k.PHP_EOL;}
exit(in_array(false,$checks,true)?1:0);
