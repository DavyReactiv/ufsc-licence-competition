<?php
/**
 * Guard the self-contained Dompdf distribution workflow.
 */

$root     = dirname( __DIR__ );
$plugin   = file_get_contents( $root . '/ufsc-licence-competition.php' );
$settings = file_get_contents( $root . '/includes/admin/class-ufsc-lc-settings-page.php' );
$build    = file_get_contents( $root . '/.github/workflows/build-production-plugin.yml' );
$quality  = file_get_contents( $root . '/.github/workflows/quality.yml' );
$release  = file_get_contents( $root . '/.github/workflows/release-plugin.yml' );
$script   = file_get_contents( $root . '/tools/build-production-package.sh' );
$composer = file_get_contents( $root . '/composer.json' );

$errors = array();

$checks = array(
	'plugin version 1.6.1'          => array( $plugin, 'Version: 1.6.1' ),
	'vendor present constant'       => array( $plugin, 'UFSC_LC_VENDOR_PRESENT' ),
	'dompdf ready constant'         => array( $plugin, 'UFSC_LC_DOMPDF_READY' ),
	'complete distribution constant'=> array( $plugin, 'UFSC_LC_DISTRIBUTION_COMPLETE' ),
	'no server Composer instruction'=> array( $plugin, 'aucune commande Composer ne doit être exécutée sur le serveur WordPress' ),
	'settings package state'        => array( $settings, 'Package autonome complet' ),
	'settings Dompdf version'       => array( $settings, 'Composer\\InstalledVersions' ),
	'canonical package script'      => array( $script, 'vendor/dompdf/dompdf/src/Dompdf.php' ),
	'versioned package name'        => array( $script, 'ufsc-licence-competition-${VERSION}.zip' ),
	'distribution marker'           => array( $script, 'DISTRIBUTION-VERSION' ),
	'build uses canonical script'   => array( $build, 'tools/build-production-package.sh' ),
	'quality uses canonical script' => array( $quality, 'tools/build-production-package.sh' ),
	'release workflow'              => array( $release, 'gh release create' ),
	'dompdf composer dependency'    => array( $composer, 'dompdf/dompdf' ),
);

foreach ( $checks as $label => $check ) {
	if ( false === strpos( $check[0], $check[1] ) ) {
		$errors[] = $label;
	}
}

if ( false !== strpos( $settings, 'Installer le package Composer' ) ) {
	$errors[] = 'settings must not instruct production users to install Composer dependencies manually';
}

if ( $errors ) {
	fwrite( STDERR, "Self-contained Dompdf distribution guard failed:\n - " . implode( "\n - ", $errors ) . "\n" );
	exit( 1 );
}

echo "Self-contained Dompdf distribution guard: OK\n";
