<?php
/**
 * Plugin Name: UFSC Licence Competition
 * Description: Add-on UFSC pour associer des PDF nominatives aux licences officielles et préparer les évolutions compétitions.
 * Plugin URI: https://studioreactiv.fr/
 * Version: 1.5.0
 * Author: StudioReactiv
 * Author URI: https://studioreactiv.fr/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ufsc-licence-competition
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define core plugin constants early to avoid undefined constant errors.
 * These are used throughout the plugin. Keep them minimal and deterministic.
 */
if ( ! defined( 'UFSC_LC_FILE' ) ) {
	define( 'UFSC_LC_FILE', __FILE__ );
}
if ( ! defined( 'UFSC_LC_DIR' ) ) {
	define( 'UFSC_LC_DIR', plugin_dir_path( UFSC_LC_FILE ) );
}
if ( ! defined( 'UFSC_LC_URL' ) ) {
	define( 'UFSC_LC_URL', plugin_dir_url( UFSC_LC_FILE ) );
}
// Backwards-compatible alias: some files historically used UFSC_LC_PLUGIN_DIR
if ( ! defined( 'UFSC_LC_PLUGIN_DIR' ) ) {
	define( 'UFSC_LC_PLUGIN_DIR', UFSC_LC_DIR );
}

require_once UFSC_LC_DIR . 'includes/class-ufsc-lc-plugin.php';

UFSC_LC_Plugin::init( UFSC_LC_FILE );

register_activation_hook(
	UFSC_LC_FILE,
	function() {
		$bootstrap = UFSC_LC_DIR . 'includes/competitions/bootstrap.php';
		if ( file_exists( $bootstrap ) ) {
			require_once $bootstrap;
		}

		if ( class_exists( '\\UFSC\\Competitions\\Front\\Front' ) ) {
			\UFSC\Competitions\Front\Front::flush_rewrite_rules();
		}
	}
);

register_deactivation_hook(
	UFSC_LC_FILE,
	function() {
		flush_rewrite_rules();
	}
);
