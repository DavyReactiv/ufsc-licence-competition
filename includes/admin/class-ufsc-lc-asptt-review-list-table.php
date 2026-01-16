<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class UFSC_LC_ASPTT_Review_List_Table extends WP_List_Table {
	private $clubs = array();
	private $filters = array();

	public function __construct( $clubs = array() ) {
		parent::__construct(
			array(
				'singular' => 'document',
				'plural'   => 'documents',
				'ajax'     => false,
			)
		);

		$this->clubs = $clubs;
	}

	public function get_columns() {
		return array(
			'cb'               => '<input type="checkbox" />',
			'club'             => __( 'Club proposé', 'ufsc-licence-competition' ),
			'nom_licence'      => __( 'Nom', 'ufsc-licence-competition' ),
			'prenom'           => __( 'Prénom', 'ufsc-licence-competition' ),
			'date_naissance'   => __( 'Date de naissance', 'ufsc-licence-competition' ),
			'asptt_number'     => __( 'N° ASPTT', 'ufsc-licence-competition' ),
			'season_end_year'  => __( 'Saison', 'ufsc-licence-competition' ),
			'category'         => __( 'Catégorie', 'ufsc-licence-competition' ),
			'confidence_score' => __( 'Score', 'ufsc-licence-competition' ),
			'resolution'       => __( 'Résolution', 'ufsc-licence-competition' ),
			'review_status'    => __( 'Statut', 'ufsc-licence-competition' ),
			'actions'          => __( 'Actions', 'ufsc-licence-competition' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'confidence_score' => array( 'confidence_score', false ),
			'nom_licence'      => array( 'nom_licence', true ),
			'club'             => array( 'club_name', true ),
		);
	}

	public function get_bulk_actions() {
		$filters = $this->get_sanitized_filters();
		$actions = array(
			'approve' => __( 'Approuver', 'ufsc-licence-competition' ),
			'reject'  => __( 'Rejeter', 'ufsc-licence-competition' ),
			'trash'   => __( 'Corbeille', 'ufsc-licence-competition' ),
		);

		if ( 'trash' === $filters['review_status'] ) {
			$actions['restore'] = __( 'Restaurer', 'ufsc-licence-competition' );
		}

		$actions['save_alias'] = __( 'Créer alias pour sélection', 'ufsc-licence-competition' );

		return $actions;
	}

	public function no_items() {
		esc_html_e( 'Aucune ligne en attente.', 'ufsc-licence-competition' );
	}

	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="document[]" value="%d" />',
			(int) $item->document_id
		);
	}

	public function column_club( $item ) {
		$club_label = $item->club_name ? $item->club_name : __( '—', 'ufsc-licence-competition' );
		return sprintf(
			'%s <code>#%d</code>',
			esc_html( $club_label ),
			(int) $item->club_id
		);
	}

	public function column_confidence_score( $item ) {
		$score = isset( $item->confidence_score ) ? (int) $item->confidence_score : 0;
		return sprintf(
			'<span class="ufsc-badge ufsc-badge--info">%d</span>',
			esc_html( $score )
		);
	}

	public function column_resolution( $item ) {
		$club_resolution   = ! empty( $item->club_resolution ) ? $item->club_resolution : 'none';
		$person_resolution = ! empty( $item->person_resolution ) ? $item->person_resolution : 'none';

		return sprintf(
			'%s / %s',
			esc_html( $club_resolution ),
			esc_html( $person_resolution )
		);
	}

	public function column_review_status( $item ) {
		$status = ! empty( $item->review_status ) ? $item->review_status : 'pending';
		$labels = array(
			'pending'  => __( 'En attente', 'ufsc-licence-competition' ),
			'approved' => __( 'Approuvé', 'ufsc-licence-competition' ),
			'rejected' => __( 'Rejeté', 'ufsc-licence-competition' ),
			'trash'    => __( 'Corbeille', 'ufsc-licence-competition' ),
		);
		$badge_class = 'pending' === $status ? 'ufsc-badge--warning' : 'ufsc-badge--muted';
		if ( 'approved' === $status ) {
			$badge_class = 'ufsc-badge--success';
		} elseif ( 'rejected' === $status ) {
			$badge_class = 'ufsc-badge--danger';
		}

		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;

		return sprintf(
			'<span class="ufsc-badge %s">%s</span>',
			esc_attr( $badge_class ),
			esc_html( $label )
		);
	}

	public function column_actions( $item ) {
		$actions = array();
		$base_url = admin_url( 'admin-post.php' );
		$status = ! empty( $item->review_status ) ? $item->review_status : 'pending';

		if ( 'trash' !== $status ) {
			$approve_url = add_query_arg(
				array(
					'action'       => 'ufsc_lc_asptt_review_approve',
					'document_id'  => (int) $item->document_id,
					'redirect_to'  => $this->get_current_url(),
				),
				$base_url
			);
			$approve_url = wp_nonce_url( $approve_url, 'ufsc_lc_asptt_review_approve_' . (int) $item->document_id );

			$reject_url = add_query_arg(
				array(
					'action'       => 'ufsc_lc_asptt_review_reject',
					'document_id'  => (int) $item->document_id,
					'redirect_to'  => $this->get_current_url(),
				),
				$base_url
			);
			$reject_url = wp_nonce_url( $reject_url, 'ufsc_lc_asptt_review_reject_' . (int) $item->document_id );

			$actions['approve'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $approve_url ),
				esc_html__( 'Valider', 'ufsc-licence-competition' )
			);
			$actions['reject'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $reject_url ),
				esc_html__( 'Rejeter', 'ufsc-licence-competition' )
			);
		}

		if ( ! empty( $item->asptt_club_note ) ) {
			$alias_url = add_query_arg(
				array(
					'action'       => 'ufsc_lc_asptt_review_save_alias',
					'document_id'  => (int) $item->document_id,
					'redirect_to'  => $this->get_current_url(),
				),
				$base_url
			);
			$alias_url = wp_nonce_url( $alias_url, 'ufsc_lc_asptt_review_save_alias_' . (int) $item->document_id );
			$actions['save_alias'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $alias_url ),
				esc_html__( 'Enregistrer alias', 'ufsc-licence-competition' )
			);
		}

		if ( 'trash' !== $status ) {
			$choose_url = add_query_arg(
				array(
					'page'        => 'ufsc-lc-import-asptt',
					'tab'         => 'review',
					'choose_club' => 1,
					'document_id' => (int) $item->document_id,
				),
				admin_url( 'admin.php' )
			);
			$actions['choose_club'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $choose_url ),
				esc_html__( 'Choisir club', 'ufsc-licence-competition' )
			);
		}

		if ( 'trash' === $status ) {
			$restore_url = add_query_arg(
				array(
					'action'       => 'ufsc_lc_asptt_restore',
					'document_id'  => (int) $item->document_id,
					'redirect_to'  => $this->get_current_url(),
				),
				$base_url
			);
			$restore_url = wp_nonce_url( $restore_url, 'ufsc_lc_asptt_review_restore_' . (int) $item->document_id );
			$actions['restore'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $restore_url ),
				esc_html__( 'Restaurer', 'ufsc-licence-competition' )
			);

			if ( current_user_can( 'manage_options' ) ) {
				$delete_url = add_query_arg(
					array(
						'action'       => 'ufsc_lc_asptt_delete',
						'document_id'  => (int) $item->document_id,
						'redirect_to'  => $this->get_current_url(),
					),
					$base_url
				);
				$delete_url = wp_nonce_url( $delete_url, 'ufsc_lc_asptt_review_delete_' . (int) $item->document_id );
				$actions['delete'] = sprintf(
					'<a href="%s" class="ufsc-confirm-delete">%s</a>',
					esc_url( $delete_url ),
					esc_html__( 'Supprimer définitivement', 'ufsc-licence-competition' )
				);
			}
		} else {
			$trash_url = add_query_arg(
				array(
					'action'       => 'ufsc_lc_asptt_trash',
					'document_id'  => (int) $item->document_id,
					'redirect_to'  => $this->get_current_url(),
				),
				$base_url
			);
			$trash_url = wp_nonce_url( $trash_url, 'ufsc_lc_asptt_review_trash_' . (int) $item->document_id );
			$actions['trash'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $trash_url ),
				esc_html__( 'Corbeille', 'ufsc-licence-competition' )
			);
		}

		return $this->row_actions( $actions, true );
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'nom_licence':
			case 'prenom':
			case 'date_naissance':
			case 'asptt_number':
			case 'season_end_year':
			case 'category':
				return ! empty( $item->{$column_name} ) ? esc_html( $item->{$column_name} ) : esc_html__( '—', 'ufsc-licence-competition' );
			case 'actions':
				return $this->column_actions( $item );
		}

		return '';
	}

	public function get_views() {
		$current = $this->get_sanitized_filters();
		$tabs = array(
			'all'      => __( 'Tous', 'ufsc-licence-competition' ),
			'pending'  => __( 'En attente', 'ufsc-licence-competition' ),
			'approved' => __( 'Approuvés', 'ufsc-licence-competition' ),
			'rejected' => __( 'Rejetés', 'ufsc-licence-competition' ),
			'trash'    => __( 'Corbeille', 'ufsc-licence-competition' ),
		);

		$views = array();
		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page'         => 'ufsc-lc-import-asptt',
					'tab'          => 'review',
					'review_status' => $slug,
				),
				admin_url( 'admin.php' )
			);
			$class = $current['review_status'] === $slug ? 'current' : '';
			$views[ $slug ] = sprintf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label )
			);
		}

		return $views;
	}

	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$filters = $this->get_sanitized_filters();
		?>
		<div class="alignleft actions">
			<label for="ufsc-review-club" class="screen-reader-text"><?php esc_html_e( 'Filtrer par club', 'ufsc-licence-competition' ); ?></label>
			<select name="review_club" id="ufsc-review-club">
				<option value=""><?php esc_html_e( 'Tous les clubs', 'ufsc-licence-competition' ); ?></option>
				<?php foreach ( $this->clubs as $club ) : ?>
					<option value="<?php echo esc_attr( $club->id ); ?>" <?php selected( $filters['club_id'], (int) $club->id ); ?>>
						<?php echo esc_html( $club->nom ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="ufsc-review-score" class="screen-reader-text"><?php esc_html_e( 'Score minimum', 'ufsc-licence-competition' ); ?></label>
			<input type="number" name="review_score_min" id="ufsc-review-score" min="0" max="100" value="<?php echo esc_attr( $filters['score_min'] ); ?>" placeholder="<?php esc_attr_e( 'Score min', 'ufsc-licence-competition' ); ?>">

			<?php submit_button( __( 'Filtrer', 'ufsc-licence-competition' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function prepare_items() {
		global $wpdb;

		$this->filters = $this->get_sanitized_filters();

		$per_page = $this->filters['per_page'];
		$current_page = $this->get_pagenum();
		$offset = ( $current_page - 1 ) * $per_page;

		$documents_table = $wpdb->prefix . 'ufsc_licence_documents';
		$meta_table      = $wpdb->prefix . 'ufsc_licence_documents_meta';
		$licences_table  = $wpdb->prefix . 'ufsc_licences';
		$clubs_table     = $wpdb->prefix . 'ufsc_clubs';

		$joins = "FROM {$documents_table} docs
			INNER JOIN {$licences_table} licences ON licences.id = docs.licence_id
			INNER JOIN {$clubs_table} clubs ON clubs.id = licences.club_id
			LEFT JOIN {$meta_table} meta_conf ON meta_conf.licence_id = docs.licence_id AND meta_conf.source = docs.source AND meta_conf.meta_key = 'confidence_score'
			LEFT JOIN {$meta_table} meta_link ON meta_link.licence_id = docs.licence_id AND meta_link.source = docs.source AND meta_link.meta_key = 'link_mode'
			LEFT JOIN {$meta_table} meta_review ON meta_review.licence_id = docs.licence_id AND meta_review.source = docs.source AND meta_review.meta_key = 'review_status'
			LEFT JOIN {$meta_table} meta_club ON meta_club.licence_id = docs.licence_id AND meta_club.source = docs.source AND meta_club.meta_key = 'club_resolution'
			LEFT JOIN {$meta_table} meta_person ON meta_person.licence_id = docs.licence_id AND meta_person.source = docs.source AND meta_person.meta_key = 'person_resolution'";

		$where = array( "docs.source = %s" );
		$params = array( 'ASPTT' );

		if ( $this->filters['club_id'] ) {
			$where[] = 'clubs.id = %d';
			$params[] = $this->filters['club_id'];
		}

		if ( 'all' !== $this->filters['review_status'] ) {
			if ( 'pending' === $this->filters['review_status'] ) {
				$where[] = "(meta_review.meta_value = %s OR meta_review.meta_value IS NULL OR meta_review.meta_value = '')";
				$params[] = 'pending';
			} else {
				$where[] = 'meta_review.meta_value = %s';
				$params[] = $this->filters['review_status'];
			}
		}

		if ( $this->filters['score_min'] ) {
			$where[] = 'CAST(meta_conf.meta_value AS UNSIGNED) >= %d';
			$params[] = $this->filters['score_min'];
		}

		if ( $this->filters['search'] ) {
			$search_like = '%' . $wpdb->esc_like( $this->filters['search'] ) . '%';
			$where[] = '(licences.nom_licence LIKE %s OR licences.prenom LIKE %s OR docs.source_licence_number LIKE %s)';
			$params[] = $search_like;
			$params[] = $search_like;
			$params[] = $search_like;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$order_by = $this->get_orderby_sql();
		$order = 'ASC' === $this->filters['order'] ? 'ASC' : 'DESC';

		$count_sql = "SELECT COUNT(DISTINCT docs.id) {$joins} {$where_sql}";
		$total_items = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		$sql = "SELECT
			docs.id AS document_id,
			docs.licence_id,
			docs.source_licence_number AS asptt_number,
			docs.asptt_club_note,
			licences.nom_licence,
			licences.prenom,
			licences.date_naissance,
			licences.sexe,
			licences.season_end_year,
			licences.category,
			clubs.id AS club_id,
			clubs.nom AS club_name,
			meta_conf.meta_value AS confidence_score,
			meta_link.meta_value AS link_mode,
			meta_review.meta_value AS review_status,
			meta_club.meta_value AS club_resolution,
			meta_person.meta_value AS person_resolution
			{$joins}
			{$where_sql}
			ORDER BY {$order_by} {$order}
			LIMIT %d OFFSET %d";

		$params[] = $per_page;
		$params[] = $offset;

		$this->items = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	public function get_sanitized_filters() {
		$review_status = isset( $_REQUEST['review_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['review_status'] ) ) : 'pending';
		$allowed_status = array( 'pending', 'approved', 'rejected', 'all', 'trash' );
		if ( ! in_array( $review_status, $allowed_status, true ) ) {
			$review_status = 'pending';
		}

		$club_id = isset( $_REQUEST['review_club'] ) ? absint( $_REQUEST['review_club'] ) : 0;
		$score_min = isset( $_REQUEST['review_score_min'] ) ? absint( $_REQUEST['review_score_min'] ) : 0;
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'confidence_score';
		$order = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : 'DESC';
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$per_page = isset( $_REQUEST['per_page'] ) ? absint( $_REQUEST['per_page'] ) : 20;
		if ( ! in_array( $per_page, array( 20, 50, 100 ), true ) ) {
			$per_page = 20;
		}

		return array(
			'review_status' => $review_status,
			'club_id'       => $club_id,
			'score_min'     => $score_min,
			'search'        => $search,
			'orderby'       => $orderby,
			'order'         => $order,
			'per_page'      => $per_page,
		);
	}

	private function get_orderby_sql() {
		$orderby = $this->filters['orderby'];
		switch ( $orderby ) {
			case 'nom_licence':
				return 'licences.nom_licence';
			case 'club_name':
				return 'clubs.nom';
			case 'confidence_score':
			default:
				return 'CAST(meta_conf.meta_value AS UNSIGNED)';
		}
	}

	private function get_current_url() {
		$params = array(
			'page'          => 'ufsc-lc-import-asptt',
			'tab'           => 'review',
			'review_status' => $this->filters['review_status'],
		);

		if ( $this->filters['club_id'] ) {
			$params['review_club'] = $this->filters['club_id'];
		}
		if ( $this->filters['score_min'] ) {
			$params['review_score_min'] = $this->filters['score_min'];
		}
		if ( $this->filters['search'] ) {
			$params['s'] = $this->filters['search'];
		}

		return add_query_arg( $params, admin_url( 'admin.php' ) );
	}
}
