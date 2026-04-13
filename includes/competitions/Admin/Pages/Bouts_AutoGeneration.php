<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Services\FightAutoGenerationService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bouts_AutoGeneration {
	public static function register_actions(): void {
		if ( ! FightAutoGenerationService::is_enabled() ) {
			return;
		}

		add_action( 'admin_post_ufsc_competitions_save_fight_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_ufsc_competitions_generate_fight_draft', array( __CLASS__, 'handle_generate_draft' ) );
		add_action( 'admin_post_ufsc_competitions_generate_fight_draft_override', array( __CLASS__, 'handle_generate_draft_override' ) );
		add_action( 'admin_post_ufsc_competitions_regenerate_fight_draft', array( __CLASS__, 'handle_regenerate_draft' ) );
		add_action( 'admin_post_ufsc_competitions_validate_fight_draft', array( __CLASS__, 'handle_validate_draft' ) );
		add_action( 'admin_post_ufsc_competitions_discard_fight_draft', array( __CLASS__, 'handle_discard_draft' ) );
		add_action( 'admin_post_ufsc_competitions_recalc_fight_schedule', array( __CLASS__, 'handle_recalc_schedule' ) );
		add_action( 'admin_post_ufsc_competitions_swap_fight_colors', array( __CLASS__, 'handle_swap_colors' ) );
		add_action( 'admin_post_ufsc_competitions_reorder_fights', array( __CLASS__, 'handle_reorder_fights' ) );
	}

	public static function render_notice( string $notice, string $message = '' ): void {
		$messages = array(
			'settings_saved' => __( 'Paramètres enregistrés.', 'ufsc-licence-competition' ),
			'draft_ready' => __( 'Brouillon généré.', 'ufsc-licence-competition' ),
			'draft_error' => __( 'Impossible de générer le brouillon.', 'ufsc-licence-competition' ),
			'draft_validated' => __( 'Combats enregistrés.', 'ufsc-licence-competition' ),
			'draft_discarded' => __( 'Brouillon supprimé.', 'ufsc-licence-competition' ),
			'schedule_recalc' => __( 'Horaires recalculés.', 'ufsc-licence-competition' ),
			'swap_ok' => __( 'Couleurs inversées.', 'ufsc-licence-competition' ),
			'reorder_ok' => __( 'Combats réordonnés.', 'ufsc-licence-competition' ),
			'action_error' => __( 'Action impossible.', 'ufsc-licence-competition' ),
			'invalid_settings' => __( 'Paramètres invalides : vérifiez les surfaces et les champs timing (minutes/secondes).', 'ufsc-licence-competition' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type = 'error' === $notice || 'draft_error' === $notice || 'action_error' === $notice ? 'error' : 'success';
		$text = $message ? $message : $messages[ $notice ];

		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	public static function render_panel( int $competition_id ): void {
		if ( ! FightAutoGenerationService::is_enabled() ) {
			return;
		}

		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$draft = $competition_id ? FightAutoGenerationService::get_draft( $competition_id ) : array();
		$draft_stats = $draft['stats'] ?? array();
		$draft_warnings = $draft['warnings'] ?? array();
		$locked = ! empty( $settings['auto_lock'] );
		$manual_mode = 'manual' === ( $settings['mode'] ?? 'auto' );
		$can_generate = $competition_id && ! $locked && ! $manual_mode;
		$has_draft = ! empty( $draft['fights'] );
		$counters = $competition_id ? FightAutoGenerationService::get_generation_counters( $competition_id, $settings ) : array(
			'total_entries' => 0,
			'eligible_entries' => 0,
			'excluded_unweighed' => 0,
			'can_override_unweighed' => false,
		);
		?>
		<div class="ufsc-competitions-box">
			<h2><?php esc_html_e( 'Génération avancée des combats', 'ufsc-licence-competition' ); ?></h2>
			<p><?php esc_html_e( 'Générez un brouillon basé sur les inscriptions approuvées, puis validez avant écriture définitive.', 'ufsc-licence-competition' ); ?></p>

			<ul>
				<li><?php echo esc_html( sprintf( __( 'Inscriptions totales : %d', 'ufsc-licence-competition' ), (int) ( $counters['total_entries'] ?? 0 ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Inscriptions éligibles : %d', 'ufsc-licence-competition' ), (int) ( $counters['eligible_entries'] ?? 0 ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Exclues (non pesées) : %d', 'ufsc-licence-competition' ), (int) ( $counters['excluded_unweighed'] ?? 0 ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Byes estimés (si tableau incomplet) : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['bye_slots'] ?? 0 ) ) ); ?></li>
			</ul>
			<details class="ufsc-competitions-helper-details">
				<summary><?php esc_html_e( 'Aide rapide génération (ordre, poules, byes, phases)', 'ufsc-licence-competition' ); ?></summary>
				<ul>
					<li><?php esc_html_e( 'Ordre par défaut : catégories jeunes puis âgées, puis légers vers lourds, puis sexe/discipline/niveau.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Formats auto : 2=finale directe, 3=poule, 4=demi-finales + finale, 5-8=tableau de 8, 9-16=tableau de 16.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Byes : les tableaux sont complétés à la puissance de 2 supérieure pour conserver un bracket lisible.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Phases usuelles : qualification, seizième, huitième, quart, demi-finale, finale.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Rouge/Bleu : attribution stable par ordre de génération, inversion manuelle disponible sur le brouillon.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Timing : profils catégorie si disponibles, sinon fallback global (durée/pause ci-dessus).', 'ufsc-licence-competition' ); ?></li>
				</ul>
			</details>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_fight_settings' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_fight_settings">
				<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_plateau_name"><?php esc_html_e( 'Nom du plateau', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="plateau_name" type="text" id="ufsc_plateau_name" class="regular-text" value="<?php echo esc_attr( $settings['plateau_name'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_surface_count"><?php esc_html_e( 'Nombre de surfaces', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="surface_count" type="number" min="1" max="32" id="ufsc_surface_count" value="<?php echo esc_attr( $settings['surface_count'] ); ?>">
									<p class="description"><?php esc_html_e( 'Valeur recommandée : 1 à 12 surfaces (max 32).', 'ufsc-licence-competition' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Surfaces', 'ufsc-licence-competition' ); ?></th>
						<td>
							<div class="ufsc-competitions-surfaces" data-surface-count="<?php echo esc_attr( $settings['surface_count'] ); ?>">
								<?php
								$surface_details = isset( $settings['surface_details'] ) && is_array( $settings['surface_details'] )
									? $settings['surface_details']
									: array();
								for ( $i = 0; $i < $settings['surface_count']; $i++ ) :
									$detail = $surface_details[ $i ] ?? array();
									$surface_name = (string) ( $detail['name'] ?? '' );
									$surface_type = (string) ( $detail['type'] ?? 'tatami' );
									?>
									<div class="ufsc-competitions-surface-row">
										<label>
											<?php echo esc_html( sprintf( __( 'Surface %d', 'ufsc-licence-competition' ), $i + 1 ) ); ?>
											<input name="surface_details[<?php echo esc_attr( $i ); ?>][name]" type="text" class="regular-text" value="<?php echo esc_attr( $surface_name ); ?>" placeholder="<?php echo esc_attr( (string) ( $i + 1 ) ); ?>">
										</label>
										<label>
											<?php esc_html_e( 'Type', 'ufsc-licence-competition' ); ?>
											<select name="surface_details[<?php echo esc_attr( $i ); ?>][type]" required>
												<option value="tatami" <?php selected( $surface_type, 'tatami' ); ?>><?php esc_html_e( 'Tatami', 'ufsc-licence-competition' ); ?></option>
												<option value="ring" <?php selected( $surface_type, 'ring' ); ?>><?php esc_html_e( 'Ring', 'ufsc-licence-competition' ); ?></option>
											</select>
										</label>
									</div>
								<?php endfor; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Le nombre de surfaces génère automatiquement les blocs ci-dessus.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_duration"><?php esc_html_e( 'Durée (minutes)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="fight_duration" type="number" min="0" max="30" id="ufsc_fight_duration" value="<?php echo esc_attr( $settings['fight_duration'] ); ?>">
									<p class="description"><?php esc_html_e( 'Utilisé pour l’horaire estimé lorsque aucun profil timing n’est appliqué.', 'ufsc-licence-competition' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_duration_seconds"><?php esc_html_e( 'Durée (secondes)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="fight_duration_seconds" type="number" min="0" max="59" id="ufsc_fight_duration_seconds" value="<?php echo esc_attr( $settings['fight_duration_seconds'] ?? 0 ); ?>">
									<p class="description"><?php esc_html_e( 'Exemple: 1 minute 30 = 1 min + 30 sec.', 'ufsc-licence-competition' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_break_duration"><?php esc_html_e( 'Pause (minutes)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="break_duration" type="number" min="0" max="30" id="ufsc_break_duration" value="<?php echo esc_attr( $settings['break_duration'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_break_duration_seconds"><?php esc_html_e( 'Pause (secondes)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="break_duration_seconds" type="number" min="0" max="59" id="ufsc_break_duration_seconds" value="<?php echo esc_attr( $settings['break_duration_seconds'] ?? 0 ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Timing global effectif', 'ufsc-licence-competition' ); ?></th>
						<td>
							<?php
							$fight_minutes = (int) ( $settings['fight_duration'] ?? 0 );
							$fight_seconds = (int) ( $settings['fight_duration_seconds'] ?? 0 );
							$pause_minutes = (int) ( $settings['break_duration'] ?? 0 );
							$pause_seconds = (int) ( $settings['break_duration_seconds'] ?? 0 );
							printf(
								'<strong>%s</strong>',
								esc_html(
									sprintf(
										/* translators: 1: fight duration mm:ss, 2: break duration mm:ss */
										__( 'Combat: %1$s — Pause inter-combat: %2$s', 'ufsc-licence-competition' ),
										sprintf( '%02d:%02d', $fight_minutes, $fight_seconds ),
										sprintf( '%02d:%02d', $pause_minutes, $pause_seconds )
									)
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_timing_mode"><?php esc_html_e( 'Timing combats', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="timing_mode" id="ufsc_timing_mode">
								<option value="global" <?php selected( $settings['timing_mode'], 'global' ); ?>><?php esc_html_e( 'Global (actuel)', 'ufsc-licence-competition' ); ?></option>
								<option value="category" <?php selected( $settings['timing_mode'], 'category' ); ?>><?php esc_html_e( 'Par catégories (profils)', 'ufsc-licence-competition' ); ?></option>
							</select>
							<p class="description">
								<?php
								printf(
									'%s <a href="%s">%s</a>. %s',
									esc_html__( 'Les profils se gèrent dans', 'ufsc-licence-competition' ),
									esc_url( admin_url( 'admin.php?page=ufsc-competitions-timing-profiles' ) ),
									esc_html__( 'Timing Profiles', 'ufsc-licence-competition' ),
									esc_html__( 'Si des profils existent déjà pour la discipline, ils sont appliqués automatiquement.', 'ufsc-licence-competition' )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_generation_mode"><?php esc_html_e( 'Mode', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="mode" id="ufsc_generation_mode">
								<option value="auto" <?php selected( $settings['mode'], 'auto' ); ?>><?php esc_html_e( 'Automatique', 'ufsc-licence-competition' ); ?></option>
								<option value="manual" <?php selected( $settings['mode'], 'manual' ); ?>><?php esc_html_e( 'Manuel', 'ufsc-licence-competition' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Le mode manuel désactive la génération automatique.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Override pesée', 'ufsc-licence-competition' ); ?></th>
						<td>
							<label>
								<input name="allow_unweighed" type="checkbox" value="1" <?php checked( $settings['allow_unweighed'] ?? 0, 1 ); ?>>
								<?php esc_html_e( 'Inclure les compétiteurs non pesés (override admin explicite)', 'ufsc-licence-competition' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Désactivé par défaut pour sécuriser la génération automatique.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verrouillage auto', 'ufsc-licence-competition' ); ?></th>
						<td>
							<label>
								<input name="auto_lock" type="checkbox" value="1" <?php checked( $settings['auto_lock'], 1 ); ?>>
								<?php esc_html_e( 'Empêcher la régénération automatique', 'ufsc-licence-competition' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Enregistrer les paramètres', 'ufsc-licence-competition' ) ); ?>
			</form>

			<?php if ( ! $competition_id ) : ?>
				<p class="description"><?php esc_html_e( 'Sélectionnez une compétition pour activer la génération.', 'ufsc-licence-competition' ); ?></p>
			<?php else : ?>
				<div class="ufsc-competitions-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::nonce_action( 'ufsc_competitions_generate_fight_draft', $competition_id ) ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_generate_fight_draft">
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
						<?php submit_button( __( 'Générer un brouillon', 'ufsc-licence-competition' ), 'secondary', '', false, $can_generate ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
					<?php if ( ! empty( $counters['can_override_unweighed'] ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( self::nonce_action( 'ufsc_competitions_generate_fight_draft_override', $competition_id ) ); ?>
							<input type="hidden" name="action" value="ufsc_competitions_generate_fight_draft_override">
							<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
							<?php submit_button( __( 'Override : inclure les non pesés', 'ufsc-licence-competition' ), 'secondary', '', false, $can_generate ? array( 'onclick' => "return confirm('" . esc_js( __( 'Confirmer l’override pesée pour cette génération ?', 'ufsc-licence-competition' ) ) . "');" ) : array( 'disabled' => 'disabled' ) ); ?>
						</form>
					<?php endif; ?>
					<?php if ( $has_draft ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( self::nonce_action( 'ufsc_competitions_regenerate_fight_draft', $competition_id ) ); ?>
							<input type="hidden" name="action" value="ufsc_competitions_regenerate_fight_draft">
							<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
							<?php submit_button( __( 'Régénérer (supprime le draft existant)', 'ufsc-licence-competition' ), 'secondary', '', false, $can_generate ? array( 'onclick' => "return confirm('" . esc_js( __( 'Confirmer la régénération ? Le brouillon actuel sera supprimé.', 'ufsc-licence-competition' ) ) . "');" ) : array( 'disabled' => 'disabled' ) ); ?>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_validate_fight_draft' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_validate_fight_draft">
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
						<fieldset class="ufsc-competitions-inline-options">
							<legend class="screen-reader-text"><?php esc_html_e( 'Mode de validation', 'ufsc-licence-competition' ); ?></legend>
							<label>
								<input type="radio" name="apply_mode" value="append" checked>
								<?php esc_html_e( 'Ajouter à la suite (par défaut)', 'ufsc-licence-competition' ); ?>
							</label>
							<label>
								<input type="radio" name="apply_mode" value="replace" disabled>
								<?php esc_html_e( 'Remplacer les combats existants (désactivé)', 'ufsc-licence-competition' ); ?>
							</label>
							<label class="description">
								<input type="checkbox" name="confirm_replace" value="1" disabled>
								<?php esc_html_e( 'Je confirme la suppression des combats existants', 'ufsc-licence-competition' ); ?>
							</label>
						</fieldset>
						<?php submit_button( __( 'Valider le brouillon', 'ufsc-licence-competition' ), 'primary', '', false, $has_draft ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_discard_fight_draft' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_discard_fight_draft">
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
					<?php submit_button( __( 'Supprimer le brouillon', 'ufsc-licence-competition' ), 'secondary', '', false, $draft ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
				</div>

				<?php if ( $draft ) : ?>
					<p><strong><?php esc_html_e( 'Brouillon actuel', 'ufsc-licence-competition' ); ?></strong></p>
					<ul>
						<li><?php echo esc_html( sprintf( __( 'Combats : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['fights'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Groupes : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['groups'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Entrées : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['entries'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Byes générés : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['bye_slots'] ?? 0 ) ) ); ?></li>
					</ul>
					<?php if ( $draft_warnings ) : ?>
						<p><strong><?php esc_html_e( 'Alertes', 'ufsc-licence-competition' ); ?></strong></p>
						<ul>
							<?php foreach ( $draft_warnings as $warning ) : ?>
								<li><?php echo esc_html( $warning ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>

				<hr />

				<h3><?php esc_html_e( 'Actions rapides', 'ufsc-licence-competition' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
					<?php wp_nonce_field( 'ufsc_competitions_recalc_fight_schedule' ); ?>
					<input type="hidden" name="action" value="ufsc_competitions_recalc_fight_schedule">
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
					<?php submit_button( __( 'Recalculer surfaces & horaires', 'ufsc-licence-competition' ), 'secondary', '', false, $has_draft ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
					<?php wp_nonce_field( 'ufsc_competitions_swap_fight_colors' ); ?>
					<input type="hidden" name="action" value="ufsc_competitions_swap_fight_colors">
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
					<label for="ufsc_swap_fight_id"><?php esc_html_e( 'N° combat du brouillon', 'ufsc-licence-competition' ); ?></label>
					<input name="fight_id" type="number" id="ufsc_swap_fight_id" min="1" />
					<?php submit_button( __( 'Inverser rouge/bleu', 'ufsc-licence-competition' ), 'secondary', '', false, $has_draft ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
					<?php wp_nonce_field( 'ufsc_competitions_reorder_fights' ); ?>
					<input type="hidden" name="action" value="ufsc_competitions_reorder_fights">
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
					<label for="ufsc_reorder_mode"><?php esc_html_e( 'Réordonner par', 'ufsc-licence-competition' ); ?></label>
					<select name="mode" id="ufsc_reorder_mode">
						<option value="fight_no"><?php esc_html_e( 'Numéro de combat', 'ufsc-licence-competition' ); ?></option>
						<option value="scheduled"><?php esc_html_e( 'Horaire', 'ufsc-licence-competition' ); ?></option>
						<option value="category"><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></option>
					</select>
					<?php submit_button( __( 'Réordonner', 'ufsc-licence-competition' ), 'secondary', '', false, $has_draft ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<script>
			(function() {
				const countInput = document.getElementById('ufsc_surface_count');
				const container = document.querySelector('.ufsc-competitions-surfaces');
				if (!countInput || !container) {
					return;
				}

				const buildRow = (index) => {
					const row = document.createElement('div');
					row.className = 'ufsc-competitions-surface-row';

					const label = document.createElement('label');
					label.textContent = `<?php echo esc_js( __( 'Surface', 'ufsc-licence-competition' ) ); ?> ${index + 1} `;

					const input = document.createElement('input');
					input.type = 'text';
					input.name = `surface_details[${index}][name]`;
					input.className = 'regular-text';
					input.placeholder = `${index + 1}`;
					label.appendChild(input);

					const typeLabel = document.createElement('label');
					typeLabel.textContent = '<?php echo esc_js( __( 'Type', 'ufsc-licence-competition' ) ); ?> ';

					const select = document.createElement('select');
					select.name = `surface_details[${index}][type]`;
					select.required = true;
					const tatami = new Option('<?php echo esc_js( __( 'Tatami', 'ufsc-licence-competition' ) ); ?>', 'tatami');
					const ring = new Option('<?php echo esc_js( __( 'Ring', 'ufsc-licence-competition' ) ); ?>', 'ring');
					select.appendChild(tatami);
					select.appendChild(ring);
					typeLabel.appendChild(select);

					row.appendChild(label);
					row.appendChild(typeLabel);
					return row;
				};

				const syncRows = () => {
					const targetCount = Math.max(1, parseInt(countInput.value || '1', 10));
					const rows = container.querySelectorAll('.ufsc-competitions-surface-row');
					if (rows.length > targetCount) {
						for (let i = rows.length - 1; i >= targetCount; i--) {
							rows[i].remove();
						}
					} else if (rows.length < targetCount) {
						for (let i = rows.length; i < targetCount; i++) {
							container.appendChild(buildRow(i));
						}
					}
				};

				countInput.addEventListener('input', syncRows);
			})();
		</script>
		<?php
	}

	public static function handle_save_settings(): void {
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		self::guard_action( 'ufsc_competitions_save_fight_settings', $competition_id );
		$result = FightAutoGenerationService::save_settings_with_result( $competition_id, wp_unslash( $_POST ) );
		if ( empty( $result['ok'] ) ) {
			self::redirect( $competition_id, 'invalid_settings', (string) ( $result['message'] ?? '' ) );
		}
		self::redirect( $competition_id, 'settings_saved' );
	}

	public static function handle_generate_draft(): void {
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		self::guard_action( self::nonce_action( 'ufsc_competitions_generate_fight_draft', $competition_id ), $competition_id );
		if ( ! $competition_id ) {
			self::redirect( 0, 'action_error', __( 'Compétition invalide.', 'ufsc-licence-competition' ) );
		}

		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$result = FightAutoGenerationService::generate_draft( $competition_id, $settings );
		self::redirect( $competition_id, $result['ok'] ? 'draft_ready' : 'draft_error', $result['message'] ?? '' );
	}

	public static function handle_generate_draft_override(): void {
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		self::guard_action( self::nonce_action( 'ufsc_competitions_generate_fight_draft_override', $competition_id ), $competition_id );
		if ( ! $competition_id ) {
			self::redirect( 0, 'action_error', __( 'Compétition invalide.', 'ufsc-licence-competition' ) );
		}

		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$settings['allow_unweighed'] = 1;
		$result = FightAutoGenerationService::generate_draft( $competition_id, $settings );
		self::redirect( $competition_id, $result['ok'] ? 'draft_ready' : 'draft_error', $result['message'] ?? '' );
	}

	public static function handle_regenerate_draft(): void {
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		self::guard_action( self::nonce_action( 'ufsc_competitions_regenerate_fight_draft', $competition_id ), $competition_id );
		if ( ! $competition_id ) {
			self::redirect( 0, 'action_error', __( 'Compétition invalide.', 'ufsc-licence-competition' ) );
		}

		FightAutoGenerationService::clear_draft( $competition_id );
		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$result = FightAutoGenerationService::generate_draft( $competition_id, $settings );
		self::redirect( $competition_id, $result['ok'] ? 'draft_ready' : 'draft_error', $result['message'] ?? '' );
	}

	public static function handle_validate_draft(): void {
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		self::guard_action( 'ufsc_competitions_validate_fight_draft', $competition_id );
		$apply_mode = isset( $_POST['apply_mode'] ) ? sanitize_key( wp_unslash( $_POST['apply_mode'] ) ) : 'append';
		if ( 'replace' === $apply_mode ) {
			self::redirect( $competition_id, 'action_error', __( 'Le mode remplacement n’est pas disponible.', 'ufsc-licence-competition' ) );
		}

		$result = FightAutoGenerationService::validate_and_apply_draft( $competition_id, $apply_mode );
		self::redirect( $competition_id, $result['ok'] ? 'draft_validated' : 'action_error', $result['message'] ?? '' );
	}

	public static function handle_discard_draft(): void {
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		self::guard_action( 'ufsc_competitions_discard_fight_draft', $competition_id );
		FightAutoGenerationService::clear_draft( $competition_id );
		self::redirect( $competition_id, 'draft_discarded' );
	}

	public static function handle_recalc_schedule(): void {
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		self::guard_action( 'ufsc_competitions_recalc_fight_schedule', $competition_id );
		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$result = FightAutoGenerationService::recalc_schedule( $competition_id, $settings );
		self::redirect( $competition_id, $result['ok'] ? 'schedule_recalc' : 'action_error', $result['message'] ?? '' );
	}

	public static function handle_swap_colors(): void {
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		self::guard_action( 'ufsc_competitions_swap_fight_colors', $competition_id );
		$fight_id = isset( $_POST['fight_id'] ) ? absint( $_POST['fight_id'] ) : 0;
		if ( ! $fight_id ) {
			self::redirect( $competition_id, 'action_error', __( 'Combat invalide.', 'ufsc-licence-competition' ) );
		}
		$result = FightAutoGenerationService::swap_colors( $competition_id, $fight_id );
		self::redirect( $competition_id, $result['ok'] ? 'swap_ok' : 'action_error', $result['message'] ?? '' );
	}

	public static function handle_reorder_fights(): void {
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		self::guard_action( 'ufsc_competitions_reorder_fights', $competition_id );
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'fight_no';
		$result = FightAutoGenerationService::reorder_fights( $competition_id, $mode );
		self::redirect( $competition_id, $result['ok'] ? 'reorder_ok' : 'action_error', $result['message'] ?? '' );
	}

	private static function ensure_manage_access(): void {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
	}

	private static function guard_action( string $nonce_action, int $competition_id ): void {
		if ( ! FightAutoGenerationService::is_enabled() ) {
			self::redirect( $competition_id, 'action_error', __( 'La génération automatique est désactivée.', 'ufsc-licence-competition' ) );
		}

		self::ensure_manage_access();
		check_admin_referer( $nonce_action );
	}

	private static function nonce_action( string $base_action, int $competition_id ): string {
		return sanitize_key( $base_action ) . '_' . absint( $competition_id );
	}

	private static function redirect( int $competition_id, string $notice, string $message = '' ): void {
		$args = array(
			'page' => Menu::PAGE_BOUTS,
			'ufsc_fight_notice' => $notice,
		);

		if ( $competition_id ) {
			$args['ufsc_competition_id'] = $competition_id;
		}

		if ( '' !== $message ) {
			$args['ufsc_fight_message'] = $message;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
