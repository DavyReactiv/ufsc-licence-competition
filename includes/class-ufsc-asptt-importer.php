<?php
/**
 * UFSC – Import ASPTT (admin)
 * Fichier complet prêt à coller (merge final sans marqueurs de conflit).
 *
 * @package ufsc-licence-competition
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_ASPTT_Importer {

	const SOURCE = UFSC_LC_ASPTT_Import_Service::SOURCE;

	const STATUS_LINKED               = UFSC_LC_ASPTT_Import_Service::STATUS_LINKED;
	const STATUS_CLUB_NOT_FOUND       = UFSC_LC_ASPTT_Import_Service::STATUS_CLUB_NOT_FOUND;
	const STATUS_NEEDS_REVIEW         = UFSC_LC_ASPTT_Import_Service::STATUS_NEEDS_REVIEW;
	const STATUS_LICENCE_MISSING      = UFSC_LC_ASPTT_Import_Service::STATUS_LICENCE_MISSING;
	const STATUS_INVALID_ASPTT_NUMBER = UFSC_LC_ASPTT_Import_Service::STATUS_INVALID_ASPTT_NUMBER;

	const SESSION_KEY = 'ufsc_lc_asptt_preview';
	const LAST_IMPORT_KEY = 'ufsc_lc_asptt_last_import';

	private $legacy_enabled = false;
	private $service;

	public function __construct( $legacy_enabled = false ) {
		$this->legacy_enabled = (bool) $legacy_enabled;
		$this->service        = new UFSC_LC_ASPTT_Import_Service();
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_ufsc_lc_asptt_upload', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_ufsc_lc_asptt_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_ufsc_lc_asptt_cancel_import', array( $this, 'handle_cancel_import' ) );
		add_action( 'wp_ajax_ufsc_lc_club_search', array( $this, 'ajax_search_clubs' ) );
		add_action( 'wp_ajax_ufsc_lc_asptt_save_alias', array( $this, 'ajax_save_alias' ) );
		add_action( 'admin_post_ufsc_lc_asptt_export_errors', array( $this, 'handle_export_errors' ) );

		if ( $this->legacy_enabled ) {
			add_action( 'admin_post_ufsc_asptt_upload', array( $this, 'handle_upload' ) );
			add_action( 'admin_post_ufsc_asptt_import', array( $this, 'handle_import' ) );
			add_action( 'admin_post_ufsc_asptt_cancel_import', array( $this, 'handle_cancel_import' ) );
			add_action( 'wp_ajax_ufsc_club_search', array( $this, 'ajax_search_clubs' ) );
			add_action( 'wp_ajax_ufsc_asptt_save_alias', array( $this, 'ajax_save_alias' ) );
			add_action( 'admin_post_ufsc_asptt_export_errors', array( $this, 'handle_export_errors' ) );
		}
	}

	public function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$aliases_table   = $this->get_aliases_table();
		$logs_table      = $this->get_import_logs_table();

		$aliases_sql = "CREATE TABLE {$aliases_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			club_id bigint(20) unsigned NOT NULL,
			alias varchar(255) NOT NULL,
			alias_normalized varchar(255) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_alias (alias_normalized),
			KEY club_id (club_id)
		) {$charset_collate};";

		$logs_sql = "CREATE TABLE {$logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			file_name varchar(255) NULL,
			mode varchar(20) NOT NULL,
			total_rows int unsigned NOT NULL DEFAULT 0,
			success_rows int unsigned NOT NULL DEFAULT 0,
			error_rows int unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'completed',
			error_message text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_user (user_id),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		dbDelta( $aliases_sql );
		dbDelta( $logs_sql );
	}

	public function register_admin_menu() {
		$parent_slug = UFSC_LC_Plugin::PARENT_SLUG;
		if ( empty( $parent_slug ) ) {
			$parent_slug = 'ufsc-licence-documents';
		}

		$hook_suffix = add_submenu_page(
			$parent_slug,
			__( 'Import ASPTT', 'ufsc-licence-competition' ),
			__( 'Import ASPTT', 'ufsc-licence-competition' ),
			UFSC_LC_Capabilities::get_import_capability(),
			'ufsc-lc-import-asptt',
			array( $this, 'render_admin_page' )
		);

		UFSC_LC_Admin_Assets::register_page( $hook_suffix );
	}

	public function render_admin_page() {
		if ( ! UFSC_LC_Capabilities::user_can_import() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'import';
		$tab = in_array( $tab, array( 'import', 'review' ), true ) ? $tab : 'import';

		$preview = $this->get_preview();

		$stats_defaults = array(
			'total'                => 0,
			'clubs_linked'         => 0,
			'licences_linked'      => 0,
			'club_not_found'       => 0,
			'needs_review'         => 0,
			'licence_not_found'    => 0,
			'invalid_asptt_number' => 0,
			'invalid_season'       => 0,
			'invalid_birthdate'    => 0,
		);

		$stats                   = $preview && isset( $preview['stats'] ) ? array_merge( $stats_defaults, $preview['stats'] ) : $stats_defaults;
		$rows                    = $preview && isset( $preview['rows'] ) ? $preview['rows'] : array();
		$errors                  = $preview && isset( $preview['errors'] ) ? $preview['errors'] : array();
		$headers                 = $preview && ! empty( $preview['headers'] ) ? $preview['headers'] : array();
		$mapping                 = $preview && ! empty( $preview['mapping'] ) ? $preview['mapping'] : array();
		$file_name               = $preview && ! empty( $preview['file_name'] ) ? $preview['file_name'] : '';
		$preview_limit           = $preview && ! empty( $preview['preview_limit'] ) ? (int) $preview['preview_limit'] : UFSC_LC_ASPTT_Import_Service::PREVIEW_DEFAULT_LIMIT;
		$force_club_id           = $preview && ! empty( $preview['force_club_id'] ) ? (int) $preview['force_club_id'] : 0;
		$pinned_club_id          = $preview && ! empty( $preview['pinned_club_id'] ) ? (int) $preview['pinned_club_id'] : 0;
		$pinned_apply            = $preview && ! empty( $preview['pinned_apply'] );
		$default_season_end_year = $this->get_default_season_end_year();
		$use_season_override     = $preview && ! empty( $preview['use_season_override'] );
		$season_end_year_override = $preview && ! empty( $preview['season_end_year_override'] ) ? (int) $preview['season_end_year_override'] : $default_season_end_year;
		$auto_save_alias         = $preview && isset( $preview['auto_save_alias'] ) ? (bool) $preview['auto_save_alias'] : true;

		$force_club  = $force_club_id ? $this->get_club_by_id( $force_club_id ) : null;
		$pinned_club = $pinned_club_id ? $this->get_club_by_id( $pinned_club_id ) : null;

		?>
		<div class="wrap ufsc-lc-admin">
			<h1><?php esc_html_e( 'Import ASPTT', 'ufsc-licence-competition' ); ?></h1>
			<?php $this->render_admin_notice(); ?>
			<?php $this->render_tabs( $tab ); ?>

			<?php if ( 'review' === $tab ) : ?>
				<?php
				$review_page = new UFSC_LC_ASPTT_Review_Page( $this->service );
				$review_page->render();
				?>
			<?php else : ?>
				<?php
				$this->render_import_tab(
					$preview,
					$rows,
					$errors,
					$headers,
					$mapping,
					$file_name,
					$preview_limit,
					$force_club_id,
					$force_club,
					$stats,
					$default_season_end_year,
					$use_season_override,
					$season_end_year_override,
					$auto_save_alias,
					$pinned_club_id,
					$pinned_apply,
					$pinned_club
				);
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_tabs( $current ) {
		$tabs = array(
			'import' => __( 'Import', 'ufsc-licence-competition' ),
			'review' => __( 'Review', 'ufsc-licence-competition' ),
		);
		?>
		<h2 class="nav-tab-wrapper">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<?php
				$url = add_query_arg(
					array(
						'page' => 'ufsc-lc-import-asptt',
						'tab'  => $key,
					),
					admin_url( 'admin.php' )
				);
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo ( $current === $key ) ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</h2>
		<?php
	}

	private function render_admin_notice() {
		$type    = isset( $_GET['ufsc_lc_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_lc_notice_type'] ) ) : '';
		$message_raw = isset( $_GET['ufsc_lc_notice'] ) ? wp_unslash( $_GET['ufsc_lc_notice'] ) : '';
		$message     = '' !== $message_raw ? sanitize_text_field( rawurldecode( $message_raw ) ) : '';

		if ( '' === $type || '' === $message ) {
			return;
		}

		$classes = array(
			'success' => 'notice notice-success is-dismissible',
			'warning' => 'notice notice-warning is-dismissible',
			'error'   => 'notice notice-error is-dismissible',
			'info'    => 'notice notice-info is-dismissible',
		);
		$class = isset( $classes[ $type ] ) ? $classes[ $type ] : 'notice notice-info is-dismissible';

		printf(
			'<div class="%s"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	private function render_cancel_actions( $preview, $last_import ) {
		$has_preview  = ! empty( $preview['file_path'] );
		$has_rollback = ! empty( $last_import['created_documents'] ) || ! empty( $last_import['created_meta'] );
		$rollback_enabled = ! class_exists( 'UFSC_LC_Settings_Page' ) || UFSC_LC_Settings_Page::is_asptt_rollback_enabled();

		if ( ! $has_preview && ( ! $has_rollback || ! $rollback_enabled ) ) {
			return;
		}
		?>
		<div class="ufsc-lc-filter-row">
			<?php if ( $has_preview ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ufsc_lc_asptt_cancel_import', 'ufsc_lc_asptt_cancel_nonce' ); ?>
					<input type="hidden" name="action" value="ufsc_lc_asptt_cancel_import">
					<input type="hidden" name="cancel_action" value="preview">
					<button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Annuler cette prévisualisation ?', 'ufsc-licence-competition' ) ); ?>');">
						<?php esc_html_e( 'Annuler la prévisualisation', 'ufsc-licence-competition' ); ?>
					</button>
				</form>
			<?php endif; ?>

			<?php if ( $has_rollback && $rollback_enabled ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ufsc_lc_asptt_cancel_import', 'ufsc_lc_asptt_cancel_nonce' ); ?>
					<input type="hidden" name="action" value="ufsc_lc_asptt_cancel_import">
					<input type="hidden" name="cancel_action" value="rollback">
					<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Annuler le dernier import ? Seuls les documents ASPTT créés seront supprimés.', 'ufsc-licence-competition' ) ); ?>');">
						<?php esc_html_e( 'Annuler le dernier import', 'ufsc-licence-competition' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_import_tab( $preview, $rows, $errors, $headers, $mapping, $file_name, $preview_limit, $force_club_id, $force_club, $stats, $default_season_end_year, $use_season_override, $season_end_year_override, $auto_save_alias, $pinned_club_id, $pinned_apply, $pinned_club ) {
		$last_import = $this->get_last_import();
		$auto_validate_threshold = class_exists( 'UFSC_LC_Settings_Page' ) ? UFSC_LC_Settings_Page::get_asptt_auto_validate_threshold() : 0;
		$auto_validate_score = $this->get_auto_validate_score( $stats );
		$auto_approve_default = 0 === $auto_validate_threshold ? true : ( $auto_validate_score >= $auto_validate_threshold );
		?>
		<?php if ( ! empty( $preview['notice'] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $preview['notice']['type'] ); ?>">
				<p><?php echo esc_html( $preview['notice']['message'] ); ?></p>
				<?php if ( ! empty( $preview['notice']['review_link'] ) ) : ?>
					<p>
						<a class="button button-secondary" href="<?php echo esc_url( $preview['notice']['review_link'] ); ?>">
							<?php echo esc_html( $preview['notice']['review_label'] ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( empty( $rows ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'ufsc_lc_asptt_upload', 'ufsc_lc_asptt_nonce' ); ?>
				<input type="hidden" name="action" value="ufsc_lc_asptt_upload">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'ufsc-licence-competition' ); ?></th>
						<td>
							<label>
								<input type="radio" name="ufsc_asptt_mode" value="dry_run" checked>
								<?php esc_html_e( 'Simulation (dry-run)', 'ufsc-licence-competition' ); ?>
							</label>
							<br>
							<label>
								<input type="radio" name="ufsc_asptt_mode" value="import">
								<?php esc_html_e( 'Importer', 'ufsc-licence-competition' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="ufsc_asptt_csv"><?php esc_html_e( 'CSV ASPTT', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<input type="file" name="ufsc_asptt_csv" id="ufsc_asptt_csv" accept=".csv,text/csv" required>
							<p class="description"><?php esc_html_e( 'Fichier CSV (max 5 Mo).', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="ufsc_asptt_preview_limit"><?php esc_html_e( 'Lignes à prévisualiser', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<input
								type="number"
								name="ufsc_asptt_preview_limit"
								id="ufsc_asptt_preview_limit"
								value="<?php echo esc_attr( $preview_limit ); ?>"
								min="<?php echo esc_attr( UFSC_LC_ASPTT_Import_Service::PREVIEW_MIN_LIMIT ); ?>"
								max="<?php echo esc_attr( UFSC_LC_ASPTT_Import_Service::PREVIEW_MAX_LIMIT ); ?>"
							>
							<p class="description">
								<?php esc_html_e( 'Nombre de lignes affichées en prévisualisation (10 à 200).', 'ufsc-licence-competition' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Option recommandée', 'ufsc-licence-competition' ); ?></th>
						<td>
							<?php esc_html_e( 'Exporter 1 CSV par club depuis l’ASPTT, puis importer ici. Si le CSV est mono-club, vous pouvez forcer le club UFSC ci-dessous.', 'ufsc-licence-competition' ); ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Saison par défaut', 'ufsc-licence-competition' ); ?></th>
						<td>
							<input type="number" value="<?php echo esc_attr( $default_season_end_year ); ?>" readonly>
							<p class="description"><?php esc_html_e( 'Année de fin de saison utilisée quand le CSV ne fournit pas la saison.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Saison spécifique', 'ufsc-licence-competition' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ufsc_asptt_use_season_override" value="1" <?php checked( $use_season_override ); ?>>
								<?php esc_html_e( 'Utiliser une saison spécifique pour cet import', 'ufsc-licence-competition' ); ?>
							</label>
							<br>
							<input type="number" name="ufsc_asptt_season_end_year" value="<?php echo esc_attr( $season_end_year_override ); ?>" min="2000" max="2100">
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="ufsc_asptt_force_club"><?php esc_html_e( 'Forcer tout le fichier à un club', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="ufsc_asptt_force_club" id="ufsc_asptt_force_club">
								<option value=""><?php esc_html_e( 'Ne pas forcer', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $this->get_clubs() as $club ) : ?>
									<option value="<?php echo esc_attr( $club->id ); ?>"><?php echo esc_html( $club->nom ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Alias automatique', 'ufsc-licence-competition' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ufsc_asptt_auto_save_alias" value="1" <?php checked( $auto_save_alias ); ?>>
								<?php esc_html_e( 'Enregistrer automatiquement NOTE comme alias du club forcé', 'ufsc-licence-competition' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Prévisualiser', 'ufsc-licence-competition' ) ); ?>
			</form>

		<?php else : ?>

			<?php if ( $file_name ) : ?>
				<p><strong><?php esc_html_e( 'Fichier chargé :', 'ufsc-licence-competition' ); ?></strong> <?php echo esc_html( $file_name ); ?></p>
			<?php endif; ?>

			<?php if ( $force_club_id ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: club name */
								__( 'Mode forcer un club actif : %s', 'ufsc-licence-competition' ),
								$force_club ? $force_club->nom : $force_club_id
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $pinned_apply && $pinned_club_id ) : ?>
				<div class="notice notice-info">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: club name */
								__( 'Club épinglé actif : %s', 'ufsc-licence-competition' ),
								$pinned_club ? $pinned_club->nom : $pinned_club_id
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php $this->render_stats( $stats, $errors, $preview_limit ); ?>

			<?php if ( ! empty( $headers ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-lc-mapping-form">
					<?php wp_nonce_field( 'ufsc_lc_asptt_upload', 'ufsc_lc_asptt_nonce' ); ?>
					<input type="hidden" name="action" value="ufsc_lc_asptt_upload">
					<input type="hidden" name="ufsc_lc_reprocess" value="1">

					<?php if ( $force_club_id ) : ?>
						<input type="hidden" name="ufsc_asptt_force_club" value="<?php echo esc_attr( $force_club_id ); ?>">
					<?php endif; ?>

					<input type="hidden" name="ufsc_asptt_pinned_club_id" value="<?php echo esc_attr( $pinned_club_id ); ?>">
					<input type="hidden" name="ufsc_asptt_pinned_apply" value="<?php echo esc_attr( $pinned_apply ? 1 : 0 ); ?>">

					<p>
						<strong><?php esc_html_e( 'Saison par défaut', 'ufsc-licence-competition' ); ?>:</strong>
						<?php echo esc_html( $default_season_end_year ); ?>
					</p>

					<p>
						<label>
							<input type="checkbox" name="ufsc_asptt_use_season_override" value="1" <?php checked( $use_season_override ); ?>>
							<?php esc_html_e( 'Utiliser une saison spécifique pour cet import', 'ufsc-licence-competition' ); ?>
						</label>
						<input type="number" name="ufsc_asptt_season_end_year" value="<?php echo esc_attr( $season_end_year_override ); ?>" min="2000" max="2100">
					</p>

					<p>
						<label>
							<input type="checkbox" name="ufsc_asptt_auto_save_alias" value="1" <?php checked( $auto_save_alias ); ?>>
							<?php esc_html_e( 'Enregistrer automatiquement NOTE comme alias du club forcé', 'ufsc-licence-competition' ); ?>
						</label>
					</p>

					<p>
						<label for="ufsc_asptt_preview_limit_mapping"><strong><?php esc_html_e( 'Lignes à prévisualiser', 'ufsc-licence-competition' ); ?></strong></label>
						<input
							type="number"
							name="ufsc_asptt_preview_limit"
							id="ufsc_asptt_preview_limit_mapping"
							value="<?php echo esc_attr( $preview_limit ); ?>"
							min="<?php echo esc_attr( UFSC_LC_ASPTT_Import_Service::PREVIEW_MIN_LIMIT ); ?>"
							max="<?php echo esc_attr( UFSC_LC_ASPTT_Import_Service::PREVIEW_MAX_LIMIT ); ?>"
						>
					</p>

					<div class="ufsc-lc-table-wrap">
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Colonne CSV', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Mapping', 'ufsc-licence-competition' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $headers as $header ) : ?>
									<tr>
										<td><?php echo esc_html( $header ); ?></td>
										<td>
											<select name="ufsc_lc_mapping[<?php echo esc_attr( $header ); ?>]">
												<option value=""><?php esc_html_e( 'Ignorer', 'ufsc-licence-competition' ); ?></option>
												<?php foreach ( $this->get_mapping_options() as $option_value => $option_label ) : ?>
													<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( isset( $mapping[ $header ] ) ? $mapping[ $header ] : '', $option_value ); ?>>
														<?php echo esc_html( $option_label ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<?php submit_button( __( 'Recalculer la prévisualisation', 'ufsc-licence-competition' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php $this->render_preview_sticky_bar( $stats, $preview_limit, $mapping, $force_club_id, $use_season_override, $season_end_year_override, $auto_save_alias, $pinned_club_id, $pinned_apply ); ?>
			<?php $this->render_cancel_actions( $preview, $last_import ); ?>
			<?php $this->render_preview_table( $rows, $preview_limit, $pinned_club_id, $pinned_apply ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ufsc_lc_asptt_import', 'ufsc_lc_asptt_import_nonce' ); ?>
				<input type="hidden" name="action" value="ufsc_lc_asptt_import">
				<input type="hidden" name="ufsc_asptt_use_season_override" value="<?php echo esc_attr( $use_season_override ? 1 : 0 ); ?>">
				<input type="hidden" name="ufsc_asptt_season_end_year" value="<?php echo esc_attr( $season_end_year_override ); ?>">
				<input type="hidden" name="ufsc_asptt_auto_save_alias" value="<?php echo esc_attr( $auto_save_alias ? 1 : 0 ); ?>">
				<input type="hidden" name="ufsc_asptt_pinned_club_id" value="<?php echo esc_attr( $pinned_club_id ); ?>">
				<input type="hidden" name="ufsc_asptt_pinned_apply" value="<?php echo esc_attr( $pinned_apply ? 1 : 0 ); ?>">

				<label>
					<input type="radio" name="ufsc_asptt_mode" value="dry_run" checked>
					<?php esc_html_e( 'Simulation (dry-run)', 'ufsc-licence-competition' ); ?>
				</label>
				&nbsp;
				<label>
					<input type="radio" name="ufsc_asptt_mode" value="import">
					<?php esc_html_e( 'Importer', 'ufsc-licence-competition' ); ?>
				</label>
				<br>

				<label>
					<input type="checkbox" name="ufsc_asptt_auto_approve" value="1" <?php checked( $auto_approve_default ); ?>>
					<?php
					echo esc_html(
						sprintf(
							__( 'Auto-valider si score ≥ %1$d (score actuel : %2$d)', 'ufsc-licence-competition' ),
							$auto_validate_threshold,
							$auto_validate_score
						)
					);
					?>
				</label>

				<?php submit_button( __( 'Lancer', 'ufsc-licence-competition' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( ! empty( $errors ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;">
					<?php wp_nonce_field( 'ufsc_lc_asptt_export_errors', 'ufsc_lc_asptt_errors_nonce' ); ?>
					<input type="hidden" name="action" value="ufsc_lc_asptt_export_errors">
					<?php submit_button( __( 'Exporter les erreurs CSV', 'ufsc-licence-competition' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px;">
				<?php wp_nonce_field( 'ufsc_lc_asptt_upload', 'ufsc_lc_asptt_nonce' ); ?>
				<input type="hidden" name="action" value="ufsc_lc_asptt_upload">
				<?php submit_button( __( 'Recharger un fichier', 'ufsc-licence-competition' ), 'secondary' ); ?>
			</form>
		<?php endif; ?>

		<?php $this->render_import_logs(); ?>
		<?php
	}

	private function render_stats( $stats, $errors, $preview_limit ) {
		?>
		<h2><?php esc_html_e( 'Rapport', 'ufsc-licence-competition' ); ?></h2>
		<ul>
			<li><?php echo esc_html( sprintf( __( 'Lignes prévisualisées: %d', 'ufsc-licence-competition' ), $preview_limit ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Total lignes: %d', 'ufsc-licence-competition' ), $stats['total'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Clubs associés: %d', 'ufsc-licence-competition' ), $stats['clubs_linked'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Licences matchées: %d', 'ufsc-licence-competition' ), $stats['licences_linked'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'club_not_found: %d', 'ufsc-licence-competition' ), $stats['club_not_found'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'needs_review: %d', 'ufsc-licence-competition' ), $stats['needs_review'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'licence_not_found: %d', 'ufsc-licence-competition' ), $stats['licence_not_found'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'invalid_asptt_number: %d', 'ufsc-licence-competition' ), $stats['invalid_asptt_number'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'invalid_season: %d', 'ufsc-licence-competition' ), isset( $stats['invalid_season'] ) ? (int) $stats['invalid_season'] : 0 ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'invalid_birthdate: %d', 'ufsc-licence-competition' ), isset( $stats['invalid_birthdate'] ) ? (int) $stats['invalid_birthdate'] : 0 ) ); ?></li>
		</ul>
		<?php
	}

	private function render_preview_sticky_bar( $stats, $preview_limit, $mapping, $force_club_id, $use_season_override, $season_end_year_override, $auto_save_alias, $pinned_club_id, $pinned_apply ) {
		$total_rows = isset( $stats['total'] ) ? (int) $stats['total'] : 0;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-lc-sticky-bar ufsc-lc-sticky-bar--import">
			<?php wp_nonce_field( 'ufsc_lc_asptt_upload', 'ufsc_lc_asptt_nonce' ); ?>
			<input type="hidden" name="action" value="ufsc_lc_asptt_upload">
			<input type="hidden" name="ufsc_lc_reprocess" value="1">
			<input type="hidden" name="ufsc_asptt_preview_limit" value="<?php echo esc_attr( $preview_limit ); ?>">
			<input type="hidden" name="ufsc_asptt_use_season_override" value="<?php echo esc_attr( $use_season_override ? 1 : 0 ); ?>">
			<input type="hidden" name="ufsc_asptt_season_end_year" value="<?php echo esc_attr( $season_end_year_override ); ?>">
			<input type="hidden" name="ufsc_asptt_auto_save_alias" value="<?php echo esc_attr( $auto_save_alias ? 1 : 0 ); ?>">

			<?php if ( $force_club_id ) : ?>
				<input type="hidden" name="ufsc_asptt_force_club" value="<?php echo esc_attr( $force_club_id ); ?>">
			<?php endif; ?>

			<?php if ( ! empty( $mapping ) ) : ?>
				<?php foreach ( $mapping as $header => $column ) : ?>
					<input type="hidden" name="ufsc_lc_mapping[<?php echo esc_attr( $header ); ?>]" value="<?php echo esc_attr( $column ); ?>">
				<?php endforeach; ?>
			<?php endif; ?>

			<div class="ufsc-lc-sticky-field">
				<label for="ufsc-lc-pinned-club" class="ufsc-lc-sticky-label"><?php esc_html_e( 'Club épinglé', 'ufsc-licence-competition' ); ?></label>
				<select name="ufsc_asptt_pinned_club_id" id="ufsc-lc-pinned-club">
					<option value=""><?php esc_html_e( 'Ne pas épingler', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $this->get_clubs() as $club ) : ?>
						<option value="<?php echo esc_attr( $club->id ); ?>" <?php selected( $pinned_club_id, (int) $club->id ); ?>>
							<?php echo esc_html( $club->nom ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ufsc-lc-sticky-field">
				<label>
					<input type="checkbox" name="ufsc_asptt_pinned_apply" value="1" <?php checked( $pinned_apply ); ?>>
					<?php esc_html_e( 'Appliquer à toutes les lignes', 'ufsc-licence-competition' ); ?>
				</label>
			</div>

			<button type="submit" class="button button-secondary ufsc-lc-apply-pinned"><?php esc_html_e( 'Appliquer au tableau', 'ufsc-licence-competition' ); ?></button>

			<span class="ufsc-lc-sticky-status" data-total="<?php echo esc_attr( $preview_limit ); ?>" data-selected="0">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: preview rows, 2: total rows, 3: selected rows */
						__( 'Lignes: %1$d / %2$d | Sélectionnées: %3$d', 'ufsc-licence-competition' ),
						$preview_limit,
						$total_rows,
						0
					)
				);
				?>
			</span>
		</form>
		<?php
	}

	/**
	 * Prévisualisation – tableau
	 * Inclut tooltip “Club (Note)” + injection du club épinglé en option.
	 */
	private function render_preview_table( $rows, $preview_limit, $pinned_club_id, $pinned_apply ) {
		$rows = array_slice( $rows, 0, $preview_limit );

		$club_map = array();
		foreach ( $this->get_clubs() as $club ) {
			$club_map[ (int) $club->id ] = $club->nom;
		}

		$status_labels = array(
			self::STATUS_LINKED               => __( 'Lié', 'ufsc-licence-competition' ),
			self::STATUS_CLUB_NOT_FOUND       => __( 'Club introuvable', 'ufsc-licence-competition' ),
			self::STATUS_NEEDS_REVIEW         => __( 'À vérifier', 'ufsc-licence-competition' ),
			self::STATUS_LICENCE_MISSING      => __( 'Licence introuvable', 'ufsc-licence-competition' ),
			self::STATUS_INVALID_ASPTT_NUMBER => __( 'N° ASPTT invalide', 'ufsc-licence-competition' ),
			UFSC_LC_ASPTT_Import_Service::STATUS_INVALID_SEASON    => __( 'Saison invalide', 'ufsc-licence-competition' ),
			UFSC_LC_ASPTT_Import_Service::STATUS_INVALID_BIRTHDATE => __( 'Date de naissance invalide', 'ufsc-licence-competition' ),
		);

		?>
		<h2><?php esc_html_e( 'Prévisualisation', 'ufsc-licence-competition' ); ?></h2>

		<div class="ufsc-lc-filter-row">
			<label>
				<input type="checkbox" id="ufsc-asptt-errors-only">
				<?php esc_html_e( 'Afficher seulement erreurs', 'ufsc-licence-competition' ); ?>
			</label>

			<input
				type="search"
				id="ufsc-asptt-search"
				placeholder="<?php esc_attr_e( 'Rechercher nom/prénom/club/n°', 'ufsc-licence-competition' ); ?>"
				style="min-width:280px;"
			>
		</div>

		<div class="ufsc-lc-table-wrap">
			<table class="widefat fixed striped ufsc-lc-preview-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Date de naissance', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Saison', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Âge réf.', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Club (Note)', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Licence UFSC', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'N° ASPTT', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Date ASPTT', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Score', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Lien', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Statut review', 'ufsc-licence-competition' ); ?></th>
						<th><?php esc_html_e( 'Action', 'ufsc-licence-competition' ); ?></th>
					</tr>
				</thead>

				<tbody>
					<?php foreach ( $rows as $index => $row ) : ?>
						<?php
						$search_value = strtolower( trim( $row['nom'] . ' ' . $row['prenom'] . ' ' . $row['note'] . ' ' . $row['asptt_number'] ) );

						$pinned_option = null;
						if ( $pinned_apply && $pinned_club_id && isset( $club_map[ (int) $pinned_club_id ] ) ) {
							$pinned_option = array(
								'id'   => (int) $pinned_club_id,
								'name' => $club_map[ (int) $pinned_club_id ],
							);
						}
						?>
						<tr
							data-has-error="<?php echo esc_attr( $row['has_error'] ? '1' : '0' ); ?>"
							data-search="<?php echo esc_attr( $search_value ); ?>"
							data-status="<?php echo esc_attr( $row['status'] ); ?>"
						>
							<td><?php echo esc_html( $row['nom'] ); ?></td>
							<td><?php echo esc_html( $row['prenom'] ); ?></td>
							<td><?php echo esc_html( $row['date_naissance'] ); ?></td>
							<td><?php echo esc_html( ! empty( $row['season_end_year'] ) ? $row['season_end_year'] : '—' ); ?></td>
							<td><?php echo esc_html( ! empty( $row['category'] ) ? $row['category'] : '—' ); ?></td>
							<td><?php echo esc_html( ! empty( $row['age_ref'] ) ? $row['age_ref'] : '—' ); ?></td>

							<td>
								<?php if ( '' !== $row['note'] ) : ?>
									<?php
									$club_label   = isset( $club_map[ (int) $row['club_id'] ] ) ? $club_map[ (int) $row['club_id'] ] : __( '—', 'ufsc-licence-competition' );
									$status_label = isset( $status_labels[ $row['status'] ] ) ? $status_labels[ $row['status'] ] : $row['status'];

									$tooltip = sprintf(
										/* translators: 1: note, 2: club name, 3: score, 4: link mode, 5: status */
										__( 'Note: %1$s | Club: %2$s | Score: %3$s | Lien: %4$s | Statut: %5$s', 'ufsc-licence-competition' ),
										$row['note'],
										$club_label,
										isset( $row['confidence_score'] ) ? (int) $row['confidence_score'] : 0,
										isset( $row['link_mode'] ) ? $row['link_mode'] : 'none',
										$status_label
									);

									$tooltip = wp_html_excerpt( $tooltip, 220, '…' );
									?>
									<span class="ufsc-lc-note" data-tooltip="<?php echo esc_attr( $tooltip ); ?>" title="<?php echo esc_attr( $tooltip ); ?>">
										<?php echo esc_html( $row['note'] ); ?>
									</span>
								<?php else : ?>
									<?php echo esc_html__( '—', 'ufsc-licence-competition' ); ?>
								<?php endif; ?>
							</td>

							<td><?php echo esc_html( ! empty( $row['licence_id'] ) ? $row['licence_id'] : '—' ); ?></td>
							<td><?php echo esc_html( $row['asptt_number'] ); ?></td>
							<td><?php echo esc_html( ! empty( $row['source_created_at'] ) ? $row['source_created_at'] : '—' ); ?></td>

							<td>
								<span class="ufsc-badge ufsc-badge--info">
									<?php echo esc_html( isset( $row['confidence_score'] ) ? (int) $row['confidence_score'] : 0 ); ?>
								</span>
							</td>

							<td>
								<span class="ufsc-badge ufsc-badge--muted">
									<?php echo esc_html( isset( $row['link_mode'] ) ? $row['link_mode'] : 'none' ); ?>
								</span>
							</td>

							<?php
							$review_status = isset( $row['review_status'] ) ? $row['review_status'] : ( ! empty( $row['auto_linked'] ) ? 'auto' : 'pending' );
							$review_label  = ( 'auto' === $review_status ) ? __( 'Auto', 'ufsc-licence-competition' ) : __( 'En attente', 'ufsc-licence-competition' );
							$review_badge_class = ( 'auto' === $review_status ) ? 'ufsc-badge--success' : 'ufsc-badge--warning';
							?>
							<td>
								<span class="ufsc-badge <?php echo esc_attr( $review_badge_class ); ?>">
									<?php echo esc_html( $review_label ); ?>
								</span>
							</td>

							<td>
								<?php if ( self::STATUS_NEEDS_REVIEW === $row['status'] && ! empty( $row['club_suggestions'] ) ) : ?>
									<div class="ufsc-lc-inline-actions">
										<select class="ufsc-club-select" data-row-index="<?php echo esc_attr( $index ); ?>">
											<option value=""><?php esc_html_e( 'Sélectionner un club', 'ufsc-licence-competition' ); ?></option>

											<?php if ( $pinned_option ) : ?>
												<?php
												$has_pinned = false;
												foreach ( $row['club_suggestions'] as $suggestion ) {
													if ( (int) $suggestion['id'] === (int) $pinned_option['id'] ) {
														$has_pinned = true;
														break;
													}
												}
												?>
												<?php if ( ! $has_pinned ) : ?>
													<option value="<?php echo esc_attr( $pinned_option['id'] ); ?>" selected>
														<?php echo esc_html( $pinned_option['name'] ); ?>
													</option>
												<?php endif; ?>
											<?php endif; ?>

											<?php foreach ( $row['club_suggestions'] as $suggestion ) : ?>
												<option value="<?php echo esc_attr( $suggestion['id'] ); ?>" <?php selected( $pinned_apply ? $pinned_club_id : 0, (int) $suggestion['id'] ); ?>>
													<?php echo esc_html( $suggestion['name'] ); ?>
												</option>
											<?php endforeach; ?>
										</select>

										<button type="button" class="button ufsc-save-alias" data-row-index="<?php echo esc_attr( $index ); ?>">
											<?php esc_html_e( 'Valider association', 'ufsc-licence-competition' ); ?>
										</button>

										<span class="ufsc-alias-feedback" data-row-index="<?php echo esc_attr( $index ); ?>"></span>
									</div>

								<?php elseif ( self::STATUS_CLUB_NOT_FOUND === $row['status'] ) : ?>
									<div class="ufsc-lc-inline-actions">
										<input type="text" class="ufsc-club-search" data-row-index="<?php echo esc_attr( $index ); ?>" placeholder="<?php esc_attr_e( 'Rechercher un club', 'ufsc-licence-competition' ); ?>">
										<select class="ufsc-club-select" data-row-index="<?php echo esc_attr( $index ); ?>">
											<option value=""><?php esc_html_e( 'Sélectionner un club', 'ufsc-licence-competition' ); ?></option>
											<?php if ( $pinned_option ) : ?>
												<option value="<?php echo esc_attr( $pinned_option['id'] ); ?>" selected>
													<?php echo esc_html( $pinned_option['name'] ); ?>
												</option>
											<?php endif; ?>
										</select>

										<button type="button" class="button ufsc-save-alias" data-row-index="<?php echo esc_attr( $index ); ?>">
											<?php esc_html_e( 'Valider association', 'ufsc-licence-competition' ); ?>
										</button>

										<span class="ufsc-alias-feedback" data-row-index="<?php echo esc_attr( $index ); ?>"></span>
									</div>

								<?php else : ?>
									<span>—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Options mapping (identifiants internes stables).
	 */
	private function get_mapping_options() {
		return array(
			'Nom'                    => __( 'Nom', 'ufsc-licence-competition' ),
			'Prenom'                 => __( 'Prénom', 'ufsc-licence-competition' ),
			'Date de naissance'      => __( 'Date de naissance', 'ufsc-licence-competition' ),
			'Saison (année de fin)'  => __( 'Saison (année de fin)', 'ufsc-licence-competition' ),
			'N° Licence'             => __( 'N° Licence', 'ufsc-licence-competition' ),
			'Date de création de la licence' => __( 'Date de création de la licence', 'ufsc-licence-competition' ),
			'Note'                   => __( 'Note', 'ufsc-licence-competition' ),
			'genre'                  => __( 'Genre', 'ufsc-licence-competition' ),
		);
	}

	public function handle_upload() {
		if ( ! UFSC_LC_Capabilities::user_can_import() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['ufsc_lc_asptt_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_lc_asptt_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ufsc_lc_asptt_upload' ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$force_club_id  = isset( $_POST['ufsc_asptt_force_club'] ) ? absint( $_POST['ufsc_asptt_force_club'] ) : 0;
		$pinned_club_id = isset( $_POST['ufsc_asptt_pinned_club_id'] ) ? absint( $_POST['ufsc_asptt_pinned_club_id'] ) : 0;
		$pinned_apply   = isset( $_POST['ufsc_asptt_pinned_apply'] );

		$mode          = isset( $_POST['ufsc_asptt_mode'] ) ? sanitize_key( wp_unslash( $_POST['ufsc_asptt_mode'] ) ) : 'dry_run';
		$mapping       = isset( $_POST['ufsc_lc_mapping'] ) ? $this->service->sanitize_mapping( wp_unslash( $_POST['ufsc_lc_mapping'] ) ) : array();
		$preview_limit = isset( $_POST['ufsc_asptt_preview_limit'] ) ? $this->service->sanitize_preview_limit( wp_unslash( $_POST['ufsc_asptt_preview_limit'] ) ) : UFSC_LC_ASPTT_Import_Service::PREVIEW_DEFAULT_LIMIT;

		$existing_preview = $this->get_preview();
		$is_reprocess     = isset( $_POST['ufsc_lc_reprocess'] );

		if ( $is_reprocess ) {
			if ( ! $pinned_club_id && ! empty( $existing_preview['pinned_club_id'] ) ) {
				$pinned_club_id = (int) $existing_preview['pinned_club_id'];
			}
			if ( ! $pinned_apply && ! empty( $existing_preview['pinned_apply'] ) ) {
				$pinned_apply = (bool) $existing_preview['pinned_apply'];
			}
		}

		$use_season_override       = isset( $_POST['ufsc_asptt_use_season_override'] );
		$raw_override              = isset( $_POST['ufsc_asptt_season_end_year'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_asptt_season_end_year'] ) ) : '';
		$season_end_year_override  = $use_season_override ? UFSC_LC_Categories::sanitize_season_end_year( $raw_override ) : null;
		$auto_save_alias           = isset( $_POST['ufsc_asptt_auto_save_alias'] ) ? (bool) absint( $_POST['ufsc_asptt_auto_save_alias'] ) : false;

		if ( $pinned_club_id && ! $this->get_club_by_id( $pinned_club_id ) ) {
			$pinned_club_id = 0;
			$pinned_apply   = false;
		}

		if ( $pinned_apply && $pinned_club_id ) {
			$force_club_id = $pinned_club_id;
		}

		$preview = array();

		if ( ! empty( $_FILES['ufsc_asptt_csv']['tmp_name'] ) ) {
			$stored = $this->service->store_upload( $_FILES['ufsc_asptt_csv'] );
			if ( is_wp_error( $stored ) ) {
				$preview['notice'] = array(
					'type'    => 'error',
					'message' => $stored->get_error_message(),
				);
			} else {
				$preview              = $this->service->build_preview( $stored['path'], $force_club_id, $mapping, $preview_limit, $season_end_year_override );
				$preview['file_path'] = $stored['path'];
				$preview['file_name'] = $stored['name'];
			}
		} else {
			if ( ! empty( $existing_preview['file_path'] ) && $is_reprocess ) {
				if ( ! $this->is_valid_preview_path( $existing_preview['file_path'] ) ) {
					$preview['notice'] = array(
						'type'    => 'error',
						'message' => __( 'Le fichier de prévisualisation est introuvable ou invalide. Merci de recharger le CSV.', 'ufsc-licence-competition' ),
					);
				} else {
					if ( ! $use_season_override && isset( $existing_preview['use_season_override'] ) ) {
						$use_season_override = (bool) $existing_preview['use_season_override'];
					}
					if ( null === $season_end_year_override && ! empty( $existing_preview['season_end_year_override'] ) ) {
						$season_end_year_override = (int) $existing_preview['season_end_year_override'];
					}

					if ( ! $use_season_override ) {
						$season_end_year_override = null;
					}

					$preview              = $this->service->build_preview( $existing_preview['file_path'], $force_club_id, $mapping, $preview_limit, $season_end_year_override );
					$preview['file_path'] = $existing_preview['file_path'];
					$preview['file_name'] = isset( $existing_preview['file_name'] ) ? $existing_preview['file_name'] : '';
				}
			}
		}

		if ( is_wp_error( $preview ) ) {
			$preview = array(
				'notice' => array(
					'type'    => 'error',
					'message' => $preview->get_error_message(),
				),
			);
		}

		if ( ! empty( $preview ) ) {
			$preview['force_club_id']             = $force_club_id;
			$preview['pinned_club_id']            = $pinned_club_id;
			$preview['pinned_apply']              = $pinned_apply;
			$preview['mode']                      = $mode;
			$preview['preview_limit']             = $preview_limit;
			$preview['use_season_override']       = $use_season_override;
			$preview['season_end_year_override']  = $season_end_year_override;
			$preview['auto_save_alias']           = $auto_save_alias;
		}

		update_option( self::SESSION_KEY, $preview, false );

		wp_safe_redirect( $this->get_admin_url() );
		exit;
	}

	public function handle_import() {
		if ( ! UFSC_LC_Capabilities::user_can_import() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['ufsc_lc_asptt_import_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_lc_asptt_import_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ufsc_lc_asptt_import' ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$preview = $this->get_preview();
		if ( empty( $preview['file_path'] ) ) {
			wp_safe_redirect( $this->get_admin_url() );
			exit;
		}
		if ( ! $this->is_valid_preview_path( $preview['file_path'] ) ) {
			delete_option( self::SESSION_KEY );
			$redirect = $this->add_notice_args( $this->get_admin_url(), 'error', __( 'Le fichier d’import est introuvable ou invalide. Merci de recharger le CSV.', 'ufsc-licence-competition' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$use_season_override = isset( $_POST['ufsc_asptt_use_season_override'] )
			? (bool) absint( $_POST['ufsc_asptt_use_season_override'] )
			: ( isset( $preview['use_season_override'] ) ? (bool) $preview['use_season_override'] : false );

		$raw_override = isset( $_POST['ufsc_asptt_season_end_year'] )
			? sanitize_text_field( wp_unslash( $_POST['ufsc_asptt_season_end_year'] ) )
			: ( isset( $preview['season_end_year_override'] ) ? $preview['season_end_year_override'] : '' );

		$season_end_year_override = $use_season_override ? UFSC_LC_Categories::sanitize_season_end_year( $raw_override ) : null;

		$auto_save_alias = isset( $_POST['ufsc_asptt_auto_save_alias'] )
			? (bool) absint( $_POST['ufsc_asptt_auto_save_alias'] )
			: ( isset( $preview['auto_save_alias'] ) ? (bool) $preview['auto_save_alias'] : true );

		$pinned_club_id = isset( $_POST['ufsc_asptt_pinned_club_id'] )
			? absint( $_POST['ufsc_asptt_pinned_club_id'] )
			: ( isset( $preview['pinned_club_id'] ) ? (int) $preview['pinned_club_id'] : 0 );

		$pinned_apply = isset( $_POST['ufsc_asptt_pinned_apply'] )
			? (bool) absint( $_POST['ufsc_asptt_pinned_apply'] )
			: ( isset( $preview['pinned_apply'] ) ? (bool) $preview['pinned_apply'] : false );

		if ( $pinned_club_id && ! $this->get_club_by_id( $pinned_club_id ) ) {
			$pinned_club_id = 0;
			$pinned_apply   = false;
		}

		if ( $pinned_apply && $pinned_club_id ) {
			$preview['force_club_id'] = $pinned_club_id;
		}

		$preview['use_season_override']      = $use_season_override;
		$preview['season_end_year_override'] = $season_end_year_override;
		$preview['auto_save_alias']          = $auto_save_alias;
		$preview['pinned_club_id']           = $pinned_club_id;
		$preview['pinned_apply']             = $pinned_apply;

		$mode = isset( $_POST['ufsc_asptt_mode'] ) ? sanitize_key( wp_unslash( $_POST['ufsc_asptt_mode'] ) ) : 'dry_run';

		if ( 'import' !== $mode ) {
			$this->service->insert_import_log(
				array(
					'user_id'      => get_current_user_id(),
					'file_name'    => isset( $preview['file_name'] ) ? $preview['file_name'] : '',
					'mode'         => 'dry_run',
					'total_rows'   => isset( $preview['stats']['total'] ) ? (int) $preview['stats']['total'] : 0,
					'success_rows' => 0,
					'error_rows'   => 0,
					'status'       => 'completed',
				)
			);

			$preview['notice'] = array(
				'type'    => 'info',
				'message' => __( 'Simulation enregistrée.', 'ufsc-licence-competition' ),
			);

			update_option( self::SESSION_KEY, $preview, false );
			wp_safe_redirect( $this->get_admin_url() );
			exit;
		}

		$auto_approve  = isset( $_POST['ufsc_asptt_auto_approve'] ) ? (bool) absint( $_POST['ufsc_asptt_auto_approve'] ) : true;
		$force_club_id = isset( $preview['force_club_id'] ) ? (int) $preview['force_club_id'] : 0;
		$mapping       = isset( $preview['mapping'] ) ? $preview['mapping'] : array();

		$result = $this->service->import_from_file(
			$preview['file_path'],
			$force_club_id,
			$mapping,
			$auto_approve,
			$season_end_year_override,
			$auto_save_alias
		);

		if ( is_wp_error( $result ) ) {
			$this->service->insert_import_log(
				array(
					'user_id'       => get_current_user_id(),
					'file_name'     => isset( $preview['file_name'] ) ? $preview['file_name'] : '',
					'mode'          => 'import',
					'status'        => 'failed',
					'error_message' => $result->get_error_message(),
				)
			);

			$preview['notice'] = array(
				'type'    => 'error',
				'message' => $result->get_error_message(),
			);
		} else {
			$stats        = isset( $result['stats'] ) ? $result['stats'] : array();
			$total_rows   = isset( $stats['total'] ) ? (int) $stats['total'] : 0;
			$success_rows = isset( $stats['ok'] ) ? (int) $stats['ok'] : 0;
			$error_rows   = isset( $stats['errors'] ) ? (int) $stats['errors'] : 0;

			$this->service->insert_import_log(
				array(
					'user_id'      => get_current_user_id(),
					'file_name'    => isset( $preview['file_name'] ) ? $preview['file_name'] : '',
					'mode'         => 'import',
					'total_rows'   => $total_rows,
					'success_rows' => $success_rows,
					'error_rows'   => $error_rows,
					'status'       => 'completed',
				)
			);

			$message = sprintf(
				/* translators: 1: success rows, 2: error rows */
				__( 'Import terminé. Succès: %1$d, erreurs: %2$d.', 'ufsc-licence-competition' ),
				$success_rows,
				$error_rows
			);

			$notice = array(
				'type'    => $error_rows ? 'warning' : 'success',
				'message' => $message,
			);

			if ( $error_rows ) {
				$notice['review_link']  = add_query_arg(
					array(
						'page' => 'ufsc-lc-import-asptt',
						'tab'  => 'review',
					),
					admin_url( 'admin.php' )
				);
				$notice['review_label'] = __( 'Aller au review', 'ufsc-licence-competition' );
			}

			$preview['notice'] = $notice;
			$this->persist_last_import( $preview, $result );
		}

		update_option( self::SESSION_KEY, $preview, false );
		wp_safe_redirect( $this->get_admin_url() );
		exit;
	}

	public function handle_cancel_import() {
		if ( ! UFSC_LC_Capabilities::user_can_import() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['ufsc_lc_asptt_cancel_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_lc_asptt_cancel_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ufsc_lc_asptt_cancel_import' ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$cancel_action = isset( $_POST['cancel_action'] ) ? sanitize_key( wp_unslash( $_POST['cancel_action'] ) ) : '';
		$redirect_url  = $this->get_admin_url();

		if ( 'rollback' === $cancel_action ) {
			if ( class_exists( 'UFSC_LC_Settings_Page' ) && ! UFSC_LC_Settings_Page::is_asptt_rollback_enabled() ) {
				$redirect_url = $this->add_notice_args( $redirect_url, 'warning', __( 'L’annulation des imports est désactivée.', 'ufsc-licence-competition' ) );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			$last_import = $this->get_last_import();
			if ( empty( $last_import['created_documents'] ) && empty( $last_import['created_meta'] ) ) {
				$redirect_url = $this->add_notice_args( $redirect_url, 'warning', __( 'Aucun import récent à annuler.', 'ufsc-licence-competition' ) );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			$result = $this->service->rollback_import_batch( $last_import );
			if ( is_wp_error( $result ) ) {
				$redirect_url = $this->add_notice_args( $redirect_url, 'error', $result->get_error_message() );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			delete_option( self::LAST_IMPORT_KEY );
			$redirect_url = $this->add_notice_args( $redirect_url, 'success', __( 'Dernier import annulé.', 'ufsc-licence-competition' ) );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		$preview = $this->get_preview();
		$this->clear_preview_files( $preview );
		delete_option( self::SESSION_KEY );

		$redirect_url = $this->add_notice_args( $redirect_url, 'success', __( 'Prévisualisation annulée.', 'ufsc-licence-competition' ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function handle_export_errors() {
		if ( ! UFSC_LC_Capabilities::user_can_import() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['ufsc_lc_asptt_errors_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_lc_asptt_errors_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ufsc_lc_asptt_export_errors' ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$preview = $this->get_preview();
		$errors  = isset( $preview['errors'] ) ? $preview['errors'] : array();

		if ( empty( $errors ) ) {
			wp_safe_redirect( $this->get_admin_url() );
			exit;
		}

		$this->service->export_errors_csv( $errors );
	}

	public function ajax_search_clubs() {
		if ( ! UFSC_LC_Capabilities::user_can_import() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
		}

		check_ajax_referer( 'ufsc_lc_club_search' );

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		if ( '' === $term ) {
			wp_send_json_success( array() );
		}

		global $wpdb;
		$table = $this->get_clubs_table();
		$like  = '%' . $wpdb->esc_like( $term ) . '%';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, nom FROM {$table} WHERE nom LIKE %s ORDER BY nom ASC LIMIT 20",
				$like
			)
		);

		$items = array();
		foreach ( $results as $club ) {
			$items[] = array(
				'id'   => (int) $club->id,
				'text' => $club->nom,
			);
		}

		wp_send_json_success( $items );
	}

	private function get_auto_validate_score( $stats ) {
		$total = isset( $stats['total'] ) ? (int) $stats['total'] : 0;
		if ( $total <= 0 ) {
			return 0;
		}

		$linked = isset( $stats['licences_linked'] ) ? (int) $stats['licences_linked'] : 0;

		return (int) round( ( $linked / $total ) * 100 );
	}

	public function ajax_save_alias() {
		if ( ! UFSC_LC_Capabilities::user_can_import() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
		}

		check_ajax_referer( 'ufsc_lc_asptt_save_alias' );

		$row_index_raw = isset( $_POST['row_index'] ) ? wp_unslash( $_POST['row_index'] ) : '';
		$club_id_raw   = isset( $_POST['club_id'] ) ? wp_unslash( $_POST['club_id'] ) : '';

		$row_index = ( '' !== $row_index_raw && ctype_digit( (string) $row_index_raw ) ) ? (int) $row_index_raw : -1;
		$club_id   = ( '' !== $club_id_raw && ctype_digit( (string) $club_id_raw ) ) ? (int) $club_id_raw : 0;

		if ( $row_index < 0 || $club_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Données invalides.', 'ufsc-licence-competition' ) ), 400 );
		}

		$preview = $this->get_preview();
		$rows    = isset( $preview['rows'] ) ? $preview['rows'] : array();
		$row     = isset( $rows[ $row_index ] ) ? $rows[ $row_index ] : null;
		$note    = $row && isset( $row['note'] ) ? $row['note'] : '';

		if ( '' === $note ) {
			wp_send_json_error( array( 'message' => __( 'Aucun alias à enregistrer.', 'ufsc-licence-competition' ) ), 400 );
		}

		$this->service->save_alias( $club_id, $note );
		wp_send_json_success( array( 'message' => __( 'Association enregistrée.', 'ufsc-licence-competition' ) ) );
	}

	private function render_import_logs() {
		$logs = $this->service->get_import_logs( 10 );

		if ( empty( $logs ) ) {
			return;
		}
		?>
		<div class="ufsc-lc-import-logs">
			<h2><?php esc_html_e( 'Historique des imports', 'ufsc-licence-competition' ); ?></h2>
			<div class="ufsc-lc-table-wrap">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Fichier', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Mode', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Total', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Succès', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Erreurs', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Utilisateur', 'ufsc-licence-competition' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<?php $user = get_user_by( 'id', (int) $log->user_id ); ?>
							<tr>
								<td><?php echo esc_html( $log->created_at ); ?></td>
								<td><?php echo esc_html( $log->file_name ); ?></td>
								<td><?php echo esc_html( $log->mode ); ?></td>
								<td><?php echo esc_html( $log->total_rows ); ?></td>
								<td><?php echo esc_html( $log->success_rows ); ?></td>
								<td><?php echo esc_html( $log->error_rows ); ?></td>
								<td><?php echo esc_html( $log->status ); ?></td>
								<td><?php echo esc_html( $user ? $user->display_name : (string) $log->user_id ); ?></td>
							</tr>
							<?php if ( ! empty( $log->error_message ) ) : ?>
								<tr>
									<td colspan="8">
										<div class="ufsc-lc-import-log-error"><?php echo esc_html( $log->error_message ); ?></div>
									</td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private function get_preview() {
		$preview = get_option( self::SESSION_KEY, array() );
		return is_array( $preview ) ? $preview : array();
	}

	private function get_last_import() {
		$last_import = get_option( self::LAST_IMPORT_KEY, array() );
		return is_array( $last_import ) ? $last_import : array();
	}

	private function persist_last_import( $preview, $result ) {
		$created_documents = isset( $result['created_documents'] ) ? array_map( 'absint', (array) $result['created_documents'] ) : array();
		$created_meta      = isset( $result['created_meta'] ) ? array_map( 'absint', (array) $result['created_meta'] ) : array();

		$created_documents = array_values( array_filter( array_unique( $created_documents ) ) );
		$created_meta      = array_values( array_filter( array_unique( $created_meta ) ) );

		if ( empty( $created_documents ) && empty( $created_meta ) ) {
			return;
		}

		update_option(
			self::LAST_IMPORT_KEY,
			array(
				'file_name'         => isset( $preview['file_name'] ) ? sanitize_text_field( $preview['file_name'] ) : '',
				'created_at'        => current_time( 'mysql' ),
				'created_documents' => $created_documents,
				'created_meta'      => $created_meta,
			),
			false
		);
	}

	private function clear_preview_files( $preview ) {
		if ( empty( $preview['file_path'] ) ) {
			return;
		}

		$file_path  = wp_normalize_path( $preview['file_path'] );
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );

		if ( 0 === strpos( $file_path, $base_dir ) && file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}
	}

	private function add_notice_args( $url, $type, $message ) {
		return add_query_arg(
			array(
				'ufsc_lc_notice_type' => $type,
				'ufsc_lc_notice'      => $message,
			),
			$url
		);
	}

	private function get_admin_url() {
		return add_query_arg(
			array(
				'page' => 'ufsc-lc-import-asptt',
			),
			admin_url( 'admin.php' )
		);
	}

	private function get_default_season_end_year() {
		if ( class_exists( 'UFSC_LC_Settings_Page' ) ) {
			return UFSC_LC_Settings_Page::get_default_season_end_year();
		}

		$option = get_option( 'ufsc_lc_default_season_end_year', 2026 );
		$year   = UFSC_LC_Categories::sanitize_season_end_year( $option );

		return $year ? $year : 2026;
	}

	private function get_clubs() {
		global $wpdb;

		$table = $this->get_clubs_table();

		return $wpdb->get_results( "SELECT id, nom FROM {$table} ORDER BY nom ASC" );
	}

	private function get_club_by_id( $club_id ) {
		global $wpdb;

		$table = $this->get_clubs_table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT id, nom FROM {$table} WHERE id = %d", $club_id )
		);
	}

	private function get_clubs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_clubs';
	}

	private function is_valid_preview_path( $file_path ) {
		if ( ! $file_path ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) ) . 'ufsc-lc/';
		$path       = wp_normalize_path( $file_path );

		if ( 0 !== strpos( $path, $base_dir ) ) {
			return false;
		}

		return is_readable( $file_path );
	}

	private function get_aliases_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_asptt_aliases';
	}

	private function get_import_logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_asptt_import_logs';
	}
}
