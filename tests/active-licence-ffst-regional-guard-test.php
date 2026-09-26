<?php
/**
 * Static production guards for the UFSC Gestion -> Competition licence bridge.
 * Runs without WordPress to catch regressions in CI.
 */

$root = dirname( __DIR__ );
$bridge_path = $root . '/includes/competitions/Front/Licenses/LicenseBridge.php';
$caps_path = $root . '/includes/class-ufsc-lc-capabilities.php';
$migrations_path = $root . '/includes/class-ufsc-lc-licence-migrations.php';

$files = array( $bridge_path, $caps_path, $migrations_path );
foreach ( $files as $file ) {
	if ( ! is_file( $file ) ) {
		fwrite( STDERR, "Missing required file: {$file}\n" );
		exit( 1 );
	}
}

$bridge = file_get_contents( $bridge_path );
$caps = file_get_contents( $caps_path );
$migrations = file_get_contents( $migrations_path );

$assertions = array(
	'bridge uses canonical FFST number' => false !== strpos( $bridge, 'numero_licence_ffst' ) && false !== strpos( $bridge, 'ffst_number' ),
	'bridge defaults to validated UFSC licences only' => false !== strpos( $bridge, "ufsc_competitions_eligible_license_statuses', array( 'valide' )" ),
	'bridge fails closed when status column is missing' => false !== strpos( $bridge, "license_search_missing_status_column" ),
	'bridge no longer exposes legacy federation number' => false === stripos( $bridge, 'asptt' ),
	'regional role exists' => false !== strpos( $caps, "REGIONAL_ROLE = 'ufsc_regional_manager'" ),
	'regional role never receives all-region capability' => false !== strpos( $caps, 'remove_cap( self::SCOPE_ALL_REGIONS_CAPABILITY )' ),
	'master schema expectation uses FFST identifier' => false !== strpos( $migrations, "'numero_licence_ffst'" ),
);

$failed = array();
foreach ( $assertions as $label => $ok ) {
	if ( ! $ok ) {
		$failed[] = $label;
	}
}

if ( $failed ) {
	fwrite( STDERR, "Licence bridge production guards failed:\n - " . implode( "\n - ", $failed ) . "\n" );
	exit( 1 );
}

echo "Licence bridge production guards: OK\n";
