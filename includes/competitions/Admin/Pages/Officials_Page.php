<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\CompetitionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Officials_Page {
	private const OPTION_KEY = 'ufsc_competitions_officials';
	private $competitions;

	public function __construct() {
		$this->competitions = new CompetitionRepository();
	}

	public function render(): void {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$notice = $this->maybe_handle_post( $competition_id );
		$all = $this->get_all_officials();
		$items = $competition_id ? ( $all[ $competition_id ] ?? array() ) : array();

		$filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$filters = ufsc_lc_competitions_apply_scope_to_query_args( $filters );
		}
		$competitions = $this->competitions->list( $filters, 200, 0 );
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php esc_html_e( 'Officiels', 'ufsc-licence-competition' ); ?></h1>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Enregistrez superviseurs, arbitres, juges et responsables de surface sans complexifier le workflow.', 'ufsc-licence-competition' ); ?></p></div>
			<?php if ( $notice ) : ?><div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div><?php endif; ?>

			<form method="get" class="ufsc-competitions-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_OFFICIALS ); ?>" />
				<select name="competition_id" required>
					<option value="0"><?php esc_html_e( 'Sélectionner une compétition', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $competitions as $competition ) : ?>
						<option value="<?php echo esc_attr( (int) $competition->id ); ?>" <?php selected( $competition_id, (int) $competition->id ); ?>><?php echo esc_html( (string) $competition->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Charger', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
			</form>

			<?php if ( $competition_id > 0 ) : ?>
			<form method="post" class="ufsc-admin-surface" style="margin-bottom:16px;">
				<?php wp_nonce_field( 'ufsc_officials_save_' . $competition_id ); ?>
				<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>" />
				<input type="hidden" name="ufsc_officials_action" value="add" />
				<table class="form-table" role="presentation">
					<tr><th><label><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></label></th><td><input type="text" name="first_name" required class="regular-text" /></td></tr>
					<tr><th><label><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></label></th><td><input type="text" name="last_name" required class="regular-text" /></td></tr>
					<tr><th><label><?php esc_html_e( 'Rôle', 'ufsc-licence-competition' ); ?></label></th><td><select name="role"><option value="supervisor"><?php esc_html_e( 'Superviseur', 'ufsc-licence-competition' ); ?></option><option value="assistant_supervisor"><?php esc_html_e( 'Superviseur adjoint', 'ufsc-licence-competition' ); ?></option><option value="referee"><?php esc_html_e( 'Arbitre', 'ufsc-licence-competition' ); ?></option><option value="judge"><?php esc_html_e( 'Juge', 'ufsc-licence-competition' ); ?></option><option value="surface_manager"><?php esc_html_e( 'Responsable surface', 'ufsc-licence-competition' ); ?></option><option value="timekeeper"><?php esc_html_e( 'Chronométreur', 'ufsc-licence-competition' ); ?></option></select></td></tr>
					<tr><th><label><?php esc_html_e( 'Téléphone', 'ufsc-licence-competition' ); ?></label></th><td><input type="text" name="phone" class="regular-text" /></td></tr>
					<tr><th><label><?php esc_html_e( 'Email', 'ufsc-licence-competition' ); ?></label></th><td><input type="email" name="email" class="regular-text" /></td></tr>
					<tr><th><label><?php esc_html_e( 'Surface', 'ufsc-licence-competition' ); ?></label></th><td><input type="text" name="surface" class="regular-text" /></td></tr>
					<tr><th><label><?php esc_html_e( 'Commentaire', 'ufsc-licence-competition' ); ?></label></th><td><textarea name="comment" class="large-text" rows="2"></textarea></td></tr>
				</table>
				<?php submit_button( __( 'Ajouter officiel', 'ufsc-licence-competition' ) ); ?>
			</form>

			<div class="ufsc-competitions-table-wrap">
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Rôle', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Contact', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Surface', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Action', 'ufsc-licence-competition' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Aucun officiel enregistré.', 'ufsc-licence-competition' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $items as $official ) : ?>
						<tr>
							<td><?php echo esc_html( trim( (string) ( $official['first_name'] ?? '' ) . ' ' . (string) ( $official['last_name'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $official['role'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( trim( (string) ( $official['phone'] ?? '' ) . ' ' . (string) ( $official['email'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $official['surface'] ?? '—' ) ); ?></td>
							<td><span class="ufsc-badge <?php echo ! empty( $official['active'] ) ? 'ufsc-badge--success' : 'ufsc-badge--muted'; ?>"><?php echo ! empty( $official['active'] ) ? esc_html__( 'Actif', 'ufsc-licence-competition' ) : esc_html__( 'Inactif', 'ufsc-licence-competition' ); ?></span></td>
							<td>
								<form method="post">
									<?php wp_nonce_field( 'ufsc_officials_toggle_' . $competition_id ); ?>
									<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>" />
									<input type="hidden" name="ufsc_officials_action" value="toggle" />
									<input type="hidden" name="official_id" value="<?php echo esc_attr( (string) ( $official['id'] ?? '' ) ); ?>" />
									<?php submit_button( ! empty( $official['active'] ) ? __( 'Désactiver', 'ufsc-licence-competition' ) : __( 'Activer', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function maybe_handle_post( int $competition_id ): ?array {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['ufsc_officials_action'] ) ) {
			return null;
		}
		if ( ! Capabilities::user_can_manage() ) {
			return array( 'type' => 'error', 'message' => __( 'Permissions insuffisantes.', 'ufsc-licence-competition' ) );
		}
		$action = sanitize_key( (string) wp_unslash( $_POST['ufsc_officials_action'] ) );
		$competition_id = $competition_id ?: ( isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0 );
		if ( ! $competition_id ) {
			return array( 'type' => 'error', 'message' => __( 'Compétition invalide.', 'ufsc-licence-competition' ) );
		}

		$all = $this->get_all_officials();
		$list = isset( $all[ $competition_id ] ) && is_array( $all[ $competition_id ] ) ? $all[ $competition_id ] : array();

		if ( 'add' === $action ) {
			check_admin_referer( 'ufsc_officials_save_' . $competition_id );
			$list[] = array(
				'id' => wp_generate_uuid4(),
				'first_name' => sanitize_text_field( (string) wp_unslash( $_POST['first_name'] ?? '' ) ),
				'last_name' => sanitize_text_field( (string) wp_unslash( $_POST['last_name'] ?? '' ) ),
				'role' => sanitize_key( (string) wp_unslash( $_POST['role'] ?? '' ) ),
				'phone' => sanitize_text_field( (string) wp_unslash( $_POST['phone'] ?? '' ) ),
				'email' => sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) ),
				'surface' => sanitize_text_field( (string) wp_unslash( $_POST['surface'] ?? '' ) ),
				'comment' => sanitize_textarea_field( (string) wp_unslash( $_POST['comment'] ?? '' ) ),
				'active' => 1,
			);
			$all[ $competition_id ] = $list;
			update_option( self::OPTION_KEY, $all, false );
			return array( 'type' => 'success', 'message' => __( 'Officiel ajouté.', 'ufsc-licence-competition' ) );
		}

		if ( 'toggle' === $action ) {
			check_admin_referer( 'ufsc_officials_toggle_' . $competition_id );
			$official_id = sanitize_text_field( (string) wp_unslash( $_POST['official_id'] ?? '' ) );
			foreach ( $list as &$official ) {
				if ( ( $official['id'] ?? '' ) !== $official_id ) {
					continue;
				}
				$official['active'] = empty( $official['active'] ) ? 1 : 0;
			}
			unset( $official );
			$all[ $competition_id ] = $list;
			update_option( self::OPTION_KEY, $all, false );
			return array( 'type' => 'success', 'message' => __( 'Statut officiel mis à jour.', 'ufsc-licence-competition' ) );
		}

		return null;
	}

	private function get_all_officials(): array {
		$data = get_option( self::OPTION_KEY, array() );
		return is_array( $data ) ? $data : array();
	}
}
