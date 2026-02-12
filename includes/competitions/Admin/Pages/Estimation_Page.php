<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Services\CompetitionScheduleEstimator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estimation_Page {
	public function render(): void {
		if ( ! Capabilities::user_can_read() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_repo = new CompetitionRepository();
		$filters = array( 'view' => 'all_with_archived' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$filters = ufsc_lc_competitions_apply_scope_to_query_args( $filters );
		}
		$competitions = $competition_repo->list( $filters, 200, 0 );

		$result = null;
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ufsc_estimation_nonce'] ) ) {
			check_admin_referer( 'ufsc_competitions_estimation', 'ufsc_estimation_nonce' );
			$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
			$day_slots = isset( $_POST['day_slots'] ) && is_array( $_POST['day_slots'] ) ? wp_unslash( $_POST['day_slots'] ) : array();
			$breaks = isset( $_POST['breaks'] ) && is_array( $_POST['breaks'] ) ? wp_unslash( $_POST['breaks'] ) : array();
			$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'draft';
			$estimator = new CompetitionScheduleEstimator();
			$result = $estimator->estimate( $competition_id, $day_slots, $breaks, $source );
		}
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php esc_html_e( 'Estimation planning', 'ufsc-licence-competition' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'ufsc_competitions_estimation', 'ufsc_estimation_nonce' ); ?>
				<p>
					<select name="competition_id" required>
						<option value="0"><?php esc_html_e( 'Choisir une compétition', 'ufsc-licence-competition' ); ?></option>
						<?php foreach ( $competitions as $competition ) : ?>
							<option value="<?php echo esc_attr( $competition->id ); ?>"><?php echo esc_html( $competition->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="source">
						<option value="draft"><?php esc_html_e( 'Brouillon', 'ufsc-licence-competition' ); ?></option>
						<option value="scheduled"><?php esc_html_e( 'Planifié', 'ufsc-licence-competition' ); ?></option>
					</select>
				</p>
				<p><?php esc_html_e( 'Créneau 1', 'ufsc-licence-competition' ); ?>
					<input type="date" name="day_slots[0][date]" required>
					<input type="time" name="day_slots[0][start_time]" value="09:00" required>
					<input type="time" name="day_slots[0][end_time]" value="18:00" required>
				</p>
				<p><?php esc_html_e( 'Entracte (optionnel)', 'ufsc-licence-competition' ); ?>
					<input type="datetime-local" name="breaks[0][start_datetime]">
					<input type="datetime-local" name="breaks[0][end_datetime]">
				</p>
				<?php submit_button( __( 'Calculer', 'ufsc-licence-competition' ) ); ?>
			</form>
			<?php if ( is_array( $result ) ) : ?>
				<h2><?php esc_html_e( 'Résultat', 'ufsc-licence-competition' ); ?></h2>
				<ul>
					<li><?php echo esc_html( sprintf( __( 'Nombre de combats: %d', 'ufsc-licence-competition' ), (int) ( $result['total_fights'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Début estimé: %s', 'ufsc-licence-competition' ), (string) ( $result['estimated_start_datetime'] ?? '—' ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Fin estimée: %s', 'ufsc-licence-competition' ), (string) ( $result['estimated_end_datetime'] ?? '—' ) ) ); ?></li>
					<li><?php echo esc_html( ! empty( $result['overflow'] ) ? __( 'Attention: overflow (créneaux insuffisants)', 'ufsc-licence-competition' ) : __( 'Créneaux suffisants', 'ufsc-licence-competition' ) ); ?></li>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
