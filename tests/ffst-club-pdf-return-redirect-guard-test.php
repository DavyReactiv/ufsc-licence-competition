<?php
/** Guard the club-only PDF return URL; no licence data is changed. */
$src=file_get_contents(dirname(__DIR__).'/includes/class-ufsc-club-licences-shortcode.php');
$checks=array(
    'Club buttons carry originating return URL' => strpos($src,"'redirect_to' => get_permalink( get_queried_object_id() )")!==false,
    'Return URL validated as local' => strpos($src,"wp_validate_redirect( \$redirect, '' )")!==false,
    'Root/homepage is not used as the PDF result screen' => strpos($src,"'/' === \$path")!==false,
    'Recovery link points to club licence page' => strpos($src,"get_page_by_path( 'licences-ufsc-ffst-documents-numeros' )")!==false,
    'Old notices stripped from return URL' => strpos($src,"remove_query_arg( array( 'ufsc_club_pdf_status', 'ufsc_club_pdf_message' )")!==false,
    'Existing nonce and club scope controls preserved' => strpos($src,"ufsc_lc_generate_club_pdf_")!==false
        && strpos($src,'validate_scope_access( $club_id )')!==false,
);
foreach($checks as $name=>$ok){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)exit(1);}
