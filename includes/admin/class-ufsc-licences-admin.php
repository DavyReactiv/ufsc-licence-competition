<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ufsc-licences-list-table.php';

class UFSC_LC_Licences_Admin {
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_ufsc_lc_export_csv', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_ufsc_lc_export_licences_csv', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_ufsc_lc_update_asptt_number', array( $this, 'handle_update_asptt_number' ) );
	}

	public function register_menu() {
		$hook_suffix = add_submenu_page(
			UFSC_LC_Plugin::PARENT_SLUG,
			__( 'Licences', 'ufsc-licence-competition' ),
			__( 'Licences', 'ufsc-licence-competition' ),
			UFSC_LC_Capabilities::get_read_capability(),
			'ufsc-lc-licences',
			array( $this, 'render_page' )
		);
		UFSC_LC_Admin_Assets::register_page( $hook_suffix );
		// Status submenu is registered in UFSC_LC_Status_Page::register_menu().
	}

	public function render_page() {
		if ( ! UFSC_LC_Capabilities::user_can_read() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		if ( $this->is_edit_asptt_action() ) {
			$this->render_asptt_edit_page();
			return;
		}

		$list_table = new UFSC_LC_Competition_Licences_List_Table();
		$list_table->prepare_items();

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Licences', 'ufsc-licence-competition' ); ?></h1>
			<?php if ( UFSC_LC_Capabilities::user_can_export() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-lc-export-form" style="display:inline-block; margin-left: 8px;">
					<?php wp_nonce_field( 'ufsc_lc_export_csv', 'ufsc_lc_nonce' ); ?>
					<input type="hidden" name="action" value="ufsc_lc_export_csv">
					<?php foreach ( $list_table->get_filter_query_args() as $key => $value ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
					<?php endforeach; ?>
					<?php submit_button( __( 'Exporter CSV (filtres actifs)', 'ufsc-licence-competition' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php $list_table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="ufsc-lc-licences" />
				<?php $filters = $list_table->get_sanitized_filters(); ?>
				<?php if ( 'all' !== $filters['tab'] ) : ?>
					<input type="hidden" name="ufsc_lc_tab" value="<?php echo esc_attr( $filters['tab'] ); ?>" />
				<?php endif; ?>
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-licences-search' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php

		if ( function_exists( 'ufsc_lc_log_query_count' ) ) {
			$screen_id = '';
			if ( function_exists( 'get_current_screen' ) ) {
				$screen    = get_current_screen();
				$screen_id = $screen ? (string) $screen->id : '';
			}

			// Log only if we're on the expected admin screen (avoid noise).
			if ( '' === $screen_id || false !== strpos( $screen_id, 'ufsc-lc-licences' ) ) {
				ufsc_lc_log_query_count(
					'admin: licences list',
					array(
						'screen' => $screen_id,
						'cache'  => 'n/a',
					)
				);
			}
		}
	}

	public function render_notices() {
		if ( ! $this->is_licences_screen() ) {
			return;
		}

		$success = isset( $_GET['success'] ) ? sanitize_text_field( wp_unslash( $_GET['success'] ) ) : '';
		$error   = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		$warning = isset( $_GET['warning'] ) ? sanitize_text_field( wp_unslash( $_GET['warning'] ) ) : '';

		$messages = array(
			'success' => array(
				'bulk_mark_review'            => __( 'Licences marquées à vérifier.', 'ufsc-licence-competition' ),
				'bulk_remove_pdf'             => __( 'Associations PDF supprimées.', 'ufsc-licence-competition' ),
				'bulk_recalculate_categories' => __( 'Catégories recalculées.', 'ufsc-licence-competition' ),
				'bulk_change_season'          => __( 'Saison mise à jour.', 'ufsc-licence-competition' ),
				'asptt_updated'               => __( 'N° licence ASPTT mis à jour.', 'ufsc-licence-competition' ),
			),
			'error' => array(
				'documents_meta_missing' => __( 'Action impossible : table meta des documents manquante.', 'ufsc-licence-competition' ),
				'documents_missing'      => __( 'Action impossible : table des documents absente.', 'ufsc-licence-competition' ),
				'season_missing'         => __( 'Action impossible : colonne saison absente.', 'ufsc-licence-competition' ),
				'asptt_missing'          => __( 'Impossible de mettre à jour le N° ASPTT (colonne manquante).', 'ufsc-licence-competition' ),
				'asptt_invalid'          => __( 'Licence invalide.', 'ufsc-licence-competition' ),
				'asptt_too_long'         => __( 'Le N° ASPTT ne doit pas dépasser 40 caractères.', 'ufsc-licence-competition' ),
			),
			'warning' => array(
				'bulk_recalculate_empty'   => __( 'Aucune licence valide pour recalculer les catégories.', 'ufsc-licence-competition' ),
				'bulk_recalculate_skipped' => __( 'Aucune catégorie recalculée : aucune règle disponible.', 'ufsc-licence-competition' ),
				'bulk_season_missing'      => __( 'Veuillez saisir une saison avant de lancer l’action.', 'ufsc-licence-competition' ),
			),
		);

		if ( $success && isset( $messages['success'][ $success ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $messages['success'][ $success ] )
			);
		}

		if ( $error && isset( $messages['error'][ $error ] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $messages['error'][ $error ] )
			);
		}

		if ( $warning && isset( $messages['warning'][ $warning ] ) ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html( $messages['warning'][ $warning ] )
			);
		}
	}

	public function handle_export_csv() {
		if ( ! UFSC_LC_Capabilities::user_can_export() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		if ( isset( $_POST['ufsc_lc_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['ufsc_lc_nonce'] ) );
			if ( ! wp_verify_nonce( $nonce, 'ufsc_lc_export_csv' ) ) {
				wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
			}
		} else {
			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'ufsc_lc_export_licences_csv' ) ) {
				wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
			}
		}

		$list_table = new UFSC_LC_Competition_Licences_List_Table();
		$filters    = $list_table->get_sanitized_filters();

		if ( function_exists( 'ufsc_lc_apply_scope_to_query_args' ) ) {
			$filters = ufsc_lc_apply_scope_to_query_args( $filters );
		}

		$exporter = new UFSC_LC_Exporter();
		$exporter->stream_licences_csv( $filters );
	}

	public function handle_update_asptt_number() {
		if ( ! $this->current_user_can_edit_asptt() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['ufsc_lc_asptt_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_lc_asptt_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ufsc_lc_update_asptt_number' ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$licence_id    = isset( $_POST['licence_id'] ) ? absint( $_POST['licence_id'] ) : 0;
		$asptt_number  = isset( $_POST['asptt_number'] ) ? sanitize_text_field( wp_unslash( $_POST['asptt_number'] ) ) : '';
		$asptt_number  = trim( $asptt_number );

		if ( ! $licence_id ) {
			$this->redirect_to_list_page( 'error', 'asptt_invalid' );
		}

		if ( class_exists( 'UFSC_LC_Scope' ) ) {
			UFSC_LC_Scope::enforce_object_scope( $licence_id, 'licence' );
		} else {
			$repository = new UFSC_LC_Licence_Repository();
			$repository->assert_licence_in_scope( $licence_id );
		}

		if ( '' !== $asptt_number && strlen( $asptt_number ) > 40 ) {
			$this->redirect_to_edit_page( $licence_id, 'error', 'asptt_too_long' );
		}

		$updated = $this->update_licence_asptt_number( $licence_id, $asptt_number );
		if ( is_wp_error( $updated ) ) {
			$this->redirect_to_edit_page( $licence_id, 'error', 'asptt_missing' );
			return;
		}

		$this->bump_licence_caches( $licence_id );

		$this->redirect_to_edit_page( $licence_id, 'success', 'asptt_updated' );
	}

	private function is_licences_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'ufsc-lc-licences' === $page;
	}

	private function is_edit_asptt_action() {
		if ( ! $this->is_licences_screen() ) {
			return false;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		return 'edit_asptt' === $action;
	}

	private function render_asptt_edit_page() {
		if ( ! $this->current_user_can_edit_asptt() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$licence_id = isset( $_GET['licence_id'] ) ? absint( $_GET['licence_id'] ) : 0;
		if ( ! $licence_id ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Modifier le N° licence ASPTT', 'ufsc-licence-competition' ); ?></h1>
				<?php
				printf(
					'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
					esc_html__( 'Licence invalide.', 'ufsc-licence-competition' )
				);
				?>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ufsc-lc-licences' ) ); ?>">&larr; <?php esc_html_e( 'Retour aux licences', 'ufsc-licence-competition' ); ?></a></p>
			</div>
			<?php
			return;
		}

		$repository = new UFSC_LC_Licence_Repository();
		$repository->assert_licence_in_scope( $licence_id );

		$licence        = $this->get_licence_context( $licence_id );
		$asptt_number   = $this->get_licence_asptt_number( $licence_id );
		$back_url       = admin_url( 'admin.php?page=ufsc-lc-licences' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Modifier le N° licence ASPTT', 'ufsc-licence-competition' ); ?></h1>
			<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Retour aux licences', 'ufsc-licence-competition' ); ?></a></p>
			<?php if ( ! $licence ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Licence introuvable.', 'ufsc-licence-competition' ); ?></p></div>
			<?php else : ?>
				<div class="ufsc-lc-licence-summary" style="margin-bottom: 20px;">
					<strong><?php echo esc_html( $licence['label'] ); ?></strong>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ufsc_lc_update_asptt_number', 'ufsc_lc_asptt_nonce' ); ?>
					<input type="hidden" name="action" value="ufsc_lc_update_asptt_number">
					<input type="hidden" name="licence_id" value="<?php echo esc_attr( $licence_id ); ?>">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ufsc_asptt_number"><?php esc_html_e( 'N° licence ASPTT', 'ufsc-licence-competition' ); ?></label></th>
							<td>
								<input type="text" id="ufsc_asptt_number" name="asptt_number" class="regular-text" value="<?php echo esc_attr( $asptt_number ); ?>" maxlength="40">
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Mettre à jour', 'ufsc-licence-competition' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function current_user_can_edit_asptt() {
		return UFSC_LC_Capabilities::user_can_edit();
	}

	private function get_licence_context( $licence_id ) {
		global $wpdb;

		$licence_id = (int) $licence_id;
		if ( $licence_id <= 0 ) {
			return null;
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$columns           = array( 'id', 'prenom', 'nom', 'nom_licence', 'numero_licence_delegataire', 'licence_number' );
		$existing_columns  = array();
		foreach ( $columns as $column ) {
			if ( $this->column_exists( $table, $column ) ) {
				$existing_columns[] = $column;
			}
		}

		if ( empty( $existing_columns ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ' . implode( ', ', $existing_columns ) . " FROM {$table} WHERE id = %d",
				$licence_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$name = '';
		if ( ! empty( $row['nom_licence'] ) ) {
			$name = $row['nom_licence'];
		} elseif ( ! empty( $row['nom'] ) ) {
			$name = $row['nom'];
		}
		if ( ! empty( $row['prenom'] ) ) {
			$name = trim( $row['prenom'] . ' ' . $name );
		}

		$licence_number = $row['numero_licence_delegataire'] ?? $row['licence_number'] ?? '';
		$label_parts    = array(
			sprintf( __( 'Licence #%d', 'ufsc-licence-competition' ), $licence_id ),
		);
		if ( '' !== $name ) {
			$label_parts[] = $name;
		}
		if ( '' !== $licence_number ) {
			$label_parts[] = $licence_number;
		}

		return array(
			'label' => implode( ' · ', array_map( 'sanitize_text_field', $label_parts ) ),
		);
	}

	private function get_licence_asptt_number( $licence_id ) {
		global $wpdb;

		$licence_id = (int) $licence_id;
		if ( $licence_id <= 0 ) {
			return '';
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return '';
		}

		$columns = $this->get_asptt_columns( $table );
		if ( empty( $columns ) ) {
			return '';
		}

		$parts = array();
		foreach ( $columns as $column ) {
			$parts[] = "NULLIF({$column}, '')";
		}
		$select = 'COALESCE(' . implode( ', ', $parts ) . ') AS asptt_number';

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT {$select} FROM {$table} WHERE id = %d",
				$licence_id
			)
		);

		return is_string( $value ) ? $value : '';
	}

	private function update_licence_asptt_number( $licence_id, $asptt_number ) {
		global $wpdb;

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return new WP_Error( 'licence_table_missing', __( 'Table licences indisponible.', 'ufsc-licence-competition' ) );
		}

		$columns = $this->get_asptt_columns( $table );
		if ( empty( $columns ) ) {
			return new WP_Error( 'asptt_column_missing', __( 'Colonne ASPTT indisponible.', 'ufsc-licence-competition' ) );
		}

		$data    = array();
		$formats = array();
		foreach ( $columns as $column ) {
			$data[ $column ] = $asptt_number;
			$formats[]       = '%s';
		}

		$result = $wpdb->update(
			$table,
			$data,
			array( 'id' => (int) $licence_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result && ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'licence_update_failed', __( 'Erreur lors de la mise à jour de la licence.', 'ufsc-licence-competition' ) );
		}

		return $result;
	}

	private function bump_licence_caches( $licence_id ): void {
		if ( ! function_exists( 'ufsc_lc_bump_cache_version' ) ) {
			return;
		}

		$club_id = $this->get_licence_club_id( $licence_id );
		if ( $club_id ) {
			ufsc_lc_bump_cache_version( 'club', (int) $club_id );
		}
	}

	private function get_licence_club_id( $licence_id ) {
		global $wpdb;

		$licence_id = (int) $licence_id;
		if ( $licence_id <= 0 ) {
			return 0;
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$club_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT club_id FROM {$table} WHERE id = %d",
				$licence_id
			)
		);

		return $club_id ? (int) $club_id : 0;
	}

	private function get_licences_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_licences';
	}

	private function get_asptt_columns( $table ) {
		$columns = array();
		foreach ( array( 'numero_licence_asptt', 'asptt_number' ) as $column ) {
			if ( $this->column_exists( $table, $column ) ) {
				$columns[] = $column;
			}
		}

		return $columns;
	}

	private function column_exists( $table, $column ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
	}

	private function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private function redirect_to_edit_page( $licence_id, $type, $code ) {
		$url = add_query_arg(
			array(
				'page'       => 'ufsc-lc-licences',
				'action'     => 'edit_asptt',
				'licence_id' => (int) $licence_id,
				$type        => $code,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	private function redirect_to_list_page( $type, $code ) {
		$url = add_query_arg(
			array(
				'page' => 'ufsc-lc-licences',
				$type  => $code,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
