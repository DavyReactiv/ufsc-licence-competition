<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UFSC_Licence_Documents' ) ) {
class UFSC_Licence_Documents {
	const DB_VERSION = '1.1.0';
	const SOURCE     = 'UFSC';

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_ufsc_upload_licence_pdf', array( $this, 'handle_upload' ) );
		add_shortcode( 'ufsc_licences', array( $this, 'render_club_licences_shortcode' ) );
		add_action( 'admin_post_ufsc_download_licence_pdf', array( $this, 'handle_download' ) );
		add_action( 'admin_post_nopriv_ufsc_download_licence_pdf', array( $this, 'handle_download' ) );
	}

	public function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $this->get_documents_table();
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

		update_option( 'ufsc_licence_documents_db_version', self::DB_VERSION );
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'UFSC Licences', 'ufsc-licence-competition' ),
			__( 'UFSC Licences', 'ufsc-licence-competition' ),
			'manage_options',
			'ufsc-licence-documents',
			array( $this, 'render_admin_page' ),
			'dashicons-media-document',
			30
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
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
				<?php wp_nonce_field( 'ufsc_upload_licence_pdf', 'ufsc_upload_nonce' ); ?>
				<input type="hidden" name="action" value="ufsc_upload_licence_pdf">

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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		check_admin_referer( 'ufsc_upload_licence_pdf', 'ufsc_upload_nonce' );

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

	public function render_club_licences_shortcode() {
		if ( ! is_user_logged_in() ) {
			return esc_html__( 'Veuillez vous connecter pour voir vos licences.', 'ufsc-licence-competition' );
		}

		$club_id = $this->get_current_user_club_id();
		if ( ! $club_id ) {
			return esc_html__( 'Aucun club associé à votre compte.', 'ufsc-licence-competition' );
		}

		$licences = $this->get_club_licences_with_documents( $club_id );
		if ( empty( $licences ) ) {
			return esc_html__( 'Aucune licence trouvée pour ce club.', 'ufsc-licence-competition' );
		}

		ob_start();
		?>
		<table class="ufsc-licence-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Licence ID', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Numéro licence', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'PDF nominatif', 'ufsc-licence-competition' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $licences as $licence ) : ?>
					<tr>
						<td><?php echo esc_html( $licence->licence_id ); ?></td>
						<td><?php echo esc_html( $licence->numero_licence_delegataire ); ?></td>
						<td>
							<?php if ( $licence->attachment_id ) : ?>
								<?php $download_url = $this->get_download_url( $licence->licence_id ); ?>
								<a href="<?php echo esc_url( $download_url ); ?>">
									<?php esc_html_e( 'Télécharger', 'ufsc-licence-competition' ); ?>
								</a>
							<?php else : ?>
								<?php esc_html_e( 'Non disponible', 'ufsc-licence-competition' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	public function handle_download() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$licence_id = isset( $_GET['licence_id'] ) ? absint( $_GET['licence_id'] ) : 0;
		$nonce      = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $licence_id || ! wp_verify_nonce( $nonce, 'ufsc_download_licence_pdf_' . $licence_id ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ) );
		}

		$club_id = $this->get_current_user_club_id();
		if ( ! $club_id ) {
			wp_die( esc_html__( 'Aucun club associé.', 'ufsc-licence-competition' ) );
		}

		$licence = $this->get_licence_record( $licence_id, '' );
		if ( ! $licence || (int) $licence->club_id !== (int) $club_id ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$document = $this->get_document_by_licence( $licence_id );
		if ( ! $document || ! $document->attachment_id ) {
			wp_die( esc_html__( 'Document indisponible.', 'ufsc-licence-competition' ) );
		}

		$file_path = get_attached_file( (int) $document->attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'Fichier introuvable.', 'ufsc-licence-competition' ) );
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
					'action'     => 'ufsc_download_licence_pdf',
					'licence_id' => (int) $licence_id,
				),
				admin_url( 'admin-post.php' )
			),
			'ufsc_download_licence_pdf_' . (int) $licence_id
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

	private function get_club_licences_with_documents( $club_id ) {
		global $wpdb;

		$licences_table  = $this->get_licences_table();
		$documents_table = $this->get_documents_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id AS licence_id, l.numero_licence_delegataire, d.attachment_id
				 FROM {$licences_table} l
				 LEFT JOIN {$documents_table} d ON d.licence_id = l.id AND d.source = %s
				 WHERE l.club_id = %d
				 ORDER BY l.id ASC",
				self::SOURCE,
				$club_id
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

	private function get_current_user_club_id() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}

		$club_id = get_user_meta( $user_id, 'club_id', true );

		return $club_id ? (int) $club_id : 0;
	}

	private function get_documents_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_licence_documents';
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
}
