<?php

namespace UFSC\Competitions\Admin\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Quality_Table extends \WP_List_Table {
	private $issues = array();

	public function __construct( array $issues ) {
		parent::__construct(
			array(
				'singular' => 'ufsc-competition-quality',
				'plural'   => 'ufsc-competition-quality',
				'ajax'     => false,
			)
		);

		$this->issues = $issues;
	}

	public function prepare_items() {
		$this->items = $this->issues;
	}

	public function get_columns() {
		return array(
			'issue'       => __( 'Anomalie', 'ufsc-licence-competition' ),
			'competition' => __( 'Compétition', 'ufsc-licence-competition' ),
			'licensee'    => __( 'Licencié', 'ufsc-licence-competition' ),
			'details'     => __( 'Détails', 'ufsc-licence-competition' ),
		);
	}

	public function column_default( $item, $column_name ) {
		return esc_html( $item[ $column_name ] ?? '' );
	}

	public function no_items() {
		esc_html_e( 'Aucune anomalie détectée.', 'ufsc-licence-competition' );
	}
}
