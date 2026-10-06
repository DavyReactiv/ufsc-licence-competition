<?php
/**
 * Guard the corrective integration after PR #505:
 * validated licence => premium V12 HTML template,
 * generated UFSC number => persistent and unique,
 * imported FFST number => displayed separately,
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
	"const TEMPLATE_VERSION = 'ufsc-card-v12-premium-a6';",
	'ensure_ufsc_license_number',
	"UFSC-%04d-%06d",
	"GET_LOCK",
	"RELEASE_LOCK",
	"ufsc_license_number",
	"ufsc_lc_ffst_import_synced",
	'resolve_ffst_license_number',
	"numero_licence_ffst",
	"EN ATTENTE D’ATTRIBUTION",
	"templates/licence-sportive-a6.html",
	"{{numero_licence_ufsc}}",
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

if ( false === strpos( $html, 'N° LICENCE UFSC' ) || false === strpos( $html, '{{numero_licence_ufsc}}' ) ) {
	$errors[] = 'premium V12 template must display the generated UFSC number';
}

if ( false === strpos( $html, 'N° LICENCE FFST' ) || false === strpos( $html, '{{numero_licence_ffst}}' ) ) {
	$errors[] = 'premium V12 template must display the imported FFST number separately';
}

if ( false === strpos( $html, 'CERTIFICATION MÉDICALE — TEXTE SOURCE FFST' ) || false === strpos( $html, 'N° MÉDECIN / RPPS' ) ) {
	$errors[] = 'premium V12 template must preserve the medical certification and doctor/RPPS area';
}

if ( false === strpos( $html, 'circle-blue' ) || false === strpos( $html, 'circle-red' ) ) {
	$errors[] = 'premium V12 template must preserve the validated blue/red circle design';
}

if ( $errors ) {
	fwrite( STDERR, "Premium FFST PDF integration guard failed:\n - " . implode( "\n - ", $errors ) . "\n" );
	exit( 1 );
}

echo "Premium FFST PDF integration guard: OK\n";
