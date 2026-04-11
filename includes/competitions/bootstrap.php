<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'UFSC_COMPETITIONS_BOOTSTRAP_LOADED' ) ) {
	return;
}

define( 'UFSC_COMPETITIONS_BOOTSTRAP_LOADED', true );

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
		$base . '/Security/Scope.php',
		$base . '/Helpers/Logger.php',
		$base . '/Access/AccessResult.php',
		$base . '/Access/CompetitionAccess.php',
		$base . '/Entries/EntriesWorkflow.php',
		$base . '/Entries/EntryEligibility.php',
		$base . '/Entries/ParticipantTypes.php',
		$base . '/Exports/Engaged_Entries_Export_Helper.php',
		$base . '/Services/CompetitionFilters.php',
		$base . '/Services/CompetitionMeta.php',
		$base . '/Services/DateTimeDisplay.php',
		$base . '/Services/DateTimeInputAdapter.php',
		$base . '/Services/DisciplineRegistry.php',
		$base . '/Services/CategoryPresetRegistry.php',
		$base . '/Services/CategoryAssigner.php',
		$base . '/Services/WeightCategoryResolver.php',
		$base . '/Services/PoolGenerator.php',
		$base . '/Services/BracketGenerator.php',
		$base . '/Services/StandingsCalculator.php',
		$base . '/Services/FightAutoGenerationService.php',
		$base . '/Services/FightDisplayService.php',
		$base . '/Services/PrintRenderer.php',
		$base . '/Services/Plateau_Pdf_Renderer.php',
		$base . '/Services/Entries_Pdf_Renderer.php',
		$base . '/Services/LogService.php',
		$base . '/Services/TimingProfilePresetSeeder.php',
		$base . '/Services/CompetitionScheduleEstimator.php',
		$base . '/Services/AuditLogger.php',
		$base . '/Services/EntryDeduplication.php',
		$base . '/Services/ExternalParticipantService.php',
		$base . '/Services/ExternalParticipantValidator.php',
		$base . '/Services/ExternalParticipantEligibility.php',
		$base . '/Services/FightGenerationPremiumPlanner.php',
		$base . '/Services/FightGenerationAnomalyReporter.php',
		$base . '/Services/UfscReference/UfscAgeCategoryRules.php',
		$base . '/Services/UfscReference/UfscWeightRules.php',
		$base . '/Services/UfscReference/UfscTimingRules.php',
		$base . '/Services/UfscReference/UfscObligationRules.php',
		$base . '/Services/UfscReference/UfscReferenceRepository.php',
		$base . '/Services/UfscReference/UfscReferenceFallback.php',
		$base . '/Services/UfscReference/UfscReferenceDateResolver.php',
		$base . '/Services/UfscReference/UfscReferenceNormalizer.php',
		$base . '/Services/UfscReference/UfscReferenceDecision.php',
		$base . '/Services/UfscReference/UfscReferenceDiagnostics.php',
		$base . '/Services/UfscReference/UfscReferenceDebugLogger.php',
		$base . '/Services/UfscReference/UfscReferenceFacade.php',

		// Repository helpers must be available to all repos.
		$base . '/Repositories/RepositoryHelpers.php',

		// Repositories.
		$base . '/Repositories/CompetitionRepository.php',
		$base . '/Repositories/CategoryRepository.php',
		$base . '/Repositories/EntryRepository.php',
		$base . '/Repositories/ExternalParticipantRepository.php',
		$base . '/Repositories/FightRepository.php',
		$base . '/Repositories/LogRepository.php',
		$base . '/Repositories/ClubRepository.php',
		$base . '/Repositories/TimingProfileRepository.php',
		$base . '/Repositories/WeighInRepository.php',

		// Front repositories / handlers required for front + ajax/admin-post.
		$base . '/Front/Access/ClubAccess.php',
		$base . '/Front/Access/ClubAccessHelpers.php',
		$base . '/Front/Entries/EntryActions.php',
		$base . '/Front/Entries/EntryFormRenderer.php',
		$base . '/Front/Entries/EntriesModule.php',
		$base . '/Front/Licenses/LicenseBridge.php',
		$base . '/Front/Exports/Club_Entries_Export_Controller.php',
		$base . '/Front/Exports/Engaged_Entries_Export_Controller.php',
		$base . '/Front/Repositories/EntryFrontRepository.php',
		$base . '/Front/Repositories/CompetitionReadRepository.php',
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
		$base . '/Admin/Exports/Engaged_Entries_Export_Controller.php',
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
		$base . '/Admin/Pages/Entries_Import_Page.php',
		$base . '/Admin/Pages/WeighIns_Page.php',
		$base . '/Admin/Pages/Entries_Validation_Page.php',
		$base . '/Admin/Pages/Bouts_Page.php',
		$base . '/Admin/Pages/Bouts_AutoGeneration.php',
		$base . '/Admin/Pages/Timing_Profiles_Page.php',
		$base . '/Admin/Pages/Settings_Page.php',
		$base . '/Admin/Pages/CompetitionLogs_Page.php',
		$base . '/Admin/Pages/Access_Diagnostic_Page.php',
		$base . '/Admin/Pages/Guide_Page.php',
		$base . '/Admin/Pages/Officials_Page.php',
		$base . '/Admin/Pages/Sensitive_Operations_Page.php',
		$base . '/Admin/Pages/Quality_Page.php',
		$base . '/Admin/Pages/Print_Page.php',
		$base . '/Admin/Pages/Estimation_Page.php',
	);

	foreach ( $admin_files as $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

// Load core dependencies immediately so classes are available for early hooks.
load_competitions_core_dependencies();

add_action(
	'init',
	function() {
		if (
			class_exists( '\\UFSC\\Competitions\\Services\\EntryDeduplication' ) &&
			method_exists( '\\UFSC\\Competitions\\Services\\EntryDeduplication', 'register_cli_commands' )
		) {
			\UFSC\Competitions\Services\EntryDeduplication::register_cli_commands();
		}
	},
	20
);

const AUTO_ARCHIVE_HOOK = 'ufsc_competitions_auto_archive_finished';

function ensure_auto_archive_cron(): void {
	if ( ! wp_next_scheduled( AUTO_ARCHIVE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', AUTO_ARCHIVE_HOOK );
	}
}

function run_auto_archive_finished_competitions(): void {
	if ( ! class_exists( '\\UFSC\\Competitions\\Repositories\\CompetitionRepository' ) ) {
		return;
	}

	$repo = new \UFSC\Competitions\Repositories\CompetitionRepository();
	if ( method_exists( $repo, 'auto_archive_finished_competitions' ) ) {
		$repo->auto_archive_finished_competitions();
	}
}

add_action( AUTO_ARCHIVE_HOOK, __NAMESPACE__ . '\\run_auto_archive_finished_competitions' );

add_action(
	'plugins_loaded',
	function() {
		ensure_auto_archive_cron();
	},
	5
);

add_action(
	'admin_init',
	function() {
		$last_run = (int) get_transient( 'ufsc_competitions_auto_archive_last_run' );
		if ( $last_run > 0 && ( time() - $last_run ) < DAY_IN_SECONDS ) {
			return;
		}

		run_auto_archive_finished_competitions();
		set_transient( 'ufsc_competitions_auto_archive_last_run', time(), DAY_IN_SECONDS );
	},
	20
);

// Run DB upgrades as early as possible so repository queries don't fail.
add_action(
	'plugins_loaded',
	function() {
		if ( class_exists( '\\UFSC\\Competitions\\Db' ) && method_exists( '\\UFSC\\Competitions\\Db', 'maybe_upgrade' ) ) {
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
		// Ensure core loaded.
		if ( function_exists( '\\UFSC\\Competitions\\load_competitions_core_dependencies' ) ) {
			\UFSC\Competitions\load_competitions_core_dependencies();
		}

		// Load admin dependencies only on admin.
		if ( is_admin() && function_exists( '\\UFSC\\Competitions\\load_competitions_admin_dependencies' ) ) {
			\UFSC\Competitions\load_competitions_admin_dependencies();
		}

		if ( is_admin() && class_exists( '\\UFSC\\Competitions\\Admin\\Exports\\Entries_Export_Controller' ) ) {
			$controller = new \UFSC\Competitions\Admin\Exports\Entries_Export_Controller();
			$controller->register();
		}

		if ( is_admin() && class_exists( '\\UFSC\\Competitions\\Admin\\Exports\\Engaged_Entries_Export_Controller' ) ) {
			$controller = new \UFSC\Competitions\Admin\Exports\Engaged_Entries_Export_Controller();
			$controller->register();
		}

		if ( is_admin() && class_exists( '\\UFSC\\Competitions\\Admin\\Pages\\Bouts_AutoGeneration' ) ) {
			\UFSC\Competitions\Admin\Pages\Bouts_AutoGeneration::register_actions();
		}

		// Call maybe_upgrade if available, defensively.
		if ( class_exists( '\\UFSC\\Competitions\\Db' ) && method_exists( '\\UFSC\\Competitions\\Db', 'maybe_upgrade' ) ) {
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
		// Ensure core and admin deps are loaded when registering module.
		if ( function_exists( '\\UFSC\\Competitions\\load_competitions_core_dependencies' ) ) {
			\UFSC\Competitions\load_competitions_core_dependencies();
		}
		if ( is_admin() && function_exists( '\\UFSC\\Competitions\\load_competitions_admin_dependencies' ) ) {
			\UFSC\Competitions\load_competitions_admin_dependencies();
		}

		if ( class_exists( '\\UFSC\\Competitions\\Front\\Entries\\EntriesModule' ) ) {
			\UFSC\Competitions\Front\Entries\EntriesModule::register_actions();
		}

		// Important: admin-ajax.php runs with is_admin() = true.
		// The license bridge must also be registered in this context, otherwise
		// front AJAX callback receives no search filter and always returns empty.
		if ( class_exists( '\\UFSC\\Competitions\\Front\\Licenses\\LicenseBridge' ) ) {
			\UFSC\Competitions\Front\Licenses\LicenseBridge::register();
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			$search_filter_priority = has_filter( 'ufsc_competitions_front_license_search_results' );
			$by_id_filter_priority  = has_filter( 'ufsc_competitions_front_license_by_id' );

			error_log(
				'UFSC Competitions Bootstrap: license_bridge_registered_in_ajax ' . wp_json_encode(
					array(
						'registered'             => false !== $search_filter_priority || false !== $by_id_filter_priority,
						'search_filter_priority' => false === $search_filter_priority ? 0 : (int) $search_filter_priority,
						'by_id_filter_priority'  => false === $by_id_filter_priority ? 0 : (int) $by_id_filter_priority,
					)
				)
			);
		}

		if ( class_exists( '\\UFSC\\Competitions\\Front\\Exports\\Club_Entries_Export_Controller' ) ) {
			( new \UFSC\Competitions\Front\Exports\Club_Entries_Export_Controller() )->register();
		}

		if ( class_exists( '\\UFSC\\Competitions\\Front\\Exports\\Engaged_Entries_Export_Controller' ) ) {
			( new \UFSC\Competitions\Front\Exports\Engaged_Entries_Export_Controller() )->register();
		}

		// Register admin menu only if class exists.
		if ( is_admin() && class_exists( '\\UFSC\\Competitions\\Admin\\Menu' ) ) {
			try {
				$menu = new \UFSC\Competitions\Admin\Menu();
				$menu->register();
			} catch ( \Throwable $e ) {
				error_log( 'UFSC Competitions: Admin\\Menu registration failed: ' . $e->getMessage() );
			}
		}

		if ( is_admin() && class_exists( '\\UFSC\\Competitions\\Admin\\Entries_Validation_Menu' ) ) {
			try {
				$menu = new \UFSC\Competitions\Admin\Entries_Validation_Menu();
				$menu->register();
			} catch ( \Throwable $e ) {
				error_log( 'UFSC Competitions: Entries validation menu registration failed: ' . $e->getMessage() );
			}
		}

		if ( ! is_admin() && class_exists( '\\UFSC\\Competitions\\Front\\Front' ) ) {
			\UFSC\Competitions\Front\Front::init();
		}

		if (
			class_exists( '\\UFSC\\Competitions\\Services\\AuditLogger' ) &&
			method_exists( '\\UFSC\\Competitions\\Services\\AuditLogger', 'register_hooks' )
		) {
			\UFSC\Competitions\Services\AuditLogger::register_hooks();
		}
	}
);
