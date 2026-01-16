<?php

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
		add_action( 'wp_ajax_ufsc_lc_club_search', array( $this, 'ajax_search_clubs' ) );
		add_action( 'wp_ajax_ufsc_lc_asptt_save_alias', array( $this, 'ajax_save_alias' ) );
		add_action( 'admin_post_ufsc_lc_asptt_export_errors', array( $this, 'handle_export_errors' ) );

		if ( $this->legacy_enabled ) {
			add_action( 'admin_post_ufsc_asptt_upload', array( $this, 'handle_upload' ) );
			add_action( 'admin_post_ufsc_asptt_import', array( $this, 'handle_import' ) );
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
			UFSC_LC_Capabilities::IMPORT_CAPABILITY,
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

		$stats         = $preview && isset( $preview['stats'] ) ? array_merge( $stats_defaults, $preview['stats'] ) : $stats_defaults;
		$rows          = $preview && isset( $preview['rows'] ) ? $preview['rows'] : array();
		$errors        = $preview && isset( $preview['errors'] ) ? $preview['errors'] : array();
		$headers       = $preview && ! empty( $preview['headers'] ) ? $preview['headers'] : array();
		$mapping       = $preview && ! empty( $preview['mapping'] ) ? $preview['mapping'] : array();
		$file_name     = $preview && ! empty( $preview['file_name'] ) ? $preview['file_name'] : '';
		$preview_limit = $preview && ! empty( $preview['preview_limit'] ) ? (int) $preview['preview_limit'] : UFSC_LC_ASPTT_Import_Service::PREVIEW_DEFAULT_LIMIT;
		$force_club_id = $preview && ! empty( $preview['force_club_id'] ) ? (int) $preview['force_club_id'] : 0;
		$default_season_end_year = $this->get_default_season_end_year();
		$use_season_override     = $preview && ! empty( $preview['use_season_override'] );
		$season_end_year_override = $preview && ! empty( $preview['season_end_year_override'] ) ? (int) $preview['season_end_year_override'] : $default_season_end_year;
		$auto_save_alias = $preview && isset( $preview['auto_save_alias'] ) ? (bool) $preview['auto_save_alias'] : true;
		$force_club    = $force_club_id ? $this->get_club_by_id( $force_club_id ) : null;
		?>
		<div class="wrap ufsc-lc-admin">
			<h1><?php esc_html_e( 'Import ASPTT', 'ufsc-licence-competition' ); ?></h1>
			<?php $this->render_tabs( $tab ); ?>

			<?php if ( 'review' === $tab ) : ?>
				<?php
				$review_page = new UFSC_LC_ASPTT_Review_Page( $this->service );
				$review_page->render();
				?>
			<?php else : ?>
				<?php $this->render_import_tab( $preview, $rows, $errors, $headers, $mapping, $file_name, $preview_limit, $force_club_id, $force_club, $stats, $default_season_end_year, $use_season_override, $season_end_year_override, $auto_save_alias ); ?>
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

	private function render_import_tab( $preview, $rows, $errors, $headers, $mapping, $file_name, $preview_limit, $force_club_id, $force_club, $stats, $default_season_end_year, $use_season_override, $season_end_year_override, $auto_save_alias ) {
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
							<input
								type="number"
								value="<?php echo esc_attr( $default_season_end_year ); ?>"
								readonly
							>
							<p class="description">
								<?php esc_html_e( 'Année de fin de saison utilisée quand le CSV ne fournit pas la saison.', 'ufsc-licence-competition' ); ?>
							</p>
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
							<input
								type="number"
								name="ufsc_asptt_season_end_year"
								value="<?php echo esc_attr( $season_end_year_override ); ?>"
								min="2000"
								max="2100"
							>
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

			<?php $this->render_stats( $stats, $errors, $preview_limit ); ?>

			<?php if ( ! empty( $headers ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-lc-mapping-form">
					<?php wp_nonce_field( 'ufsc_lc_asptt_upload', 'ufsc_lc_asptt_nonce' ); ?>
					<input type="hidden" name="action" value="ufsc_lc_asptt_upload">
					<input type="hidden" name="ufsc_lc_reprocess" value="1">
					<?php if ( $force_club_id ) : ?>
						<input type="hidden" name="ufsc_asptt_force_club" value="<?php echo esc_attr( $force_club_id ); ?>">
					<?php endif; ?>
					<p>
						<strong><?php esc_html_e( 'Saison par défaut', 'ufsc-licence-competition' ); ?>:</strong>
						<?php echo esc_html( $default_season_end_year ); ?>
					</p>
					<p>
						<label>
							<input type="checkbox" name="ufsc_asptt_use_season_override" value="1" <?php checked( $use_season_override ); ?>>
							<?php esc_html_e( 'Utiliser une saison spécifique pour cet import', 'ufsc-licence-competition' ); ?>
						</label>
						<input
							type="number"
							name="ufsc_asptt_season_end_year"
							value="<?php echo esc_attr( $season_end_year_override ); ?>"
							min="2000"
							max="2100"
						>
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

			<?php $this->render_preview_table( $rows, $preview_limit ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ufsc_lc_asptt_import', 'ufsc_lc_asptt_import_nonce' ); ?>
				<input type="hidden" name="action" value="ufsc_lc_asptt_import">
				<input type="hidden" name="ufsc_asptt_use_season_override" value="<?php echo esc_attr( $use_season_override ? 1 : 0 ); ?>">
				<input type="hidden" name="ufsc_asptt_season_end_year" value="<?php echo esc_attr( $season_end_year_override ); ?>">
				<input type="hidden" name="ufsc_asptt_auto_save_alias" value="<?php echo esc_attr( $auto_save_alias ? 1 : 0 ); ?>">
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
					<input type="checkbox" name="ufsc_asptt_auto_approve" value="1" checked>
					<?php esc_html_e( 'Auto-valider si score ≥ 85', 'ufsc-licence-competition' ); ?>
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

	private function render_preview_table( $rows, $preview_limit ) {
		$rows = array_slice( $rows, 0, $preview_limit );
		?>
		<h2><?php esc_html_e( 'Prévisualisation', 'ufsc-licence-competition' ); ?></h2>
		<div class="ufsc-lc-filter-row">
			<label>
				<input type="checkbox" id="ufsc-asptt-errors-only">
				<?php esc_html_e( 'Afficher seulement erreurs', 'ufsc-licence-competition' ); ?>
			</label>
			<input type="search" id="ufsc-asptt-search" placeholder="<?php esc_attr_e( 'Rechercher nom/prénom/club/n°', 'ufsc-licence-competition' ); ?>" style="min-width:280px;">
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
						<?php $search_value = strtolower( trim( $row['nom'] . ' ' . $row['prenom'] . ' ' . $row['note'] . ' ' . $row['asptt_number'] ) ); ?>
						<tr data-has-error="<?php echo esc_attr( $row['has_error'] ? '1' : '0' ); ?>" data-search="<?php echo esc_attr( $search_value ); ?>" data-status="<?php echo esc_attr( $row['status'] ); ?>">
							<td><?php echo esc_html( $row['nom'] ); ?></td>
							<td><?php echo esc_html( $row['prenom'] ); ?></td>
							<td><?php echo esc_html( $row['date_naissance'] ); ?></td>
							<td><?php echo esc_html( ! empty( $row['season_end_year'] ) ? $row['season_end_year'] : '—' ); ?></td>
							<td><?php echo esc_html( ! empty( $row['category'] ) ? $row['category'] : '—' ); ?></td>
							<td><?php echo esc_html( ! empty( $row['age_ref'] ) ? $row['age_ref'] : '—' ); ?></td>
							<td><?php echo esc_html( $row['note'] ); ?></td>
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
							$review_label  = ( 'auto' === $review_status )
								? __( 'Auto', 'ufsc-licence-competition' )
								: __( 'En attente', 'ufsc-licence-competition' );
							$review_badge_class = 'auto' === $review_status ? 'ufsc-badge--success' : 'ufsc-badge--warning';
							?>
							<td>
								<span class="ufsc-badge <?php echo esc_attr( $review_badge_class ); ?>">
									<?php echo esc_html( $review_label ); ?>
								</span>
							</td>
							<td>
								<?php if ( empty( $row['club_id'] ) && in_array( $row['status'], array( self::STATUS_NEEDS_REVIEW, self::STATUS_CLUB_NOT_FOUND ), true ) ) : ?>
									<span class="ufsc-badge ufsc-badge--warning"><?php esc_html_e( 'À associer', 'ufsc-licence-competition' ); ?></span>
								<?php endif; ?>
								<?php if ( self::STATUS_NEEDS_REVIEW === $row['status'] && ! empty( $row['club_suggestions'] ) ) : ?>
									<div class="ufsc-lc-inline-actions">
										<select class="ufsc-club-select" data-row-index="<?php echo esc_attr( $index ); ?>">
											<option value=""><?php esc_html_e( 'Sélectionner un club', 'ufsc-licence-competition' ); ?></option>
											<?php foreach ( $row['club_suggestions'] as $suggestion ) : ?>
												<option value="<?php echo esc_attr( $suggestion['id'] ); ?>">
													<?php echo esc_html( $suggestion['name'] ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<button type="button" class="button ufsc-save-alias" data-row-index="<?php echo esc_attr( $index ); ?>" disabled>
											<?php esc_html_e( 'Valider association', 'ufsc-licence-competition' ); ?>
										</button>
										<span class="ufsc-alias-feedback" data-row-index="<?php echo esc_attr( $index ); ?>"></span>
									</div>
								<?php elseif ( self::STATUS_CLUB_NOT_FOUND === $row['status'] ) : ?>
									<div class="ufsc-lc-inline-actions">
										<input type="text" class="ufsc-club-search" data-row-index="<?php echo esc_attr( $index ); ?>" placeholder="<?php esc_attr_e( 'Rechercher un club', 'ufsc-licence-competition' ); ?>">
										<select class="ufsc-club-select" data-row-index="<?php echo esc_attr( $index ); ?>">
											<option value=""><?php esc_html_e( 'Sélectionner un club', 'ufsc-licence-competition' ); ?></option>
										</select>
										<button type="button" class="button ufsc-save-alias" data-row-index="<?php echo esc_attr( $index ); ?>" disabled>
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
	 * ✅ Merge resolved:
	 * - we keep i18n labels (codex) AND the extra "Saison (année de fin)" (main)
	 * - keys are stable internal identifiers used by mapping service
	 */
	private function get_mapping_options() {
		return array(
			'Nom'                  => __( 'Nom', 'ufsc-licence-competition' ),
			'Prenom'               => __( 'Prénom', 'ufsc-licence-competition' ),
			'Date de naissance'    => __( 'Date de naissance', 'ufsc-licence-competition' ),
			'Saison (année de fin)' => __( 'Saison (année de fin)', 'ufsc-licence-competition' ),
			'N° Licence'           => __( 'N° Licence', 'ufsc-licence-competition' ),
			'Date de création de la licence' => __( 'Date de création de la licence', 'ufsc-licence-competition' ),
			'Note'                 => __( 'Note', 'ufsc-licence-competition' ),
			'genre'                => __( 'Genre', 'ufsc-licence-competition' ),
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

		$force_club_id = isset( $_POST['ufsc_asptt_force_club'] ) ? absint( $_POST['ufsc_asptt_force_club'] ) : 0;
		$mode          = isset( $_POST['ufsc_asptt_mode'] ) ? sanitize_key( wp_unslash( $_POST['ufsc_asptt_mode'] ) ) : 'dry_run';
		$mapping       = isset( $_POST['ufsc_lc_mapping'] ) ? $this->service->sanitize_mapping( wp_unslash( $_POST['ufsc_lc_mapping'] ) ) : array();
		$preview_limit = isset( $_POST['ufsc_asptt_preview_limit'] ) ? $this->service->sanitize_preview_limit( wp_unslash( $_POST['ufsc_asptt_preview_limit'] ) ) : UFSC_LC_ASPTT_Import_Service::PREVIEW_DEFAULT_LIMIT;

		$existing_preview = $this->get_preview();
		$use_season_override = isset( $_POST['ufsc_asptt_use_season_override'] );
		$raw_override         = isset( $_POST['ufsc_asptt_season_end_year'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_asptt_season_end_year'] ) ) : '';
		$season_end_year_override = $use_season_override ? UFSC_LC_Categories::sanitize_season_end_year( $raw_override ) : null;
		$auto_save_alias = isset( $_POST['ufsc_asptt_auto_save_alias'] ) ? (bool) absint( $_POST['ufsc_asptt_auto_save_alias'] ) : false;

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
			if ( ! empty( $existing_preview['file_path'] ) && isset( $_POST['ufsc_lc_reprocess'] ) ) {
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

		if ( is_wp_error( $preview ) ) {
			$preview = array(
				'notice' => array(
					'type'    => 'error',
					'message' => $preview->get_error_message(),
				),
			);
		}

		if ( ! empty( $preview ) ) {
			$preview['force_club_id']  = $force_club_id;
			$preview['mode']           = $mode;
			$preview['preview_limit']  = $preview_limit;
			$preview['use_season_override'] = $use_season_override;
			$preview['season_end_year_override'] = $season_end_year_override;
			$preview['auto_save_alias'] = $auto_save_alias;
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

		$preview['use_season_override']      = $use_season_override;
		$preview['season_end_year_override'] = $season_end_year_override;
		$preview['auto_save_alias']          = $auto_save_alias;

		$mode = isset( $_POST['ufsc_asptt_mode'] ) ? sanitize_key( wp_unslash( $_POST['ufsc_asptt_mode'] ) ) : 'dry_run';
		if ( 'import' !== $mode ) {
			$this->service->insert_import_log(
				array(
					'user_id'      => get_current_user_id(),
					'file_name'    => isset( $preview['file_name'] ) ? $preview['file_name'] : '',
					'mode'         => 'dry_run',
					'total_rows'   => isset( $preview['stats']['total'] ) ? (int) $preview['stats']['total'] : 0,
					'success_rows' => isset( $preview['stats']['licences_linked'] ) ? (int) $preview['stats']['licences_linked'] : 0,
					'error_rows'   => isset( $preview['errors'] ) ? count( $preview['errors'] ) : 0,
					'status'       => 'completed',
				)
			);

			$preview['notice'] = array(
				'type'    => 'info',
				'message' => __( 'Simulation terminée (aucune donnée importée).', 'ufsc-licence-competition' ),
			);
			update_option( self::SESSION_KEY, $preview, false );
			wp_safe_redirect( $this->get_admin_url() );
			exit;
		}

		$auto_approve = isset( $_POST['ufsc_asptt_auto_approve'] );

		$result = $this->service->import_from_file(
			$preview['file_path'],
			$preview['force_club_id'],
			isset( $preview['mapping'] ) ? $preview['mapping'] : array(),
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
			update_option( self::SESSION_KEY, $preview, false );
			wp_safe_redirect( $this->get_admin_url() );
			exit;
		}

		delete_option( self::SESSION_KEY );

		$stats  = isset( $result['stats'] ) ? $result['stats'] : array();
		$notice = sprintf(
			/* translators: 1: ok rows, 2: error rows */
			__( 'Import terminé. %1$d lignes OK / %2$d lignes en erreur.', 'ufsc-licence-competition' ),
			isset( $stats['ok'] ) ? (int) $stats['ok'] : 0,
			isset( $stats['errors'] ) ? (int) $stats['errors'] : 0
		);

		$this->service->insert_import_log(
			array(
				'user_id'      => get_current_user_id(),
				'file_name'    => isset( $preview['file_name'] ) ? $preview['file_name'] : '',
				'mode'         => 'import',
				'total_rows'   => isset( $stats['total'] ) ? (int) $stats['total'] : 0,
				'success_rows' => isset( $stats['ok'] ) ? (int) $stats['ok'] : 0,
				'error_rows'   => isset( $stats['errors'] ) ? (int) $stats['errors'] : 0,
				'status'       => 'completed',
			)
		);

		update_option(
			self::SESSION_KEY,
			array(
				'notice' => array(
					'type'         => 'success',
					'message'      => $notice,
					'review_link'  => $this->get_admin_url( 'review' ),
					'review_label' => __( 'Aller à Review (pending)', 'ufsc-licence-competition' ),
				),
			),
			false
		);
		wp_safe_redirect( $this->get_admin_url() );
		exit;
	}

	public function ajax_search_clubs() {
		if ( ! UFSC_LC_Capabilities::user_can_import() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ) );
		}

		check_ajax_referer( 'ufsc_lc_club_search' );

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		if ( '' === $term ) {
			wp_send_json_success( array() );
		}

		$clubs = $this->service->search_clubs( $term );
		$data  = array();

		foreach ( $clubs as $club ) {
			$data[] = array(
				'id'   => $club->id,
				'text' => $club->nom,
			);
		}

		wp_send_json_success( $data );
	}

	public function ajax_save_alias() {
		if ( ! UFSC_LC_Capabilities::user_can_import() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ) );
		}

		check_ajax_referer( 'ufsc_lc_asptt_save_alias' );

		$row_index = isset( $_POST['row_index'] ) ? absint( $_POST['row_index'] ) : -1;
		$club_id   = isset( $_POST['club_id'] ) ? absint( $_POST['club_id'] ) : 0;

		$preview = $this->get_preview();
		if ( $row_index < 0 || empty( $preview['rows'][ $row_index ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Ligne introuvable.', 'ufsc-licence-competition' ) ) );
		}

		$row = $preview['rows'][ $row_index ];
		if ( ! $club_id || '' === $row['note'] ) {
			wp_send_json_error( array( 'message' => __( 'Données invalides.', 'ufsc-licence-competition' ) ) );
		}

		$this->service->save_alias( $club_id, $row['note'] );

		if ( empty( $preview['file_path'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Fichier introuvable.', 'ufsc-licence-competition' ) ) );
		}

		$updated = $this->service->build_preview(
			$preview['file_path'],
			$preview['force_club_id'],
			isset( $preview['mapping'] ) ? $preview['mapping'] : array(),
			0,
			isset( $preview['season_end_year_override'] ) ? $preview['season_end_year_override'] : null
		);

		if ( is_wp_error( $updated ) ) {
			wp_send_json_error( array( 'message' => $updated->get_error_message() ) );
		}

		$updated['file_path']     = $preview['file_path'];
		$updated['file_name']     = isset( $preview['file_name'] ) ? $preview['file_name'] : '';
		$updated['force_club_id'] = $preview['force_club_id'];
		$updated['mapping']       = isset( $preview['mapping'] ) ? $preview['mapping'] : array();
		$updated['use_season_override'] = isset( $preview['use_season_override'] ) ? (bool) $preview['use_season_override'] : false;
		$updated['season_end_year_override'] = isset( $preview['season_end_year_override'] ) ? $preview['season_end_year_override'] : null;
		$updated['auto_save_alias'] = isset( $preview['auto_save_alias'] ) ? (bool) $preview['auto_save_alias'] : true;

		update_option( self::SESSION_KEY, $updated, false );

		wp_send_json_success( array( 'message' => __( 'Alias enregistré.', 'ufsc-licence-competition' ) ) );
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
		if ( empty( $preview['errors'] ) ) {
			wp_die( esc_html__( 'Aucune erreur à exporter.', 'ufsc-licence-competition' ) );
		}

		$this->service->export_errors_csv( $preview['errors'] );
	}

	/* ---------------------------------------------------------------------
	 * Helpers / data layer (legacy methods retained for compatibility)
	 * -------------------------------------------------------------------*/

	private function build_preview( $csv_path, $force_club_id ) {
		$rows = $this->read_csv( $csv_path );

		$preview                 = $this->build_preview_from_rows( $rows, $force_club_id );
		$preview['raw_rows']     = $rows;
		$preview['force_club_id'] = $force_club_id;

		return $preview;
	}

	private function build_preview_from_rows( $rows, $force_club_id ) {
		$preview_rows = array();
		$errors       = array();
		$stats        = array(
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

		foreach ( $rows as $row ) {
			$stats['total']++;

			$note           = isset( $row['Note'] ) ? sanitize_text_field( $row['Note'] ) : '';
			$nom            = isset( $row['Nom'] ) ? sanitize_text_field( $row['Nom'] ) : '';
			$prenom         = isset( $row['Prenom'] ) ? sanitize_text_field( $row['Prenom'] ) : '';
			$dob            = isset( $row['Date de naissance'] ) ? sanitize_text_field( $row['Date de naissance'] ) : '';
			$asptt_no        = isset( $row['N° Licence'] ) ? sanitize_text_field( $row['N° Licence'] ) : '';
			$asptt_no        = trim( $asptt_no );
			$genre          = isset( $row['genre'] ) ? sanitize_text_field( $row['genre'] ) : '';
			$raw_created_at = isset( $row['Date de création de la licence'] ) ? sanitize_text_field( $row['Date de création de la licence'] ) : '';
			$source_created_at = $this->parse_source_created_at( $raw_created_at );

			$row_errors       = array();
			$club_suggestions  = array();
			$licence_id        = 0;
			$status            = self::STATUS_CLUB_NOT_FOUND;

			if ( '' === $asptt_no ) {
				$status = self::STATUS_INVALID_ASPTT_NUMBER;
				$stats['invalid_asptt_number']++;
				$row_errors[] = __( 'N° Licence ASPTT manquant.', 'ufsc-licence-competition' );
				$this->log_import_warning(
					__( 'N° Licence ASPTT manquant.', 'ufsc-licence-competition' ),
					array(
						'nom'            => $nom,
						'prenom'         => $prenom,
						'date_naissance' => $dob,
						'note'           => $note,
					)
				);

				$resolved = array(
					'status'      => self::STATUS_INVALID_ASPTT_NUMBER,
					'club_id'     => 0,
					'suggestions' => array(),
				);
			} else {
				$resolved = $this->resolve_club( $note, $force_club_id );
				$club_suggestions = $resolved['suggestions'];

				if ( $resolved['status'] === self::STATUS_LINKED ) {
					$stats['clubs_linked']++;
					$licence_id = $this->find_licence_id( $resolved['club_id'], $nom, $prenom, $dob, $genre );
					if ( $licence_id ) {
						$status = self::STATUS_LINKED;
						$stats['licences_linked']++;
					} else {
						$status = self::STATUS_LICENCE_MISSING;
						$stats['licence_not_found']++;
						$row_errors[] = __( 'Licence introuvable.', 'ufsc-licence-competition' );
					}
				} elseif ( $resolved['status'] === self::STATUS_NEEDS_REVIEW ) {
					$status = self::STATUS_NEEDS_REVIEW;
					$stats['needs_review']++;
					$row_errors[] = __( 'Plusieurs clubs possibles.', 'ufsc-licence-competition' );
				} else {
					$status = self::STATUS_CLUB_NOT_FOUND;
					$stats['club_not_found']++;
					$row_errors[] = __( 'Club introuvable.', 'ufsc-licence-competition' );
				}
			}

			if ( '' !== $raw_created_at && null === $source_created_at ) {
				$row_errors[] = sprintf(
					/* translators: %s: raw date string */
					__( 'Date de création de la licence invalide: %s', 'ufsc-licence-competition' ),
					$raw_created_at
				);
				$this->log_import_warning(
					__( 'Date de création de la licence invalide.', 'ufsc-licence-competition' ),
					array( 'value' => $raw_created_at )
				);
			}

			$preview_rows[] = array(
				'nom'                 => $nom,
				'prenom'              => $prenom,
				'date_naissance'      => $dob,
				'note'                => $note,
				'asptt_number'        => $asptt_no,
				'source_created_at'   => $source_created_at,
				'source_created_at_raw' => $raw_created_at,
				'club_id'             => $resolved['club_id'],
				'club_suggestions'    => $club_suggestions,
				'status'              => $status,
				'licence_id'          => $licence_id,
				'attachment_id'       => 0,
				'has_error'           => ! empty( $row_errors ),
			);

			if ( ! empty( $row_errors ) ) {
				$errors[] = array(
					'nom'                 => $nom,
					'prenom'              => $prenom,
					'date_naissance'      => $dob,
					'note'                => $note,
					'asptt_number'        => $asptt_no,
					'source_created_at'   => ( null !== $source_created_at ) ? $source_created_at : $raw_created_at,
					'source_created_at_raw' => $raw_created_at,
					'status'              => $status,
					'error'               => implode( ' | ', $row_errors ),
				);
			}
		}

		return array(
			'rows'   => $preview_rows,
			'stats'  => $stats,
			'errors' => $errors,
		);
	}

	private function read_csv( $csv_path ) {
		$rows = array();
		if ( ! is_readable( $csv_path ) ) {
			return $rows;
		}

		$handle = fopen( $csv_path, 'r' );
		if ( ! $handle ) {
			return $rows;
		}

		$header = array();
		while ( false !== ( $data = fgetcsv( $handle, 0, ';' ) ) ) {
			if ( empty( $header ) ) {
				$header = $this->map_headers( $data );
				continue;
			}

			$row = array();
			foreach ( $header as $index => $column ) {
				$row[ $column ] = isset( $data[ $index ] ) ? $data[ $index ] : '';
			}
			$rows[] = $row;
		}

		fclose( $handle );

		return $rows;
	}

	private function map_headers( $headers ) {
		$mapped = array();
		$used   = array();

		foreach ( $headers as $header ) {
			$key = $this->map_header( $header );
			if ( isset( $used[ $key ] ) ) {
				$key = $header;
			}
			$used[ $key ] = true;
			$mapped[]     = $key;
		}

		return $mapped;
	}

	private function map_header( $header ) {
		$normalized = $this->normalize_header( $header );
		$mapping    = array(
			'nom'                        => 'Nom',
			'prenom'                     => 'Prenom',
			'genre'                      => 'genre',
			'sexe'                       => 'genre',
			'date de naissance'          => 'Date de naissance',
			'date naissance'             => 'Date de naissance',
			'date of birth'              => 'Date de naissance',
			'birth date'                 => 'Date de naissance',
			'n licence'                  => 'N° Licence',
			'no licence'                 => 'N° Licence',
			'numero licence'             => 'N° Licence',
			'numero de licence'          => 'N° Licence',
			'num licence'                => 'N° Licence',
			'licence number'             => 'N° Licence',
			'license number'             => 'N° Licence',
			'date de creation de la licence' => 'Date de création de la licence',
			'date d creation de la licence'  => 'Date de création de la licence',
			'date creation de la licence'    => 'Date de création de la licence',
			'date creation licence'          => 'Date de création de la licence',
			'date de creation licence'       => 'Date de création de la licence',
			'date creation de licence'       => 'Date de création de la licence',
			'date creation'                  => 'Date de création de la licence',
			'note'                       => 'Note',
		);

		return isset( $mapping[ $normalized ] ) ? $mapping[ $normalized ] : $header;
	}

	private function normalize_header( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = remove_accents( $value );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/u', ' ', $value );
		$value = preg_replace( '/\\s+/', ' ', $value );

		return trim( $value );
	}

	private function resolve_club( $note, $force_club_id ) {
		if ( $force_club_id ) {
			return array(
				'status'      => self::STATUS_LINKED,
				'club_id'     => $force_club_id,
				'suggestions' => array(),
			);
		}

		if ( '' === $note ) {
			return array(
				'status'      => self::STATUS_CLUB_NOT_FOUND,
				'club_id'     => 0,
				'suggestions' => array(),
			);
		}

		$normalized = $this->normalize_name( $note );

		$club = $this->find_club_by_name( $normalized );
		if ( $club ) {
			return array(
				'status'      => self::STATUS_LINKED,
				'club_id'     => (int) $club->id,
				'suggestions' => array(),
			);
		}

		$alias = $this->find_alias( $normalized );
		if ( $alias ) {
			return array(
				'status'      => self::STATUS_LINKED,
				'club_id'     => (int) $alias->club_id,
				'suggestions' => array(),
			);
		}

		$suggestions = $this->find_club_suggestions( $normalized );
		if ( count( $suggestions ) === 1 ) {
			return array(
				'status'      => self::STATUS_LINKED,
				'club_id'     => (int) $suggestions[0]->id,
				'suggestions' => array(),
			);
		}

		if ( count( $suggestions ) > 1 ) {
			$suggestion_rows = array();
			foreach ( $suggestions as $suggestion ) {
				$suggestion_rows[] = array(
					'id'   => (int) $suggestion->id,
					'name' => $suggestion->nom,
				);
			}

			return array(
				'status'      => self::STATUS_NEEDS_REVIEW,
				'club_id'     => 0,
				'suggestions' => $suggestion_rows,
			);
		}

		return array(
			'status'      => self::STATUS_CLUB_NOT_FOUND,
			'club_id'     => 0,
			'suggestions' => array(),
		);
	}

	private function find_licence_id( $club_id, $nom, $prenom, $dob, $genre ) {
		global $wpdb;

		$table             = $this->get_licences_table();
		$normalized_nom    = $this->normalize_name( $nom );
		$normalized_prenom = $this->normalize_name( $prenom );
		$dob_value         = $this->parse_date( $dob );

		if ( ! $dob_value ) {
			return 0;
		}

		$sql = "SELECT id, sexe, nom_licence, prenom, date_naissance FROM {$table}
			WHERE club_id = %d AND date_naissance = %s";

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $club_id, $dob_value ) );
		if ( empty( $results ) ) {
			return 0;
		}

		$matches = array();
		foreach ( $results as $row ) {
			if ( $this->normalize_name( $row->nom_licence ) === $normalized_nom
				&& $this->normalize_name( $row->prenom ) === $normalized_prenom ) {
				$matches[] = $row;
			}
		}

		if ( empty( $matches ) ) {
			return 0;
		}

		if ( 1 === count( $matches ) ) {
			return (int) $matches[0]->id;
		}

		if ( $genre ) {
			$genre_normalized = strtolower( trim( $genre ) );
			foreach ( $matches as $match ) {
				if ( strtolower( $match->sexe ) === $genre_normalized ) {
					return (int) $match->id;
				}
			}
		}

		return 0;
	}

	private function upsert_document( $licence_id, $source_licence_number, $attachment_id, $note, $source_created_at ) {
		global $wpdb;

		$table = $this->get_documents_table();

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s",
				$licence_id,
				self::SOURCE
			)
		);

		$data = array(
			'licence_id'             => $licence_id,
			'source'                 => self::SOURCE,
			'source_licence_number'  => $source_licence_number,
			'attachment_id'          => $attachment_id ? $attachment_id : null,
			'asptt_club_note'        => $note,
			'updated_at'             => current_time( 'mysql' ),
		);

		$field_formats = array(
			'licence_id'             => '%d',
			'source'                 => '%s',
			'source_licence_number'  => '%s',
			'attachment_id'          => '%d',
			'asptt_club_note'        => '%s',
			'updated_at'             => '%s',
			'source_created_at'      => '%s',
			'imported_at'            => '%s',
		);

		$formats = array();
		foreach ( array_keys( $data ) as $field ) {
			if ( isset( $field_formats[ $field ] ) ) {
				$formats[] = $field_formats[ $field ];
			}
		}

		if ( null !== $source_created_at ) {
			$data['source_created_at'] = $source_created_at;
			$formats[] = '%s';
		}

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing ), $formats, array( '%d' ) );
		} else {
			$data['imported_at'] = current_time( 'mysql' );

			$formats = array();
			foreach ( array_keys( $data ) as $field ) {
				if ( isset( $field_formats[ $field ] ) ) {
					$formats[] = $field_formats[ $field ];
				}
			}

			$wpdb->insert( $table, $data, $formats );
		}
	}

	private function save_alias( $club_id, $alias ) {
		global $wpdb;

		$table = $this->get_aliases_table();

		$normalized = $this->normalize_name( $alias );
		if ( '' === $normalized ) {
			return;
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE alias_normalized = %s", $normalized )
		);

		if ( $exists ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'club_id'          => $club_id,
				'alias'            => $alias,
				'alias_normalized' => $normalized,
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	private function normalize_name( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = strtoupper( $value );
		$value = remove_accents( $value );
		$value = preg_replace( '/[^A-Z0-9]+/u', ' ', $value );
		$value = preg_replace( '/\\s+/', ' ', $value );

		return trim( $value );
	}

	private function parse_date( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$parts = explode( '/', $value );
		if ( 3 !== count( $parts ) ) {
			return '';
		}

		$day   = str_pad( $parts[0], 2, '0', STR_PAD_LEFT );
		$month = str_pad( $parts[1], 2, '0', STR_PAD_LEFT );
		$year  = $parts[2];

		if ( ! checkdate( (int) $month, (int) $day, (int) $year ) ) {
			return '';
		}

		return $year . '-' . $month . '-' . $day;
	}

	private function parse_source_created_at( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}\\s+\\d{2}:\\d{2}:\\d{2}$/', $value ) ) {
			$date = DateTime::createFromFormat( 'Y-m-d H:i:s', $value );
			return $this->validate_datetime( $date ) ? $date->format( 'Y-m-d H:i:s' ) : null;
		}

		if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $value ) ) {
			$date = DateTime::createFromFormat( 'Y-m-d', $value );
			return $this->validate_datetime( $date ) ? $date->format( 'Y-m-d 00:00:00' ) : null;
		}

		if ( preg_match( '/^\\d{1,2}\\/\\d{1,2}\\/\\d{4}$/', $value ) ) {
			$date = DateTime::createFromFormat( 'd/m/Y', $value );
			return $this->validate_datetime( $date ) ? $date->format( 'Y-m-d 00:00:00' ) : null;
		}

		return null;
	}

	private function validate_datetime( $date ) {
		if ( ! $date ) {
			return false;
		}

		$errors = DateTime::getLastErrors();
		if ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) {
			return false;
		}

		return true;
	}

	private function get_default_season_end_year() {
		$option = get_option( 'ufsc_lc_default_season_end_year', 2026 );
		$year   = UFSC_LC_Categories::sanitize_season_end_year( $option );

		return $year ? $year : 2026;
	}

	private function log_import_warning( $message, $context = array() ) {
		UFSC_LC_Logger::log( $message, $context );
	}

	private function find_club_by_name( $normalized ) {
		global $wpdb;

		$table   = $this->get_clubs_table();
		$results = $wpdb->get_results( "SELECT id, nom FROM {$table}" );

		foreach ( $results as $club ) {
			if ( $this->normalize_name( $club->nom ) === $normalized ) {
				return $club;
			}
		}

		return null;
	}

	private function find_alias( $normalized ) {
		global $wpdb;

		$table = $this->get_aliases_table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT id, club_id FROM {$table} WHERE alias_normalized = %s", $normalized )
		);
	}

	private function find_club_suggestions( $normalized ) {
		global $wpdb;

		$table  = $this->get_clubs_table();
		$tokens = array_filter( explode( ' ', $normalized ) );

		if ( empty( $tokens ) ) {
			return array();
		}

		$like_clauses = array();
		$params       = array();

		foreach ( $tokens as $token ) {
			$like_clauses[] = 'nom LIKE %s';
			$params[]       = '%' . $wpdb->esc_like( $token ) . '%';
		}

		$sql = "SELECT id, nom FROM {$table} WHERE " . implode( ' AND ', $like_clauses );

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	private function search_clubs( $term ) {
		global $wpdb;

		$table = $this->get_clubs_table();
		$like  = '%' . $wpdb->esc_like( $term ) . '%';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, nom FROM {$table} WHERE nom LIKE %s ORDER BY nom ASC LIMIT 20",
				$like
			)
		);
	}

	private function get_preview() {
		$preview = get_option( self::SESSION_KEY, array() );
		return is_array( $preview ) ? $preview : array();
	}

	private function get_admin_url( $tab = 'import' ) {
		$args = array( 'page' => 'ufsc-lc-import-asptt' );
		if ( $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function get_error_export_url() {
		return wp_nonce_url(
			add_query_arg(
				array( 'action' => 'ufsc_lc_asptt_export_errors' ),
				admin_url( 'admin-post.php' )
			),
			'ufsc_lc_asptt_export_errors'
		);
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

	private function get_aliases_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_asptt_aliases';
	}

	private function get_documents_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_licence_documents';
	}

	private function get_import_logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_asptt_import_logs';
	}

	private function render_import_logs() {
		$logs = $this->service->get_import_logs( 10 );
		if ( empty( $logs ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Historique des imports', 'ufsc-licence-competition' ); ?></h2>
		<table class="widefat striped ufsc-lc-import-logs">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Utilisateur', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Mode', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Fichier', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'OK', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'KO', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<?php
					$user       = isset( $log['user_id'] ) ? get_userdata( (int) $log['user_id'] ) : null;
					$user_label = $user ? $user->display_name : __( 'N/A', 'ufsc-licence-competition' );
					?>
					<tr>
						<td><?php echo esc_html( $log['created_at'] ); ?></td>
						<td><?php echo esc_html( $user_label ); ?></td>
						<td><?php echo esc_html( $log['mode'] ); ?></td>
						<td><?php echo esc_html( ! empty( $log['file_name'] ) ? $log['file_name'] : '—' ); ?></td>
						<td><?php echo esc_html( (int) $log['success_rows'] ); ?></td>
						<td><?php echo esc_html( (int) $log['error_rows'] ); ?></td>
						<td>
							<?php echo esc_html( $log['status'] ); ?>
							<?php if ( ! empty( $log['error_message'] ) ) : ?>
								<div class="ufsc-lc-import-log-error"><?php echo esc_html( $log['error_message'] ); ?></div>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function get_licences_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_licences';
	}
}
