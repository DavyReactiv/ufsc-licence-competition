<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Db;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Admin\Tables\Entries_Table;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Front\Entries\EntriesModule;
use UFSC\Competitions\Services\EntryDeduplication;
use UFSC\Competitions\Services\WeightCategoryResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Entries_Page {
	private $repository;
	private $competition_repository;
	private $category_repository;

	public function __construct() {
		$this->repository = new EntryRepository();
		$this->competition_repository = new CompetitionRepository();
		$this->category_repository = new CategoryRepository();
	}

	public function register_actions() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_action( 'admin_post_ufsc_competitions_save_entry', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_entry', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_entry', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_entry', array( $this, 'handle_delete' ) );
		add_action( 'wp_ajax_ufsc_lc_search_licence', array( $this, 'ajax_search_licence' ) );
		add_action( 'wp_ajax_ufsc_lc_get_licensee', array( $this, 'ajax_get_licensee' ) );
		add_action( 'wp_ajax_ufsc_lc_resolve_weight_class', array( $this, 'ajax_resolve_weight_class' ) );
	}

	public function render() {
		if ( ! Capabilities::user_can_manage_entries() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->render_notice( $notice );

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$item = null;
			if ( 'edit' === $action && $id ) {
				if ( method_exists( $this->repository, 'assert_entry_in_scope' ) ) {
					$this->repository->assert_entry_in_scope( $id );
				}
				$item = $this->repository->get( $id, true );
			}
			$this->render_form( $item );
			return;
		}

		$list_table = new Entries_Table();
		$this->maybe_handle_bulk_actions( $list_table, Menu::PAGE_ENTRIES );
		$list_table->prepare_items();
		$filters = $list_table->get_filters();
		$current_view = $filters['view'] ?? 'all';
		$total_entries = (int) $this->repository->count( array( 'view' => 'all' ) );
		$submitted_entries = (int) $this->repository->count( array( 'view' => 'all', 'status' => 'submitted' ) );
		$approved_entries = (int) $this->repository->count( array( 'view' => 'all', 'status' => 'approved' ) );
		$rejected_entries = (int) $this->repository->count( array( 'view' => 'all', 'status' => 'rejected' ) );
		$items_count = is_countable( $list_table->items ) ? count( $list_table->items ) : 0;
		$table_output = $this->capture_list_table_output( $list_table );
		$has_table_markup = false !== strpos( $table_output, 'wp-list-table' );
		$has_table_tag = false !== strpos( $table_output, '<table' );
		$table_classes = '';
		if ( preg_match( '/<table[^>]*class="([^"]+)"/i', $table_output, $matches ) ) {
			$table_classes = $matches[1];
		}
		$has_table_nonce = false !== strpos( $table_output, 'name="_wpnonce"' );
		$needs_bulk_nonce = ( '' !== trim( $table_output ) ) && ! $has_table_nonce;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
			error_log(
				sprintf(
					'UFSC Entries list render: items=%d output_len=%d has_table=%s has_table_tag=%s table_classes=%s',
					(int) $items_count,
					strlen( $table_output ),
					$has_table_markup ? 'yes' : 'no',
					$has_table_tag ? 'yes' : 'no',
					$table_classes ?: 'n/a'
				)
			);
		}

		?>
		<div class="wrap ufsc-competitions-admin">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Gestion opérationnelle', 'ufsc-licence-competition' ); ?></p>
					<h1 class="wp-heading-inline"><?php esc_html_e( 'Inscriptions', 'ufsc-licence-competition' ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Suivez les brouillons, validations et anomalies pour préparer les combats sans retard.', 'ufsc-licence-competition' ); ?></p>
				</div>
				<?php if ( Capabilities::user_can_manage_entries() ) : ?>
					<div class="ufsc-admin-page-actions">
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_ENTRIES, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-primary"><?php esc_html_e( 'Ajouter une inscription', 'ufsc-licence-competition' ); ?></a>
					</div>
				<?php endif; ?>
			</header>
			<section class="ufsc-kpis ufsc-kpis--premium">
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Total inscriptions', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $total_entries ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'À valider', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $submitted_entries ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Approuvées', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $approved_entries ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Rejetées', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $rejected_entries ) ); ?></strong></article>
			</section>
			<?php $this->render_helper_notice( __( 'Ajouter/valider les inscrits, contrôler doublons, gérer la forclusion.', 'ufsc-licence-competition' ) ); ?>
			<?php $list_table->views(); ?>
			<form method="post" class="ufsc-admin-toolbar">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_ENTRIES ); ?>" />
				<?php if ( $current_view && 'all' !== $current_view ) : ?>
					<input type="hidden" name="ufsc_view" value="<?php echo esc_attr( $current_view ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $filters['competition_id'] ) ) : ?>
					<input type="hidden" name="ufsc_competition_id" value="<?php echo esc_attr( (int) $filters['competition_id'] ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $filters['status'] ) ) : ?>
					<input type="hidden" name="ufsc_status" value="<?php echo esc_attr( $filters['status'] ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $filters['discipline'] ) ) : ?>
					<input type="hidden" name="ufsc_discipline" value="<?php echo esc_attr( $filters['discipline'] ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $filters['search'] ) ) : ?>
					<input type="hidden" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $_REQUEST['paged'] ) ) : ?>
					<input type="hidden" name="paged" value="<?php echo esc_attr( absint( $_REQUEST['paged'] ) ); ?>" />
				<?php endif; ?>
				<?php if ( $needs_bulk_nonce ) : ?>
					<?php wp_nonce_field( 'bulk-' . $list_table->_args['plural'] ); ?>
				<?php endif; ?>
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competition-entries-search' ); ?>
			</form>
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_ENTRIES ); ?>" />
				<?php if ( $current_view && 'all' !== $current_view ) : ?>
					<input type="hidden" name="ufsc_view" value="<?php echo esc_attr( $current_view ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $filters['competition_id'] ) ) : ?>
					<input type="hidden" name="ufsc_competition_id" value="<?php echo esc_attr( (int) $filters['competition_id'] ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $filters['status'] ) ) : ?>
					<input type="hidden" name="ufsc_status" value="<?php echo esc_attr( $filters['status'] ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $filters['discipline'] ) ) : ?>
					<input type="hidden" name="ufsc_discipline" value="<?php echo esc_attr( $filters['discipline'] ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $_REQUEST['paged'] ) ) : ?>
					<input type="hidden" name="paged" value="<?php echo esc_attr( absint( $_REQUEST['paged'] ) ); ?>" />
				<?php endif; ?>
				<?php if ( $needs_bulk_nonce ) : ?>
					<?php wp_nonce_field( 'bulk-' . $list_table->_args['plural'] ); ?>
				<?php endif; ?>
				<div class="ufsc-competitions-table-wrap ufsc-competitions-entries-table-wrap">
					<?php
					if ( '' !== trim( $table_output ) ) {
						echo $table_output;
					} else {
						$list_table->display();
					}
					?>
				</div>
			</form>
			<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) : ?>
				<!-- UFSC entries debug: display_called=1 items=<?php echo esc_attr( (string) $items_count ); ?> -->
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! Capabilities::user_can_manage_entries() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_entry' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			if ( class_exists( 'UFSC_LC_Scope' ) ) {
				ufsc_lc_safe_enforce_object_scope( $id, 'entry' );
			} elseif ( method_exists( $this->repository, 'assert_entry_in_scope' ) ) {
				$this->repository->assert_entry_in_scope( $id );
			}
		}
		$data = array(
			'competition_id' => isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0,
			'category_id'    => isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0,
			'club_id'        => isset( $_POST['club_id'] ) ? absint( $_POST['club_id'] ) : 0,
			'licensee_id'    => isset( $_POST['licensee_id'] ) ? absint( $_POST['licensee_id'] ) : 0,
			'status'         => isset( $_POST['status'] ) ? EntriesWorkflow::normalize_status( (string) wp_unslash( $_POST['status'] ) ) : 'draft',
		);
		$weight_kg_raw = isset( $_POST['weight_kg'] ) ? wp_unslash( $_POST['weight_kg'] ) : '';
		$weight_class_raw = isset( $_POST['weight_class'] ) ? wp_unslash( $_POST['weight_class'] ) : '';
		$weight_kg = $this->sanitize_weight( $weight_kg_raw );
		$weight_class = sanitize_text_field( (string) $weight_class_raw );

		$selected_licensee_id = isset( $_POST['selected_licensee_id'] ) ? absint( $_POST['selected_licensee_id'] ) : 0;
		if ( ! $data['licensee_id'] && $selected_licensee_id ) {
			$data['licensee_id'] = $selected_licensee_id;
		}

		if ( ! $data['competition_id'] || ! $data['licensee_id'] ) {
			$this->redirect_with_notice( Menu::PAGE_ENTRIES, 'error_required', $id );
		}

		if ( class_exists( 'UFSC_LC_Scope' ) ) {
			ufsc_lc_safe_enforce_object_scope( (int) $data['competition_id'], 'competition' );
		} elseif ( method_exists( $this->competition_repository, 'assert_competition_in_scope' ) ) {
			$this->competition_repository->assert_competition_in_scope( (int) $data['competition_id'] );
		}

		if ( ! $id && $this->repository->get_by_competition_licensee( $data['competition_id'], $data['licensee_id'] ) ) {
			$this->redirect_with_notice( Menu::PAGE_ENTRIES, 'duplicate', $id );
		}

		$licensee_data = $this->get_licensee_data( $data['licensee_id'] );
		$weight_context = $this->get_weight_context( $data['competition_id'] );
		if ( '' === $weight_class && null !== $weight_kg ) {
			$resolved = WeightCategoryResolver::resolve_with_details(
				$licensee_data['birthdate'] ?? '',
				$licensee_data['sex'] ?? '',
				$weight_kg,
				$weight_context
			);
			$weight_class = (string) ( $resolved['label'] ?? '' );
		}

		$data['weight_kg'] = $weight_kg;
		$data['weight_class'] = '' !== $weight_class ? $weight_class : null;
		$data = $this->resolve_category_payload( $data, $licensee_data );

		if ( in_array( $data['status'], array( 'submitted', 'approved' ), true )
			&& WeightCategoryResolver::requires_weight( $weight_context )
			&& null === $weight_kg
		) {
			$this->redirect_with_notice( Menu::PAGE_ENTRIES, 'weight_required', $id );
		}

		if ( $id ) {
			$updated = $this->repository->update( $id, $data );
			if ( false === $updated ) {
				global $wpdb;
				if ( ! empty( $wpdb->last_error ) ) {
					error_log( 'UFSC Competitions entry update failed: ' . $wpdb->last_error );
				}
				$this->redirect_with_notice( Menu::PAGE_ENTRIES, 'db_error', $id );
			}
			$this->redirect_with_notice( Menu::PAGE_ENTRIES, 'updated', $id );
		}

		$new_id = $this->repository->insert( $data );
		if ( ! $new_id ) {
			global $wpdb;
			if ( class_exists( EntryDeduplication::class ) && EntryDeduplication::is_duplicate_key_error( (string) $wpdb->last_error ) ) {
				$this->redirect_with_notice( Menu::PAGE_ENTRIES, 'duplicate', $id );
			}
			if ( ! empty( $wpdb->last_error ) ) {
				error_log( 'UFSC Competitions entry insert failed: ' . $wpdb->last_error );
			}
			$this->redirect_with_notice( Menu::PAGE_ENTRIES, 'db_error', $id );
		}
		$this->redirect_with_notice( Menu::PAGE_ENTRIES, 'created', $new_id );
	}

	public function handle_trash() {
		$this->handle_simple_action( 'ufsc_competitions_trash_entry', 'trash', Menu::PAGE_ENTRIES );
	}

	public function handle_restore() {
		$this->handle_simple_action( 'ufsc_competitions_restore_entry', 'restore', Menu::PAGE_ENTRIES );
	}

	public function handle_delete() {
		$this->handle_simple_action( 'ufsc_competitions_delete_entry', 'delete', Menu::PAGE_ENTRIES );
	}

	public function ajax_search_licence() {
		if ( ! Capabilities::user_can_manage_entries() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
		}

		check_ajax_referer( 'ufsc_lc_entries', 'nonce' );

		$nom              = isset( $_POST['nom'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['nom'] ) ) ) : '';
		$prenom           = isset( $_POST['prenom'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['prenom'] ) ) ) : '';
		$numero_licence   = isset( $_POST['numero_licence'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['numero_licence'] ) ) ) : '';
		$date_naissance   = isset( $_POST['date_naissance'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['date_naissance'] ) ) ) : '';
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

		if ( '' === $nom && '' === $prenom && '' === $numero_licence && '' === $date_naissance ) {
			$this->debug_search_log(
				'reject_empty_criteria',
				array(
					'nom' => $nom,
					'prenom' => $prenom,
					'numero_licence' => $numero_licence,
					'date_naissance' => $date_naissance,
					'competition_id' => $competition_id,
				)
			);
			wp_send_json_error( array( 'message' => __( 'Veuillez saisir au moins un critère de recherche.', 'ufsc-licence-competition' ) ) );
		}

		$normalized_birthdate = $this->normalize_birthdate( $date_naissance );
		$season_end_year      = $this->get_competition_season_end_year( $competition_id );

		global $wpdb;

		$licences_table = $wpdb->prefix . 'ufsc_licences';
		$clubs_table    = $wpdb->prefix . 'ufsc_clubs';
		$columns        = Db::get_table_columns( $licences_table );
		$schema         = $this->resolve_license_search_schema( $columns );

		if ( '' === $schema['last_name_expr'] && '' === $schema['first_name_expr'] && empty( $schema['license_columns'] ) ) {
			$this->debug_search_log(
				'no_searchable_columns',
				array(
					'source' => $licences_table,
					'columns_count' => count( $columns ),
				)
			);
			wp_send_json_error( array( 'message' => __( 'Recherche indisponible : colonnes de licence introuvables.', 'ufsc-licence-competition' ) ), 500 );
		}

		$sex_column     = $this->resolve_first_column( $columns, array( 'sexe', 'sex', 'gender' ) );
		$weight_column  = $this->resolve_first_column( $columns, array( 'poids', 'weight', 'weight_kg' ) );
		$sex_select     = $sex_column ? "l.{$sex_column} AS sex," : "'' AS sex,";
		$weight_select  = $weight_column ? "l.{$weight_column} AS weight_kg," : "NULL AS weight_kg,";
		$birthdate_select = $schema['birthdate_column'] ? "l.{$schema['birthdate_column']} AS date_naissance" : "'' AS date_naissance";
		$license_number_select = ! empty( $schema['license_columns'] ) ? "l.{$schema['license_columns'][0]} AS numero_licence" : "'' AS numero_licence";
		$name_expr      = '' !== $schema['last_name_expr'] ? $schema['last_name_expr'] : "''";
		$first_name_expr = '' !== $schema['first_name_expr'] ? $schema['first_name_expr'] : "''";

		$where  = array();
		$params = array();

		$normalized_nom = $this->normalize_search_term( $nom );
		$normalized_prenom = $this->normalize_search_term( $prenom );
		$normalized_full_name = trim( $normalized_nom . ' ' . $normalized_prenom );

		if ( '' !== $numero_licence && ! empty( $schema['license_columns'] ) ) {
			$compact_number = $this->normalize_identifier( $numero_licence );
			$number_clauses = array();
			foreach ( $schema['license_columns'] as $license_column ) {
				$number_clauses[] = "TRIM(COALESCE(l.{$license_column}, '')) = %s";
				$params[] = $numero_licence;
				if ( '' !== $compact_number ) {
					$number_clauses[] = "REPLACE(REPLACE(LOWER(TRIM(COALESCE(l.{$license_column}, ''))), ' ', ''), '-', '') = %s";
					$params[] = $compact_number;
				}
			}
			$where[] = '(' . implode( ' OR ', $number_clauses ) . ')';
		}

		$name_clauses = array();
		if ( '' !== $nom && '' !== $name_expr ) {
			$name_like = '%' . $wpdb->esc_like( $nom ) . '%';
			$name_clauses[] = "{$name_expr} LIKE %s";
			$params[] = $name_like;
			if ( '' !== $normalized_nom ) {
				$name_clauses[] = "LOWER({$name_expr}) LIKE %s";
				$params[] = '%' . $wpdb->esc_like( $normalized_nom ) . '%';
			}
		}
		if ( '' !== $prenom && '' !== $first_name_expr ) {
			$first_name_like = '%' . $wpdb->esc_like( $prenom ) . '%';
			$name_clauses[] = "{$first_name_expr} LIKE %s";
			$params[] = $first_name_like;
			if ( '' !== $normalized_prenom ) {
				$name_clauses[] = "LOWER({$first_name_expr}) LIKE %s";
				$params[] = '%' . $wpdb->esc_like( $normalized_prenom ) . '%';
			}
		}
		if ( '' !== $normalized_full_name && '' !== $name_expr && '' !== $first_name_expr ) {
			$name_clauses[] = "LOWER(CONCAT_WS(' ', {$name_expr}, {$first_name_expr})) LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $normalized_full_name ) . '%';
			$name_clauses[] = "LOWER(CONCAT_WS(' ', {$first_name_expr}, {$name_expr})) LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $normalized_full_name ) . '%';
		}
		if ( $name_clauses ) {
			$where[] = '(' . implode( ' OR ', $name_clauses ) . ')';
		}

		if ( '' !== $normalized_birthdate && $schema['birthdate_column'] ) {
			$where[]  = "(l.{$schema['birthdate_column']} = %s OR l.{$schema['birthdate_column']} = %s)";
			$params[] = $normalized_birthdate;
			$params[] = $this->format_birthdate_for_storage( $normalized_birthdate );
		}

		if ( '' !== $schema['status_expr'] ) {
			$where[] = "(l.{$schema['status_expr']} IS NULL OR l.{$schema['status_expr']} = '' OR LOWER(l.{$schema['status_expr']}) IN ('valide', 'valid', 'active', 'actif', 'approved'))";
		}

		$scope_region = function_exists( 'ufsc_lc_competitions_get_user_scope_region' )
			? ufsc_lc_competitions_get_user_scope_region()
			: '';
		$scope_region = is_string( $scope_region ) ? sanitize_key( $scope_region ) : '';
		if ( '' !== $scope_region ) {
			if ( Db::has_table_column( $clubs_table, 'region' ) ) {
				$where[] = 'c.region = %s';
				$params[] = $scope_region;
			} else {
				$where[] = '1=0';
			}
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$this->debug_search_log(
			'query_context',
			array(
				'nom' => $nom,
				'prenom' => $prenom,
				'numero_licence' => $numero_licence,
				'date_naissance' => $date_naissance,
				'date_naissance_normalized' => $normalized_birthdate,
				'source' => $licences_table,
				'license_columns' => $schema['license_columns'],
				'name_columns' => array( $schema['last_name_column'], $schema['first_name_column'] ),
				'birthdate_column' => $schema['birthdate_column'],
				'status_column' => $schema['status_expr'],
				'scope_region' => $scope_region,
			)
		);

		$order_first_name = '' !== $first_name_expr ? $first_name_expr : 'l.id';
		$sql = "SELECT l.id AS licence_id, {$name_expr} AS nom, {$first_name_expr} AS prenom, {$birthdate_select}, {$license_number_select}, {$sex_select} {$weight_select} l.club_id, c.nom AS club_nom
			FROM {$licences_table} l
			LEFT JOIN {$clubs_table} c ON c.id = l.club_id
			{$where_sql}
			ORDER BY {$name_expr} ASC, {$order_first_name} ASC, l.id ASC
			LIMIT %d";

		$params[] = 20;
		$query    = $wpdb->prepare( $sql, $params );
		$rows     = $wpdb->get_results( $query, ARRAY_A );
		if ( $wpdb->last_error ) {
			$this->debug_search_log(
				'query_error',
				array(
					'error' => $wpdb->last_error,
				)
			);
			wp_send_json_error(
				array(
					'message' => __( 'Erreur interne lors de la recherche.', 'ufsc-licence-competition' ),
				),
				500
			);
		}
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$this->debug_search_log(
			'query_results_count',
			array(
				'count' => count( $rows ),
			)
		);

		$results = array();
		foreach ( $rows as $row ) {
			$birthdate_raw = trim( sanitize_text_field( $row['date_naissance'] ?? '' ) );
			$category      = '';
			if ( $birthdate_raw && function_exists( 'ufsc_lc_compute_category_from_birthdate' ) ) {
				$category = ufsc_lc_compute_category_from_birthdate( $birthdate_raw, $season_end_year );
			}

			$results[] = array(
				'licence_id'         => absint( $row['licence_id'] ?? 0 ),
				'nom'                => trim( sanitize_text_field( $row['nom'] ?? '' ) ),
				'prenom'             => trim( sanitize_text_field( $row['prenom'] ?? '' ) ),
				'date_naissance'     => $birthdate_raw,
				'date_naissance_fmt' => $this->format_birthdate( $birthdate_raw ),
				'club_id'            => absint( $row['club_id'] ?? 0 ),
				'club_nom'           => trim( sanitize_text_field( $row['club_nom'] ?? '' ) ),
				'sex'                => trim( sanitize_text_field( $row['sex'] ?? '' ) ),
				'weight_kg'          => isset( $row['weight_kg'] ) ? $this->sanitize_weight( $row['weight_kg'] ) : null,
				'numero_licence'     => trim( sanitize_text_field( $row['numero_licence'] ?? '' ) ),
				'category'           => $category,
			);
		}

		wp_send_json_success(
			array(
				'results' => $results,
				'message' => $results ? '' : __( 'Aucun résultat trouvé.', 'ufsc-licence-competition' ),
			)
		);
	}

	public function ajax_get_licensee() {
		if ( ! Capabilities::user_can_manage_entries() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
		}

		check_ajax_referer( 'ufsc_lc_entries', 'nonce' );

		$licensee_id   = isset( $_POST['licensee_id'] ) ? absint( $_POST['licensee_id'] ) : 0;
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

		if ( ! $licensee_id ) {
			wp_send_json_error( array( 'message' => __( 'Identifiant licencié invalide.', 'ufsc-licence-competition' ) ) );
		}

		$season_end_year = $this->get_competition_season_end_year( $competition_id );

		global $wpdb;

		$licences_table = $wpdb->prefix . 'ufsc_licences';
		$clubs_table    = $wpdb->prefix . 'ufsc_clubs';
		$columns        = Db::get_table_columns( $licences_table );
		$schema         = $this->resolve_license_search_schema( $columns );
		$name_expr      = '' !== $schema['last_name_expr'] ? $schema['last_name_expr'] : "''";
		$first_name_expr = '' !== $schema['first_name_expr'] ? $schema['first_name_expr'] : "''";
		$sex_column     = $this->resolve_first_column( $columns, array( 'sexe', 'sex', 'gender' ) );
		$weight_column  = $this->resolve_first_column( $columns, array( 'poids', 'weight', 'weight_kg' ) );
		$sex_select     = $sex_column ? "l.{$sex_column} AS sex," : "'' AS sex,";
		$weight_select  = $weight_column ? "l.{$weight_column} AS weight_kg," : "NULL AS weight_kg,";
		$birthdate_select = $schema['birthdate_column'] ? "l.{$schema['birthdate_column']} AS date_naissance" : "'' AS date_naissance";
		$license_number_select = ! empty( $schema['license_columns'] ) ? "l.{$schema['license_columns'][0]} AS numero_licence" : "'' AS numero_licence";

		$scope_region = function_exists( 'ufsc_lc_competitions_get_user_scope_region' )
			? ufsc_lc_competitions_get_user_scope_region()
			: '';
		$scope_region = is_string( $scope_region ) ? sanitize_key( $scope_region ) : '';

		$where = 'WHERE l.id = %d';
		$params = array( $licensee_id );
		if ( '' !== $scope_region ) {
			if ( Db::has_table_column( $clubs_table, 'region' ) ) {
				$where .= ' AND c.region = %s';
				$params[] = $scope_region;
			} else {
				wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
			}
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT l.id AS licence_id, {$name_expr} AS nom, {$first_name_expr} AS prenom, {$birthdate_select}, {$license_number_select}, {$sex_select} {$weight_select} l.club_id, c.nom AS club_nom
				FROM {$licences_table} l
				LEFT JOIN {$clubs_table} c ON c.id = l.club_id
				{$where}
				LIMIT 1",
				$params
			),
			ARRAY_A
		);

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Licencié introuvable.', 'ufsc-licence-competition' ) ) );
		}

		$birthdate_raw = trim( sanitize_text_field( $row['date_naissance'] ?? '' ) );
		$category      = '';
		if ( $birthdate_raw && function_exists( 'ufsc_lc_compute_category_from_birthdate' ) ) {
			$category = ufsc_lc_compute_category_from_birthdate( $birthdate_raw, $season_end_year );
		}

		$data = array(
			'licence_id'         => absint( $row['licence_id'] ?? 0 ),
			'nom'                => trim( sanitize_text_field( $row['nom'] ?? '' ) ),
			'prenom'             => trim( sanitize_text_field( $row['prenom'] ?? '' ) ),
			'date_naissance'     => $birthdate_raw,
			'date_naissance_fmt' => $this->format_birthdate( $birthdate_raw ),
			'club_id'            => absint( $row['club_id'] ?? 0 ),
			'club_nom'           => trim( sanitize_text_field( $row['club_nom'] ?? '' ) ),
			'sex'                => trim( sanitize_text_field( $row['sex'] ?? '' ) ),
			'weight_kg'          => isset( $row['weight_kg'] ) ? $this->sanitize_weight( $row['weight_kg'] ) : null,
			'numero_licence'     => trim( sanitize_text_field( $row['numero_licence'] ?? '' ) ),
			'category'           => $category,
		);

		wp_send_json_success( $data );
	}

	public function ajax_resolve_weight_class() {
		if ( ! Capabilities::user_can_manage_entries() ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ufsc-licence-competition' ) ), 403 );
		}

		check_ajax_referer( 'ufsc_lc_entries', 'nonce' );

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$licensee_id = isset( $_POST['licensee_id'] ) ? absint( $_POST['licensee_id'] ) : 0;
		$birth_date = isset( $_POST['birth_date'] ) ? sanitize_text_field( wp_unslash( $_POST['birth_date'] ) ) : '';
		$sex = isset( $_POST['sex'] ) ? sanitize_text_field( wp_unslash( $_POST['sex'] ) ) : '';
		$weight_raw = isset( $_POST['weight_kg'] ) ? wp_unslash( $_POST['weight_kg'] ) : '';

		if ( $licensee_id && '' === $birth_date ) {
			$licensee_data = $this->get_licensee_data( $licensee_id );
			$birth_date = $licensee_data['birthdate'] ?? '';
			if ( '' === $sex ) {
				$sex = $licensee_data['sex'] ?? '';
			}
		}

		$weight_kg = $this->sanitize_weight( $weight_raw );
		$context = $this->get_weight_context( $competition_id );

		$classes = WeightCategoryResolver::get_weight_classes( $birth_date, $sex, $context );
		$result = WeightCategoryResolver::resolve_with_details( $birth_date, $sex, $weight_kg, $context );

		wp_send_json_success(
			array(
				'classes' => $classes,
				'label' => $result['label'] ?? '',
				'message' => $result['message'] ?? '',
				'status' => $result['status'] ?? '',
			)
		);
	}

	private function normalize_birthdate( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$formats  = array( 'Y-m-d', 'd/m/Y' );
		foreach ( $formats as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( '!' . $format, $raw, $timezone );
			if ( $parsed && $parsed->format( $format ) === $raw ) {
				return $parsed->format( 'Y-m-d' );
			}
		}

		return '';
	}

	private function format_birthdate( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( function_exists( 'ufsc_lc_format_birthdate' ) ) {
			return ufsc_lc_format_birthdate( $raw );
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$parsed   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, $timezone );
		if ( $parsed && $parsed->format( 'Y-m-d' ) === $raw ) {
			return $parsed->format( 'd/m/Y' );
		}

		return $raw;
	}

	private function get_competition_season_end_year( $competition_id ) {
		if ( ! $competition_id ) {
			return '';
		}

		$competition = $this->competition_repository->get( $competition_id, true );
		return $competition ? (string) ( $competition->season ?? '' ) : '';
	}

	private function handle_simple_action( $action, $method, $page_slug ) {
		if ( ! Capabilities::user_can_manage_entries() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( $action . '_' . $id );

		if ( ! $id ) {
			$this->redirect_with_notice( $page_slug, 'not_found' );
		}

		if ( class_exists( 'UFSC_LC_Scope' ) ) {
			ufsc_lc_safe_enforce_object_scope( $id, 'entry' );
		} elseif ( method_exists( $this->repository, 'assert_entry_in_scope' ) ) {
			$this->repository->assert_entry_in_scope( $id );
		}

		switch ( $method ) {
			case 'trash':
				$this->repository->soft_delete( $id );
				$this->redirect_with_notice( $page_slug, 'trashed' );
				break;
			case 'restore':
				$this->repository->restore( $id );
				$this->redirect_with_notice( $page_slug, 'restored' );
				break;
			case 'delete':
				if ( ! Capabilities::user_can_delete() ) {
					wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
				}
				$this->repository->delete( $id );
				$this->redirect_with_notice( $page_slug, 'deleted' );
				break;
		}
	}

	private function render_form( $item ) {
		$values = array(
			'id'             => $item->id ?? 0,
			'competition_id' => $item->competition_id ?? 0,
			'category_id'    => $item->category_id ?? 0,
			'club_id'        => $item->club_id ?? 0,
			'licensee_id'    => $item->licensee_id ?? 0,
			'status'         => $item->status ?? 'draft',
			'weight_kg'      => $item->weight_kg ?? '',
			'weight_class'   => $item->weight_class ?? '',
		);

		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $this->competition_repository->list( $competition_filters, 200, 0 );
		$categories = $this->category_repository->list( array( 'view' => 'all' ), 500, 0 );
		$action_label = $values['id'] ? __( 'Mettre à jour', 'ufsc-licence-competition' ) : __( 'Créer l\'inscription', 'ufsc-licence-competition' );
		$licensee_data = $this->get_licensee_data( (int) $values['licensee_id'] );
		$weight_context = $this->get_weight_context( (int) $values['competition_id'] );
		$weight_classes = $licensee_data
			? WeightCategoryResolver::get_weight_classes( $licensee_data['birthdate'] ?? '', $licensee_data['sex'] ?? '', $weight_context )
			: array();
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html( $values['id'] ? __( 'Modifier l\'inscription', 'ufsc-licence-competition' ) : __( 'Nouvelle inscription', 'ufsc-licence-competition' ) ); ?></h1>
			<?php $this->render_helper_notice( __( 'Ajouter/valider les inscrits, contrôler doublons, gérer la forclusion.', 'ufsc-licence-competition' ) ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_entry' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_entry">
				<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_entry_competition"><?php esc_html_e( 'Compétition', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="competition_id" id="ufsc_entry_competition" class="regular-text" required>
								<option value="0"><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $competitions as $competition ) : ?>
									<option value="<?php echo esc_attr( $competition->id ); ?>" <?php selected( $values['competition_id'], $competition->id ); ?>><?php echo esc_html( $competition->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_category"><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="category_id" id="ufsc_entry_category" class="regular-text">
								<option value="0"><?php esc_html_e( 'Auto / non assignée', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $categories as $category ) : ?>
									<option
										value="<?php echo esc_attr( $category->id ); ?>"
										data-competition-id="<?php echo esc_attr( (int) ( $category->competition_id ?? 0 ) ); ?>"
										<?php selected( $values['category_id'], $category->id ); ?>
									>
										<?php echo esc_html( $category->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description ufsc-entry-auto-category" id="ufsc_entry_auto_category_preview"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_weight"><?php esc_html_e( 'Poids (kg)', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<input name="weight_kg" type="number" step="0.1" min="0" id="ufsc_entry_weight" value="<?php echo esc_attr( $values['weight_kg'] ); ?>" placeholder="<?php echo esc_attr__( 'ex: 63.5', 'ufsc-licence-competition' ); ?>">
							<p class="description" id="ufsc_entry_weight_message"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_weight_class"><?php esc_html_e( 'Catégorie de poids', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="weight_class" id="ufsc_entry_weight_class" class="regular-text">
								<option value=""><?php esc_html_e( 'Auto / non assignée', 'ufsc-licence-competition' ); ?></option>
								<?php if ( '' !== (string) $values['weight_class'] && ! in_array( (string) $values['weight_class'], $weight_classes, true ) ) : ?>
									<option value="<?php echo esc_attr( (string) $values['weight_class'] ); ?>" selected><?php echo esc_html( (string) $values['weight_class'] ); ?></option>
								<?php endif; ?>
								<?php foreach ( $weight_classes as $weight_class_option ) : ?>
									<option value="<?php echo esc_attr( $weight_class_option ); ?>" <?php selected( (string) $values['weight_class'], $weight_class_option ); ?>>
										<?php echo esc_html( $weight_class_option ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description ufsc-entry-auto-category" id="ufsc_entry_weight_class_preview"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_licensee_search_nom"><?php esc_html_e( 'Rechercher un licencié', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<fieldset class="ufsc-entry-licensee-search">
								<div class="ufsc-entry-licensee-search-fields">
									<label>
										<span class="screen-reader-text"><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></span>
										<input type="text" id="ufsc_entry_licensee_search_nom" placeholder="<?php echo esc_attr__( 'Nom', 'ufsc-licence-competition' ); ?>">
									</label>
									<label>
										<span class="screen-reader-text"><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></span>
										<input type="text" id="ufsc_entry_licensee_search_prenom" placeholder="<?php echo esc_attr__( 'Prénom', 'ufsc-licence-competition' ); ?>">
									</label>
									<label>
										<span class="screen-reader-text"><?php esc_html_e( 'Numéro de licence', 'ufsc-licence-competition' ); ?></span>
										<input type="text" id="ufsc_entry_licensee_search_license_number" placeholder="<?php echo esc_attr__( 'N° licence', 'ufsc-licence-competition' ); ?>">
									</label>
									<label>
										<span class="screen-reader-text"><?php esc_html_e( 'Date de naissance', 'ufsc-licence-competition' ); ?></span>
										<input type="date" id="ufsc_entry_licensee_search_birthdate" placeholder="<?php echo esc_attr__( 'JJ/MM/AAAA', 'ufsc-licence-competition' ); ?>">
									</label>
									<button type="button" class="button" id="ufsc_entry_licensee_search_button"><?php esc_html_e( 'Rechercher', 'ufsc-licence-competition' ); ?></button>
								</div>
								<p class="description"><?php esc_html_e( 'Recherche par n° de licence, nom, prénom et/ou date de naissance (formats acceptés : JJ/MM/AAAA ou AAAA-MM-JJ).', 'ufsc-licence-competition' ); ?></p>
								<div class="ufsc-entry-licensee-search-message" id="ufsc_entry_licensee_search_message" role="status" aria-live="polite"></div>
								<div class="ufsc-entry-licensee-search-results" id="ufsc_entry_licensee_search_results"></div>
								<div class="ufsc-entry-licensee-search-actions">
									<button type="button" class="button button-primary" id="ufsc_entry_use_licensee" disabled><?php esc_html_e( 'Utiliser ce licencié', 'ufsc-licence-competition' ); ?></button>
									<span class="ufsc-entry-licensee-selected" id="ufsc_entry_licensee_selected"></span>
								</div>
								<input type="hidden" name="selected_licensee_id" id="ufsc_entry_selected_licensee" value="<?php echo esc_attr( $values['licensee_id'] ); ?>">
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_licensee"><?php esc_html_e( 'ID licencié', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="licensee_id" type="number" id="ufsc_entry_licensee" value="<?php echo esc_attr( $values['licensee_id'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_club"><?php esc_html_e( 'ID club', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="club_id" type="number" id="ufsc_entry_club" value="<?php echo esc_attr( $values['club_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entry_status"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="status" id="ufsc_entry_status" class="regular-text">
								<?php foreach ( EntriesWorkflow::get_status_labels() as $status_code => $status_label ) : ?>
									<option value="<?php echo esc_attr( $status_code ); ?>" <?php selected( $values['status'], $status_code ); ?>><?php echo esc_html( $status_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( $action_label ); ?>
			</form>
		</div>
		<?php
	}

	private function resolve_category_payload( array $data, array $licensee_data ): array {
		$competition_id = absint( $data['competition_id'] ?? 0 );
		if ( ! $competition_id ) {
			$data['category_id'] = 0;
			$data['category'] = null;
			return $data;
		}

		$category_id = absint( $data['category_id'] ?? 0 );
		$category_label = '';

		if ( $category_id > 0 ) {
			$selected_category = $this->category_repository->get( $category_id, true );
			$selected_competition_id = absint( $selected_category->competition_id ?? 0 );
			if ( $selected_category && $selected_competition_id === $competition_id ) {
				$category_label = sanitize_text_field( (string) ( $selected_category->name ?? '' ) );
			} else {
				$category_id = 0;
			}
		}

		if ( 0 === $category_id ) {
			$competition = $this->competition_repository->get( $competition_id, true );
			$birthdate = (string) ( $licensee_data['birthdate'] ?? '' );
			if ( $competition && '' !== $birthdate && class_exists( EntriesModule::class ) ) {
				$computed_label = EntriesModule::get_category_from_birthdate(
					$birthdate,
					array(
						'sex' => (string) ( $licensee_data['sex'] ?? '' ),
						'weight' => $data['weight_kg'] ?? null,
					),
					$competition
				);
				$category_label = sanitize_text_field( $computed_label );
				if ( '' !== $category_label ) {
					$category_match = $this->category_repository->get_by_competition_and_name( $competition_id, $category_label, true );
					if ( $category_match ) {
						$category_id = absint( $category_match->id ?? 0 );
					}
				}
			}
		}

		$data['category_id'] = $category_id;
		$data['category'] = '' !== $category_label ? $category_label : null;
		return $data;
	}

	private function redirect_with_notice( $page, $notice, $id = 0 ) {
		$url = add_query_arg(
			array(
				'page'        => $page,
				'ufsc_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);

		if ( $id ) {
			$url = add_query_arg( 'id', $id, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	private function render_notice( $notice ) {
		$messages = array(
			'created'       => __( 'Inscription créée.', 'ufsc-licence-competition' ),
			'updated'       => __( 'Inscription mise à jour.', 'ufsc-licence-competition' ),
			'trashed'       => __( 'Inscription déplacée dans la corbeille.', 'ufsc-licence-competition' ),
			'restored'      => __( 'Inscription restaurée.', 'ufsc-licence-competition' ),
			'deleted'       => __( 'Inscription supprimée définitivement.', 'ufsc-licence-competition' ),
			'db_error'      => __( 'Erreur lors de l’enregistrement de l’inscription.', 'ufsc-licence-competition' ),
			'error_required'=> __( 'Veuillez renseigner la compétition et le licencié.', 'ufsc-licence-competition' ),
			'duplicate'     => __( 'Ce licencié est déjà inscrit à cette compétition.', 'ufsc-licence-competition' ),
			'not_found'     => __( 'Inscription introuvable.', 'ufsc-licence-competition' ),
			'weight_required' => __( 'Veuillez renseigner le poids avant validation.', 'ufsc-licence-competition' ),
		);

		if ( ! $notice || ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type = in_array( $notice, array( 'error_required', 'not_found', 'duplicate', 'weight_required', 'db_error' ), true ) ? 'error' : 'success';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $messages[ $notice ] ) );
	}

	private function render_helper_notice( $message ) {
		printf(
			'<div class="notice notice-info ufsc-competitions-helper"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	private function capture_list_table_output( Entries_Table $list_table ): string {
		ob_start();
		$list_table->display();
		$output = (string) ob_get_clean();

		$stripped_output = preg_replace( '/<form\b[^>]*>/i', '', $output );
		$stripped_output = preg_replace( '/<\/form>/i', '', $stripped_output );
		$stripped_nested_form = $stripped_output !== $output;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
			error_log( sprintf( 'UFSC entries: stripped_nested_form=%s', $stripped_nested_form ? 'yes' : 'no' ) );
		}

		return $stripped_output;
	}

	private function maybe_handle_bulk_actions( Entries_Table $list_table, $page_slug ) {
		$action = $list_table->current_action();
		if ( ! $action ) {
			return;
		}

		if ( ! Capabilities::user_can_manage_entries() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'bulk-' . $list_table->_args['plural'] );

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_filter( $ids );
		if ( ! $ids ) {
			return;
		}

		if ( method_exists( $this->repository, 'assert_entry_in_scope' ) ) {
			foreach ( $ids as $entry_id ) {
				$this->repository->assert_entry_in_scope( (int) $entry_id );
			}
		}

		foreach ( $ids as $id ) {
			switch ( $action ) {
				case 'trash':
					$this->repository->soft_delete( $id );
					break;
				case 'restore':
					$this->repository->restore( $id );
					break;
				case 'delete':
					if ( ! Capabilities::user_can_delete() ) {
						wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
					}
					$this->repository->delete( $id );
					break;
			}
		}

		$notice_map = array(
			'trash'   => 'trashed',
			'restore' => 'restored',
			'delete'  => 'deleted',
		);

		$this->redirect_with_notice( $page_slug, $notice_map[ $action ] ?? 'updated' );
	}

	private function sanitize_weight( $value ): ?float {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = str_replace( ',', '.', $value );
		$weight = (float) $value;
		if ( $weight <= 0 || $weight > 300 ) {
			return null;
		}

		return $weight;
	}

	private function resolve_first_column( array $columns, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				return $candidate;
			}
		}

		return '';
	}

	private function get_licensee_data( int $licensee_id ): array {
		global $wpdb;

		$licensee_id = absint( $licensee_id );
		if ( ! $licensee_id ) {
			return array();
		}

		$licences_table = $wpdb->prefix . 'ufsc_licences';
		$columns = Db::get_table_columns( $licences_table );
		if ( ! $columns ) {
			return array();
		}
		$birthdate_column = $this->resolve_first_column( $columns, array( 'date_naissance', 'naissance', 'birthdate', 'date_of_birth' ) );
		if ( '' === $birthdate_column ) {
			return array();
		}

		$sex_column = $this->resolve_first_column( $columns, array( 'sexe', 'sex', 'gender' ) );
		$weight_column = $this->resolve_first_column( $columns, array( 'poids', 'weight', 'weight_kg' ) );

		$select = array( $birthdate_column . ' AS date_naissance' );
		if ( $sex_column ) {
			$select[] = $sex_column . ' AS sex';
		}
		if ( $weight_column ) {
			$select[] = $weight_column . ' AS weight_kg';
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ' . implode( ', ', $select ) . ' FROM ' . $licences_table . ' WHERE id = %d LIMIT 1',
				$licensee_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return array();
		}

		return array(
			'birthdate' => trim( sanitize_text_field( $row['date_naissance'] ?? '' ) ),
			'sex' => trim( sanitize_text_field( $row['sex'] ?? '' ) ),
			'weight_kg' => isset( $row['weight_kg'] ) ? $this->sanitize_weight( $row['weight_kg'] ) : null,
		);
	}

	private function get_weight_context( int $competition_id ): array {
		$competition = $competition_id ? $this->competition_repository->get( $competition_id, true ) : null;
		if ( ! $competition ) {
			return array();
		}

		return array(
			'discipline' => sanitize_key( (string) ( $competition->discipline ?? '' ) ),
			'age_reference' => sanitize_text_field( (string) ( $competition->age_reference ?? '12-31' ) ),
			'season_end_year' => isset( $competition->season ) ? (int) $competition->season : 0,
		);
	}

	private function resolve_license_search_schema( array $columns ): array {
		$last_name_column = $this->resolve_first_column( $columns, array( 'nom', 'nom_licence', 'last_name' ) );
		$first_name_column = $this->resolve_first_column( $columns, array( 'prenom', 'prenom_licence', 'first_name' ) );
		$birthdate_column = $this->resolve_first_column( $columns, array( 'date_naissance', 'naissance', 'birthdate', 'date_of_birth' ) );
		$status_column = $this->resolve_first_column( $columns, array( 'statut', 'status' ) );
		$license_columns = array();
		foreach ( array( 'numero_licence_asptt', 'numero_licence_delegataire', 'numero_licence', 'num_licence', 'licence_numero', 'licence_number', 'asptt_number' ) as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				$license_columns[] = $candidate;
			}
		}

		$last_name_expr = '';
		if ( '' !== $last_name_column ) {
			$last_name_parts = array();
			foreach ( array_unique( array_filter( array( $last_name_column, in_array( 'nom_licence', $columns, true ) ? 'nom_licence' : '', in_array( 'nom', $columns, true ) ? 'nom' : '' ) ) ) as $column_name ) {
				$last_name_parts[] = "NULLIF(l.{$column_name},'')";
			}
			$last_name_expr = $last_name_parts ? 'COALESCE(' . implode( ', ', $last_name_parts ) . ')' : '';
		}

		$first_name_expr = '';
		if ( '' !== $first_name_column ) {
			$first_name_parts = array();
			foreach ( array_unique( array_filter( array( $first_name_column, in_array( 'prenom', $columns, true ) ? 'prenom' : '' ) ) ) as $column_name ) {
				$first_name_parts[] = "NULLIF(l.{$column_name},'')";
			}
			$first_name_expr = $first_name_parts ? 'COALESCE(' . implode( ', ', $first_name_parts ) . ')' : '';
		}

		return array(
			'last_name_column' => $last_name_column,
			'first_name_column' => $first_name_column,
			'birthdate_column' => $birthdate_column,
			'status_expr' => $status_column,
			'license_columns' => $license_columns,
			'last_name_expr' => $last_name_expr,
			'first_name_expr' => $first_name_expr,
		);
	}

	private function normalize_search_term( string $value ): string {
		$value = sanitize_text_field( $value );
		$value = remove_accents( $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = trim( (string) $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	private function normalize_identifier( string $value ): string {
		$value = remove_accents( sanitize_text_field( $value ) );
		$value = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $value );
		$value = trim( (string) $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	private function format_birthdate_for_storage( string $normalized_birthdate ): string {
		$normalized_birthdate = trim( $normalized_birthdate );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $normalized_birthdate ) ) {
			return $normalized_birthdate;
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$parsed   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $normalized_birthdate, $timezone );
		if ( ! $parsed ) {
			return $normalized_birthdate;
		}

		return $parsed->format( 'd/m/Y' );
	}

	private function debug_search_log( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$payload = $context ? wp_json_encode( $context ) : '';
		error_log( sprintf( 'UFSC entries license search: %s %s', $message, $payload ) );
	}
}
