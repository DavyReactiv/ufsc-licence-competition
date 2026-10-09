<?php
$s=file_get_contents(dirname(__DIR__).'/includes/admin/class-ufsc-lc-settings-page.php');
$checks=array(
'admin permission'=>strpos($s,"current_user_can( 'manage_options' )")!==false,
'original settings API preserved'=>strpos($s,'register_setting(')!==false,
'old settings retained'=>strpos($s,'sync_legacy_options')!==false,
'only changed keys logged'=>strpos($s,'array_key_exists( $key, $old )')!==false,
'bounded history'=>strpos($s,'array_slice( $history, -40 )')!==false,
'audit values not persisted'=>strpos($s,"'keys' => array_values( $keys )")!==false,
);
foreach($checks as $name=>$ok){echo($ok?'PASS ':'FAIL ').$name.PHP_EOL;}
exit(in_array(false,$checks,true)?1:0);
