<?php
/**
 * Guard the corrective integration after PR #505:
 * validated licence => premium HTML template,
 * displayed number => FFST,
 * missing FFST => non-blocking pending state.
 */

$generator = dirname( __DIR__ ) . '/includes/class-ufsc-lc-licence-pdf-generator.php';
$template  = dirname( __DIR__ ) . '/templates/licence-sportive-a6.html';

if ( ! is_file( $generator ) || ! is_file( $template ) ) {
	fwrite( STDERR, "Missing PDF generator or A6 template.\n" );
	exit( 1 );
}

$php  = file_get_contents( $generator );
$html = file_get_contents( $template );

$required_php = array(
	"const TEMPLATE_VERSION = 'ufsc-card-v3-dompdf-a6';",
	'resolve_ffst_license_number',
	"numero_licence_ffst",
	"EN ATTENTE D’ATTRIBUTION",
	"templates/licence-sportive-a6.html",
	"{{numero_licence_ffst}}",
	"{{ffst_missing_class}}",
	"ffst_display_number",
	"defaultFont', 'DejaVu Sans",
	"defaultMediaType', 'print",
);

$errors = array();

foreach ( $required_php as $needle ) {
	if ( false === strpos( $php, $needle ) ) {
		$errors[] = "generator missing: {$needle}";
	}
}

if ( false !== strpos( $php, "Le numéro de licence UFSC doit être attribué avant la génération du PDF." ) ) {
	$errors[] = 'generation must not be blocked by a missing UFSC display number';
}

if ( false === strpos( $html, 'LICENCE UFSC / FFST' ) || false === strpos( $html, '{{numero_licence_ffst}}' ) ) {
	$errors[] = 'premium template must display the FFST number as LICENCE UFSC / FFST';
}

if ( false !== strpos( $html, '{{numero_licence_ufsc}}' ) ) {
	$errors[] = 'premium template must not display a separate UFSC number';
}

if ( $errors ) {
	fwrite( STDERR, "Premium FFST PDF integration guard failed:\n - " . implode( "\n - ", $errors ) . "\n" );
	exit( 1 );
}

echo "Premium FFST PDF integration guard: OK\n";
