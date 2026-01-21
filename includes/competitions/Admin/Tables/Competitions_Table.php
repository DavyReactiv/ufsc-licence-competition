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

	/** @var CompetitionRepository */
	private $repository;

	/** @var array */
	private $filters = array();

	/** @var array|null debug info when WP_DEBUG */
	private $debug_info = null;

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

	public function get_debug_info() {
		return $this->debug_info;
	}

	/**
	 * REQUIRED by WP_List_Table
	 */
	public function get_columns(): array {
		return array(
			'cb'        => '<input type="checkbox" />',
			'name'      => __( 'Nom', 'ufsc-licence-competition' ),
			'discipline'=> __( 'Discipline', 'ufsc-licence-competition' ),
			'type'      => __( 'Type', 'ufsc-licence-competition' ),
			'season'    => __( 'Saison', 'ufsc-licence-competition' ),
			'status'    => __( 'Statut', 'ufsc-licence-competition' ),
			'event'     => __( 'Début', 'ufsc-licence-competition' ),
			'updated'   => __( 'Màj', 'ufsc-licence-competition' ),
		);
	}

	/**
	 * Optional but recommended for sorting links in headers
	 */
	protected function get_sortable_columns(): array {
		return array(
			'name'    => array( 'name', false ),
			'season'  => array( 'season', false ),
			'status'  => array( 'status', false ),
			'event'   => array( 'event', false ),
			'updated' => array( 'updated', false ),
		);
	}

	protected function column_cb( $item ): string {
		$id = '';
		if ( is_object( $item ) && isset( $item->id ) ) {
			$id = (string) $item->id;
		} elseif ( is_array( $item ) && isset( $item['id'] ) ) {
			$id = (string) $item['id'];
		}

		return sprintf( '<input type="checkbox" name="ids[]" value="%s" />', esc_attr( $id ) );
	}

	/**
	 * Default renderer for columns not explicitly handled.
	 */
	protected function column_default( $item, $column_name ) {
		// Map virtual columns to DB fields
		if ( 'event' === $column_name ) {
			$val = is_object( $item ) ? ( $item->event_start_datetime ?? '' ) : ( $item['event_start_datetime'] ?? '' );
			return $val ? esc_html( (string) $val ) : '—';
		}

		if ( 'updated' === $column_name ) {
			$val = is_object( $item ) ? ( $item->updated_at ?? '' ) : ( $item['updated_at'] ?? '' );
			return $val ? esc_html( (string) $val ) : '—';
		}

		// Direct fields
		$val = '';
		if ( is_object( $item ) && isset( $item->$column_name ) ) {
			$val = $item->$column_name;
		} elseif ( is_array( $item ) && isset( $item[ $column_name ] ) ) {
			$val = $item[ $column_name ];
		}

		return $val !== '' ? esc_html( (string) $val ) : '—';
	}

	/**
	 * (Optionnel) rendre le nom cliquable plus tard.
	 * Pour l'instant on reste minimal et safe.
	 */
	protected function column_name( $item ) {
		$val = is_object( $item ) ? ( $item->name ?? '' ) : ( $item['name'] ?? '' );
		return $val !== '' ? esc_html( (string) $val ) : '—';
	}

	public function prepare_items() {
		$per_page     = (int) $this->get_items_per_page( 'ufsc_competitions_per_page', 20 );
		$current_page = max( 1, (int) $this->get_pagenum() );

		$filters = array(
			'view'       => isset( $_REQUEST['ufsc_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) ) : 'all',
			'status'     => isset( $_REQUEST['ufsc_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_status'] ) ) : '',
			'discipline' => isset( $_REQUEST['ufsc_discipline'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_discipline'] ) ) : '',
			'season'     => isset( $_REQUEST['ufsc_season'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ufsc_season'] ) ) : '',
			// IMPORTANT: WP_List_Table uses "s" for search. Repository expects "s".
			's'          => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
		);

		if ( ! in_array( $filters['view'], array( 'all', 'trash' ), true ) ) {
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

		// REQUIRED: set column headers for WP_List_Table
		$this->_column_headers = array(
			$this->get_columns(),
			array(), // hidden
			$this->get_sortable_columns(),
			'name',  // primary
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->debug_info = array(
				'filters'     => $filters,
				'total_items' => $total_items,
				'returned'    => is_array( $this->items ) ? count( $this->items ) : 0,
			);

			$log_msg = sprintf(
				'Competitions_Table prepare_items: filters=%s ; total=%d ; returned=%d',
				wp_json_encode( $filters ),
				$total_items,
				$this->debug_info['returned']
			);

			if ( class_exists( '\\UFSC_LC_Logger' ) ) {
				\UFSC_LC_Logger::log( $log_msg );
			} else {
				error_log( $log_msg );
			}
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
	}

	public function no_items() {
		echo esc_html__( 'Aucune compétition trouvée.', 'ufsc-licence-competition' );
	}
}
