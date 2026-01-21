<?php

namespace UFSC\Competitions\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {

	const MENU_SLUG = 'ufsc-competitions';

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
		$this->add_submenu_safe( $cap, self::MENU_SLUG, __( 'Compétitions', 'ufsc-licence-competition' ), __( 'Compétitions', 'ufsc-licence-competition' ), 'UFSC\\Competitions\\Admin\\Pages\\Competitions_Page' );
		$this->add_submenu_safe( $cap, 'ufsc-competitions-categories', __( 'Catégories', 'ufsc-licence-competition' ), __( 'Catégories', 'ufsc-licence-competition' ), 'UFSC\\Competitions\\Admin\\Pages\\Categories_Page' );
		$this->add_submenu_safe( $cap, 'ufsc-competitions-entries', __( 'Inscriptions', 'ufsc-licence-competition' ), __( 'Inscriptions', 'ufsc-licence-competition' ), 'UFSC\\Competitions\\Admin\\Pages\\Entries_Page' );
		$this->add_submenu_safe( $cap, 'ufsc-competitions-bouts', __( 'Combats', 'ufsc-licence-competition' ), __( 'Combats', 'ufsc-licence-competition' ), 'UFSC\\Competitions\\Admin\\Pages\\Bouts_Page' );
		$this->add_submenu_safe( $cap, 'ufsc-competitions-quality', __( 'Qualité', 'ufsc-licence-competition' ), __( 'Qualité', 'ufsc-licence-competition' ), 'UFSC\\Competitions\\Admin\\Pages\\Quality_Page' );
		$this->add_submenu_safe( $cap, 'ufsc-competitions-print', __( 'Impression', 'ufsc-licence-competition' ), __( 'Impression', 'ufsc-licence-competition' ), 'UFSC\\Competitions\\Admin\\Pages\\Print_Page' );
		$this->add_submenu_safe( $cap, 'ufsc-competitions-settings', __( 'Paramètres', 'ufsc-licence-competition' ), __( 'Paramètres', 'ufsc-licence-competition' ), 'UFSC\\Competitions\\Admin\\Pages\\Settings_Page' );
		$this->add_submenu_safe( $cap, 'ufsc-competitions-guide', __( 'Guide', 'ufsc-licence-competition' ), __( 'Guide', 'ufsc-licence-competition' ), 'UFSC\\Competitions\\Admin\\Pages\\Guide_Page' );
	}

	private function add_submenu_safe( string $cap, string $slug, string $page_title, string $menu_title, string $page_class ): void {
		// Callback safe: si la page n'existe pas / pas de render(), on affiche un message au lieu de planter.
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

		add_submenu_page(
			self::MENU_SLUG,
			$page_title,
			$menu_title,
			$cap,
			$slug,
			$callback
		);
	}

	public function render_root(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
		echo '<p>' . esc_html__( 'Menu Compétitions actif. Utilise les sous-menus pour accéder aux pages.', 'ufsc-licence-competition' ) . '</p>';
		echo '</div>';
	}
}
