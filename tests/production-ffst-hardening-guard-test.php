<?php
/**
 * Production guard for the UFSC / FFST licence workflow.
 */

$root     = dirname( __DIR__ );
$list     = file_get_contents( $root . '/includes/admin/class-ufsc-licences-list-table.php' );
$admin    = file_get_contents( $root . '/includes/admin/class-ufsc-licences-admin.php' );
$front    = file_get_contents( $root . '/includes/class-ufsc-club-licences-shortcode.php' );
$settings = file_get_contents( $root . '/includes/admin/class-ufsc-lc-settings-page.php' );
$template = file_get_contents( $root . '/templates/licence-sportive-a6.html' );
$build    = file_get_contents( $root . '/.github/workflows/build-production-plugin.yml' );
$composer = file_get_contents( $root . '/composer.json' );

$errors = array();

$checks = array(
	'admin canonical season filter' => array( $list, 'get_season_end_year_sql' ),
	'admin inline FFST editor'      => array( $list, 'ufsc-lc-inline-ffst-save' ),
	'admin inline FFST AJAX'        => array( $admin, 'wp_ajax_ufsc_lc_update_ffst_inline' ),
	'front UFSC document source'    => array( $front, "const SOURCE = 'UFSC';" ),
	'front PDF generation'          => array( $front, 'ufsc_lc_generate_club_pdf' ),
	'front FFST label'              => array( $front, 'N° FFST' ),
	'FFST settings tab'             => array( $settings, 'FFST & imports' ),
	'professional settings cards'  => array( $settings, 'ufsc-lc-settings-grid' ),
	'recto FFST logo'               => array( $template, 'class="recto-logo-ffst"' ),
	'recto FFST logo variable'      => array( $template, 'src="{{logo_ffst}}"' ),
	'production Composer install'   => array( $build, 'composer install --no-dev' ),
	'production Dompdf check'       => array( $build, "class_exists('Dompdf" ),
	'Dompdf dependency'             => array( $composer, 'dompdf/dompdf' ),
);

foreach ( $checks as $label => $check ) {
	if ( false === strpos( $check[0], $check[1] ) ) {
		$errors[] = $label;
	}
}

if ( false !== strpos( $front, "const SOURCE = 'ASPTT';" ) ) {
	$errors[] = 'front must not use ASPTT as current document source';
}

if ( false !== strpos( $front, "esc_html_e( 'N° ASPTT'" ) ) {
	$errors[] = 'front must not expose N° ASPTT';
}

if ( false !== strpos( $settings, "__( 'Import ASPTT'" ) ) {
	$errors[] = 'settings must not expose Import ASPTT';
}

if ( false !== strpos( $template, '<div class="ffst-left">FFST</div>' ) ) {
	$errors[] = 'recto must use the FFST logo instead of plain FFST text';
}

if ( $errors ) {
	fwrite( STDERR, "Production FFST hardening guard failed:\n - " . implode( "\n - ", $errors ) . "\n" );
	exit( 1 );
}

echo "Production FFST hardening guard: OK\n";
