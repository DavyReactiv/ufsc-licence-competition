<?php

namespace UFSC\Competitions\Admin;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Services\CompetitionMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adds configurable document/control reminders to the competition form. */
class CompetitionRequirementsAdmin {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'ufsc_competition_meta_before_save', array( __CLASS__, 'capture_posted_requirements' ), 20, 2 );
		add_action( 'admin_footer', array( __CLASS__, 'render_fields' ), 30 );
	}

	public static function capture_posted_requirements( $data, $competition_id ): array {
		$data           = is_array( $data ) ? $data : array();
		$competition_id = absint( $competition_id );
		if ( $competition_id <= 0 ) {
			return $data;
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the competition controller verifies its nonce before CompetitionMeta::save().
		if ( 'ufsc_competitions_save_competition' !== $action ) {
			return $data;
		}

		$mode = isset( $_POST['requirements_mode'] ) ? sanitize_key( wp_unslash( $_POST['requirements_mode'] ) ) : 'auto'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $mode, array( 'auto', 'custom' ), true ) ) {
			$mode = 'auto';
		}

		$data['requirements_mode']            = $mode;
		$data['check_medical_document']       = isset( $_POST['check_medical_document'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$data['check_parental_authorization'] = isset( $_POST['check_parental_authorization'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$data['check_sport_passport']         = isset( $_POST['check_sport_passport'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$data['requirements_note']            = isset( $_POST['requirements_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['requirements_note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return $data;
	}

	public static function render_fields(): void {
		if ( ! is_admin() ) {
			return;
		}

		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( Menu::MENU_SLUG !== $page || ! in_array( $action, array( 'add', 'edit' ), true ) ) {
			return;
		}

		$can_edit = 'add' === $action ? Capabilities::user_can_create() : Capabilities::user_can_edit();
		if ( ! $can_edit ) {
			return;
		}

		$competition_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$meta           = CompetitionMeta::get( $competition_id );
		$mode           = sanitize_key( (string) ( $meta['requirements_mode'] ?? 'auto' ) );
		if ( ! in_array( $mode, array( 'auto', 'custom' ), true ) ) {
			$mode = 'auto';
		}
		?>
		<div id="ufsc-competition-requirements-fields" hidden>
			<h2><?php esc_html_e( 'Pièces & contrôles demandés aux clubs', 'ufsc-licence-competition' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Ces réglages pilotent les rappels affichés dans l’espace club. Ils n’ajoutent pas automatiquement une obligation juridique : le règlement applicable et les consignes officielles de l’événement restent la référence.', 'ufsc-licence-competition' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="requirements_mode"><?php esc_html_e( 'Mode', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="requirements_mode" id="requirements_mode">
								<option value="auto" <?php selected( $mode, 'auto' ); ?>><?php esc_html_e( 'Automatique recommandé', 'ufsc-licence-competition' ); ?></option>
								<option value="custom" <?php selected( $mode, 'custom' ); ?>><?php esc_html_e( 'Personnalisé', 'ufsc-licence-competition' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'En automatique : rappel médical et autorisation parentale, plus passeport sportif pour les compétitions fédérales.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
					<tr class="ufsc-requirements-custom-row">
						<th scope="row"><?php esc_html_e( 'Contrôles affichés', 'ufsc-licence-competition' ); ?></th>
						<td>
							<label><input type="checkbox" name="check_medical_document" value="1" <?php checked( ! empty( $meta['check_medical_document'] ) ); ?> /> <?php esc_html_e( 'Certificat / justificatif médical à vérifier', 'ufsc-licence-competition' ); ?></label><br />
							<label><input type="checkbox" name="check_parental_authorization" value="1" <?php checked( ! empty( $meta['check_parental_authorization'] ) ); ?> /> <?php esc_html_e( 'Autorisation parentale / représentant légal pour mineur à vérifier', 'ufsc-licence-competition' ); ?></label><br />
							<label><input type="checkbox" name="check_sport_passport" value="1" <?php checked( ! empty( $meta['check_sport_passport'] ) ); ?> /> <?php esc_html_e( 'Passeport sportif à vérifier', 'ufsc-licence-competition' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="requirements_note"><?php esc_html_e( 'Consigne complémentaire', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<textarea name="requirements_note" id="requirements_note" class="large-text" rows="3" maxlength="1000"><?php echo esc_textarea( (string) ( $meta['requirements_note'] ?? '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Exemple : apporter l’original du passeport sportif le jour de la pesée.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<script>
		(function () {
			'use strict';
			var block = document.getElementById('ufsc-competition-requirements-fields');
			var actionInput = document.querySelector('input[name="action"][value="ufsc_competitions_save_competition"]');
			var form = actionInput ? actionInput.closest('form') : null;
			if (!block || !form) {
				return;
			}

			var submit = form.querySelector('.submit');
			block.hidden = false;
			if (submit && submit.parentNode === form) {
				form.insertBefore(block, submit);
			} else {
				form.appendChild(block);
			}

			var mode = block.querySelector('#requirements_mode');
			var customRows = block.querySelectorAll('.ufsc-requirements-custom-row');
			var sync = function () {
				var show = mode && mode.value === 'custom';
				customRows.forEach(function (row) {
					row.style.display = show ? '' : 'none';
				});
			};
			if (mode) {
				mode.addEventListener('change', sync);
			}
			sync();
		}());
		</script>
		<?php
	}
}