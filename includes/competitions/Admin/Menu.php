<?php

namespace UFSC\Competitions\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu(): void {
		$cap = 'manage_options'; // adapte si tu as une capability UFSC dédiée

		add_menu_page(
			__( 'Compétitions', 'ufsc-licence-competition' ),
			__( 'Compétitions', 'ufsc-licence-competition' ),
			$cap,
			'ufsc-competitions',
			array( $this, 'render_root' ),
			'dashicons-awards',
			56
		);

		// Sous-menu "Liste" (redirige vers la page root)
		add_submenu_page(
			'ufsc-competitions',
			__( 'Liste des compétitions', 'ufsc-licence-competition' ),
			__( 'Liste', 'ufsc-licence-competition' ),
			$cap,
			'ufsc-competitions',
			array( $this, 'render_root' )
		);

		// (Optionnel) Tu pourras ajouter tes pages réelles ensuite, mais déjà ça doit réafficher le menu.
	}

	public function render_root(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
		echo '<p>' . esc_html__( 'Menu Compétitions actif. Si tu vois cette page, le chargement admin est OK.', 'ufsc-licence-competition' ) . '</p>';
		echo '</div>';
	}
}
