<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Db;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Admin\Tables\Categories_Table;
use UFSC\Competitions\Services\DisciplineRegistry;
use UFSC\Competitions\Services\GenerationLockService;
use UFSC\Competitions\Services\LogService;
use UFSC\Competitions\Services\CategoryPresetService;
use UFSC\Competitions\Services\CategoryPresetRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Categories_Page {
	private $repository;
	private $competition_repository;

	public function __construct() {
		$this->repository = new CategoryRepository();
		$this->competition_repository = new CompetitionRepository();
	}

	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_category', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_category', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_category', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_category', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ufsc_competitions_import_category_preset', array( $this, 'handle_preset_import' ) );
	}

	public function render() {
		if ( ! Capabilities::user_can_read() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->render_notice( $notice );

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$item = null;
			if ( 'edit' === $action && $id ) {
				$item = $this->repository->get( $id, true );
			}
			$this->render_form( $item );
			return;
		}

		$list_table = new Categories_Table();
		$this->maybe_handle_bulk_actions( $list_table, Menu::PAGE_CATEGORIES );
		$list_table->prepare_items();

		?>
		<div class="wrap ufsc-competitions-admin">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Référentiels compétition', 'ufsc-licence-competition' ); ?></p>
					<h1 class="wp-heading-inline"><?php esc_html_e( 'Catégories & formats', 'ufsc-licence-competition' ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Structurez les catégories par discipline, âge, poids et niveau pour fiabiliser inscriptions et génération des combats.', 'ufsc-licence-competition' ); ?></p>
				</div>
				<div class="ufsc-admin-page-actions">
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_CATEGORIES, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-primary"><?php esc_html_e( 'Ajouter une catégorie', 'ufsc-licence-competition' ); ?></a>
				</div>
			</header>
			<?php $this->render_helper_notice( __( 'Définir âge/poids/sexe/niveau + format poules/élimination. Les catégories du référentiel servent à la génération automatique des combats ; les catégories visibles dans les inscriptions sont des données déclarées/importées.', 'ufsc-licence-competition' ) ); ?>
			<?php $this->render_category_diagnostic( (array) $list_table->get_filters() ); ?>
			<?php $this->render_preset_preview_section(); ?>
			<section class="ufsc-admin-surface ufsc-admin-listing-surface">
			<?php $list_table->views(); ?>
			<form method="post" class="ufsc-admin-toolbar">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_CATEGORIES ); ?>" />
				<?php $list_table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-competitions-categories-search' ); ?>
			</form>
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_CATEGORIES ); ?>" />
				<div class="ufsc-competitions-table-wrap">
					<?php $list_table->display(); ?>
				</div>
			</form>
			</section>
		</div>
		<?php
	}


	private function render_category_diagnostic( array $filters ): void {
		$diagnostic = $this->build_category_diagnostic( $filters );
		$duplicates = (array) ( $diagnostic['duplicates'] ?? array() );
		$same_weight = (array) ( $diagnostic['same_weight'] ?? array() );
		$analyzed = (int) ( $diagnostic['analyzed'] ?? 0 );
		?>
		<section class="ufsc-admin-surface" style="margin-top:16px;">
			<h2><?php esc_html_e( 'Catégories détectées', 'ufsc-licence-competition' ); ?></h2>
			<p><?php esc_html_e( 'Diagnostic non destructif : aucune catégorie, inscription, pesée, combat ou résultat n’est modifié. Les catégories à poids identique ne sont suspectes que si discipline, sexe, âge, niveau et poids correspondent aussi.', 'ufsc-licence-competition' ); ?></p>
			<p><strong><?php echo esc_html( sprintf( __( 'Catégories analysées : %d', 'ufsc-licence-competition' ), $analyzed ) ); ?></strong></p>
			<?php if ( empty( $duplicates ) && empty( $same_weight ) ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Aucun doublon probable détecté avec les critères disponibles.', 'ufsc-licence-competition' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $duplicates ) ) : ?>
				<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Doublons possibles', 'ufsc-licence-competition' ); ?></strong> — <?php esc_html_e( 'Même discipline, sexe, tranche d’âge, niveau et poids/plage de poids. Vérification manuelle recommandée uniquement.', 'ufsc-licence-competition' ); ?></p></div>
				<?php $this->render_category_diagnostic_table( $duplicates, __( 'Doublons possibles', 'ufsc-licence-competition' ) ); ?>
			<?php endif; ?>
			<?php if ( ! empty( $same_weight ) ) : ?>
				<div class="notice notice-info inline"><p><strong><?php esc_html_e( 'Même poids, catégorie différente', 'ufsc-licence-competition' ); ?></strong> — <?php esc_html_e( 'Ces groupes partagent un poids mais diffèrent par discipline, sexe, âge ou niveau : ce n’est pas une anomalie critique.', 'ufsc-licence-competition' ); ?></p></div>
				<?php $this->render_category_diagnostic_table( $same_weight, __( 'Même poids, catégorie différente', 'ufsc-licence-competition' ) ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_category_diagnostic_table( array $groups, string $caption ): void {
		$groups = array_slice( $groups, 0, 20 );
		?>
		<div class="ufsc-competitions-table-wrap">
			<table class="widefat striped">
				<caption class="screen-reader-text"><?php echo esc_html( $caption ); ?></caption>
				<thead><tr><th><?php esc_html_e( 'Diagnostic', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Critères', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Catégories', 'ufsc-licence-competition' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $groups as $group ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $group['label'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $group['criteria'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( implode( ' | ', array_map( 'strval', (array) ( $group['names'] ?? array() ) ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function build_category_diagnostic( array $filters ): array {
		if ( ! class_exists( Db::class ) || ! Db::table_exists( Db::categories_table() ) ) {
			return array( 'analyzed' => 0, 'duplicates' => array(), 'same_weight' => array() );
		}

		$query_filters = array( 'view' => 'all' );
		if ( ! empty( $filters['competition_id'] ) ) {
			$query_filters['competition_id'] = absint( $filters['competition_id'] );
		}
		if ( ! empty( $filters['discipline'] ) ) {
			$query_filters['discipline'] = sanitize_key( (string) $filters['discipline'] );
		}

		$categories = $this->repository->list( $query_filters, 2000, 0 );
		if ( ! is_array( $categories ) ) {
			$categories = array();
		}

		$full_groups = array();
		$weight_groups = array();
		foreach ( $categories as $category ) {
			$normalized = $this->normalize_category_diagnostic_row( $category );
			$full_groups[ $normalized['full_key'] ][] = $normalized;
			if ( '' !== $normalized['weight_key'] ) {
				$weight_groups[ $normalized['weight_key'] ][] = $normalized;
			}
		}

		$duplicates = array();
		foreach ( $full_groups as $rows ) {
			if ( count( $rows ) > 1 ) {
				$duplicates[] = $this->format_category_diagnostic_group( $rows, __( 'Doublon possible', 'ufsc-licence-competition' ) );
			}
		}

		$same_weight = array();
		foreach ( $weight_groups as $rows ) {
			if ( count( $rows ) <= 1 ) {
				continue;
			}
			$full_keys = array_unique( wp_list_pluck( $rows, 'full_key' ) );
			if ( count( $full_keys ) > 1 ) {
				$same_weight[] = $this->format_category_diagnostic_group( $rows, __( 'Même poids, catégorie différente', 'ufsc-licence-competition' ) );
			}
		}

		return array(
			'analyzed' => count( $categories ),
			'duplicates' => $duplicates,
			'same_weight' => $same_weight,
		);
	}

	private function normalize_category_diagnostic_row( $category ): array {
		$discipline = sanitize_key( (string) ( $category->discipline ?? '' ) );
		$sex = sanitize_key( (string) ( $category->sex ?? '' ) );
		$level = sanitize_key( (string) ( $category->level ?? '' ) );
		$age_min = '' === (string) ( $category->age_min ?? '' ) ? '' : (string) absint( $category->age_min );
		$age_max = '' === (string) ( $category->age_max ?? '' ) ? '' : (string) absint( $category->age_max );
		$weight_min = $this->normalize_diagnostic_decimal( $category->weight_min ?? '' );
		$weight_max = $this->normalize_diagnostic_decimal( $category->weight_max ?? '' );
		$weight_key = $weight_min . '-' . $weight_max;

		return array(
			'name' => sanitize_text_field( (string) ( $category->name ?? '' ) ) . ' (#' . absint( $category->id ?? 0 ) . ')',
			'full_key' => implode( '|', array( $discipline, $sex, $age_min, $age_max, $level, $weight_min, $weight_max ) ),
			'weight_key' => '-' === $weight_key ? '' : $weight_key,
			'criteria' => sprintf( 'discipline=%s; sexe=%s; âge=%s-%s; niveau=%s; poids=%s-%s', $discipline ?: '—', $sex ?: '—', $age_min ?: '—', $age_max ?: '—', $level ?: '—', $weight_min ?: '—', $weight_max ?: '—' ),
		);
	}

	private function normalize_diagnostic_decimal( $value ): string {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return '';
		}

		return rtrim( rtrim( number_format( (float) $value, 3, '.', '' ), '0' ), '.' );
	}

	private function format_category_diagnostic_group( array $rows, string $label ): array {
		$first = $rows[0] ?? array();
		return array(
			'label' => $label,
			'criteria' => (string) ( $first['criteria'] ?? '' ),
			'names' => wp_list_pluck( $rows, 'name' ),
		);
	}

	public function handle_save() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_save_category' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'competition_id' => isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0,
			'discipline'     => isset( $_POST['discipline'] ) ? sanitize_text_field( wp_unslash( $_POST['discipline'] ) ) : '',
			'name'           => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'age_min'        => isset( $_POST['age_min'] ) ? sanitize_text_field( wp_unslash( $_POST['age_min'] ) ) : '',
			'age_max'        => isset( $_POST['age_max'] ) ? sanitize_text_field( wp_unslash( $_POST['age_max'] ) ) : '',
			'weight_min'     => isset( $_POST['weight_min'] ) ? sanitize_text_field( wp_unslash( $_POST['weight_min'] ) ) : '',
			'weight_max'     => isset( $_POST['weight_max'] ) ? sanitize_text_field( wp_unslash( $_POST['weight_max'] ) ) : '',
			'sex'            => isset( $_POST['sex'] ) ? sanitize_text_field( wp_unslash( $_POST['sex'] ) ) : '',
			'level'          => isset( $_POST['level'] ) ? sanitize_text_field( wp_unslash( $_POST['level'] ) ) : '',
			'format'         => isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : '',
		);

		if ( $id ) {
			$existing_category = $this->repository->get( $id, true );
			if ( $existing_category && ! empty( $existing_category->competition_id ) && function_exists( 'ufsc_lc_enforce_competition_access' ) ) {
				ufsc_lc_enforce_competition_access( (int) $existing_category->competition_id );
			}
		}
		if ( $data['competition_id'] && function_exists( 'ufsc_lc_enforce_competition_access' ) ) {
			ufsc_lc_enforce_competition_access( (int) $data['competition_id'] );
		}

		if ( $data['competition_id'] && class_exists( GenerationLockService::class ) && GenerationLockService::is_generation_locked( (int) $data['competition_id'] ) && ! Capabilities::current_user_can( Capabilities::SENSITIVE_OPS_CAPABILITY, (int) $data['competition_id'] ) ) {
			( new LogService() )->audit( 'sensitive_action_blocked', (int) $data['competition_id'], 'category', $id, array( 'reason' => 'categories_locked_after_generation' ) );
			$this->redirect_with_notice( Menu::PAGE_CATEGORIES, 'locked_after_generation', $id );
		}

		if ( $data['competition_id'] ) {
			$competition = $this->competition_repository->get( $data['competition_id'], true );
			if ( $competition ) {
				$data['discipline'] = $competition->discipline;
			}
		}

		$data['discipline'] = DisciplineRegistry::normalize( $data['discipline'] );

		if ( '' === $data['name'] || '' === $data['discipline'] ) {
			$this->redirect_with_notice( Menu::PAGE_CATEGORIES, 'error_required', $id );
		}

		if ( $id ) {
			$this->repository->update( $id, $data );
			$this->redirect_with_notice( Menu::PAGE_CATEGORIES, 'updated', $id );
		}

		$new_id = $this->repository->insert( $data );
		$this->redirect_with_notice( Menu::PAGE_CATEGORIES, 'created', $new_id );
	}

	public function handle_trash() {
		$this->handle_simple_action( 'ufsc_competitions_trash_category', 'trash', Menu::PAGE_CATEGORIES );
	}

	public function handle_restore() {
		$this->handle_simple_action( 'ufsc_competitions_restore_category', 'restore', Menu::PAGE_CATEGORIES );
	}

	public function handle_delete() {
		$this->handle_simple_action( 'ufsc_competitions_delete_category', 'delete', Menu::PAGE_CATEGORIES );
	}

	public function handle_preset_import() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_import_category_preset' );
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$discipline = isset( $_POST['discipline'] ) ? sanitize_key( wp_unslash( $_POST['discipline'] ) ) : '';
		$season = isset( $_POST['season'] ) ? sanitize_text_field( wp_unslash( $_POST['season'] ) ) : '2025-2026';
		$confirm = isset( $_POST['confirm_non_destructive'] ) ? sanitize_key( wp_unslash( $_POST['confirm_non_destructive'] ) ) : '';

		if ( ! $competition_id || '' === $discipline || 'yes' !== $confirm ) {
			$this->redirect_with_notice( Menu::PAGE_CATEGORIES, 'preset_missing_confirmation' );
		}
		if ( function_exists( 'ufsc_lc_enforce_competition_access' ) ) {
			ufsc_lc_enforce_competition_access( $competition_id );
		}

		$result = ( new CategoryPresetService() )->import_missing( $competition_id, $discipline, $season );
		( new LogService() )->audit(
			'category_preset_import',
			$competition_id,
			'category',
			0,
			$result,
			'Import non destructif du référentiel catégories manquantes.'
		);

		$url = add_query_arg(
			array(
				'page' => Menu::PAGE_CATEGORIES,
				'ufsc_notice' => 'preset_imported',
				'competition_id' => $competition_id,
				'discipline' => $discipline,
				'season' => $season,
				'created' => (int) $result['created'],
				'ignored' => (int) $result['ignored'],
				'conflicts' => (int) $result['conflicts'],
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	private function render_preset_preview_section(): void {
		$service = new CategoryPresetService();
		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$discipline = isset( $_GET['discipline'] ) ? sanitize_key( wp_unslash( $_GET['discipline'] ) ) : '';
		$season = isset( $_GET['season'] ) ? sanitize_text_field( wp_unslash( $_GET['season'] ) ) : '2025-2026';
		$competitions = $this->competition_repository->list( array( 'view' => 'all' ), 200, 0 );
		$disciplines = $service->get_tatami_disciplines();
		if ( '' === $discipline && ! empty( $disciplines ) ) {
			$discipline_keys = array_keys( $disciplines );
			$discipline = (string) reset( $discipline_keys );
		}
		$preview = $competition_id ? $service->preview( $competition_id, $discipline, $season ) : array( 'rows' => array() );
		$rows = $preview['rows'] ?? array();
		$extra_rows = (array) ( $preview['extra_rows'] ?? array() );
		$missing_count = count( array_filter( $rows, static function( $row ) { return empty( $row['exists'] ) && empty( $row['conflict'] ); } ) );
		$conflict_count = count( array_filter( $rows, static function( $row ) { return ! empty( $row['conflict'] ); } ) );
		?>
		<section class="ufsc-admin-surface ufsc-category-preset-preview">
			<h2><?php esc_html_e( 'Prévisualiser le référentiel officiel ASSAUT / TATAMI UFSC', 'ufsc-licence-competition' ); ?></h2>
			<p><?php esc_html_e( 'Prévisualisation non destructive du tableau officiel ASSAUT / TATAMI : aucune inscription, pesée, combat ou catégorie existante n’est modifié. L’import crée uniquement les catégories manquantes après confirmation.', 'ufsc-licence-competition' ); ?></p>
			<p><?php echo esc_html( sprintf( __( 'Référentiel attendu : %d lignes catégories/poids, avec séparation par sexe sauf pré-poussins mixte.', 'ufsc-licence-competition' ), count( CategoryPresetRegistry::get_assaut_tatami_categories_reference() ) ) ); ?></p>
			<form method="get" class="ufsc-admin-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_CATEGORIES ); ?>" />
				<select name="competition_id" required>
					<option value="0"><?php esc_html_e( 'Sélectionner une compétition', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $competitions as $competition ) : ?>
						<option value="<?php echo esc_attr( (int) $competition->id ); ?>" <?php selected( $competition_id, (int) $competition->id ); ?>><?php echo esc_html( (string) $competition->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="discipline">
					<?php foreach ( $disciplines as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $discipline, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="season">
					<?php foreach ( $service->get_available_seasons() as $season_key => $season_label ) : ?>
						<option value="<?php echo esc_attr( $season_key ); ?>" <?php selected( $season, $season_key ); ?>><?php echo esc_html( $season_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Prévisualiser le référentiel catégories', 'ufsc-licence-competition' ); ?></button>
			</form>
			<?php if ( $competition_id && $rows ) : ?>
				<p><strong><?php echo esc_html( sprintf( __( '%1$d catégorie(s) manquante(s) détectée(s), %2$d déjà présente(s), %3$d conflit(s) possible(s).', 'ufsc-licence-competition' ), (int) $missing_count, (int) count( $rows ) - (int) $missing_count - (int) $conflict_count, (int) $conflict_count ) ); ?></strong></p>
				<?php if ( ! empty( $extra_rows ) ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( __( '%d catégorie(s) présente(s) ne correspondent pas exactement au référentiel officiel. Elles sont signalées uniquement : aucune suppression ni modification automatique.', 'ufsc-licence-competition' ), count( $extra_rows ) ) ); ?></p></div>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Catégorie hors référentiel strict', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Âges', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Poids', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Sexe', 'ufsc-licence-competition' ); ?></th></tr></thead><tbody>
					<?php foreach ( array_slice( $extra_rows, 0, 30 ) as $extra ) : ?>
						<tr><td><?php echo esc_html( (string) ( $extra['name'] ?? '' ) . ' #' . (int) ( $extra['id'] ?? 0 ) ); ?></td><td><?php echo esc_html( (string) ( $extra['age_min'] ?? '—' ) . '–' . (string) ( $extra['age_max'] ?? '—' ) ); ?></td><td><?php echo esc_html( ( '' !== (string) ( $extra['weight_min'] ?? '' ) ? (string) $extra['weight_min'] : '0' ) . ' / ' . ( '' !== (string) ( $extra['weight_max'] ?? '' ) ? (string) $extra['weight_max'] : '+' ) ); ?></td><td><?php echo esc_html( (string) ( $extra['sex'] ?? '' ) ?: __( 'mixte/non renseigné', 'ufsc-licence-competition' ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Âges', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Poids', 'ufsc-licence-competition' ); ?></th><th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th></tr></thead><tbody>
				<?php foreach ( array_slice( $rows, 0, 80 ) as $row ) : ?>
					<tr><td><?php echo esc_html( (string) $row['name'] ); ?></td><td><?php echo esc_html( (string) $row['age_min'] . '–' . (string) $row['age_max'] ); ?></td><td><?php echo esc_html( ( '' !== (string) $row['weight_min'] ? (string) $row['weight_min'] : '0' ) . ' / ' . ( '' !== (string) $row['weight_max'] ? (string) $row['weight_max'] : '+' ) ); ?></td><td><?php echo ! empty( $row['conflict'] ) ? esc_html( sprintf( __( 'Conflit possible — ignorée (%s)', 'ufsc-licence-competition' ), (string) ( $row['conflict_reason'] ?? '' ) ) ) : ( ! empty( $row['exists'] ) ? esc_html__( 'Déjà existante — ignorée', 'ufsc-licence-competition' ) : esc_html__( 'À créer', 'ufsc-licence-competition' ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php if ( $missing_count > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ufsc_competitions_import_category_preset' ); ?>
						<input type="hidden" name="action" value="ufsc_competitions_import_category_preset" />
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>" />
						<input type="hidden" name="discipline" value="<?php echo esc_attr( $discipline ); ?>" />
						<input type="hidden" name="season" value="<?php echo esc_attr( $season ); ?>" />
						<label><input type="checkbox" name="confirm_non_destructive" value="yes" required /> <?php esc_html_e( 'Je confirme importer uniquement les catégories manquantes, sans modifier les inscriptions existantes.', 'ufsc-licence-competition' ); ?></label>
						<?php submit_button( __( 'Importer les catégories manquantes', 'ufsc-licence-competition' ), 'primary', '', false ); ?>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}


	private function handle_simple_action( $action, $method, $page_slug ) {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( $action . '_' . $id );

		if ( ! $id ) {
			$this->redirect_with_notice( $page_slug, 'not_found' );
		}
		$category = $this->repository->get( $id, true );
		if ( ! $category ) {
			$this->redirect_with_notice( $page_slug, 'not_found' );
		}
		if ( ! empty( $category->competition_id ) && function_exists( 'ufsc_lc_enforce_competition_access' ) ) {
			ufsc_lc_enforce_competition_access( (int) $category->competition_id );
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
			'discipline'     => $item->discipline ?? '',
			'name'           => $item->name ?? '',
			'age_min'        => $item->age_min ?? '',
			'age_max'        => $item->age_max ?? '',
			'weight_min'     => $item->weight_min ?? '',
			'weight_max'     => $item->weight_max ?? '',
			'sex'            => $item->sex ?? '',
			'level'          => $item->level ?? '',
			'format'         => $item->format ?? '',
		);

		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $this->competition_repository->list( $competition_filters, 200, 0 );
		$locked_discipline = '';
		if ( $values['competition_id'] ) {
			$competition = $this->competition_repository->get( $values['competition_id'], true );
			if ( $competition ) {
				$locked_discipline = $competition->discipline;
				$values['discipline'] = $competition->discipline;
			}
		}
		$disciplines  = DisciplineRegistry::get_disciplines();
		$action_label = $values['id'] ? __( 'Mettre à jour', 'ufsc-licence-competition' ) : __( 'Créer la catégorie', 'ufsc-licence-competition' );
		?>
		<div class="wrap ufsc-competitions-admin">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Référentiel catégories', 'ufsc-licence-competition' ); ?></p>
					<h1><?php echo esc_html( $values['id'] ? __( 'Modifier la catégorie', 'ufsc-licence-competition' ) : __( 'Nouvelle catégorie', 'ufsc-licence-competition' ) ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Renseignez les paramètres sportifs essentiels utilisés dans les listes, filtres et workflows de compétition.', 'ufsc-licence-competition' ); ?></p>
				</div>
			</header>
			<?php $this->render_helper_notice( __( 'Définir âge/poids/sexe/niveau + format poules/élimination.', 'ufsc-licence-competition' ) ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-competitions-form">
				<?php wp_nonce_field( 'ufsc_competitions_save_category' ); ?>
				<input type="hidden" name="action" value="ufsc_competitions_save_category">
				<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">
				<div class="ufsc-admin-surface ufsc-admin-form-surface">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_category_name"><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="name" type="text" id="ufsc_category_name" class="regular-text" value="<?php echo esc_attr( $values['name'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_discipline"><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<?php if ( $locked_discipline ) : ?>
								<input type="hidden" name="discipline" value="<?php echo esc_attr( $locked_discipline ); ?>">
								<input type="text" class="regular-text" value="<?php echo esc_attr( DisciplineRegistry::get_label( $locked_discipline ) ); ?>" readonly>
								<p class="description"><?php esc_html_e( 'La discipline est héritée de la compétition.', 'ufsc-licence-competition' ); ?></p>
							<?php else : ?>
								<select name="discipline" id="ufsc_category_discipline" class="regular-text" required>
									<option value=""><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
									<?php foreach ( $disciplines as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $values['discipline'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_competition"><?php esc_html_e( 'Compétition (optionnel)', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="competition_id" id="ufsc_category_competition" class="regular-text">
								<option value="0"><?php esc_html_e( 'Toutes', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $competitions as $competition ) : ?>
									<option value="<?php echo esc_attr( $competition->id ); ?>" <?php selected( $values['competition_id'], $competition->id ); ?>><?php echo esc_html( $competition->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_age_min"><?php esc_html_e( 'Âge min', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="age_min" type="number" id="ufsc_category_age_min" value="<?php echo esc_attr( $values['age_min'] ); ?>" min="0"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_age_max"><?php esc_html_e( 'Âge max', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="age_max" type="number" id="ufsc_category_age_max" value="<?php echo esc_attr( $values['age_max'] ); ?>" min="0"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_weight_min"><?php esc_html_e( 'Poids min (kg)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="weight_min" type="number" step="0.1" id="ufsc_category_weight_min" value="<?php echo esc_attr( $values['weight_min'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_weight_max"><?php esc_html_e( 'Poids max (kg)', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="weight_max" type="number" step="0.1" id="ufsc_category_weight_max" value="<?php echo esc_attr( $values['weight_max'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_sex"><?php esc_html_e( 'Sexe', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="sex" type="text" id="ufsc_category_sex" class="regular-text" value="<?php echo esc_attr( $values['sex'] ); ?>" placeholder="M/F/X"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_level"><?php esc_html_e( 'Niveau', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="level" type="text" id="ufsc_category_level" class="regular-text" value="<?php echo esc_attr( $values['level'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_category_format"><?php esc_html_e( 'Format', 'ufsc-licence-competition' ); ?></label></th>
						<td><input name="format" type="text" id="ufsc_category_format" class="regular-text" value="<?php echo esc_attr( $values['format'] ); ?>" placeholder="poule / élimination"></td>
					</tr>
				</table>
				</div>
				<?php submit_button( $action_label ); ?>
			</form>
		</div>
		<?php
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
			'created'       => __( 'Catégorie créée.', 'ufsc-licence-competition' ),
			'updated'       => __( 'Catégorie mise à jour.', 'ufsc-licence-competition' ),
			'trashed'       => __( 'Catégorie déplacée dans la corbeille.', 'ufsc-licence-competition' ),
			'restored'      => __( 'Catégorie restaurée.', 'ufsc-licence-competition' ),
			'deleted'       => __( 'Catégorie supprimée définitivement.', 'ufsc-licence-competition' ),
			'error_required'=> __( 'Veuillez renseigner le nom et la discipline de la catégorie.', 'ufsc-licence-competition' ),
			'not_found'     => __( 'Catégorie introuvable.', 'ufsc-licence-competition' ),
			'locked_after_generation' => __( 'Catégories verrouillées après génération : action sensible requise.', 'ufsc-licence-competition' ),
			'preset_imported' => __( 'Import du référentiel terminé : seules les catégories manquantes ont été créées, aucune inscription n’a été modifiée.', 'ufsc-licence-competition' ),
			'preset_missing_confirmation' => __( 'Import annulé : sélectionnez une compétition et confirmez explicitement que seules les catégories manquantes seront créées.', 'ufsc-licence-competition' ),
		);

		if ( ! $notice || ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type = in_array( $notice, array( 'error_required', 'not_found', 'locked_after_generation', 'preset_missing_confirmation' ), true ) ? 'error' : 'success';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $messages[ $notice ] ) );
	}

	private function render_helper_notice( $message ) {
		printf(
			'<div class="notice notice-info ufsc-competitions-helper"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	private function maybe_handle_bulk_actions( Categories_Table $list_table, $page_slug ) {
		$action = $list_table->current_action();
		if ( ! $action ) {
			return;
		}

		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'bulk-' . $list_table->_args['plural'] );

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_filter( $ids );
		if ( ! $ids ) {
			return;
		}

		foreach ( $ids as $id ) {
			$category = $this->repository->get( $id, true );
			if ( $category && ! empty( $category->competition_id ) && function_exists( 'ufsc_lc_enforce_competition_access' ) ) {
				ufsc_lc_enforce_competition_access( (int) $category->competition_id );
			}
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
}
