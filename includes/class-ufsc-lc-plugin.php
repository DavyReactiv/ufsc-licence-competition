<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ufsc-lc-capabilities.php';
require_once __DIR__ . '/class-ufsc-lc-categories.php';
require_once __DIR__ . '/class-ufsc-licence-documents.php';
require_once __DIR__ . '/class-ufsc-asptt-importer.php';
require_once __DIR__ . '/class-ufsc-club-licences-shortcode.php';
require_once __DIR__ . '/class-ufsc-licence-indexes.php';
require_once __DIR__ . '/class-ufsc-lc-licence-migrations.php';
require_once __DIR__ . '/class-ufsc-lc-logger.php';
require_once __DIR__ . '/export/class-ufsc-lc-exporter.php';
require_once __DIR__ . '/import/class-ufsc-lc-asptt-importer.php';
require_once __DIR__ . '/admin/class-ufsc-lc-admin-assets.php';
require_once __DIR__ . '/admin/class-ufsc-licences-admin.php';
require_once __DIR__ . '/admin/class-ufsc-lc-status-page.php';

class UFSC_LC_Plugin {
	const CAPABILITY      = UFSC_LC_Capabilities::MANAGE_CAPABILITY;
	const DB_VERSION_OPTION = 'ufsc_lc_db_version';
	const DB_VERSION        = '1.4.0';
	const LEGACY_OPTION     = 'ufsc_lc_legacy_compatibility';
	const PARENT_SLUG       = 'ufsc-licence-documents';

	private static $instance;
	private $plugin_file;
	private $legacy_enabled = false;
	private $dependencies_met = true;
	private $dependency_missing = array();

	public static function init( $plugin_file ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file );
		}

		return self::$instance;
	}

	private function __construct( $plugin_file ) {
		$this->plugin_file   = $plugin_file;
		$this->legacy_enabled = $this->is_legacy_enabled();

		register_activation_hook( $this->plugin_file, array( $this, 'activate' ) );
		register_deactivation_hook( $this->plugin_file, array( $this, 'deactivate' ) );

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( $this, 'boot' ), 5 );
	}

	public function activate() {
		UFSC_LC_Capabilities::add_caps();
		$this->create_tables_and_indexes();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public function deactivate() {
		UFSC_LC_Capabilities::remove_caps();
	}

	public function boot() {
		if ( is_admin() ) {
			$status_page = new UFSC_LC_Status_Page();
			$status_page->register();
		}

		$this->dependencies_met = $this->check_dependencies();
		if ( ! $this->dependencies_met ) {
			add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );
			return;
		}

		if ( is_admin() ) {
			$this->maybe_upgrade();
		}

		$documents = new UFSC_LC_Licence_Documents( $this->legacy_enabled );
		$documents->register();

		$importer = new UFSC_LC_ASPTT_Importer( $this->legacy_enabled );
		$importer->register();

		$licences_admin = new UFSC_LC_Licences_Admin();
		$licences_admin->register();

		$shortcode = new UFSC_LC_Club_Licences_Shortcode( $this->legacy_enabled );
		$shortcode->register();
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'ufsc-licence-competition',
			false,
			dirname( plugin_basename( $this->plugin_file ) ) . '/languages'
		);
	}

	public function maybe_upgrade() {
		if ( ! is_admin() || ! UFSC_LC_Capabilities::user_can_manage() ) {
			return;
		}

		if ( get_transient( 'ufsc_lc_upgrading' ) ) {
			return;
		}

		$installed = get_option( self::DB_VERSION_OPTION, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}

		set_transient( 'ufsc_lc_upgrading', 1, 60 );

		$this->create_tables_and_indexes();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );

		delete_transient( 'ufsc_lc_upgrading' );
	}

	private function create_tables_and_indexes() {
		$documents = new UFSC_LC_Licence_Documents( $this->legacy_enabled );
		$documents->create_table();

		$importer = new UFSC_LC_ASPTT_Importer( $this->legacy_enabled );
		$importer->create_tables();

		$migrations = new UFSC_LC_Licence_Migrations();
		$migrations->ensure_licence_columns();
		$migrations->backfill_categories();

		$indexes = new UFSC_LC_Licence_Indexes();
		$indexes->ensure_indexes();
	}

	public function recreate_tables_and_indexes() {
		$this->create_tables_and_indexes();
	}

	private function is_legacy_enabled() {
		$enabled = (bool) get_option( self::LEGACY_OPTION, false );

		return (bool) apply_filters( 'ufsc_lc_enable_legacy_compatibility', $enabled );
	}

	private function check_dependencies() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'ufsc_licences' => __( 'table des licences UFSC', 'ufsc-licence-competition' ),
			$wpdb->prefix . 'ufsc_clubs'    => __( 'table des clubs UFSC', 'ufsc-licence-competition' ),
		);

		$missing = array();
		foreach ( $tables as $table => $label ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				$missing[] = $label;
			}
		}

		$this->dependency_missing = $missing;

		$met = empty( $missing );

		return (bool) apply_filters( 'ufsc_lc_dependencies_met', $met, $missing );
	}

	public function render_dependency_notice() {
		if ( empty( $this->dependency_missing ) ) {
			return;
		}

		$missing = $this->dependency_missing;

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					__( 'UFSC Licence Competition add-on: dépendances manquantes (%s). Les fonctionnalités administratives ne sont pas chargées.', 'ufsc-licence-competition' ),
					implode( ', ', $missing )
				)
			)
		);
	}
}
