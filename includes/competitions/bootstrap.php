<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central loader for the Competitions module.
 *
 * - load_competitions_dependencies(): single place for require_once of module files
 * - Defensive hook registration to avoid call_user_func_array() on missing classes
 */

/** Load all necessary competitions files from one place (idempotent). */
function load_competitions_dependencies(): void {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}
	$loaded = true;

	$base = __DIR__;

	$files = array(
		// Core DB and capabilities
		$base . '/Db.php',
		$base . '/Capabilities.php',

		// Services
		$base . '/Services/DisciplineRegistry.php',
		$base . '/Services/CategoryPresetRegistry.php',
		$base . '/Services/CategoryAssigner.php',
		$base . '/Services/PoolGenerator.php',
		$base . '/Services/BracketGenerator.php',
		$base . '/Services/StandingsCalculator.php',
		$base . '/Services/PrintRenderer.php',
		$base . '/Services/LogService.php',

		// Repositories
		$base . '/Repositories/CompetitionRepository.php',
		$base . '/Repositories/CategoryRepository.php',
		$base . '/Repositories/EntryRepository.php',
		$base . '/Repositories/FightRepository.php',
		$base . '/Repositories/LogRepository.php',
		$base . '/Repositories/ClubRepository.php', // critical: ensure club repo is loaded

		// Admin assets & menu
		$base . '/Admin/Assets.php',
		$base . '/Admin/Menu.php',

		// Admin tables & pages
		$base . '/Admin/Tables/Competitions_Table.php',
		$base . '/Admin/Tables/Categories_Table.php',
		$base . '/Admin/Tables/Entries_Table.php',
		$base . '/Admin/Tables/Fights_Table.php',
		$base . '/Admin/Tables/Quality_Table.php',

		$base . '/Admin/Pages/Competitions_Page.php',
		$base . '/Admin/Pages/Categories_Page.php',
		$base . '/Admin/Pages/Entries_Page.php',
		$base . '/Admin/Pages/Bouts_Page.php',
		$base . '/Admin/Pages/Settings_Page.php',
		$base . '/Admin/Pages/Guide_Page.php',
		$base . '/Admin/Pages/Quality_Page.php',
		$base . '/Admin/Pages/Print_Page.php',
	);

	foreach ( $files as $f ) {
		if ( file_exists( $f ) ) {
			require_once $f;
		}
	}
}

// Load module dependencies immediately (idempotent)
load_competitions_dependencies();

class Bootstrap {
	private static $initialized = false;
	private static $module_registered = false;
	private static $instance;
	private $plugin_file;

	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	public static function init( $plugin_file ) {
		if ( self::$initialized ) {
			return self::$instance;
		}

		self::$initialized = true;

		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file );
			self::$instance->register();
		}

		return self::$instance;
	}

	public function register() {
		// Register module on init
		add_action( 'init', array( $this, 'register_module' ) );

		// Defensive admin_init: call Db::maybe_upgrade only if class + method exist.
		add_action( 'admin_init', function() {
			// ensure dependencies loaded (safe)
			if ( function_exists( '\UFSC\Competitions\load_competitions_dependencies' ) ) {
				\UFSC\Competitions\load_competitions_dependencies();
			}

			if ( class_exists( '\UFSC\Competitions\Db' ) && method_exists( '\UFSC\Competitions\Db', 'maybe_upgrade' ) ) {
				try {
					\UFSC\Competitions\Db::maybe_upgrade();
				} catch ( \Throwable $e ) {
					// Log but do not fatal
					if ( class_exists( '\UFSC\Competitions\Services\LogService' ) ) {
						\UFSC\Competitions\Services\LogService::log( 'Db::maybe_upgrade failed: ' . $e->getMessage() );
					} else {
						error_log( 'UFSC Competitions: Db::maybe_upgrade failed: ' . $e->getMessage() );
					}
				}
			}
		}, 5 );
	}

	public function register_module() {
		if ( self::$module_registered ) {
			return;
		}
		self::$module_registered = true;

		if ( ! is_admin() ) {
			return;
		}

		// ensure dependencies (safe noop)
		if ( function_exists( '\UFSC\Competitions\load_competitions_dependencies' ) ) {
			\UFSC\Competitions\load_competitions_dependencies();
		}

		// Instantiate menu if available
		if ( class_exists( '\UFSC\Competitions\Admin\Menu' ) ) {
			$menu = new \UFSC\Competitions\Admin\Menu();
			$menu->register();
		} else {
			// log missing menu class
			if ( class_exists( '\UFSC\Competitions\Services\LogService' ) ) {
				\UFSC\Competitions\Services\LogService::log( 'Admin\\Menu class missing; competitions admin menu not registered.' );
			} else {
				error_log( 'UFSC Competitions: Admin\\Menu class missing; competitions admin menu not registered.' );
			}
		}
	}
}
