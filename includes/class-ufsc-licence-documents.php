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
			UFSC_LC_Plugin::CAPABILITY,
			UFSC_LC_Plugin::PARENT_SLUG,
			array( $this, 'render_admin_page' ),
			'dashicons-media-document',
			30
		);
		UFSC_LC_Admin_Assets::register_page( $hook_suffix );
	}

	public function render_admin_page() {
		if ( ! current_user_can( UFSC_LC_Capabilities::CAPABILITY ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$status = isset( $_GET['ufsc_status'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_status'] ) ) : '';
		$message = isset( $_GET['ufsc_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_message'] ) ) : '';

		if ( $status && $message ) {
			$class = ( 'success' === $status ) ? 'notice notice-success' : 'notice notice-error';
			echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Associer un PDF de licence', 'ufsc-licence-competition' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'ufsc_lc_upload_licence_pdf', 'ufsc_lc_upload_nonce' ); ?>
				<input type="hidden" name="action" value="ufsc_lc_upload_licence_pdf">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="licence_id"><?php esc_html_e( 'Licence ID', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="licence_id" type="number" id="licence_id" class="regular-text" min="1"></td>
					</tr>
					<tr>
						<th scope="row"><label for="numero_licence_delegataire"><?php esc_html_e( 'Numéro licence délégataire', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="numero_licence_delegataire" type="text" id="numero_licence_delegataire" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="licence_pdf"><?php esc_html_e( 'PDF nominatif', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="licence_pdf" type="file" id="licence_pdf" accept="application/pdf" required></td>
					</tr>
				</table>
				<?php submit_button( __( 'Uploader et associer', 'ufsc-licence-competition' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_upload() {
		if ( ! current_user_can( UFSC_LC_Capabilities::CAPABILITY ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['ufsc_lc_upload_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_lc_upload_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ufsc_lc_upload_licence_pdf' ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$licence_id = isset( $_POST['licence_id'] ) ? absint( $_POST['licence_id'] ) : 0;
		$numero_licence = isset( $_POST['numero_licence_delegataire'] ) ? sanitize_text_field( wp_unslash( $_POST['numero_licence_delegataire'] ) ) : '';

		if ( 0 === $licence_id && '' === $numero_licence ) {
			$this->redirect_with_message( 'error', __( 'Veuillez fournir un identifiant ou un numéro de licence.', 'ufsc-licence-competition' ) );
		}

		$licence = $this->get_licence_record( $licence_id, $numero_licence );
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

		$this->upsert_document( (int) $licence->id, $numero_licence, (int) $attachment_id );

		$this->redirect_with_message( 'success', __( 'PDF associé avec succès.', 'ufsc-licence-competition' ) );
	}

	public function handle_download() {
		if ( ! is_user_logged_in() ) {
			UFSC_LC_Logger::log( 'Téléchargement refusé: utilisateur non connecté.' );
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$licence_id = isset( $_GET['licence_id'] ) ? absint( $_GET['licence_id'] ) : 0;
		$nonce      = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $licence_id || ! wp_verify_nonce( $nonce, 'ufsc_lc_download_licence_pdf_' . $licence_id ) ) {
			UFSC_LC_Logger::log( 'Téléchargement refusé: nonce invalide.', array( 'licence_id' => $licence_id ) );
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$licence = $this->get_licence_record( $licence_id, '' );
		if ( ! $licence ) {
			UFSC_LC_Logger::log( 'Téléchargement refusé: licence introuvable.', array( 'licence_id' => $licence_id ) );
			wp_die( esc_html__( 'Licence introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		if ( ! current_user_can( UFSC_LC_Capabilities::CAPABILITY ) ) {
			$club = $this->get_club_by_id( (int) $licence->club_id );
			if ( ! $club || (int) $club->responsable_id !== (int) get_current_user_id() ) {
				UFSC_LC_Logger::log(
					'Téléchargement refusé: utilisateur non autorisé.',
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
			UFSC_LC_Logger::log( 'Téléchargement refusé: document indisponible.', array( 'licence_id' => $licence_id ) );
			wp_die( esc_html__( 'Document indisponible.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		$file_path = get_attached_file( (int) $document->attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			UFSC_LC_Logger::log( 'Téléchargement refusé: fichier introuvable.', array( 'attachment_id' => (int) $document->attachment_id ) );
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

		$table = $wpdb->prefix . 'ufsc_clubs';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, responsable_id FROM {$table} WHERE id = %d",
				$club_id
			)
		);
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
