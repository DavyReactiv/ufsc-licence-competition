<?php

namespace UFSC\Competitions\Admin;

use UFSC\Competitions\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Entries_Validation_Menu {
	public const PAGE_SLUG = 'ufsc-competitions-entry-validation';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 25 );
	}

	public function add_menu(): void {
		$capability = Capabilities::get_validate_entries_capability();
		$parent_slug = $this->resolve_parent_slug();

		$callback = function() {
			if ( class_exists( '\UFSC\Competitions\Admin\Pages\Entries_Validation_Page' ) ) {
				$page = new \UFSC\Competitions\Admin\Pages\Entries_Validation_Page();
				$page->render();
				return;
			}

			echo '<div class="wrap"><h1>' . esc_html__( 'Inscriptions (Validation)', 'ufsc-licence-competition' ) . '</h1></div>';
		};

		$hook_suffix = add_submenu_page(
			$parent_slug,
			__( 'Inscriptions (Validation)', 'ufsc-licence-competition' ),
			__( 'Inscriptions (Validation)', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_SLUG,
			$callback
		);

		if ( $hook_suffix && class_exists( '\UFSC\Competitions\Admin\Assets' ) ) {
			$assets = new Assets();
			$assets->register( $hook_suffix );
		}
	}

	private function resolve_parent_slug(): string {
		$parent_slug = 'tools.php';

		if ( class_exists( '\UFSC\Competitions\Admin\Menu' ) ) {
			$competitions_slug = \UFSC\Competitions\Admin\Menu::MENU_SLUG;
			if ( $this->menu_slug_exists( $competitions_slug ) ) {
				$parent_slug = $competitions_slug;
			}
		}

		return $parent_slug;
	}

	private function menu_slug_exists( string $slug ): bool {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return false;
		}

		foreach ( $menu as $menu_item ) {
			if ( isset( $menu_item[2] ) && $menu_item[2] === $slug ) {
				return true;
			}
		}

		return false;
	}
}
