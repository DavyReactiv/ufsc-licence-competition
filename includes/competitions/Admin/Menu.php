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
	const PARENT_SLUG = 'ufsc_competitions';
	const PAGE_COMPETITIONS = 'ufsc_competitions_competitions';
	const PAGE_CATEGORIES = 'ufsc_competitions_categories';
	const PAGE_ENTRIES = 'ufsc_competitions_registrations';
	const PAGE_QUALITY = 'ufsc_competitions_quality';
	const PAGE_PRINT = 'ufsc_competitions_print';
	const PAGE_BOUTS = 'ufsc_competitions_bouts';
	const PAGE_SETTINGS = 'ufsc_competitions_settings';

	private static $registered = false;

	public function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_menu', array( $this, 'register_menu' ), 40 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_pages' ) );
	}

	public function maybe_redirect_legacy_pages() {
		if ( ! is_admin() || ! isset( $_GET['page'] ) || ! empty( $_POST ) ) {
			return;
		}

		$current = sanitize_key( wp_unslash( $_GET['page'] ) );
		$map = array(
			'ufsc-competitions'            => self::PAGE_COMPETITIONS,
			'ufsc-competition-categories'  => self::PAGE_CATEGORIES,
			'ufsc-competition-registrations' => self::PAGE_ENTRIES,
			'ufsc-competition-quality'     => self::PAGE_QUALITY,
			'ufsc-competition-print'       => self::PAGE_PRINT,
			'ufsc-competition-bouts'       => self::PAGE_BOUTS,
			'ufsc-competition-settings'    => self::PAGE_SETTINGS,
			self::PARENT_SLUG              => self::PAGE_COMPETITIONS,
		);

		if ( ! isset( $map[ $current ] ) || $map[ $current ] === $current ) {
			return;
		}

		$params = array();
		foreach ( wp_unslash( $_GET ) as $key => $value ) {
			if ( 'page' === $key ) {
				continue;
			}
			$params[ sanitize_key( $key ) ] = is_array( $value )
				? array_map( 'sanitize_text_field', $value )
				: sanitize_text_field( $value );
		}
		$params['page'] = $map[ $current ];

		wp_safe_redirect( add_query_arg( $params, admin_url( 'admin.php' ) ) );
		exit;
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
			self::PARENT_SLUG,
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
			self::PARENT_SLUG,
			__( 'Compétitions', 'ufsc-licence-competition' ),
			__( 'Compétitions', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_COMPETITIONS,
			array( $competitions_page, 'render' )
		);
		$assets->register( $hook_suffix );

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Catégories & formats', 'ufsc-licence-competition' ),
			__( 'Catégories & formats', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_CATEGORIES,
			array( $categories_page, 'render' )
		);
		$assets->register( $hook_suffix );

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Inscriptions', 'ufsc-licence-competition' ),
			__( 'Inscriptions', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_ENTRIES,
			array( $entries_page, 'render' )
		);
		$assets->register( $hook_suffix );

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Contrôles qualité', 'ufsc-licence-competition' ),
			__( 'Contrôles qualité', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_QUALITY,
			array( new Quality_Page(), 'render' )
		);
		$assets->register( $hook_suffix );

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Impression', 'ufsc-licence-competition' ),
			__( 'Impression', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_PRINT,
			array( new Print_Page(), 'render' )
		);
		$assets->register( $hook_suffix );

		$placeholder = new Placeholder_Page();
		$subpages = array(
			self::PAGE_BOUTS => __( 'Combats', 'ufsc-licence-competition' ),
			self::PAGE_SETTINGS => __( 'Paramètres', 'ufsc-licence-competition' ),
		);

		foreach ( $subpages as $slug => $label ) {
			$hook_suffix = add_submenu_page(
				self::PARENT_SLUG,
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
