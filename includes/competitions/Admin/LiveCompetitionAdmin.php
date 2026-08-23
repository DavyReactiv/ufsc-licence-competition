<?php

namespace UFSC\Competitions\Admin;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Services\CompetitionMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adds the public-live toggle to competition add/edit forms. */
class LiveCompetitionAdmin {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'ufsc_competition_meta_before_save', array( __CLASS__, 'capture_live_setting' ), 25, 2 );
		add_action( 'admin_footer', array( __CLASS__, 'render_fields' ), 35 );
	}

	public static function capture_live_setting( $data, $competition_id ): array {
		$data           = is_array( $data ) ? $data : array();
		$competition_id = absint( $competition_id );
		if ( $competition_id <= 0 ) {
			return $data;
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- parent controller verifies nonce before CompetitionMeta::save().
		if ( 'ufsc_competitions_save_competition' !== $action ) {
			return $data;
		}

		$data['live_enabled'] = isset( $_POST['live_enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
		?>
		<div id="ufsc-competition-live-fields" hidden>
			<h2><?php esc_html_e( 'UFSC LIVE', 'ufsc-licence-competition' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Diffusion publique', 'ufsc-licence-competition' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="live_enabled" value="1" <?php checked( ! empty( $meta['live_enabled'] ) ); ?> />
								<?php esc_html_e( 'Activer les combats et résultats en direct', 'ufsc-licence-competition' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Lecture seule. Aucune donnée de contact, date de naissance, poids de pesée ou note administrative n’est exposée.', 'ufsc-licence-competition' ); ?></p>
							<?php if ( $competition_id > 0 ) : ?>
								<p class="description"><code>[ufsc_competition_live id="<?php echo esc_attr( (string) $competition_id ); ?>"]</code></p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<script>
		(function () {
			'use strict';
			var block = document.getElementById('ufsc-competition-live-fields');
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
		}());
		</script>
		<?php
	}
}
