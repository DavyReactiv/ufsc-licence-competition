<?php

namespace UFSC\Competitions\Admin\Tables;

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
		$per_page = $this->get_items_per_page( 'ufsc_competitions_per_page', 20 );
		$current_page = max( 1, (int) $this->get_pagenum() );
		$filters = array(
			'view'   => isset( $_GET['ufsc_view'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_view'] ) ) : 'all',
			'status' => isset( $_GET['ufsc_status'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_status'] ) ) : '',
			'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
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
			'name'       => __( 'Compétition', 'ufsc-licence-competition' ),
			'discipline' => __( 'Discipline', 'ufsc-licence-competition' ),
			'type'       => __( 'Type', 'ufsc-licence-competition' ),
			'season'     => __( 'Saison', 'ufsc-licence-competition' ),
			'dates'      => __( 'Dates', 'ufsc-licence-competition' ),
			'deadline'   => __( 'Forclusion', 'ufsc-licence-competition' ),
			'status'     => __( 'Statut', 'ufsc-licence-competition' ),
			'updated'    => __( 'Mise à jour', 'ufsc-licence-competition' ),
		);
	}

	public function get_sortable_columns() {
		return array();
	}

	public function get_views() {
		$current = $this->filters['view'] ?? 'all';
		$base_url = $this->get_page_url();

		$views = array(
			'all'   => sprintf( '<a href="%s" class="%s">%s</a>', esc_url( $base_url ), 'all' === $current ? 'current' : '', esc_html__( 'Tous', 'ufsc-licence-competition' ) ),
			'trash' => sprintf( '<a href="%s" class="%s">%s</a>', esc_url( add_query_arg( 'ufsc_view', 'trash', $base_url ) ), 'trash' === $current ? 'current' : '', esc_html__( 'Corbeille', 'ufsc-licence-competition' ) ),
		);

		return $views;
	}

	protected function column_name( $item ) {
		$edit_url = add_query_arg(
			array(
				'page'        => 'ufsc-competitions',
				'ufsc_action' => 'edit',
				'id'          => $item->id,
			),
			admin_url( 'admin.php' )
		);

		$title = sprintf( '<strong><a href="%s">%s</a></strong>', esc_url( $edit_url ), esc_html( $item->name ) );

		$actions = array();
		if ( empty( $item->deleted_at ) ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'ufsc-licence-competition' ) );
			$actions['trash'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_trash_competition', 'id' => $item->id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_trash_competition_' . $item->id ) ),
				esc_html__( 'Mettre à la corbeille', 'ufsc-licence-competition' )
			);
		} else {
			$actions['restore'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_restore_competition', 'id' => $item->id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_restore_competition_' . $item->id ) ),
				esc_html__( 'Restaurer', 'ufsc-licence-competition' )
			);
			$actions['delete'] = sprintf(
				'<a href="%s" class="submitdelete">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_delete_competition', 'id' => $item->id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_delete_competition_' . $item->id ) ),
				esc_html__( 'Supprimer définitivement', 'ufsc-licence-competition' )
			);
		}

		return $title . $this->row_actions( $actions );
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'discipline':
			case 'type':
			case 'season':
				return esc_html( $item->{$column_name} );
			case 'dates':
				return esc_html( $this->format_dates( $item->start_date, $item->end_date ) );
			case 'deadline':
				return esc_html( $this->format_date( $item->registration_deadline ) );
			case 'status':
				return esc_html( $this->format_status( $item->status ) );
			case 'updated':
				return esc_html( $this->format_datetime( $item->updated_at ) );
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

		$status = $this->filters['status'] ?? '';
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="ufsc_status_filter"><?php esc_html_e( 'Filtrer par statut', 'ufsc-licence-competition' ); ?></label>
			<select name="ufsc_status" id="ufsc_status_filter">
				<option value=""><?php esc_html_e( 'Tous les statuts', 'ufsc-licence-competition' ); ?></option>
				<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Brouillon', 'ufsc-licence-competition' ); ?></option>
				<option value="open" <?php selected( $status, 'open' ); ?>><?php esc_html_e( 'Ouvert', 'ufsc-licence-competition' ); ?></option>
				<option value="closed" <?php selected( $status, 'closed' ); ?>><?php esc_html_e( 'Clos', 'ufsc-licence-competition' ); ?></option>
			</select>
			<?php submit_button( __( 'Filtrer', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
		</div>
		<?php
	}

	private function get_page_url() {
		$base = admin_url( 'admin.php?page=ufsc-competitions' );
		return $base;
	}

	private function format_date( $date ) {
		if ( empty( $date ) ) {
			return '';
		}

		$timestamp = strtotime( $date );
		if ( ! $timestamp ) {
			return '';
		}

		return date_i18n( get_option( 'date_format' ), $timestamp );
	}

	private function format_datetime( $date ) {
		if ( empty( $date ) ) {
			return '';
		}

		$timestamp = strtotime( $date );
		if ( ! $timestamp ) {
			return '';
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	private function format_dates( $start, $end ) {
		if ( $start && $end && $start !== $end ) {
			return $this->format_date( $start ) . ' → ' . $this->format_date( $end );
		}

		return $this->format_date( $start ?: $end );
	}

	private function format_status( $status ) {
		$labels = array(
			'draft'  => __( 'Brouillon', 'ufsc-licence-competition' ),
			'open'   => __( 'Ouvert', 'ufsc-licence-competition' ),
			'closed' => __( 'Clos', 'ufsc-licence-competition' ),
		);

		return $labels[ $status ] ?? $status;
	}
}
