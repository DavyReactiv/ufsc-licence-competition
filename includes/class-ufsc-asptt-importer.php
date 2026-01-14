<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_ASPTT_Importer {
	const SOURCE = 'ASPTT';

	const STATUS_LINKED          = 'linked';
	const STATUS_CLUB_NOT_FOUND  = 'club_not_found';
	const STATUS_NEEDS_REVIEW    = 'needs_review';
	const STATUS_LICENCE_MISSING = 'licence_not_found';
	const STATUS_INVALID_ASPTT_NUMBER = 'invalid_asptt_number';

	const SESSION_KEY = 'ufsc_lc_asptt_preview';

	private $legacy_enabled = false;

	public function __construct( $legacy_enabled = false ) {
		$this->legacy_enabled = (bool) $legacy_enabled;
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
		add_submenu_page(
			'ufsc-licence-documents',
			__( 'Import ASPTT', 'ufsc-licence-competition' ),
			__( 'Import ASPTT', 'ufsc-licence-competition' ),
			'manage_options',
			'ufsc-asptt-import',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$preview = $this->get_preview();
		$stats   = $preview ? $preview['stats'] : array();
		$rows    = $preview ? $preview['rows'] : array();
		$errors  = $preview ? $preview['errors'] : array();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import ASPTT', 'ufsc-licence-competition' ); ?></h1>

			<?php if ( empty( $rows ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'ufsc_lc_asptt_upload', 'ufsc_lc_asptt_nonce' ); ?>
					<input type="hidden" name="action" value="ufsc_lc_asptt_upload">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ufsc_asptt_csv"><?php esc_html_e( 'CSV ASPTT', 'ufsc-licence-competition' ); ?></label></th>
							<td><input type="file" name="ufsc_asptt_csv" id="ufsc_asptt_csv" accept=".csv,text/csv" required></td>
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
				<?php $this->render_stats( $stats, $errors ); ?>
				<?php $this->render_preview_table( $rows ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ufsc_lc_asptt_import', 'ufsc_lc_asptt_import_nonce' ); ?>
					<input type="hidden" name="action" value="ufsc_lc_asptt_import">
					<?php submit_button( __( 'Importer', 'ufsc-licence-competition' ), 'primary' ); ?>
				</form>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url( $this->get_error_export_url() ); ?>">
						<?php esc_html_e( 'Exporter les erreurs CSV', 'ufsc-licence-competition' ); ?>
					</a>
				</p>
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
		</ul>
		<?php if ( ! empty( $errors ) ) : ?>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $this->get_error_export_url() ); ?>">
					<?php esc_html_e( 'Exporter les erreurs CSV', 'ufsc-licence-competition' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	private function render_preview_table( $rows ) {
		?>
		<h2><?php esc_html_e( 'Prévisualisation', 'ufsc-licence-competition' ); ?></h2>
		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Date de naissance', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Club (Note)', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Licence UFSC', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'N° ASPTT', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Action', 'ufsc-licence-competition' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $index => $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['nom'] ); ?></td>
						<td><?php echo esc_html( $row['prenom'] ); ?></td>
						<td><?php echo esc_html( $row['date_naissance'] ); ?></td>
						<td><?php echo esc_html( $row['note'] ); ?></td>
						<td><?php echo esc_html( $row['licence_id'] ? $row['licence_id'] : '-' ); ?></td>
						<td><?php echo esc_html( $row['asptt_number'] ); ?></td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
						<td>
							<?php if ( in_array( $row['status'], array( self::STATUS_CLUB_NOT_FOUND, self::STATUS_NEEDS_REVIEW ), true ) ) : ?>
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
		<script>
			(function() {
				function fetchClubs(term, select) {
					if (!term) {
						select.innerHTML = '<option value=""><?php echo esc_js( __( 'Sélectionner un club', 'ufsc-licence-competition' ) ); ?></option>';
						return;
					}
					fetch(ajaxurl + '?action=ufsc_lc_club_search&term=' + encodeURIComponent(term))
						.then(function(response) { return response.json(); })
						.then(function(response) {
							if (!response.success) {
								return;
							}
							var options = '<option value=""><?php echo esc_js( __( 'Sélectionner un club', 'ufsc-licence-competition' ) ); ?></option>';
							response.data.forEach(function(item) {
								options += '<option value=\"' + item.id + '\">' + item.text + '</option>';
							});
							select.innerHTML = options;
						});
				}

				function sendAlias(rowIndex, clubId) {
					var feedback = document.querySelector('.ufsc-alias-feedback[data-row-index=\"' + rowIndex + '\"]');
					feedback.textContent = '<?php echo esc_js( __( 'Enregistrement...', 'ufsc-licence-competition' ) ); ?>';
					var data = new window.FormData();
					data.append('action', 'ufsc_lc_asptt_save_alias');
					data.append('row_index', rowIndex);
					data.append('club_id', clubId);
					data.append('_ajax_nonce', '<?php echo esc_js( wp_create_nonce( 'ufsc_lc_asptt_save_alias' ) ); ?>');
					fetch(ajaxurl, { method: 'POST', body: data })
						.then(function(response) { return response.json(); })
						.then(function(response) {
							if (response.success) {
								feedback.textContent = response.data.message;
								window.location.reload();
							} else {
								feedback.textContent = response.data.message || 'Erreur';
							}
						});
				}

				document.querySelectorAll('.ufsc-save-alias').forEach(function(button) {
					button.addEventListener('click', function() {
						var rowIndex = this.getAttribute('data-row-index');
						var select = document.querySelector('.ufsc-club-select[data-row-index=\"' + rowIndex + '\"]');
						var clubId = select ? select.value : '';
						if (!clubId) {
							alert('<?php echo esc_js( __( 'Veuillez sélectionner un club.', 'ufsc-licence-competition' ) ); ?>');
							return;
						}
						sendAlias(rowIndex, clubId);
					});
				});

				document.querySelectorAll('.ufsc-club-search').forEach(function(input) {
					var rowIndex = input.getAttribute('data-row-index');
					var select = document.querySelector('.ufsc-club-select[data-row-index=\"' + rowIndex + '\"]');
					input.addEventListener('input', function() {
						fetchClubs(input.value, select);
					});
				});
			})();
		</script>
		<?php
	}

	public function handle_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		check_admin_referer( 'ufsc_lc_asptt_upload', 'ufsc_lc_asptt_nonce' );

		if ( empty( $_FILES['ufsc_asptt_csv']['tmp_name'] ) ) {
			wp_safe_redirect( $this->get_admin_url() );
			exit;
		}

		$force_club_id = isset( $_POST['ufsc_asptt_force_club'] ) ? absint( $_POST['ufsc_asptt_force_club'] ) : 0;
		$preview       = $this->build_preview( $_FILES['ufsc_asptt_csv']['tmp_name'], $force_club_id );

		update_option( self::SESSION_KEY, $preview, false );

		wp_safe_redirect( $this->get_admin_url() );
		exit;
	}

	public function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		check_admin_referer( 'ufsc_lc_asptt_import', 'ufsc_lc_asptt_import_nonce' );

		$preview = $this->get_preview();
		if ( empty( $preview['rows'] ) ) {
			wp_safe_redirect( $this->get_admin_url() );
			exit;
		}

		foreach ( $preview['rows'] as $row ) {
			if ( ! empty( $preview['force_club_id'] ) && '' !== $row['note'] ) {
				$this->save_alias( (int) $preview['force_club_id'], $row['note'] );
			}

			if ( self::STATUS_LINKED !== $row['status'] || '' === $row['asptt_number'] ) {
				continue;
			}

			$this->upsert_document(
				(int) $row['licence_id'],
				$row['asptt_number'],
				$row['attachment_id'],
				$row['note'],
				$row['source_created_at']
			);
		}

		delete_option( self::SESSION_KEY );

		wp_safe_redirect( $this->get_admin_url() );
		exit;
	}

	public function ajax_search_clubs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ) );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		if ( '' === $term ) {
			wp_send_json_success( array() );
		}

		$clubs = $this->search_clubs( $term );
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
		if ( ! current_user_can( 'manage_options' ) ) {
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

		$this->save_alias( $club_id, $row['note'] );

		$preview = $this->build_preview_from_rows( $preview['raw_rows'], $preview['force_club_id'] );
		update_option( self::SESSION_KEY, $preview, false );

		wp_send_json_success( array( 'message' => __( 'Alias enregistré.', 'ufsc-licence-competition' ) ) );
	}

	public function handle_export_errors() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		check_admin_referer( 'ufsc_lc_asptt_export_errors' );

		$preview = $this->get_preview();
		if ( empty( $preview['errors'] ) ) {
			wp_die( esc_html__( 'Aucune erreur à exporter.', 'ufsc-licence-competition' ) );
		}

		$filename = 'asptt-errors-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'Nom', 'Prenom', 'Date de naissance', 'Note', 'N° Licence', 'Date de création de la licence', 'Statut', 'Erreur' ), ';' );

		foreach ( $preview['errors'] as $error ) {
			fputcsv(
				$output,
				array(
					$error['nom'],
					$error['prenom'],
					$error['date_naissance'],
					$error['note'],
					$error['asptt_number'],
					$error['source_created_at'],
					$error['status'],
					$error['error'],
				),
				';'
			);
		}

		fclose( $output );
		exit;
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
				);
			} else {
				$resolved = $this->resolve_club( $note, $force_club_id );
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
				'club_id'       => $resolved['club_id'],
				'status'        => $status,
				'licence_id'    => $licence_id,
				'attachment_id' => 0,
			);

			if ( ! empty( $row_errors ) ) {
				$errors[] = array(
					'nom'           => $nom,
					'prenom'        => $prenom,
					'date_naissance'=> $dob,
					'note'          => $note,
					'asptt_number'  => $asptt_no,
					'source_created_at' => ( null !== $source_created_at ) ? $source_created_at : $raw_created_at,
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
				$header = $data;
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

	private function resolve_club( $note, $force_club_id ) {
		if ( $force_club_id ) {
			return array(
				'status'  => self::STATUS_LINKED,
				'club_id' => $force_club_id,
			);
		}

		if ( '' === $note ) {
			return array(
				'status'  => self::STATUS_CLUB_NOT_FOUND,
				'club_id' => 0,
			);
		}

		$normalized = $this->normalize_name( $note );

		$club = $this->find_club_by_name( $normalized );
		if ( $club ) {
			return array(
				'status'  => self::STATUS_LINKED,
				'club_id' => (int) $club->id,
			);
		}

		$alias = $this->find_alias( $normalized );
		if ( $alias ) {
			return array(
				'status'  => self::STATUS_LINKED,
				'club_id' => (int) $alias->club_id,
			);
		}

		$suggestions = $this->find_club_suggestions( $normalized );
		if ( count( $suggestions ) === 1 ) {
			return array(
				'status'  => self::STATUS_LINKED,
				'club_id' => (int) $suggestions[0]->id,
			);
		}

		if ( count( $suggestions ) > 1 ) {
			return array(
				'status'  => self::STATUS_NEEDS_REVIEW,
				'club_id' => 0,
			);
		}

		return array(
			'status'  => self::STATUS_CLUB_NOT_FOUND,
			'club_id' => 0,
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
				'page' => 'ufsc-asptt-import',
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
