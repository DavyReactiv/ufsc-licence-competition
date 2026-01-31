<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Competitions module bootstrap / loader.
 *
 * Provides:
 * - load_competitions_core_dependencies()
 * - load_competitions_admin_dependencies()
 * - Defensive early upgrades via Db::maybe_upgrade()
 * - register menus/pages when appropriate
 *
 * This file is idempotent and safe to require_once from plugin bootstrap.
 */

/**
 * Load core dependencies for competitions (Db, services, repositories).
 */
function load_competitions_core_dependencies(): void {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}
	$loaded = true;

	$base = __DIR__;

	$core_files = array(
		$base . '/Db.php',
		$base . '/Capabilities.php',
		$base . '/Entries/EntriesWorkflow.php',
		$base . '/Services/CompetitionMeta.php',
		$base . '/Services/DisciplineRegistry.php',
		$base . '/Services/CategoryPresetRegistry.php',
		$base . '/Services/CategoryAssigner.php',
		$base . '/Services/PoolGenerator.php',
		$base . '/Services/BracketGenerator.php',
		$base . '/Services/StandingsCalculator.php',
		$base . '/Services/FightAutoGenerationService.php',
		$base . '/Services/PrintRenderer.php',
		$base . '/Services/Plateau_Pdf_Renderer.php',
		$base . '/Services/LogService.php',
		$base . '/Services/AuditLogger.php',
		// Repository helpers must be available to all repos
		$base . '/Repositories/RepositoryHelpers.php',
		// Repositories (ClubRepository must be loaded here)
		$base . '/Repositories/CompetitionRepository.php',
		$base . '/Repositories/CategoryRepository.php',
		$base . '/Repositories/EntryRepository.php',
		$base . '/Repositories/FightRepository.php',
		$base . '/Repositories/LogRepository.php',
		$base . '/Repositories/ClubRepository.php',
		$base . '/Repositories/TimingProfileRepository.php',
		$base . '/Front/Front.php',
	);

	foreach ( $core_files as $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

/**
 * Load admin-only dependencies (pages, tables, assets, menu).
 */
function load_competitions_admin_dependencies(): void {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}
	$loaded = true;

	$base = __DIR__;

	$admin_files = array(
		$base . '/Admin/Assets.php',
		$base . '/Admin/Exports/Entries_Export_Controller.php',
		$base . '/Admin/Entries_Validation_Menu.php',
		$base . '/Admin/Menu.php',
		$base . '/Admin/Tables/Competitions_Table.php',
		$base . '/Admin/Tables/Categories_Table.php',
		$base . '/Admin/Tables/Entries_Table.php',
		$base . '/Admin/Tables/Entries_Validation_Table.php',
		$base . '/Admin/Tables/Fights_Table.php',
		$base . '/Admin/Tables/Quality_Table.php',
		$base . '/Admin/Pages/Competitions_Page.php',
		$base . '/Admin/Pages/Categories_Page.php',
		$base . '/Admin/Pages/Entries_Page.php',
		$base . '/Admin/Pages/Entries_Validation_Page.php',
		$base . '/Admin/Pages/Bouts_Page.php',
		$base . '/Admin/Pages/Bouts_AutoGeneration.php',
		$base . '/Admin/Pages/Timing_Profiles_Page.php',
		$base . '/Admin/Pages/Settings_Page.php',
		$base . '/Admin/Pages/CompetitionLogs_Page.php',
		$base . '/Admin/Pages/Guide_Page.php',
		$base . '/Admin/Pages/Quality_Page.php',
		$base . '/Admin/Pages/Print_Page.php',
	);

	foreach ( $admin_files as $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

// Load core dependencies immediately so classes are available for early hooks.
load_competitions_core_dependencies();

// Run DB upgrades as early as possible so repository queries don't fail.
add_action(
	'plugins_loaded',
	function() {
		if ( class_exists( '\UFSC\Competitions\Db' ) && method_exists( '\UFSC\Competitions\Db', 'maybe_upgrade' ) ) {
			try {
				\UFSC\Competitions\Db::maybe_upgrade();
			} catch ( \Throwable $e ) {
				error_log( 'UFSC Competitions: Db::maybe_upgrade failed on plugins_loaded: ' . $e->getMessage() );
			}
		}
	},
	1
);

// Register safe admin_init for upgrade/migrations and admin deps.
add_action(
	'admin_init',
	function() {
		// ensure core loaded
		if ( function_exists( '\UFSC\Competitions\load_competitions_core_dependencies' ) ) {
			\UFSC\Competitions\load_competitions_core_dependencies();
		}

		// Load admin dependencies only on admin
		if ( is_admin() && function_exists( '\UFSC\Competitions\load_competitions_admin_dependencies' ) ) {
			\UFSC\Competitions\load_competitions_admin_dependencies();
		}

		if ( is_admin() && class_exists( '\UFSC\Competitions\Admin\Exports\Entries_Export_Controller' ) ) {
			$controller = new \UFSC\Competitions\Admin\Exports\Entries_Export_Controller();
			$controller->register();
		}

		if ( is_admin() && class_exists( '\UFSC\Competitions\Admin\Pages\Bouts_AutoGeneration' ) ) {
			\UFSC\Competitions\Admin\Pages\Bouts_AutoGeneration::register_actions();
		}

		// Call maybe_upgrade if available, defensively
		if ( class_exists( '\UFSC\Competitions\Db' ) && method_exists( '\UFSC\Competitions\Db', 'maybe_upgrade' ) ) {
			try {
				\UFSC\Competitions\Db::maybe_upgrade();
			} catch ( \Throwable $e ) {
				error_log( 'UFSC Competitions: Db::maybe_upgrade failed: ' . $e->getMessage() );
			}
		}
	},
	1
);

/**
 * Register the module (menus/pages) on init, defensively.
 */
add_action(
	'init',
	function() {
		// ensure core and admin deps are loaded when registering module
		if ( function_exists( '\UFSC\Competitions\load_competitions_core_dependencies' ) ) {
			\UFSC\Competitions\load_competitions_core_dependencies();
		}
		if ( is_admin() && function_exists( '\UFSC\Competitions\load_competitions_admin_dependencies' ) ) {
			\UFSC\Competitions\load_competitions_admin_dependencies();
		}

		if ( class_exists( '\UFSC\Competitions\Front\Entries\EntriesModule' ) ) {
			\UFSC\Competitions\Front\Entries\EntriesModule::register_actions();
		}

		// Register admin menu only if class exists
		if ( is_admin() && class_exists( '\UFSC\Competitions\Admin\Menu' ) ) {
			try {
				$menu = new \UFSC\Competitions\Admin\Menu();
				$menu->register();
			} catch ( \Throwable $e ) {
				error_log( 'UFSC Competitions: Admin\\Menu registration failed: ' . $e->getMessage() );
			}
		}

		if ( is_admin() && class_exists( '\UFSC\Competitions\Admin\Entries_Validation_Menu' ) ) {
			try {
				$menu = new \UFSC\Competitions\Admin\Entries_Validation_Menu();
				$menu->register();
			} catch ( \Throwable $e ) {
				error_log( 'UFSC Competitions: Entries validation menu registration failed: ' . $e->getMessage() );
			}
		}

		if ( ! is_admin() && class_exists( '\UFSC\Competitions\Front\Front' ) ) {
			\UFSC\Competitions\Front\Front::init();
		}

		if ( class_exists( '\UFSC\Competitions\Services\AuditLogger' ) && method_exists( '\UFSC\Competitions\Services\AuditLogger', 'register_hooks' ) ) {
			\UFSC\Competitions\Services\AuditLogger::register_hooks();
		}
	}
);
