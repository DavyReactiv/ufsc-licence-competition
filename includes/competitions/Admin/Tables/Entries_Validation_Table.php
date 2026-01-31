<?php

namespace UFSC\Competitions\Admin\Tables;

use UFSC\Competitions\Admin\Entries_Validation_Menu;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Entries_Validation_Table extends \WP_List_Table {
	private $repository;
	private $competition_repository;
	private $filters = array();
	private $competitions = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ufsc-competition-entry-validation',
				'plural'   => 'ufsc-competition-entries-validation',
				'ajax'     => false,
			)
		);

		$this->repository = new EntryFrontRepository();
		$this->competition_repository = new CompetitionRepository();
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'ufsc_competition_entries_validation_per_page', 20 );
		$current_page = max( 1, (int) $this->get_pagenum() );

		$filters = array(
			'competition_id' => isset( $_REQUEST['ufsc_competition_id'] ) ? absint( $_REQUEST['ufsc_competition_id'] ) : 0,
			'club_id' => isset( $_REQUEST['ufsc_club_id'] ) ? absint( $_REQUEST['ufsc_club_id'] ) : 0,
			'status' => isset( $_REQUEST['ufsc_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['ufsc_status'] ) ) : '',
			'search' => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
		);

		if ( '' === $filters['status'] ) {
			$filters['status'] = 'submitted';
		}

		$this->filters = $filters;
		$this->competitions = $this->competition_repository->list( array( 'view' => 'all' ), 200, 0 );

		$items = $this->get_entries( $filters, $per_page, ( $current_page - 1 ) * $per_page );
		$total_items = $this->count_entries( $filters );

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
			'competition' => __( 'Compétition', 'ufsc-licence-competition' ),
			'club' => __( 'Club', 'ufsc-licence-competition' ),
			'weight' => __( 'Poids', 'ufsc-licence-competition' ),
			'weight_class' => __( 'Catégorie poids', 'ufsc-licence-competition' ),
			'status' => __( 'Statut', 'ufsc-licence-competition' ),
			'submitted' => __( 'Soumise le', 'ufsc-licence-competition' ),
			'updated' => __( 'Mise à jour', 'ufsc-licence-competition' ),
		);
	}

	public function no_items() {
		esc_html_e( 'Aucune inscription trouvée.', 'ufsc-licence-competition' );
	}

	protected function column_licensee( $item ) {
		$name = $this->format_entry_name( $item );
		$title = $name ? sprintf( '<strong>%s</strong>', esc_html( $name ) ) : sprintf( '<strong>#%d</strong>', (int) ( $item->licensee_id ?? 0 ) );

		$actions = array();
		$status = $this->repository->get_entry_status( $item );

		if ( 'submitted' === $status ) {
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

		return $title . $this->row_actions( $actions );
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'competition':
				return esc_html( $this->get_competition_name( $item->competition_id ) );
			case 'club':
				return esc_html( (string) ( $item->club_id ?? '' ) );
			case 'weight':
				return esc_html( (string) ( $item->weight ?? $item->weight_kg ?? '' ) );
			case 'weight_class':
				return esc_html( (string) ( $item->weight_class ?? '' ) );
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
			<label class="screen-reader-text" for="ufsc_club_filter"><?php esc_html_e( 'Filtrer par club', 'ufsc-licence-competition' ); ?></label>
			<input type="number" name="ufsc_club_id" id="ufsc_club_filter" placeholder="<?php echo esc_attr__( 'Club ID', 'ufsc-licence-competition' ); ?>" value="<?php echo esc_attr( $club_id ); ?>" />
			<?php submit_button( __( 'Filtrer', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
		</div>
		<?php
	}

	private function format_entry_name( $entry ): string {
		$keys = array( 'athlete_name', 'full_name', 'name', 'licensee_name' );
		foreach ( $keys as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				return (string) $entry->{$key};
			}
		}

		$first = '';
		$last = '';
		foreach ( array( 'first_name', 'firstname', 'prenom' ) as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				$first = (string) $entry->{$key};
				break;
			}
		}
		foreach ( array( 'last_name', 'lastname', 'nom' ) as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				$last = (string) $entry->{$key};
				break;
			}
		}

		return trim( $first . ' ' . $last );
	}

	private function format_status( $entry ): string {
		$status = $this->repository->get_entry_status( $entry );
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
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		return $value;
	}

	private function get_competition_name( $competition_id ): string {
		foreach ( $this->competitions as $competition ) {
			if ( (int) $competition->id === (int) $competition_id ) {
				return (string) $competition->name;
			}
		}

		return '';
	}

	private function get_entries( array $filters, int $limit, int $offset ): array {
		global $wpdb;

		$where = array( 'deleted_at IS NULL' );
		$status_field = $this->repository->get_status_storage_field();

		if ( ! empty( $filters['competition_id'] ) ) {
			$where[] = $wpdb->prepare( 'competition_id = %d', absint( $filters['competition_id'] ) );
		}

		if ( ! empty( $filters['club_id'] ) ) {
			$where[] = $wpdb->prepare( 'club_id = %d', absint( $filters['club_id'] ) );
		}

		if ( ! empty( $filters['status'] ) && 'status' === $status_field ) {
			$where[] = $wpdb->prepare( 'status = %s', sanitize_key( $filters['status'] ) );
		}

		if ( ! empty( $filters['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = $wpdb->prepare( 'licensee_id LIKE %s', $like );
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$table = \UFSC\Competitions\Db::entries_table();
		$order_by = 'created_at DESC';
		if ( $this->repository->has_entry_column( 'submitted_at' ) ) {
			$order_by = 'submitted_at DESC, created_at DESC';
		}
		$sql = "SELECT * FROM {$table} {$where_sql} ORDER BY {$order_by}";
		$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', absint( $limit ), absint( $offset ) );

		$items = $wpdb->get_results( $sql );

		return is_array( $items ) ? $items : array();
	}

	private function count_entries( array $filters ): int {
		global $wpdb;

		$where = array( 'deleted_at IS NULL' );
		$status_field = $this->repository->get_status_storage_field();

		if ( ! empty( $filters['competition_id'] ) ) {
			$where[] = $wpdb->prepare( 'competition_id = %d', absint( $filters['competition_id'] ) );
		}

		if ( ! empty( $filters['club_id'] ) ) {
			$where[] = $wpdb->prepare( 'club_id = %d', absint( $filters['club_id'] ) );
		}

		if ( ! empty( $filters['status'] ) && 'status' === $status_field ) {
			$where[] = $wpdb->prepare( 'status = %s', sanitize_key( $filters['status'] ) );
		}

		if ( ! empty( $filters['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = $wpdb->prepare( 'licensee_id LIKE %s', $like );
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$table = \UFSC\Competitions\Db::entries_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
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
