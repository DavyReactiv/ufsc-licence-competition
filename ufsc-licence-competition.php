<?php
/**
 * Plugin Name: UFSC Licence Competition
 * Description: Add-on UFSC pour associer des PDF nominatives aux licences officielles et préparer les évolutions compétitions.
 * Plugin URI: https://studioreactiv.fr/
 * Version: 1.4.0
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

define( 'UFSC_LC_FILE', __FILE__ );
define( 'UFSC_LC_DIR', plugin_dir_path( __FILE__ ) );
define( 'UFSC_LC_URL', plugin_dir_url( __FILE__ ) );

require_once UFSC_LC_DIR . 'includes/class-ufsc-lc-plugin.php';

UFSC_LC_Plugin::init( UFSC_LC_FILE );
