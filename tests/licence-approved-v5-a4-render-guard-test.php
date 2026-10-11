<?php
/** Guard approved V5 geometry, literal placeholders and pre-save PDF validation. */
$root = dirname( __DIR__ );
$template = file_get_contents( $root . '/templates/licence-sportive-a6.html' );
$generator = file_get_contents( $root . '/includes/class-ufsc-lc-licence-pdf-generator.php' );
$checks = array(
 'single A4 page' => strpos( $template, '@page { size: A4 portrait; margin: 0; }' ) !== false,
 'two stacked faces' => strpos( $template, '.recto { top:9mm; }' ) !== false && strpos( $template, '.verso { top:155mm; }' ) !== false,
 'both faces within print area' => strpos( $template, 'height:132mm;' ) !== false,
 'no page break' => strpos( $template, 'page-break-after:always' ) === false,
 'approved neutral medical certificate' => strpos( $template, 'Je soussigné(e), docteur en médecine' ) !== false,
 'dynamic club and disciplines' => strpos( $template, '{{club}}' ) !== false && strpos( $template, '{{discipline}}' ) !== false,
 'federal licence numbers retained' => strpos( $template, '{{numero_licence_ufsc}}' ) !== false && strpos( $template, '{{numero_licence_ffst}}' ) !== false,
 'Dompdf output checked before attachment' => strpos( $generator, '1 !== $page_count' ) !== false && strpos( $generator, '$output = $dompdf->output();' ) !== false,
 'A4 dimensions checked' => strpos( $generator, '595.28' ) !== false && strpos( $generator, '841.89' ) !== false,
 'PDF failure preserves previous association' => strpos( $generator, 'ufsc_lc_pdf_a4_validation_failed' ) !== false,
 'template versioned' => strpos( $generator, 'ufsc-document-v16-approved-v5-a4' ) !== false,
 'historical attachments preserved by default' => strpos( $generator, "'ufsc_lc_license_pdf_delete_previous_generated_attachment', false" ) !== false,
);
foreach ( $checks as $name => $ok ) {
 echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . PHP_EOL;
}
exit( in_array( false, $checks, true ) ? 1 : 0 );
