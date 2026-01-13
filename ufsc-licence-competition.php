<?php
/**
 * Plugin Name: UFSC Licence Competition
 * Description: Add-on UFSC pour associer des PDF nominatives aux licences officielles et préparer les évolutions compétitions.
 * Version: 1.0.0
 * Author: UFSC
 * Text Domain: ufsc-licence-competition
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-ufsc-licence-competition.php';

UFSC_Licence_Competition::get_instance();
