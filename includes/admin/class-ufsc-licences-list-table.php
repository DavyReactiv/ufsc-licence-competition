<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class UFSC_LC_Competition_Licences_List_Table extends WP_List_Table {
	private $has_created_at = false;
	private $has_documents_table = false;
	private $has_source_created_at = false;
	private $has_internal_note = false;
	private $has_documents_meta_table = false;
	private $notices = array();
	
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'licence',
				'plural'   => 'licences',
				'ajax'     => false,
			)
		);
	
		$this->has_created_at        = $this->has_column( $this->get_licences_table(), 'created_at' );
		$this->has_documents_table   = $this->table_exists( $this->get_documents_table() );
		$this->has_source_created_at = $this->has_documents_table && $this->has_column( $this->get_documents_table(), 'source_created_at' );
		$this->has_internal_note     = $this->has_column( $this->get_licences_table(), 'note_interne' );
		$this->has_documents_meta_table = $this->table_exists( $this->get_documents_meta_table() );
	}
	
	public function get_columns() {
		$columns = array(
			'cb'            => '<input type="checkbox" />',
			'club_name'     => __( 'Club', 'ufsc-licence-competition' ),
			'nom_licence'   => __( 'Nom', 'ufsc-licence-competition' ),
			'prenom'        => __( 'Prénom', 'ufsc-licence-competition' ),
			'date_naissance'=> __( 'Date naissance', 'ufsc-licence-competition' ),
			'statut'        => __( 'Statut', 'ufsc-licence-competition' ),
			'categorie'     => __( 'Catégorie', 'ufsc-licence-competition' ),
			'competition'   => __( 'Compétition', 'ufsc-licence-competition' ),
			'asptt_number'  => __( 'N° ASPTT', 'ufsc-licence-competition' ),
			'date_asptt'    => __( 'Date ASPTT', 'ufsc-licence-competition' ),
			'pdf'           => __( 'PDF', 'ufsc-licence-competition' ),
		);
	
		if ( $this->has_created_at ) {
			$columns['created_at'] = __( 'Date création', 'ufsc-licence-competition' );
		}
	
		return $columns;
	}
	
	public function get_sortable_columns() {
		$columns = array(
			'nom_licence'   => array( 'nom_licence', true ),
			'prenom'        => array( 'prenom', true ),
			'statut'        => array( 'statut', false ),
			'categorie'     => array( 'categorie', false ),
			'date_naissance'=> array( 'date_naissance', false ),
		);

		if ( $this->has_source_created_at ) {
			$columns['date_asptt'] = array( 'source_created_at', false );
		}

		return $columns;
	}

	public function get_bulk_actions() {
		return array(
			'ufsc_lc_mark_review' => __( 'Marquer à vérifier', 'ufsc-licence-competition' ),
			'ufsc_lc_remove_pdf'  => __( 'Supprimer association PDF', 'ufsc-licence-competition' ),
		);
	}

	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="%s[]" value="%d" />',
			esc_attr( $this->_args['singular'] ),
			(int) $item->id
		);
	}

	public function get_views() {
		$tabs = $this->get_tabs();
		$views = array();
		$current = $this->get_current_tab();

		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg( $this->get_view_query_args( $slug ), admin_url( 'admin.php' ) );
			$views[ $slug ] = sprintf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $url ),
				$slug === $current ? 'current' : '',
				esc_html( $label )
			);
		}

		return $views;
	}
	
	public function no_items() {
		esc_html_e( 'Aucune licence trouvée.', 'ufsc-licence-competition' );
	}
	
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'club_name':
			case 'nom_licence':
			case 'prenom':
			case 'date_naissance':
			case 'statut':
			case 'categorie':
			case 'asptt_number':
			case 'created_at':
				return $item->{$column_name} ? esc_html( $item->{$column_name} ) : '&mdash;';
			case 'date_asptt':
				return $this->format_date_asptt( $item->date_asptt );
			case 'competition':
				return esc_html( $this->format_competition( $item->competition ) );
			case 'pdf':
				if ( ! empty( $item->attachment_id ) ) {
					return sprintf(
						'<a href="%s">%s</a>',
						esc_url( $this->get_download_url( (int) $item->id ) ),
						esc_html__( 'Télécharger', 'ufsc-licence-competition' )
					);
				}
	
				return '&mdash;';
			default:
				return '';
		}
	}

	public function get_notices() {
		return $this->notices;
	}

	public function get_sanitized_filters() {
		$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 25;
		$per_page = in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 25;

		$orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'nom_licence';
		$order   = isset( $_GET['order'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) : 'asc';

		$allowed_orderby = array( 'nom_licence', 'prenom', 'statut', 'categorie', 'date_naissance' );
		if ( $this->has_source_created_at ) {
			$allowed_orderby[] = 'source_created_at';
		}

		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'nom_licence';
		}

		$order = 'desc' === $order ? 'DESC' : 'ASC';

		$pdf_filter = isset( $_GET['pdf_filter'] ) ? sanitize_key( wp_unslash( $_GET['pdf_filter'] ) ) : '';
		$pdf_filter = in_array( $pdf_filter, array( 'with', 'without' ), true ) ? $pdf_filter : '';

		$tab = isset( $_GET['ufsc_lc_tab'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_lc_tab'] ) ) : 'all';
		$tabs = array_keys( $this->get_tabs() );
		if ( ! in_array( $tab, $tabs, true ) ) {
			$tab = 'all';
		}

		return array(
			'per_page'    => $per_page,
			'orderby'     => $orderby,
			'order'       => $order,
			'search'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'club_id'     => isset( $_GET['club_id'] ) ? absint( $_GET['club_id'] ) : 0,
			'club_search' => isset( $_GET['club_search'] ) ? sanitize_text_field( wp_unslash( $_GET['club_search'] ) ) : '',
			'statut'      => isset( $_GET['statut'] ) ? sanitize_text_field( wp_unslash( $_GET['statut'] ) ) : '',
			'categorie'   => isset( $_GET['categorie'] ) ? sanitize_text_field( wp_unslash( $_GET['categorie'] ) ) : '',
			'competition' => isset( $_GET['competition'] ) ? sanitize_text_field( wp_unslash( $_GET['competition'] ) ) : '',
			'pdf_filter'  => $pdf_filter,
			'tab'         => $tab,
		);
	}

	public function get_filter_query_args() {
		$filters = $this->get_sanitized_filters();
		$args = array(
			'page'        => 'ufsc-licences',
			's'           => $filters['search'],
			'club_id'     => $filters['club_id'],
			'club_search' => $filters['club_search'],
			'statut'      => $filters['statut'],
			'categorie'   => $filters['categorie'],
			'competition' => $filters['competition'],
			'pdf_filter'  => $filters['pdf_filter'],
			'ufsc_lc_tab' => $filters['tab'],
			'orderby'     => $filters['orderby'],
			'order'       => strtolower( $filters['order'] ),
			'per_page'    => $filters['per_page'],
		);

		return array_filter(
			$args,
			static function ( $value ) {
				return '' !== $value && null !== $value && false !== $value;
			}
		);
	}

	private function get_tabs() {
		return array(
			'all'           => __( 'Toutes', 'ufsc-licence-competition' ),
			'with_pdf'      => __( 'Avec PDF', 'ufsc-licence-competition' ),
			'without_pdf'   => __( 'Sans PDF', 'ufsc-licence-competition' ),
			'with_asptt'    => __( 'Avec N° ASPTT', 'ufsc-licence-competition' ),
			'without_asptt' => __( 'Sans N° ASPTT', 'ufsc-licence-competition' ),
			'recent_asptt'  => __( 'Date ASPTT (30 derniers jours)', 'ufsc-licence-competition' ),
		);
	}

	private function get_current_tab() {
		$filters = $this->get_sanitized_filters();

		return $filters['tab'];
	}

	private function get_view_query_args( $tab ) {
		$args = $this->get_filter_query_args();
		$args['ufsc_lc_tab'] = $tab;

		return $args;
	}
	
		public function extra_tablenav( $which ) {
			if ( 'top' !== $which ) {
				return;
			}
			$filters = $this->get_sanitized_filters();
			$club_id     = $filters['club_id'];
			$club_search = $filters['club_search'];
			$statut      = $filters['statut'];
			$categorie   = $filters['categorie'];
			$competition = $filters['competition'];
			$per_page    = $filters['per_page'];
			$pdf_filter  = $filters['pdf_filter'];
	
			echo '<div class="alignleft actions">';
			echo '<label class="screen-reader-text" for="ufsc-club-filter">' . esc_html__( 'Filtrer par club', 'ufsc-licence-competition' ) . '</label>';
			echo '<select name="club_id" id="ufsc-club-filter">';
			echo '<option value="">' . esc_html__( 'Tous les clubs', 'ufsc-licence-competition' ) . '</option>';
			foreach ( $this->get_clubs() as $club ) {
				printf(
					'<option value="%d" %s>%s</option>',
					(int) $club->id,
					selected( $club_id, (int) $club->id, false ),
					esc_html( $club->nom )
				);
			}
			echo '</select>';
	
			echo '<label class="screen-reader-text" for="ufsc-club-search">' . esc_html__( 'Recherche club', 'ufsc-licence-competition' ) . '</label>';
			printf(
				'<input type="text" name="club_search" id="ufsc-club-search" value="%s" placeholder="%s" class="regular-text" />',
				esc_attr( $club_search ),
				esc_attr__( 'Recherche club…', 'ufsc-licence-competition' )
			);
	
			echo '<label class="screen-reader-text" for="ufsc-status-filter">' . esc_html__( 'Filtrer par statut', 'ufsc-licence-competition' ) . '</label>';
			echo '<select name="statut" id="ufsc-status-filter">';
			echo '<option value="">' . esc_html__( 'Tous les statuts', 'ufsc-licence-competition' ) . '</option>';
			foreach ( $this->get_distinct_values( 'statut' ) as $value ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $value ),
					selected( $statut, $value, false ),
					esc_html( $value )
				);
			}
			echo '</select>';
	
			echo '<label class="screen-reader-text" for="ufsc-category-filter">' . esc_html__( 'Filtrer par catégorie', 'ufsc-licence-competition' ) . '</label>';
			echo '<select name="categorie" id="ufsc-category-filter">';
			echo '<option value="">' . esc_html__( 'Toutes les catégories', 'ufsc-licence-competition' ) . '</option>';
			foreach ( $this->get_distinct_values( 'categorie' ) as $value ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $value ),
					selected( $categorie, $value, false ),
					esc_html( $value )
				);
			}
			echo '</select>';
	
			echo '<label class="screen-reader-text" for="ufsc-competition-filter">' . esc_html__( 'Filtrer par compétition', 'ufsc-licence-competition' ) . '</label>';
			echo '<select name="competition" id="ufsc-competition-filter">';
			echo '<option value="">' . esc_html__( 'Toutes les compétitions', 'ufsc-licence-competition' ) . '</option>';
			foreach ( $this->get_distinct_values( 'competition' ) as $value ) {
				$label = $this->format_competition( $value );
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $value ),
					selected( $competition, $value, false ),
					esc_html( $label )
				);
			}
			echo '</select>';

			echo '<label class="screen-reader-text" for="ufsc-pdf-filter">' . esc_html__( 'Filtrer par PDF', 'ufsc-licence-competition' ) . '</label>';
			echo '<select name="pdf_filter" id="ufsc-pdf-filter">';
			echo '<option value="">' . esc_html__( 'Avec/Sans PDF', 'ufsc-licence-competition' ) . '</option>';
			printf(
				'<option value="with" %s>%s</option>',
				selected( $pdf_filter, 'with', false ),
				esc_html__( 'Avec PDF', 'ufsc-licence-competition' )
			);
			printf(
				'<option value="without" %s>%s</option>',
				selected( $pdf_filter, 'without', false ),
				esc_html__( 'Sans PDF', 'ufsc-licence-competition' )
			);
			echo '</select>';
	
			echo '<label class="screen-reader-text" for="ufsc-per-page">' . esc_html__( 'Par page', 'ufsc-licence-competition' ) . '</label>';
			echo '<select name="per_page" id="ufsc-per-page">';
			foreach ( array( 25, 50, 100 ) as $option ) {
				printf(
					'<option value="%d" %s>%d</option>',
					(int) $option,
					selected( $per_page, (int) $option, false ),
					(int) $option
				);
			}
			echo '</select>';
	
			submit_button( __( 'Filtrer', 'ufsc-licence-competition' ), 'secondary', 'filter_action', false );
			echo '</div>';
		}
	
	public function prepare_items() {
		global $wpdb;

		$this->process_bulk_action();

		$filters = $this->get_sanitized_filters();
		$per_page = $filters['per_page'];
		$current_page = max( 1, (int) $this->get_pagenum() );
		$orderby = $filters['orderby'];
		$order = $filters['order'];
		$search = $filters['search'];
		$club_id = $filters['club_id'];
		$club_search = $filters['club_search'];
		$statut = $filters['statut'];
		$categorie = $filters['categorie'];
		$competition = $filters['competition'];
		$pdf_filter = $filters['pdf_filter'];
		$tab = $filters['tab'];

		$licences_table  = $this->get_licences_table();
		$clubs_table     = $this->get_clubs_table();
		$documents_table = $this->get_documents_table();

		$where  = array();
		$params = array();

		$search_like = '';
		if ( '' !== $search ) {
			$search_like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(l.nom_licence LIKE %s OR l.prenom LIKE %s OR l.email LIKE %s OR c.nom LIKE %s)';
			$params = array_merge( $params, array( $search_like, $search_like, $search_like, $search_like ) );
		}

		if ( $club_id ) {
			$where[] = 'l.club_id = %d';
			$params[] = $club_id;
		}

		if ( '' !== $club_search ) {
			$club_like = '%' . $wpdb->esc_like( $club_search ) . '%';
			$where[] = 'c.nom LIKE %s';
			$params[] = $club_like;
		}

		if ( '' !== $statut ) {
			$where[] = 'l.statut = %s';
			$params[] = $statut;
		}

		if ( '' !== $categorie ) {
			$where[] = 'l.categorie = %s';
			$params[] = $categorie;
		}

		if ( '' !== $competition ) {
			$where[] = 'l.competition = %s';
			$params[] = $competition;
		}

		$join_documents = '';
		$document_params = array();
		$select_documents = 'NULL AS asptt_number, NULL AS date_asptt, NULL AS attachment_id';

		if ( $this->has_documents_table ) {
			$join_documents = "LEFT JOIN {$documents_table} d ON d.licence_id = l.id AND d.source = %s";
			$document_params = array( 'ASPTT' );
			$select_documents = 'd.source_licence_number AS asptt_number, d.source_created_at AS date_asptt, d.attachment_id';
			if ( '' !== $search ) {
				$where[] = 'd.source_licence_number LIKE %s';
				$params[] = $search_like;
			}

			if ( 'with' === $pdf_filter ) {
				$where[] = 'd.attachment_id IS NOT NULL';
			} elseif ( 'without' === $pdf_filter ) {
				$where[] = 'd.attachment_id IS NULL';
			}

			switch ( $tab ) {
				case 'with_pdf':
					$where[] = 'd.attachment_id IS NOT NULL';
					break;
				case 'without_pdf':
					$where[] = 'd.attachment_id IS NULL';
					break;
				case 'with_asptt':
					$where[] = "d.source_licence_number IS NOT NULL AND d.source_licence_number != ''";
					break;
				case 'without_asptt':
					$where[] = "(d.source_licence_number IS NULL OR d.source_licence_number = '')";
					break;
				case 'recent_asptt':
					if ( $this->has_source_created_at ) {
						$cutoff = gmdate( 'Y-m-d H:i:s', (int) ( current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) );
						$where[] = 'd.source_created_at >= %s';
						$params[] = $cutoff;
					}
					break;
			}
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		$select_columns = "l.id, l.club_id, l.nom_licence, l.prenom, l.date_naissance, l.statut, l.categorie, l.competition, {$select_documents}, c.nom AS club_name";
		if ( $this->has_created_at ) {
			$select_columns .= ', l.created_at';
		}

		$orderby_sql = 'source_created_at' === $orderby && $this->has_source_created_at ? 'd.source_created_at' : 'l.' . $orderby;

		$count_sql = "SELECT COUNT(*) FROM {$licences_table} l
			LEFT JOIN {$clubs_table} c ON c.id = l.club_id
			{$join_documents}
			{$where_sql}";

		$items_sql = "SELECT {$select_columns}
			FROM {$licences_table} l
			LEFT JOIN {$clubs_table} c ON c.id = l.club_id
			{$join_documents}
			{$where_sql}
			ORDER BY {$orderby_sql} {$order}
			LIMIT %d OFFSET %d";

		$count_params = array_merge( $document_params, $params );
		$total_items  = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) );

		$offset = ( $current_page - 1 ) * $per_page;
		$item_params = array_merge( $document_params, $params, array( $per_page, $offset ) );
		$this->items = $wpdb->get_results( $wpdb->prepare( $items_sql, $item_params ) );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	public function get_export_rows( $filters ) {
		global $wpdb;

		$licences_table  = $this->get_licences_table();
		$clubs_table     = $this->get_clubs_table();
		$documents_table = $this->get_documents_table();

		$search      = $filters['search'];
		$club_id     = $filters['club_id'];
		$club_search = $filters['club_search'];
		$statut      = $filters['statut'];
		$categorie   = $filters['categorie'];
		$competition = $filters['competition'];
		$pdf_filter  = $filters['pdf_filter'];
		$tab         = $filters['tab'];
		$orderby     = $filters['orderby'];
		$order       = $filters['order'];

		$where  = array();
		$params = array();

		$search_like = '';
		if ( '' !== $search ) {
			$search_like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(l.nom_licence LIKE %s OR l.prenom LIKE %s OR l.email LIKE %s OR c.nom LIKE %s)';
			$params = array_merge( $params, array( $search_like, $search_like, $search_like, $search_like ) );
		}

		if ( $club_id ) {
			$where[] = 'l.club_id = %d';
			$params[] = $club_id;
		}

		if ( '' !== $club_search ) {
			$club_like = '%' . $wpdb->esc_like( $club_search ) . '%';
			$where[] = 'c.nom LIKE %s';
			$params[] = $club_like;
		}

		if ( '' !== $statut ) {
			$where[] = 'l.statut = %s';
			$params[] = $statut;
		}

		if ( '' !== $categorie ) {
			$where[] = 'l.categorie = %s';
			$params[] = $categorie;
		}

		if ( '' !== $competition ) {
			$where[] = 'l.competition = %s';
			$params[] = $competition;
		}

		$join_documents = '';
		$document_params = array();
		$select_documents = 'NULL AS asptt_number, NULL AS date_asptt, 0 AS has_pdf';

		if ( $this->has_documents_table ) {
			$join_documents = "LEFT JOIN {$documents_table} d ON d.licence_id = l.id AND d.source = %s";
			$document_params = array( 'ASPTT' );
			$select_documents = 'd.source_licence_number AS asptt_number, d.source_created_at AS date_asptt, CASE WHEN d.attachment_id IS NULL THEN 0 ELSE 1 END AS has_pdf';
			if ( '' !== $search ) {
				$where[] = 'd.source_licence_number LIKE %s';
				$params[] = $search_like;
			}

			if ( 'with' === $pdf_filter ) {
				$where[] = 'd.attachment_id IS NOT NULL';
			} elseif ( 'without' === $pdf_filter ) {
				$where[] = 'd.attachment_id IS NULL';
			}

			switch ( $tab ) {
				case 'with_pdf':
					$where[] = 'd.attachment_id IS NOT NULL';
					break;
				case 'without_pdf':
					$where[] = 'd.attachment_id IS NULL';
					break;
				case 'with_asptt':
					$where[] = "d.source_licence_number IS NOT NULL AND d.source_licence_number != ''";
					break;
				case 'without_asptt':
					$where[] = "(d.source_licence_number IS NULL OR d.source_licence_number = '')";
					break;
				case 'recent_asptt':
					if ( $this->has_source_created_at ) {
						$cutoff = gmdate( 'Y-m-d H:i:s', (int) ( current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) );
						$where[] = 'd.source_created_at >= %s';
						$params[] = $cutoff;
					}
					break;
			}
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		$select_columns = "c.nom AS club_name, l.nom_licence, l.prenom, l.date_naissance, l.statut, l.categorie, l.competition, {$select_documents}";

		$orderby_sql = 'source_created_at' === $orderby && $this->has_source_created_at ? 'd.source_created_at' : 'l.' . $orderby;

		$sql = "SELECT {$select_columns}
			FROM {$licences_table} l
			LEFT JOIN {$clubs_table} c ON c.id = l.club_id
			{$join_documents}
			{$where_sql}
			ORDER BY {$orderby_sql} {$order}";

		$query_params = array_merge( $document_params, $params );

		return $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A );
	}

	private function process_bulk_action() {
		$action = $this->current_action();
		if ( ! $action ) {
			return;
		}

		$licence_ids = isset( $_REQUEST[ $this->_args['singular'] ] ) ? array_map( 'absint', (array) $_REQUEST[ $this->_args['singular'] ] ) : array();
		$licence_ids = array_filter( $licence_ids );

		if ( empty( $licence_ids ) ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		switch ( $action ) {
			case 'ufsc_lc_mark_review':
				$this->handle_bulk_mark_review( $licence_ids );
				break;
			case 'ufsc_lc_remove_pdf':
				$this->handle_bulk_remove_pdf( $licence_ids );
				break;
		}
	}

	private function handle_bulk_mark_review( array $licence_ids ) {
		global $wpdb;

		if ( $this->has_internal_note ) {
			$licences_table = $this->get_licences_table();
			$placeholders = implode( ',', array_fill( 0, count( $licence_ids ), '%d' ) );
			$params = array_merge( array( 'À vérifier' ), $licence_ids );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$licences_table} SET note_interne = %s WHERE id IN ({$placeholders})",
					$params
				)
			);
			$this->add_notice( 'success', __( 'Licences marquées à vérifier.', 'ufsc-licence-competition' ) );
			return;
		}

		if ( ! $this->has_documents_meta_table ) {
			$this->add_notice( 'error', __( 'Action impossible : table meta des documents manquante.', 'ufsc-licence-competition' ) );
			return;
		}

		$meta_table = $this->get_documents_meta_table();
		$now = gmdate( 'Y-m-d H:i:s' );
		$values = array();
		$params = array();

		foreach ( $licence_ids as $licence_id ) {
			$values[] = '( %d, %s, %s, %s, %s )';
			$params[] = $licence_id;
			$params[] = 'ASPTT';
			$params[] = 'status';
			$params[] = 'review';
			$params[] = $now;
		}

		$sql = "INSERT INTO {$meta_table} (licence_id, source, meta_key, meta_value, updated_at)
			VALUES " . implode( ', ', $values ) . '
			ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = VALUES(updated_at)';

		$wpdb->query( $wpdb->prepare( $sql, $params ) );
		$this->add_notice( 'success', __( 'Licences marquées à vérifier.', 'ufsc-licence-competition' ) );
	}

	private function handle_bulk_remove_pdf( array $licence_ids ) {
		global $wpdb;

		if ( ! $this->has_documents_table ) {
			$this->add_notice( 'error', __( 'Action impossible : table des documents absente.', 'ufsc-licence-competition' ) );
			return;
		}

		$documents_table = $this->get_documents_table();
		$placeholders = implode( ',', array_fill( 0, count( $licence_ids ), '%d' ) );
		$params = array_merge( array( 'ASPTT' ), $licence_ids );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$documents_table} SET attachment_id = NULL WHERE source = %s AND licence_id IN ({$placeholders})",
				$params
			)
		);

		$this->add_notice( 'success', __( 'Associations PDF supprimées.', 'ufsc-licence-competition' ) );
	}

	private function add_notice( $type, $message ) {
		$this->notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
	}
	
	private function get_download_url( $licence_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'ufsc_lc_download_asptt_pdf',
					'licence_id' => (int) $licence_id,
				),
				admin_url( 'admin-post.php' )
			),
			'ufsc_lc_download_asptt_pdf_' . (int) $licence_id
		);
	}
	
		private function format_competition( $value ) {
			if ( null === $value || '' === $value ) {
				return '—';
			}
	
			if ( is_numeric( $value ) ) {
				return (int) $value ? __( 'Compétition', 'ufsc-licence-competition' ) : __( 'Loisir', 'ufsc-licence-competition' );
			}
	
			return (string) $value;
		}

		private function format_date_asptt( $value ) {
			if ( null === $value || '' === $value ) {
				return '&mdash;';
			}

			$formatted = mysql2date( 'd/m/Y H:i', $value );
			if ( '' === $formatted ) {
				return esc_html( $value );
			}

			return esc_html( $formatted );
		}
	
		private function get_clubs() {
			global $wpdb;
	
			$table = $this->get_clubs_table();
	
			return $wpdb->get_results( "SELECT id, nom FROM {$table} ORDER BY nom ASC" );
		}
	
		private function get_distinct_values( $column ) {
			global $wpdb;
	
			$allowed = array( 'statut', 'categorie', 'competition' );
			if ( ! in_array( $column, $allowed, true ) ) {
				return array();
			}
	
			$table = $this->get_licences_table();
			$results = $wpdb->get_col( "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} IS NOT NULL AND {$column} != '' ORDER BY {$column} ASC" );
	
			return array_filter( array_map( 'strval', $results ) );
		}
	
	private function has_column( $table, $column ) {
		global $wpdb;

		$column = sanitize_key( $column );

		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );

		return ! empty( $exists );
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

	private function table_exists( $table ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
