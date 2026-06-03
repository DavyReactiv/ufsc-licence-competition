<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Services\ResultService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Results_Page {
	private $competitions;
	private $fights;
	private $entries;
	private $categories;
	private $result_service;

	public function __construct() {
		$this->competitions = new CompetitionRepository();
		$this->fights = new FightRepository();
		$this->entries = new EntryRepository();
		$this->categories = new CategoryRepository();
		$this->result_service = new ResultService();
	}

	public function register_actions(): void {
		add_action( 'admin_post_ufsc_competitions_results_record', array( $this, 'handle_record_result' ) );
		add_action( 'admin_post_ufsc_competitions_results_correct', array( $this, 'handle_correct_result' ) );
		add_action( 'admin_post_ufsc_competitions_results_lock', array( $this, 'handle_lock_result' ) );
	}

	public function render(): void {
		if ( ! Capabilities::user_can_read() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$filters = $this->read_filters_from_request();
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$message = isset( $_GET['ufsc_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['ufsc_message'] ) ) ) : '';
		$competitions = $this->get_competitions_for_select();
		$competition = $competition_id > 0 ? $this->competitions->get( $competition_id, true ) : null;
		if ( $competition && function_exists( 'ufsc_lc_enforce_competition_access' ) ) {
			ufsc_lc_enforce_competition_access( $competition_id );
		}
		?>
		<div class="wrap ufsc-competitions-admin">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Jour J', 'ufsc-licence-competition' ); ?></p>
					<h1><?php esc_html_e( 'Résultats jour J', 'ufsc-licence-competition' ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Saisie rapide et contrôlée des résultats des combats générés, sans modifier la génération ni les impressions.', 'ufsc-licence-competition' ); ?></p>
				</div>
				<?php if ( $competition_id > 0 ) : ?>
					<div class="ufsc-admin-page-actions">
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_PRINT, 'competition_id' => $competition_id, 'print_type' => 'results_sheet' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Feuille résultats', 'ufsc-licence-competition' ); ?></a>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_PLATEAU, 'competition_id' => $competition_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Plateau jour J', 'ufsc-licence-competition' ); ?></a>
					</div>
				<?php endif; ?>
			</header>
			<?php $this->render_notice( $notice, $message ); ?>
			<form method="get" class="ufsc-admin-toolbar ufsc-admin-toolbar--filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_RESULTS ); ?>" />
				<label for="ufsc_results_competition" class="screen-reader-text"><?php esc_html_e( 'Compétition', 'ufsc-licence-competition' ); ?></label>
				<select id="ufsc_results_competition" name="competition_id">
					<option value="0"><?php esc_html_e( 'Sélectionner une compétition', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $competitions as $item ) : ?>
						<option value="<?php echo esc_attr( (int) $item->id ); ?>" <?php selected( $competition_id, (int) $item->id ); ?>><?php echo esc_html( (string) $item->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="surface" value="<?php echo esc_attr( $filters['surface'] ); ?>" placeholder="<?php esc_attr_e( 'Surface', 'ufsc-licence-competition' ); ?>" />
				<input type="text" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>" placeholder="<?php esc_attr_e( 'Statut', 'ufsc-licence-competition' ); ?>" />
				<input type="number" min="0" name="category_id" value="<?php echo esc_attr( (string) $filters['category_id'] ); ?>" placeholder="<?php esc_attr_e( 'Catégorie ID', 'ufsc-licence-competition' ); ?>" />
				<input type="text" name="discipline" value="<?php echo esc_attr( $filters['discipline'] ); ?>" placeholder="<?php esc_attr_e( 'Discipline', 'ufsc-licence-competition' ); ?>" />
				<?php submit_button( __( 'Filtrer', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
			</form>
			<?php
			if ( $competition_id <= 0 ) {
				echo '<div class="notice notice-info"><p>' . esc_html__( 'Sélectionnez une compétition pour afficher les combats à saisir.', 'ufsc-licence-competition' ) . '</p></div>';
				echo '</div>';
				return;
			}
			if ( ! $competition ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Compétition introuvable.', 'ufsc-licence-competition' ) . '</p></div>';
				echo '</div>';
				return;
			}
			$this->render_results_table( $competition_id, $filters );
			?>
		</div>
		<?php
	}

	public function handle_record_result(): void {
		$this->handle_result_mutation( 'record' );
	}

	public function handle_correct_result(): void {
		$this->handle_result_mutation( 'correct' );
	}

	public function handle_lock_result(): void {
		if ( ! Capabilities::user_can_correct_results() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
		$fight_id = isset( $_POST['fight_id'] ) ? absint( $_POST['fight_id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_results_lock_' . $fight_id );
		$fight = $this->get_fight_for_post( $fight_id );
		$competition_id = (int) ( $fight->competition_id ?? 0 );
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$result = $this->result_service->lock_result( $fight_id, $reason );
		if ( empty( $result['ok'] ) ) {
			$this->redirect_to_results( $competition_id, 'lock_blocked', $this->get_admin_error_message( (string) ( $result['error'] ?? 'lock_blocked' ), (string) ( $result['message'] ?? '' ) ) );
		}
		$this->redirect_to_results( $competition_id, 'result_locked' );
	}

	private function handle_result_mutation( string $mode ): void {
		$can_submit = 'correct' === $mode ? Capabilities::user_can_correct_results() : Capabilities::user_can_record_results();
		if ( ! $can_submit ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
		$fight_id = isset( $_POST['fight_id'] ) ? absint( $_POST['fight_id'] ) : 0;
		check_admin_referer( 'ufsc_competitions_results_' . $mode . '_' . $fight_id );
		$fight = $this->get_fight_for_post( $fight_id );
		$competition_id = (int) ( $fight->competition_id ?? 0 );
		$payload = array(
			'winner_entry_id' => isset( $_POST['winner_entry_id'] ) ? absint( $_POST['winner_entry_id'] ) : 0,
			'result_type'     => isset( $_POST['result_type'] ) ? sanitize_key( wp_unslash( $_POST['result_type'] ) ) : '',
			'score_red'       => isset( $_POST['score_red'] ) ? sanitize_text_field( wp_unslash( $_POST['score_red'] ) ) : '',
			'score_blue'      => isset( $_POST['score_blue'] ) ? sanitize_text_field( wp_unslash( $_POST['score_blue'] ) ) : '',
			'note'            => isset( $_POST['result_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['result_note'] ) ) : '',
			'reason'          => isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '',
		);
		$result = 'correct' === $mode ? $this->result_service->correct_result( $fight_id, $payload ) : $this->result_service->record_result( $fight_id, $payload );
		if ( empty( $result['ok'] ) ) {
			$this->redirect_to_results( $competition_id, 'result_blocked', $this->get_admin_error_message( (string) ( $result['error'] ?? 'result_blocked' ), (string) ( $result['message'] ?? '' ) ) );
		}
		$this->redirect_to_results( $competition_id, 'correct' === $mode ? 'result_corrected' : 'result_recorded' );
	}

	private function render_results_table( int $competition_id, array $filters ): void {
		$fights = $this->get_filtered_fights( $competition_id, $filters );
		$entries = $this->entries->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 3000, 0 );
		$entry_map = array();
		foreach ( $entries as $entry ) {
			$entry_map[ (int) $entry->id ] = $entry;
		}
		$categories = $this->categories->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );
		$category_map = array();
		foreach ( $categories as $category ) {
			$category_map[ (int) $category->id ] = (string) $category->name;
		}
		echo '<div class="ufsc-admin-surface">';
		echo '<h2>' . esc_html__( 'Combats à traiter', 'ufsc-licence-competition' ) . '</h2>';
		echo '<table class="widefat striped ufsc-results-day-table"><thead><tr>';
		foreach ( array( 'Ordre', 'N°', 'Surface', 'Horaire', 'Discipline', 'Catégorie', 'Rouge', 'Bleu', 'Statut', 'Résultat', 'Action' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $fights ) {
			echo '<tr><td colspan="11">' . esc_html__( 'Aucun combat ne correspond aux filtres.', 'ufsc-licence-competition' ) . '</td></tr>';
		}
		foreach ( $fights as $fight ) {
			$red = $entry_map[ (int) ( $fight->red_entry_id ?? 0 ) ] ?? null;
			$blue = $entry_map[ (int) ( $fight->blue_entry_id ?? 0 ) ] ?? null;
			$status = $this->fights->get_effective_fight_status( $fight );
			$is_special = $this->is_bye_or_placeholder( $fight );
			$is_locked = FightRepository::STATUS_LOCKED === $status;
			$has_result = $this->fight_has_result_payload( $fight );
			$can_record = Capabilities::user_can_record_results() && ! $is_special && ! $is_locked && ! $has_result;
			$can_correct = Capabilities::user_can_correct_results() && ! $is_special && ! $is_locked && $has_result;
			$can_lock = Capabilities::user_can_correct_results() && ! $is_special && ! $is_locked && $has_result;
			echo '<tr>';
			echo '<td>' . esc_html( $this->format_order( $fight ) ) . '</td>';
			echo '<td>#' . esc_html( (string) ( $fight->fight_no ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_surface( $fight ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_time( $fight ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_discipline( $fight, $red, $blue ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_category( $fight, $category_map ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_entry_label( $red ) . ' — ' . $this->format_entry_club( $red ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_entry_label( $blue ) . ' — ' . $this->format_entry_club( $blue ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_status_label( $status, $fight ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_result_label( $fight, $entry_map ) ) . '</td>';
			echo '<td>';
			if ( $is_special ) {
				echo '<em>' . esc_html__( 'BYE / placeholder / supprimé : aucune saisie classique.', 'ufsc-licence-competition' ) . '</em>';
			} elseif ( $is_locked ) {
				echo '<strong>' . esc_html__( 'Verrouillé', 'ufsc-licence-competition' ) . '</strong>';
			} elseif ( $can_record || $can_correct ) {
				$this->render_result_form( $fight, $red, $blue, $can_correct ? 'correct' : 'record' );
				if ( $can_lock ) {
					$this->render_lock_form( $fight );
				}
			} else {
				echo '<em>' . esc_html__( 'Action non autorisée.', 'ufsc-licence-competition' ) . '</em>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private function render_result_form( $fight, $red, $blue, string $mode ): void {
		$fight_id = (int) ( $fight->id ?? 0 );
		$action = 'correct' === $mode ? 'ufsc_competitions_results_correct' : 'ufsc_competitions_results_record';
		$nonce = 'ufsc_competitions_results_' . $mode . '_' . $fight_id;
		$button = 'correct' === $mode ? __( 'Corriger', 'ufsc-licence-competition' ) : __( 'Saisir', 'ufsc-licence-competition' );
		$red_id = (int) ( $fight->red_entry_id ?? 0 );
		$blue_id = (int) ( $fight->blue_entry_id ?? 0 );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ufsc-inline-result-form">';
		wp_nonce_field( $nonce );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="fight_id" value="' . esc_attr( $fight_id ) . '" />';
		echo '<input type="hidden" name="competition_id" value="' . esc_attr( (int) ( $fight->competition_id ?? 0 ) ) . '" />';
		echo '<select name="winner_entry_id" aria-label="' . esc_attr__( 'Vainqueur', 'ufsc-licence-competition' ) . '">';
		echo '<option value="0">' . esc_html__( 'Sans vainqueur', 'ufsc-licence-competition' ) . '</option>';
		if ( $red_id > 0 ) {
			echo '<option value="' . esc_attr( $red_id ) . '" ' . selected( (int) ( $fight->winner_entry_id ?? 0 ), $red_id, false ) . '>' . esc_html( 'Rouge — ' . $this->format_entry_label( $red ) ) . '</option>';
		}
		if ( $blue_id > 0 ) {
			echo '<option value="' . esc_attr( $blue_id ) . '" ' . selected( (int) ( $fight->winner_entry_id ?? 0 ), $blue_id, false ) . '>' . esc_html( 'Bleu — ' . $this->format_entry_label( $blue ) ) . '</option>';
		}
		echo '</select> ';
		echo '<select name="result_type" aria-label="' . esc_attr__( 'Méthode', 'ufsc-licence-competition' ) . '">';
		foreach ( $this->get_result_methods() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) ( $fight->result_method ?? '' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<input type="text" size="3" name="score_red" value="' . esc_attr( (string) ( $fight->score_red ?? '' ) ) . '" placeholder="R" />';
		echo '<input type="text" size="3" name="score_blue" value="' . esc_attr( (string) ( $fight->score_blue ?? '' ) ) . '" placeholder="B" />';
		echo '<input type="text" name="result_note" value="" placeholder="' . esc_attr__( 'Observation', 'ufsc-licence-competition' ) . '" />';
		echo '<input type="text" name="reason" value="" placeholder="' . esc_attr__( 'Motif si correction/no contest', 'ufsc-licence-competition' ) . '" ' . ( 'correct' === $mode ? 'required' : '' ) . ' />';
		submit_button( $button, 'secondary small', '', false );
		echo '</form>';
	}

	private function render_lock_form( $fight ): void {
		$fight_id = (int) ( $fight->id ?? 0 );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ufsc-inline-lock-form">';
		wp_nonce_field( 'ufsc_competitions_results_lock_' . $fight_id );
		echo '<input type="hidden" name="action" value="ufsc_competitions_results_lock" />';
		echo '<input type="hidden" name="fight_id" value="' . esc_attr( $fight_id ) . '" />';
		echo '<input type="hidden" name="competition_id" value="' . esc_attr( (int) ( $fight->competition_id ?? 0 ) ) . '" />';
		echo '<input type="text" name="reason" value="" placeholder="' . esc_attr__( 'Motif verrouillage', 'ufsc-licence-competition' ) . '" />';
		submit_button( __( 'Verrouiller', 'ufsc-licence-competition' ), 'secondary small', '', false );
		echo '</form>';
	}

	private function get_filtered_fights( int $competition_id, array $filters ): array {
		$fights = $this->fights->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );
		return array_values(
			array_filter(
				$fights,
				function ( $fight ) use ( $filters ) {
					if ( '' !== $filters['surface'] && false === stripos( $this->format_surface( $fight ), $filters['surface'] ) ) {
						return false;
					}
					if ( '' !== $filters['status'] && sanitize_key( (string) ( $fight->status ?? '' ) ) !== $filters['status'] ) {
						return false;
					}
					if ( $filters['category_id'] > 0 && absint( $fight->category_id ?? 0 ) !== $filters['category_id'] ) {
						return false;
					}
					if ( '' !== $filters['discipline'] && false === stripos( $this->format_discipline( $fight ), $filters['discipline'] ) ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	private function get_fight_for_post( int $fight_id ) {
		if ( $fight_id <= 0 ) {
			$this->redirect_to_results( 0, 'not_found' );
		}
		$fight = $this->fights->get( $fight_id, true );
		if ( ! $fight ) {
			$this->redirect_to_results( 0, 'not_found' );
		}
		$post_competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$competition_id = (int) ( $fight->competition_id ?? 0 );
		if ( $post_competition_id > 0 && $post_competition_id !== $competition_id ) {
			$this->redirect_to_results( $post_competition_id, 'result_blocked', __( 'Validation refusée : le combat ne correspond pas à la compétition sélectionnée.', 'ufsc-licence-competition' ) );
		}
		if ( function_exists( 'ufsc_lc_enforce_competition_access' ) ) {
			ufsc_lc_enforce_competition_access( $competition_id );
		}
		if ( $this->is_bye_or_placeholder( $fight ) ) {
			$this->redirect_to_results( $competition_id, 'result_blocked', __( 'Saisie refusée : BYE, placeholder ou combat supprimé ne se saisit pas comme un combat réel.', 'ufsc-licence-competition' ) );
		}
		return $fight;
	}

	private function read_filters_from_request(): array {
		return array(
			'surface' => isset( $_GET['surface'] ) ? sanitize_text_field( wp_unslash( $_GET['surface'] ) ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'category_id' => isset( $_GET['category_id'] ) ? absint( $_GET['category_id'] ) : 0,
			'discipline' => isset( $_GET['discipline'] ) ? sanitize_text_field( wp_unslash( $_GET['discipline'] ) ) : '',
		);
	}

	private function get_competitions_for_select(): array {
		$filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$filters = ufsc_lc_competitions_apply_scope_to_query_args( $filters );
		}
		return $this->competitions->list( $filters, 200, 0 );
	}

	private function get_result_methods(): array {
		return array(
			'points' => __( 'Décision / points', 'ufsc-licence-competition' ),
			'forfait' => __( 'Forfait', 'ufsc-licence-competition' ),
			'abandon' => __( 'Abandon', 'ufsc-licence-competition' ),
			'disqualification' => __( 'Disqualification', 'ufsc-licence-competition' ),
			'arret_arbitre' => __( 'Arrêt arbitre / KO-TKO', 'ufsc-licence-competition' ),
			'absence' => __( 'Absence', 'ufsc-licence-competition' ),
			'no_contest' => __( 'No contest / sans vainqueur', 'ufsc-licence-competition' ),
			'litige' => __( 'Litige', 'ufsc-licence-competition' ),
			'annule' => __( 'Annulé', 'ufsc-licence-competition' ),
		);
	}

	private function is_bye_or_placeholder( $fight ): bool {
		$status = $this->fights->get_effective_fight_status( $fight );
		$case_type = sanitize_key( (string) ( $fight->case_type ?? $fight->type ?? '' ) );
		return in_array( $status, array( FightRepository::STATUS_BYE, FightRepository::STATUS_PLACEHOLDER, FightRepository::STATUS_TRASHED ), true ) || in_array( $case_type, array( 'bye', 'placeholder' ), true );
	}

	private function fight_has_result_payload( $fight ): bool {
		return absint( $fight->winner_entry_id ?? 0 ) > 0 || '' !== trim( (string) ( $fight->result_method ?? '' ) ) || '' !== trim( (string) ( $fight->score_red ?? '' ) ) || '' !== trim( (string) ( $fight->score_blue ?? '' ) );
	}

	private function format_order( $fight ): string {
		$order = absint( $fight->scheduled_order ?? 0 );
		return $order > 0 ? (string) $order : ( absint( $fight->fight_no ?? 0 ) > 0 ? '#' . absint( $fight->fight_no ?? 0 ) : '—' );
	}

	private function format_surface( $fight ): string {
		foreach ( array( 'surface_name', 'surface', 'ring', 'tatami', 'area', 'scheduled_surface' ) as $key ) {
			$value = trim( (string) ( $fight->{$key} ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return __( 'Surface non assignée', 'ufsc-licence-competition' );
	}

	private function format_time( $fight ): string {
		$value = trim( (string) ( $fight->scheduled_time ?? $fight->scheduled_at ?? '' ) );
		if ( '' === $value ) {
			return '—';
		}
		$timestamp = strtotime( $value );
		return $timestamp ? wp_date( 'H:i', $timestamp ) : $value;
	}

	private function format_discipline( $fight, $red = null, $blue = null ): string {
		foreach ( array( $fight, $red, $blue ) as $source ) {
			if ( ! $source ) {
				continue;
			}
			$value = trim( (string) ( $source->discipline ?? $source->category_discipline ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '—';
	}

	private function format_category( $fight, array $category_map ): string {
		$category_id = absint( $fight->category_id ?? 0 );
		$parts = array();
		if ( $category_id > 0 && isset( $category_map[ $category_id ] ) ) {
			$parts[] = $category_map[ $category_id ];
		}
		foreach ( array( 'category_name', 'weight_class', 'level', 'class' ) as $key ) {
			$value = trim( (string) ( $fight->{$key} ?? '' ) );
			if ( '' !== $value && ! in_array( $value, $parts, true ) ) {
				$parts[] = $value;
			}
		}
		return $parts ? implode( ' · ', $parts ) : '—';
	}

	private function format_entry_label( $entry ): string {
		if ( ! $entry ) {
			return '—';
		}
		$name = trim( (string) ( $entry->licensee_last_name ?? $entry->last_name ?? $entry->lastname ?? '' ) . ' ' . (string) ( $entry->licensee_first_name ?? $entry->first_name ?? $entry->firstname ?? '' ) );
		return '' !== $name ? $name : '#' . (int) ( $entry->id ?? 0 );
	}

	private function format_entry_club( $entry ): string {
		return $entry ? ( '' !== trim( (string) ( $entry->club_name ?? '' ) ) ? (string) $entry->club_name : '—' ) : '—';
	}

	private function format_status_label( string $status, $fight ): string {
		$label = $this->fights->get_status_label( $status );
		$case_type = sanitize_key( (string) ( $fight->case_type ?? $fight->type ?? '' ) );
		if ( in_array( $case_type, array( 'bye', 'placeholder' ), true ) ) {
			$label .= ' · ' . strtoupper( $case_type );
		}
		return $label;
	}

	private function format_result_label( $fight, array $entry_map ): string {
		if ( ! $this->fight_has_result_payload( $fight ) ) {
			return '—';
		}
		$winner_id = absint( $fight->winner_entry_id ?? 0 );
		$winner = $winner_id > 0 ? $this->format_entry_label( $entry_map[ $winner_id ] ?? null ) : __( 'Sans vainqueur', 'ufsc-licence-competition' );
		$method = trim( (string) ( $fight->result_method ?? '' ) );
		$scores = trim( (string) ( $fight->score_red ?? '' ) . ' - ' . (string) ( $fight->score_blue ?? '' ), ' -' );
		return trim( $winner . ( '' !== $method ? ' · ' . $method : '' ) . ( '' !== $scores ? ' · ' . $scores : '' ) );
	}

	private function get_admin_error_message( string $error, string $fallback = '' ): string {
		$messages = array(
			'unsupported_fight_status' => __( 'Saisie refusée : ce statut de combat ne permet pas un résultat classique.', 'ufsc-licence-competition' ),
			'locked_result_cannot_be_modified_directly' => __( 'Saisie refusée : le résultat est verrouillé.', 'ufsc-licence-competition' ),
			'completed_requires_correction_flow' => __( 'Saisie refusée : utilisez la correction contrôlée pour un combat déjà terminé.', 'ufsc-licence-competition' ),
			'invalid_result_type' => __( 'Saisie refusée : méthode de résultat invalide.', 'ufsc-licence-competition' ),
			'winner_required' => __( 'Saisie refusée : choisissez le vainqueur rouge ou bleu.', 'ufsc-licence-competition' ),
			'winner_mismatch' => __( 'Saisie refusée : le vainqueur doit être rouge ou bleu.', 'ufsc-licence-competition' ),
			'reason_required' => __( 'Saisie refusée : un motif est obligatoire pour cette action.', 'ufsc-licence-competition' ),
			'invalid_score_score_red' => __( 'Saisie refusée : score rouge invalide.', 'ufsc-licence-competition' ),
			'invalid_score_score_blue' => __( 'Saisie refusée : score bleu invalide.', 'ufsc-licence-competition' ),
			'not_completed' => __( 'Verrouillage refusé : le combat doit être terminé.', 'ufsc-licence-competition' ),
		);
		return $messages[ $error ] ?? ( '' !== $fallback ? $fallback : __( 'Action résultat refusée.', 'ufsc-licence-competition' ) );
	}

	private function render_notice( string $notice, string $message ): void {
		if ( '' === $notice ) {
			return;
		}
		$type = in_array( $notice, array( 'result_recorded', 'result_corrected', 'result_locked' ), true ) ? 'success' : 'error';
		$default_messages = array(
			'result_recorded' => __( 'Résultat saisi.', 'ufsc-licence-competition' ),
			'result_corrected' => __( 'Résultat corrigé.', 'ufsc-licence-competition' ),
			'result_locked' => __( 'Résultat verrouillé.', 'ufsc-licence-competition' ),
			'not_found' => __( 'Combat introuvable.', 'ufsc-licence-competition' ),
			'result_blocked' => __( 'Action résultat refusée.', 'ufsc-licence-competition' ),
			'lock_blocked' => __( 'Verrouillage refusé.', 'ufsc-licence-competition' ),
		);
		$text = '' !== $message ? $message : ( $default_messages[ $notice ] ?? __( 'Action traitée.', 'ufsc-licence-competition' ) );
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( $text ) . '</p></div>';
	}

	private function redirect_to_results( int $competition_id, string $notice, string $message = '' ): void {
		$url = add_query_arg(
			array_filter(
				array(
					'page' => Menu::PAGE_RESULTS,
					'competition_id' => $competition_id > 0 ? $competition_id : null,
					'ufsc_notice' => $notice,
					'ufsc_message' => '' !== $message ? rawurlencode( $message ) : null,
				),
				static function ( $value ) {
					return null !== $value;
				}
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
