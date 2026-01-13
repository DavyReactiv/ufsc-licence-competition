<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ufsc-licence-documents.php';

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
	}

	public function init() {
		$documents = new UFSC_Licence_Documents();
		$documents->register();
	}
}
