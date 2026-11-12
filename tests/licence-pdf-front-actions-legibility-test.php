<?php
/**
 * Regression guards for number legibility and PDF action navigation.
 * UI-only changes must not touch licence status, quotas, or master tables.
 */
$root = dirname( __DIR__ );
$template = file_get_contents( $root . '/templates/licence-sportive-a6.html' );
$front = file_get_contents( $root . '/includes/class-ufsc-club-licences-shortcode.php' );
$generator = file_get_contents( $root . '/includes/class-ufsc-lc-licence-pdf-generator.php' );
$check = static function ( $yes, $name ) {
    if ( ! $yes ) { fwrite( STDERR, "FAIL: {$name}\n" ); exit( 1 ); }
};
$check( false !== strpos( $template, 'top:113.4mm; height:11.5mm' ), 'Licence numbers fit above recto footer' );
$check( false !== strpos( $template, 'font-size:11.5pt; line-height:1.12;' ), 'Number values have explicit printable line metrics' );
$check( false !== strpos( $template, '{{numero_licence_ufsc}}' ), 'UFSC number binding unchanged' );
$check( false !== strpos( $template, '{{numero_licence_ffst}}' ), 'FFST number binding unchanged' );
$check( false !== strpos( $front, 'grid-template-columns:repeat(2,minmax(0,1fr))' ), 'Action buttons never force column overflow' );
$check( false !== strpos( $front, 'font-size:11px !important' ), 'Compact front action buttons' );
$check( false !== strpos( $front, 'target="_blank" rel="noopener noreferrer"' ), 'PDF actions use secure new tabs' );
$check( substr_count( $front, 'target="_blank" rel="noopener noreferrer"' ) >= 3, 'View download and generate all open new tabs' );
$check( false !== strpos( $front, "get_pdf_action_url( 'view', \$item->id )" ), 'View remains linked to correct licence' );
$check( false !== strpos( $front, "get_pdf_action_url( 'download', \$item->id )" ), 'Download remains linked to correct licence' );
$check( false !== strpos( $front, "get_generate_pdf_url( \$item->id )" ), 'Generation remains linked to correct licence' );
$check( false !== strpos( $generator, "ufsc-document-v18-section-spacing-a4" ), 'New template revision is used by future PDF generations' );
echo "Licence PDF legibility and front controls regression OK\n";
