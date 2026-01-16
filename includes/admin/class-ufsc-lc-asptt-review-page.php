<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ufsc-lc-asptt-review-list-table.php';

class UFSC_LC_ASPTT_Review_Page {
	private $service;
	private $notice = array();

	public function __construct( UFSC_LC_ASPTT_Import_Service $service ) {
		$this->service = $service;
	}

	public function register_actions() {
		add_action( 'admin_post_ufsc_lc_asptt_review_approve', array( $this, 'handle_approve' ) );
		add_action( 'admin_post_ufsc_lc_asptt_review_reject', array( $this, 'handle_reject' ) );
		add_action( 'admin_post_ufsc_lc_asptt_trash', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_lc_asptt_restore', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_lc_asptt_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ufsc_lc_asptt_review_set_club', array( $this, 'handle_set_club' ) );
		add_action( 'admin_post_ufsc_lc_asptt_review_save_alias', array( $this, 'handle_save_alias' ) );
		add_action( 'wp_ajax_ufsc_lc_search_clubs', array( $this, 'ajax_search_clubs' ) );
	}

	public function render() {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		if ( ! $this->documents_meta_table_exists() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Table meta des documents manquante : la validation est indisponible.', 'ufsc-licence-competition' )
			);
			return;
		}

		$this->handle_bulk_action_request();
		$this->render_notice();
		$this->render_choose_club_form();

		$list_table = new UFSC_LC_ASPTT_Review_List_Table( $this->get_clubs() );
		$list_table->prepare_items();

