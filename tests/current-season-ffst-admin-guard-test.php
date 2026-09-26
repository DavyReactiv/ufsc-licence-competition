<?php
/**
 * Guard admin behaviour for current UFSC/FFST season and PDF workflow.
 */

$root      = dirname( __DIR__ );
$list      = file_get_contents( $root . '/includes/admin/class-ufsc-licences-list-table.php' );
$admin     = file_get_contents( $root . '/includes/admin/class-ufsc-licences-admin.php' );
$importer  = file_get_contents( $root . '/includes/class-ufsc-asptt-importer.php' );
$generator = file_get_contents( $root . '/includes/class-ufsc-lc-licence-pdf-generator.php' );

$errors = array();

$required_list = array(
	"'ffst_number'     => __( 'N° FFST'",
	'$season_end_year = isset( $_REQUEST[\'season_end_year\'] ) ? sanitize_text_field( wp_unslash( $_REQUEST[\'season_end_year\'] ) ) : $defaults[\'season_end_year\'];',
	'$args[\'season_end_year\'] = $active_season;',
	"case 'actions':",
	"'action'     => 'view'",
	"array( 'UFSC' )",
);

foreach ( $required_list as $needle ) {
	if ( false === strpos( $list, $needle ) ) {
		$errors[] = "licence list missing: {$needle}";
	}
}

if ( false !== strpos( $list, "__( 'N° ASPTT'" ) ) {
	$errors[] = 'ASPTT column label must not be exposed in current licence list';
}

if ( false !== strpos( $importer, 'add_action( \'admin_menu\', array( $this, \'register_admin_menu\' ) );' ) ) {
	$errors[] = 'legacy ASPTT import menu must remain hidden';
}

$required_admin = array(
	'if ( \'view\' === $action )',
	"render_licence_view_page",
	"ufsc_lc_generate_licence_pdf",
	"Générer le PDF",
	"Régénérer le PDF",
	"Voir / télécharger le PDF",
);

foreach ( $required_admin as $needle ) {
	if ( false === strpos( $admin, $needle ) ) {
		$errors[] = "licence detail missing: {$needle}";
	}
}

$required_generator = array(
	'self::build_pdf_html( $sample )',
	"Gabarit automatique de licence UFSC / FFST",
	"Le document utilise le gabarit premium A6 recto/verso",
	"'ffst_missing_class'",
);

foreach ( $required_generator as $needle ) {
	if ( false === strpos( $generator, $needle ) ) {
		$errors[] = "PDF generator missing: {$needle}";
	}
}

if ( $errors ) {
	fwrite( STDERR, "Current-season / FFST admin guard failed:\n - " . implode( "\n - ", $errors ) . "\n" );
	exit( 1 );
}

echo "Current-season / FFST admin guard: OK\n";
