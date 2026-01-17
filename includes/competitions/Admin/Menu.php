<?php

namespace UFSC\Competitions\Admin;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Pages\Competitions_Page;
use UFSC\Competitions\Admin\Pages\Categories_Page;
use UFSC\Competitions\Admin\Pages\Entries_Page;
use UFSC\Competitions\Admin\Pages\Quality_Page;
use UFSC\Competitions\Admin\Pages\Print_Page;
use UFSC\Competitions\Admin\Pages\Placeholder_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 40 );
	}

	public function register_menu() {
		$capability = defined( 'UFSC_LC_Capabilities::COMPETITIONS_CAPABILITY' )
			? \UFSC_LC_Capabilities::COMPETITIONS_CAPABILITY
			: 'manage_options';
		if ( ! current_user_can( $capability ) && current_user_can( 'manage_options' ) ) {
			$capability = 'manage_options';
		}

		$assets = new Assets();

		$hook_suffix = add_menu_page(
			__( 'UFSC – Compétitions', 'ufsc-licence-competition' ),
			__( 'UFSC – Compétitions', 'ufsc-licence-competition' ),
			$capability,
			'ufsc-competitions',
			array( new Competitions_Page(), 'render' ),
			'dashicons-groups',
			31
		);
		$assets->register( $hook_suffix );

		$competitions_page = new Competitions_Page();
		$competitions_page->register_actions();

		$categories_page = new Categories_Page();
		$categories_page->register_actions();

		$entries_page = new Entries_Page();
		$entries_page->register_actions();

		$hook_suffix = add_submenu_page(
			'ufsc-competitions',
			__( 'Compétitions', 'ufsc-licence-competition' ),
			__( 'Compétitions', 'ufsc-licence-competition' ),
			$capability,
			'ufsc-competitions',
			array( $competitions_page, 'render' )
		);
		$assets->register( $hook_suffix );

		$hook_suffix = add_submenu_page(
			'ufsc-competitions',
			__( 'Catégories & formats', 'ufsc-licence-competition' ),
			__( 'Catégories & formats', 'ufsc-licence-competition' ),
			$capability,
			'ufsc-competition-categories',
			array( $categories_page, 'render' )
		);
		$assets->register( $hook_suffix );

		$hook_suffix = add_submenu_page(
			'ufsc-competitions',
			__( 'Inscriptions', 'ufsc-licence-competition' ),
			__( 'Inscriptions', 'ufsc-licence-competition' ),
			$capability,
			'ufsc-competition-registrations',
			array( $entries_page, 'render' )
		);
		$assets->register( $hook_suffix );

		$hook_suffix = add_submenu_page(
			'ufsc-competitions',
			__( 'Contrôles qualité', 'ufsc-licence-competition' ),
			__( 'Contrôles qualité', 'ufsc-licence-competition' ),
			$capability,
			'ufsc-competition-quality',
			array( new Quality_Page(), 'render' )
		);
		$assets->register( $hook_suffix );

		$hook_suffix = add_submenu_page(
			'ufsc-competitions',
			__( 'Impression', 'ufsc-licence-competition' ),
			__( 'Impression', 'ufsc-licence-competition' ),
			$capability,
			'ufsc-competition-print',
			array( new Print_Page(), 'render' )
		);
		$assets->register( $hook_suffix );

		$placeholder = new Placeholder_Page();
		$subpages = array(
			'ufsc-competition-bouts' => __( 'Combats', 'ufsc-licence-competition' ),
			'ufsc-competition-settings' => __( 'Paramètres', 'ufsc-licence-competition' ),
		);

		foreach ( $subpages as $slug => $label ) {
			$hook_suffix = add_submenu_page(
				'ufsc-competitions',
				$label,
				$label,
				$capability,
				$slug,
				array( $placeholder, 'render' )
			);
			$assets->register( $hook_suffix );
		}
	}
}
