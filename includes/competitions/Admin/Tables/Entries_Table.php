<?php

namespace UFSC\Competitions\Admin\Tables;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Services\DisciplineRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Entries_Table extends \WP_List_Table {
	private $repository;
	private $competition_repository;
	private $category_repository;
	private $filters = array();
	private $competitions = array();
	private $categories = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ufsc-competition-entry',
				'plural'   => 'ufsc-competition-entries',
				'ajax'     => false,
			)
		);

		$this->repository = new EntryRepository();
		$this->competition_repository = new CompetitionRepository();
		$this->category_repository = new CategoryRepository();
	}

	public function get_filters() {
		return $this->filters;
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'ufsc_competition_entries_per_page', 20 );
		$current_page = max( 1, (int) $this->get_pagenum() );

		$filters = array(
			'view'           => isset( $_REQUEST['ufsc_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_view'] ) ) : 'all',
			'competition_id' => isset( $_REQUEST['ufsc_competition_id'] ) ? absint( $_REQUEST['ufsc_competition_id'] ) : 0,
			'status'         => isset( $_REQUEST['ufsc_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_status'] ) ) : '',
			'discipline'     => isset( $_REQUEST['ufsc_discipline'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_discipline'] ) ) : '',
			'search'         => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
		);

		$this->filters = $filters;
		$this->competitions = $this->competition_repository->list( array( 'view' => 'all' ), 100, 0 );
		$this->categories = $this->category_repository->list( array( 'view' => 'all' ), 500, 0 );

		if ( $filters['discipline'] && ! $filters['competition_id'] ) {
			$filters['competition_ids'] = $this->get_competition_ids_by_discipline( $filters['discipline'] );
		}

		$total_items = $this->repository->count_with_details( $filters );
		$this->items = $this->repository->list_with_details( $filters, $per_page, ( $current_page - 1 ) * $per_page );

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
			'licensee'   => __( 'Licencié', 'ufsc-licence-competition' ),
			'license_number' => __( 'N° licence', 'ufsc-licence-competition' ),
			'birthdate'  => __( 'Date de naissance', 'ufsc-licence-competition' ),
			'birth_year' => __( 'Année de naissance', 'ufsc-licence-competition' ),
			'club'       => __( 'Club', 'ufsc-licence-competition' ),
			'competition'=> __( 'Compétition', 'ufsc-licence-competition' ),
			'discipline' => __( 'Discipline', 'ufsc-licence-competition' ),
			'category'   => __( 'Catégorie', 'ufsc-licence-competition' ),
			'weight'     => __( 'Poids', 'ufsc-licence-competition' ),
			'weight_class' => __( 'Catégorie poids', 'ufsc-licence-competition' ),
			'status'     => __( 'Statut', 'ufsc-licence-competition' ),
			'updated'    => __( 'Mise à jour', 'ufsc-licence-competition' ),
			'actions'    => __( 'Actions', 'ufsc-licence-competition' ),
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

	protected function column_licensee( $item ) {
		$name = $this->format_fallback( $this->format_entry_name( $item ) );
		$edit_url = add_query_arg(
			array(
				'page'        => Menu::PAGE_ENTRIES,
				'ufsc_action' => 'edit',
				'id'          => $item->id,
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<strong><a href="%s">%s</a></strong>', esc_url( $edit_url ), esc_html( $name ) );
	}

	protected function column_actions( $item ) {
		$edit_url = add_query_arg(
			array(
				'page'        => Menu::PAGE_ENTRIES,
				'ufsc_action' => 'edit',
				'id'          => $item->id,
			),
			admin_url( 'admin.php' )
		);

		$actions = array();
		if ( empty( $item->deleted_at ) ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'ufsc-licence-competition' ) );
			$actions['trash'] = sprintf(
				'<a href="%s" class="ufsc-confirm" data-ufsc-confirm="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_trash_entry', 'id' => $item->id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_trash_entry_' . $item->id ) ),
				esc_attr__( 'Mettre cette inscription à la corbeille ?', 'ufsc-licence-competition' ),
				esc_html__( 'Mettre à la corbeille', 'ufsc-licence-competition' )
			);
		} else {
			$actions['restore'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_restore_entry', 'id' => $item->id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_restore_entry_' . $item->id ) ),
				esc_html__( 'Restaurer', 'ufsc-licence-competition' )
			);
			if ( Capabilities::user_can_delete() ) {
				$actions['delete'] = sprintf(
					'<a href="%s" class="submitdelete ufsc-confirm" data-ufsc-confirm="%s">%s</a>',
					esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_delete_entry', 'id' => $item->id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_delete_entry_' . $item->id ) ),
					esc_attr__( 'Supprimer définitivement cette inscription ?', 'ufsc-licence-competition' ),
					esc_html__( 'Supprimer définitivement', 'ufsc-licence-competition' )
				);
			}
		}

		return $this->row_actions( $actions );
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', absint( $item->id ) );
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'license_number':
				return esc_html( $this->format_fallback( $item->license_number ?? '' ) );
			case 'birthdate':
				return esc_html( $this->format_fallback( $item->licensee_birthdate ?? '' ) );
			case 'birth_year':
				return esc_html( $this->format_fallback( $this->format_birth_year( $item->licensee_birthdate ?? '' ) ) );
			case 'club':
				return esc_html( $this->format_fallback( $item->club_name ?? '' ) );
			case 'competition':
				return esc_html( $this->format_fallback( $this->get_competition_name( $item->competition_id ) ) );
			case 'discipline':
				return esc_html( $this->format_fallback( $this->get_competition_discipline( $item->competition_id ) ) );
			case 'category':
				return esc_html( $this->format_fallback( $this->get_category_name( $item->category_id ) ) );
			case 'weight':
				return esc_html( $this->format_fallback( (string) ( $item->weight ?? $item->weight_kg ?? '' ) ) );
			case 'weight_class':
				return esc_html( $this->format_fallback( (string) ( $item->weight_class ?? '' ) ) );
			case 'status':
				return esc_html( $this->format_status( $item ) );
			case 'updated':
				return esc_html( $this->format_datetime( $item->updated_at ) );
			default:
				return esc_html( $this->format_fallback( $item->{$column_name} ?? '' ) );
		}
	}

	public function no_items() {
		esc_html_e( 'Aucune inscription trouvée.', 'ufsc-licence-competition' );
	}

	public function display_rows_or_placeholder() {
		if ( empty( $this->items ) ) {
			echo '<tr class="no-items"><td class="colspanchange" colspan="' . esc_attr( $this->get_column_count() ) . '">';
			$this->no_items();
			echo '</td></tr>';
			return;
		}

		$this->display_rows();
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
			<label class="screen-reader-text" for="ufsc_status_filter"><?php esc_html_e( 'Filtrer par statut', 'ufsc-licence-competition' ); ?></label>
			<select name="ufsc_status" id="ufsc_status_filter">
				<option value=""><?php esc_html_e( 'Tous les statuts', 'ufsc-licence-competition' ); ?></option>
				<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Brouillon', 'ufsc-licence-competition' ); ?></option>
				<option value="submitted" <?php selected( $status, 'submitted' ); ?>><?php esc_html_e( 'Soumise', 'ufsc-licence-competition' ); ?></option>
				<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'En attente', 'ufsc-licence-competition' ); ?></option>
				<option value="approved" <?php selected( $status, 'approved' ); ?>><?php esc_html_e( 'Approuvée', 'ufsc-licence-competition' ); ?></option>
				<option value="rejected" <?php selected( $status, 'rejected' ); ?>><?php esc_html_e( 'Rejetée', 'ufsc-licence-competition' ); ?></option>
				<option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Annulée', 'ufsc-licence-competition' ); ?></option>
			</select>
			<label class="screen-reader-text" for="ufsc_discipline_filter"><?php esc_html_e( 'Filtrer par discipline', 'ufsc-licence-competition' ); ?></label>
			<select name="ufsc_discipline" id="ufsc_discipline_filter">
				<option value=""><?php esc_html_e( 'Toutes les disciplines', 'ufsc-licence-competition' ); ?></option>
				<?php foreach ( $disciplines as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $discipline, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
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
			return Menu::PAGE_ENTRIES;
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

	private function get_competition_ids_by_discipline( $discipline ) {
		$ids = array();
		foreach ( $this->competitions as $competition ) {
			if ( $discipline === $competition->discipline ) {
				$ids[] = (int) $competition->id;
			}
		}

		return $ids ? $ids : array( 0 );
	}

	private function format_entry_name( $item ): string {
		$last = isset( $item->licensee_last_name ) ? (string) $item->licensee_last_name : '';
		$first = isset( $item->licensee_first_name ) ? (string) $item->licensee_first_name : '';
		$name = trim( $last . ' ' . $first );

		if ( '' !== $name ) {
			return $name;
		}

		foreach ( array( 'athlete_name', 'full_name', 'name', 'licensee_name' ) as $key ) {
			if ( isset( $item->{$key} ) && '' !== (string) $item->{$key} ) {
				return (string) $item->{$key};
			}
		}

		$licensee_id = $item->licensee_id ?? $item->licence_id ?? 0;
		if ( $licensee_id ) {
			return sprintf( '#%d', (int) $licensee_id );
		}

		return '';
	}

	private function format_fallback( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = trim( $value );

		return '' !== $value ? $value : '—';
	}

	private function format_status( $entry ) {
		$status = '';
		if ( function_exists( 'ufsc_is_entry_eligible' ) ) {
			$eligibility = ufsc_is_entry_eligible( (int) ( $entry->id ?? 0 ), 'admin_entries' );
			$status = (string) ( $eligibility['status'] ?? '' );
		}
		if ( '' === $status ) {
			$status = $this->repository->get_entry_status( $entry );
		}

		return EntriesWorkflow::get_status_label( $status );
	}

	private function format_datetime( $date ) {
		$value = is_scalar( $date ) ? (string) $date : '';
		$value = trim( $value );
		if ( '' === $value ) {
			return '—';
		}

		return function_exists( 'ufsc_lc_format_datetime' )
			? ufsc_lc_format_datetime( $value )
			: $value;
	}

	private function get_column_count(): int {
		list( $columns ) = $this->get_column_info();

		return is_array( $columns ) ? count( $columns ) : 0;
	}

	private function format_birth_year( $birthdate ): string {
		$birthdate = is_scalar( $birthdate ) ? (string) $birthdate : '';
		if ( preg_match( '/^(\\d{4})-\\d{2}-\\d{2}$/', $birthdate, $matches ) ) {
			return $matches[1];
		}
		if ( preg_match( '/^(\\d{2})\\/(\\d{2})\\/(\\d{4})$/', $birthdate, $matches ) ) {
			return $matches[3];
		}

		return '';
	}
}
