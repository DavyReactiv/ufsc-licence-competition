<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class UFSC_LC_Competition_Licences_List_Table extends WP_List_Table {
	const FILTERS_USER_META = 'ufsc_lc_licences_filters';

	private $has_created_at = false;
	private $has_documents_table = false;
	private $has_source_created_at = false;
	private $has_licence_number = false;
	private $has_asptt_number = false;
	private $has_internal_note = false;
	private $has_documents_meta_table = false;
	private $has_competition = false;
	private $has_email = false;
	private $has_legacy_category = false;

	// ✅ Compatibility: dynamic season column (saison|season) + explicit season_end_year.
	private $has_season_column = false;
	private $has_season_end_year = false;

	// ✅ Compatibility: category vs categorie.
	private $has_category = false;

	private $date_column = 'date_naissance';
	private $licence_number_column = 'id';

	// If table has "saison" or "season", we store it here.
	private $season_column = '';

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'licence',
				'plural'   => 'licences',
				'ajax'     => false,
			)
		);

		$this->has_created_at            = $this->has_column( $this->get_licences_table(), 'created_at' );
		$this->has_licence_number        = $this->has_column( $this->get_licences_table(), 'numero_licence_delegataire' );
		$this->has_asptt_number          = $this->has_column( $this->get_licences_table(), 'numero_licence_asptt' );
		$this->has_documents_table       = $this->table_exists( $this->get_documents_table() );
		$this->has_source_created_at     = $this->has_documents_table && $this->has_column( $this->get_documents_table(), 'source_created_at' );
		$this->has_internal_note         = $this->has_column( $this->get_licences_table(), 'note_interne' );
		$this->has_documents_meta_table  = $this->table_exists( $this->get_documents_meta_table() );
		$this->has_competition           = $this->has_column( $this->get_licences_table(), 'competition' );
		$this->has_email                 = $this->has_column( $this->get_licences_table(), 'email' );
		$this->has_legacy_category       = $this->has_column( $this->get_licences_table(), 'categorie' );

		// Season support
		$this->season_column       = $this->get_season_column(); // 'saison' or 'season' or ''
		$this->has_season_column   = '' !== $this->season_column;
		$this->has_season_end_year = $this->has_column( $this->get_licences_table(), 'season_end_year' );

		// Category support
		$this->has_category = $this->has_column( $this->get_licences_table(), 'category' );

		$this->date_column           = $this->has_created_at ? 'created_at' : 'date_naissance';
		$this->licence_number_column = $this->has_asptt_number ? 'numero_licence_asptt' : ( $this->has_licence_number ? 'numero_licence_delegataire' : 'id' );
	}

	public function get_columns() {
		// On garde l’affichage Saison/Catégorie même si vide, pour ne pas casser l’UX.
		return array(
			'cb'              => '<input type="checkbox" />',
			'licence_number'  => __( 'N° licence', 'ufsc-licence-competition' ),
			'nom_licence'     => __( 'Nom', 'ufsc-licence-competition' ),
			'prenom'          => __( 'Prénom', 'ufsc-licence-competition' ),
			'club_name'       => __( 'Club', 'ufsc-licence-competition' ),
			'statut'          => __( 'Statut', 'ufsc-licence-competition' ),
			'season_end_year' => __( 'Saison', 'ufsc-licence-competition' ),
			'category'        => __( 'Catégorie', 'ufsc-licence-competition' ),
			'date'            => __( 'Date', 'ufsc-licence-competition' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'licence_number' => array( $this->licence_number_column, false ),
			'nom_licence'    => array( 'nom_licence', true ),
			'date'           => array( $this->date_column, false ),
		);
	}

	public function get_bulk_actions() {
		$actions = array(
			'ufsc_lc_mark_review'            => __( 'Marquer à vérifier', 'ufsc-licence-competition' ),
			'ufsc_lc_remove_pdf'             => __( 'Supprimer association PDF', 'ufsc-licence-competition' ),
			'ufsc_lc_recalculate_categories' => __( 'Recalculer catégories', 'ufsc-licence-competition' ),
		);

		// Bulk season only for dynamic season string column (saison|season) to avoid type issues.
		if ( $this->has_season_column ) {
			$actions['ufsc_lc_change_season'] = __( 'Changer saison', 'ufsc-licence-competition' );
		}

		return $actions;
	}

	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="%s[]" value="%d" />',
			esc_attr( $this->_args['singular'] ),
			(int) $item->id
		);
	}

	public function get_views() {
		$tabs    = $this->get_tabs();
		$views   = array();
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
			case 'licence_number':
				return ! empty( $item->licence_number )
					? esc_html( $item->licence_number )
					: esc_html__( '—', 'ufsc-licence-competition' );

			case 'club_name':
			case 'nom_licence':
			case 'prenom':
			case 'statut':
				return ! empty( $item->{$column_name} )
					? esc_html( $item->{$column_name} )
					: esc_html__( '—', 'ufsc-licence-competition' );

			case 'season_end_year':
				return ! empty( $item->season_end_year )
					? esc_html( $item->season_end_year )
					: esc_html__( '—', 'ufsc-licence-competition' );

			case 'category':
				return ! empty( $item->category )
					? esc_html( $item->category )
					: esc_html__( '—', 'ufsc-licence-competition' );

			case 'date':
				return ! empty( $item->date_value )
					? esc_html( $item->date_value )
					: esc_html__( '—', 'ufsc-licence-competition' );

			default:
				return '';
		}
	}

	/**
	 * ✅ Merge-safe: defines ALL variables (search/club_id/etc) regardless of branch.
	 * ✅ Supports both "saison" (dynamic) and "season_end_year" (explicit).
	 * ✅ Supports both "category" and legacy "categorie".
	 */
	public function get_sanitized_filters() {
		$defaults  = $this->get_filter_defaults();
		$persisted = $this->get_persisted_filters();

		$reset = isset( $_REQUEST['ufsc_lc_reset'] ) ? absint( $_REQUEST['ufsc_lc_reset'] ) : 0;
		if ( $reset ) {
			$this->clear_persisted_filters();
			$persisted = array();
		}

		$default_per_page = class_exists( 'UFSC_LC_Settings_Page' ) ? UFSC_LC_Settings_Page::get_licences_per_page() : 25;
		$per_page = isset( $_REQUEST['per_page'] ) ? absint( $_REQUEST['per_page'] ) : $default_per_page;
		if ( ! isset( $_REQUEST['per_page'] ) && isset( $persisted['per_page'] ) ) {
			$per_page = absint( $persisted['per_page'] );
		}
		$per_page = in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : $default_per_page;

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : $defaults['orderby'];
		if ( ! isset( $_REQUEST['orderby'] ) && isset( $persisted['orderby'] ) ) {
			$orderby = sanitize_text_field( $persisted['orderby'] );
		}

		$order = isset( $_REQUEST['order'] ) ? strtolower( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ) : $defaults['order'];
		if ( ! isset( $_REQUEST['order'] ) && isset( $persisted['order'] ) ) {
			$order = strtolower( sanitize_text_field( $persisted['order'] ) );
		}

		$allowed_orderby = array( $this->licence_number_column, 'nom_licence', $this->date_column );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = $defaults['orderby'];
		}
		$order = 'desc' === $order ? 'DESC' : 'ASC';

		$pdf_filter = isset( $_REQUEST['pdf_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['pdf_filter'] ) ) : '';
		if ( ! isset( $_REQUEST['pdf_filter'] ) && isset( $persisted['pdf_filter'] ) ) {
			$pdf_filter = sanitize_key( $persisted['pdf_filter'] );
		}
		$pdf_filter = in_array( $pdf_filter, array( 'with', 'without' ), true ) ? $pdf_filter : '';

		$tab = isset( $_REQUEST['ufsc_lc_tab'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_lc_tab'] ) ) : $defaults['tab'];
		if ( ! isset( $_REQUEST['ufsc_lc_tab'] ) && isset( $persisted['tab'] ) ) {
			$tab = sanitize_key( $persisted['tab'] );
		}
		$tabs = array_keys( $this->get_tabs() );
		if ( ! in_array( $tab, $tabs, true ) ) {
			$tab = $defaults['tab'];
		}

		// ---- Base filters
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		if ( ! isset( $_REQUEST['s'] ) && isset( $persisted['search'] ) ) {
			$search = sanitize_text_field( $persisted['search'] );
		}

		$club_id = isset( $_REQUEST['club_id'] ) ? absint( $_REQUEST['club_id'] ) : 0;
		if ( ! isset( $_REQUEST['club_id'] ) && isset( $persisted['club_id'] ) ) {
			$club_id = absint( $persisted['club_id'] );
		}

		$club_search = isset( $_REQUEST['club_search'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['club_search'] ) ) : '';
		if ( ! isset( $_REQUEST['club_search'] ) && isset( $persisted['club_search'] ) ) {
			$club_search = sanitize_text_field( $persisted['club_search'] );
		}

		$statut = isset( $_REQUEST['statut'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['statut'] ) ) : '';
		if ( ! isset( $_REQUEST['statut'] ) && isset( $persisted['statut'] ) ) {
			$statut = sanitize_text_field( $persisted['statut'] );
		}

		// Category: accept both category & categorie (legacy)
		$categorie = isset( $_REQUEST['category'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['category'] ) ) : '';
		if ( '' === $categorie && isset( $_REQUEST['categorie'] ) ) {
			$categorie = sanitize_text_field( wp_unslash( $_REQUEST['categorie'] ) );
		}
		if ( ! isset( $_REQUEST['category'] ) && ! isset( $_REQUEST['categorie'] ) && isset( $persisted['category'] ) ) {
			$categorie = sanitize_text_field( $persisted['category'] );
		}
		if ( ! isset( $_REQUEST['category'] ) && ! isset( $_REQUEST['categorie'] ) && isset( $persisted['categorie'] ) && '' === $categorie ) {
			$categorie = sanitize_text_field( $persisted['categorie'] );
		}

		$competition = isset( $_REQUEST['competition'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['competition'] ) ) : '';
		if ( ! isset( $_REQUEST['competition'] ) && isset( $persisted['competition'] ) ) {
			$competition = sanitize_text_field( $persisted['competition'] );
		}

		// Season (dynamic string) + Season end year (int)
		$saison = isset( $_REQUEST['saison'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['saison'] ) ) : '';
		if ( ! isset( $_REQUEST['saison'] ) && isset( $persisted['saison'] ) ) {
			$saison = sanitize_text_field( $persisted['saison'] );
		}

		$season_end_year = isset( $_REQUEST['season_end_year'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['season_end_year'] ) ) : '';
		if ( ! isset( $_REQUEST['season_end_year'] ) && isset( $persisted['season_end_year'] ) ) {
			$season_end_year = sanitize_text_field( $persisted['season_end_year'] );
		}

		// Export columns (optional)
		$export_columns_raw = isset( $_REQUEST['export_columns'] ) ? wp_unslash( $_REQUEST['export_columns'] ) : array();
		if ( is_string( $export_columns_raw ) ) {
			$export_columns_raw = explode( ',', $export_columns_raw );
		}
		$export_columns = array();
		if ( is_array( $export_columns_raw ) ) {
			$export_columns = array_values(
				array_filter(
					array_map( 'sanitize_key', $export_columns_raw )
				)
			);
		}

		// ---- Sanitize values against DB distinct values
		$statut      = $this->sanitize_filter_value( 'statut', $statut );
		$categorie   = $this->sanitize_filter_value( 'category', $categorie );
		$competition = $this->sanitize_filter_value( 'competition', $competition );

		// Dynamic season only if supported
		if ( $this->has_season_column ) {
			$saison = $this->sanitize_filter_value( 'saison', $saison );
		} else {
			$saison = '';
		}

		// season_end_year only if supported
		if ( $this->has_season_end_year ) {
			$season_end_year = $this->sanitize_filter_value( 'season_end_year', $season_end_year );
		} else {
			$season_end_year = '';
		}

		return array(
			'per_page'        => $per_page,
			'orderby'         => $orderby,
			'order'           => $order,
			'search'          => $search,
			'club_id'         => $club_id,
			'club_search'     => $club_search,
			'statut'          => $statut,
			'category'        => $categorie,
			'competition'     => $competition,
			'saison'          => $saison,
			'season_end_year' => $season_end_year,
			'pdf_filter'      => $pdf_filter,
			'tab'             => $tab,
			'export_columns'  => $export_columns,
		);
	}

	public function get_filter_query_args() {
		$filters = $this->get_sanitized_filters();
		$this->persist_filters( $filters );

		$args = array(
			'page'            => 'ufsc-lc-licences',
			's'               => $filters['search'],
			'club_id'         => $filters['club_id'],
			'club_search'     => $filters['club_search'],
			'statut'          => $filters['statut'],
			'category'        => $filters['category'],
			'competition'     => $filters['competition'],
			'saison'          => $filters['saison'],
			'season_end_year' => $filters['season_end_year'],
			'pdf_filter'      => $filters['pdf_filter'],
			'ufsc_lc_tab'     => $filters['tab'],
			'orderby'         => $filters['orderby'],
			'order'           => strtolower( $filters['order'] ),
			'per_page'        => $filters['per_page'],
		);

		return array_filter(
			$args,
			static function ( $value ) {
				return '' !== $value && null !== $value && false !== $value;
			}
		);
	}

	private function get_tabs() {
		// We keep season tab even if only one of the season systems is available.
		return array(
			'all'    => __( 'Toutes', 'ufsc-licence-competition' ),
			'status' => __( 'Par statut', 'ufsc-licence-competition' ),
			'season' => __( 'Par saison', 'ufsc-licence-competition' ),
		);
	}

	private function get_current_tab() {
		$filters = $this->get_sanitized_filters();
		return $filters['tab'];
	}

	private function get_view_query_args( $tab ) {
		$args = $this->get_filter_query_args();
		$args['ufsc_lc_tab'] = $tab;

		if ( 'all' === $tab ) {
			$args['statut'] = '';
			$args['saison'] = '';
			$args['season_end_year'] = '';
		}
		if ( 'status' === $tab ) {
			$args['saison'] = '';
			$args['season_end_year'] = '';
		}
		if ( 'season' === $tab ) {
			$args['statut'] = '';
		}

		return $args;
	}

	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$filters     = $this->get_sanitized_filters();
		$club_id     = $filters['club_id'];
		$club_search = $filters['club_search'];
		$statut      = $filters['statut'];
		$categorie   = $filters['category'];
		$competition = $filters['competition'];
		$saison      = $filters['saison'];
		$season_end_year = $filters['season_end_year'];
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
		echo '<select name="category" id="ufsc-category-filter">';
		echo '<option value="">' . esc_html__( 'Toutes les catégories', 'ufsc-licence-competition' ) . '</option>';
		foreach ( $this->get_distinct_values( 'category' ) as $value ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $categorie, $value, false ),
				esc_html( $value )
			);
		}
		echo '</select>';

		// ✅ Prefer season_end_year if exists, otherwise use saison/season.
		if ( $this->has_season_end_year ) {
			echo '<label class="screen-reader-text" for="ufsc-season-end-year-filter">' . esc_html__( 'Filtrer par saison', 'ufsc-licence-competition' ) . '</label>';
			echo '<select name="season_end_year" id="ufsc-season-end-year-filter">';
			echo '<option value="">' . esc_html__( 'Toutes les saisons', 'ufsc-licence-competition' ) . '</option>';
			foreach ( $this->get_distinct_values( 'season_end_year' ) as $value ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $value ),
					selected( $season_end_year, $value, false ),
					esc_html( $value )
				);
			}
			echo '</select>';
		} elseif ( $this->has_season_column ) {
			echo '<label class="screen-reader-text" for="ufsc-season-filter">' . esc_html__( 'Filtrer par saison', 'ufsc-licence-competition' ) . '</label>';
			echo '<select name="saison" id="ufsc-season-filter">';
			echo '<option value="">' . esc_html__( 'Toutes les saisons', 'ufsc-licence-competition' ) . '</option>';
			foreach ( $this->get_distinct_values( 'saison' ) as $value ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $value ),
					selected( $saison, $value, false ),
					esc_html( $value )
				);
			}
			echo '</select>';
		}

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
		printf(
			'<a class="button button-secondary" href="%s">%s</a>',
			esc_url( add_query_arg( 'ufsc_lc_reset', 1, admin_url( 'admin.php?page=ufsc-lc-licences' ) ) ),
			esc_html__( 'Réinitialiser filtres', 'ufsc-licence-competition' )
		);
		echo '</div>';

		// Bulk season input only for dynamic season column.
		echo '<div class="alignleft actions">';
		if ( $this->has_season_column ) {
			echo '<label class="screen-reader-text" for="ufsc-bulk-season">' . esc_html__( 'Nouvelle saison', 'ufsc-licence-competition' ) . '</label>';
			printf(
				'<input type="text" name="bulk_saison" id="ufsc-bulk-season" value="" placeholder="%s" class="regular-text" list="ufsc-bulk-season-list" />',
				esc_attr__( 'Nouvelle saison…', 'ufsc-licence-competition' )
			);
			echo '<datalist id="ufsc-bulk-season-list">';
			foreach ( $this->get_distinct_values( 'saison' ) as $value ) {
				printf( '<option value="%s"></option>', esc_attr( $value ) );
			}
			echo '</datalist>';
		}
		echo '</div>';
	}

	public function prepare_items() {
		global $wpdb;

		$this->process_bulk_action();

		$filters       = $this->get_sanitized_filters();
		$per_page      = $filters['per_page'];
		$current_page  = max( 1, (int) $this->get_pagenum() );
		$orderby       = $filters['orderby'];
		$order         = $filters['order'];
		$search        = $filters['search'];
		$club_id       = $filters['club_id'];
		$club_search   = $filters['club_search'];
		$statut        = $filters['statut'];
		$categorie     = $filters['category'];
		$competition   = $filters['competition'];
		$saison        = $filters['saison'];
		$season_end_year = $filters['season_end_year'];
		$pdf_filter    = $filters['pdf_filter'];

		$licences_table  = $this->get_licences_table();
		$clubs_table     = $this->get_clubs_table();
		$documents_table = $this->get_documents_table();

		$where  = array();
		$params = array();

		$search_like = '';
		if ( '' !== $search ) {
			$search_like = '%' . $wpdb->esc_like( $search ) . '%';
			$search_clauses = array( 'l.nom_licence LIKE %s', 'l.prenom LIKE %s', 'c.nom LIKE %s' );
			$params = array_merge( $params, array( $search_like, $search_like, $search_like ) );

			if ( $this->has_email ) {
				$search_clauses[] = 'l.email LIKE %s';
				$params[] = $search_like;
			}

			if ( $this->has_asptt_number ) {
				$search_clauses[] = 'l.numero_licence_asptt LIKE %s';
				$params[] = $search_like;
			}

			if ( $this->has_licence_number ) {
				$search_clauses[] = 'l.numero_licence_delegataire LIKE %s';
				$params[] = $search_like;
			}
			$where[] = '(' . implode( ' OR ', $search_clauses ) . ')';
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
		$this->add_default_valid_filter( $licences_table, $statut, $where, $params );

		if ( '' !== $categorie && ( $this->has_category || $this->has_legacy_category ) ) {
			$category_column = $this->has_category ? 'l.category' : 'l.categorie';
			$where[] = "{$category_column} = %s";
			$params[] = $categorie;
		}

		if ( '' !== $competition && $this->has_competition ) {
			$where[] = 'l.competition = %s';
			$params[] = $competition;
		}

		// Season filter (prefer season_end_year if present and used)
		if ( '' !== $season_end_year && $this->has_season_end_year ) {
			$where[] = 'l.season_end_year = %d';
			$params[] = (int) $season_end_year;
		} elseif ( '' !== $saison && $this->has_season_column ) {
			$where[] = "l.{$this->season_column} = %s";
			$params[] = $saison;
		}

		$join_documents    = '';
		$document_params   = array();
		$select_documents  = 'NULL AS asptt_number, NULL AS date_asptt, NULL AS attachment_id';

		if ( $this->has_documents_table ) {
			$join_documents   = "LEFT JOIN {$documents_table} d ON d.licence_id = l.id AND d.source = %s";
			$document_params  = array( 'ASPTT' );
			$date_asptt_sql   = $this->has_source_created_at ? 'd.source_created_at' : 'NULL';
			$select_documents = "d.source_licence_number AS asptt_number, {$date_asptt_sql} AS date_asptt, d.attachment_id";

			if ( '' !== $search ) {
				$where[]  = 'd.source_licence_number LIKE %s';
				$params[] = $search_like;
			}

			if ( 'with' === $pdf_filter ) {
				$where[] = 'd.attachment_id IS NOT NULL';
			} elseif ( 'without' === $pdf_filter ) {
				$where[] = 'd.attachment_id IS NULL';
			}
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		if ( $this->has_asptt_number && $this->has_licence_number ) {
			$licence_number_sql = "COALESCE(NULLIF(l.numero_licence_asptt, ''), NULLIF(l.numero_licence_delegataire, ''), l.id)";
		} elseif ( $this->has_asptt_number ) {
			$licence_number_sql = 'l.numero_licence_asptt';
		} elseif ( $this->has_licence_number ) {
			$licence_number_sql = 'l.numero_licence_delegataire';
		} else {
			$licence_number_sql = 'l.id';
		}
		if ( $this->has_category ) {
			$category_column = 'l.category';
		} elseif ( $this->has_legacy_category ) {
			$category_column = 'l.categorie';
		} else {
			$category_column = 'NULL';
		}

		// We always output season_end_year as alias; if column doesn't exist => NULL.
		$season_column_sql  = $this->has_season_end_year ? 'l.season_end_year AS season_end_year' : 'NULL AS season_end_year';

		$competition_column = $this->has_competition ? 'l.competition' : 'NULL AS competition';
		$select_columns = "l.id, l.club_id, {$licence_number_sql} AS licence_number, l.nom_licence, l.prenom, l.statut, {$category_column} AS category, {$season_column_sql}, {$competition_column}, {$select_documents}, c.nom AS club_name, l.{$this->date_column} AS date_value";

		$orderby_sql = 'l.' . $orderby;

		$count_sql = "SELECT COUNT(*)
			FROM {$licences_table} l
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
		$count_query  = $wpdb->prepare( $count_sql, $count_params );
		$total_items  = (int) $wpdb->get_var( $count_query );

		$offset      = ( $current_page - 1 ) * $per_page;
		$item_params = array_merge( $document_params, $params, array( $per_page, $offset ) );
		$items_query = $wpdb->prepare( $items_sql, $item_params );
		$this->items = $wpdb->get_results( $items_query );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'UFSC LC Licences count SQL: ' . $count_query );
			error_log( 'UFSC LC Licences items SQL: ' . $items_query );
			error_log(
				sprintf(
					'UFSC LC Licences pagination: paged=%d per_page=%d offset=%d total_items=%d items=%d',
					$current_page,
					$per_page,
					$offset,
					$total_items,
					count( $this->items )
				)
			);
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	// --- Export (kept compatible)
	public function get_export_rows( $filters ) {
		return $this->get_export_rows_chunk( $filters, 0, 0 );
	}

	public function get_export_rows_chunk( $filters, $limit, $offset ) {
		global $wpdb;

		$licences_table  = $this->get_licences_table();
		$clubs_table     = $this->get_clubs_table();
		$documents_table = $this->get_documents_table();

		$search          = $filters['search'];
		$club_id         = $filters['club_id'];
		$club_search     = $filters['club_search'];
		$statut          = $filters['statut'];
		$categorie       = $filters['category'];
		$competition     = $filters['competition'];
		$saison          = $filters['saison'];
		$season_end_year = $filters['season_end_year'];
		$pdf_filter      = $filters['pdf_filter'];
		$orderby         = $filters['orderby'];
		$order           = $filters['order'];

		$where  = array();
		$params = array();

		$search_like = '';
		if ( '' !== $search ) {
			$search_like = '%' . $wpdb->esc_like( $search ) . '%';
			$search_clauses = array( 'l.nom_licence LIKE %s', 'l.prenom LIKE %s', 'c.nom LIKE %s' );
			$params = array_merge( $params, array( $search_like, $search_like, $search_like ) );

			if ( $this->has_email ) {
				$search_clauses[] = 'l.email LIKE %s';
				$params[] = $search_like;
			}

			if ( $this->has_asptt_number ) {
				$search_clauses[] = 'l.numero_licence_asptt LIKE %s';
				$params[] = $search_like;
			}

			if ( $this->has_licence_number ) {
				$search_clauses[] = 'l.numero_licence_delegataire LIKE %s';
				$params[] = $search_like;
			}
			$where[] = '(' . implode( ' OR ', $search_clauses ) . ')';
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
		$this->add_default_valid_filter( $licences_table, $statut, $where, $params );

		if ( '' !== $categorie && ( $this->has_category || $this->has_legacy_category ) ) {
			$category_column = $this->has_category ? 'l.category' : 'l.categorie';
			$where[] = "{$category_column} = %s";
			$params[] = $categorie;
		}

		if ( '' !== $competition && $this->has_competition ) {
			$where[] = 'l.competition = %s';
			$params[] = $competition;
		}

		if ( '' !== $season_end_year && $this->has_season_end_year ) {
			$where[] = 'l.season_end_year = %d';
			$params[] = (int) $season_end_year;
		} elseif ( '' !== $saison && $this->has_season_column ) {
			$where[] = "l.{$this->season_column} = %s";
			$params[] = $saison;
		}

		$join_documents   = '';
		$document_params  = array();
		$select_documents = 'NULL AS asptt_number, NULL AS date_asptt, 0 AS has_pdf';

		if ( $this->has_documents_table ) {
			$join_documents   = "LEFT JOIN {$documents_table} d ON d.licence_id = l.id AND d.source = %s";
			$document_params  = array( 'ASPTT' );
			$date_asptt_sql   = $this->has_source_created_at ? 'd.source_created_at' : 'NULL';
			$select_documents = "d.source_licence_number AS asptt_number, {$date_asptt_sql} AS date_asptt, CASE WHEN d.attachment_id IS NULL THEN 0 ELSE 1 END AS has_pdf";

			if ( '' !== $search ) {
				$where[]  = 'd.source_licence_number LIKE %s';
				$params[] = $search_like;
			}

			if ( 'with' === $pdf_filter ) {
				$where[] = 'd.attachment_id IS NOT NULL';
			} elseif ( 'without' === $pdf_filter ) {
				$where[] = 'd.attachment_id IS NULL';
			}
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		if ( $this->has_category ) {
			$category_column = 'l.category';
		} elseif ( $this->has_legacy_category ) {
			$category_column = 'l.categorie';
		} else {
			$category_column = 'NULL';
		}
		$season_column_sql = $this->has_season_end_year ? 'l.season_end_year AS season_end_year' : 'NULL AS season_end_year';
		$age_ref_column    = $this->has_column( $licences_table, 'age_ref' ) ? 'l.age_ref' : 'NULL AS age_ref';

		$competition_column = $this->has_competition ? 'l.competition' : 'NULL AS competition';
		$select_columns = "c.nom AS club_name, l.nom_licence, l.prenom, l.date_naissance, l.statut, {$category_column} AS category, {$season_column_sql}, {$age_ref_column}, {$competition_column}, {$select_documents}";

		$orderby_sql = ( 'source_created_at' === $orderby && $this->has_source_created_at ) ? 'd.source_created_at' : 'l.' . $orderby;

		$limit_sql = '';
		if ( $limit > 0 ) {
			$limit_sql = $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		$sql = "SELECT {$select_columns}
			FROM {$licences_table} l
			LEFT JOIN {$clubs_table} c ON c.id = l.club_id
			{$join_documents}
			{$where_sql}
			ORDER BY {$orderby_sql} {$order}{$limit_sql}";

		$query_params = array_merge( $document_params, $params );
		return $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A );
	}

	private function process_bulk_action() {
		$action = $this->current_action();
		if ( ! $action ) {
			return;
		}

		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$licence_ids = isset( $_REQUEST[ $this->_args['singular'] ] ) ? array_map( 'absint', (array) $_REQUEST[ $this->_args['singular'] ] ) : array();
		$licence_ids = array_filter( $licence_ids );

		if ( empty( $licence_ids ) ) {
			return;
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bulk-' . $this->_args['plural'] ) ) {
			wp_die( esc_html__( 'Requête invalide.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		switch ( $action ) {
			case 'ufsc_lc_mark_review':
				$result = $this->handle_bulk_mark_review( $licence_ids );
				break;
			case 'ufsc_lc_remove_pdf':
				$result = $this->handle_bulk_remove_pdf( $licence_ids );
				break;
			case 'ufsc_lc_recalculate_categories':
				$result = $this->handle_bulk_recalculate_categories( $licence_ids );
				break;
			case 'ufsc_lc_change_season':
				$result = $this->handle_bulk_change_season( $licence_ids );
				break;
			default:
				$result = array();
		}

		if ( ! empty( $result ) ) {
			$this->redirect_with_notice( $result );
		}
	}

	private function add_default_valid_filter( $licences_table, $statut, array &$where, array &$params ) {
		if ( '' !== $statut ) {
			return;
		}

		$has_statut = $this->has_column( $licences_table, 'statut' );
		$has_status = $this->has_column( $licences_table, 'status' );

		if ( ! $has_statut && ! $has_status ) {
			return;
		}

		if ( $has_statut && $has_status ) {
			$status_expr = 'LOWER(COALESCE(l.statut, l.status))';
		} else {
			$status_expr = $has_statut ? 'LOWER(l.statut)' : 'LOWER(l.status)';
		}

		$where[] = "{$status_expr} = %s";
		$params[] = 'valide';
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

			return array( 'type' => 'success', 'code' => 'bulk_mark_review' );
		}

		if ( ! $this->has_documents_meta_table ) {
			return array( 'type' => 'error', 'code' => 'documents_meta_missing' );
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
		return array( 'type' => 'success', 'code' => 'bulk_mark_review' );
	}

	private function handle_bulk_remove_pdf( array $licence_ids ) {
		global $wpdb;

		if ( ! $this->has_documents_table ) {
			return array( 'type' => 'error', 'code' => 'documents_missing' );
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

		return array( 'type' => 'success', 'code' => 'bulk_remove_pdf' );
	}

	private function handle_bulk_recalculate_categories( array $licence_ids ) {
		global $wpdb;

		$licences_table = $this->get_licences_table();
		$placeholders = implode( ',', array_fill( 0, count( $licence_ids ), '%d' ) );

		// Keep legacy fetch (categorie) but calculation hook can decide.
		$licences = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, date_naissance, categorie FROM {$licences_table} WHERE id IN ({$placeholders})",
				$licence_ids
			)
		);

		if ( empty( $licences ) ) {
			return array( 'type' => 'warning', 'code' => 'bulk_recalculate_empty' );
		}

		$updated = 0;
		foreach ( $licences as $licence ) {
			$calculated = apply_filters( 'ufsc_lc_calculate_category', null, $licence );
			if ( null === $calculated ) {
				continue;
			}
			$calculated = (string) $calculated;

			// Write in legacy column "categorie" (no regression). If you want to also write "category" later, do it in migration.
			$wpdb->update(
				$licences_table,
				array( 'categorie' => $calculated ),
				array( 'id' => (int) $licence->id ),
				array( '%s' ),
				array( '%d' )
			);

			$updated++;
		}

		if ( 0 === $updated ) {
			return array( 'type' => 'warning', 'code' => 'bulk_recalculate_skipped' );
		}

		return array( 'type' => 'success', 'code' => 'bulk_recalculate_categories' );
	}

	private function handle_bulk_change_season( array $licence_ids ) {
		global $wpdb;

		if ( ! $this->has_season_column ) {
			return array( 'type' => 'error', 'code' => 'season_missing' );
		}

		$new_season = isset( $_REQUEST['bulk_saison'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['bulk_saison'] ) ) : '';
		if ( '' === $new_season ) {
			return array( 'type' => 'warning', 'code' => 'bulk_season_missing' );
		}

		$licences_table = $this->get_licences_table();
		$placeholders = implode( ',', array_fill( 0, count( $licence_ids ), '%d' ) );
		$params = array_merge( array( $new_season ), $licence_ids );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$licences_table} SET {$this->season_column} = %s WHERE id IN ({$placeholders})",
				$params
			)
		);

		return array( 'type' => 'success', 'code' => 'bulk_change_season' );
	}

	private function redirect_with_notice( array $notice ) {
		$type = isset( $notice['type'] ) ? sanitize_key( $notice['type'] ) : '';
		$code = isset( $notice['code'] ) ? sanitize_key( $notice['code'] ) : '';

		if ( ! $type || ! $code ) {
			return;
		}

		$args = $this->get_filter_query_args();
		$args[ $type ] = $code;

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
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
			return __( '—', 'ufsc-licence-competition' );
		}
		if ( is_numeric( $value ) ) {
			return (int) $value ? __( 'Compétition', 'ufsc-licence-competition' ) : __( 'Loisir', 'ufsc-licence-competition' );
		}
		return (string) $value;
	}

	private function format_date_asptt( $value ) {
		if ( null === $value || '' === $value ) {
			return esc_html__( '—', 'ufsc-licence-competition' );
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

		// Canonical allowed keys (request-level)
		$allowed = array( 'statut', 'category', 'season_end_year', 'competition', 'saison' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}

		$table = $this->get_licences_table();

		// Map "category" => real column
		if ( 'category' === $column ) {
			$real = $this->has_category ? 'category' : ( $this->has_legacy_category ? 'categorie' : '' );
			if ( '' === $real ) {
				return array();
			}
			$results = $wpdb->get_col( "SELECT DISTINCT {$real} FROM {$table} WHERE {$real} IS NOT NULL AND {$real} != '' ORDER BY {$real} ASC" );
			return array_filter( array_map( 'strval', $results ) );
		}

		// Map "saison" => dynamic season column (saison|season)
		if ( 'saison' === $column ) {
			if ( ! $this->has_season_column ) {
				return array();
			}
			$real = $this->season_column;
			$results = $wpdb->get_col( "SELECT DISTINCT {$real} FROM {$table} WHERE {$real} IS NOT NULL AND {$real} != '' ORDER BY {$real} ASC" );
			return array_filter( array_map( 'strval', $results ) );
		}

		// season_end_year only if column exists
		if ( 'season_end_year' === $column && ! $this->has_column( $table, 'season_end_year' ) ) {
			return array();
		}

		// Generic
		if ( ! $this->has_column( $table, $column ) ) {
			return array();
		}

		$results = $wpdb->get_col( "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} IS NOT NULL AND {$column} != '' ORDER BY {$column} ASC" );
		return array_filter( array_map( 'strval', $results ) );
	}

	private function has_column( $table, $column ) {
		global $wpdb;

		$column = sanitize_key( $column );
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		return ! empty( $exists );
	}

	private function sanitize_filter_value( $column, $value ) {
		if ( '' === $value ) {
			return '';
		}
		$allowed = $this->get_distinct_values( $column );
		if ( empty( $allowed ) ) {
			return '';
		}
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	private function get_filter_defaults() {
		$default_per_page = class_exists( 'UFSC_LC_Settings_Page' ) ? UFSC_LC_Settings_Page::get_licences_per_page() : 25;
		return array(
			'per_page'        => $default_per_page,
			'orderby'         => 'nom_licence',
			'order'           => 'asc',
			'search'          => '',
			'club_id'         => 0,
			'club_search'     => '',
			'statut'          => '',
			'categorie'       => '',
			'competition'     => '',
			'saison'          => '',
			'season_end_year' => '',
			'pdf_filter'      => '',
			'tab'             => 'all',
		);
	}

	private function get_persisted_filters() {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		$stored = get_user_meta( get_current_user_id(), self::FILTERS_USER_META, true );
		return is_array( $stored ) ? $stored : array();
	}

	private function persist_filters( array $filters ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		update_user_meta( get_current_user_id(), self::FILTERS_USER_META, $filters );
	}

	private function clear_persisted_filters() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		delete_user_meta( get_current_user_id(), self::FILTERS_USER_META );
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

	private function get_season_column() {
		$table = $this->get_licences_table();
		$candidates = array( 'saison', 'season' );
		foreach ( $candidates as $candidate ) {
			if ( $this->has_column( $table, $candidate ) ) {
				return $candidate;
			}
		}
		return '';
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
