<?php

namespace UFSC\Competitions\Admin\Tables;

use UFSC\Competitions\Admin\Entries_Validation_Menu;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Services\DisciplineRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Entries_Validation_Table extends \WP_List_Table {
	private $repository;
	private $competition_repository;
	private $category_repository;
	private $filters = array();
	private $competitions = array();
	private $categories = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ufsc-competition-entry-validation',
				'plural'   => 'ufsc-competition-entries-validation',
				'ajax'     => false,
			)
		);

		$this->repository = new EntryRepository();
		$this->competition_repository = new CompetitionRepository();
		$this->category_repository = new CategoryRepository();
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'ufsc_competition_entries_validation_per_page', 20 );
		$current_page = max( 1, (int) $this->get_pagenum() );

		$filters = array(
			'competition_id' => isset( $_REQUEST['ufsc_competition_id'] ) ? absint( $_REQUEST['ufsc_competition_id'] ) : 0,
			'club_id' => isset( $_REQUEST['ufsc_club_id'] ) ? absint( $_REQUEST['ufsc_club_id'] ) : 0,
			'status' => isset( $_REQUEST['ufsc_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_status'] ) ) : '',
			'discipline' => isset( $_REQUEST['ufsc_discipline'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_discipline'] ) ) : '',
			'search' => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
		);

		if ( '' === $filters['status'] ) {
			$filters['status'] = 'submitted';
		}

		$this->filters = $filters;
		$this->competitions = $this->competition_repository->list( array( 'view' => 'all' ), 200, 0 );
		$this->categories = $this->category_repository->list( array( 'view' => 'all' ), 500, 0 );

		if ( $filters['discipline'] && ! $filters['competition_id'] ) {
			$filters['competition_ids'] = $this->get_competition_ids_by_discipline( $filters['discipline'] );
		}

		$items = $this->repository->list_with_details( $filters, $per_page, ( $current_page - 1 ) * $per_page );
		$total_items = $this->repository->count_with_details( $filters );

		$this->items = $items;

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page' => $per_page,
			)
		);
	}

	public function get_columns() {
		return array(
			'licensee' => __( 'Licencié', 'ufsc-licence-competition' ),
			'license_number' => __( 'N° licence', 'ufsc-licence-competition' ),
			'birthdate' => __( 'Date de naissance', 'ufsc-licence-competition' ),
			'birth_year' => __( 'Année de naissance', 'ufsc-licence-competition' ),
			'category' => __( 'Catégorie', 'ufsc-licence-competition' ),
			'competition' => __( 'Compétition', 'ufsc-licence-competition' ),
			'club' => __( 'Club', 'ufsc-licence-competition' ),
			'weight' => __( 'Poids', 'ufsc-licence-competition' ),
			'weight_class' => __( 'Catégorie poids', 'ufsc-licence-competition' ),
			'status' => __( 'Statut', 'ufsc-licence-competition' ),
			'submitted' => __( 'Soumise le', 'ufsc-licence-competition' ),
			'updated' => __( 'Mise à jour', 'ufsc-licence-competition' ),
			'actions' => __( 'Actions', 'ufsc-licence-competition' ),
		);
	}

	public function no_items() {
		esc_html_e( 'Aucune inscription trouvée.', 'ufsc-licence-competition' );
	}

	protected function column_licensee( $item ) {
		$name = $this->format_entry_name( $item );
		$title = $name ? sprintf( '<strong>%s</strong>', esc_html( $name ) ) : sprintf( '<strong>#%d</strong>', (int) ( $item->licensee_id ?? $item->licence_id ?? 0 ) );

		return $title;
	}

	protected function column_actions( $item ) {
		$actions = array();
		$status = function_exists( 'ufsc_is_entry_eligible' )
			? (string) ( ufsc_is_entry_eligible( (int) ( $item->id ?? 0 ), 'admin_validation' )['status'] ?? '' )
			: $this->repository->get_entry_status( $item );

		if ( in_array( $status, array( 'submitted', 'pending' ), true ) ) {
			$actions['validate'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->build_admin_action_url( 'ufsc_entry_admin_validate', $item->id, 'ufsc_entry_admin_validate_' . $item->id ) ),
				esc_html__( 'Valider', 'ufsc-licence-competition' )
			);
			$actions['reject'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->build_reject_url( $item->id ) ),
				esc_html__( 'Rejeter', 'ufsc-licence-competition' )
			);
		} elseif ( 'rejected' === $status ) {
			$actions['reopen'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->build_admin_action_url( 'ufsc_entry_admin_reopen', $item->id, 'ufsc_entry_admin_reopen_' . $item->id ) ),
				esc_html__( 'Ré-ouvrir', 'ufsc-licence-competition' )
			);
		}

		return $this->row_actions( $actions );
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'license_number':
				return esc_html( $this->format_fallback( $item->license_number ?? '' ) );
			case 'birthdate':
				return esc_html( $this->format_fallback( $item->licensee_birthdate ?? '' ) );
			case 'birth_year':
				return esc_html( $this->format_fallback( $this->format_birth_year( $item->licensee_birthdate ?? '' ) ) );
			case 'category':
				return esc_html( $this->format_fallback( $this->get_category_name( $item->category_id ?? 0 ) ) );
			case 'competition':
				return esc_html( $this->format_fallback( $this->get_competition_name( $item->competition_id ) ) );
			case 'club':
				return esc_html( $this->format_fallback( $item->club_name ?? (string) ( $item->club_id ?? '' ) ) );
			case 'weight':
				return esc_html( $this->format_fallback( (string) ( $item->weight ?? $item->weight_kg ?? '' ) ) );
			case 'weight_class':
				return esc_html( $this->format_fallback( (string) ( $item->weight_class ?? '' ) ) );
			case 'status':
				return $this->format_status( $item );
			case 'submitted':
				return esc_html( $this->format_datetime( $item->submitted_at ?? '' ) );
			case 'updated':
				return esc_html( $this->format_datetime( $item->updated_at ?? '' ) );
			default:
				return '';
		}
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current_competition = $this->filters['competition_id'] ?? 0;
		$status = $this->filters['status'] ?? '';
		$club_id = $this->filters['club_id'] ?? 0;
		$discipline = $this->filters['discipline'] ?? '';
		$disciplines = DisciplineRegistry::get_disciplines();
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="ufsc_competition_filter"><?php esc_html_e( 'Filtrer par compétition', 'ufsc-licence-competition' ); ?></label>
			<select name="ufsc_competition_id" id="ufsc_competition_filter">
				<option value="0"><?php esc_html_e( 'Toutes les compétitions', 'ufsc-licence-competition' ); ?></option>
				<?php foreach ( $this->competitions as $competition ) : ?>
					<option value="<?php echo esc_attr( $competition->id ); ?>" <?php selected( $current_competition, $competition->id ); ?>><?php echo esc_html( $competition->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="ufsc_status_filter"><?php esc_html_e( 'Filtrer par statut', 'ufsc-licence-competition' ); ?></label>
			<select name="ufsc_status" id="ufsc_status_filter">
				<option value=""><?php esc_html_e( 'Tous les statuts', 'ufsc-licence-competition' ); ?></option>
				<?php foreach ( EntriesWorkflow::get_status_labels() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="ufsc_discipline_filter"><?php esc_html_e( 'Filtrer par discipline', 'ufsc-licence-competition' ); ?></label>
			<select name="ufsc_discipline" id="ufsc_discipline_filter">
				<option value=""><?php esc_html_e( 'Toutes les disciplines', 'ufsc-licence-competition' ); ?></option>
				<?php foreach ( $disciplines as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $discipline, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="ufsc_club_filter"><?php esc_html_e( 'Filtrer par club', 'ufsc-licence-competition' ); ?></label>
			<input type="number" name="ufsc_club_id" id="ufsc_club_filter" placeholder="<?php echo esc_attr__( 'Club ID', 'ufsc-licence-competition' ); ?>" value="<?php echo esc_attr( $club_id ); ?>" />
			<?php submit_button( __( 'Filtrer', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
		</div>
		<?php
	}

	private function format_entry_name( $entry ): string {
		$last = isset( $entry->licensee_last_name ) ? (string) $entry->licensee_last_name : '';
		$first = isset( $entry->licensee_first_name ) ? (string) $entry->licensee_first_name : '';
		$name = trim( $last . ' ' . $first );

		if ( '' !== $name ) {
			return $name;
		}

		$keys = array( 'athlete_name', 'full_name', 'name', 'licensee_name' );
		foreach ( $keys as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				return (string) $entry->{$key};
			}
		}

		return '';
	}

	private function format_status( $entry ): string {
		$status = function_exists( 'ufsc_is_entry_eligible' )
			? (string) ( ufsc_is_entry_eligible( (int) ( $entry->id ?? 0 ), 'admin_validation' )['status'] ?? '' )
			: $this->repository->get_entry_status( $entry );
		$label = EntriesWorkflow::get_status_label( $status );
		$class = EntriesWorkflow::get_status_badge_class( $status );

		$reason = '';
		if ( 'rejected' === $status && ! empty( $entry->rejected_reason ) ) {
			$reason = '<br /><small>' . esc_html( (string) $entry->rejected_reason ) . '</small>';
		}

		return sprintf(
			'<span class="ufsc-badge %s">%s</span>%s',
			esc_attr( $class ),
			esc_html( $label ),
			$reason
		);
	}

	private function format_datetime( $value ): string {
		return function_exists( 'ufsc_lc_format_datetime' )
			? ufsc_lc_format_datetime( $value )
			: ( $value ? (string) $value : '—' );
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

	private function get_competition_name( $competition_id ): string {
		foreach ( $this->competitions as $competition ) {
			if ( (int) $competition->id === (int) $competition_id ) {
				return (string) $competition->name;
			}
		}

		return '';
	}

	private function get_category_name( $category_id ): string {
		foreach ( $this->categories as $category ) {
			if ( (int) $category->id === (int) $category_id ) {
				return (string) $category->name;
			}
		}

		return '';
	}

	private function get_competition_ids_by_discipline( string $discipline ): array {
		$ids = array();
		foreach ( $this->competitions as $competition ) {
			if ( $discipline === (string) ( $competition->discipline ?? '' ) ) {
				$ids[] = (int) $competition->id;
			}
		}

		return $ids ? $ids : array( 0 );
	}

	private function format_fallback( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = trim( $value );

		return '' !== $value ? $value : '—';
	}

	private function build_admin_action_url( string $action, int $entry_id, string $nonce_action ): string {
		$url = add_query_arg(
			array(
				'action' => $action,
				'entry_id' => $entry_id,
				'redirect_to' => admin_url( 'admin.php?page=' . Entries_Validation_Menu::PAGE_SLUG ),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, $nonce_action );
	}

	private function build_reject_url( int $entry_id ): string {
		return add_query_arg(
			array(
				'page' => Entries_Validation_Menu::PAGE_SLUG,
				'ufsc_action' => 'reject',
				'entry_id' => $entry_id,
			),
			admin_url( 'admin.php' )
		);
	}
}
