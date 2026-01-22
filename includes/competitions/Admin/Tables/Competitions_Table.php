<?php

namespace UFSC\Competitions\Admin\Tables;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Competitions_Table extends \WP_List_Table {

	/** @var CompetitionRepository */
	private $repository;

	/** @var array */
	private $filters = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ufsc-competition',
				'plural'   => 'ufsc-competitions',
				'ajax'     => false,
			)
		);

		$this->repository = new CompetitionRepository();
	}

	public function get_filters() {
		return $this->filters;
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'name'       => __( 'Nom', 'ufsc-licence-competition' ),
			'discipline' => __( 'Discipline', 'ufsc-licence-competition' ),
			'type'       => __( 'Type', 'ufsc-licence-competition' ),
			'season'     => __( 'Saison', 'ufsc-licence-competition' ),
			'status'     => __( 'Statut', 'ufsc-licence-competition' ),
			'event'      => __( 'Début', 'ufsc-licence-competition' ),
			'updated'    => __( 'Maj', 'ufsc-licence-competition' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'name'    => array( 'name', false ),
			'season'  => array( 'season', false ),
			'status'  => array( 'status', false ),
			'event'   => array( 'event_start_datetime', false ),
			'updated' => array( 'updated_at', true ),
		);
	}

	protected function get_hidden_columns() {
		return array();
	}

	public function column_cb( $item ) {
		$id = isset( $item->id ) ? (int) $item->id : 0;
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', $id );
	}

	public function column_name( $item ) {
		$id   = isset( $item->id ) ? (int) $item->id : 0;
		$name = isset( $item->name ) ? (string) $item->name : '';

		$view = isset( $_REQUEST['ufsc_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) ) : 'all';

		$edit_url = add_query_arg(
			array(
				'page'        => Menu::MENU_SLUG,
				'ufsc_action' => 'edit',
				'id'          => $id,
			),
			admin_url( 'admin.php' )
		);

		$actions = array();

		if ( 'trash' === $view ) {
			$restore_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'ufsc_competitions_restore_competition',
						'id'     => $id,
					),
					admin_url( 'admin-post.php' )
				),
				'ufsc_competitions_restore_competition_' . $id
			);

			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'ufsc_competitions_delete_competition',
						'id'     => $id,
					),
					admin_url( 'admin-post.php' )
				),
				'ufsc_competitions_delete_competition_' . $id
			);

			$actions['restore'] = sprintf( '<a href="%s">%s</a>', esc_url( $restore_url ), esc_html__( 'Restaurer', 'ufsc-licence-competition' ) );
			$actions['delete']  = sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $delete_url ), esc_html__( 'Supprimer définitivement', 'ufsc-licence-competition' ) );
		} else {
			// Edit always available in non-trash views
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'ufsc-licence-competition' ) );

			if ( 'archived' === $view ) {
				$unarchive_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'ufsc_competitions_unarchive_competition',
							'id'     => $id,
						),
						admin_url( 'admin-post.php' )
					),
					'ufsc_competitions_unarchive_competition_' . $id
				);
				$actions['unarchive'] = sprintf( '<a href="%s">%s</a>', esc_url( $unarchive_url ), esc_html__( 'Désarchiver', 'ufsc-licence-competition' ) );
			} else {
				$archive_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'ufsc_competitions_archive_competition',
							'id'     => $id,
						),
						admin_url( 'admin-post.php' )
					),
					'ufsc_competitions_archive_competition_' . $id
				);
				$actions['archive'] = sprintf( '<a href="%s">%s</a>', esc_url( $archive_url ), esc_html__( 'Archiver', 'ufsc-licence-competition' ) );
			}

			$trash_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'ufsc_competitions_trash_competition',
						'id'     => $id,
					),
					admin_url( 'admin-post.php' )
				),
				'ufsc_competitions_trash_competition_' . $id
			);
			$actions['trash'] = sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $trash_url ), esc_html__( 'Corbeille', 'ufsc-licence-competition' ) );
		}

		if ( \UFSC\Competitions\Capabilities::user_can_validate_entries() ) {
			$csv_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'ufsc_competitions_export_plateau_csv',
						'competition_id' => $id,
					),
					admin_url( 'admin-post.php' )
				),
				'ufsc_competitions_export_plateau_csv'
			);
			$actions['export_plateau'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $csv_url ),
				esc_html__( 'Exporter CSV plateau', 'ufsc-licence-competition' )
			);

			$pdf_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'ufsc_competitions_download_plateau_pdf',
						'competition_id' => $id,
						'mode' => 'plateau',
					),
					admin_url( 'admin-post.php' )
				),
				'ufsc_competitions_download_plateau_pdf'
			);
			$actions['download_plateau'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $pdf_url ),
				esc_html__( 'Télécharger PDF plateau', 'ufsc-licence-competition' )
			);

			$pdf_fiche_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'ufsc_competitions_download_plateau_pdf',
						'competition_id' => $id,
						'mode' => 'fiche',
					),
					admin_url( 'admin-post.php' )
				),
				'ufsc_competitions_download_plateau_pdf'
			);
			$actions['download_plateau_fiche'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $pdf_fiche_url ),
				esc_html__( 'Télécharger PDF fiche', 'ufsc-licence-competition' )
			);
		}

		$title = sprintf( '<a href="%s"><strong>%s</strong></a>', esc_url( $edit_url ), esc_html( $name ) );

		return $title . $this->row_actions( $actions );
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'discipline':
				return esc_html( (string) ( $item->discipline ?? '' ) );
			case 'type':
				return esc_html( (string) ( $item->type ?? '' ) );
			case 'season':
				return esc_html( (string) ( $item->season ?? '' ) );
			case 'status':
				return esc_html( (string) ( $item->status ?? '' ) );
			case 'event':
				$dt = (string) ( $item->event_start_datetime ?? '' );
				return $dt ? esc_html( $dt ) : '—';
			case 'updated':
				$u = (string) ( $item->updated_at ?? '' );
				return $u ? esc_html( $u ) : '—';
		}

		return '';
	}

	protected function get_bulk_actions() {
		$view = isset( $_REQUEST['ufsc_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) ) : 'all';

		if ( 'trash' === $view ) {
			return array(
				'restore' => __( 'Restaurer', 'ufsc-licence-competition' ),
				'delete'  => __( 'Supprimer définitivement', 'ufsc-licence-competition' ),
			);
		}

		if ( 'archived' === $view ) {
			return array(
				'unarchive' => __( 'Désarchiver', 'ufsc-licence-competition' ),
				'trash'     => __( 'Mettre à la corbeille', 'ufsc-licence-competition' ),
			);
		}

		return array(
			'archive' => __( 'Archiver', 'ufsc-licence-competition' ),
			'trash'   => __( 'Mettre à la corbeille', 'ufsc-licence-competition' ),
		);
	}

	protected function bulk_actions( $which = '' ) {
		if ( is_null( $this->_actions ) ) {
			$this->_actions = $this->get_bulk_actions();
			$this->_actions = apply_filters( "bulk_actions-{$this->screen->id}", $this->_actions );
			$two = '';
		} else {
			$two = '2';
		}

		if ( empty( $this->_actions ) ) {
			return;
		}

		echo '<label for="ufsc_bulk_action' . esc_attr( $two ) . '" class="screen-reader-text">' . esc_html__( 'Actions groupées', 'ufsc-licence-competition' ) . '</label>';
		echo '<select name="ufsc_bulk_action' . esc_attr( $two ) . '" id="ufsc_bulk_action' . esc_attr( $two ) . '">';
		echo '<option value="-1">' . esc_html__( 'Actions groupées', 'ufsc-licence-competition' ) . "</option>\n";

		foreach ( $this->_actions as $action => $title ) {
			$css_class = 'edit' === $action ? ' class="hide-if-no-js"' : '';
			echo "\t" . '<option value="' . esc_attr( $action ) . '"' . $css_class . '>' . esc_html( $title ) . "</option>\n";
		}

		echo "</select>\n";

		submit_button( __( 'Appliquer', 'ufsc-licence-competition' ), 'action', "doaction{$two}", false );
		echo "\n";
	}

	public function get_views() {
		$current = isset( $_REQUEST['ufsc_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) ) : 'all';

		// Build counts
		$base_filters = $this->filters;
		$base_filters['s'] = isset( $base_filters['s'] ) ? (string) $base_filters['s'] : '';

		$all_filters = $base_filters;
		$all_filters['view'] = 'all';
		$all_count = $this->repository->count( $all_filters );

		$arch_filters = $base_filters;
		$arch_filters['view'] = 'archived';
		$arch_count = $this->repository->count( $arch_filters );

		$trash_filters = $base_filters;
		$trash_filters['view'] = 'trash';
		$trash_count = $this->repository->count( $trash_filters );

		$views = array();

		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( add_query_arg( array( 'page' => Menu::MENU_SLUG, 'ufsc_view' => 'all' ), admin_url( 'admin.php' ) ) ),
			( 'all' === $current || '' === $current ) ? ' class="current"' : '',
			esc_html__( 'Actives', 'ufsc-licence-competition' ),
			(int) $all_count
		);

		$views['archived'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( add_query_arg( array( 'page' => Menu::MENU_SLUG, 'ufsc_view' => 'archived' ), admin_url( 'admin.php' ) ) ),
			( 'archived' === $current ) ? ' class="current"' : '',
			esc_html__( 'Archivées', 'ufsc-licence-competition' ),
			(int) $arch_count
		);

		$views['trash'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( add_query_arg( array( 'page' => Menu::MENU_SLUG, 'ufsc_view' => 'trash' ), admin_url( 'admin.php' ) ) ),
			( 'trash' === $current ) ? ' class="current"' : '',
			esc_html__( 'Corbeille', 'ufsc-licence-competition' ),
			(int) $trash_count
		);

		return $views;
	}

	public function no_items() {
		esc_html_e( 'Aucune compétition trouvée.', 'ufsc-licence-competition' );
	}

	public function prepare_items() {
		$per_page     = (int) $this->get_items_per_page( 'ufsc_competitions_per_page', 20 );
		$current_page = max( 1, (int) $this->get_pagenum() );

		$filters = array(
			'view'       => isset( $_REQUEST['ufsc_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) ) : 'all',
			'status'     => isset( $_REQUEST['ufsc_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_status'] ) ) : '',
			'discipline' => isset( $_REQUEST['ufsc_discipline'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ufsc_discipline'] ) ) : '',
			'season'     => isset( $_REQUEST['ufsc_season'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ufsc_season'] ) ) : '',
			's'          => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
		);

		if ( ! in_array( $filters['view'], array( 'all', 'archived', 'trash' ), true ) ) {
			$filters['view'] = 'all';
		}

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : '';
		$order   = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : '';

		$sortable_map = array(
			'name'    => 'name',
			'season'  => 'season',
			'status'  => 'status',
			'event'   => 'event_start_datetime',
			'updated' => 'updated_at',
		);

		if ( $orderby && isset( $sortable_map[ $orderby ] ) ) {
			$filters['order_by']  = $sortable_map[ $orderby ];
			$filters['order_dir'] = ( 'ASC' === $order ) ? 'ASC' : 'DESC';
		}

		$this->filters = $filters;

		$total_items = (int) $this->repository->count( $filters );
		$this->items = $this->repository->list( $filters, $per_page, ( $current_page - 1 ) * $per_page );

		$columns  = $this->get_columns();
		$hidden   = $this->get_hidden_columns();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
	}
}
