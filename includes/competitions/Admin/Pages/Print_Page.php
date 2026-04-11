<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Services\CompetitionMeta;
use UFSC\Competitions\Services\DisciplineRegistry;
use UFSC\Competitions\Services\FightDisplayService;
use UFSC\Competitions\Services\PrintRenderer;
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

	public function __construct() {
		$this->competitions = new CompetitionRepository();
		$this->categories = new CategoryRepository();
		$this->entries = new EntryRepository();
		$this->fights = new FightRepository();
		$this->renderer = new PrintRenderer();
	}

	public function render() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$type = isset( $_GET['print_type'] ) ? sanitize_key( wp_unslash( $_GET['print_type'] ) ) : 'entries';
		$format = isset( $_GET['print_format'] ) ? sanitize_key( wp_unslash( $_GET['print_format'] ) ) : 'a4';
		if ( ! in_array( $format, array( 'a4', 'a3', 'a2' ), true ) ) {
			$format = 'a4';
		}

		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $this->competitions->list( $competition_filters, 200, 0 );

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php esc_html_e( 'Impression', 'ufsc-licence-competition' ); ?></h1>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Sorties professionnelles des inscriptions, catégories et répartitions des combats par surface.', 'ufsc-licence-competition' ); ?></p></div>
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
					<option value="fights_by_surface" <?php selected( $type, 'fights_by_surface' ); ?>><?php esc_html_e( 'Répartition des combats par surface', 'ufsc-licence-competition' ); ?></option>
					<option value="surface_overview" <?php selected( $type, 'surface_overview' ); ?>><?php esc_html_e( 'Affichage synthétique organisation', 'ufsc-licence-competition' ); ?></option>
				</select>
				<label for="ufsc_print_format" class="screen-reader-text"><?php esc_html_e( 'Format', 'ufsc-licence-competition' ); ?></label>
				<select name="print_format" id="ufsc_print_format">
					<option value="a4" <?php selected( $format, 'a4' ); ?>>A4</option>
					<option value="a3" <?php selected( $format, 'a3' ); ?>>A3</option>
					<option value="a2" <?php selected( $format, 'a2' ); ?>>A2</option>
				</select>
				<?php submit_button( __( 'Afficher', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
				<?php submit_button( __( 'Imprimer', 'ufsc-licence-competition' ), 'primary', 'ufsc_print_now', false, array( 'onclick' => 'window.print();return false;' ) ); ?>
			</form>

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
					$page_size = 'A4 portrait';
					if ( 'a3' === $format ) {
						$page_size = 'A3 landscape';
					} elseif ( 'a2' === $format ) {
						$page_size = 'A2 landscape';
					}
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
						} elseif ( 'fights_by_surface' === $type ) {
							$this->render_fights_by_surface( $competition_id );
						} elseif ( 'surface_overview' === $type ) {
							$this->render_surface_overview( $competition_id );
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

	private function render_categories_table( int $competition_id ): void {
		$categories = $this->categories->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );

		echo '<h2>' . esc_html__( 'Catégories et classes', 'ufsc-licence-competition' ) . '</h2>';
		echo '<table class="widefat striped ufsc-print-table">';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'Catégorie', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Âges', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Poids', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Sexe', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Niveau', 'ufsc-licence-competition' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $categories as $category ) {
			echo '<tr>'
				. '<td>' . esc_html( $category->name ) . '</td>'
				. '<td>' . esc_html( $this->format_range( $category->age_min ?? '', $category->age_max ?? '', __( 'ans', 'ufsc-licence-competition' ) ) ) . '</td>'
				. '<td>' . esc_html( $this->format_range( $category->weight_min ?? '', $category->weight_max ?? '', 'kg' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $category->sex ?? '—' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $category->class_level ?? '—' ) ) . '</td>'
				. '</tr>';
		}

		if ( ! $categories ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Aucune catégorie.', 'ufsc-licence-competition' ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function render_entries_table( int $competition_id, $competition ): void {
		$entry_filters = array( 'view' => 'all', 'competition_id' => $competition_id );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$entry_filters = ufsc_lc_competitions_apply_scope_to_query_args( $entry_filters );
		}
		$entries = $this->entries->list_with_details( $entry_filters, 3000, 0 );
		$categories = $this->categories->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );
		$category_map = array();
		foreach ( $categories as $category ) {
			$category_map[ (int) $category->id ] = (string) $category->name;
		}

		echo '<h2>' . esc_html__( 'Liste des inscrits', 'ufsc-licence-competition' ) . '</h2>';
		echo '<table class="widefat striped ufsc-print-table ufsc-print-table--entries">';
		echo '<thead><tr>'
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
			$birthdate = (string) ( $entry->birth_date ?? $entry->birthdate ?? $entry->licensee_birthdate ?? '' );
			$category_label = (string) ( $entry->category ?? $entry->category_name ?? '' );
			if ( '' === $category_label ) {
				$category_id = absint( $entry->category_id ?? 0 );
				$category_label = $category_id && isset( $category_map[ $category_id ] ) ? $category_map[ $category_id ] : '—';
			}

			$fighter_last_name = (string) ( $entry->licensee_last_name ?? $entry->last_name ?? $entry->lastname ?? '—' );
			$fighter_first_name = (string) ( $entry->licensee_first_name ?? $entry->first_name ?? $entry->firstname ?? '—' );
			$club_name = (string) ( $entry->club_name ?? '—' );

			echo '<tr>'
				. '<td>' . esc_html( (string) ( $entry->license_number ?? '—' ) ) . '</td>'
				. '<td>' . esc_html( $fighter_last_name ) . '</td>'
				. '<td>' . esc_html( $fighter_first_name ) . '</td>'
				. '<td>' . esc_html( (string) ( $entry->licensee_sex ?? $entry->sex ?? $entry->gender ?? '—' ) ) . '</td>'
				. '<td>' . esc_html( $this->format_date( $birthdate ) ) . '</td>'
				. '<td>' . esc_html( $this->compute_age_label( $birthdate, $reference_ts ) ) . '</td>'
				. '<td>' . esc_html( $club_name ) . '</td>'
				. '<td>' . esc_html( $category_label ) . '</td>'
				. '<td>' . esc_html( $this->format_weight( $entry->weight_kg ?? $entry->weight ?? '' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $entry->weight_class ?? '—' ) ) . '</td>'
				. '<td>' . esc_html( $this->format_entry_status( $entry ) ) . '</td>'
				. '</tr>';
		}

		if ( ! $entries ) {
			echo '<tr><td colspan="11">' . esc_html__( 'Aucune inscription.', 'ufsc-licence-competition' ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function render_fights_by_surface( int $competition_id ): void {
		$fights = $this->fights->list(
			array(
				'view' => 'all',
				'competition_id' => $competition_id,
			),
			5000,
			0
		);

		$entry_filters = array( 'view' => 'all', 'competition_id' => $competition_id );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$entry_filters = ufsc_lc_competitions_apply_scope_to_query_args( $entry_filters );
		}
		$entries = $this->entries->list_with_details( $entry_filters, 3000, 0 );
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
			$surface = trim( (string) ( $fight->ring ?? '' ) );
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

		echo '<h2>' . esc_html__( 'Répartition des combats par surface / tatami / ring / aire', 'ufsc-licence-competition' ) . '</h2>';
		if ( ! $groups ) {
			echo '<p>' . esc_html__( 'Aucun combat planifié pour cette compétition.', 'ufsc-licence-competition' ) . '</p>';
			return;
		}

		foreach ( $groups as $surface => $surface_fights ) {
			echo '<section class="ufsc-print-section">';
			echo '<h3>' . esc_html( $surface ) . '</h3>';
			echo '<table class="widefat striped ufsc-print-table ufsc-print-table--fights">';
			echo '<thead><tr>'
				. '<th>' . esc_html__( 'Ordre', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Phase', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'N° Rouge', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Coin Rouge', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Club Rouge', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'N° Bleu', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Coin Bleu', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Club Bleu', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Catégorie / poids', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Horaire', 'ufsc-licence-competition' ) . '</th>'
				. '<th>' . esc_html__( 'Statut', 'ufsc-licence-competition' ) . '</th>'
				. '</tr></thead><tbody>';

			usort(
				$surface_fights,
				static function ( $a, $b ) {
					return (int) ( $a->fight_no ?? 0 ) <=> (int) ( $b->fight_no ?? 0 );
				}
			);

			foreach ( $surface_fights as $fight ) {
				$red = $entry_map[ (int) ( $fight->red_entry_id ?? 0 ) ] ?? null;
				$blue = $entry_map[ (int) ( $fight->blue_entry_id ?? 0 ) ] ?? null;
				$category_key = $this->get_category_key( (int) ( $fight->competition_id ?? 0 ), (int) ( $fight->category_id ?? 0 ) );
				$category_fights = $fights_by_category[ $category_key ] ?? array();
				$red_label = FightDisplayService::format_corner_label( $fight, $red, 'red', $category_fights );
				$blue_label = FightDisplayService::format_corner_label( $fight, $blue, 'blue', $category_fights );
				$category_name = $category_map[ (int) ( $fight->category_id ?? 0 ) ] ?? '—';
				$phase_label = FightDisplayService::format_phase_label( $fight, $category_fights );
				$scheduled_at = $this->format_datetime( (string) ( $fight->scheduled_at ?? '' ) );
				$category_weight = $this->format_fight_category_weight( $fight, $category_name );
				$red_no = $this->format_competitor_number( $red );
				$blue_no = $this->format_competitor_number( $blue );
				$red_club = $this->format_competitor_club( $red );
				$blue_club = $this->format_competitor_club( $blue );

				echo '<tr>'
					. '<td>#' . esc_html( (string) ( $fight->fight_no ?? '' ) ) . '</td>'
					. '<td>' . esc_html( $phase_label ) . '</td>'
					. '<td>' . esc_html( $red_no ) . '</td>'
					. '<td>' . esc_html( $red_label ) . '</td>'
					. '<td>' . esc_html( $red_club ) . '</td>'
					. '<td>' . esc_html( $blue_no ) . '</td>'
					. '<td>' . esc_html( $blue_label ) . '</td>'
					. '<td>' . esc_html( $blue_club ) . '</td>'
					. '<td>' . esc_html( $category_weight ) . '</td>'
					. '<td>' . esc_html( '' !== $scheduled_at ? $scheduled_at : '—' ) . '</td>'
					. '<td>' . esc_html( $this->format_fight_status( (string) ( $fight->status ?? '' ) ) ) . '</td>'
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
		foreach ( $fights as $fight ) {
			$surface = trim( (string) ( $fight->ring ?? '' ) );
			$surface = '' !== $surface ? $surface : __( 'Surface non assignée', 'ufsc-licence-competition' );
			if ( ! isset( $groups[ $surface ] ) ) {
				$groups[ $surface ] = array();
			}
			$groups[ $surface ][] = $fight;
		}
		ksort( $groups, SORT_NATURAL | SORT_FLAG_CASE );

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
			'surface_overview' => __( 'Affichage synthétique organisation', 'ufsc-licence-competition' ),
		);

		return $map[ $type ] ?? __( 'Sortie compétition', 'ufsc-licence-competition' );
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

	private function format_date( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '—';
		}

		$date = date_create( $value );
		return $date ? $date->format( 'd/m/Y' ) : $value;
	}

	private function compute_age_label( string $birthdate, int $reference_ts ): string {
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
		if ( ! $entry ) {
			return '—';
		}
		$name = trim( (string) ( $entry->licensee_last_name ?? $entry->last_name ?? '' ) . ' ' . (string) ( $entry->licensee_first_name ?? $entry->first_name ?? '' ) );
		if ( '' === $name ) {
			$name = '#' . (int) ( $entry->id ?? 0 );
		}
		$club = (string) ( $entry->club_name ?? '' );
		$license = (string) ( $entry->license_number ?? '' );
		$parts = array_filter( array( $name, $club, $license ) );

		return implode( ' · ', $parts );
	}

	private function format_competitor_number( $entry ): string {
		if ( ! $entry ) {
			return '—';
		}

		$number = (int) ( $entry->fighter_number ?? $entry->competition_number ?? 0 );
		if ( $number > 0 ) {
			return '#' . $number;
		}

		return '#' . (int) ( $entry->id ?? 0 );
	}

	private function format_competitor_club( $entry ): string {
		if ( ! $entry ) {
			return '—';
		}

		$club = trim( (string) ( $entry->club_name ?? $entry->club_nom ?? '' ) );
		return '' !== $club ? $club : '—';
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

	private function format_fight_status( string $status ): string {
		$labels = array(
			'scheduled' => __( 'Planifié', 'ufsc-licence-competition' ),
			'running' => __( 'En cours', 'ufsc-licence-competition' ),
			'completed' => __( 'Terminé', 'ufsc-licence-competition' ),
		);

		return $labels[ $status ] ?? $status;
	}
}
