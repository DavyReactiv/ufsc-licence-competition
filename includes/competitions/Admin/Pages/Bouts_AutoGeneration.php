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
		?>
		<div class="ufsc-competitions-box">
			<h2><?php esc_html_e( 'Génération avancée des combats', 'ufsc-licence-competition' ); ?></h2>
			<p><?php esc_html_e( 'Générez un brouillon basé sur les inscriptions validées, puis validez avant écriture définitive.', 'ufsc-licence-competition' ); ?></p>

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
						<td><input name="surface_count" type="number" min="1" id="ufsc_surface_count" value="<?php echo esc_attr( $settings['surface_count'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_surface_labels"><?php esc_html_e( 'Noms des surfaces', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<input name="surface_labels" type="text" id="ufsc_surface_labels" class="regular-text" value="<?php echo esc_attr( $settings['surface_labels'] ); ?>">
							<p class="description"><?php esc_html_e( 'Séparer par virgules pour nommer chaque surface.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_fight_duration"><?php esc_html_e( 'Durée (minutes)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="fight_duration" type="number" min="1" id="ufsc_fight_duration" value="<?php echo esc_attr( $settings['fight_duration'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_break_duration"><?php esc_html_e( 'Pause (minutes)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="break_duration" type="number" min="0" id="ufsc_break_duration" value="<?php echo esc_attr( $settings['break_duration'] ); ?>"></td>
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
						<?php wp_nonce_field( 'ufsc_competitions_generate_fight_draft' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_generate_fight_draft">
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
						<?php submit_button( __( 'Générer un brouillon', 'ufsc-licence-competition' ), 'secondary', '', false, $can_generate ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_validate_fight_draft' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_validate_fight_draft">
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
						<?php submit_button( __( 'Valider le brouillon', 'ufsc-licence-competition' ), 'primary', '', false, empty( $draft['fights'] ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_discard_fight_draft' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_discard_fight_draft">
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
						<?php submit_button( __( 'Supprimer le brouillon', 'ufsc-licence-competition' ), 'secondary', '', false, empty( $draft ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
					</form>
				</div>

				<?php if ( $draft ) : ?>
					<p><strong><?php esc_html_e( 'Brouillon actuel', 'ufsc-licence-competition' ); ?></strong></p>
					<ul>
						<li><?php echo esc_html( sprintf( __( 'Combats : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['fights'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Groupes : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['groups'] ?? 0 ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Entrées : %d', 'ufsc-licence-competition' ), (int) ( $draft_stats['entries'] ?? 0 ) ) ); ?></li>
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
					<?php submit_button( __( 'Recalculer surfaces & horaires', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
					<?php wp_nonce_field( 'ufsc_competitions_swap_fight_colors' ); ?>
					<input type="hidden" name="action" value="ufsc_competitions_swap_fight_colors">
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>">
					<label for="ufsc_swap_fight_id"><?php esc_html_e( 'ID combat à inverser', 'ufsc-licence-competition' ); ?></label>
					<input name="fight_id" type="number" id="ufsc_swap_fight_id" min="1" />
					<?php submit_button( __( 'Inverser rouge/bleu', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
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
					<?php submit_button( __( 'Réordonner', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_save_settings(): void {
		self::ensure_manage_access();
		check_admin_referer( 'ufsc_competitions_save_fight_settings' );

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		FightAutoGenerationService::save_settings( $competition_id, wp_unslash( $_POST ) );
		self::redirect( $competition_id, 'settings_saved' );
	}

	public static function handle_generate_draft(): void {
		self::ensure_manage_access();
		check_admin_referer( 'ufsc_competitions_generate_fight_draft' );

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$result = FightAutoGenerationService::generate_draft( $competition_id, $settings );
		self::redirect( $competition_id, $result['ok'] ? 'draft_ready' : 'draft_error', $result['message'] ?? '' );
	}

	public static function handle_validate_draft(): void {
		self::ensure_manage_access();
		check_admin_referer( 'ufsc_competitions_validate_fight_draft' );

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$result = FightAutoGenerationService::validate_and_apply_draft( $competition_id );
		self::redirect( $competition_id, $result['ok'] ? 'draft_validated' : 'action_error', $result['message'] ?? '' );
	}

	public static function handle_discard_draft(): void {
		self::ensure_manage_access();
		check_admin_referer( 'ufsc_competitions_discard_fight_draft' );

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		FightAutoGenerationService::clear_draft( $competition_id );
		self::redirect( $competition_id, 'draft_discarded' );
	}

	public static function handle_recalc_schedule(): void {
		self::ensure_manage_access();
		check_admin_referer( 'ufsc_competitions_recalc_fight_schedule' );

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$settings = FightAutoGenerationService::get_settings( $competition_id );
		$result = FightAutoGenerationService::recalc_schedule( $competition_id, $settings );
		self::redirect( $competition_id, $result['ok'] ? 'schedule_recalc' : 'action_error', $result['message'] ?? '' );
	}

	public static function handle_swap_colors(): void {
		self::ensure_manage_access();
		check_admin_referer( 'ufsc_competitions_swap_fight_colors' );

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$fight_id = isset( $_POST['fight_id'] ) ? absint( $_POST['fight_id'] ) : 0;
		$result = FightAutoGenerationService::swap_colors( $fight_id );
		self::redirect( $competition_id, $result['ok'] ? 'swap_ok' : 'action_error', $result['message'] ?? '' );
	}

	public static function handle_reorder_fights(): void {
		self::ensure_manage_access();
		check_admin_referer( 'ufsc_competitions_reorder_fights' );

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'fight_no';
		$result = FightAutoGenerationService::reorder_fights( $competition_id, $mode );
		self::redirect( $competition_id, $result['ok'] ? 'reorder_ok' : 'action_error', $result['message'] ?? '' );
	}

	private static function ensure_manage_access(): void {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
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
