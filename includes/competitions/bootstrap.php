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
 * - Defensive admin_init that calls Db::maybe_upgrade() only if available
 * - register_module() to register admin menu when appropriate
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
		$base . '/Services/DisciplineRegistry.php',
		$base . '/Services/CategoryPresetRegistry.php',
		$base . '/Services/CategoryAssigner.php',
		$base . '/Services/PoolGenerator.php',
		$base . '/Services/BracketGenerator.php',
		$base . '/Services/StandingsCalculator.php',
		$base . '/Services/PrintRenderer.php',
		$base . '/Services/LogService.php',
		// Repositories (ClubRepository must be loaded here)
		$base . '/Repositories/CompetitionRepository.php',
		$base . '/Repositories/CategoryRepository.php',
		$base . '/Repositories/EntryRepository.php',
		$base . '/Repositories/FightRepository.php',
		$base . '/Repositories/LogRepository.php',
		$base . '/Repositories/ClubRepository.php',
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
		$base . '/Admin/Menu.php',
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

	foreach ( $admin_files as $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

// Load core dependencies immediately so classes are available for early hooks.
load_competitions_core_dependencies();

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

		// Call maybe_upgrade if available, defensively
		if ( class_exists( '\UFSC\Competitions\Db' ) && method_exists( '\UFSC\Competitions\Db', 'maybe_upgrade' ) ) {
			try {
				\UFSC\Competitions\Db::maybe_upgrade();
			} catch ( \Throwable $e ) {
				// Do not use non-existing static loggers; use error_log
				error_log( 'UFSC Competitions: Db::maybe_upgrade failed: ' . $e->getMessage() );
			}
		}
	},
	5
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

		// Register admin menu only if class exists
		if ( is_admin() && class_exists( '\UFSC\Competitions\Admin\Menu' ) ) {
			try {
				$menu = new \UFSC\Competitions\Admin\Menu();
				$menu->register();
			} catch ( \Throwable $e ) {
				error_log( 'UFSC Competitions: Admin\\Menu registration failed: ' . $e->getMessage() );
			}
		}
	}
);
