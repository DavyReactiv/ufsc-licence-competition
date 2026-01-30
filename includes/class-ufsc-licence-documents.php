<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Licence_Documents {
	const SOURCE = 'UFSC';

	private $legacy_enabled = false;

	public function __construct( $legacy_enabled = false ) {
		$this->legacy_enabled = (bool) $legacy_enabled;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_ufsc_lc_upload_licence_pdf', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_ufsc_lc_download_licence_pdf', array( $this, 'handle_download' ) );

		if ( $this->legacy_enabled ) {
			add_action( 'admin_post_ufsc_upload_licence_pdf', array( $this, 'handle_upload' ) );
			add_action( 'admin_post_ufsc_download_licence_pdf', array( $this, 'handle_download' ) );
		}
	}

	public function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $this->get_documents_table();
		$meta_table_name = $this->get_documents_meta_table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			licence_id bigint(20) unsigned NOT NULL,
			source varchar(50) NOT NULL,
			source_licence_number varchar(100) NOT NULL,
			attachment_id bigint(20) unsigned NULL,
			asptt_club_note varchar(255) NULL,
			source_created_at datetime NULL,
			imported_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_source_number (source, source_licence_number),
			KEY idx_licence_source (licence_id, source),
			KEY licence_id (licence_id),
			KEY idx_source_created_at (source_created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		$meta_sql = "CREATE TABLE {$meta_table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			licence_id bigint(20) unsigned NOT NULL,
			source varchar(50) NOT NULL,
			meta_key varchar(190) NOT NULL,
			meta_value longtext NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_licence_meta (licence_id, source, meta_key),
			KEY idx_meta_key (meta_key),
			KEY idx_licence_source (licence_id, source)
		) {$charset_collate};";

		dbDelta( $meta_sql );

	}

	public function register_admin_menu() {
		$hook_suffix = add_menu_page(
			__( 'UFSC Licences', 'ufsc-licence-competition' ),
			__( 'UFSC Licences', 'ufsc-licence-competition' ),
			UFSC_LC_Capabilities::get_manage_capability(),
			UFSC_LC_Plugin::PARENT_SLUG,
			array( $this, 'render_admin_page' ),
			'dashicons-media-document',
			30
		);
		UFSC_LC_Admin_Assets::register_page( $hook_suffix );
	}

	public function render_admin_page() {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$status = isset( $_GET['ufsc_status'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_status'] ) ) : '';
		$message = isset( $_GET['ufsc_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_message'] ) ) : '';
		$search  = $this->get_search_filters();

		$search_results = array();
		$search_notice  = array();

		if ( $search['submitted'] ) {
			if ( '' === $search['nom'] || '' === $search['prenom'] || '' === $search['date_naissance'] ) {
				$search_notice = array(
					'type'    => 'error',
					'message' => __( 'Veuillez renseigner le nom, le prénom et la date de naissance.', 'ufsc-licence-competition' ),
				);
			} elseif ( ! $search['search_without_club'] && ! $search['club_id'] ) {
				$search_notice = array(
					'type'    => 'error',
					'message' => __( 'Veuillez sélectionner un club ou activer la recherche élargie.', 'ufsc-licence-competition' ),
				);
			} else {
				$search_results = $this->find_licences( $search );
				if ( empty( $search_results ) ) {
					$search_notice = array(
						'type'    => 'warning',
						'message' => __( 'Aucune licence trouvée. Vérifiez l’orthographe, le club et la saison, ou essayez la recherche élargie.', 'ufsc-licence-competition' ),
					);
				}
			}
		}

		if ( $status && $message ) {
			$notice_classes = array(
				'success' => 'notice notice-success is-dismissible',
				'warning' => 'notice notice-warning is-dismissible',
				'error'   => 'notice notice-error is-dismissible',
			);
			$class = isset( $notice_classes[ $status ] ) ? $notice_classes[ $status ] : 'notice notice-info is-dismissible';
			echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
		}

		?>
		<div class="wrap ufsc-lc-documents">
			<h1><?php esc_html_e( 'Associer un PDF de licence', 'ufsc-licence-competition' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Recherchez une licence par identité, club et saison pour éviter les erreurs, puis associez le PDF nominatif.', 'ufsc-licence-competition' ); ?></p>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ufsc-lc-document-search">
				<input type="hidden" name="page" value="ufsc-licence-documents">
				<input type="hidden" name="ufsc_search" value="1">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_nom"><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="ufsc_nom" type="text" id="ufsc_nom" class="regular-text" value="<?php echo esc_attr( $search['nom'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_prenom"><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="ufsc_prenom" type="text" id="ufsc_prenom" class="regular-text" value="<?php echo esc_attr( $search['prenom'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_date_naissance"><?php esc_html_e( 'Date de naissance', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="ufsc_date_naissance" type="date" id="ufsc_date_naissance" class="regular-text" value="<?php echo esc_attr( $search['date_naissance'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_club_search"><?php esc_html_e( 'Club', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<input type="text" id="ufsc_club_search" class="regular-text ufsc-club-search" data-row-index="0" value="<?php echo esc_attr( $search['club_search'] ); ?>" placeholder="<?php esc_attr_e( 'Rechercher un club…', 'ufsc-licence-competition' ); ?>">
							<select id="ufsc_club_id" name="ufsc_club_id" class="regular-text ufsc-club-select" data-row-index="0">
								<option value=""><?php esc_html_e( 'Sélectionner un club', 'ufsc-licence-competition' ); ?></option>
								<?php if ( $search['club_id'] ) : ?>
									<?php $club = $this->get_club_by_id( $search['club_id'] ); ?>
									<?php if ( $club ) : ?>
										<option value="<?php echo esc_attr( $club->id ); ?>" selected><?php echo esc_html( $club->nom ); ?></option>
									<?php endif; ?>
								<?php endif; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Tapez au moins 2 caractères pour filtrer les clubs.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_season"><?php echo esc_html( $search['season_label'] ); ?></label></th>
						<td><input name="ufsc_season" type="text" id="ufsc_season" class="regular-text" value="<?php echo esc_attr( $search['season'] ); ?>" placeholder="<?php esc_attr_e( 'Optionnel', 'ufsc-licence-competition' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Recherche élargie', 'ufsc-licence-competition' ); ?></th>
						<td>
							<label for="ufsc_search_without_club">
								<input type="checkbox" name="ufsc_search_without_club" id="ufsc_search_without_club" value="1" <?php checked( $search['search_without_club'] ); ?>>
								<?php esc_html_e( 'Ignorer le club (attention aux homonymes)', 'ufsc-licence-competition' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Rechercher les licences', 'ufsc-licence-competition' ), 'secondary' ); ?>
			</form>

			<?php if ( ! empty( $search_notice ) && isset( $search_notice['type'], $search_notice['message'] ) ) : ?>
				<?php $class = 'notice notice-' . esc_attr( $search_notice['type'] ) . ' is-dismissible'; ?>
				<div class="<?php echo esc_attr( $class ); ?>"><p><?php echo esc_html( $search_notice['message'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $search['submitted'] && ! empty( $search_results ) ) : ?>
				<?php if ( $search['search_without_club'] ) : ?>
					<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Recherche élargie sans club : vérifiez attentivement la licence avant association.', 'ufsc-licence-competition' ); ?></p></div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="ufsc-lc-document-associate">
					<?php wp_nonce_field( 'ufsc_lc_upload_licence_pdf', 'ufsc_lc_upload_nonce' ); ?>
					<input type="hidden" name="action" value="ufsc_lc_upload_licence_pdf">
					<?php if ( 1 === count( $search_results ) ) : ?>
						<?php $item = $search_results[0]; ?>
						<input type="hidden" name="matched_licence_id" value="<?php echo esc_attr( $item->id ); ?>">
						<div class="ufsc-lc-card">
							<h2><?php esc_html_e( 'Licence trouvée', 'ufsc-licence-competition' ); ?></h2>
							<?php echo wp_kses_post( $this->render_licence_summary( $item, $search['season_label'] ) ); ?>
						</div>
					<?php else : ?>
						<h2><?php esc_html_e( 'Plusieurs licences correspondent', 'ufsc-licence-competition' ); ?></h2>
						<table class="widefat striped ufsc-lc-results-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Sélection', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Licence', 'ufsc-licence-competition' ); ?></th>
									<th><?php esc_html_e( 'Club', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html( $search['season_label'] ); ?></th>
									<th><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $search_results as $item ) : ?>
									<?php
									$nom_affiche = ufsc_lc_get_nom_affiche( $item );
									$birthdate   = ufsc_lc_format_birthdate( $item->date_naissance ?? '' );
									$category    = $item->categorie_affiche ?? ( $item->category_value ?? '' );
									if ( '' === $category && '' !== $birthdate ) {
										$season_end_year = UFSC_LC_Categories::sanitize_season_end_year( $item->season_value ?? '' );
										if ( null === $season_end_year ) {
											$season_end_year = $this->get_default_season_end_year();
										}
										$computed = UFSC_LC_Categories::category_from_birthdate( $birthdate, $season_end_year );
										$category = isset( $computed['category'] ) ? (string) $computed['category'] : '';
									} elseif ( '' === $birthdate ) {
										$category = '';
									}
									?>
									<tr>
										<td><input type="radio" name="matched_licence_id" value="<?php echo esc_attr( $item->id ); ?>" required></td>
										<td><?php echo esc_html( $nom_affiche . ' ' . $item->prenom . ' · ' . ( '' !== $birthdate ? $birthdate : __( '—', 'ufsc-licence-competition' ) ) ); ?></td>
										<td><?php echo esc_html( $item->club_name ? $item->club_name : __( '—', 'ufsc-licence-competition' ) ); ?></td>
										<td><?php echo esc_html( $item->season_value ? $item->season_value : __( '—', 'ufsc-licence-competition' ) ); ?></td>
										<td><?php echo esc_html( $category ? $category : __( '—', 'ufsc-licence-competition' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="licence_pdf"><?php esc_html_e( 'PDF nominatif', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="licence_pdf" type="file" id="licence_pdf" accept="application/pdf" required></td>
						</tr>
					</table>
					<?php submit_button( __( 'Associer le PDF', 'ufsc-licence-competition' ) ); ?>
				</form>
			<?php endif; ?>

			<details class="ufsc-lc-advanced-mode">
				<summary><?php esc_html_e( 'Mode avancé : association directe', 'ufsc-licence-competition' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'ufsc_lc_upload_licence_pdf', 'ufsc_lc_upload_nonce' ); ?>
					<input type="hidden" name="action" value="ufsc_lc_upload_licence_pdf">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="licence_id"><?php esc_html_e( 'Licence ID', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="licence_id" type="number" id="licence_id" class="regular-text" min="1"></td>
						</tr>
						<tr>
							<th scope="row"><label for="numero_licence_delegataire"><?php esc_html_e( 'Numéro licence délégataire (optionnel)', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="numero_licence_delegataire" type="text" id="numero_licence_delegataire" class="regular-text"></td>
						</tr>
						<tr>
							<th scope="row"><label for="licence_pdf_advanced"><?php esc_html_e( 'PDF nominatif', 'ufsc-licence-competition' ); ?></label></th>
							<td><input name="licence_pdf" type="file" id="licence_pdf_advanced" accept="application/pdf" required></td>
						</tr>
					</table>
					<?php submit_button( __( 'Uploader et associer', 'ufsc-licence-competition' ) ); ?>
				</form>
			</details>
		</div>
		<?php
	}

	public function handle_upload() {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['ufsc_lc_upload_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_lc_upload_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ufsc_lc_upload_licence_pdf' ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$matched_licence_id = isset( $_POST['matched_licence_id'] ) ? absint( $_POST['matched_licence_id'] ) : 0;
		$licence_id         = isset( $_POST['licence_id'] ) ? absint( $_POST['licence_id'] ) : 0;
		$numero_licence     = isset( $_POST['numero_licence_delegataire'] ) ? sanitize_text_field( wp_unslash( $_POST['numero_licence_delegataire'] ) ) : '';

		if ( $matched_licence_id ) {
			$licence = $this->get_licence_record( $matched_licence_id, '' );
		} else {
			if ( 0 === $licence_id && '' === $numero_licence ) {
				$this->redirect_with_message( 'error', __( 'Veuillez fournir un identifiant ou un numéro de licence.', 'ufsc-licence-competition' ) );
			}
			$licence = $this->get_licence_record( $licence_id, $numero_licence );
		}

		if ( ! $licence ) {
			$this->redirect_with_message( 'error', __( 'Licence introuvable.', 'ufsc-licence-competition' ) );
		}

		if ( empty( $_FILES['licence_pdf']['name'] ) ) {
			$this->redirect_with_message( 'error', __( 'Aucun fichier PDF fourni.', 'ufsc-licence-competition' ) );
		}

		$check = wp_check_filetype_and_ext( $_FILES['licence_pdf']['tmp_name'], $_FILES['licence_pdf']['name'] );
		if ( empty( $check['ext'] ) || 'pdf' !== strtolower( $check['ext'] ) ) {
			$this->redirect_with_message( 'error', __( 'Le fichier doit être un PDF.', 'ufsc-licence-competition' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'licence_pdf', 0, array(), array( 'test_form' => false ) );

		if ( is_wp_error( $attachment_id ) ) {
			$this->redirect_with_message( 'error', $attachment_id->get_error_message() );
		}

		$source_number = $matched_licence_id ? '' : $numero_licence;
		$this->upsert_document( (int) $licence->id, $source_number, (int) $attachment_id );
		$this->update_document_meta( (int) $licence->id, 'pdf_attachment_id', (int) $attachment_id );
		$this->update_document_meta( (int) $licence->id, 'pdf_assigned_at', current_time( 'mysql' ) );
		$this->update_document_meta( (int) $licence->id, 'pdf_assigned_by', (int) get_current_user_id() );

		$this->redirect_with_message( 'success', __( 'PDF associé avec succès.', 'ufsc-licence-competition' ) );
	}

	public function handle_download() {
		if ( ! is_user_logged_in() ) {
			UFSC_LC_Logger::log( __( 'Téléchargement refusé: utilisateur non connecté.', 'ufsc-licence-competition' ) );
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$licence_id = isset( $_GET['licence_id'] ) ? absint( $_GET['licence_id'] ) : 0;
		$nonce      = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $licence_id || ! wp_verify_nonce( $nonce, 'ufsc_lc_download_licence_pdf_' . $licence_id ) ) {
			UFSC_LC_Logger::log(
				__( 'Téléchargement refusé: nonce invalide.', 'ufsc-licence-competition' ),
				array( 'licence_id' => $licence_id )
			);
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$licence = $this->get_licence_record( $licence_id, '' );
		if ( ! $licence ) {
			UFSC_LC_Logger::log(
				__( 'Téléchargement refusé: licence introuvable.', 'ufsc-licence-competition' ),
				array( 'licence_id' => $licence_id )
			);
			wp_die( esc_html__( 'Licence introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			$club = $this->get_club_by_id( (int) $licence->club_id );
			if ( ! $club || (int) $club->responsable_id !== (int) get_current_user_id() ) {
				UFSC_LC_Logger::log(
					__( 'Téléchargement refusé: utilisateur non autorisé.', 'ufsc-licence-competition' ),
					array(
						'licence_id' => (int) $licence->id,
						'club_id'    => (int) $licence->club_id,
					)
				);
				wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
			}
		}

		$document = $this->get_document_by_licence( $licence_id );
		if ( ! $document || ! $document->attachment_id ) {
			UFSC_LC_Logger::log(
				__( 'Téléchargement refusé: document indisponible.', 'ufsc-licence-competition' ),
				array( 'licence_id' => $licence_id )
			);
			wp_die( esc_html__( 'Document indisponible.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		$file_path = get_attached_file( (int) $document->attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			UFSC_LC_Logger::log(
				__( 'Téléchargement refusé: fichier introuvable.', 'ufsc-licence-competition' ),
				array( 'attachment_id' => (int) $document->attachment_id )
			);
			wp_die( esc_html__( 'Fichier introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		$file_name = basename( $file_path );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );

		readfile( $file_path );
		exit;
	}

	private function get_download_url( $licence_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'ufsc_lc_download_licence_pdf',
					'licence_id' => (int) $licence_id,
				),
				admin_url( 'admin-post.php' )
			),
			'ufsc_lc_download_licence_pdf_' . (int) $licence_id
		);
	}

	private function get_current_user_club_id() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}

		$club_id = get_user_meta( $user_id, 'club_id', true );

		return $club_id ? (int) $club_id : 0;
	}

	private function get_club_by_id( $club_id ) {
		global $wpdb;

		if ( ! $club_id ) {
			return null;
		}

		$table = $this->get_clubs_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, nom, responsable_id FROM {$table} WHERE id = %d",
				$club_id
			)
		);
	}

	private function get_search_filters() {
		$season_label  = __( 'Saison (optionnel)', 'ufsc-licence-competition' );
		$season_column = $this->get_season_column();
		if ( 'season_end_year' === $season_column ) {
			$season_label = __( 'Saison (année de fin)', 'ufsc-licence-competition' );
		} elseif ( '' !== $season_column ) {
			$season_label = __( 'Saison', 'ufsc-licence-competition' );
		}

		return array(
			'submitted'          => isset( $_GET['ufsc_search'] ),
			'nom'                => isset( $_GET['ufsc_nom'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_nom'] ) ) : '',
			'prenom'             => isset( $_GET['ufsc_prenom'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_prenom'] ) ) : '',
			'date_naissance'     => isset( $_GET['ufsc_date_naissance'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_date_naissance'] ) ) : '',
			'club_id'            => isset( $_GET['ufsc_club_id'] ) ? absint( $_GET['ufsc_club_id'] ) : 0,
			'club_search'        => isset( $_GET['ufsc_club_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_club_search'] ) ) : '',
			'season'             => isset( $_GET['ufsc_season'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_season'] ) ) : '',
			'search_without_club'=> isset( $_GET['ufsc_search_without_club'] ),
			'season_label'       => $season_label,
		);
	}

	private function find_licences( array $filters ) {
		global $wpdb;

		$licences_table = $this->get_licences_table();
		$clubs_table    = $this->get_clubs_table();

		$has_nom        = $this->has_column( $licences_table, 'nom' );
		$has_nom_licence = $this->has_column( $licences_table, 'nom_licence' );
		$name_clauses   = array();
		$params         = array();

		if ( $has_nom ) {
			$name_clauses[] = 'l.nom LIKE %s';
			$params[]       = '%' . $wpdb->esc_like( $filters['nom'] ) . '%';
		}
		if ( $has_nom_licence ) {
			$name_clauses[] = 'l.nom_licence LIKE %s';
			$params[]       = '%' . $wpdb->esc_like( $filters['nom'] ) . '%';
		}
		if ( empty( $name_clauses ) ) {
			$name_clauses[] = '1=0';
		}

		$where = array(
			'(' . implode( ' OR ', $name_clauses ) . ')',
			'l.prenom LIKE %s',
			'l.date_naissance = %s',
		);
		$params[] = '%' . $wpdb->esc_like( $filters['prenom'] ) . '%';
		$params[] = $filters['date_naissance'];

		if ( ! $filters['search_without_club'] && $filters['club_id'] ) {
			$where[] = 'l.club_id = %d';
			$params[] = $filters['club_id'];
		}

		$season_column = $this->get_season_column();
		if ( '' !== $filters['season'] && '' !== $season_column ) {
			if ( 'season_end_year' === $season_column ) {
				$season_value = UFSC_LC_Categories::sanitize_season_end_year( $filters['season'] );
				if ( null !== $season_value ) {
					$where[] = 'l.season_end_year = %d';
					$params[] = $season_value;
				}
			} else {
				$where[] = "l.{$season_column} = %s";
				$params[] = $filters['season'];
			}
		}

		$category_column = $this->get_category_column();
		$category_sql    = $category_column ? "l.{$category_column} AS category_value" : "'' AS category_value";

		$category_parts = array();
		if ( $this->has_column( $licences_table, 'categorie' ) ) {
			$category_parts[] = 'NULLIF(l.categorie, \'\')';
		}
		if ( $this->has_column( $licences_table, 'category' ) ) {
			$category_parts[] = 'NULLIF(l.category, \'\')';
		}
		if ( $this->has_column( $licences_table, 'legacy_category' ) ) {
			$category_parts[] = 'NULLIF(l.legacy_category, \'\')';
		}
		$categorie_affiche_sql = empty( $category_parts ) ? "''" : 'COALESCE(' . implode( ', ', $category_parts ) . ')';

		$season_sql = "'' AS season_value";
		if ( $season_column ) {
			$season_sql = "l.{$season_column} AS season_value";
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$nom_parts = array();
		if ( $has_nom ) {
			$nom_parts[] = 'NULLIF(l.nom, \'\')';
		}
		if ( $has_nom_licence ) {
			$nom_parts[] = 'NULLIF(l.nom_licence, \'\')';
		}
		$nom_affiche_sql = empty( $nom_parts ) ? "''" : 'COALESCE(' . implode( ', ', $nom_parts ) . ')';

		$sql = "SELECT l.id, {$nom_affiche_sql} AS nom_affiche, l.prenom, l.date_naissance, l.statut, l.competition, {$category_sql}, {$categorie_affiche_sql} AS categorie_affiche, {$season_sql},
			l.club_id, c.nom AS club_name
			FROM {$licences_table} l
			LEFT JOIN {$clubs_table} c ON c.id = l.club_id
			{$where_sql}
			ORDER BY {$nom_affiche_sql} ASC, l.prenom ASC, l.id ASC";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	private function render_licence_summary( $item, $season_label ) {
		$nom_affiche = ufsc_lc_get_nom_affiche( $item );
		$birthdate   = ufsc_lc_format_birthdate( $item->date_naissance ?? '' );
		$category    = $item->categorie_affiche ?? ( $item->category_value ?? '' );
		if ( '' === $category && '' !== $birthdate ) {
			$season_end_year = UFSC_LC_Categories::sanitize_season_end_year( $item->season_value ?? '' );
			if ( null === $season_end_year ) {
				$season_end_year = $this->get_default_season_end_year();
			}
			$computed = UFSC_LC_Categories::category_from_birthdate( $birthdate, $season_end_year );
			$category = isset( $computed['category'] ) ? (string) $computed['category'] : '';
		} elseif ( '' === $birthdate ) {
			$category = '';
		}
		$lines = array(
			'<strong>' . esc_html__( 'Licence ID', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( $item->id ),
			'<strong>' . esc_html__( 'Nom', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( '' !== $nom_affiche ? $nom_affiche : __( '—', 'ufsc-licence-competition' ) ),
			'<strong>' . esc_html__( 'Prénom', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( $item->prenom ),
			'<strong>' . esc_html__( 'Date de naissance', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( '' !== $birthdate ? $birthdate : __( '—', 'ufsc-licence-competition' ) ),
			'<strong>' . esc_html__( 'Club', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( $item->club_name ? $item->club_name : __( '—', 'ufsc-licence-competition' ) ),
			'<strong>' . esc_html( $season_label ) . '</strong>: ' . esc_html( $item->season_value ? $item->season_value : __( '—', 'ufsc-licence-competition' ) ),
			'<strong>' . esc_html__( 'Catégorie', 'ufsc-licence-competition' ) . '</strong>: ' . esc_html( $category ? $category : __( '—', 'ufsc-licence-competition' ) ),
		);

		return '<ul class="ufsc-lc-summary"><li>' . implode( '</li><li>', $lines ) . '</li></ul>';
	}

	private function get_licence_record( $licence_id, $numero_licence ) {
		global $wpdb;

		$table = $this->get_licences_table();

		if ( $licence_id ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT id, club_id, numero_licence_delegataire FROM {$table} WHERE id = %d", $licence_id ) );
		}

		return $wpdb->get_row( $wpdb->prepare( "SELECT id, club_id, numero_licence_delegataire FROM {$table} WHERE numero_licence_delegataire = %s", $numero_licence ) );
	}

	private function get_document_by_licence( $licence_id ) {
		global $wpdb;

		$table = $this->get_documents_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, attachment_id FROM {$table} WHERE licence_id = %d AND source = %s",
				$licence_id,
				self::SOURCE
			)
		);
	}

	private function upsert_document( $licence_id, $source_number, $attachment_id ) {
		global $wpdb;

		$table = $this->get_documents_table();

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s",
				$licence_id,
				self::SOURCE
			)
		);

		$source_number = '' !== $source_number ? $source_number : (string) $licence_id;

		$data = array(
			'licence_id'            => $licence_id,
			'source'                => self::SOURCE,
			'source_licence_number' => $source_number,
			'attachment_id'         => $attachment_id,
			'asptt_club_note'        => null,
			'updated_at'             => current_time( 'mysql' ),
		);

		$formats = array( '%d', '%s', '%s', '%d', '%s', '%s' );

		if ( $existing_id ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing_id ), $formats, array( '%d' ) );
		} else {
			$data['imported_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $data, $formats );
		}
	}

	private function update_document_meta( $licence_id, $meta_key, $meta_value ) {
		global $wpdb;

		$table = $this->get_documents_meta_table();
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s AND meta_key = %s",
				$licence_id,
				self::SOURCE,
				$meta_key
			)
		);

		$data = array(
			'licence_id' => $licence_id,
			'source'     => self::SOURCE,
			'meta_key'   => $meta_key,
			'meta_value' => maybe_serialize( $meta_value ),
			'updated_at' => current_time( 'mysql' ),
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s' );

		if ( $existing_id ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing_id ), $formats, array( '%d' ) );
		} else {
			$wpdb->insert( $table, $data, $formats );
		}
	}

	private function get_default_season_end_year() {
		if ( class_exists( 'UFSC_LC_Settings_Page' ) ) {
			return UFSC_LC_Settings_Page::get_default_season_end_year();
		}

		$year = (int) gmdate( 'Y' );
		return UFSC_LC_Categories::sanitize_season_end_year( $year );
	}

	private function get_season_column() {
		$table = $this->get_licences_table();

		if ( $this->has_column( $table, 'season_end_year' ) ) {
			return 'season_end_year';
		}

		foreach ( array( 'saison', 'season' ) as $column ) {
			if ( $this->has_column( $table, $column ) ) {
				return $column;
			}
		}

		return '';
	}

	private function get_category_column() {
		$table = $this->get_licences_table();

		if ( $this->has_column( $table, 'category' ) ) {
			return 'category';
		}

		if ( $this->has_column( $table, 'categorie' ) ) {
			return 'categorie';
		}

		return '';
	}

	private function has_column( $table, $column ) {
		global $wpdb;

		$results = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );

		return ! empty( $results );
	}

	private function table_exists( $table ) {
		global $wpdb;

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $exists === $table;
	}

	private function get_documents_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_licence_documents';
	}

	private function get_documents_meta_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_licence_documents_meta';
	}

	private function get_licences_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_licences';
	}

	private function get_clubs_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_clubs';
	}

	private function redirect_with_message( $status, $message ) {
		$redirect_url = add_query_arg(
			array(
				'page'         => 'ufsc-licence-documents',
				'ufsc_status'  => $status,
				'ufsc_message' => rawurlencode( $message ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
