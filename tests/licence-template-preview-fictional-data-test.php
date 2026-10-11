<?php
/** Static guard: admin preview contains no production identity; generator unchanged. */
$source = file_get_contents( dirname(__DIR__) . '/includes/class-ufsc-lc-licence-pdf-generator.php' );
$start = strpos( $source, 'private static function get_template_preview_demo_data()' );
$end = strpos( $source, 'public static function render_admin_page()', $start );
$preview = $start !== false && $end !== false ? substr( $source, $start, $end - $start ) : '';
$checks = array(
 'dedicated preview dataset' => strpos( $source, '$sample = self::get_template_preview_demo_data();' ) !== false,
 'fictional identity' => strpos( $preview, "'last_name' => 'EXEMPLE'" ) !== false,
 'fictional club' => strpos( $preview, "'club_name' => 'Club Démonstration UFSC'" ) !== false,
 'fictional identifiers' => strpos( $preview, "'ufsc_number' => 'UFSC-DEMO-000001'" ) !== false && strpos( $preview, "'ffst_number' => 'FFST-DEMO-000000'" ) !== false,
 'explicit warning' => strpos( $source, 'DONNÉES 100 % FICTIVES' ) !== false,
 'real name absent' => strpos( $preview, 'SAUTRON' ) === false && strpos( $preview, 'Jean-Bernard' ) === false,
 'real address absent' => strpos( $preview, 'Ravine des Cabris' ) === false,
 'real identifiers absent' => strpos( $preview, 'M211036' ) === false,
 'preview still uses existing template' => strpos( $source, 'self::build_pdf_html( $sample )' ) !== false,
);
foreach( $checks as $name => $ok ) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; }
exit( in_array( false, $checks, true ) ? 1 : 0 );