		$list_table->views();
		?>
		<form method="post" class="ufsc-lc-review-form">
			<?php wp_nonce_field( 'ufsc_lc_asptt_review_bulk', 'ufsc_lc_asptt_review_nonce' ); ?>
			<input type="hidden" name="page" value="ufsc-lc-import-asptt" />
			<input type="hidden" name="tab" value="review" />
			<input type="hidden" name="review_status" value="<?php echo esc_attr( $list_table->get_sanitized_filters()['review_status'] ); ?>" />
			<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-review-search' ); ?>
			<div class="ufsc-lc-table-wrap">
				<?php $list_table->display(); ?>
			</div>
		</form>
		<?php
	}

	public function handle_approve() {
		$this->handle_single_action( 'approve' );
	}

	public function handle_reject() {
		$this->handle_single_action( 'reject' );
	}

	public function handle_trash() {
		$this->handle_single_action( 'trash' );
	}

	public function handle_restore() {
		$this->handle_single_action( 'restore' );
	}

	public function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$document_id = isset( $_GET['document_id'] ) ? absint( $_GET['document_id'] ) : 0;
		if ( ! $document_id ) {
			$this->redirect_with_notice( 'error', __( 'Document introuvable.', 'ufsc-licence-competition' ) );
		}

		$nonce_action = 'ufsc_lc_asptt_review_delete_' . $document_id;
		check_admin_referer( $nonce_action );

		$document = $this->get_document_row( $document_id );
		if ( ! $document || 'ASPTT' !== $document->source ) {
			$this->redirect_with_notice( 'error', __( 'Document introuvable.', 'ufsc-licence-competition' ) );
		}

		$this->delete_document_data( (int) $document->document_id, (int) $document->licence_id, $document->source );

		$this->redirect_with_notice( 'success', __( 'Suppression définitive effectuée.', 'ufsc-licence-competition' ) );
	}

	public function handle_set_club() {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$document_id = isset( $_POST['document_id'] ) ? absint( $_POST['document_id'] ) : 0;
		$club_id_raw = isset( $_POST['club_id'] ) ? wp_unslash( $_POST['club_id'] ) : '';
		$club_id     = ( '' !== $club_id_raw && ctype_digit( (string) $club_id_raw ) ) ? absint( $club_id_raw ) : 0;
		$licence_id_raw = isset( $_POST['licence_id'] ) ? wp_unslash( $_POST['licence_id'] ) : '';
		$licence_id     = ( '' !== $licence_id_raw && ctype_digit( (string) $licence_id_raw ) ) ? absint( $licence_id_raw ) : 0;

		if ( ! $document_id || ! $club_id || ! $club_id_raw || ( $licence_id_raw && ! $licence_id ) ) {
			$this->redirect_with_notice( 'error', __( 'Données invalides.', 'ufsc-licence-competition' ) );
		}

		check_admin_referer( 'ufsc_lc_asptt_review_set_club_' . $document_id );

		$document = $this->get_document_row( $document_id );
		if ( ! $document ) {
			$this->redirect_with_notice( 'error', __( 'Document introuvable.', 'ufsc-licence-competition' ) );
		}
		if ( $licence_id && (int) $document->licence_id !== $licence_id ) {
			$this->redirect_with_notice( 'error', __( 'Licence invalide.', 'ufsc-licence-competition' ) );
		}

		$match = $this->find_matching_licence_id( $club_id, $document->nom_licence, $document->prenom, $document->date_naissance, $document->sexe );
		if ( ! $match ) {
			$this->redirect_with_notice( 'warning', __( 'Aucune licence correspondante pour ce club.', 'ufsc-licence-competition' ) );
		}

		$this->update_document_licence( $document_id, $match );
		$this->upsert_document_meta( $match, $document->source, 'club_resolution', 'manual' );
		$this->upsert_document_meta( $match, $document->source, 'review_status', 'pending' );
		$this->upsert_document_meta( $match, $document->source, 'link_mode', 'manual' );

		$this->redirect_with_notice( 'success', __( 'Club mis à jour.', 'ufsc-licence-competition' ) );
	}

	public function handle_save_alias() {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$document_id = isset( $_GET['document_id'] ) ? absint( $_GET['document_id'] ) : 0;
		if ( ! $document_id ) {
			$this->redirect_with_notice( 'error', __( 'Document introuvable.', 'ufsc-licence-competition' ) );
		}

		check_admin_referer( 'ufsc_lc_asptt_review_save_alias_' . $document_id );

		$document = $this->get_document_row( $document_id );
		if ( ! $document || empty( $document->asptt_club_note ) ) {
			$this->redirect_with_notice( 'warning', __( 'Aucun alias à enregistrer.', 'ufsc-licence-competition' ) );
		}

		$this->service->save_alias( (int) $document->club_id, $document->asptt_club_note );
		$this->redirect_with_notice( 'success', __( 'Alias enregistré.', 'ufsc-licence-competition' ) );
	}

	private function handle_single_action( $action ) {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$document_id = isset( $_GET['document_id'] ) ? absint( $_GET['document_id'] ) : 0;
		if ( ! $document_id ) {
			$this->redirect_with_notice( 'error', __( 'Document introuvable.', 'ufsc-licence-competition' ) );
		}

		$nonce_action = 'ufsc_lc_asptt_review_' . $action . '_' . $document_id;
		check_admin_referer( $nonce_action );

		$document = $this->get_document_row( $document_id );
		if ( ! $document ) {
			$this->redirect_with_notice( 'error', __( 'Document introuvable.', 'ufsc-licence-competition' ) );
		}

		$message = '';

		if ( 'trash' === $action ) {
			$previous_status = $this->get_document_meta_value( (int) $document->licence_id, $document->source, 'review_status' );
			$previous_status = $previous_status ? $previous_status : 'pending';
			$this->upsert_document_meta( (int) $document->licence_id, $document->source, 'prev_review_status', $previous_status );
			$this->upsert_document_meta( (int) $document->licence_id, $document->source, 'review_status', 'trash' );

			$undo_url = $this->get_action_url( 'ufsc_lc_asptt_restore', (int) $document->document_id, 'ufsc_lc_asptt_review_restore_' . (int) $document->document_id );

			$message = sprintf(
				/* translators: %s: undo link */
				__( 'Élément mis à la corbeille. %s', 'ufsc-licence-competition' ),
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( $undo_url ),
					esc_html__( 'Annuler', 'ufsc-licence-competition' )
				)
			);
			$this->redirect_with_notice( 'warning', $message );
		}

		if ( 'restore' === $action ) {
			$previous_status = $this->get_document_meta_value( (int) $document->licence_id, $document->source, 'prev_review_status' );
			$restore_status  = $previous_status ? $previous_status : 'pending';
			if ( ! in_array( $restore_status, array( 'pending', 'approved', 'rejected' ), true ) ) {
				$restore_status = 'pending';
			}
			$this->upsert_document_meta( (int) $document->licence_id, $document->source, 'review_status', $restore_status );
			$message = __( 'Élément restauré.', 'ufsc-licence-competition' );
			$this->redirect_with_notice( 'success', $message );
		}

		$review_status = 'approve' === $action ? 'approved' : 'rejected';
		$link_mode = 'approve' === $action ? 'manual' : $document->link_mode;

		$this->upsert_document_meta( (int) $document->licence_id, $document->source, 'review_status', $review_status );
		$this->upsert_document_meta( (int) $document->licence_id, $document->source, 'link_mode', $link_mode );

		$message = 'approve' === $action
			? __( 'Validation enregistrée.', 'ufsc-licence-competition' )
			: __( 'Rejet enregistré.', 'ufsc-licence-competition' );

		$this->redirect_with_notice( 'success', $message );
	}

	private function handle_bulk_action_request() {
		if ( empty( $_POST['action'] ) && empty( $_POST['action2'] ) ) {
			return;
		}

		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		check_admin_referer( 'ufsc_lc_asptt_review_bulk', 'ufsc_lc_asptt_review_nonce' );

		$action = isset( $_POST['action'] ) && '-1' !== $_POST['action'] ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( ! $action && isset( $_POST['action2'] ) && '-1' !== $_POST['action2'] ) {
			$action = sanitize_key( wp_unslash( $_POST['action2'] ) );
		}

		if ( ! $action ) {
			return;
		}

		$document_ids = isset( $_POST['document'] ) ? array_map( 'absint', (array) $_POST['document'] ) : array();
		$document_ids = array_filter( $document_ids );
		if ( empty( $document_ids ) ) {
			$this->notice = array(
				'type'    => 'warning',
				'message' => __( 'Aucun élément sélectionné.', 'ufsc-licence-competition' ),
			);
			return;
		}

		$updated = 0;
		$failed  = 0;
		foreach ( $document_ids as $document_id ) {
			$document = $this->get_document_row( $document_id );
			if ( ! $document ) {
				$failed++;
				continue;
			}

			switch ( $action ) {
				case 'approve':
					$this->upsert_document_meta( (int) $document->licence_id, $document->source, 'review_status', 'approved' );
					$this->upsert_document_meta( (int) $document->licence_id, $document->source, 'link_mode', 'manual' );
					$updated++;
					break;
				case 'reject':
					$this->upsert_document_meta( (int) $document->licence_id, $document->source, 'review_status', 'rejected' );
					$updated++;
					break;
				case 'trash':
					$previous_status = $this->get_document_meta_value( (int) $document->licence_id, $document->source, 'review_status' );
					$previous_status = $previous_status ? $previous_status : 'pending';
					$this->upsert_document_meta( (int) $document->licence_id, $document->source, 'prev_review_status', $previous_status );
					$this->upsert_document_meta( (int) $document->licence_id, $document->source, 'review_status', 'trash' );
					$updated++;
					break;
				case 'restore':
					$previous_status = $this->get_document_meta_value( (int) $document->licence_id, $document->source, 'prev_review_status' );
					$restore_status  = $previous_status ? $previous_status : 'pending';
					if ( ! in_array( $restore_status, array( 'pending', 'approved', 'rejected' ), true ) ) {
						$restore_status = 'pending';
					}
					$this->upsert_document_meta( (int) $document->licence_id, $document->source, 'review_status', $restore_status );
					$updated++;
					break;
				case 'save_alias':
					if ( ! empty( $document->asptt_club_note ) ) {
						$this->service->save_alias( (int) $document->club_id, $document->asptt_club_note );
						$updated++;
					} else {
						$failed++;
					}
					break;
				default:
					$failed++;
					break;
			}
		}

		$this->notice = array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: 1: updated count, 2: failed count */
				esc_html__( '%1$d ligne(s) mises à jour, %2$d en échec.', 'ufsc-licence-competition' ),
				$updated,
				$failed
			),
		);
	}

	private function render_notice() {
		if ( ! empty( $this->notice ) ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				esc_attr( $this->notice['type'] ),
				esc_html( $this->notice['message'] )
			);
			return;
		}

		$status = isset( $_GET['ufsc_review_status'] ) ? sanitize_text_field( wp_unslash( $_GET['ufsc_review_status'] ) ) : '';
		$message = isset( $_GET['ufsc_review_message'] ) ? wp_unslash( $_GET['ufsc_review_message'] ) : '';
		if ( ! $status || ! $message ) {
			return;
		}

		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( $status ),
			wp_kses(
				$message,
				array(
					'a' => array(
						'href'  => array(),
						'class' => array(),
					),
				)
			)
		);
	}

	private function render_choose_club_form() {
		$choose_club = isset( $_GET['choose_club'] ) ? absint( $_GET['choose_club'] ) : 0;
		$document_id = isset( $_GET['document_id'] ) ? absint( $_GET['document_id'] ) : 0;

		if ( ! $choose_club || ! $document_id ) {
			return;
		}

		$document = $this->get_document_row( $document_id );
		if ( ! $document ) {
			return;
		}

		$clubs = $this->get_clubs_for_select();
		if ( empty( $clubs ) ) {
			return;
		}

		$current_club_id = (int) $document->club_id;
		$has_current_club = false;
		foreach ( $clubs as $club ) {
			if ( (int) $club['id'] === $current_club_id ) {
				$has_current_club = true;
				break;
			}
		}
		if ( $current_club_id && ! $has_current_club ) {
			$current_club = $this->get_club_for_select_by_id( $current_club_id );
			if ( $current_club ) {
				$clubs[] = $current_club;
			}
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-lc-choose-club-form">
			<?php wp_nonce_field( 'ufsc_lc_asptt_review_set_club_' . (int) $document_id ); ?>
			<input type="hidden" name="action" value="ufsc_lc_asptt_review_set_club" />
			<input type="hidden" name="document_id" value="<?php echo esc_attr( $document_id ); ?>" />
			<input type="hidden" name="licence_id" value="<?php echo esc_attr( (int) $document->licence_id ); ?>" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->get_review_url() ); ?>" />
			<p>
				<strong><?php esc_html_e( 'Choisir un club pour la licence', 'ufsc-licence-competition' ); ?></strong>
				<?php echo esc_html( $document->nom_licence . ' ' . $document->prenom ); ?>
			</p>
			<select name="club_id" class="ufsc-lc-club-select" data-licence-id="<?php echo esc_attr( (int) $document->licence_id ); ?>" required>
				<option value=""><?php esc_html_e( 'Rechercher un club…', 'ufsc-licence-competition' ); ?></option>
				<?php foreach ( $clubs as $club ) : ?>
					<option value="<?php echo esc_attr( $club['id'] ); ?>" <?php selected( (int) $club['id'], (int) $document->club_id ); ?>>
						<?php echo esc_html( $club['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Valider association', 'ufsc-licence-competition' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public function ajax_search_clubs() {
		if ( ! current_user_can( 'manage_options' ) && ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ) );
		}

		check_ajax_referer( 'ufsc_lc_search_clubs' );

		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		if ( strlen( $search ) < 2 ) {
			wp_send_json_success( array() );
		}

		$clubs = $this->get_clubs_for_select( $search );
		$data  = array();

		foreach ( $clubs as $club ) {
			$data[] = array(
				'id'   => $club['id'],
				'text' => $club['label'],
			);
		}

		wp_send_json_success( $data );
	}

	private function redirect_with_notice( $type, $message ) {
		$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		if ( ! $redirect ) {
			$redirect = $this->get_review_url();
		}

		$redirect = add_query_arg(
			array(
				'ufsc_review_status'  => $type,
				'ufsc_review_message' => $message,
			),
			$redirect
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	private function get_review_url() {
		return add_query_arg(
			array(
				'page' => 'ufsc-lc-import-asptt',
				'tab'  => 'review',
			),
			admin_url( 'admin.php' )
		);
	}

	private function get_action_url( $action, $document_id, $nonce_action ) {
		$url = add_query_arg(
			array(
				'action'      => $action,
				'document_id' => $document_id,
				'redirect_to' => $this->get_review_url(),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, $nonce_action );
	}

	private function documents_meta_table_exists() {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licence_documents_meta';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private function get_clubs() {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_clubs';
		return $wpdb->get_results( "SELECT id, nom FROM {$table} ORDER BY nom ASC" );
	}

	private function get_clubs_for_select( $search = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_clubs';
		$has_postal = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'code_postal'" );
		$select_fields = $has_postal ? 'id, nom, code_postal' : 'id, nom';
		$where = '';
		$params = array();
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			if ( $has_postal ) {
				$where = 'WHERE nom LIKE %s OR code_postal LIKE %s';
				$params[] = $like;
				$params[] = $like;
			} else {
				$where = 'WHERE nom LIKE %s';
				$params[] = $like;
			}
		}

		$sql = "SELECT {$select_fields} FROM {$table} {$where} ORDER BY nom ASC LIMIT 50";
		$results = empty( $params ) ? $wpdb->get_results( $sql ) : $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$data = array();
		foreach ( $results as $club ) {
			$data[] = array(
				'id'    => (int) $club->id,
				'label' => $this->format_club_label( $club ),
			);
		}

		return $data;
	}

	private function get_club_for_select_by_id( $club_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_clubs';
		$has_postal = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'code_postal'" );
		$select_fields = $has_postal ? 'id, nom, code_postal' : 'id, nom';

		$club = $wpdb->get_row(
			$wpdb->prepare( "SELECT {$select_fields} FROM {$table} WHERE id = %d", $club_id )
		);
		if ( ! $club ) {
			return null;
		}

		return array(
			'id'    => (int) $club->id,
			'label' => $this->format_club_label( $club ),
		);
	}

	private function format_club_label( $club ) {
		$label = $club->nom;
		if ( isset( $club->code_postal ) && '' !== $club->code_postal ) {
			$label .= ' (' . $club->code_postal . ')';
		}

		return $label;
	}

	private function get_document_row( $document_id ) {
		global $wpdb;

		$documents_table = $wpdb->prefix . 'ufsc_licence_documents';
		$licences_table  = $wpdb->prefix . 'ufsc_licences';
		$clubs_table     = $wpdb->prefix . 'ufsc_clubs';
		$meta_table      = $wpdb->prefix . 'ufsc_licence_documents_meta';

		$sql = "SELECT
			docs.id AS document_id,
			docs.licence_id,
			docs.source,
			docs.asptt_club_note,
			licences.nom_licence,
			licences.prenom,
			licences.date_naissance,
			licences.sexe,
			clubs.id AS club_id,
			meta_link.meta_value AS link_mode
			FROM {$documents_table} docs
			INNER JOIN {$licences_table} licences ON licences.id = docs.licence_id
			INNER JOIN {$clubs_table} clubs ON clubs.id = licences.club_id
			LEFT JOIN {$meta_table} meta_link ON meta_link.licence_id = docs.licence_id AND meta_link.source = docs.source AND meta_link.meta_key = 'link_mode'
			WHERE docs.id = %d";

		return $wpdb->get_row( $wpdb->prepare( $sql, $document_id ) );
	}

	private function get_document_meta_value( $licence_id, $source, $meta_key ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licence_documents_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return '';
		}

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$table} WHERE licence_id = %d AND source = %s AND meta_key = %s",
				$licence_id,
				$source,
				$meta_key
			)
		);
	}

	private function delete_document_data( $document_id, $licence_id, $source ) {
		global $wpdb;

		$meta_table = $wpdb->prefix . 'ufsc_licence_documents_meta';
		$docs_table = $wpdb->prefix . 'ufsc_licence_documents';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) ) === $meta_table ) {
			$wpdb->delete(
				$meta_table,
				array(
					'licence_id' => $licence_id,
					'source'     => $source,
				),
				array( '%d', '%s' )
			);
		}

		$wpdb->delete(
			$docs_table,
			array(
				'id'     => $document_id,
				'source' => $source,
			),
			array( '%d', '%s' )
		);
	}

	private function upsert_document_meta( $licence_id, $source, $meta_key, $meta_value ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licence_documents_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s AND meta_key = %s",
				$licence_id,
				$source,
				$meta_key
			)
		);

		$data = array(
			'licence_id' => $licence_id,
			'source'     => $source,
			'meta_key'   => $meta_key,
			'meta_value' => maybe_serialize( $meta_value ),
			'updated_at' => current_time( 'mysql' ),
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s' );

		if ( $existing ) {
			return false !== $wpdb->update( $table, $data, array( 'id' => (int) $existing ), $formats, array( '%d' ) );
		}

		return false !== $wpdb->insert( $table, $data, $formats );
	}

	private function update_document_licence( $document_id, $licence_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licence_documents';

		$wpdb->update(
			$table,
			array(
				'licence_id' => $licence_id,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $document_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	private function find_matching_licence_id( $club_id, $nom, $prenom, $dob, $genre ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licences';

		$normalized_nom    = $this->normalize_name( $nom );
		$normalized_prenom = $this->normalize_name( $prenom );
		$dob_value         = $this->parse_date( $dob );

		if ( ! $dob_value ) {
			return 0;
		}

		$sql = "SELECT id, sexe, nom_licence, prenom FROM {$table} WHERE club_id = %d AND date_naissance = %s";
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

	private function normalize_name( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = strtoupper( $value );
		$value = remove_accents( $value );
		$value = preg_replace( '/[^A-Z0-9]+/u', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return trim( $value );
	}

	private function parse_date( $value ) {
		$value = trim( (string) $value );
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
}
