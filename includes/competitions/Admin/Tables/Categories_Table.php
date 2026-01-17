<?php

namespace UFSC\Competitions\Admin\Tables;

use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Categories_Table extends \WP_List_Table {
	private $repository;
	private $competition_repository;
	private $filters = array();
	private $competitions = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ufsc-competition-category',
				'plural'   => 'ufsc-competition-categories',
				'ajax'     => false,
			)
		);

		$this->repository = new CategoryRepository();
		$this->competition_repository = new CompetitionRepository();
	}

	public function get_filters() {
		return $this->filters;
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'ufsc_competition_categories_per_page', 20 );
		$current_page = max( 1, (int) $this->get_pagenum() );

		$filters = array(
			'view'           => isset( $_GET['ufsc_view'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_view'] ) ) : 'all',
			'competition_id' => isset( $_GET['ufsc_competition_id'] ) ? absint( $_GET['ufsc_competition_id'] ) : 0,
			'search'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);

		$this->filters = $filters;
		$this->competitions = $this->competition_repository->list( array( 'view' => 'all' ), 100, 0 );

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
			'name'        => __( 'Catégorie', 'ufsc-licence-competition' ),
			'competition' => __( 'Compétition', 'ufsc-licence-competition' ),
			'age'         => __( 'Âge', 'ufsc-licence-competition' ),
			'weight'      => __( 'Poids', 'ufsc-licence-competition' ),
			'sex'         => __( 'Sexe', 'ufsc-licence-competition' ),
			'level'       => __( 'Niveau', 'ufsc-licence-competition' ),
			'format'      => __( 'Format', 'ufsc-licence-competition' ),
			'updated'     => __( 'Mise à jour', 'ufsc-licence-competition' ),
		);
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
				'page'        => 'ufsc-competition-categories',
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
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_trash_category', 'id' => $item->id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_trash_category_' . $item->id ) ),
				esc_html__( 'Mettre à la corbeille', 'ufsc-licence-competition' )
			);
		} else {
			$actions['restore'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_restore_category', 'id' => $item->id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_restore_category_' . $item->id ) ),
				esc_html__( 'Restaurer', 'ufsc-licence-competition' )
			);
			$actions['delete'] = sprintf(
				'<a href="%s" class="submitdelete">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_delete_category', 'id' => $item->id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_delete_category_' . $item->id ) ),
				esc_html__( 'Supprimer définitivement', 'ufsc-licence-competition' )
			);
		}

		return $title . $this->row_actions( $actions );
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'competition':
				return esc_html( $this->get_competition_name( $item->competition_id ) );
			case 'age':
				return esc_html( $this->format_range( $item->age_min, $item->age_max, __( 'ans', 'ufsc-licence-competition' ) ) );
			case 'weight':
				return esc_html( $this->format_range( $item->weight_min, $item->weight_max, __( 'kg', 'ufsc-licence-competition' ) ) );
			case 'sex':
				return esc_html( $item->sex );
			case 'level':
			case 'format':
				return esc_html( $item->{$column_name} );
			case 'updated':
				return esc_html( $this->format_datetime( $item->updated_at ) );
			default:
				return '';
		}
	}

	public function no_items() {
		esc_html_e( 'Aucune catégorie trouvée.', 'ufsc-licence-competition' );
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current = $this->filters['competition_id'] ?? 0;
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="ufsc_competition_filter"><?php esc_html_e( 'Filtrer par compétition', 'ufsc-licence-competition' ); ?></label>
			<select name="ufsc_competition_id" id="ufsc_competition_filter">
				<option value="0"><?php esc_html_e( 'Toutes les compétitions', 'ufsc-licence-competition' ); ?></option>
				<?php foreach ( $this->competitions as $competition ) : ?>
					<option value="<?php echo esc_attr( $competition->id ); ?>" <?php selected( $current, $competition->id ); ?>><?php echo esc_html( $competition->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filtrer', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
		</div>
		<?php
	}

	private function get_page_url() {
		return admin_url( 'admin.php?page=ufsc-competition-categories' );
	}

	private function get_competition_name( $competition_id ) {
		foreach ( $this->competitions as $competition ) {
			if ( (int) $competition->id === (int) $competition_id ) {
				return $competition->name;
			}
		}

		return '';
	}

	private function format_range( $min, $max, $unit ) {
		if ( $min && $max ) {
			return sprintf( '%s-%s %s', $min, $max, $unit );
		}

		if ( $min ) {
			return sprintf( '%s+ %s', $min, $unit );
		}

		if ( $max ) {
			return sprintf( '≤ %s %s', $max, $unit );
		}

		return '';
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
}
