<?php

namespace UFSC\Competitions\Admin\Tables;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Services\DisciplineRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Fights_Table extends \WP_List_Table {
	private $repository;
	private $competition_repository;
	private $category_repository;
	private $filters = array();
	private $competitions = array();
	private $categories = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ufsc-competition-fight',
				'plural'   => 'ufsc-competition-fights',
				'ajax'     => false,
			)
		);

		if ( class_exists( '\UFSC\Competitions\Db' ) && method_exists( '\UFSC\Competitions\Db', 'maybe_upgrade' ) ) {
			\UFSC\Competitions\Db::maybe_upgrade();
		}

		$this->repository = new FightRepository();
		$this->competition_repository = new CompetitionRepository();
		$this->category_repository = new CategoryRepository();
	}

	public function get_filters() {
		return $this->filters;
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'ufsc_competition_fights_per_page', 20 );
		$current_page = max( 1, (int) $this->get_pagenum() );

		$filters = array(
			'view'           => isset( $_REQUEST['ufsc_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) ) : 'all',
			'competition_id' => isset( $_REQUEST['ufsc_competition_id'] ) ? absint( $_REQUEST['ufsc_competition_id'] ) : 0,
			'status'         => isset( $_REQUEST['ufsc_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_status'] ) ) : '',
			'discipline'     => isset( $_REQUEST['ufsc_discipline'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_discipline'] ) ) : '',
		);

		$this->competitions = $this->competition_repository->list( array( 'view' => 'all' ), 200, 0 );
		$this->categories = $this->category_repository->list( array( 'view' => 'all' ), 500, 0 );

		if ( $filters['discipline'] && ! $filters['competition_id'] ) {
			$filters['competition_ids'] = $this->get_competition_ids_by_discipline( $filters['discipline'] );
		}

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
			'fight_no'   => __( 'Combat', 'ufsc-licence-competition' ),
			'competition'=> __( 'Compétition', 'ufsc-licence-competition' ),
			'discipline' => __( 'Discipline', 'ufsc-licence-competition' ),
			'category'   => __( 'Catégorie', 'ufsc-licence-competition' ),
			'ring'       => __( 'Ring/Tatami', 'ufsc-licence-competition' ),
			'round_no'   => __( 'Round', 'ufsc-licence-competition' ),
			'status'     => __( 'Statut', 'ufsc-licence-competition' ),
			'scheduled'  => __( 'Horaire', 'ufsc-licence-competition' ),
		);
	}

	public function get_views() {
		$current = $this->filters['view'] ?? 'all';
		$base_url = $this->get_page_url();

		return array(
			'all'   => sprintf( '<a href="%s" class="%s">%s</a>', esc_url( $base_url ), 'all' === $current ? 'current' : '', esc_html__( 'Tous', 'ufsc-licence-competition' ) ),
			'trash' => sprintf( '<a href="%s" class="%s">%s</a>', esc_url( add_query_arg( 'ufsc_view', 'trash', $base_url ) ), 'trash' === $current ? 'current' : '', esc_html__( 'Corbeille', 'ufsc-licence-competition' ) ),
		);
	}

	public function get_bulk_actions() {
		$actions = array();
		$view = $this->filters['view'] ?? 'all';

		if ( 'trash' === $view ) {
			$actions['restore'] = __( 'Restaurer', 'ufsc-licence-competition' );
			if ( Capabilities::user_can_delete() ) {
				$actions['delete'] = __( 'Supprimer définitivement', 'ufsc-licence-competition' );
			}
		} else {
			$actions['trash'] = __( 'Mettre à la corbeille', 'ufsc-licence-competition' );
		}

		return $actions;
	}

	protected function column_fight_no( $item ) {
		$edit_url = add_query_arg(
			array(
				'page'        => Menu::PAGE_BOUTS,
				'ufsc_action' => 'edit',
				'id'          => $item->id,
			),
			admin_url( 'admin.php' )
		);

		$title = sprintf( '<strong><a href="%s">#%d</a></strong>', esc_url( $edit_url ), (int) $item->fight_no );

		$actions = array();
		if ( empty( $item->deleted_at ) ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'ufsc-licence-competition' ) );
			$actions['trash'] = sprintf(
				'<a href="%s" class="ufsc-confirm" data-ufsc-confirm="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_trash_fight', 'id' => $item->id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_trash_fight_' . $item->id ) ),
				esc_attr__( 'Mettre ce combat à la corbeille ?', 'ufsc-licence-competition' ),
				esc_html__( 'Mettre à la corbeille', 'ufsc-licence-competition' )
			);
		} else {
			$actions['restore'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_restore_fight', 'id' => $item->id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_restore_fight_' . $item->id ) ),
				esc_html__( 'Restaurer', 'ufsc-licence-competition' )
			);
			if ( Capabilities::user_can_delete() ) {
				$actions['delete'] = sprintf(
					'<a href="%s" class="submitdelete ufsc-confirm" data-ufsc-confirm="%s">%s</a>',
					esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_delete_fight', 'id' => $item->id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_delete_fight_' . $item->id ) ),
					esc_attr__( 'Supprimer définitivement ce combat ?', 'ufsc-licence-competition' ),
					esc_html__( 'Supprimer définitivement', 'ufsc-licence-competition' )
				);
			}
		}

		return $title . $this->row_actions( $actions );
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', absint( $item->id ) );
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'competition':
				return esc_html( $this->get_competition_name( $item->competition_id ) );
			case 'discipline':
				return esc_html( $this->get_competition_discipline( $item->competition_id ) );
			case 'category':
				return esc_html( $this->get_category_name( $item->category_id ) );
			case 'ring':
				return esc_html( $item->ring );
			case 'round_no':
				return esc_html( $item->round_no );
			case 'status':
				return esc_html( $this->format_status( $item->status ) );
			case 'scheduled':
				return esc_html( $this->format_datetime( $item->scheduled_at ) );
			default:
				return '';
		}
	}

	public function no_items() {
		esc_html_e( 'Aucun combat trouvé.', 'ufsc-licence-competition' );
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current = $this->filters['competition_id'] ?? 0;
		$status = $this->filters['status'] ?? '';
		$discipline = $this->filters['discipline'] ?? '';
		$disciplines = DisciplineRegistry::get_disciplines();
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="ufsc_competition_filter"><?php esc_html_e( 'Filtrer par compétition', 'ufsc-licence-competition' ); ?></label>
			<select name="ufsc_competition_id" id="ufsc_competition_filter">
				<option value="0"><?php esc_html_e( 'Toutes les compétitions', 'ufsc-licence-competition' ); ?></option>
				<?php foreach ( $this->competitions as $competition ) : ?>
					<option value="<?php echo esc_attr( $competition->id ); ?>" <?php selected( $current, $competition->id ); ?>><?php echo esc_html( $competition->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="ufsc_discipline_filter"><?php esc_html_e( 'Filtrer par discipline', 'ufsc-licence-competition' ); ?></label>
			<select name="ufsc_discipline" id="ufsc_discipline_filter">
				<option value=""><?php esc_html_e( 'Toutes les disciplines', 'ufsc-licence-competition' ); ?></option>
				<?php foreach ( $disciplines as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $discipline, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="ufsc_status_filter"><?php esc_html_e( 'Filtrer par statut', 'ufsc-licence-competition' ); ?></label>
			<select name="ufsc_status" id="ufsc_status_filter">
				<option value=""><?php esc_html_e( 'Tous les statuts', 'ufsc-licence-competition' ); ?></option>
				<option value="scheduled" <?php selected( $status, 'scheduled' ); ?>><?php esc_html_e( 'Planifié', 'ufsc-licence-competition' ); ?></option>
				<option value="running" <?php selected( $status, 'running' ); ?>><?php esc_html_e( 'En cours', 'ufsc-licence-competition' ); ?></option>
				<option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Terminé', 'ufsc-licence-competition' ); ?></option>
			</select>
			<?php submit_button( __( 'Filtrer', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
		</div>
		<?php
	}

	private function get_page_url() {
		return admin_url( 'admin.php?page=' . $this->get_current_page_slug() );
	}

	private function get_current_page_slug() {
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';
		if ( '' === $page ) {
			return Menu::PAGE_BOUTS;
		}

		return $page;
	}

	private function get_competition_name( $competition_id ) {
		foreach ( $this->competitions as $competition ) {
			if ( (int) $competition->id === (int) $competition_id ) {
				return $competition->name;
			}
		}

		return '';
	}

	private function get_competition_discipline( $competition_id ) {
		foreach ( $this->competitions as $competition ) {
			if ( (int) $competition->id === (int) $competition_id ) {
				return DisciplineRegistry::get_label( $competition->discipline );
			}
		}

		return '';
	}

	private function get_category_name( $category_id ) {
		foreach ( $this->categories as $category ) {
			if ( (int) $category->id === (int) $category_id ) {
				return $category->name;
			}
		}

		return '';
	}

	private function format_status( $status ) {
		$labels = array(
			'scheduled' => __( 'Planifié', 'ufsc-licence-competition' ),
			'running'   => __( 'En cours', 'ufsc-licence-competition' ),
			'completed' => __( 'Terminé', 'ufsc-licence-competition' ),
		);

		return $labels[ $status ] ?? $status;
	}

	private function format_datetime( $date ) {
		return function_exists( 'ufsc_lc_format_datetime' )
			? ufsc_lc_format_datetime( $date, '' )
			: ( $date ? (string) $date : '' );
	}

	private function get_competition_ids_by_discipline( $discipline ) {
		$ids = array();
		foreach ( $this->competitions as $competition ) {
			if ( $discipline === $competition->discipline ) {
				$ids[] = (int) $competition->id;
			}
		}

		return $ids ? $ids : array( 0 );
	}
}
