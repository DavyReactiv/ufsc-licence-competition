<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_ASPTT_Importer {
	const SOURCE = UFSC_LC_ASPTT_Import_Service::SOURCE;

	const STATUS_LINKED          = UFSC_LC_ASPTT_Import_Service::STATUS_LINKED;
	const STATUS_CLUB_NOT_FOUND  = UFSC_LC_ASPTT_Import_Service::STATUS_CLUB_NOT_FOUND;
	const STATUS_NEEDS_REVIEW    = UFSC_LC_ASPTT_Import_Service::STATUS_NEEDS_REVIEW;
	const STATUS_LICENCE_MISSING = UFSC_LC_ASPTT_Import_Service::STATUS_LICENCE_MISSING;
	const STATUS_INVALID_ASPTT_NUMBER = UFSC_LC_ASPTT_Import_Service::STATUS_INVALID_ASPTT_NUMBER;

	const SESSION_KEY = 'ufsc_lc_asptt_preview';

	private $legacy_enabled = false;
	private $service;

	public function __construct( $legacy_enabled = false ) {
		$this->legacy_enabled = (bool) $legacy_enabled;
		$this->service = new UFSC_LC_ASPTT_Import_Service();
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

		dbDelta( $aliases_sql );

	}

	public function register_admin_menu() {
		$hook_suffix = add_submenu_page(
			UFSC_LC_Plugin::PARENT_SLUG,
			__( 'Import ASPTT', 'ufsc-licence-competition' ),
			__( 'Import ASPTT', 'ufsc-licence-competition' ),
			UFSC_LC_Capabilities::IMPORT_CAPABILITY,
			'ufsc-lc-asptt-import',
			array( $this, 'render_admin_page' )
		);
		UFSC_LC_Admin_Assets::register_page( $hook_suffix );
	}

	public function render_admin_page() {
		if ( ! UFSC_LC_Capabilities::user_can_import() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$preview = $this->get_preview();
		$stats   = $preview && isset( $preview['stats'] ) ? $preview['stats'] : array();
		$rows    = $preview && isset( $preview['rows'] ) ? $preview['rows'] : array();
		$errors  = $preview && isset( $preview['errors'] ) ? $preview['errors'] : array();
		$headers = $preview && ! empty( $preview['headers'] ) ? $preview['headers'] : array();
		$mapping = $preview && ! empty( $preview['mapping'] ) ? $preview['mapping'] : array();
		$file_name = $preview && ! empty( $preview['file_name'] ) ? $preview['file_name'] : '';
		$force_club_id = $preview && ! empty( $preview['force_club_id'] ) ? (int) $preview['force_club_id'] : 0;
		$force_club    = $force_club_id ? $this->get_club_by_id( $force_club_id ) : null;

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import ASPTT', 'ufsc-licence-competition' ); ?></h1>
			<?php if ( ! empty( $preview['notice'] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $preview['notice']['type'] ); ?>">
					<p><?php echo esc_html( $preview['notice']['message'] ); ?></p>
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
				<?php $this->render_stats( $stats, $errors ); ?>
				<?php if ( ! empty( $headers ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-lc-mapping-form">
						<?php wp_nonce_field( 'ufsc_lc_asptt_upload', 'ufsc_lc_asptt_nonce' ); ?>
						<input type="hidden" name="action" value="ufsc_lc_asptt_upload">
						<input type="hidden" name="ufsc_lc_reprocess" value="1">
						<?php if ( $force_club_id ) : ?>
							<input type="hidden" name="ufsc_asptt_force_club" value="<?php echo esc_attr( $force_club_id ); ?>">
						<?php endif; ?>
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
												<?php foreach ( $this->get_mapping_options() as $option ) : ?>
													<option value="<?php echo esc_attr( $option ); ?>" <?php selected( isset( $mapping[ $header ] ) ? $mapping[ $header ] : '', $option ); ?>>
														<?php echo esc_html( $option ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php submit_button( __( 'Recalculer la prévisualisation', 'ufsc-licence-competition' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
				<?php $this->render_preview_table( $rows ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ufsc_lc_asptt_import', 'ufsc_lc_asptt_import_nonce' ); ?>
					<input type="hidden" name="action" value="ufsc_lc_asptt_import">
					<label>
						<input type="radio" name="ufsc_asptt_mode" value="dry_run" checked>
						<?php esc_html_e( 'Simulation (dry-run)', 'ufsc-licence-competition' ); ?>
					</label>
					&nbsp;
					<label>
						<input type="radio" name="ufsc_asptt_mode" value="import">
						<?php esc_html_e( 'Importer', 'ufsc-licence-competition' ); ?>
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
		</div>
		<?php
	}

	private function render_stats( $stats, $errors ) {
		?>
		<h2><?php esc_html_e( 'Rapport', 'ufsc-licence-competition' ); ?></h2>
		<ul>
			<li><?php echo esc_html( sprintf( __( 'Total lignes: %d', 'ufsc-licence-competition' ), $stats['total'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Clubs associés: %d', 'ufsc-licence-competition' ), $stats['clubs_linked'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Licences matchées: %d', 'ufsc-licence-competition' ), $stats['licences_linked'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'club_not_found: %d', 'ufsc-licence-competition' ), $stats['club_not_found'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'needs_review: %d', 'ufsc-licence-competition' ), $stats['needs_review'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'licence_not_found: %d', 'ufsc-licence-competition' ), $stats['licence_not_found'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'invalid_asptt_number: %d', 'ufsc-licence-competition' ), $stats['invalid_asptt_number'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'invalid_season: %d', 'ufsc-licence-competition' ), $stats['invalid_season'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'invalid_birthdate: %d', 'ufsc-licence-competition' ), $stats['invalid_birthdate'] ) ); ?></li>
		</ul>
		<?php
	}

	private function render_preview_table( $rows ) {
		$rows = array_slice( $rows, 0, 50 );
		?>
		<h2><?php esc_html_e( 'Prévisualisation', 'ufsc-licence-competition' ); ?></h2>
		<div class="ufsc-lc-filter-row">
			<label>
				<input type="checkbox" id="ufsc-asptt-errors-only">
				<?php esc_html_e( 'Afficher seulement erreurs', 'ufsc-licence-competition' ); ?>
			</label>
			<input type="search" id="ufsc-asptt-search" placeholder="<?php esc_attr_e( 'Rechercher nom/prénom/club/n°', 'ufsc-licence-competition' ); ?>" style="min-width:280px;">
		</div>
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
					<th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Action', 'ufsc-licence-competition' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $index => $row ) : ?>
					<?php
					$search_value = strtolower( trim( $row['nom'] . ' ' . $row['prenom'] . ' ' . $row['note'] . ' ' . $row['asptt_number'] ) );
					?>
					<tr data-has-error="<?php echo esc_attr( $row['has_error'] ? '1' : '0' ); ?>" data-search="<?php echo esc_attr( $search_value ); ?>" data-status="<?php echo esc_attr( $row['status'] ); ?>">
						<td><?php echo esc_html( $row['nom'] ); ?></td>
						<td><?php echo esc_html( $row['prenom'] ); ?></td>
						<td><?php echo esc_html( $row['date_naissance'] ); ?></td>
						<td><?php echo esc_html( $row['season_end_year'] ? $row['season_end_year'] : '-' ); ?></td>
						<td><?php echo esc_html( $row['category'] ? $row['category'] : '-' ); ?></td>
						<td><?php echo esc_html( $row['age_ref'] ? $row['age_ref'] : '-' ); ?></td>
						<td><?php echo esc_html( $row['note'] ); ?></td>
						<td><?php echo esc_html( $row['licence_id'] ? $row['licence_id'] : '-' ); ?></td>
						<td><?php echo esc_html( $row['asptt_number'] ); ?></td>
						<td><?php echo esc_html( $row['source_created_at'] ? $row['source_created_at'] : '-' ); ?></td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
						<td>
							<?php if ( self::STATUS_NEEDS_REVIEW === $row['status'] && ! empty( $row['club_suggestions'] ) ) : ?>
								<select class="ufsc-club-select" data-row-index="<?php echo esc_attr( $index ); ?>">
									<option value=""><?php esc_html_e( 'Sélectionner un club', 'ufsc-licence-competition' ); ?></option>
									<?php foreach ( $row['club_suggestions'] as $suggestion ) : ?>
										<option value="<?php echo esc_attr( $suggestion['id'] ); ?>">
											<?php echo esc_html( $suggestion['name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<button type="button" class="button ufsc-save-alias" data-row-index="<?php echo esc_attr( $index ); ?>">
									<?php esc_html_e( 'Valider association', 'ufsc-licence-competition' ); ?>
								</button>
								<span class="ufsc-alias-feedback" data-row-index="<?php echo esc_attr( $index ); ?>"></span>
							<?php elseif ( self::STATUS_CLUB_NOT_FOUND === $row['status'] ) : ?>
								<input type="text" class="ufsc-club-search" data-row-index="<?php echo esc_attr( $index ); ?>" placeholder="<?php esc_attr_e( 'Rechercher un club', 'ufsc-licence-competition' ); ?>">
								<select class="ufsc-club-select" data-row-index="<?php echo esc_attr( $index ); ?>">
									<option value=""><?php esc_html_e( 'Sélectionner un club', 'ufsc-licence-competition' ); ?></option>
								</select>
								<button type="button" class="button ufsc-save-alias" data-row-index="<?php echo esc_attr( $index ); ?>">
									<?php esc_html_e( 'Valider association', 'ufsc-licence-competition' ); ?>
								</button>
								<span class="ufsc-alias-feedback" data-row-index="<?php echo esc_attr( $index ); ?>"></span>
							<?php else : ?>
								<span>-</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function get_mapping_options() {
		return array(
			'Nom',
			'Prenom',
			'Date de naissance',
			'Saison (année de fin)',
			'N° Licence',
			'Date de création de la licence',
			'Note',
			'genre',
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

		$preview = array();
		if ( ! empty( $_FILES['ufsc_asptt_csv']['tmp_name'] ) ) {
			$stored = $this->service->store_upload( $_FILES['ufsc_asptt_csv'] );
			if ( is_wp_error( $stored ) ) {
				$preview['notice'] = array(
					'type'    => 'error',
					'message' => $stored->get_error_message(),
				);
			} else {
				$preview = $this->service->build_preview( $stored['path'], $force_club_id, $mapping );
				$preview['file_path'] = $stored['path'];
				$preview['file_name'] = $stored['name'];
			}
		} else {
			$existing = $this->get_preview();
			if ( ! empty( $existing['file_path'] ) && isset( $_POST['ufsc_lc_reprocess'] ) ) {
				$preview = $this->service->build_preview( $existing['file_path'], $force_club_id, $mapping );
				$preview['file_path'] = $existing['file_path'];
				$preview['file_name'] = isset( $existing['file_name'] ) ? $existing['file_name'] : '';
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
			$preview['force_club_id'] = $force_club_id;
			$preview['mode'] = $mode;
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

		$mode = isset( $_POST['ufsc_asptt_mode'] ) ? sanitize_key( wp_unslash( $_POST['ufsc_asptt_mode'] ) ) : 'dry_run';
		if ( 'import' !== $mode ) {
			$preview['notice'] = array(
				'type'    => 'info',
				'message' => __( 'Simulation terminée (aucune donnée importée).', 'ufsc-licence-competition' ),
			);
			update_option( self::SESSION_KEY, $preview, false );
			wp_safe_redirect( $this->get_admin_url() );
			exit;
		}

		$result = $this->service->import_from_file( $preview['file_path'], $preview['force_club_id'], isset( $preview['mapping'] ) ? $preview['mapping'] : array() );
		if ( is_wp_error( $result ) ) {
			$preview['notice'] = array(
				'type'    => 'error',
				'message' => $result->get_error_message(),
			);
			update_option( self::SESSION_KEY, $preview, false );
			wp_safe_redirect( $this->get_admin_url() );
			exit;
		}

		delete_option( self::SESSION_KEY );

		$notice = sprintf(
			/* translators: %d: rows imported */
			__( 'Import terminé. %d enregistrements traités.', 'ufsc-licence-competition' ),
			count( $result['inserted'] )
		);
		update_option( self::SESSION_KEY, array( 'notice' => array( 'type' => 'success', 'message' => $notice ) ), false );
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

		$updated = $this->service->build_preview( $preview['file_path'], $preview['force_club_id'], isset( $preview['mapping'] ) ? $preview['mapping'] : array() );
		if ( is_wp_error( $updated ) ) {
			wp_send_json_error( array( 'message' => $updated->get_error_message() ) );
		}

		$updated['file_path'] = $preview['file_path'];
		$updated['file_name'] = isset( $preview['file_name'] ) ? $preview['file_name'] : '';
		$updated['force_club_id'] = $preview['force_club_id'];
		$updated['mapping'] = isset( $preview['mapping'] ) ? $preview['mapping'] : array();
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

	private function build_preview( $csv_path, $force_club_id ) {
		$rows = $this->read_csv( $csv_path );

		$preview = $this->build_preview_from_rows( $rows, $force_club_id );
		$preview['raw_rows']     = $rows;
		$preview['force_club_id'] = $force_club_id;

		return $preview;
	}

	private function build_preview_from_rows( $rows, $force_club_id ) {
		$preview_rows = array();
		$errors       = array();
		$stats        = array(
			'total'          => 0,
			'clubs_linked'   => 0,
			'licences_linked'=> 0,
			'club_not_found' => 0,
			'needs_review'   => 0,
			'licence_not_found' => 0,
			'invalid_asptt_number' => 0,
			'invalid_season' => 0,
			'invalid_birthdate' => 0,
		);

		foreach ( $rows as $row ) {
			$stats['total']++;
			$note     = isset( $row['Note'] ) ? sanitize_text_field( $row['Note'] ) : '';
			$nom      = isset( $row['Nom'] ) ? sanitize_text_field( $row['Nom'] ) : '';
			$prenom   = isset( $row['Prenom'] ) ? sanitize_text_field( $row['Prenom'] ) : '';
			$dob      = isset( $row['Date de naissance'] ) ? sanitize_text_field( $row['Date de naissance'] ) : '';
			$asptt_no = isset( $row['N° Licence'] ) ? sanitize_text_field( $row['N° Licence'] ) : '';
			$asptt_no = trim( $asptt_no );
			$genre    = isset( $row['genre'] ) ? sanitize_text_field( $row['genre'] ) : '';
			$raw_created_at = isset( $row['Date de création de la licence'] ) ? sanitize_text_field( $row['Date de création de la licence'] ) : '';
			$source_created_at = $this->parse_source_created_at( $raw_created_at );
			$row_errors = array();
			$club_suggestions = array();

			$licence_id = 0;
			$status     = self::STATUS_CLUB_NOT_FOUND;
			if ( '' === $asptt_no ) {
				$status = self::STATUS_INVALID_ASPTT_NUMBER;
				$stats['invalid_asptt_number']++;
				$row_errors[] = __( 'N° Licence ASPTT manquant.', 'ufsc-licence-competition' );
				$this->log_import_warning(
					'N° Licence ASPTT manquant.',
					array(
						'nom'           => $nom,
						'prenom'        => $prenom,
						'date_naissance'=> $dob,
						'note'          => $note,
					)
				);
				$resolved = array(
					'status'  => self::STATUS_INVALID_ASPTT_NUMBER,
					'club_id' => 0,
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
				$this->log_import_warning( 'Date de création de la licence invalide.', array( 'value' => $raw_created_at ) );
			}

			$preview_rows[] = array(
				'nom'           => $nom,
				'prenom'        => $prenom,
				'date_naissance'=> $dob,
				'note'          => $note,
				'asptt_number'  => $asptt_no,
				'source_created_at' => $source_created_at,
				'source_created_at_raw' => $raw_created_at,
				'club_id'       => $resolved['club_id'],
				'club_suggestions' => $club_suggestions,
				'status'        => $status,
				'licence_id'    => $licence_id,
				'attachment_id' => 0,
				'has_error'     => ! empty( $row_errors ),
			);

			if ( ! empty( $row_errors ) ) {
				$errors[] = array(
					'nom'           => $nom,
					'prenom'        => $prenom,
					'date_naissance'=> $dob,
					'note'          => $note,
					'asptt_number'  => $asptt_no,
					'source_created_at' => ( null !== $source_created_at ) ? $source_created_at : $raw_created_at,
					'source_created_at_raw' => $raw_created_at,
					'status'        => $status,
					'error'         => implode( ' | ', $row_errors ),
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
			$mapped[] = $key;
		}

		return $mapped;
	}

	private function map_header( $header ) {
		$normalized = $this->normalize_header( $header );
		$mapping = array(
			'nom' => 'Nom',
			'prenom' => 'Prenom',
			'genre' => 'genre',
			'sexe' => 'genre',
			'date de naissance' => 'Date de naissance',
			'date naissance' => 'Date de naissance',
			'date of birth' => 'Date de naissance',
			'birth date' => 'Date de naissance',
			'n licence' => 'N° Licence',
			'no licence' => 'N° Licence',
			'numero licence' => 'N° Licence',
			'numero de licence' => 'N° Licence',
			'num licence' => 'N° Licence',
			'licence number' => 'N° Licence',
			'license number' => 'N° Licence',
			'date de creation de la licence' => 'Date de création de la licence',
			'date d creation de la licence' => 'Date de création de la licence',
			'date creation de la licence' => 'Date de création de la licence',
			'date creation licence' => 'Date de création de la licence',
			'date de creation licence' => 'Date de création de la licence',
			'date creation de licence' => 'Date de création de la licence',
			'date creation' => 'Date de création de la licence',
			'note' => 'Note',
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
				'status'  => self::STATUS_LINKED,
				'club_id' => $force_club_id,
				'suggestions' => array(),
			);
		}

		if ( '' === $note ) {
			return array(
				'status'  => self::STATUS_CLUB_NOT_FOUND,
				'club_id' => 0,
				'suggestions' => array(),
			);
		}

		$normalized = $this->normalize_name( $note );

		$club = $this->find_club_by_name( $normalized );
		if ( $club ) {
			return array(
				'status'  => self::STATUS_LINKED,
				'club_id' => (int) $club->id,
				'suggestions' => array(),
			);
		}

		$alias = $this->find_alias( $normalized );
		if ( $alias ) {
			return array(
				'status'  => self::STATUS_LINKED,
				'club_id' => (int) $alias->club_id,
				'suggestions' => array(),
			);
		}

		$suggestions = $this->find_club_suggestions( $normalized );
		if ( count( $suggestions ) === 1 ) {
			return array(
				'status'  => self::STATUS_LINKED,
				'club_id' => (int) $suggestions[0]->id,
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
				'status'  => self::STATUS_NEEDS_REVIEW,
				'club_id' => 0,
				'suggestions' => $suggestion_rows,
			);
		}

		return array(
			'status'  => self::STATUS_CLUB_NOT_FOUND,
			'club_id' => 0,
			'suggestions' => array(),
		);
	}

	private function find_licence_id( $club_id, $nom, $prenom, $dob, $genre ) {
		global $wpdb;

		$table = $this->get_licences_table();

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
			'licence_id'            => $licence_id,
			'source'                => self::SOURCE,
			'source_licence_number' => $source_licence_number,
			'attachment_id'         => $attachment_id ? $attachment_id : null,
			'asptt_club_note'        => $note,
			'updated_at'             => current_time( 'mysql' ),
		);

		$field_formats = array(
			'licence_id'            => '%d',
			'source'                => '%s',
			'source_licence_number' => '%s',
			'attachment_id'         => '%d',
			'asptt_club_note'       => '%s',
			'updated_at'            => '%s',
			'source_created_at'     => '%s',
			'imported_at'           => '%s',
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

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE alias_normalized = %s", $normalized ) );
		if ( $exists ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'club_id'         => $club_id,
				'alias'           => $alias,
				'alias_normalized'=> $normalized,
				'created_at'      => current_time( 'mysql' ),
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

	private function log_import_warning( $message, $context = array() ) {
		UFSC_LC_Logger::log( $message, $context );
	}

	private function find_club_by_name( $normalized ) {
		global $wpdb;

		$table = $this->get_clubs_table();

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

	private function get_admin_url() {
		return add_query_arg(
			array(
				'page' => 'ufsc-lc-asptt-import',
			),
			admin_url( 'admin.php' )
		);
	}

	private function get_error_export_url() {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'ufsc_lc_asptt_export_errors',
				),
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

	private function get_licences_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_licences';
	}
}
