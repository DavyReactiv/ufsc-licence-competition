<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ufsc-licence-documents.php';
require_once __DIR__ . '/class-ufsc-asptt-importer.php';
require_once __DIR__ . '/class-ufsc-club-licences-shortcode.php';
require_once __DIR__ . '/class-ufsc-licence-indexes.php';
require_once __DIR__ . '/admin/class-ufsc-licences-admin.php';

class UFSC_Licence_Competition {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __DIR__ . '/../ufsc-licence-competition.php', array( $this, 'activate' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function activate() {
		$documents = new UFSC_Licence_Documents();
		$documents->create_table();

		$importer = new UFSC_ASPTT_Importer();
		$importer->create_tables();

		$indexes = new UFSC_Licence_Indexes();
		$indexes->ensure_indexes();
	}

	public function init() {
		$documents = new UFSC_Licence_Documents();
		$documents->register();

		$importer = new UFSC_ASPTT_Importer();
		$importer->register();

		$licences_admin = new UFSC_Licences_Admin();
		$licences_admin->register();

		$shortcode = new UFSC_Club_Licences_Shortcode();
		$shortcode->register();
	}
}
