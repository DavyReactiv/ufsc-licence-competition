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
	private $columns_state = array();
	private $has_logged_state = false;

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

		$columns = $this->get_columns();
		$sortable = $this->get_sortable_columns();
		$hidden = (array) $this->get_hidden_columns();
		$primary = method_exists( $this, 'get_primary_column_name' ) ? $this->get_primary_column_name() : 'licensee';
		if ( ! isset( $columns[ $primary ] ) ) {
			$primary = is_array( $columns ) && $columns ? (string) array_key_first( $columns ) : 'licensee';
		}

		$this->_column_headers = array(
			$columns,
			$hidden,
			$sortable,
			$primary,
		);

		$this->columns_state['primary'] = $primary;
		$this->maybe_log_columns_state();
	}

	public function get_columns() {
		$columns = array(
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

		$validation = $this->validate_columns( $columns );
		$columns = $validation['columns'];
		$used_fallback = empty( $columns );
		if ( $used_fallback ) {
			$columns = $this->get_fallback_columns( ! empty( $this->get_bulk_actions() ) );
		}

		$this->columns_state = array(
			'columns'               => $columns,
			'columns_count'         => count( $columns ),
			'non_empty_labels_count'=> $this->count_non_empty_labels( $columns ),
			'used_fallback'         => $used_fallback,
			'invalid_columns'       => $validation['invalid_columns'],
		);

		return $columns;
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
		$entry_id = (int) $this->get_item_value( $item, 'id' );
		$edit_url = add_query_arg(
			array(
				'page'        => Menu::PAGE_ENTRIES,
				'ufsc_action' => 'edit',
				'id'          => $entry_id,
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<strong><a href="%s">%s</a></strong>', esc_url( $edit_url ), esc_html( $name ) );
	}

	protected function column_actions( $item ) {
		$entry_id = (int) $this->get_item_value( $item, 'id' );
		$deleted_at = $this->get_item_value( $item, 'deleted_at' );
		$edit_url = add_query_arg(
			array(
				'page'        => Menu::PAGE_ENTRIES,
				'ufsc_action' => 'edit',
				'id'          => $entry_id,
			),
			admin_url( 'admin.php' )
		);

		$actions = array();
		if ( empty( $deleted_at ) ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Modifier', 'ufsc-licence-competition' ) );
			$actions['trash'] = sprintf(
				'<a href="%s" class="ufsc-confirm" data-ufsc-confirm="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_trash_entry', 'id' => $entry_id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_trash_entry_' . $entry_id ) ),
				esc_attr__( 'Mettre cette inscription à la corbeille ?', 'ufsc-licence-competition' ),
				esc_html__( 'Mettre à la corbeille', 'ufsc-licence-competition' )
			);
		} else {
			$actions['restore'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_restore_entry', 'id' => $entry_id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_restore_entry_' . $entry_id ) ),
				esc_html__( 'Restaurer', 'ufsc-licence-competition' )
			);
			if ( Capabilities::user_can_delete() ) {
				$actions['delete'] = sprintf(
					'<a href="%s" class="submitdelete ufsc-confirm" data-ufsc-confirm="%s">%s</a>',
					esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ufsc_competitions_delete_entry', 'id' => $entry_id ), admin_url( 'admin-post.php' ) ), 'ufsc_competitions_delete_entry_' . $entry_id ) ),
					esc_attr__( 'Supprimer définitivement cette inscription ?', 'ufsc-licence-competition' ),
					esc_html__( 'Supprimer définitivement', 'ufsc-licence-competition' )
				);
			}
		}

		return $this->row_actions( $actions );
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', absint( $this->get_item_value( $item, 'id' ) ) );
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'name':
			case 'licensee':
				return esc_html( $this->format_fallback( $this->format_entry_name( $item ) ) );
			case 'license_number':
				return esc_html( $this->format_fallback( $this->get_item_value( $item, 'license_number' ) ) );
			case 'birthdate':
				return esc_html( $this->format_fallback( $this->get_item_value_from_keys( $item, array( 'licensee_birthdate', 'birthdate' ) ) ) );
			case 'birth_year':
				return esc_html( $this->format_fallback( $this->format_birth_year( $this->get_item_value_from_keys( $item, array( 'licensee_birthdate', 'birthdate' ) ) ) ) );
			case 'club':
				return esc_html( $this->format_fallback( $this->get_item_value_from_keys( $item, array( 'club_name', 'club', 'club_label' ) ) ) );
			case 'competition':
				return esc_html( $this->format_fallback( $this->get_competition_name( $this->get_item_value( $item, 'competition_id' ) ) ) );
			case 'discipline':
				return esc_html( $this->format_fallback( $this->get_competition_discipline( $this->get_item_value( $item, 'competition_id' ) ) ) );
			case 'category':
				$category_id = $this->get_item_value( $item, 'category_id' );
				$category_name = $category_id ? $this->get_category_name( $category_id ) : $this->get_item_value_from_keys( $item, array( 'category_name', 'category', 'category_label' ) );
				return esc_html( $this->format_fallback( $category_name ) );
			case 'weight':
				return esc_html( $this->format_fallback( $this->get_item_value_from_keys( $item, array( 'weight', 'weight_kg' ) ) ) );
			case 'weight_class':
				return esc_html( $this->format_fallback( $this->get_item_value_from_keys( $item, array( 'weight_class', 'weight_category' ) ) ) );
			case 'status':
				return esc_html( $this->format_fallback( $this->format_status( $item ) ) );
			case 'updated':
				return esc_html( $this->format_datetime( $this->get_item_value_from_keys( $item, array( 'updated_at', 'updated' ) ) ) );
			case 'updated_at':
				return esc_html( $this->format_datetime( $this->get_item_value_from_keys( $item, array( 'updated_at', 'updated' ) ) ) );
			case 'actions':
				return $this->column_actions( $item );
			case 'cb':
				return $this->column_cb( $item );
			default:
				return esc_html( $this->format_fallback( $this->get_item_value( $item, $column_name ) ) );
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
		$last = (string) $this->get_item_value( $item, 'licensee_last_name' );
		$first = (string) $this->get_item_value( $item, 'licensee_first_name' );
		$name = trim( $last . ' ' . $first );

		if ( '' !== $name ) {
			return $name;
		}

		foreach ( array( 'athlete_name', 'full_name', 'name', 'licensee_name' ) as $key ) {
			$value = $this->get_item_value( $item, $key );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}

		$licensee_id = $this->get_item_value_from_keys( $item, array( 'licensee_id', 'licence_id' ) );
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

	public function get_column_count(): int {
		if ( method_exists( '\WP_List_Table', 'get_column_count' ) ) {
			return (int) parent::get_column_count();
		}

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

	private function get_fallback_columns( bool $with_bulk = true ): array {
		$columns = array(
			'licensee'     => __( 'Licencié', 'ufsc-licence-competition' ),
			'competition'  => __( 'Compétition', 'ufsc-licence-competition' ),
			'category'     => __( 'Catégorie', 'ufsc-licence-competition' ),
			'weight_class' => __( 'Catégorie poids', 'ufsc-licence-competition' ),
			'status'       => __( 'Statut', 'ufsc-licence-competition' ),
			'updated_at'   => __( 'Mise à jour', 'ufsc-licence-competition' ),
			'actions'      => __( 'Actions', 'ufsc-licence-competition' ),
		);

		if ( $with_bulk ) {
			$columns = array_merge(
				array( 'cb' => '<input type="checkbox" />' ),
				$columns
			);
		}

		return $columns;
	}

	private function validate_columns( $columns ): array {
		if ( ! is_array( $columns ) ) {
			return array(
				'columns' => array(),
				'invalid_columns' => array( 'not_array' ),
			);
		}

		$filtered = array();
		$invalid_columns = array();
		foreach ( $columns as $key => $label ) {
			$key_valid = is_string( $key ) && '' !== trim( $key );
			$label_valid = is_string( $label ) && '' !== trim( $label );
			if ( $key_valid && $label_valid ) {
				$filtered[ $key ] = $label;
				continue;
			}
			$invalid_columns[ (string) $key ] = is_scalar( $label ) ? (string) $label : '';
		}

		return array(
			'columns' => $filtered,
			'invalid_columns' => $invalid_columns,
		);
	}

	private function count_non_empty_labels( array $columns ): int {
		$count = 0;
		foreach ( $columns as $label ) {
			if ( is_string( $label ) && '' !== trim( $label ) ) {
				$count++;
			}
		}

		return $count;
	}

	private function maybe_log_columns_state(): void {
		if ( $this->has_logged_state ) {
			return;
		}
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$columns = $this->columns_state['columns'] ?? array();
		$items = is_array( $this->items ) ? $this->items : array();
		$items_count = is_countable( $items ) ? count( $items ) : 0;
		$first_item = $items_count ? $items[0] : null;
		$item_keys = $first_item ? $this->get_item_keys( $first_item ) : array();
		$item_keys = array_slice( $item_keys, 0, 20 );
		$primary = $this->columns_state['primary'] ?? '';
		$columns_dump = wp_json_encode( $columns );

		$log_parts = array(
			sprintf( 'columns=%s', is_string( $columns_dump ) ? $columns_dump : 'n/a' ),
			sprintf( 'primary=%s', $primary ? $primary : 'n/a' ),
			sprintf( 'items=%d', (int) $items_count ),
			sprintf( 'used_fallback_columns=%s', ! empty( $this->columns_state['used_fallback'] ) ? 'yes' : 'no' ),
			sprintf( 'item_keys=%s', $item_keys ? implode( ',', $item_keys ) : 'n/a' ),
		);

		if ( ! empty( $this->columns_state['used_fallback'] ) && ! empty( $this->columns_state['invalid_columns'] ) ) {
			$invalid = wp_json_encode( $this->columns_state['invalid_columns'] );
			if ( is_string( $invalid ) ) {
				$log_parts[] = 'invalid_columns=' . $invalid;
			}
		}

		error_log( 'UFSC Entries_Table ' . implode( ' ', $log_parts ) );
		$this->has_logged_state = true;
	}

	private function get_item_keys( $item ): array {
		if ( is_array( $item ) ) {
			return array_keys( $item );
		}
		if ( is_object( $item ) ) {
			return array_keys( get_object_vars( $item ) );
		}

		return array();
	}

	private function get_item_value( $item, string $key ) {
		if ( is_array( $item ) && array_key_exists( $key, $item ) ) {
			return $item[ $key ];
		}
		if ( is_object( $item ) && property_exists( $item, $key ) ) {
			return $item->{$key};
		}

		return '';
	}

	private function get_item_value_from_keys( $item, array $keys ) {
		foreach ( $keys as $key ) {
			$value = $this->get_item_value( $item, $key );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}
}
