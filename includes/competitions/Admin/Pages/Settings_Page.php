<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Services\DisciplineRegistry;
use UFSC\Competitions\Services\CategoryPresetRegistry;
use UFSC\Competitions\Services\AuditLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Page {
	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_load_presets', array( $this, 'handle_load_presets' ) );
		add_action( 'admin_post_ufsc_competitions_save_log_settings', array( $this, 'handle_save_log_settings' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$this->render_notice( $notice );

		$disciplines = DisciplineRegistry::get_disciplines_with_types();
		$preset_package = CategoryPresetRegistry::get_package();
		$loaded_version = $preset_package['version'] ?? '';
		$updated_at = $preset_package['updated_at'] ?? '';
		$audit_enabled = (bool) get_option( AuditLogger::OPTION_ENABLED, false );
		$audit_retention = absint( get_option( AuditLogger::OPTION_RETENTION_DAYS, AuditLogger::DEFAULT_RETENTION_DAYS ) );

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php esc_html_e( 'Paramètres', 'ufsc-licence-competition' ); ?></h1>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Configurer les disciplines et charger le référentiel UFSC.', 'ufsc-licence-competition' ); ?></p></div>

			<h2><?php esc_html_e( 'Logs', 'ufsc-licence-competition' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_log_settings' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_log_settings">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Activer logs', 'ufsc-licence-competition' ); ?></th>
						<td><label><input type="checkbox" name="audit_enabled" value="1" <?php checked( $audit_enabled ); ?>> <?php esc_html_e( 'Activer la traçabilité des actions.', 'ufsc-licence-competition' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_audit_retention"><?php esc_html_e( 'Rétention (jours)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input type="number" id="ufsc_audit_retention" name="audit_retention_days" value="<?php echo esc_attr( $audit_retention ); ?>" min="1" class="small-text"></td>
					</tr>
				</table>
				<?php submit_button( __( 'Enregistrer les logs', 'ufsc-licence-competition' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Disciplines', 'ufsc-licence-competition' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_settings' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_settings">
				<table class="widefat striped ufsc-competitions-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Slug', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Libellé', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Type', 'ufsc-licence-competition' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $disciplines as $key => $discipline ) : ?>
							<tr>
								<td><input type="text" name="disciplines[<?php echo esc_attr( $key ); ?>][key]" value="<?php echo esc_attr( $key ); ?>" class="regular-text" readonly></td>
								<td><input type="text" name="disciplines[<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $discipline['label'] ); ?>" class="regular-text"></td>
								<td><?php $this->render_type_select( "disciplines[{$key}][type]", $discipline['type'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php for ( $i = 0; $i < 3; $i++ ) : ?>
							<tr>
								<td><input type="text" name="disciplines[new_<?php echo esc_attr( $i ); ?>][key]" value="" class="regular-text" placeholder="<?php esc_attr_e( 'slug', 'ufsc-licence-competition' ); ?>"></td>
								<td><input type="text" name="disciplines[new_<?php echo esc_attr( $i ); ?>][label]" value="" class="regular-text" placeholder="<?php esc_attr_e( 'Libellé', 'ufsc-licence-competition' ); ?>"></td>
								<td><?php $this->render_type_select( "disciplines[new_{$i}][type]", DisciplineRegistry::TYPE_TATAMI ); ?></td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Les slugs existants sont verrouillés pour préserver les compétitions enregistrées.', 'ufsc-licence-competition' ); ?></p>
				<?php submit_button( __( 'Enregistrer les disciplines', 'ufsc-licence-competition' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Référentiel catégories UFSC', 'ufsc-licence-competition' ); ?></h2>
			<p>
				<?php if ( $loaded_version ) : ?>
					<?php
					printf(
						'%s %s · %s %s',
						esc_html__( 'Version chargée :', 'ufsc-licence-competition' ),
						esc_html( $loaded_version ),
						esc_html__( 'Mise à jour :', 'ufsc-licence-competition' ),
						esc_html( $updated_at )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Aucun référentiel UFSC chargé.', 'ufsc-licence-competition' ); ?>
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ufsc_competitions_load_presets' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_load_presets">
				<?php submit_button( __( 'Charger/Mettre à jour le référentiel UFSC 2024/2025', 'ufsc-licence-competition' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_settings' );

		$submitted = isset( $_POST['disciplines'] ) ? (array) wp_unslash( $_POST['disciplines'] ) : array();
		$clean = array();

		foreach ( $submitted as $row ) {
			$key = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : DisciplineRegistry::TYPE_OTHER;
			if ( '' === $key || '' === $label ) {
				continue;
			}

			$clean[ $key ] = array(
				'label' => $label,
				'type'  => $this->normalize_type( $type ),
			);
		}

		if ( $clean ) {
			update_option( DisciplineRegistry::OPTION_NAME, $clean, false );
		}

		$this->redirect_with_notice( 'settings_saved' );
	}

	public function handle_save_log_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_log_settings' );

		$enabled = isset( $_POST['audit_enabled'] ) ? 1 : 0;
		$retention = isset( $_POST['audit_retention_days'] ) ? absint( $_POST['audit_retention_days'] ) : AuditLogger::DEFAULT_RETENTION_DAYS;
		if ( $retention < 1 ) {
			$retention = AuditLogger::DEFAULT_RETENTION_DAYS;
		}

		update_option( AuditLogger::OPTION_ENABLED, (bool) $enabled, false );
		update_option( AuditLogger::OPTION_RETENTION_DAYS, $retention, false );

		$this->redirect_with_notice( 'logs_settings_saved' );
	}

	public function handle_load_presets() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_load_presets' );

		CategoryPresetRegistry::load_default_presets();

		$this->redirect_with_notice( 'presets_loaded' );
	}

	private function render_notice( $notice ) {
		$messages = array(
			'settings_saved' => __( 'Disciplines enregistrées.', 'ufsc-licence-competition' ),
			'presets_loaded' => __( 'Référentiel UFSC mis à jour.', 'ufsc-licence-competition' ),
			'logs_settings_saved' => __( 'Paramètres des logs enregistrés.', 'ufsc-licence-competition' ),
		);

		if ( ! $notice || ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
	}

	private function render_type_select( $name, $value ) {
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<option value="<?php echo esc_attr( DisciplineRegistry::TYPE_TATAMI ); ?>" <?php selected( $value, DisciplineRegistry::TYPE_TATAMI ); ?>><?php esc_html_e( 'Tatami', 'ufsc-licence-competition' ); ?></option>
			<option value="<?php echo esc_attr( DisciplineRegistry::TYPE_RING ); ?>" <?php selected( $value, DisciplineRegistry::TYPE_RING ); ?>><?php esc_html_e( 'Ring', 'ufsc-licence-competition' ); ?></option>
			<option value="<?php echo esc_attr( DisciplineRegistry::TYPE_OTHER ); ?>" <?php selected( $value, DisciplineRegistry::TYPE_OTHER ); ?>><?php esc_html_e( 'Autre', 'ufsc-licence-competition' ); ?></option>
		</select>
		<?php
	}

	private function normalize_type( $type ) {
		$allowed = array( DisciplineRegistry::TYPE_TATAMI, DisciplineRegistry::TYPE_RING, DisciplineRegistry::TYPE_OTHER );
		if ( ! in_array( $type, $allowed, true ) ) {
			return DisciplineRegistry::TYPE_OTHER;
		}

		return $type;
	}

	private function redirect_with_notice( $notice ) {
		$url = add_query_arg(
			array(
				'page'        => Menu::PAGE_SETTINGS,
				'ufsc_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
