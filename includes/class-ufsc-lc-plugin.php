<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/ufsc-lc-helpers.php';
require_once __DIR__ . '/class-ufsc-lc-capabilities.php';
require_once __DIR__ . '/Security/Scope.php';
require_once __DIR__ . '/class-ufsc-lc-categories.php';
require_once __DIR__ . '/class-ufsc-lc-schema-cache.php';
require_once __DIR__ . '/class-ufsc-licence-documents.php';
require_once __DIR__ . '/class-ufsc-asptt-importer.php';
require_once __DIR__ . '/class-ufsc-club-licences-shortcode.php';
require_once __DIR__ . '/class-ufsc-licence-indexes.php';
require_once __DIR__ . '/class-ufsc-lc-licence-migrations.php';
require_once __DIR__ . '/class-ufsc-lc-logger.php';
require_once __DIR__ . '/ea11y-fix.php';
require_once __DIR__ . '/Repositories/LicenceRepository.php';
require_once __DIR__ . '/export/class-ufsc-lc-exporter.php';
require_once __DIR__ . '/import/class-ufsc-lc-asptt-importer.php';
require_once __DIR__ . '/admin/class-ufsc-lc-admin-assets.php';
require_once __DIR__ . '/admin/class-ufsc-licences-admin.php';
require_once __DIR__ . '/admin/class-ufsc-lc-status-page.php';
require_once __DIR__ . '/admin/class-ufsc-lc-asptt-review-page.php';
require_once __DIR__ . '/admin/class-ufsc-lc-settings-page.php';
$competitions_bootstrap = __DIR__ . '/competitions/bootstrap.php';
if ( file_exists( $competitions_bootstrap ) ) {
	require_once $competitions_bootstrap;
}

