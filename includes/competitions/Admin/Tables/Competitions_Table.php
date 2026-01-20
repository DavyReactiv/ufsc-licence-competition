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

	public function prepare_items() {
		$per_page     = (int) $this->get_items_per_page( 'ufsc_competitions_per_page', 20 );
		$current_page = max( 1, (int) $this->get_pagenum() );

		$filters = array(
			'view'       => isset( $_REQUEST['ufsc_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) ) : 'all',
			'status'     => isset( $_REQUEST['ufsc_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_status'] ) ) : '',
			'discipline' => isset( $_REQUEST['ufsc_discipline'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_discipline'] ) ) : '',
			'season'     => isset( $_REQUEST['ufsc_season'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ufsc_season'] ) ) : '',
			'search'     => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
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

		// Count & list
		$total_items = (int) $this->repository->count( $filters );
		$this->items = $this->repository->list( $filters, $per_page, ( $current_page - 1 ) * $per_page );

		// Debug info when WP_DEBUG
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->debug_info = array(
				'filters'      => $filters,
				'total_items'  => $total_items,
				'returned'     => is_array( $this->items ) ? count( $this->items ) : 0,
			);

			// also log via UFSC_LC_Logger/error_log
			$log_msg = sprintf( 'Competitions_Table prepare_items: filters=%s ; total=%d ; returned=%d',
				wp_json_encode( $filters ), $total_items, $this->debug_info['returned'] );
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
}
