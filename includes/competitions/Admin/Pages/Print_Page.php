<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Repositories\WeighInRepository;
use UFSC\Competitions\Services\CompetitionMeta;
use UFSC\Competitions\Services\DisciplineRegistry;
use UFSC\Competitions\Services\FighterNumberService;
use UFSC\Competitions\Services\FightDisplayService;
use UFSC\Competitions\Services\PrintRenderer;
use UFSC\Competitions\Services\ResultSummaryService;
use UFSC\Competitions\Entries\EntriesWorkflow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Print_Page {
	private $competitions;
	private $categories;
	private $entries;
	private $fights;
	private $renderer;
	private $fighter_numbers_by_entry = array();
	private $result_summary;
	private $surface_map_cache = array();
	private $weighins;

	public function __construct() {
		$this->competitions = new CompetitionRepository();
		$this->categories = new CategoryRepository();
		$this->entries = new EntryRepository();
		$this->fights = new FightRepository();
		$this->weighins = new WeighInRepository();
		$this->renderer = new PrintRenderer();
		$this->result_summary = new ResultSummaryService();
	}

	public function render() {
		if ( ! Capabilities::user_can_read() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$type = isset( $_GET['print_type'] ) ? sanitize_key( wp_unslash( $_GET['print_type'] ) ) : 'entries';
		$format = isset( $_GET['print_format'] ) ? sanitize_key( wp_unslash( $_GET['print_format'] ) ) : 'a4';
		if ( ! in_array( $format, array( 'a4', 'a3', 'a2' ), true ) ) {
			$format = 'a4';
		}
		$filters = array(
			'surface'     => isset( $_GET['surface'] ) ? sanitize_text_field( wp_unslash( $_GET['surface'] ) ) : '',
			'category_id' => isset( $_GET['category_id'] ) ? absint( $_GET['category_id'] ) : 0,
			'discipline'  => isset( $_GET['discipline'] ) ? sanitize_text_field( wp_unslash( $_GET['discipline'] ) ) : '',
			'status'      => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
		);

		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $this->competitions->list( $competition_filters, 200, 0 );

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php esc_html_e( 'Impression', 'ufsc-licence-competition' ); ?></h1>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Sorties professionnelles des inscriptions, catégories et répartitions des combats par surface. Les placeholders comme “Vainqueur combat X” sont remplacés automatiquement quand les résultats sont saisis.', 'ufsc-licence-competition' ); ?></p></div>
			<form method="get" class="ufsc-competitions-print-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_PRINT ); ?>" />
				<label for="ufsc_print_competition" class="screen-reader-text"><?php esc_html_e( 'Compétition', 'ufsc-licence-competition' ); ?></label>
				<select name="competition_id" id="ufsc_print_competition">
					<option value="0"><?php esc_html_e( 'Sélectionner une compétition', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $competitions as $competition ) : ?>
						<option value="<?php echo esc_attr( $competition->id ); ?>" <?php selected( $competition_id, $competition->id ); ?>><?php echo esc_html( $competition->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="ufsc_print_type" class="screen-reader-text"><?php esc_html_e( 'Type d\'impression', 'ufsc-licence-competition' ); ?></label>
				<select name="print_type" id="ufsc_print_type">
					<option value="entries" <?php selected( $type, 'entries' ); ?>><?php esc_html_e( 'Liste détaillée des inscrits', 'ufsc-licence-competition' ); ?></option>
					<option value="categories" <?php selected( $type, 'categories' ); ?>><?php esc_html_e( 'Référentiel catégories', 'ufsc-licence-competition' ); ?></option>
					<option value="fights_by_surface" <?php selected( $type, 'fights_by_surface' ); ?>><?php esc_html_e( 'Répartition des combats par surface / tous les plateaux', 'ufsc-licence-competition' ); ?></option>
					<option value="fights_list" <?php selected( $type, 'fights_list' ); ?>><?php esc_html_e( 'Liste générale des combats', 'ufsc-licence-competition' ); ?></option>
					<option value="surface_sheet" <?php selected( $type, 'surface_sheet' ); ?>><?php esc_html_e( 'Feuille de surface', 'ufsc-licence-competition' ); ?></option>
					<option value="judge_sheets" <?php selected( $type, 'judge_sheets' ); ?>><?php esc_html_e( 'Feuilles arbitres / juges par combat', 'ufsc-licence-competition' ); ?></option>
					<option value="surface_overview" <?php selected( $type, 'surface_overview' ); ?>><?php esc_html_e( 'Affichage synthétique organisation', 'ufsc-licence-competition' ); ?></option>
					<option value="weighins" <?php selected( $type, 'weighins' ); ?>><?php esc_html_e( 'Liste des pesées', 'ufsc-licence-competition' ); ?></option>
					<option value="results_sheet" <?php selected( $type, 'results_sheet' ); ?>><?php esc_html_e( 'Feuille de résultats', 'ufsc-licence-competition' ); ?></option>
					<option value="results_entered" <?php selected( $type, 'results_entered' ); ?>><?php esc_html_e( 'Résultats saisis', 'ufsc-licence-competition' ); ?></option>
					<option value="lone_fighters" <?php selected( $type, 'lone_fighters' ); ?>><?php esc_html_e( 'Combattants sans adversaire', 'ufsc-licence-competition' ); ?></option>
					<option value="results_summary" <?php selected( $type, 'results_summary' ); ?>><?php esc_html_e( 'Synthèse résultats / podiums provisoires', 'ufsc-licence-competition' ); ?></option>
				</select>
				<label for="ufsc_print_format" class="screen-reader-text"><?php esc_html_e( 'Format', 'ufsc-licence-competition' ); ?></label>
				<select name="print_format" id="ufsc_print_format">
					<option value="a4" <?php selected( $format, 'a4' ); ?>>A4</option>
					<option value="a3" <?php selected( $format, 'a3' ); ?>>A3</option>
					<option value="a2" <?php selected( $format, 'a2' ); ?>>A2</option>
				</select>
				<label for="ufsc_print_surface" class="screen-reader-text"><?php esc_html_e( 'Surface', 'ufsc-licence-competition' ); ?></label>
				<input type="text" id="ufsc_print_surface" name="surface" value="<?php echo esc_attr( $filters['surface'] ); ?>" placeholder="<?php esc_attr_e( 'Surface', 'ufsc-licence-competition' ); ?>" />
				<label for="ufsc_print_category" class="screen-reader-text"><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></label>
				<input type="number" min="0" id="ufsc_print_category" name="category_id" value="<?php echo esc_attr( (string) $filters['category_id'] ); ?>" placeholder="<?php esc_attr_e( 'Catégorie ID', 'ufsc-licence-competition' ); ?>" />
				<label for="ufsc_print_discipline" class="screen-reader-text"><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></label>
				<input type="text" id="ufsc_print_discipline" name="discipline" value="<?php echo esc_attr( $filters['discipline'] ); ?>" placeholder="<?php esc_attr_e( 'Discipline', 'ufsc-licence-competition' ); ?>" />
				<label for="ufsc_print_status" class="screen-reader-text"><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></label>
				<input type="text" id="ufsc_print_status" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>" placeholder="<?php esc_attr_e( 'Statut', 'ufsc-licence-competition' ); ?>" />
				<?php submit_button( __( 'Afficher', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
				<?php submit_button( __( 'Imprimer', 'ufsc-licence-competition' ), 'primary', 'ufsc_print_now', false, array( 'onclick' => 'window.print();return false;' ) ); ?>
			</form>
			<p class="description">
				<?php
				$desc = array(
					'fights_by_surface' => __( 'Ce document affiche le plateau opérationnel : ordre des combats, surfaces, horaires estimés, coins rouge/bleu et observations.', 'ufsc-licence-competition' ),
					'fights_list' => __( 'Ce document donne une vue générale des combats triés par surface puis ordre, avec zones de résultat rapide et signature.', 'ufsc-licence-competition' ),
					'surface_sheet' => __( 'Ce document fournit une feuille exploitable par responsable de surface, avec observations et résultat rapide.', 'ufsc-licence-competition' ),
					'judge_sheets' => __( 'Ce document produit une feuille arbitre ou juge par combat réel, avec cases décision, score, observation et signature.', 'ufsc-licence-competition' ),
					'surface_overview' => __( 'Ce document sert aux officiels et à l’organisation : résumé des surfaces, volumes de combats, catégories ouvertes, alertes et besoins humains par surface.', 'ufsc-licence-competition' ),
					'categories' => __( 'Ce document liste les catégories réellement détectées dans les inscriptions ou dans les combats générés.', 'ufsc-licence-competition' ),
					'entries' => __( 'Ce document permet de contrôler les engagés, les données manquantes et les informations administratives avant génération.', 'ufsc-licence-competition' ),
					'weighins' => __( 'Ce document sert au contrôle des pesées : poids prévu, poids mesuré, statut et observations.', 'ufsc-licence-competition' ),
				);
				echo esc_html( $desc[ $type ] ?? $desc['entries'] );
				?>
			</p>
			<p class="description"><strong><?php esc_html_e( 'Format recommandé : paysage pour les tableaux larges.', 'ufsc-licence-competition' ); ?></strong></p>
			<?php if ( 'a4' === $format && in_array( $type, array( 'entries', 'weighins', 'fights_by_surface', 'fights_list', 'surface_sheet', 'lone_fighters', 'results_sheet' ), true ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Ce document contient beaucoup de colonnes. Le format paysage est recommandé.', 'ufsc-licence-competition' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $competition_id ) : ?>
				<?php
				if ( method_exists( $this->competitions, 'assert_competition_in_scope' ) ) {
					$this->competitions->assert_competition_in_scope( $competition_id );
				}
				$competition = $this->competitions->get( $competition_id, true );
				?>
				<?php if ( $competition ) : ?>
					<?php
					$competition_meta = class_exists( CompetitionMeta::class ) ? CompetitionMeta::get( (int) $competition->id ) : array();
					$header_meta = $this->build_header_meta( $competition, $competition_meta );
					$page_size = strtoupper( $format ) . ' ' . $this->get_print_orientation( $type, $format );
					?>
					<style media="print">@page { size: <?php echo esc_html( $page_size ); ?>; margin: 10mm; }</style>
					<div class="ufsc-print-area ufsc-print--<?php echo esc_attr( $format ); ?>">
						<?php
						echo $this->renderer->render_header(
							(string) $competition->name,
							$header_meta,
							array(
								'document_title' => $this->get_print_title( $type ),
								'generated_at' => $this->format_datetime( current_time( 'mysql' ) ),
							)
						);

						if ( 'categories' === $type ) {
							$this->render_categories_table( $competition_id );
						} elseif ( 'fights_by_surface' === $type || 'surface_sheet' === $type || 'fights_list' === $type ) {
							$this->render_fights_by_surface( $competition_id, $filters, $type );
						} elseif ( 'judge_sheets' === $type ) {
							$this->render_judge_sheets( $competition_id, $filters );
						} elseif ( 'surface_overview' === $type ) {
							$this->render_surface_overview( $competition_id );
						} elseif ( 'weighins' === $type ) {
							$this->render_weighins_table( $competition_id, $competition );
						} elseif ( 'results_sheet' === $type ) {
							$this->render_results_sheet( $competition_id, false, $filters );
						} elseif ( 'results_entered' === $type ) {
							$this->render_results_sheet( $competition_id, true, $filters );
						} elseif ( 'lone_fighters' === $type ) {
							$this->render_lone_fighters( $competition_id );
						} elseif ( 'results_summary' === $type ) {
							$this->render_results_summary( $competition_id );
						} else {
							$this->render_entries_table( $competition_id, $competition );
						}
						?>
						<div class="ufsc-print-footer">
							<span><?php esc_html_e( 'Document organisation compétition', 'ufsc-licence-competition' ); ?></span>
							<span class="ufsc-print-footer__page"><?php esc_html_e( 'Page', 'ufsc-licence-competition' ); ?> <span class="ufsc-print-page-number"></span></span>
						</div>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'Sélectionnez une compétition pour générer la vue imprimable.', 'ufsc-licence-competition' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_weighins_table( int $competition_id, $competition ): void {
		$entry_filters = array( 'view' => 'all', 'competition_id' => $competition_id );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$entry_filters = ufsc_lc_competitions_apply_scope_to_query_args( $entry_filters );
		}
		$entries = $this->entries->list_with_details( $entry_filters, 3000, 0 );
		$entry_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $entries, 'id' ) ) ) );
		$weighins = $this->weighins->get_for_entries( $competition_id, $entry_ids );

		echo '<h2>' . esc_html__( 'Liste des pesées', 'ufsc-licence-competition' ) . '</h2>';
		echo '<table class="widefat striped ufsc-print-table">';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'N°', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Nom', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Club', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Catégorie', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Poids prévu', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Poids mesuré', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Statut pesée', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Observation', 'ufsc-licence-competition' ) . '</th>'
			. '</tr></thead><tbody>';

		if ( empty( $entries ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Aucune inscription à imprimer.', 'ufsc-licence-competition' ) . '</td></tr>';
		} else {
			foreach ( $entries as $entry ) {
				$row = $weighins[ (int) ( $entry->id ?? 0 ) ] ?? null;
				$status = sanitize_key( (string) ( $row->status ?? 'pending' ) );
				$is_valid = $this->weighins->is_valid_weighin_row( $row, (float) ( $competition->weight_tolerance ?? 1 ), isset( $entry->weight_kg ) ? (float) $entry->weight_kg : null );
				$status_label = $is_valid ? __( 'Validée', 'ufsc-licence-competition' ) : ( $status ? $status : __( 'À faire', 'ufsc-licence-competition' ) );
				$name = trim( (string) ( ( $entry->last_name ?? '' ) . ' ' . ( $entry->first_name ?? '' ) ) );
				if ( '' === $name ) {
					$name = sprintf( '#%d', (int) ( $entry->id ?? 0 ) );
				}
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $entry->fighter_number ?? $entry->competition_number ?? $entry->id ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( $name ) . '</td>';
				echo '<td>' . esc_html( (string) ( $entry->club_name ?? $entry->club ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $entry->category_name ?? $entry->category ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $entry->weight_kg ?? $entry->weight ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row->weight_measured ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( $status_label ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row->notes ?? '' ) ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
	}

	private function render_categories_table( int $competition_id ): void {
		$categories = $this->categories->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );
		$entries = $this->entries->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 3000, 0 );
		$fights = $this->fights->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );

		echo '<h2>' . esc_html__( 'Catégories et classes', 'ufsc-licence-competition' ) . '</h2>';
		echo '<table class="widefat striped ufsc-print-table">';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'Discipline', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Catégorie', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Sexe', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Poids', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Sexe', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Niveau', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Inscrits', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Combats', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Statut', 'ufsc-licence-competition' ) . '</th>'
			. '</tr></thead><tbody>';
		$rows = array();
		foreach ( $entries as $entry ) {
			$key = strtolower( trim( (string) ( $entry->category ?? $entry->category_name ?? '—' ) ) ) . '|' . strtolower( trim( (string) ( $entry->weight_class ?? '—' ) ) );
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array( 'discipline' => (string) ( $entry->discipline ?? '—' ), 'category' => (string) ( $entry->category ?? $entry->category_name ?? '—' ), 'sex' => (string) ( $entry->sex ?? '—' ), 'weight' => (string) ( $entry->weight_class ?? '—' ), 'level' => (string) ( $entry->level ?? '—' ), 'entries' => 0, 'fights' => 0 );
			}
			$rows[ $key ]['entries']++;
		}
		foreach ( $fights as $fight ) {
			$key = strtolower( trim( (string) ( $fight->category_name ?? $fight->category ?? '—' ) ) ) . '|' . strtolower( trim( (string) ( $fight->weight_class ?? '—' ) ) );
			if ( isset( $rows[ $key ] ) ) { $rows[ $key ]['fights']++; }
		}
		if ( empty( $rows ) ) {
			foreach ( $categories as $category ) {
				$key = strtolower( (string) $category->name ) . '|—';
				$rows[ $key ] = array( 'discipline' => '—', 'category' => (string) $category->name, 'sex' => (string) ( $category->sex ?? '—' ), 'weight' => '—', 'level' => (string) ( $category->class_level ?? '—' ), 'entries' => 0, 'fights' => 0 );
			}
		}
		foreach ( $rows as $row ) {
			$status = $row['entries'] <= 1 ? __( 'combattant seul', 'ufsc-licence-competition' ) : ( $row['fights'] > 0 ? __( 'générée', 'ufsc-licence-competition' ) : __( 'en attente', 'ufsc-licence-competition' ) );
			echo '<tr><td>' . esc_html( $row['discipline'] ) . '</td><td>' . esc_html( $row['category'] ) . '</td><td>' . esc_html( $row['sex'] ) . '</td><td>' . esc_html( $row['weight'] ) . '</td><td>' . esc_html( $row['sex'] ) . '</td><td>' . esc_html( $row['level'] ) . '</td><td>' . esc_html( (string) $row['entries'] ) . '</td><td>' . esc_html( (string) $row['fights'] ) . '</td><td>' . esc_html( $status ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function render_entries_table( int $competition_id, $competition ): void {
		$entry_filters = array( 'view' => 'all', 'competition_id' => $competition_id );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$entry_filters = ufsc_lc_competitions_apply_scope_to_query_args( $entry_filters );
		}
		$entries = $this->entries->list_with_details( $entry_filters, 3000, 0 );
		$this->fighter_numbers_by_entry = FighterNumberService::build_map_from_entries( $competition_id, $entries );
		$categories = $this->categories->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );
		$category_map = array();
		foreach ( $categories as $category ) {
			$category_map[ (int) $category->id ] = (string) $category->name;
		}

		echo '<h2>' . esc_html__( 'Liste des inscrits', 'ufsc-licence-competition' ) . '</h2>';
		echo '<table class="widefat striped ufsc-print-table ufsc-print-table--entries">';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'N° combattant', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Licence', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Nom', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Prénom', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Sexe', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Date de naissance', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Âge', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Club', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Catégorie', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Poids', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Classe poids', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Statut inscription', 'ufsc-licence-competition' ) . '</th>'
			. '</tr></thead><tbody>';

		$reference_date = (string) ( $competition->event_start_datetime ?? current_time( 'mysql' ) );
		$reference_ts = strtotime( $reference_date );
		if ( ! $reference_ts ) {
			$reference_ts = current_time( 'timestamp' );
		}

		foreach ( $entries as $entry ) {
			$birthdate = function_exists( 'ufsc_comp_get_entry_birth_date' ) ? ufsc_comp_get_entry_birth_date( $entry ) : (string) ( $entry->birth_date ?? $entry->birthdate ?? $entry->licensee_birthdate ?? '' );
			$category_label = (string) ( $entry->category ?? $entry->category_name ?? '' );
			if ( '' === $category_label ) {
				$category_id = absint( $entry->category_id ?? 0 );
				$category_label = $category_id && isset( $category_map[ $category_id ] ) ? $category_map[ $category_id ] : '—';
			}

			$fighter_last_name = (string) ( $entry->licensee_last_name ?? $entry->last_name ?? $entry->lastname ?? '—' );
			$fighter_first_name = (string) ( $entry->licensee_first_name ?? $entry->first_name ?? $entry->firstname ?? '—' );
			$club_name = (string) ( $entry->club_name ?? '—' );

			echo '<tr>'
				. '<td>' . esc_html( $this->format_competitor_number( $entry ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $entry->license_number ?? '—' ) ) . '</td>'
				. '<td>' . esc_html( $fighter_last_name ) . '</td>'
				. '<td>' . esc_html( $fighter_first_name ) . '</td>'
				. '<td>' . esc_html( (string) ( $entry->licensee_sex ?? $entry->sex ?? $entry->gender ?? '—' ) ) . '</td>'
				. '<td>' . esc_html( function_exists( 'ufsc_comp_format_birth_date' ) ? ufsc_comp_format_birth_date( $birthdate ) : $this->format_date( (string) $birthdate ) ) . '</td>'
				. '<td>' . esc_html( function_exists( 'ufsc_comp_calculate_age' ) ? ufsc_comp_calculate_age( $birthdate, $reference_date ) : $this->compute_age_label( (string) $birthdate, $reference_ts ) ) . '</td>'
				. '<td>' . esc_html( $club_name ) . '</td>'
				. '<td>' . esc_html( $category_label ) . '</td>'
				. '<td>' . esc_html( $this->format_weight( $entry->weight_kg ?? $entry->weight ?? '' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $entry->weight_class ?? '—' ) ) . '</td>'
				. '<td>' . esc_html( $this->format_entry_status( $entry ) ) . '</td>'
				. '</tr>';
		}

		if ( ! $entries ) {
			echo '<tr><td colspan="12">' . esc_html__( 'Aucune inscription.', 'ufsc-licence-competition' ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function render_fights_by_surface( int $competition_id, array $filters = array(), string $print_type = 'fights_by_surface' ): void {
		$fights = $this->get_filtered_fights_for_print( $competition_id, $filters );

		$entry_filters = array( 'view' => 'all', 'competition_id' => $competition_id );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$entry_filters = ufsc_lc_competitions_apply_scope_to_query_args( $entry_filters );
		}
		$entries = $this->entries->list_with_details( $entry_filters, 3000, 0 );
		$this->fighter_numbers_by_entry = FighterNumberService::build_map_from_entries( $competition_id, $entries );
		$entry_map = array();
		foreach ( $entries as $entry ) {
			$entry_map[ (int) $entry->id ] = $entry;
		}

		$categories = $this->categories->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );
		$category_map = array();
		foreach ( $categories as $category ) {
			$category_map[ (int) $category->id ] = (string) $category->name;
		}

		$groups = array();
		$fights_by_category = array();
		foreach ( $fights as $fight ) {
			$surface = $this->resolve_surface_label( $fight );
			$short = trim( (string) ( $fight->surface_short_label ?? '' ) );
			if ( '' !== $short && '' !== $surface && false === strpos( $surface, $short ) ) {
				$surface .= ' — ' . $short;
			}
			$surface = '' !== $surface ? $surface : __( 'Surface non assignée', 'ufsc-licence-competition' );
			if ( ! isset( $groups[ $surface ] ) ) {
				$groups[ $surface ] = array();
			}
			$groups[ $surface ][] = $fight;
			$category_key = $this->get_category_key( (int) ( $fight->competition_id ?? 0 ), (int) ( $fight->category_id ?? 0 ) );
			if ( ! isset( $fights_by_category[ $category_key ] ) ) {
				$fights_by_category[ $category_key ] = array();
			}
			$fights_by_category[ $category_key ][] = $fight;
		}
		ksort( $groups, SORT_NATURAL | SORT_FLAG_CASE );

		$title = 'fights_list' === $print_type ? __( 'Liste générale des combats', 'ufsc-licence-competition' ) : __( 'Répartition des combats par surface / tatami / ring / aire', 'ufsc-licence-competition' );
		echo '<h2>' . esc_html( $title ) . '</h2>';
		$this->render_active_print_filters( $filters );
		if ( isset( $groups[ __( 'Surface non assignée', 'ufsc-licence-competition' ) ] ) && count( $groups ) > 1 ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Attention : surfaces configurées mais non assignées aux combats.', 'ufsc-licence-competition' ) . '</p></div>';
		}
		if ( ! $groups ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Aucun combat ne correspond aux filtres d’impression.', 'ufsc-licence-competition' ) . '</p></div>';
			return;
		}

		foreach ( $groups as $surface => $surface_fights ) {
			usort( $surface_fights, array( $this, 'compare_print_fights' ) );
			echo '<section class="ufsc-print-section ufsc-print-surface-sheet">';
			echo '<h3>' . esc_html( $surface ) . '</h3>';
			echo '<table class="widefat striped ufsc-print-table ufsc-print-table--fights">';
			echo '<thead><tr>'
				. '<th>' . esc_html__( 'Ordre', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Combat', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Horaire', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Discipline', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Catégorie / niveau', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Rouge', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Club rouge', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Bleu', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Club bleu', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Statut / type', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Résultat rapide', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Observation / signature', 'ufsc-licence-competition' ) . '</th>'
				. '</tr></thead><tbody>';

			foreach ( $surface_fights as $fight ) {
				$red = $entry_map[ (int) ( $fight->red_entry_id ?? 0 ) ] ?? null;
				$blue = $entry_map[ (int) ( $fight->blue_entry_id ?? 0 ) ] ?? null;
				$category_key = $this->get_category_key( (int) ( $fight->competition_id ?? 0 ), (int) ( $fight->category_id ?? 0 ) );
				$category_fights = $fights_by_category[ $category_key ] ?? array();
				$category_name = $category_map[ (int) ( $fight->category_id ?? 0 ) ] ?? '—';
				$type_label = $this->format_print_case_type( $fight );
				echo '<tr>'
					. '<td>' . esc_html( $this->get_order_label( $fight ) ) . '</td>'
					. '<td>#' . esc_html( (string) ( $fight->fight_no ?? '' ) ) . '<br><small>' . esc_html( $this->resolve_print_phase_label( $fight, $category_fights ) ) . '</small></td>'
					. '<td>' . esc_html( $this->get_print_time_label( $fight ) ) . '</td>'
					. '<td>' . esc_html( $this->get_fight_discipline_label( $fight, $red, $blue ) ) . '</td>'
					. '<td>' . esc_html( $this->format_fight_category_weight( $fight, $category_name ) ) . '</td>'
					. '<td class="ufsc-print-fighter-cell">' . wp_kses_post( nl2br( esc_html( $this->format_fighter_or_placeholder( $fight, 'red', $red ) ) ) ) . '</td>'
					. '<td>' . esc_html( $this->format_competitor_club( $red ) ) . '</td>'
					. '<td class="ufsc-print-fighter-cell">' . wp_kses_post( nl2br( esc_html( $this->format_fighter_or_placeholder( $fight, 'blue', $blue ) ) ) ) . '</td>'
					. '<td>' . esc_html( $this->format_competitor_club( $blue ) ) . '</td>'
					. '<td>' . esc_html( $this->format_fight_status( (string) ( $fight->status ?? '' ), $fight ) . ( '' !== $type_label ? ' · ' . $type_label : '' ) ) . '</td>'
					. '<td class="ufsc-print-result-box">□ R&nbsp;&nbsp;□ B<br>□ Décision □ Forfait<br>Score : ______</td>'
					. '<td class="ufsc-print-observation-box">Obs. : __________________<br>Signature : ______________</td>'
					. '</tr>';
			}

			echo '</tbody></table>';
			echo '</section>';
		}
	}

	private function render_surface_overview( int $competition_id ): void {
		$fights = $this->fights->list(
			array(
				'view' => 'all',
				'competition_id' => $competition_id,
			),
			5000,
			0
		);

		echo '<h2>' . esc_html__( 'Affichage synthétique organisation (officiels)', 'ufsc-licence-competition' ) . '</h2>';

		if ( ! $fights ) {
			echo '<p>' . esc_html__( 'Aucun combat disponible pour une synthèse organisation.', 'ufsc-licence-competition' ) . '</p>';
			return;
		}

		$groups = array();
		$non_assigned = 0;
		foreach ( $fights as $fight ) {
			$surface = trim( (string) ( $fight->ring ?? '' ) );
			$surface = '' !== $surface ? $surface : __( 'Surface non assignée', 'ufsc-licence-competition' );
			if ( __( 'Surface non assignée', 'ufsc-licence-competition' ) === $surface ) {
				$non_assigned++;
			}
			if ( ! isset( $groups[ $surface ] ) ) {
				$groups[ $surface ] = array();
			}
			$groups[ $surface ][] = $fight;
		}
		ksort( $groups, SORT_NATURAL | SORT_FLAG_CASE );
		echo '<p><strong>' . esc_html( sprintf( 'Surfaces utilisées: %1$d | Combats non assignés: %2$d', count( $groups ), $non_assigned ) ) . '</strong></p>';

		echo '<table class="widefat striped ufsc-print-table ufsc-print-table--surface-overview">';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'Surface', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Combats', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Plage horaire', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Statut dominant', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Remarques officiels', 'ufsc-licence-competition' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $groups as $surface => $surface_fights ) {
			$time_values = array();
			$status_counts = array();
			foreach ( $surface_fights as $fight ) {
				$time = trim( (string) ( $fight->scheduled_at ?? '' ) );
				if ( '' !== $time ) {
					$time_values[] = $time;
				}

				$status = (string) ( $fight->status ?? 'scheduled' );
				if ( ! isset( $status_counts[ $status ] ) ) {
					$status_counts[ $status ] = 0;
				}
				$status_counts[ $status ]++;
			}

			$time_label = '—';
			if ( $time_values ) {
				sort( $time_values );
				$time_start = $this->format_datetime( (string) reset( $time_values ) );
				$time_end = $this->format_datetime( (string) end( $time_values ) );
				$time_label = $time_start && $time_end ? $time_start . ' → ' . $time_end : ( $time_start ?: $time_end );
			}

			arsort( $status_counts );
			$dominant_status_key = (string) key( $status_counts );
				$dominant_status = '' !== $dominant_status_key ? $this->format_fight_status( $dominant_status_key ) : '—';

			echo '<tr>'
				. '<td>' . esc_html( $surface ) . '</td>'
				. '<td>' . esc_html( (string) count( $surface_fights ) ) . '</td>'
				. '<td>' . esc_html( $time_label ) . '</td>'
				. '<td>' . esc_html( $dominant_status ) . '</td>'
				. '<td>______________________________</td>'
				. '</tr>';
		}

		echo '</tbody></table>';
	}

	private function build_header_meta( $competition, array $competition_meta ): array {
		$event_date = $this->format_datetime( (string) ( $competition->event_start_datetime ?? '' ) );
		$location = (string) ( $competition_meta['lieu_name'] ?? $competition->lieu_name ?? $competition->location ?? '' );
		$organizer = (string) ( $competition_meta['organizer_contact_name'] ?? $competition->contact_name ?? '' );

		$meta = array(
			__( 'Discipline', 'ufsc-licence-competition' ) => DisciplineRegistry::get_label( (string) ( $competition->discipline ?? '' ) ),
			__( 'Saison', 'ufsc-licence-competition' ) => (string) ( $competition->season ?? '' ),
			__( 'Date', 'ufsc-licence-competition' ) => $event_date,
			__( 'Lieu', 'ufsc-licence-competition' ) => $location,
			__( 'Organisateur', 'ufsc-licence-competition' ) => $organizer,
		);

		return array_filter(
			$meta,
			static function ( $value ) {
				return '' !== trim( (string) $value );
			}
		);
	}

	private function get_print_title( string $type ): string {
		$map = array(
			'entries' => __( 'État administratif des inscrits', 'ufsc-licence-competition' ),
			'categories' => __( 'Référentiel des catégories', 'ufsc-licence-competition' ),
			'fights_by_surface' => __( 'Répartition des combats', 'ufsc-licence-competition' ),
			'fights_list' => __( 'Liste générale des combats', 'ufsc-licence-competition' ),
			'surface_sheet' => __( 'Feuille de surface', 'ufsc-licence-competition' ),
			'judge_sheets' => __( 'Feuilles arbitres / juges', 'ufsc-licence-competition' ),
			'surface_overview' => __( 'Affichage synthétique organisation', 'ufsc-licence-competition' ),
			'results_sheet' => __( 'Feuille de résultats', 'ufsc-licence-competition' ),
			'results_entered' => __( 'Résultats saisis', 'ufsc-licence-competition' ),
			'lone_fighters' => __( 'Combattants sans adversaire', 'ufsc-licence-competition' ),
		);

		return $map[ $type ] ?? __( 'Sortie compétition', 'ufsc-licence-competition' );
	}

	private function render_results_sheet( int $competition_id, bool $only_entered, array $filters = array() ): void {
		$fights = $this->get_filtered_fights_for_print( $competition_id, $filters );
		$entries = $this->entries->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 3000, 0 );
		$map = array();
		foreach ( $entries as $e ) { $map[ (int) $e->id ] = $e; }
		echo '<h2>' . esc_html( $only_entered ? __( 'Résultats saisis', 'ufsc-licence-competition' ) : __( 'Feuille de résultats', 'ufsc-licence-competition' ) ) . '</h2>';
		$this->render_active_print_filters( $filters );
		echo '<table class="widefat striped ufsc-print-table ufsc-print-results-sheet"><thead><tr><th>Ordre</th><th>Surface</th><th>N°</th><th>Rouge</th><th>Bleu</th><th>Vainqueur</th><th>Méthode</th><th>Score</th><th>Observation</th><th>Signature</th></tr></thead><tbody>';
		$count = 0;
		foreach ( $fights as $fight ) {
			$result = trim( (string) ( $fight->result ?? '' ) );
			if ( $only_entered && '' === $result && 'completed' !== (string) ( $fight->status ?? '' ) ) { continue; }
			$count++;
			$winner_entry_id = (int) ( $fight->winner_entry_id ?? 0 );
			$winner_label = $winner_entry_id > 0 ? $this->format_fighter_label( $map[ $winner_entry_id ] ?? null ) : '—';
			$result_type = (string) ( $fight->result_type ?? '' );
			$red_label = $this->format_fighter_or_placeholder( $fight, 'red', $map[ (int) ( $fight->red_entry_id ?? 0 ) ] ?? null );
			$blue_label = $this->format_fighter_or_placeholder( $fight, 'blue', $map[ (int) ( $fight->blue_entry_id ?? 0 ) ] ?? null );
			echo '<tr><td>' . esc_html( $this->get_order_label( $fight ) ) . '</td><td>' . esc_html( $this->resolve_surface_label( $fight ) ) . '</td><td>#' . esc_html( (string) ( $fight->fight_no ?? 0 ) ) . '</td><td class="ufsc-print-fighter-cell">' . wp_kses_post( nl2br( esc_html( $red_label ) ) ) . '</td><td class="ufsc-print-fighter-cell">' . wp_kses_post( nl2br( esc_html( $blue_label ) ) ) . '</td><td>' . esc_html( $winner_label ) . '</td><td>' . esc_html( $result_type ?: '□ Décision □ Forfait □ Abandon □ DQ □ KO/TKO' ) . '</td><td>' . esc_html( '' !== $result ? $result : '________' ) . '</td><td>________________</td><td>__________</td></tr>';
		}
		if ( 0 === $count ) { echo '<tr><td colspan="10">' . esc_html__( 'Aucun résultat saisi pour cette compétition.', 'ufsc-licence-competition' ) . '</td></tr>'; }
		echo '</tbody></table>';
	}


	private function render_judge_sheets( int $competition_id, array $filters = array() ): void {
		$fights = $this->get_filtered_fights_for_print( $competition_id, $filters );
		$entries = $this->entries->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 3000, 0 );
		$this->fighter_numbers_by_entry = FighterNumberService::build_map_from_entries( $competition_id, $entries );
		$map = array();
		foreach ( $entries as $entry ) {
			$map[ (int) $entry->id ] = $entry;
		}
		$categories = $this->categories->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );
		$category_map = array();
		foreach ( $categories as $category ) {
			$category_map[ (int) $category->id ] = (string) $category->name;
		}
		echo '<h2>' . esc_html__( 'Feuilles arbitres / juges par combat', 'ufsc-licence-competition' ) . '</h2>';
		$this->render_active_print_filters( $filters );
		$count = 0;
		foreach ( $fights as $fight ) {
			$status = sanitize_key( (string) ( $fight->status ?? '' ) );
			$case_type = sanitize_key( (string) ( $fight->case_type ?? $fight->type ?? '' ) );
			if ( in_array( $status, array( 'bye', 'placeholder' ), true ) || in_array( $case_type, array( 'bye', 'placeholder' ), true ) ) {
				$notice_label = $this->format_print_case_type( $fight );
				if ( '' === $notice_label ) {
					$notice_label = strtoupper( '' !== $status ? $status : $case_type );
				}
				echo '<section class="ufsc-print-judge-sheet ufsc-print-judge-sheet--notice"><h3>#' . esc_html( (string) ( $fight->fight_no ?? 0 ) ) . ' — ' . esc_html( $notice_label ) . '</h3><p>' . esc_html__( 'Aucune feuille arbitre individuelle nécessaire : cas signalé pour l’organisation.', 'ufsc-licence-competition' ) . '</p></section>';
				continue;
			}
			$count++;
			$red = $map[ (int) ( $fight->red_entry_id ?? 0 ) ] ?? null;
			$blue = $map[ (int) ( $fight->blue_entry_id ?? 0 ) ] ?? null;
			$category_name = $category_map[ (int) ( $fight->category_id ?? 0 ) ] ?? '—';
			echo '<section class="ufsc-print-judge-sheet">';
			echo '<h3>' . esc_html( sprintf( __( 'Combat #%1$s — %2$s — Ordre %3$s', 'ufsc-licence-competition' ), (string) ( $fight->fight_no ?? 0 ), $this->resolve_surface_label( $fight ), $this->get_order_label( $fight ) ) ) . '</h3>';
			echo '<div class="ufsc-print-judge-grid">';
			echo '<div><strong>' . esc_html__( 'Discipline', 'ufsc-licence-competition' ) . '</strong><br>' . esc_html( $this->get_fight_discipline_label( $fight, $red, $blue ) ) . '</div>';
			echo '<div><strong>' . esc_html__( 'Catégorie / format', 'ufsc-licence-competition' ) . '</strong><br>' . esc_html( $this->format_fight_category_weight( $fight, $category_name ) . ' · ' . $this->format_print_case_type( $fight ) ) . '</div>';
			echo '<div><strong>' . esc_html__( 'Durée', 'ufsc-licence-competition' ) . '</strong><br>' . esc_html( $this->format_duration_label( $fight ) ) . '</div>';
			echo '</div>';
			echo '<table class="ufsc-print-table ufsc-print-judge-table"><tbody>';
			echo '<tr><th>' . esc_html__( 'Rouge', 'ufsc-licence-competition' ) . '</th><td>' . wp_kses_post( nl2br( esc_html( $this->format_fighter_or_placeholder( $fight, 'red', $red ) ) ) ) . '</td><td>' . esc_html( $this->format_competitor_club( $red ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Bleu', 'ufsc-licence-competition' ) . '</th><td>' . wp_kses_post( nl2br( esc_html( $this->format_fighter_or_placeholder( $fight, 'blue', $blue ) ) ) ) . '</td><td>' . esc_html( $this->format_competitor_club( $blue ) ) . '</td></tr>';
			echo '</tbody></table>';
			echo '<div class="ufsc-print-decision-boxes"><span>□ ' . esc_html__( 'Vainqueur rouge', 'ufsc-licence-competition' ) . '</span><span>□ ' . esc_html__( 'Vainqueur bleu', 'ufsc-licence-competition' ) . '</span><span>□ ' . esc_html__( 'Décision', 'ufsc-licence-competition' ) . '</span><span>□ ' . esc_html__( 'Forfait', 'ufsc-licence-competition' ) . '</span><span>□ ' . esc_html__( 'Abandon', 'ufsc-licence-competition' ) . '</span><span>□ ' . esc_html__( 'Disqualification', 'ufsc-licence-competition' ) . '</span><span>□ KO/TKO</span></div>';
			echo '<div class="ufsc-print-signature-row"><div>' . esc_html__( 'Score / méthode :', 'ufsc-licence-competition' ) . ' ____________________</div><div>' . esc_html__( 'Observations :', 'ufsc-licence-competition' ) . ' ____________________</div><div>' . esc_html__( 'Signature arbitre/officiel :', 'ufsc-licence-competition' ) . ' ____________________</div></div>';
			echo '</section>';
		}
		if ( 0 === $count ) {
			echo '<p>' . esc_html__( 'Aucun combat réel ne correspond aux filtres.', 'ufsc-licence-competition' ) . '</p>';
		}
	}

	private function get_filtered_fights_for_print( int $competition_id, array $filters = array() ): array {
		$fights = $this->fights->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );
		$fights = array_values(
			array_filter(
				$fights,
				function ( $fight ) use ( $filters ) {
					if ( ! empty( $filters['surface'] ) && false === stripos( $this->resolve_surface_label( $fight ), (string) $filters['surface'] ) ) {
						return false;
					}
					if ( ! empty( $filters['category_id'] ) && absint( $fight->category_id ?? 0 ) !== absint( $filters['category_id'] ) ) {
						return false;
					}
					if ( ! empty( $filters['status'] ) && sanitize_key( (string) ( $fight->status ?? '' ) ) !== sanitize_key( (string) $filters['status'] ) ) {
						return false;
					}
					if ( ! empty( $filters['discipline'] ) ) {
						$discipline = trim( (string) ( $fight->discipline ?? $fight->category_discipline ?? '' ) );
						$discipline_label = '' !== $discipline ? DisciplineRegistry::get_label( $discipline ) : '';
						$discipline_haystack = trim( $discipline . ' ' . $discipline_label );
						if ( '' === $discipline_haystack || false === stripos( $discipline_haystack, (string) $filters['discipline'] ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
		usort( $fights, array( $this, 'compare_print_fights' ) );
		return $fights;
	}

	private function render_active_print_filters( array $filters ): void {
		$labels = array();
		foreach ( array( 'surface' => __( 'Surface', 'ufsc-licence-competition' ), 'category_id' => __( 'Catégorie', 'ufsc-licence-competition' ), 'discipline' => __( 'Discipline', 'ufsc-licence-competition' ), 'status' => __( 'Statut', 'ufsc-licence-competition' ) ) as $key => $label ) {
			$value = $filters[ $key ] ?? '';
			if ( 'category_id' === $key ) {
				if ( absint( $value ) <= 0 ) {
					continue;
				}
				$value = (string) absint( $value );
			} elseif ( '' === trim( (string) $value ) ) {
				continue;
			}
			$labels[] = $label . ' : ' . sanitize_text_field( (string) $value );
		}
		if ( $labels ) {
			echo '<p class="ufsc-print-active-filters"><strong>' . esc_html__( 'Filtres', 'ufsc-licence-competition' ) . '</strong> — ' . esc_html( implode( ' · ', $labels ) ) . '</p>';
		}
	}

	private function render_lone_fighters( int $competition_id ): void {
		$entries = $this->entries->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 3000, 0 );
		$groups = array();
		foreach ( $entries as $entry ) {
			$key = sanitize_key( (string) ( $entry->category ?? $entry->category_name ?? 'nc' ) ) . '|' . sanitize_text_field( (string) ( $entry->weight_class ?? 'nc' ) );
			$groups[ $key ][] = $entry;
		}
		echo '<h2>' . esc_html__( 'Combattants sans adversaire', 'ufsc-licence-competition' ) . '</h2><table class="widefat striped"><thead><tr><th>N°</th><th>Nom</th><th>Club</th><th>Catégorie</th><th>Poids</th><th>Raison</th><th>Action conseillée</th></tr></thead><tbody>';
		$has = false;
		foreach ( $groups as $g ) {
			if ( count( $g ) !== 1 ) { continue; }
			$has = true; $e = $g[0];
			echo '<tr><td>' . esc_html( $this->format_competitor_number( $e ) ) . '</td><td>' . esc_html( $this->format_fighter_label( $e ) ) . '</td><td>' . esc_html( $this->format_competitor_club( $e ) ) . '</td><td>' . esc_html( (string) ( $e->category ?? $e->category_name ?? '—' ) ) . '</td><td>' . esc_html( (string) ( $e->weight_class ?? '—' ) ) . '</td><td>' . esc_html__( 'Seul dans sa catégorie', 'ufsc-licence-competition' ) . '</td><td>' . esc_html__( 'Attendre inscription / regrouper / opposition gala', 'ufsc-licence-competition' ) . '</td></tr>';
		}
		if ( ! $has ) { echo '<tr><td colspan="7">—</td></tr>'; }
		echo '</tbody></table>';
	}



	private function render_results_summary( int $competition_id ): void {
		$summary = $this->result_summary->build_competition_summary( $competition_id );
		echo '<h2>' . esc_html__( 'Synthèse résultats (provisoire)', 'ufsc-licence-competition' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Statut', 'ufsc-licence-competition' ) . ':</strong> ' . esc_html__( 'Provisoire / à vérifier manuellement', 'ufsc-licence-competition' ) . '</p>';
		echo '<ul>';
		echo '<li>' . esc_html( sprintf( __( 'Combats terminés: %d', 'ufsc-licence-competition' ), (int) ( $summary['completed_fights'] ?? 0 ) ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Combats sans résultat fiable: %d', 'ufsc-licence-competition' ), (int) ( $summary['fights_without_result'] ?? 0 ) ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Litiges: %d', 'ufsc-licence-competition' ), (int) ( $summary['litiges'] ?? 0 ) ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Absences: %d', 'ufsc-licence-competition' ), (int) ( $summary['absents'] ?? 0 ) ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Forfaits: %d', 'ufsc-licence-competition' ), (int) ( $summary['forfaits'] ?? 0 ) ) ) . '</li>';
		echo '</ul>';
		foreach ( (array) ( $summary['categories'] ?? array() ) as $category_id => $cat ) {
			echo '<h3>' . esc_html( sprintf( __( 'Catégorie #%d', 'ufsc-licence-competition' ), (int) $category_id ) ) . '</h3>';
			if ( ! empty( $cat['notes'] ) ) {
				echo '<p><em>' . esc_html( implode( ' | ', array_map( 'strval', (array) $cat['notes'] ) ) ) . '</em></p>';
			}
			$podium = (array) ( $cat['podium']['top3'] ?? array() );
			echo '<p><strong>' . esc_html__( 'Podium provisoire', 'ufsc-licence-competition' ) . ':</strong> ';
			if ( empty( $podium ) ) { echo esc_html__( 'Données insuffisantes', 'ufsc-licence-competition' ); }
			else { $labels=array(); foreach($podium as $idx=>$row){$labels[]=(($idx+1).'. '.(string)($row['label']??'N/A'));} echo esc_html( implode( ' — ', $labels ) ); }
			echo '</p>';
			$ranking = (array) ( $cat['pool_ranking']['rows'] ?? array() );
			if ( ! empty( $ranking ) ) {
				echo '<table class="widefat striped"><thead><tr><th>#</th><th>Nom</th><th>Club</th><th>J</th><th>V</th><th>D</th><th>PF</th><th>PC</th><th>Diff</th><th>F</th><th>Litiges</th><th>Statut</th></tr></thead><tbody>';
				foreach ( $ranking as $i => $r ) {
					echo '<tr><td>' . esc_html( (string) ( $i + 1 ) ) . '</td><td>' . esc_html( (string) ( $r['name'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $r['club'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $r['fights'] ?? 0 ) ) . '</td><td>' . esc_html( (string) ( $r['wins'] ?? 0 ) ) . '</td><td>' . esc_html( (string) ( $r['losses'] ?? 0 ) ) . '</td><td>' . esc_html( (string) ( $r['points_for'] ?? 0 ) ) . '</td><td>' . esc_html( (string) ( $r['points_against'] ?? 0 ) ) . '</td><td>' . esc_html( (string) ( $r['diff'] ?? 0 ) ) . '</td><td>' . esc_html( (string) ( $r['forfaits'] ?? 0 ) ) . '</td><td>' . esc_html( (string) ( $r['litiges'] ?? 0 ) ) . '</td><td>' . esc_html( (string) ( $r['status'] ?? 'provisoire' ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}
	}
	private function format_datetime( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'ufsc_lc_format_datetime' ) ) {
			return (string) ufsc_lc_format_datetime( $value, $value );
		}

		$date = date_create( $value );
		return $date ? $date->format( 'Y-m-d H:i' ) : $value;
	}

	private function format_fighter_or_placeholder( $fight, string $slot, $entry ): string {
		$label = $this->format_fighter_label( $entry );
		if ( '—' !== $label ) {
			return $label;
		}
		$source_column = 'red' === $slot ? 'source_red_fight_id' : 'source_blue_fight_id';
		$source_fight_id = (int) ( $fight->{$source_column} ?? 0 );
		if ( $source_fight_id <= 0 ) {
			return $label;
		}
		$phase = mb_strtolower( (string) ( $fight->phase ?? '' ) );
		if ( false !== strpos( $phase, 'petite finale' ) ) {
			return sprintf( 'Perdant combat %d', $source_fight_id );
		}
		return sprintf( 'Vainqueur combat %d', $source_fight_id );
	}

	private function format_date( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '—';
		}

		if ( function_exists( 'ufsc_comp_format_birth_date' ) ) {
			return ufsc_comp_format_birth_date( $value );
		}
		$date = date_create( $value );
		return $date ? $date->format( 'd/m/Y' ) : '—';
	}

	private function compute_age_label( string $birthdate, int $reference_ts ): string {
		if ( function_exists( 'ufsc_comp_calculate_age' ) ) {
			return ufsc_comp_calculate_age( $birthdate, gmdate( 'Y-m-d H:i:s', $reference_ts ) );
		}
		$birthdate = trim( $birthdate );
		if ( '' === $birthdate ) {
			return '—';
		}
		$birth_ts = strtotime( $birthdate );
		if ( ! $birth_ts ) {
			return '—';
		}
		$age = (int) floor( ( $reference_ts - $birth_ts ) / YEAR_IN_SECONDS );
		if ( $age < 0 || $age > 120 ) {
			return '—';
		}

		return (string) $age;
	}

	private function format_weight( $value ): string {
		if ( '' === (string) $value || null === $value ) {
			return '—';
		}
		$weight = (float) str_replace( ',', '.', (string) $value );
		if ( $weight <= 0 ) {
			return '—';
		}

		return number_format_i18n( $weight, 1 ) . ' kg';
	}

	private function format_range( $min, $max, string $unit ): string {
		$min = '' !== (string) $min ? (string) $min : '';
		$max = '' !== (string) $max ? (string) $max : '';

		if ( '' !== $min && '' !== $max ) {
			return sprintf( '%s - %s %s', $min, $max, $unit );
		}
		if ( '' !== $min ) {
			return sprintf( '%s+ %s', $min, $unit );
		}
		if ( '' !== $max ) {
			return sprintf( '≤ %s %s', $max, $unit );
		}

		return '—';
	}

	private function format_entry_status( $entry ): string {
		$status = '';
		if ( function_exists( 'ufsc_lc_is_entry_eligible' ) ) {
			$eligibility = ufsc_lc_is_entry_eligible( (int) ( $entry->id ?? 0 ), 'admin_entries' );
			$status = (string) ( $eligibility['status'] ?? '' );
		}

		if ( '' === $status ) {
			$status = $this->entries->get_entry_status( $entry );
		}

		return class_exists( EntriesWorkflow::class ) ? EntriesWorkflow::get_status_label( $status ) : $status;
	}

	private function format_fighter_label( $entry ): string {
		if ( function_exists( 'ufsc_comp_format_fighter_print_label' ) ) {
			return ufsc_comp_format_fighter_print_label( $entry );
		}
		return '—';
	}

	private function format_competitor_number( $entry ): string {
		if ( ! $entry ) {
			return '—';
		}
		$number = '';
		foreach ( array( 'fighter_number', 'combatant_number', 'contestant_number', 'numero_combattant' ) as $field ) {
			$value = trim( (string) ( $entry->{$field} ?? '' ) );
			if ( '' !== $value ) {
				$number = $value;
				break;
			}
		}
		if ( '' === $number ) {
			$resolved = FighterNumberService::resolve_for_entry( $entry, $this->fighter_numbers_by_entry );
			if ( $resolved > 0 ) {
				$number = (string) $resolved;
			}
		}
		if ( '' === $number ) {
			return '—';
		}
		return '#' . str_pad( preg_replace( '/\D+/', '', (string) $number ) ?: (string) $number, 3, '0', STR_PAD_LEFT );
	}

	private function format_competitor_club( $entry ): string {
		if ( ! $entry ) {
			return '—';
		}

		$club = trim( (string) ( $entry->club_name ?? $entry->club_nom ?? '' ) );
		$department = function_exists( 'ufsc_comp_get_club_department' ) ? ufsc_comp_get_club_department( $entry ) : '—';
		$club = '' !== $club ? $club : '—';
		return sprintf( '%s — Dept %s', $club, '' !== $department ? $department : '—' );
	}


	private function compare_print_fights( $a, $b ): int {
		$order_a = absint( $a->scheduled_order ?? 0 );
		$order_b = absint( $b->scheduled_order ?? 0 );
		if ( $order_a !== $order_b ) {
			return ( 0 === $order_a ? PHP_INT_MAX : $order_a ) <=> ( 0 === $order_b ? PHP_INT_MAX : $order_b );
		}
		$time_a = ! empty( $a->scheduled_time ) ? strtotime( (string) $a->scheduled_time ) : ( ! empty( $a->scheduled_at ) ? strtotime( (string) $a->scheduled_at ) : 0 );
		$time_b = ! empty( $b->scheduled_time ) ? strtotime( (string) $b->scheduled_time ) : ( ! empty( $b->scheduled_at ) ? strtotime( (string) $b->scheduled_at ) : 0 );
		if ( $time_a !== $time_b ) {
			return ( 0 === $time_a ? PHP_INT_MAX : $time_a ) <=> ( 0 === $time_b ? PHP_INT_MAX : $time_b );
		}
		$fight_no = absint( $a->fight_no ?? 0 ) <=> absint( $b->fight_no ?? 0 );
		return 0 !== $fight_no ? $fight_no : ( absint( $a->id ?? 0 ) <=> absint( $b->id ?? 0 ) );
	}

	private function get_order_label( $fight ): string {
		$order = absint( $fight->scheduled_order ?? 0 );
		if ( $order > 0 ) {
			return (string) $order;
		}
		$fight_no = absint( $fight->fight_no ?? 0 );
		return $fight_no > 0 ? '#' . $fight_no : '—';
	}

	private function get_print_time_label( $fight ): string {
		$time = trim( (string) ( $fight->scheduled_time ?? $fight->scheduled_at ?? '' ) );
		$formatted = $this->format_datetime( $time );
		return '' !== $formatted ? $formatted : '—';
	}

	private function get_fight_discipline_label( $fight, $red = null, $blue = null ): string {
		foreach ( array( $fight, $red, $blue ) as $source ) {
			if ( ! $source ) {
				continue;
			}
			$value = trim( (string) ( $source->discipline ?? $source->category_discipline ?? '' ) );
			if ( '' !== $value ) {
				return DisciplineRegistry::get_label( $value );
			}
		}
		return '—';
	}

	private function format_print_case_type( $fight ): string {
		$type = sanitize_key( (string) ( $fight->case_type ?? $fight->type ?? $fight->status ?? '' ) );
		$labels = array(
			'bye' => 'BYE',
			'placeholder' => __( 'Attente / placeholder', 'ufsc-licence-competition' ),
			'direct_final' => __( 'Finale directe', 'ufsc-licence-competition' ),
			'pool_3' => __( 'Poule de 3', 'ufsc-licence-competition' ),
			'bracket_with_bye' => __( 'Tableau avec BYE', 'ufsc-licence-competition' ),
		);
		return $labels[ $type ] ?? '';
	}

	private function format_duration_label( $fight ): string {
		$duration = absint( $fight->fight_duration ?? $fight->round_duration ?? 0 );
		$rounds = absint( $fight->rounds ?? 0 );
		$parts = array();
		if ( $rounds > 0 ) {
			$parts[] = sprintf( _n( '%d reprise', '%d reprises', $rounds, 'ufsc-licence-competition' ), $rounds );
		}
		if ( $duration > 0 ) {
			$parts[] = gmdate( 'i:s', $duration );
		}
		return $parts ? implode( ' · ', $parts ) : '—';
	}

	private function get_print_orientation( string $print_type, string $format ): string {
		$landscape_types = array( 'entries', 'fights_by_surface', 'fights_list', 'surface_sheet', 'lone_fighters', 'surface_overview' );
		return in_array( $print_type, $landscape_types, true ) ? 'landscape' : 'portrait';
	}

	private function resolve_surface_label( $fight ): string {
		foreach ( array( 'surface_name', 'surface', 'ring', 'tatami', 'area', 'surface_short_label', 'scheduled_surface' ) as $key ) {
			$value = trim( (string) ( $fight->{$key} ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		$surface_id = trim( (string) ( $fight->surface_id ?? '' ) );
		if ( '' !== $surface_id ) {
			$competition_id = absint( $fight->competition_id ?? 0 );
			$surface_map = $this->get_surface_map( $competition_id );
			if ( isset( $surface_map[ $surface_id ] ) && '' !== trim( (string) $surface_map[ $surface_id ] ) ) {
				return (string) $surface_map[ $surface_id ];
			}
		}
		return __( 'Surface non assignée', 'ufsc-licence-competition' );
	}

	private function get_surface_map( int $competition_id ): array {
		if ( isset( $this->surface_map_cache[ $competition_id ] ) ) {
			return $this->surface_map_cache[ $competition_id ];
		}
		$map = array();
		if ( $competition_id > 0 && function_exists( 'ufsc_competition_get_surfaces' ) ) {
			$surfaces = (array) ufsc_competition_get_surfaces( $competition_id );
			foreach ( $surfaces as $index => $surface ) {
				$name = trim( (string) ( $surface['name'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}
				$keys = array(
					(string) ( $surface['uuid'] ?? '' ),
					(string) ( $surface['index'] ?? '' ),
					(string) ( $surface['order'] ?? '' ),
					(string) ( $index + 1 ),
				);
				foreach ( $keys as $key ) {
					$key = trim( $key );
					if ( '' !== $key && ! isset( $map[ $key ] ) ) {
						$map[ $key ] = $name;
					}
				}
			}
		}
		$this->surface_map_cache[ $competition_id ] = $map;
		return $map;
	}

	private function format_fight_category_weight( $fight, string $category_name ): string {
		$parts = array();
		$category_name = trim( $category_name );
		if ( '' !== $category_name ) {
			$parts[] = $category_name;
		}

		$weight = $this->format_weight( $fight->weight_kg ?? '' );
		if ( '—' !== $weight ) {
			$parts[] = $weight;
		}

		$weight_class = trim( (string) ( $fight->weight_class ?? '' ) );
		if ( '' !== $weight_class ) {
			$parts[] = $weight_class;
		}

		return $parts ? implode( ' · ', $parts ) : '—';
	}

	private function get_category_key( int $competition_id, int $category_id ): string {
		return $competition_id . ':' . $category_id;
	}

	private function format_fight_status( string $status, $fight = null ): string {
		$status_label = $this->fights->get_status_label( $status );
		if ( ! $fight || ! $this->fights->is_fight_bye( $fight ) ) {
			return $status_label;
		}

		$winner_entry_id = $this->fights->get_bye_winner_entry_id( $fight );
		if ( $winner_entry_id <= 0 ) {
			return $status_label;
		}

		return sprintf(
			/* translators: %s: status label */
			__( '%s — Qualifié automatiquement', 'ufsc-licence-competition' ),
			$status_label
		);
	}

	private function resolve_print_phase_label( $fight, array $category_fights ): string {
		if ( 'bye' === (string) ( $fight->status ?? '' ) ) {
			return 'BYE';
		}
		if ( 'placeholder' === (string) ( $fight->status ?? '' ) ) {
			return 'Combat à venir';
		}
		$round_no = (int) ( $fight->round_no ?? 0 );
		$count = count( $category_fights );
		if ( $round_no > 1 && $count >= 3 ) {
			return 'Finale';
		}
		if ( 4 === $count && 1 === $round_no ) {
			return 'Demi-finale';
		}
		if ( $count >= 8 && 1 === $round_no ) {
			return 'Quart de finale';
		}
		$label = FightDisplayService::format_phase_label( $fight, $category_fights );
		if ( '' === trim( (string) $label ) || false !== stripos( (string) $label, 'Finale' ) ) {
			return 'Combat';
		}
		return $label;
	}

}
