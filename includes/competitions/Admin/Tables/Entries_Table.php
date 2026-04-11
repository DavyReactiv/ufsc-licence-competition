<?php

namespace UFSC\Competitions\Admin\Tables;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Services\DisciplineRegistry;
use UFSC\Competitions\Services\FighterNumberService;

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
	private $fighter_numbers_by_entry = array();

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
			'participant_type' => isset( $_REQUEST['ufsc_participant_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_participant_type'] ) ) : '',
			'group_label'    => isset( $_REQUEST['ufsc_group_label'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ufsc_group_label'] ) ) : '',
			'club_affiliation' => isset( $_REQUEST['ufsc_club_affiliation'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_club_affiliation'] ) ) : '',
			'search'         => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
		);

		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$filters = ufsc_lc_competitions_apply_scope_to_query_args( $filters );
		}

		$this->filters = $filters;
		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$this->competitions = $this->competition_repository->list( $competition_filters, 100, 0 );
		$this->categories = $this->category_repository->list( array( 'view' => 'all' ), 500, 0 );

		if ( $filters['discipline'] && ! $filters['competition_id'] ) {
			$filters['competition_ids'] = $this->get_competition_ids_by_discipline( $filters['discipline'] );
		}

		$total_items = $this->repository->count_with_details( $filters );
		$this->items = $this->repository->list_with_details( $filters, $per_page, ( $current_page - 1 ) * $per_page );
		$this->fighter_numbers_by_entry = $this->build_fighter_number_map_for_items( $this->items );

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
			'last_name'  => __( 'Nom', 'ufsc-licence-competition' ),
			'first_name' => __( 'Prénom', 'ufsc-licence-competition' ),
			'license_number' => __( 'N° licence', 'ufsc-licence-competition' ),
			'fighter_number' => __( 'N° combattant', 'ufsc-licence-competition' ),
			'birthdate'  => __( 'Date de naissance', 'ufsc-licence-competition' ),
			'birth_year' => __( 'Année de naissance', 'ufsc-licence-competition' ),
			'club'       => __( 'Club', 'ufsc-licence-competition' ),
			'competition'=> __( 'Compétition', 'ufsc-licence-competition' ),
			'discipline' => __( 'Discipline', 'ufsc-licence-competition' ),
			'participant_type' => __( 'Type participant', 'ufsc-licence-competition' ),
			'category'   => __( 'Catégorie', 'ufsc-licence-competition' ),
			'weight'     => __( 'Poids', 'ufsc-licence-competition' ),
			'weight_class' => __( 'Catégorie poids', 'ufsc-licence-competition' ),
			'level'      => __( 'Niveau / Classe', 'ufsc-licence-competition' ),
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
			$actions['set_draft'] = __( 'Passer en brouillon', 'ufsc-licence-competition' );
			$actions['set_submitted'] = __( 'Passer à valider', 'ufsc-licence-competition' );
			$actions['set_approved'] = __( 'Approuver', 'ufsc-licence-competition' );
			$actions['set_rejected'] = __( 'Rejeter', 'ufsc-licence-competition' );
			$actions['set_group'] = __( 'Affecter un groupe', 'ufsc-licence-competition' );
			$actions['clear_group'] = __( 'Retirer du groupe', 'ufsc-licence-competition' );
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
				return esc_html( $this->format_fallback( $this->get_item_value_from_keys( $item, array( 'license_number', 'licence_number', 'licensee_number', 'license', 'licence' ) ) ) );
			case 'fighter_number':
				$entry_id = (int) $this->get_item_value( $item, 'id' );
				$fighter_number = (int) $this->get_item_value_from_keys( $item, array( 'fighter_number', 'competition_number' ) );
				if ( $fighter_number <= 0 && $entry_id > 0 ) {
					$fighter_number = (int) ( $this->fighter_numbers_by_entry[ $entry_id ] ?? 0 );
				}
				return $fighter_number > 0 ? '#' . esc_html( (string) $fighter_number ) : '—';
			case 'last_name':
				return esc_html( $this->format_fallback( $this->get_item_value_from_keys( $item, array( 'licensee_last_name', 'last_name', 'lastname', 'nom' ) ) ) );
			case 'first_name':
				return esc_html( $this->format_fallback( $this->get_item_value_from_keys( $item, array( 'licensee_first_name', 'first_name', 'firstname', 'prenom' ) ) ) );
			case 'birthdate':
				return esc_html( $this->format_fallback( $this->get_item_value_from_keys( $item, array( 'licensee_birthdate', 'birth_date', 'birthdate', 'date_of_birth', 'dob' ) ) ) );
			case 'birth_year':
				return esc_html( $this->format_fallback( $this->format_birth_year( $this->get_item_value_from_keys( $item, array( 'licensee_birthdate', 'birth_date', 'birthdate', 'date_of_birth', 'dob' ) ) ) ) );
			case 'club':
				return $this->format_club_display( $item );
			case 'competition':
				return esc_html( $this->format_fallback( $this->get_competition_name( $this->get_item_value( $item, 'competition_id' ) ) ) );
			case 'discipline':
				return esc_html( $this->format_fallback( $this->get_competition_discipline( $this->get_item_value( $item, 'competition_id' ) ) ) );
			case 'participant_type':
				$type = sanitize_key( (string) $this->get_item_value_from_keys( $item, array( 'participant_type' ) ) );
				if ( 'external_non_licensed' === $type ) {
					return esc_html__( 'Non licencié UFSC', 'ufsc-licence-competition' );
				}
				return esc_html__( 'Licencié UFSC', 'ufsc-licence-competition' );
			case 'category':
				$category_name = $this->resolve_category_label( $item );
				return $this->format_with_empty_badge( $category_name, __( 'Non renseignée', 'ufsc-licence-competition' ) );
			case 'weight':
				return esc_html( $this->format_fallback( $this->get_item_value_from_keys( $item, array( 'weight', 'weight_kg' ) ) ) );
			case 'weight_class':
				return esc_html( $this->format_fallback( $this->get_item_value_from_keys( $item, array( 'weight_class', 'weight_category' ) ) ) );
			case 'level':
				return $this->format_with_empty_badge(
					$this->get_item_value_from_keys( $item, array( 'level', 'class', 'classe' ) ),
					__( 'Non défini', 'ufsc-licence-competition' )
				);
			case 'status':
				return $this->format_status( $item );
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
		$participant_type = $this->filters['participant_type'] ?? '';
		$group_label = $this->filters['group_label'] ?? '';
		$club_affiliation = $this->filters['club_affiliation'] ?? '';
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
				<?php foreach ( EntriesWorkflow::get_status_labels() as $status_code => $status_label ) : ?>
					<option value="<?php echo esc_attr( $status_code ); ?>" <?php selected( $status, $status_code ); ?>><?php echo esc_html( $status_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="ufsc_discipline_filter"><?php esc_html_e( 'Filtrer par discipline', 'ufsc-licence-competition' ); ?></label>
			<select name="ufsc_discipline" id="ufsc_discipline_filter">
				<option value=""><?php esc_html_e( 'Toutes les disciplines', 'ufsc-licence-competition' ); ?></option>
				<?php foreach ( $disciplines as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $discipline, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="ufsc_participant_type_filter"><?php esc_html_e( 'Filtrer par type participant', 'ufsc-licence-competition' ); ?></label>
			<select name="ufsc_participant_type" id="ufsc_participant_type_filter">
				<option value=""><?php esc_html_e( 'Tous types', 'ufsc-licence-competition' ); ?></option>
				<option value="licensed_ufsc" <?php selected( $participant_type, 'licensed_ufsc' ); ?>><?php esc_html_e( 'Licencié UFSC', 'ufsc-licence-competition' ); ?></option>
				<option value="external_non_licensed" <?php selected( $participant_type, 'external_non_licensed' ); ?>><?php esc_html_e( 'Non licencié UFSC', 'ufsc-licence-competition' ); ?></option>
			</select>
			<input type="text" name="ufsc_group_label" value="<?php echo esc_attr( $group_label ); ?>" placeholder="<?php echo esc_attr__( 'Groupe / lot', 'ufsc-licence-competition' ); ?>" />
			<select name="ufsc_club_affiliation" id="ufsc_club_affiliation_filter">
				<option value=""><?php esc_html_e( 'Tous les clubs', 'ufsc-licence-competition' ); ?></option>
				<option value="non_affiliated" <?php selected( $club_affiliation, 'non_affiliated' ); ?>><?php esc_html_e( 'Club non affilié', 'ufsc-licence-competition' ); ?></option>
				<option value="noclub" <?php selected( $club_affiliation, 'noclub' ); ?>><?php esc_html_e( 'Sans club (noclub)', 'ufsc-licence-competition' ); ?></option>
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

	private function build_fighter_number_map_for_items( array $items ): array {
		$entry_ids_by_competition = array();
		foreach ( $items as $item ) {
			$entry_id = (int) ( $item->id ?? 0 );
			$competition_id = (int) ( $item->competition_id ?? 0 );
			if ( $entry_id > 0 && $competition_id > 0 ) {
				$entry_ids_by_competition[ $competition_id ][ $entry_id ] = $entry_id;
			}
		}

		$map = array();
		foreach ( $entry_ids_by_competition as $competition_id => $entry_ids ) {
			$map += FighterNumberService::build_map( (int) $competition_id, array_values( $entry_ids ) );
		}

		return $map;
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

	private function get_competition_season_end_year( $competition_id ): string {
		foreach ( $this->competitions as $competition ) {
			if ( (int) $competition->id === (int) $competition_id ) {
				return isset( $competition->season ) ? (string) $competition->season : '';
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

		$category = $this->category_repository->get( (int) $category_id, true );
		if ( $category ) {
			return (string) ( $category->name ?? '' );
		}

		return '';
	}

	private function resolve_category_label( $item ): string {
		$label = $this->get_item_value_from_keys( $item, array( 'category', 'category_name', 'category_label', 'category_title' ) );
		if ( '' !== $label ) {
			return $label;
		}

		$category_id = $this->get_item_value( $item, 'category_id' );
		if ( $category_id ) {
			$category_name = $this->get_category_name( $category_id );
			if ( '' !== $category_name ) {
				return $category_name;
			}
		}

		$birth_date = $this->get_item_value_from_keys( $item, array( 'birth_date', 'birthdate', 'date_of_birth', 'dob', 'licensee_birthdate' ) );
		if ( '' !== $birth_date && function_exists( 'ufsc_lc_compute_category_from_birthdate' ) ) {
			$competition_id = $this->get_item_value( $item, 'competition_id' );
			$season_end_year = $competition_id ? $this->get_competition_season_end_year( $competition_id ) : '';
			if ( '' !== $season_end_year ) {
				$computed = ufsc_lc_compute_category_from_birthdate( $birth_date, $season_end_year );
				if ( '' !== $computed ) {
					return $computed;
				}
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

		$entry_last = $this->get_item_value_from_keys( $item, array( 'last_name', 'lastname', 'nom', 'family_name' ) );
		$entry_first = $this->get_item_value_from_keys( $item, array( 'first_name', 'firstname', 'prenom', 'given_name' ) );
		$entry_name = trim( $entry_last . ' ' . $entry_first );
		if ( '' !== $entry_name ) {
			return $entry_name;
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

	private function format_with_empty_badge( $value, string $empty_label ): string {
		$formatted = $this->format_fallback( $value );
		if ( '—' !== $formatted ) {
			return esc_html( $formatted );
		}

		return sprintf(
			'<span class="ufsc-badge ufsc-badge-warning">⚠️ %s</span>',
			esc_html( $empty_label )
		);
	}

	private function format_club_display( $item ): string {
		$club_name = $this->format_fallback( $this->get_item_value_from_keys( $item, array( 'club_name', 'club_nom', 'club', 'club_label' ) ) );
		$club_id   = absint( $this->get_item_value( $item, 'club_id' ) );
		$source    = sanitize_key( (string) $this->get_item_value_from_keys( $item, array( 'club_source', 'club_status' ) ) );
		$lowered   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $club_name ) : strtolower( $club_name );

		$badge = '';
		if ( 0 === $club_id && 'noclub' === $lowered ) {
			$badge = sprintf(
				' <span class="ufsc-badge ufsc-badge--muted">%s</span>',
				esc_html__( 'Sans club', 'ufsc-licence-competition' )
			);
		} elseif ( 0 === $club_id && in_array( $source, array( 'csv', 'external', 'invited' ), true ) ) {
			$badge = sprintf(
				' <span class="ufsc-badge ufsc-badge--warning">%s</span>',
				esc_html__( 'Club non affilié', 'ufsc-licence-competition' )
			);
		}

		return esc_html( $club_name ) . $badge;
	}

	private function format_status( $entry ): string {
		$status = '';
		if ( function_exists( 'ufsc_lc_is_entry_eligible' ) ) {
			$eligibility = ufsc_lc_is_entry_eligible( (int) ( $entry->id ?? 0 ), 'admin_entries' );
			$status = (string) ( $eligibility['status'] ?? '' );
		}
		if ( '' === $status ) {
			$status = $this->repository->get_entry_status( $entry );
		}

		$label = EntriesWorkflow::get_status_label( $status );
		$class = EntriesWorkflow::get_status_badge_class( $status );

		return sprintf(
			'<span class="ufsc-badge %s">%s</span>',
			esc_attr( $class ),
			esc_html( $label )
		);
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
