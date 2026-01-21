<?php

namespace UFSC\Competitions\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {

	/**
	 * Slug racine du menu Compétitions.
	 */
	public const MENU_SLUG = 'ufsc-competitions';

	/**
	 * Constantes attendues par certaines pages (ex: Competitions_Page.php)
	 * pour éviter les "Undefined constant Menu::PAGE_*".
	 */
	public const PAGE_COMPETITIONS = 'ufsc-competitions';
	public const PAGE_CATEGORIES   = 'ufsc-competitions-categories';
	public const PAGE_ENTRIES      = 'ufsc-competitions-entries';
	public const PAGE_BOUTS        = 'ufsc-competitions-bouts';
	public const PAGE_QUALITY      = 'ufsc-competitions-quality';
	public const PAGE_PRINT        = 'ufsc-competitions-print';
	public const PAGE_SETTINGS     = 'ufsc-competitions-settings';
	public const PAGE_GUIDE        = 'ufsc-competitions-guide';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu(): void {
		$cap = 'manage_options'; // à adapter si tu as une capability UFSC

		add_menu_page(
			__( 'Compétitions', 'ufsc-licence-competition' ),
			__( 'Compétitions', 'ufsc-licence-competition' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_root' ),
			'dashicons-awards',
			56
		);

		// Sous-menus (chargés seulement si les classes existent)
		$this->add_submenu_safe(
			$cap,
			self::PAGE_COMPETITIONS,
			__( 'Compétitions', 'ufsc-licence-competition' ),
			__( 'Compétitions', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Competitions_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_CATEGORIES,
			__( 'Catégories', 'ufsc-licence-competition' ),
			__( 'Catégories', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Categories_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_ENTRIES,
			__( 'Inscriptions', 'ufsc-licence-competition' ),
			__( 'Inscriptions', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Entries_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_BOUTS,
			__( 'Combats', 'ufsc-licence-competition' ),
			__( 'Combats', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Bouts_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_QUALITY,
			__( 'Qualité', 'ufsc-licence-competition' ),
			__( 'Qualité', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Quality_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_PRINT,
			__( 'Impression', 'ufsc-licence-competition' ),
			__( 'Impression', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Print_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_SETTINGS,
			__( 'Paramètres', 'ufsc-licence-competition' ),
			__( 'Paramètres', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Settings_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_GUIDE,
			__( 'Guide', 'ufsc-licence-competition' ),
			__( 'Guide', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Guide_Page'
		);
	}

	private function add_submenu_safe( string $cap, string $slug, string $page_title, string $menu_title, string $page_class ): void {
		$callback = function() use ( $page_class ) {
			if ( class_exists( $page_class ) ) {
				$obj = new $page_class();

				if ( method_exists( $obj, 'render' ) ) {
					$obj->render();
					return;
				}
				if ( method_exists( $obj, 'output' ) ) {
					$obj->output();
					return;
				}
			}

			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
			echo '<p><strong>' . esc_html__( 'Sous-menu non chargé :', 'ufsc-licence-competition' ) . '</strong> ' . esc_html( $page_class ) . '</p>';
			echo '<p>' . esc_html__( 'Vérifie que la classe existe et expose une méthode render() ou output().', 'ufsc-licence-competition' ) . '</p>';
			echo '</div>';
		};

		$hook_suffix = add_submenu_page(
			self::MENU_SLUG,
			$page_title,
			$menu_title,
			$cap,
			$slug,
			$callback
		);
		if ( $hook_suffix && class_exists( '\UFSC\Competitions\Admin\Assets' ) ) {
			$assets = new Assets();
			$assets->register( $hook_suffix, self::PAGE_COMPETITIONS === $slug );
		}
	}

	public function render_root(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
		echo '<p>' . esc_html__( 'Menu Compétitions actif. Utilise les sous-menus pour accéder aux pages.', 'ufsc-licence-competition' ) . '</p>';
		echo '</div>';
	}
}
