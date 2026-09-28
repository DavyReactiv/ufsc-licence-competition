<?php
/**
 * Guard premium club front UI for licences and competitions.
 */

$root        = dirname( __DIR__ );
$licences    = file_get_contents( $root . '/includes/class-ufsc-club-licences-shortcode.php' );
$competitions = file_get_contents( $root . '/includes/competitions/Front/Shortcodes/CompetitionsListShortcode.php' );
$css         = file_get_contents( $root . '/includes/competitions/assets/front.css' );

$errors = array();

$checks = array(
	'licence premium stats'        => array( $licences, 'ufsc-licence-stat:before' ),
	'licence wide desktop layout'  => array( $licences, 'calc(100vw - 56px)' ),
	'licence status badges'        => array( $licences, 'ufsc-licence-status--' ),
	'licence premium table header' => array( $licences, 'linear-gradient(180deg,#283474,#20265f)' ),
	'competition premium hub'      => array( $competitions, 'ufsc-competitions-hub__intro' ),
	'competition premium empty'    => array( $competitions, 'ufsc-competitions-empty' ),
	'competition count card'       => array( $competitions, 'ufsc-competitions-hub__count' ),
	'competition wide layout'      => array( $css, 'calc(100vw - 48px)' ),
	'competition premium palette'  => array( $css, '--ufsc-red:#df1737' ),
	'competition responsive table' => array( $css, 'min-width:980px' ),
);

foreach ( $checks as $label => $check ) {
	if ( false === strpos( $check[0], $check[1] ) ) {
		$errors[] = $label;
	}
}

if ( false === strpos( $licences, 'ufsc_lc_generate_club_pdf' ) ) {
	$errors[] = 'PDF generation action must remain available';
}

if ( false === strpos( $competitions, 'can_register' ) ) {
	$errors[] = 'competition access rules must remain present';
}

if ( $errors ) {
	fwrite( STDERR, "Premium front UI guard failed:\n - " . implode( "\n - ", $errors ) . "\n" );
	exit( 1 );
}

echo "Premium front UI guard: OK\n";
