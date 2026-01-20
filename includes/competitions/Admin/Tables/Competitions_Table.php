<?php

namespace UFSC\Competitions\Admin\Tables;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\CompetitionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Competitions_Table extends \WP_List_Table {
	private $repository;
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

	public function prepare_items() {
		$per_page     = $this->get_items_per_page( 'ufsc_competitions_per_page', 20 );
		$current_page = max( 1, (int) $this->get_pagenum() );

		$filters = array(
			'view'       => isset( $_REQUEST['ufsc_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) ) : 'all',
			'status'     => isset( $_REQUEST['ufsc_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_status'] ) ) : '',
			'discipline' => isset( $_REQUEST['ufsc_discipline'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_discipline'] ) ) : '',
			'season'     => isset( $_REQUEST['ufsc_season'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ufsc_season'] ) ) : '',
			'search'     => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
		);

		$this->filters = $filters;

		$total_items = $this->repository->count( $filters );
		$this->items = $this->repository->list( $filters, $per_page, ( $current_page - 1 ) * $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'name'       => __( 'Compétition', 'ufsc-licence-competition' ),
			'discipline' => __( 'Discipline', 'ufsc-licence-competition' ),
			'type'       => __( 'Type', 'ufsc-licence-competition' ),
			'season'     => __( 'Saison', 'ufsc-licence-competition' ),
			'event'      => __( 'Manifestation', 'ufsc-licence-competition' ),
			'reg_close'  => __( 'Clôture inscriptions', 'ufsc-licence-competition' ),
			'status'     => __( 'Statut', 'ufsc-licence-competition' ),
			'updated'    => __( 'Mise à jour', 'ufsc-licence-competition' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'name'   => array( 'name', false ),
			'season' => array( 'season', false ),
			'status' => array( 'status', false ),
			'event'  => array( 'event_start_datetime', true ),
			'updated'=> array( 'updated_at', true ),
		);
	}

	public function get_bulk_actions() {
		$view = $this->filters['view'] ?? 'all';

		if ( 'trash' === $view ) {
			$actions = array(
				'restore' => __( 'Restaurer', 'ufsc-licence-competition' ),
			);
			if ( Capabilities::user_can_delete() ) {
				$actions['delete'] = __( 'Supprimer définitivement', 'ufsc-licence-competition' );
			}
			return $actions;
		}

		return array(
			'trash' => __( 'Mettre à la corbeille', 'ufsc-licence-competition' ),
		);
	}

	public function get_views() {
		$current  = $this->filters['view'] ?? 'all';
		$base_url = $this->get_page_url();

		$views = array(
			'all'   => sprintf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $base_url ),
				( 'all' === $current ) ? 'current' : '',
				esc_html__( 'Actives', 'ufsc-licence-competition' )
			),
			'trash' => sprintf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( add_query_arg( 'ufsc_view', 'trash', $base_url ) ),
				( 'trash' === $current ) ? 'current' : '',
				esc_html__( 'Corbeille', 'ufsc-licence-competition' )
			),
		);

		return $views;
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', absint( $item->id ) );
	}

	protected function column_name( $item ) {
		$edit_url = add_query_arg(
			array(
				'page'        => Menu::PAGE_COMPETITIONS,
				'ufsc_action' => 'edit',
				'id'          => (int) $item->id,
			),
			admin_url( 'admin.php' )
		);

		$title = sprintf(
			'<strong><a href="%s">%s</a></strong>',
			esc_url( $edit_url ),
			esc_html( (string) ( $item->name ?? '' ) )
		);

		$actions = array();
		$is_deleted = ! empty( $item->deleted_at );

		if ( ! $is_deleted ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'ufsc-licence-competition' ) );

			$actions['trash'] = sprintf(
				'<a href="%s" class="ufsc-confirm" data-ufsc-confirm="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'ufsc_competitions_trash_competition',
								'id'     => (int) $item->id,
							),
							admin_url( 'admin-post.php' )
						),
						'ufsc_competitions_trash_competition_' . (int) $item->id
					)
				),
				esc_attr__( 'Mettre cette compétition à la corbeille ?', 'ufsc-licence-competition' ),
				esc_html__( 'Corbeille', 'ufsc-licence-competition' )
			);
		} else {
			$actions['restore'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'ufsc_competitions_restore_competition',
								'id'     => (int) $item->id,
							),
							admin_url( 'admin-post.php' )
						),
						'ufsc_competitions_restore_competition_' . (int) $item->id
					)
				),
				esc_html__( 'Restaurer', 'ufsc-licence-competition' )
			);

			if ( Capabilities::user_can_delete() ) {
				$actions['delete'] = sprintf(
					'<a href="%s" class="submitdelete ufsc-confirm" data-ufsc-confirm="%s">%s</a>',
					esc_url(
						wp_nonce_url(
							add_query_arg(
								array(
									'action' => 'ufsc_competitions_delete_competition',
									'id'     => (int) $item->id,
								),
								admin_url( 'admin-post.php' )
							),
							'ufsc_competitions_delete_competition_' . (int) $item->id
						)
					),
					esc_attr__( 'Supprimer définitivement cette compétition ?', 'ufsc-licence-competition' ),
					esc_html__( 'Supprimer définitivement', 'ufsc-licence-competition' )
				);
			}
		}

		return $title . $this->row_actions( $actions );
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'discipline':
			case 'type':
			case 'season':
				return esc_html( (string) ( $item->{$column_name} ?? '' ) );

			case 'event':
				$start = (string) ( $item->event_start_datetime ?? '' );
				$end   = (string) ( $item->event_end_datetime ?? '' );
				return esc_html( $this->format_range_datetime( $start, $end ) );

			case 'reg_close':
				return esc_html( $this->format_datetime( (string) ( $item->registration_close_datetime ?? '' ) ) );

			case 'status':
				return esc_html( $this->format_status( (string) ( $item->status ?? '' ) ) );

			case 'updated':
				return esc_html( $this->format_datetime( (string) ( $item->updated_at ?? '' ) ) );

			default:
				return '';
		}
	}

	public function no_items() {
		esc_html_e( 'Aucune compétition trouvée.', 'ufsc-licence-competition' );
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$status     = $this->filters['status'] ?? '';
		$discipline = $this->filters['discipline'] ?? '';
		$season     = $this->filters['season'] ?? '';

		?>
		<div class="alignleft actions">
			<select name="ufsc_status">
				<option value=""><?php esc_html_e( 'Tous les statuts', 'ufsc-licence-competition' ); ?></option>
				<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Brouillon', 'ufsc-licence-competition' ); ?></option>
				<option value="open" <?php selected( $status, 'open' ); ?>><?php esc_html_e( 'Ouvert', 'ufsc-licence-competition' ); ?></option>
				<option value="closed" <?php selected( $status, 'closed' ); ?>><?php esc_html_e( 'Fermé', 'ufsc-licence-competition' ); ?></option>
				<option value="archived" <?php selected( $status, 'archived' ); ?>><?php esc_html_e( 'Archivé', 'ufsc-licence-competition' ); ?></option>
			</select>

			<input type="text" name="ufsc_discipline" value="<?php echo esc_attr( $discipline ); ?>" placeholder="<?php echo esc_attr__( 'Discipline', 'ufsc-licence-competition' ); ?>" class="regular-text" style="max-width:180px;" />

			<input type="text" name="ufsc_season" value="<?php echo esc_attr( $season ); ?>" placeholder="<?php echo esc_attr__( 'Saison', 'ufsc-licence-competition' ); ?>" class="regular-text" style="max-width:120px;" />

			<?php submit_button( __( 'Filtrer', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
		</div>
		<?php
	}

	private function get_page_url() {
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : Menu::PAGE_COMPETITIONS;
		$url  = admin_url( 'admin.php?page=' . $page );

		// keep view
		if ( isset( $_REQUEST['ufsc_view'] ) ) {
			$url = add_query_arg( 'ufsc_view', sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) ), $url );
		}

		return $url;
	}

	private function format_datetime( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) ) {
			return '';
		}
		$ts = strtotime( $mysql_datetime );
		if ( false === $ts ) {
			return '';
		}
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	private function format_range_datetime( $start, $end ) {
		$s = $this->format_datetime( $start );
		$e = $this->format_datetime( $end );

		if ( $s && $e && $s !== $e ) {
			return $s . ' → ' . $e;
		}
		return $s ?: $e;
	}

	private function format_status( $status ) {
		$labels = array(
			'draft'    => __( 'Brouillon', 'ufsc-licence-competition' ),
			'open'     => __( 'Ouvert', 'ufsc-licence-competition' ),
			'closed'   => __( 'Fermé', 'ufsc-licence-competition' ),
			'archived' => __( 'Archivé', 'ufsc-licence-competition' ),
		);
		return $labels[ $status ] ?? $status;
	}
}
