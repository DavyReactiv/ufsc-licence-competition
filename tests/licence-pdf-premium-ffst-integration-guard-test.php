<?php
/**
 * Guard the corrective integration after PR #505:
 * validated licence => premium V14 A4 HTML template,
 * UFSC number => read canonically from UFSC Gestion,
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
	"const TEMPLATE_VERSION = 'ufsc-document-v15-a4-two-faces-one-sheet';",
	"UFSC_Identifier_Resolver::read( \$licence, 'licence_ufsc' )",
	"return \$record_id > 0 ? (string) \$record_id : '';",
	"'licence-ufsc'",
	"'first_name'",
	"'last_name'",
	"ufsc_lc_ffst_import_synced",
	'resolve_ffst_license_number',
	"numero_licence_ffst",
	"EN ATTENTE D’ATTRIBUTION",
	"templates/licence-sportive-a6.html",
	"{{numero_licence_ufsc}}",
	"{{numero_licence_ffst}}",
	"{{ffst_missing_class}}",
	'resolve_discipline',
	"'discipline_principale'",
	"'source_discipline'",
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

if ( false !== strpos( $php, "UFSC-%04d-%06d" ) || false !== strpos( $php, "GET_LOCK" ) || false !== strpos( $php, "ufsc_lc_number_sequence_" ) ) {
	$errors[] = 'the competition add-on must never allocate a parallel UFSC number';
}

if ( false === strpos( $html, 'N° LICENCE UFSC' ) || false === strpos( $html, '{{numero_licence_ufsc}}' ) ) {
	$errors[] = 'premium V14 template must display the canonical UFSC number';
}

if ( false === strpos( $html, 'N° LICENCE FFST' ) || false === strpos( $html, '{{numero_licence_ffst}}' ) ) {
	$errors[] = 'premium V14 template must display the imported FFST number separately';
}

if ( false === strpos( $html, 'CERTIFICATION MÉDICALE — TEXTE SOURCE FFST' ) || false === strpos( $html, 'N° MÉDECIN / RPPS' ) ) {
	$errors[] = 'premium V14 template must preserve the medical certification and doctor/RPPS area';
}

if ( false === strpos( $html, 'circle-blue' ) || false === strpos( $html, 'circle-red' ) ) {
	$errors[] = 'premium V14 template must preserve the validated blue/red circle design';
}

if ( $errors ) {
	fwrite( STDERR, "Premium FFST PDF integration guard failed:\n - " . implode( "\n - ", $errors ) . "\n" );
	exit( 1 );
}

echo "Premium FFST PDF integration guard: OK\n";
