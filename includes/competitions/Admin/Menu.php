<?php

namespace UFSC\Competitions\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {
	const PARENT_SLUG        = 'ufsc-licence-documents';
	const PAGE_COMPETITIONS  = 'ufsc-competitions';
	const PAGE_CATEGORIES    = 'ufsc-competitions-categories';
	const PAGE_ENTRIES       = 'ufsc-competitions-entries';
	const PAGE_BOUTS         = 'ufsc-competitions-bouts';
	const PAGE_SETTINGS      = 'ufsc-competitions-settings';
	const PAGE_PRINT         = 'ufsc-competitions-print';
	const PAGE_GUIDE         = 'ufsc-competitions-guide';
	const PAGE_QUALITY       = 'ufsc-competitions-quality';

	public function register() {
		$capability = \UFSC_LC_Capabilities::get_manage_capability();

		$assets = new Assets();

		$competitions_page = new Competitions_Page();
		$categories_page   = new Categories_Page();
		$entries_page      = new Entries_Page();
		$bouts_page        = new Bouts_Page();
		$settings_page     = new Settings_Page();
		$guide_page        = new Guide_Page();

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Compétitions', 'ufsc-licence-competition' ),
			__( 'Compétitions', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_COMPETITIONS,
			array( $competitions_page, 'render' )
		);

		if ( $hook_suffix ) {
			\UFSC_LC_Admin_Assets::register_page( $hook_suffix );
			$assets->register( $hook_suffix );
		}

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Catégories & formats', 'ufsc-licence-competition' ),
			__( 'Catégories & formats', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_CATEGORIES,
			array( $categories_page, 'render' )
		);

		if ( $hook_suffix ) {
			\UFSC_LC_Admin_Assets::register_page( $hook_suffix );
			$assets->register( $hook_suffix );
		}

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Inscriptions', 'ufsc-licence-competition' ),
			__( 'Inscriptions', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_ENTRIES,
			array( $entries_page, 'render' )
		);

		if ( $hook_suffix ) {
			\UFSC_LC_Admin_Assets::register_page( $hook_suffix );
			$assets->register( $hook_suffix );
		}

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Contrôles qualité', 'ufsc-licence-competition' ),
			__( 'Contrôles qualité', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_QUALITY,
			array( new Quality_Page(), 'render' )
		);

		if ( $hook_suffix ) {
			\UFSC_LC_Admin_Assets::register_page( $hook_suffix );
			$assets->register( $hook_suffix );
		}

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Impression', 'ufsc-licence-competition' ),
			__( 'Impression', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_PRINT,
			array( new Print_Page(), 'render' )
		);

		if ( $hook_suffix ) {
			\UFSC_LC_Admin_Assets::register_page( $hook_suffix );
			$assets->register( $hook_suffix );
		}

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Combats', 'ufsc-licence-competition' ),
			__( 'Combats', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_BOUTS,
			array( $bouts_page, 'render' )
		);

		if ( $hook_suffix ) {
			\UFSC_LC_Admin_Assets::register_page( $hook_suffix );
			$assets->register( $hook_suffix );
		}

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Paramètres', 'ufsc-licence-competition' ),
			__( 'Paramètres', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_SETTINGS,
			array( $settings_page, 'render' )
		);

		if ( $hook_suffix ) {
			\UFSC_LC_Admin_Assets::register_page( $hook_suffix );
			$assets->register( $hook_suffix );
		}

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Aide & Guide', 'ufsc-licence-competition' ),
			__( 'Aide & Guide', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_GUIDE,
			array( $guide_page, 'render' )
		);

		if ( $hook_suffix ) {
			\UFSC_LC_Admin_Assets::register_page( $hook_suffix );
			$assets->register( $hook_suffix );
		}
	}
}
