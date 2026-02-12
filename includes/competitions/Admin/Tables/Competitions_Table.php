<?php

namespace UFSC\Competitions\Admin\Tables;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Access\CompetitionAccess;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Entries\EntriesWorkflow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Competitions_Table extends \WP_List_Table {

	/** @var CompetitionRepository */
	private $repository;
	private $entries_repository;
	private $entry_counts = array();

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
		$this->entries_repository = new EntryRepository();
	}

	public function get_filters() {
		return $this->filters;
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'name'       => __( 'Nom', 'ufsc-licence-competition' ),
			'entries'    => __( 'Inscrits', 'ufsc-licence-competition' ),
			'access'     => __( 'Accès', 'ufsc-licence-competition' ),
			'discipline' => __( 'Discipline', 'ufsc-licence-competition' ),
			'type'       => __( 'Type', 'ufsc-licence-competition' ),
			'season'     => __( 'Saison', 'ufsc-licence-competition' ),
			'status'     => __( 'Statut', 'ufsc-licence-competition' ),
			'event'      => __( 'Début', 'ufsc-licence-competition' ),
			'updated'    => __( 'Maj', 'ufsc-licence-competition' ),
		);
	}

	public function column_access( $item ) {
		$competition_id = (int) ( $item->id ?? 0 );
		if ( ! $competition_id ) {
			return '—';
		}

		$access = new CompetitionAccess();
		$summary = $access->get_access_summary( $competition_id );

		return $summary ? esc_html( $summary ) : '—';
	}

	public function column_entries( $item ) {
		$competition_id = (int) ( $item->id ?? 0 );
		if ( ! $competition_id ) {
			return '—';
		}

		$counts = $this->entry_counts[ $competition_id ] ?? array();
		$total = (int) ( $counts['total'] ?? 0 );

		$submitted = (int) ( $counts['submitted'] ?? 0 );
		$pending = (int) ( $counts['pending'] ?? 0 );
		$approved = (int) ( $counts['approved'] ?? 0 );
		$rejected = (int) ( $counts['rejected'] ?? 0 );

		$badges = array();
		if ( $submitted ) {
			$badges[] = sprintf(
				'<span class="ufsc-badge %s">%s</span>',
				esc_attr( EntriesWorkflow::get_status_badge_class( 'submitted' ) ),
				esc_html( sprintf( __( 'Soumises: %d', 'ufsc-licence-competition' ), $submitted ) )
			);
		}
		if ( $pending ) {
			$badges[] = sprintf(
				'<span class="ufsc-badge %s">%s</span>',
				esc_attr( EntriesWorkflow::get_status_badge_class( 'pending' ) ),
				esc_html( sprintf( __( 'En attente: %d', 'ufsc-licence-competition' ), $pending ) )
			);
		}
		if ( $approved ) {
			$badges[] = sprintf(
				'<span class="ufsc-badge %s">%s</span>',
				esc_attr( EntriesWorkflow::get_status_badge_class( 'approved' ) ),
				esc_html( sprintf( __( 'Approuvées: %d', 'ufsc-licence-competition' ), $approved ) )
			);
		}
		if ( $rejected ) {
			$badges[] = sprintf(
				'<span class="ufsc-badge %s">%s</span>',
				esc_attr( EntriesWorkflow::get_status_badge_class( 'rejected' ) ),
				esc_html( sprintf( __( 'Refusées: %d', 'ufsc-licence-competition' ), $rejected ) )
			);
		}

		$badges_html = $badges ? '<div class="ufsc-competition-entry-counts">' . implode( ' ', $badges ) . '</div>' : '';

		return sprintf(
			'<strong>%s</strong>%s',
			esc_html( sprintf( __( 'Total: %d', 'ufsc-licence-competition' ), $total ) ),
			$badges_html
		);
	}

	public function get_sortable_columns() {
		return array(
			'name'    => array( 'name', false ),
			'season'  => array( 'season', false ),
			'status'  => array( 'status', false ),
			'event'   => array( 'event_start_datetime', false ),
			'updated' => array( 'updated_at', true ),
		);
	}

	public function get_hidden_columns() {
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

			$actions['restore'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $restore_url ),
				esc_html__( 'Restaurer', 'ufsc-licence-competition' )
			);
			$actions['delete']  = sprintf(
				'<a href="%s" class="submitdelete">%s</a>',
				esc_url( $delete_url ),
				esc_html__( 'Supprimer définitivement', 'ufsc-licence-competition' )
			);
		} else {
			// Edit always available in non-trash views
			$actions['edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Modifier', 'ufsc-licence-competition' )
			);

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
				$actions['unarchive'] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( $unarchive_url ),
					esc_html__( 'Désarchiver', 'ufsc-licence-competition' )
				);
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
				$actions['archive'] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( $archive_url ),
					esc_html__( 'Archiver', 'ufsc-licence-competition' )
				);
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
			$actions['trash'] = sprintf(
				'<a href="%s" class="submitdelete">%s</a>',
				esc_url( $trash_url ),
				esc_html__( 'Corbeille', 'ufsc-licence-competition' )
			);
		}

		// Exports (Phase 2.4) — only for users who can validate entries.
		if ( \UFSC\Competitions\Capabilities::user_can_validate_entries() ) {
			// CSV plateau
			$csv_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'         => 'ufsc_competitions_export_plateau_csv',
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

			// PDF plateau (mode plateau)
			$pdf_plateau_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'         => 'ufsc_competitions_download_plateau_pdf',
						'competition_id' => $id,
						'mode'           => 'plateau',
					),
					admin_url( 'admin-post.php' )
				),
				'ufsc_competitions_download_plateau_pdf'
			);
			$actions['download_plateau'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $pdf_plateau_url ),
				esc_html__( 'Télécharger PDF plateau', 'ufsc-licence-competition' )
			);

			// PDF contrôle (mode controle)
			$pdf_controle_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'         => 'ufsc_competitions_download_plateau_pdf',
						'competition_id' => $id,
						'mode'           => 'controle',
					),
					admin_url( 'admin-post.php' )
				),
				'ufsc_competitions_download_plateau_pdf'
			);
			$actions['download_controle'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $pdf_controle_url ),
				esc_html__( 'Télécharger PDF contrôle', 'ufsc-licence-competition' )
			);

			// PDF fiche (mode fiche)
			$pdf_fiche_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'         => 'ufsc_competitions_download_plateau_pdf',
						'competition_id' => $id,
						'mode'           => 'fiche',
					),
					admin_url( 'admin-post.php' )
				),
				'ufsc_competitions_download_fiche_pdf'
			);
			$actions['download_fiche'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $pdf_fiche_url ),
				esc_html__( 'Télécharger PDF fiche', 'ufsc-licence-competition' )
			);

			// PDF fiche complète (mode fiche_complete)
			$pdf_fiche_complete_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'         => 'ufsc_competitions_download_plateau_pdf',
						'competition_id' => $id,
						'mode'           => 'fiche_complete',
					),
					admin_url( 'admin-post.php' )
				),
				'ufsc_competitions_download_fiche_pdf'
			);
			$actions['download_fiche_complete'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $pdf_fiche_complete_url ),
				esc_html__( 'Télécharger PDF fiche complète', 'ufsc-licence-competition' )
			);

			$entries_pdf_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'         => 'ufsc_competitions_export_entries_pdf',
						'competition_id' => $id,
					),
					admin_url( 'admin-post.php' )
				),
				'ufsc_competitions_export_entries_pdf'
			);
			$actions['export_entries_pdf'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $entries_pdf_url ),
				esc_html__( 'Exporter la liste des inscriptions (PDF)', 'ufsc-licence-competition' )
			);

			$engaged_csv_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'         => 'ufsc_competitions_export_engaged_csv_admin',
						'competition_id' => $id,
						'status'         => 'approved',
					),
					admin_url( 'admin-post.php' )
				),
				'ufsc_competitions_export_engaged_csv_admin_' . $id
			);
			$actions['export_engaged_csv'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $engaged_csv_url ),
				esc_html__( 'Exporter CSV engagés', 'ufsc-licence-competition' )
			);
		}

		$title = sprintf(
			'<a href="%s"><strong>%s</strong></a>',
			esc_url( $edit_url ),
			esc_html( $name )
		);

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
				return esc_html( function_exists( 'ufsc_lc_format_datetime' ) ? ufsc_lc_format_datetime( $dt ) : ( $dt ?: '—' ) );
			case 'updated':
				$u = (string) ( $item->updated_at ?? '' );
				return esc_html( function_exists( 'ufsc_lc_format_datetime' ) ? ufsc_lc_format_datetime( $u ) : ( $u ?: '—' ) );
		}

		return '';
	}

	public function get_bulk_actions() {
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

	public function bulk_actions( $which = '' ) {
		if ( is_null( $this->_actions ) ) {
			$this->_actions = $this->get_bulk_actions();
			$this->_actions = apply_filters( "bulk_actions-{$this->screen->id}", $this->_actions );
			$two            = '';
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

		$base_filters       = $this->filters;
		$base_filters['s']  = isset( $base_filters['s'] ) ? (string) $base_filters['s'] : '';

		$all_filters        = $base_filters;
		$all_filters['view'] = 'all';
		$all_count          = $this->repository->count( $all_filters );

		$arch_filters        = $base_filters;
		$arch_filters['view'] = 'archived';
		$arch_count          = $this->repository->count( $arch_filters );

		$all_with_archived_filters        = $base_filters;
		$all_with_archived_filters['view'] = 'all_with_archived';
		$all_with_archived_count          = $this->repository->count( $all_with_archived_filters );

		$trash_filters        = $base_filters;
		$trash_filters['view'] = 'trash';
		$trash_count          = $this->repository->count( $trash_filters );

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

		$views['all_with_archived'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( add_query_arg( array( 'page' => Menu::MENU_SLUG, 'ufsc_view' => 'all_with_archived' ), admin_url( 'admin.php' ) ) ),
			( 'all_with_archived' === $current ) ? ' class="current"' : '',
			esc_html__( 'Toutes', 'ufsc-licence-competition' ),
			(int) $all_with_archived_count
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

		$filters['view'] = \UFSC\Competitions\Repositories\CompetitionRepository::normalize_view( $filters['view'] );

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

		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$filters = ufsc_lc_competitions_apply_scope_to_query_args( $filters );
		}

		$this->filters = $filters;

		$total_items = (int) $this->repository->count( $filters );
		$this->items = $this->repository->list( $filters, $per_page, ( $current_page - 1 ) * $per_page );

		$competition_ids = array();
		foreach ( $this->items as $item ) {
			if ( isset( $item->id ) ) {
				$competition_ids[] = (int) $item->id;
			}
		}
		$this->entry_counts = $competition_ids ? $this->entries_repository->get_competition_counters( $competition_ids ) : array();

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
