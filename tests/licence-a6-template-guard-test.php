<?php
/**
 * Static guard for the official A6 licence HTML template.
 */

$template = dirname( __DIR__ ) . '/templates/licence-sportive-a6.html';

if ( ! is_file( $template ) ) {
	fwrite( STDERR, "Missing licence template: {$template}\n" );
	exit( 1 );
}

$html = file_get_contents( $template );

$required = array(
	'@page{size:A6 landscape;margin:0}',
	'.page{position:relative;width:148mm;height:104.5mm;',
	'class="page recto"',
	'class="page verso"',
	'LICENCE UFSC / FFST',
	'{{numero_licence_ffst}}',
	'{{ffst_missing_class}}',
	'Numéro associé à la couverture d’assurance FFST',
	'7 B chemin de la Combette, 13210 Saint-Rémy-de-Provence - France',
	'RNA : W596010570',
	'06 27 85 80 20',
	'contact@ufsc-france.org',
	'www.ufsc-france.fr',
	'{{logo_ufsc}}',
	'{{logo_ffst}}',
	'{{photo_licencie}}',
	'{{saison}}',
	'{{nom}} {{prenom}}',
	'{{date_naissance}}',
	'{{categorie}}',
	'{{club}}',
	'{{region}}',
);

$missing = array();
foreach ( $required as $needle ) {
	if ( false === strpos( $html, $needle ) ) {
		$missing[] = $needle;
	}
}

if ( 2 !== substr_count( $html, 'class="ufsc-legal-footer"' ) ) {
	$missing[] = 'footer institutionnel recto + verso';
}

if ( false !== strpos( $html, '{{numero_licence_ufsc}}' ) ) {
	$missing[] = 'le numéro UFSC ne doit plus être affiché séparément sur la carte';
}

if ( false !== stripos( $html, 'ASPTT' ) ) {
	$missing[] = 'absence de référence ASPTT';
}

if ( false !== strpos( $html, 'display:grid' ) || false !== strpos( $html, 'display:flex' ) ) {
	$missing[] = 'le gabarit PDF ne doit pas dépendre de CSS Grid/Flex';
}

if ( false !== strpos( $html, 'margin:10mm auto' ) ) {
	$missing[] = 'aucune marge extérieure ne doit créer de page blanche Dompdf';
}

if ( 1 !== substr_count( $html, 'page-break-after:always' ) ) {
	$missing[] = 'un seul saut de page recto -> verso est attendu';
}

if ( $missing ) {
	fwrite( STDERR, "Licence A6 template guard failed:\n - " . implode( "\n - ", $missing ) . "\n" );
	exit( 1 );
}

echo "Licence A6 template guard: OK\n";
