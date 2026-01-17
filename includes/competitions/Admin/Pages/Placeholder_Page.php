<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Placeholder_Page {
	public function render() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$title = __( 'Module Compétitions UFSC', 'ufsc-licence-competition' );
		$message = __( 'Cette section est prête pour les prochains sprints (inscriptions, combats, impression, paramètres).', 'ufsc-licence-competition' );

		if ( Menu::PAGE_BOUTS === $page ) {
			$title = __( 'Combats', 'ufsc-licence-competition' );
			$message = __( 'Générer et gérer oppositions, saisir résultats, progression bracket.', 'ufsc-licence-competition' );
		}

		if ( Menu::PAGE_SETTINGS === $page ) {
			$title = __( 'Paramètres', 'ufsc-licence-competition' );
			$message = __( 'Réglages globaux (disciplines, tolérance pesée, âge référence…).', 'ufsc-licence-competition' );
		}

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $title ); ?></h1>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php echo esc_html( $message ); ?></p></div>
		</div>
		<?php
	}
}
