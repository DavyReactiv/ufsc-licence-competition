<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Placeholder_Page {
	public function render() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php esc_html_e( 'Module Compétitions UFSC', 'ufsc-licence-competition' ); ?></h1>
			<p><?php esc_html_e( 'Cette section est prête pour les prochains sprints (inscriptions, combats, impression, paramètres).', 'ufsc-licence-competition' ); ?></p>
		</div>
		<?php
	}
}
