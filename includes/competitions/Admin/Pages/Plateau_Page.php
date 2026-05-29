<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plateau_Page {
	private $fights;
	private $competitions;
	private $entries;
	private $logger;

	public function __construct() {
		$this->fights = new FightRepository();
		$this->competitions = new CompetitionRepository();
		$this->entries = new EntryRepository();
		$this->logger = new LogService();
	}

	public function register_actions(): void {
		add_action( 'admin_post_ufsc_competitions_plateau_update_status', array( $this, 'handle_update_status' ) );
		add_action( 'admin_post_ufsc_competitions_plateau_change_surface', array( $this, 'handle_change_surface' ) );
	}

	public function render(): void {
		if ( ! $this->can_manage_plateau() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$surface_filter = isset( $_GET['surface'] ) ? sanitize_text_field( wp_unslash( $_GET['surface'] ) ) : '';
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$message = isset( $_GET['ufsc_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['ufsc_message'] ) ) ) : '';

		$competitions = $this->competitions->list( array( 'view' => 'all' ), 300, 0 );
		if ( ! $competition_id && ! empty( $competitions ) ) {
			$competition_id = (int) $competitions[0]->id;
		}

		$fights = array();
		if ( $competition_id > 0 ) {
			$filters = array( 'view' => 'all', 'competition_id' => $competition_id );
			if ( '' !== $status_filter ) {
				$filters['status'] = $status_filter;
			}
			$fights = $this->fights->list( $filters, 5000, 0 );
		}

		$fights = $this->filter_fights( $fights, $surface_filter, $status_filter );
		$entry_map = $this->build_entry_map( $fights );
		$grouped = $this->group_by_surface( $fights );
		$counts = $this->compute_counts( $fights );
		$surface_options = array_keys( $grouped );
		sort( $surface_options );

		$this->render_notice( $notice, $message );
		?>
		<div class="wrap ufsc-competitions-admin">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Gestion live', 'ufsc-licence-competition' ); ?></p>
					<h1><?php esc_html_e( 'Plateau jour J', 'ufsc-licence-competition' ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Pilotage des combats par surface avec actions rapides sécurisées.', 'ufsc-licence-competition' ); ?></p>
				</div>
			</header>
			<section class="ufsc-kpis ufsc-kpis--premium">
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'À venir', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $counts['scheduled'] + $counts['called'] + $counts['delayed'] ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'En cours', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $counts['running'] ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Terminés', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $counts['completed'] ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Litiges', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $counts['disputed'] ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Absences', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $counts['absent'] ) ); ?></strong></article>
			</section>

			<form method="get" class="ufsc-competitions-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_PLATEAU ); ?>" />
				<label>
					<?php esc_html_e( 'Compétition', 'ufsc-licence-competition' ); ?>
					<select name="competition_id">
						<?php foreach ( $competitions as $competition ) : ?>
							<option value="<?php echo esc_attr( (int) $competition->id ); ?>" <?php selected( $competition_id, (int) $competition->id ); ?>><?php echo esc_html( $competition->name ?: ( '#' . (int) $competition->id ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Surface', 'ufsc-licence-competition' ); ?>
					<select name="surface">
						<option value=""><?php esc_html_e( 'Toutes', 'ufsc-licence-competition' ); ?></option>
						<?php foreach ( $surface_options as $surface ) : ?>
							<option value="<?php echo esc_attr( $surface ); ?>" <?php selected( $surface_filter, $surface ); ?>><?php echo esc_html( $surface ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?>
					<select name="status">
						<option value=""><?php esc_html_e( 'Tous', 'ufsc-licence-competition' ); ?></option>
						<?php foreach ( $this->fights->get_allowed_statuses() as $status ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $status_filter, $status ); ?>><?php echo esc_html( $this->fights->get_status_label( $status ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Filtrer', 'ufsc-licence-competition' ); ?></button>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_PLATEAU, 'competition_id' => $competition_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Actualiser', 'ufsc-licence-competition' ); ?></a>
			</form>
			<p class="description"><?php esc_html_e( 'En cas d’échec, le système bloque les actions incohérentes (BYE/placeholders/combats verrouillés).', 'ufsc-licence-competition' ); ?></p>

			<div class="ufsc-plateau-grid">
			<?php foreach ( $grouped as $surface_name => $surface_fights ) : ?>
				<section class="ufsc-plateau-surface-card">
					<h2><?php echo esc_html( $surface_name ); ?></h2>
					<?php foreach ( $surface_fights as $fight ) : ?>
						<?php $this->render_fight_card( $competition_id, $fight, $entry_map, $surface_options ); ?>
					<?php endforeach; ?>
				</section>
			<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	public function handle_update_status(): void {
		if ( ! $this->can_manage_plateau() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
		$fight_id = isset( $_POST['fight_id'] ) ? absint( $_POST['fight_id'] ) : 0;
		check_admin_referer( 'ufsc_plateau_update_status_' . $fight_id );
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		if ( function_exists( 'ufsc_lc_enforce_competition_access' ) ) {
			ufsc_lc_enforce_competition_access( $competition_id );
		}
		$new_status = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$this->process_status_transition( $competition_id, $fight_id, $new_status, $reason );
	}

	public function handle_change_surface(): void {
		if ( ! $this->can_manage_plateau() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
		$fight_id = isset( $_POST['fight_id'] ) ? absint( $_POST['fight_id'] ) : 0;
		check_admin_referer( 'ufsc_plateau_change_surface_' . $fight_id );
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		if ( function_exists( 'ufsc_lc_enforce_competition_access' ) ) {
			ufsc_lc_enforce_competition_access( $competition_id );
		}
		$new_surface = isset( $_POST['new_surface'] ) ? sanitize_text_field( wp_unslash( $_POST['new_surface'] ) ) : '';
		$new_surface = trim( $new_surface );
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( '' === $new_surface ) {
			$this->log_blocked( $competition_id, $fight_id, 'surface_change_invalid_target', '', '' );
			$this->redirect( $competition_id, 'blocked', __( 'Surface cible invalide.', 'ufsc-licence-competition' ) );
		}

		$fight = $this->fights->get( $fight_id, true );
		if ( ! $fight || (int) $fight->competition_id !== $competition_id ) {
			$this->redirect( $competition_id, 'invalid_scope' );
		}
		$status = $this->fights->get_effective_fight_status( $fight );
		if ( in_array( $status, array( FightRepository::STATUS_COMPLETED, FightRepository::STATUS_LOCKED, FightRepository::STATUS_TRASHED ), true ) ) {
			$this->log_blocked( $competition_id, $fight_id, 'surface_change_blocked_status', $status, '' );
			$this->redirect( $competition_id, 'blocked', __( 'Combat verrouillé/terminé: déplacement interdit.', 'ufsc-licence-competition' ) );
		}
		$old_surface = (string) ( $fight->ring ?? '' );
		$this->fights->update( $fight_id, array( 'ring' => $new_surface ) );
		$this->logger->audit( 'plateau_surface_changed', $competition_id, 'fight', $fight_id, array(
			'old_status' => $status,
			'new_status' => $status,
			'old_surface' => $old_surface,
			'new_surface' => $new_surface,
			'reason' => $reason,
		) );
		$this->redirect( $competition_id, 'surface_updated' );
	}

	private function process_status_transition( int $competition_id, int $fight_id, string $new_status, string $reason ): void {
		$fight = $this->fights->get( $fight_id, true );
		if ( ! $fight || (int) $fight->competition_id !== $competition_id ) {
			$this->redirect( $competition_id, 'invalid_scope' );
		}

		$current = $this->fights->get_effective_fight_status( $fight );
		if ( ! $this->is_plateau_transition_allowed( $fight, $current, $new_status ) ) {
			$this->log_blocked( $competition_id, $fight_id, 'status_transition_blocked', $current, $new_status );
			$this->redirect( $competition_id, 'blocked', __( 'Transition de statut non autorisée pour ce combat.', 'ufsc-licence-competition' ) );
		}

		$this->fights->update( $fight_id, array( 'status' => $new_status ) );
		$this->logger->audit( 'plateau_fight_' . $new_status, $competition_id, 'fight', $fight_id, array(
			'old_status' => $current,
			'new_status' => $new_status,
			'old_surface' => (string) ( $fight->ring ?? '' ),
			'new_surface' => (string) ( $fight->ring ?? '' ),
			'reason' => $reason,
		) );
		$this->redirect( $competition_id, 'status_updated' );
	}

	private function is_plateau_transition_allowed( $fight, string $current, string $new ): bool {
		if ( in_array( $current, array( FightRepository::STATUS_TRASHED, FightRepository::STATUS_LOCKED ), true ) ) {
			return false;
		}
		if ( in_array( $current, array( FightRepository::STATUS_BYE, FightRepository::STATUS_PLACEHOLDER ), true ) ) {
			return in_array( $new, array( FightRepository::STATUS_CANCELLED ), true );
		}
		if ( FightRepository::STATUS_COMPLETED === $current && ! Capabilities::user_can_correct_results() ) {
			return false;
		}
		if ( FightRepository::STATUS_CANCELLED === $current && FightRepository::STATUS_COMPLETED === $new && ! Capabilities::user_can_correct_results() ) {
			return false;
		}

		$allowed = array(
			FightRepository::STATUS_CALLED,
			FightRepository::STATUS_RUNNING,
			FightRepository::STATUS_COMPLETED,
			FightRepository::STATUS_DELAYED,
			FightRepository::STATUS_ABSENT,
			FightRepository::STATUS_DISPUTED,
			FightRepository::STATUS_CANCELLED,
			FightRepository::STATUS_SCHEDULED,
		);
		if ( ! in_array( $new, $allowed, true ) ) {
			return false;
		}

		$fight->status = $current;
		return $this->fights->can_transition_status( $fight, $new );
	}

	private function can_manage_plateau(): bool {
		return Capabilities::current_user_can( Capabilities::PLATEAU_MANAGE_CAPABILITY ) || Capabilities::user_can_manage_fights();
	}

	private function build_entry_map( array $fights ): array {
		$entry_ids = array();
		foreach ( $fights as $fight ) {
			$entry_ids[] = absint( $fight->red_entry_id ?? 0 );
			$entry_ids[] = absint( $fight->blue_entry_id ?? 0 );
		}
		$entry_ids = array_values( array_filter( array_unique( $entry_ids ) ) );
		if ( empty( $entry_ids ) ) {
			return array();
		}
		$rows = $this->entries->list_with_details( array( 'entry_ids' => $entry_ids, 'include_deleted' => true ), 3000, 0 );
		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->id ] = $row;
		}
		return $map;
	}

	private function filter_fights( array $fights, string $surface_filter, string $status_filter ): array {
		return array_values( array_filter( $fights, static function ( $fight ) use ( $surface_filter, $status_filter ) {
			$status = sanitize_key( (string) ( $fight->status ?? '' ) );
			$surface = trim( (string) ( $fight->ring ?? '' ) );
			if ( '' !== $surface_filter && $surface_filter !== $surface ) {
				return false;
			}
			if ( '' !== $status_filter && $status_filter !== $status ) {
				return false;
			}
			return true;
		} ) );
	}

	private function group_by_surface( array $fights ): array {
		$grouped = array();
		usort( $fights, static function ( $a, $b ) {
			return (int) ( $a->fight_no ?? 0 ) <=> (int) ( $b->fight_no ?? 0 );
		} );
		foreach ( $fights as $fight ) {
			$surface = trim( (string) ( $fight->ring ?? '' ) );
			if ( '' === $surface ) {
				$surface = __( 'Surface libre', 'ufsc-licence-competition' );
			}
			if ( ! isset( $grouped[ $surface ] ) ) {
				$grouped[ $surface ] = array();
			}
			$grouped[ $surface ][] = $fight;
		}
		if ( empty( $grouped ) ) {
			$grouped[ __( 'Aucune surface', 'ufsc-licence-competition' ) ] = array();
		}
		return $grouped;
	}

	private function compute_counts( array $fights ): array {
		$keys = array( 'scheduled', 'called', 'running', 'completed', 'disputed', 'absent', 'delayed' );
		$counts = array_fill_keys( $keys, 0 );
		foreach ( $fights as $fight ) {
			$status = sanitize_key( (string) ( $fight->status ?? '' ) );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
		}
		return $counts;
	}

	private function render_fight_card( int $competition_id, $fight, array $entry_map, array $surface_options ): void {
		$status = $this->fights->get_effective_fight_status( $fight );
		$red = $this->entry_label( absint( $fight->red_entry_id ?? 0 ), $entry_map );
		$blue = $this->entry_label( absint( $fight->blue_entry_id ?? 0 ), $entry_map );
		?>
		<article class="ufsc-plateau-fight-card">
			<header>
				<strong>#<?php echo esc_html( (string) ( $fight->fight_no ?? 0 ) ); ?></strong>
				<span class="ufsc-badge <?php echo esc_attr( $this->fights->get_status_badge_class( $status ) ); ?>"><?php echo esc_html( $this->fights->get_status_label( $status ) ); ?></span>
			</header>
			<p><strong><?php esc_html_e( 'Rouge', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( $red ); ?></p>
			<p><strong><?php esc_html_e( 'Bleu', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( $blue ); ?></p>
			<p><strong><?php esc_html_e( 'Phase', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( (string) ( $fight->phase ?? '-' ) ); ?> / R<?php echo esc_html( (string) ( $fight->round_no ?? 0 ) ); ?></p>
			<div class="ufsc-plateau-actions">
				<?php foreach ( array( FightRepository::STATUS_CALLED, FightRepository::STATUS_RUNNING, FightRepository::STATUS_COMPLETED, FightRepository::STATUS_DELAYED, FightRepository::STATUS_ABSENT, FightRepository::STATUS_DISPUTED, FightRepository::STATUS_CANCELLED ) as $target_status ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ufsc_competitions_plateau_update_status" />
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>" />
						<input type="hidden" name="fight_id" value="<?php echo esc_attr( (int) $fight->id ); ?>" />
						<input type="hidden" name="new_status" value="<?php echo esc_attr( $target_status ); ?>" />
						<?php wp_nonce_field( 'ufsc_plateau_update_status_' . (int) $fight->id ); ?>
						<button type="submit" class="button button-small"><?php echo esc_html( $this->fights->get_status_label( $target_status ) ); ?></button>
					</form>
				<?php endforeach; ?>
			</div>
			<p><a class="button button-link" href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_BOUTS, 'ufsc_action' => 'edit', 'id' => (int) $fight->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Voir détail', 'ufsc-licence-competition' ); ?></a></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-plateau-surface-form">
				<input type="hidden" name="action" value="ufsc_competitions_plateau_change_surface" />
				<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>" />
				<input type="hidden" name="fight_id" value="<?php echo esc_attr( (int) $fight->id ); ?>" />
				<?php wp_nonce_field( 'ufsc_plateau_change_surface_' . (int) $fight->id ); ?>
				<select name="new_surface">
					<option value=""><?php esc_html_e( 'Surface libre', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $surface_options as $surface_option ) : ?>
						<option value="<?php echo esc_attr( $surface_option ); ?>"><?php echo esc_html( $surface_option ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Changer surface', 'ufsc-licence-competition' ); ?></button>
			</form>
		</article>
		<?php
	}

	private function entry_label( int $entry_id, array $entry_map ): string {
		if ( $entry_id <= 0 ) {
			return __( 'N/A', 'ufsc-licence-competition' );
		}
		if ( ! isset( $entry_map[ $entry_id ] ) ) {
			return '#' . $entry_id;
		}
		$row = $entry_map[ $entry_id ];
		$name = trim( (string) ( $row->fighter_name ?? ( $row->participant_name ?? '' ) ) );
		$club = trim( (string) ( $row->club_name ?? '' ) );
		if ( '' !== $club ) {
			return trim( $name . ' (' . $club . ')' );
		}
		return '' !== $name ? $name : ( '#' . $entry_id );
	}

	private function render_notice( string $notice, string $message ): void {
		if ( '' === $notice && '' === $message ) {
			return;
		}
		$class = in_array( $notice, array( 'blocked', 'invalid_scope' ), true ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $message ?: $notice ) . '</p></div>';
	}

	private function log_blocked( int $competition_id, int $fight_id, string $reason, string $old_status, string $new_status ): void {
		$this->logger->audit( 'plateau_action_blocked', $competition_id, 'fight', $fight_id, array(
			'reason' => $reason,
			'old_status' => $old_status,
			'new_status' => $new_status,
		) );
	}

	private function redirect( int $competition_id, string $notice, string $message = '' ): void {
		$args = array(
			'page' => Menu::PAGE_PLATEAU,
			'competition_id' => $competition_id,
			'ufsc_notice' => $notice,
		);
		if ( '' !== $message ) {
			$args['ufsc_message'] = rawurlencode( $message );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
