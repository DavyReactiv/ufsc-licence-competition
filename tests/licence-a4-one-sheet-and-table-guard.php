<?php
/** Layout regression guard: single A4 sheet, two faces and readable licence actions. */
$root = dirname( __DIR__ );
$pdf = file_get_contents( $root . '/templates/licence-sportive-a6.html' );
$gen = file_get_contents( $root . '/includes/class-ufsc-lc-licence-pdf-generator.php' );
$front = file_get_contents( $root . '/includes/class-ufsc-club-licences-shortcode.php' );
$checks = array(
 'A4 portrait page' => false !== strpos( $pdf, '@page { size: A4 portrait; margin: 0; }' ),
 'two faces stacked inside the sheet' => false !== strpos( $pdf, '.recto { top:9mm; }' ) && false !== strpos( $pdf, '.verso { top:155mm; }' ),
 'no second page break' => false === strpos( $pdf, 'page-break-after:always' ),
 'top and bottom page fit' => false !== strpos( $pdf, 'height:132mm;' ) && false !== strpos( $pdf, 'top:155mm;' ),
 'PDF paper A4' => false !== strpos( $gen, "setPaper( 'A4', 'portrait' )" ),
 'new template version' => false !== strpos( $gen, 'ufsc-document-v16-approved-v5-a4' ),
 'printable and viewable data preserved' => false !== strpos( $pdf, '{{numero_licence_ufsc}}' ) && false !== strpos( $pdf, '{{numero_licence_ffst}}' ) && false !== strpos( $pdf, '{{nom}}' ),
 'table has native PDF column width' => false !== strpos( $front, 'td:nth-child(9){min-width:265px;width:25%}' ),
 'PDF actions have width' => false !== strpos( $front, 'grid-template-columns:repeat(2,minmax(108px,1fr))' ),
 'View route preserved' => false !== strpos( $front, "get_pdf_action_url( 'view', \$item->id )" ),
 'Download route preserved' => false !== strpos( $front, "get_pdf_action_url( 'download', \$item->id )" ),
 'generated PDF historic copies preserved' => false !== strpos( $gen, "'ufsc_lc_license_pdf_delete_previous_generated_attachment', false" ),
);
foreach ( $checks as $label => $passed ) {
    echo ( $passed ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
}
exit( in_array( false, $checks, true ) ? 1 : 0 );
