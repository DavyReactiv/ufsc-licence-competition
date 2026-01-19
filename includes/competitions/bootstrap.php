<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Capabilities.php';
require_once __DIR__ . '/Services/DisciplineRegistry.php';
require_once __DIR__ . '/Services/CategoryPresetRegistry.php';
require_once __DIR__ . '/Services/CategoryAssigner.php';
require_once __DIR__ . '/Services/PoolGenerator.php';
require_once __DIR__ . '/Services/BracketGenerator.php';
require_once __DIR__ . '/Services/StandingsCalculator.php';
require_once __DIR__ . '/Services/PrintRenderer.php';
require_once __DIR__ . '/Services/LogService.php';
require_once __DIR__ . '/Repositories/CompetitionRepository.php';
require_once __DIR__ . '/Repositories/CategoryRepository.php';
require_once __DIR__ . '/Repositories/EntryRepository.php';
require_once __DIR__ . '/Repositories/FightRepository.php';
require_once __DIR__ . '/Repositories/LogRepository.php';

/*
 * ClubRepository is a new/required repository used by admin pages.
 * Load it here so classes under Admin can safely use it.
 */
$club_repo_path = __DIR__ . '/Repositories/ClubRepository.php';
if ( file_exists( $club_repo_path ) ) {
	require_once $club_repo_path;
}

require_once __DIR__ . '/Admin/Assets.php';
require_once __DIR__ . '/Admin/Menu.php';
require_once __DIR__ . '/Admin/Tables/Competitions_Table.php';
require_once __DIR__ . '/Admin/Tables/Categories_Table.php';
require_once __DIR__ . '/Admin/Tables/Entries_Table.php';
require_once __DIR__ . '/Admin/Tables/Fights_Table.php';
require_once __DIR__ . '/Admin/Tables/Quality_Table.php';
require_once __DIR__ . '/Admin/Pages/Competitions_Page.php';
require_once __DIR__ . '/Admin/Pages/Categories_Page.php';
require_once __DIR__ . '/Admin/Pages/Entries_Page.php';
require_once __DIR__ . '/Admin/Pages/Bouts_Page.php';
require_once __DIR__ . '/Admin/Pages/Settings_Page.php';
require_once __DIR__ . '/Admin/Pages/Guide_Page.php';
require_once __DIR__ . '/Admin/Pages/Quality_Page.php';
require_once __DIR__ . '/Admin/Pages/Print_Page.php';

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
		add_action( 'init', array( $this, 'register_module' ) );
		// Ensure competitions DB can be upgraded automatically when admin visits.
		add_action( 'admin_init', array( '\\UFSC\\Competitions\\Db', 'maybe_upgrade' ) );
		if ( did_action( 'init' ) ) {
			$this->register_module();
		}
	}

	public function register_module() {
		if ( self::$module_registered ) {
			return;
		}
		self::$module_registered = true;

		if ( ! is_admin() ) {
			return;
		}

		$menu = new Admin\Menu();
		$menu->register();
	}
}
