<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Capabilities.php';
require_once __DIR__ . '/Services/LogService.php';
require_once __DIR__ . '/Repositories/CompetitionRepository.php';
require_once __DIR__ . '/Repositories/CategoryRepository.php';
require_once __DIR__ . '/Admin/Assets.php';
require_once __DIR__ . '/Admin/Menu.php';
require_once __DIR__ . '/Admin/Tables/Competitions_Table.php';
require_once __DIR__ . '/Admin/Tables/Categories_Table.php';
require_once __DIR__ . '/Admin/Pages/Competitions_Page.php';
require_once __DIR__ . '/Admin/Pages/Categories_Page.php';
require_once __DIR__ . '/Admin/Pages/Placeholder_Page.php';

class Bootstrap {
	private $plugin_file;

	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	public function register() {
		add_action( 'init', array( $this, 'register_module' ) );
	}

	public function register_module() {
		if ( ! is_admin() ) {
			return;
		}

		$menu = new Admin\Menu();
		$menu->register();
	}
}
