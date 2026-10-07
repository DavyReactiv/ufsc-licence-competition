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
	'licence unified width'        => array( $licences, 'width:100%' ),
	'licence status badges'        => array( $licences, 'ufsc-licence-status--' ),
	'licence premium table header' => array( $licences, 'linear-gradient(180deg,#283474,#20265f)' ),
	'competition premium hub'      => array( $competitions, 'ufsc-competitions-hub__intro' ),
	'competition premium empty'    => array( $competitions, 'ufsc-competitions-empty' ),
	'competition count card'       => array( $competitions, 'ufsc-competitions-hub__count' ),
	'competition premium palette'  => array( $css, '--ufsc-red: #df1737' ),
	'competition responsive table' => array( $css, 'min-width: 920px' ),
);

foreach ( $checks as $label => $check ) {
	if ( false === strpos( $check[0], $check[1] ) ) {
		$errors[] = $label;
	}
}

$hub_block_ok = preg_match(
	'/\.ufsc-competitions-hub\s*\{[^}]*\bwidth\s*:\s*100%\s*;/s',
	$css
);
if ( 1 !== $hub_block_ok ) {
	$errors[] = 'competition unified width';
}


if ( false !== strpos( $licences, 'transform:translateX(-50%)' ) || false !== strpos( $licences, 'calc(100vw - 56px)' ) ) {
	$errors[] = 'licence UI must not break out of the page container';
}

if ( false !== strpos( $css, 'transform:translateX(-50%)' ) || false !== strpos( $css, 'calc(100vw - 48px)' ) ) {
	$errors[] = 'competition UI must not break out of the page container';
}

if ( 1 !== substr_count( $css, '.ufsc-competitions-hub {' ) ) {
	$errors[] = 'competition hub must have one canonical style definition';
}

if ( false !== stripos( $licences, '>ASPTT<' ) || false !== stripos( $licences, 'ASPTT)' ) ) {
	$errors[] = 'public club licence UI must not display ASPTT labels';
}

if ( false === strpos( $licences, 'ufsc-licence-ffst' ) ) {
	$errors[] = 'FFST number must keep its dedicated visual treatment';
}

if ( false === strpos( $licences, 'Nom, prénom, N° UFSC ou N° FFST' ) ) {
	$errors[] = 'UFSC/FFST-aware search placeholder must remain explicit';
}
if ( false === strpos( $licences, 'ufsc-licence-ufsc' ) ) {
	$errors[] = 'UFSC number must have a dedicated visual treatment';
}
if ( false === strpos( $licences, 'grid-template-columns:repeat(2,minmax(96px,1fr))' ) ) {
	$errors[] = 'PDF actions must use a balanced two-column desktop layout';
}
if ( false === strpos( $licences, 'content:attr(data-label)' ) ) {
	$errors[] = 'licence table must switch to labelled responsive cards on smaller screens';
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
