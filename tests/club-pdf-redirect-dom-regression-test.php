<?php
/** Static regression for club PDF error reporting and safe redirect (PHP 7.4+). */
$root = dirname( __DIR__ );
$club = file_get_contents( $root . '/includes/class-ufsc-club-licences-shortcode.php' );
$pdf = file_get_contents( $root . '/includes/class-ufsc-lc-licence-pdf-generator.php' );
$start = strpos( $pdf, 'public static function maybe_generate_for_licence(' );
$end = strpos( $pdf, 'private static function build_template_data', $start );
$generator = substr( $pdf, $start, $end - $start );
$checks = array(
    'URL from rendering shortcode' => strpos( $club, "'redirect_to' => \$return_url" ) !== false,
    'Only same-site redirects' => strpos( $club, "wp_validate_redirect( esc_url_raw(" ) !== false,
    'Root redirect not allowed' => strpos( $club, "'/' === \$redirect_path" ) !== false,
    'No homepage fallback' => strpos( $club, "wp_get_referer() ? wp_get_referer() : home_url( '/' )" ) === false,
    'Existing club access scope' => strpos( $club, 'validate_scope_access( $club_id )' ) !== false,
    'Existing per-licence nonce' => strpos( $club, "'ufsc_lc_generate_club_pdf_' . absint( \$licence_id )" ) !== false,
    'DOM preflight before Dompdf' => strpos( $generator, "class_exists( 'DOMImplementation' )" ) !== false
        && strpos( $generator, "'ufsc_lc_pdf_dom_missing'" ) !== false,
    'Throwable caught in generator' => strpos( $generator, 'catch ( Throwable $exception )' ) !== false,
    'Document state unaffected by redirect' => strpos( $club, 'update_post_meta(' ) === false,
);
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) exit(1);
}
