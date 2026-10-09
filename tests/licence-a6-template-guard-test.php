<?php
/**
 * Static guard for the official A4 portrait licence HTML template.
 * V14 keeps the historical institutional/legal content while displaying the
 * generated UFSC number separately from the imported FFST number.
 */

$template = dirname( __DIR__ ) . '/templates/licence-sportive-a6.html';

if ( ! is_file( $template ) ) {
	fwrite( STDERR, "Missing licence template: {$template}\n" );
	exit( 1 );
}

$html = file_get_contents( $template );

$required = array(
	'@page { size: A4 portrait; margin: 0; }',
	'.page{position:relative;width:210mm;height:296mm;',
	'class="page recto"',
	'class="page verso"',
	'LICENCE SPORTIVE',
	'INFORMATIONS DE LICENCE',
	'Licence UFSC / FFST',
	'N° LICENCE UFSC',
	'N° LICENCE FFST',
	'{{numero_licence_ufsc}}',
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
	'{{nom}}',
	'{{prenom}}',
	'{{date_naissance}}',
	'{{categorie}}',
	'{{club}}',
	'{{region}}',
	'{{sexe}}',
	'{{adresse}}',
	'{{code_postal}}',
	'{{ville}}',
	'{{fonction}}',
	'{{discipline}}',
	'{{references_federales}}',
	'{{date_delivrance_ffst}}',
	'{{code_club_source}}',
	'{{reference_titulaire}}',
	'{{code_source}}',
	'CERTIFICATION MÉDICALE — TEXTE SOURCE FFST',
	'N° MÉDECIN / RPPS',
	'CACHET ET SIGNATURE DU MÉDECIN',
	'circle-blue',
	'circle-red',
);

$missing = array();
foreach ( $required as $needle ) {
	if ( false === strpos( $html, $needle ) ) {
		$missing[] = $needle;
	}
}

if ( 2 !== substr_count( $html, 'class="institutional-footer"' ) ) {
	$missing[] = 'footer institutionnel recto + verso';
}

if ( false !== stripos( $html, 'ASPTT' ) ) {
	$missing[] = 'absence de référence ASPTT';
}

// Dompdf safety: the PDF template must not rely on CSS Grid/Flex.
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
	fwrite( STDERR, "Licence A4 template guard failed:\n - " . implode( "\n - ", $missing ) . "\n" );
	exit( 1 );
}

echo "Licence A4 template guard: OK\n";