class UFSC_LC_Plugin {
	const CAPABILITY      = UFSC_LC_Capabilities::MANAGE_CAPABILITY;
	const DB_VERSION_OPTION = 'ufsc_lc_db_version';
	const DB_VERSION        = '1.5.1';
	const LEGACY_OPTION     = 'ufsc_lc_legacy_compatibility';
	// Must match add_menu_page slug.
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
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( UFSC_LC_Capabilities::IMPORT_CAPABILITY ) ) {
			$role->add_cap( UFSC_LC_Capabilities::IMPORT_CAPABILITY );
		}
		if ( $role ) {
			$competition_caps = array(
				'ufsc_manage_competitions',
				'ufsc_manage_competition_results',
				'ufsc_club_manage_entries',
			);

			foreach ( $competition_caps as $capability ) {
				if ( ! $role->has_cap( $capability ) ) {
					$role->add_cap( $capability );
				}
			}
		}
		$this->create_tables_and_indexes();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );

		if ( class_exists( '\\UFSC\\Competitions\\Front\\Front' ) ) {
			\UFSC\Competitions\Front\Front::register_rewrite_rules();
		}

		flush_rewrite_rules();
	}

	public function deactivate() {
		UFSC_LC_Capabilities::remove_caps();
		flush_rewrite_rules();
	}

	public function boot() {
		if ( is_admin() ) {
			$status_page = new UFSC_LC_Status_Page();
			$status_page->register();

			$settings_page = new UFSC_LC_Settings_Page();
			$settings_page->register();
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

		$review_page = new UFSC_LC_ASPTT_Review_Page( new UFSC_LC_ASPTT_Import_Service() );
		$review_page->register_actions();

		$licences_admin = new UFSC_LC_Licences_Admin();
		$licences_admin->register();

		$shortcode = new UFSC_LC_Club_Licences_Shortcode( $this->legacy_enabled );
		$shortcode->register();

		if ( class_exists( '\\UFSC\\Competitions\\Bootstrap' ) ) {
			\UFSC\Competitions\Bootstrap::init( $this->plugin_file );
		}

		if ( defined( 'UFSC_LC_DEBUG_PERF' ) && UFSC_LC_DEBUG_PERF ) {
			add_action( 'shutdown', array( $this, 'log_perf_summary' ) );
		}
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

$allow_master_alter = (bool) apply_filters( 'ufsc_lc_allow_master_table_alter', false );

		$migrations = new UFSC_LC_Licence_Migrations();
		$indexes = new UFSC_LC_Licence_Indexes();

		if ( $allow_master_alter ) {
			$migrations->ensure_licence_columns();
			$migrations->backfill_categories();
			$migrations->backfill_nom_columns();
			$indexes->ensure_indexes();
		} else {
			$missing_columns = method_exists( $migrations, 'get_missing_master_columns' ) ? $migrations->get_missing_master_columns() : array();
			$missing_indexes = method_exists( $indexes, 'get_missing_master_indexes' ) ? $indexes->get_missing_master_indexes() : array();
			$this->register_master_tables_notice( $missing_columns, $missing_indexes );
		}

		if ( class_exists( '\\UFSC\\Competitions\\Db' ) ) {
			\UFSC\Competitions\Db::create_tables();
			if ( method_exists( '\UFSC\Competitions\Db', 'maybe_upgrade' ) ) {
				\UFSC\Competitions\Db::maybe_upgrade();
			}
		}
	}

	private function register_master_tables_notice( array $missing_columns, array $missing_indexes ): void {
		if ( ! is_admin() || ! UFSC_LC_Capabilities::user_can_manage() ) {
			return;
		}

		if ( empty( $missing_columns ) && empty( $missing_indexes ) ) {
			return;
		}

		add_action(
			'admin_notices',
			function() use ( $missing_columns, $missing_indexes ) {
				$parts = array();
				if ( $missing_columns ) {
					$parts[] = sprintf(
						/* translators: %s: columns list */
						__( 'Colonnes manquantes dans ufsc_licences : %s.', 'ufsc-licence-competition' ),
						implode( ', ', array_map( 'esc_html', $missing_columns ) )
					);
				}
				if ( $missing_indexes ) {
					$parts[] = sprintf(
						/* translators: %s: indexes list */
						__( 'Indexes manquants dans ufsc_licences : %s.', 'ufsc-licence-competition' ),
						implode( ', ', array_map( 'esc_html', $missing_indexes ) )
					);
				}
				$parts[] = __( 'Ces modifications doivent être gérées par le plugin maître (UFSC Gestion).', 'ufsc-licence-competition' );

				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					implode( ' ', array_map( 'wp_kses_post', $parts ) )
				);
			}
		);
	}

	public function recreate_tables_and_indexes() {
		$this->create_tables_and_indexes();
	}

	private function is_legacy_enabled() {
		$enabled = (bool) get_option( self::LEGACY_OPTION, false );

		return (bool) apply_filters( 'ufsc_lc_enable_legacy_compatibility', $enabled );
	}

	private function check_dependencies() {
		$tables = array(
			'ufsc_licences' => __( 'table des licences UFSC', 'ufsc-licence-competition' ),
			'ufsc_clubs'    => __( 'table des clubs UFSC', 'ufsc-licence-competition' ),
		);

		$missing = array();
		foreach ( $tables as $suffix => $label ) {
			$table = $this->get_table_name( $suffix );
			if ( ! UFSC_LC_Schema_Cache::table_exists( $table ) ) {
				$missing[] = $label;
			}
		}

		$this->dependency_missing = $missing;

		$met = empty( $missing );

		return (bool) apply_filters( 'ufsc_lc_dependencies_met', $met, $missing );
	}

	private function get_table_name( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . ltrim( $suffix, '_' );
	}

	public function log_perf_summary(): void {
		if ( ! is_admin() ) {
			return;
		}

		global $wpdb;

		$page  = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$time  = function_exists( 'timer_stop' ) ? timer_stop( 0, 3 ) : '';
		$stats = class_exists( 'UFSC_LC_Schema_Cache' ) ? UFSC_LC_Schema_Cache::get_debug_stats() : array(
			'hits'   => 0,
			'misses' => 0,
		);

		error_log(
			sprintf(
				'UFSC LC perf: page=%s queries=%d time=%ss schema_cache_hits=%d schema_cache_misses=%d',
				$page,
				(int) $wpdb->num_queries,
				$time,
				(int) $stats['hits'],
				(int) $stats['misses']
			)
		);
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
