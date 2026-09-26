<?php
/**
 * Guard secure FFST number editing in licence admin.
 */

$admin_path = dirname( __DIR__ ) . '/includes/admin/class-ufsc-licences-admin.php';
$admin      = file_get_contents( $admin_path );

$required = array(
	"admin_post_ufsc_lc_update_ffst_number",
	"handle_update_ffst_number",
	"numero_licence_ffst",
	"ufsc_lc_ffst_nonce",
	"Enregistrer le N° FFST",
	"N° licence FFST mis à jour.",
	"SHOW COLUMNS FROM",
	"ufsc_lc_safe_enforce_object_scope",
);

$errors = array();

foreach ( $required as $needle ) {
	if ( false === strpos( $admin, $needle ) ) {
		$errors[] = "missing FFST admin behaviour: {$needle}";
	}
}

if ( false === strpos( $admin, "maxlength="64"" ) ) {
	$errors[] = 'FFST input maxlength must be 64';
}

if ( false === strpos( $admin, "UFSC_LC_Capabilities::user_can_edit()" ) ) {
	$errors[] = 'FFST edit must require edit capability';
}

if ( $errors ) {
	fwrite( STDERR, "FFST admin edit guard failed:\n - " . implode( "\n - ", $errors ) . "\n" );
	exit( 1 );
}

echo "FFST admin edit guard: OK\n";
