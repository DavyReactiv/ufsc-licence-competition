<?php

namespace UFSC\Competitions\Front;

use UFSC\Competitions\Access\AccessResult;
use UFSC\Competitions\Access\CompetitionAccess;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Front\Entries\EntriesModule;
use UFSC\Competitions\Front\Entries\EntryFormRenderer;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;
use UFSC\Competitions\Repositories\ClubRepository;
use UFSC\Competitions\Services\CompetitionFilters;
use UFSC\Competitions\Services\RegistrationWindowService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Club-facing competition workspace.
 *
 * Reuses the existing entry renderer and repositories. This class only adds
 * orchestration, deadline visibility and configurable compliance reminders.
 */
class ClubCompetitionPortal {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'ufsc_competitions_front_registration_box', array( __CLASS__, 'render_dashboard' ), 7, 1 );
		add_action( 'ufsc_competitions_front_registration_box', array( __CLASS__, 'render_read_only_workspace' ), 9, 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ), 35 );
	}

	public static function enqueue_styles(): void {
		if ( is_admin() ) {
			return;
		}

		$file = UFSC_LC_DIR . 'includes/competitions/assets/club-competition-portal.css';
		if ( ! file_exists( $file ) ) {
			return;
		}

		$mtime = filemtime( $file );
		wp_enqueue_style(
			'ufsc-club-competition-portal',
			UFSC_LC_URL . 'includes/competitions/assets/club-competition-portal.css',
			array( 'ufsc-competitions-premium-front' ),
			false !== $mtime ? (string) $mtime : '1.0.0'
		);
	}

	public static function render_dashboard( $competition ): void {
		if ( ! is_object( $competition ) || ! is_user_logged_in() ) {
			return;
		}

		$competition_id = absint( $competition->id ?? 0 );
		$user_id        = absint( get_current_user_id() );
		$club_id        = function_exists( 'ufsc_lc_get_current_club_id' ) ? absint( ufsc_lc_get_current_club_id( $user_id ) ) : 0;
		if ( ! $competition_id || ! $club_id ) {
			return;
		}

		$access      = new CompetitionAccess();
		$view_result = $access->can_view_competition( $competition_id, $club_id, $user_id );
		if ( ! $view_result->allowed && ! $view_result->can_view_details ) {
			return;
		}

		$register_result = $access->can_register( $competition_id, $club_id, $user_id );
		$window          = RegistrationWindowService::evaluate( $competition );
		$repo            = new EntryFrontRepository();
		$entries         = $repo->list_by_competition_and_club( $competition_id, $club_id );
		$counts          = self::count_statuses( $entries, $repo );
		$checklist       = self::build_checklist( $competition );
		$details_url     = Front::get_competition_details_url( $competition_id );
		$base_url        = $details_url ? $details_url : home_url( '/' );
		$entries_url     = $details_url ? $details_url . '#ufsc-inscriptions' : '#ufsc-inscriptions';
		$engaged_url     = add_query_arg(
			array(
				'ufsc_engaged_view'   => 1,
				'ufsc_engaged_status' => 'approved',
			),
			$base_url
		) . '#ufsc-engaged-list';

		$is_mutable = $register_result->allowed && ! empty( $window['is_open'] );
		?>
		<section class="ufsc-club-event-portal <?php echo esc_attr( $is_mutable ? 'is-open' : 'is-readonly' ); ?>" aria-label="<?php esc_attr_e( 'Espace compétition du club', 'ufsc-licence-competition' ); ?>">
			<div class="ufsc-club-event-portal__hero">
				<div>
					<p class="ufsc-club-event-portal__eyebrow"><?php esc_html_e( 'Espace club · Compétition', 'ufsc-licence-competition' ); ?></p>
					<h3><?php esc_html_e( 'Préparez vos engagés sans erreur', 'ufsc-licence-competition' ); ?></h3>
					<p><?php echo esc_html( (string) $window['message'] ); ?></p>
				</div>
				<span class="ufsc-club-event-portal__state">
					<?php echo $is_mutable ? esc_html__( 'Modifications autorisées', 'ufsc-licence-competition' ) : esc_html__( 'Consultation uniquement', 'ufsc-licence-competition' ); ?>
				</span>
			</div>

			<div class="ufsc-club-event-portal__stats" aria-label="<?php esc_attr_e( 'Résumé des inscriptions du club', 'ufsc-licence-competition' ); ?>">
				<div><span><?php esc_html_e( 'Total', 'ufsc-licence-competition' ); ?></span><strong><?php echo esc_html( (string) $counts['total'] ); ?></strong></div>
				<div><span><?php esc_html_e( 'Brouillons', 'ufsc-licence-competition' ); ?></span><strong><?php echo esc_html( (string) $counts['draft'] ); ?></strong></div>
				<div><span><?php esc_html_e( 'En validation', 'ufsc-licence-competition' ); ?></span><strong><?php echo esc_html( (string) $counts['review'] ); ?></strong></div>
				<div><span><?php esc_html_e( 'Approuvées', 'ufsc-licence-competition' ); ?></span><strong><?php echo esc_html( (string) $counts['approved'] ); ?></strong></div>
			</div>

			<div class="ufsc-club-event-portal__actions">
				<a class="button button-primary" href="<?php echo esc_url( $entries_url ); ?>"><?php echo $is_mutable ? esc_html__( 'Gérer mes inscriptions', 'ufsc-licence-competition' ) : esc_html__( 'Consulter mes inscriptions', 'ufsc-licence-competition' ); ?></a>
				<a class="button" href="<?php echo esc_url( $engaged_url ); ?>"><?php esc_html_e( 'Voir la liste des engagés', 'ufsc-licence-competition' ); ?></a>
			</div>

			<div class="ufsc-club-event-portal__grid">
				<div class="ufsc-club-event-portal__panel">
					<h4><?php esc_html_e( 'Modification avant validation', 'ufsc-licence-competition' ); ?></h4>
					<p><?php esc_html_e( 'Tant que l’inscription n’est pas approuvée et que la forclusion n’est pas dépassée, le club peut retirer une inscription soumise, la corriger puis la soumettre à nouveau.', 'ufsc-licence-competition' ); ?></p>
					<?php if ( ! empty( $window['closes_at'] ) ) : ?>
						<p class="ufsc-club-event-portal__deadline"><strong><?php esc_html_e( 'Forclusion :', 'ufsc-licence-competition' ); ?></strong> <?php echo esc_html( self::format_sql_datetime( (string) $window['closes_at'] ) ); ?></p>
					<?php endif; ?>
				</div>

				<div class="ufsc-club-event-portal__panel">
					<h4><?php esc_html_e( 'Checklist avant soumission', 'ufsc-licence-competition' ); ?></h4>
					<ul class="ufsc-club-event-checklist">
						<?php foreach ( $checklist as $item ) : ?>
							<li><span aria-hidden="true">✓</span><span><?php echo esc_html( $item ); ?></span></li>
						<?php endforeach; ?>
					</ul>
					<p class="ufsc-club-event-portal__legal-note"><?php esc_html_e( 'Ces rappels facilitent le contrôle du dossier. Les pièces réellement exigées restent celles prévues par le règlement et les consignes de l’événement.', 'ufsc-licence-competition' ); ?></p>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Keep club data visible after forclusion while reusing the existing entry
	 * renderer in read-only mode. No second registration/list implementation.
	 */
	public static function render_read_only_workspace( $competition ): void {
		if ( ! is_object( $competition ) || ! is_user_logged_in() ) {
			return;
		}

		$competition_id = absint( $competition->id ?? 0 );
		$user_id        = absint( get_current_user_id() );
		$club_id        = function_exists( 'ufsc_lc_get_current_club_id' ) ? absint( ufsc_lc_get_current_club_id( $user_id ) ) : 0;
		if ( ! $competition_id || ! $club_id ) {
			return;
		}

		$access          = new CompetitionAccess();
		$view_result     = $access->can_view_competition( $competition_id, $club_id, $user_id );
		$register_result = $access->can_register( $competition_id, $club_id, $user_id );
		if ( ! $view_result->allowed || $register_result->allowed || 'registration_closed' !== $register_result->reason_code ) {
			return;
		}

		remove_action( 'ufsc_competitions_front_registration_box', array( EntriesModule::class, 'render' ), 10 );

		$repo       = new EntryFrontRepository();
		$entries    = $repo->list_by_competition_and_club( $competition_id, $club_id );
		$club_repo  = new ClubRepository();
		$club       = $club_repo->get( $club_id );
		$club_label = $club_repo->get_region_label( $club );

		$read_access = AccessResult::allow(
			array(
				'scope'   => 'engaged_list',
				'club_id' => $club_id,
			),
			array(
				'can_view_details'      => true,
				'can_view_engaged_list' => true,
				'can_view_engaged'      => true,
				'can_register'          => false,
				'can_export_engaged'    => true,
			)
		);

		$workspace_html = EntryFormRenderer::render(
			array(
				'competition'       => $competition,
				'club_id'           => $club_id,
				'entries'           => $entries,
				'editing_entry'     => null,
				'club_label'        => $club_label,
				'registration_open' => false,
				'access_result'     => $read_access,
				'license_results'   => array(),
				'selected_license'  => null,
				'license_term'      => '',
				'license_number'    => '',
				'license_birthdate' => '',
				'return_url'        => Front::get_competition_details_url( $competition_id ),
				'license_id'        => 0,
				'prefill'           => array(),
				'entry_repo'        => $repo,
			)
		);

		echo $workspace_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EntryFormRenderer escapes user-controlled fields before returning trusted module markup.
	}

	private static function count_statuses( array $entries, EntryFrontRepository $repo ): array {
		$counts = array(
			'total'    => count( $entries ),
			'draft'    => 0,
			'review'   => 0,
			'approved' => 0,
		);

		foreach ( $entries as $entry ) {
			$status = EntriesWorkflow::normalize_status( (string) $repo->get_entry_status( $entry ) );
			if ( 'draft' === $status ) {
				++$counts['draft'];
			} elseif ( 'approved' === $status ) {
				++$counts['approved'];
			} elseif ( in_array( $status, array( 'submitted', 'pending', 'review_queue' ), true ) ) {
				++$counts['review'];
			}
		}

		return $counts;
	}

	private static function build_checklist( $competition ): array {
		$type = CompetitionFilters::normalize_type_key( (string) ( $competition->type ?? '' ) );
		$federal_event_types = array(
			'competition',
			'tournoi',
			'coupe',
			'open',
			'championnat_regional',
			'selection_regionale',
			'championnat_national',
		);

		$items = array(
			__( 'Identité, date de naissance et licence du combattant cohérentes.', 'ufsc-licence-competition' ),
			__( 'Poids, catégorie, niveau/classe et discipline vérifiés avant soumission.', 'ufsc-licence-competition' ),
			__( 'Vérifier les justificatifs médicaux exigés par le règlement applicable et leur validité.', 'ufsc-licence-competition' ),
			__( 'Pour un mineur, vérifier l’autorisation du représentant légal et les pièces demandées par l’événement.', 'ufsc-licence-competition' ),
		);

		if ( in_array( $type, $federal_event_types, true ) ) {
			$items[] = __( 'Pensez à vérifier le passeport sportif lorsqu’il est requis pour cette compétition fédérale.', 'ufsc-licence-competition' );
		}

		return (array) apply_filters( 'ufsc_competitions_club_submission_checklist', $items, $competition );
	}

	private static function format_sql_datetime( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '—';
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$date     = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $timezone );
		if ( ! $date instanceof \DateTimeImmutable ) {
			return $value;
		}

		if ( function_exists( 'wp_date' ) ) {
			return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date->getTimestamp(), $timezone );
		}

		return $date->format( 'd/m/Y H:i' );
	}
}
