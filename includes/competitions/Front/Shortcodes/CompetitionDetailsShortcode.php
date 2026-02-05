<?php

namespace UFSC\Competitions\Front\Shortcodes;

use UFSC\Competitions\Access\CompetitionAccess;
use UFSC\Competitions\Front\Front;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionDetailsShortcode {
	public function register(): void {
		add_shortcode( 'ufsc_competition', array( $this, 'render' ) );
	}

	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'id'            => '',
				'require_login' => 1,
				'require_club'  => 0,
			),
			$atts,
			'ufsc_competition'
		);

		$id = absint( $atts['id'] );
		if ( ! $id ) {
			$request_id = Front::get_competition_id_from_request();
			$id = $request_id ? $request_id : 0;
		}

		if ( ! $id ) {
			return $this->render_notice( esc_html__( 'Aucune compétition sélectionnée.', 'ufsc-licence-competition' ) );
		}

		$repository = new CompetitionReadRepository();
		$competition = $repository->get( $id );

		if ( ! $competition ) {
			return $this->render_notice( esc_html__( 'Compétition introuvable.', 'ufsc-licence-competition' ) );
		}

		$access = new CompetitionAccess();
		$user_id = is_user_logged_in() ? (int) get_current_user_id() : 0;
		$club_id = function_exists( 'ufsc_get_current_club_id' ) ? (int) ufsc_get_current_club_id( $user_id ) : 0;
		$view_result = $access->can_view_competition( (int) $competition->id, $club_id, $user_id );

		if ( ! $view_result->can_view_details ) {
			if ( function_exists( 'ufsc_render_access_denied_notice' ) ) {
				return (string) ufsc_render_access_denied_notice( $view_result );
			}
			return $this->render_notice( $access->get_denied_message( $view_result ) );
		}

		ob_start();
		$info_rows = array();
		$photo_html = '';
		$photo_id = absint( $competition->photo_evenement_id ?? 0 );
		if ( $photo_id && function_exists( 'wp_attachment_is_image' ) && wp_attachment_is_image( $photo_id ) ) {
			$photo_html = wp_get_attachment_image( $photo_id, 'large', false, array( 'class' => 'ufsc-competition-photo' ) );
		}

		if ( ! empty( $competition->lieu_name ) ) {
			$info_rows[ __( 'Lieu', 'ufsc-licence-competition' ) ] = (string) $competition->lieu_name;
		}
		if ( ! empty( $competition->lieu_address ) ) {
			$info_rows[ __( 'Adresse', 'ufsc-licence-competition' ) ] = (string) $competition->lieu_address;
		}

		$weighin_start = (string) ( $competition->weighin_start ?? '' );
		$weighin_end   = (string) ( $competition->weighin_end ?? '' );
		if ( function_exists( 'ufsc_lc_format_datetime' ) ) {
			$weighin_start = ufsc_lc_format_datetime( $weighin_start, '' );
			$weighin_end = ufsc_lc_format_datetime( $weighin_end, '' );
		}
		if ( $weighin_start || $weighin_end ) {
			$label = trim( $weighin_start . ( $weighin_end ? ' → ' . $weighin_end : '' ) );
			$info_rows[ __( 'Pesée', 'ufsc-licence-competition' ) ] = $label;
		}

		if ( ! empty( $competition->briefing_time ) ) {
			$briefing = (string) $competition->briefing_time;
			if ( function_exists( 'ufsc_lc_format_datetime' ) ) {
				$briefing = ufsc_lc_format_datetime( $briefing, $briefing );
			}
			$info_rows[ __( 'Briefing', 'ufsc-licence-competition' ) ] = $briefing;
		}

		if ( ! empty( $competition->fights_start ) ) {
			$fights_start = (string) $competition->fights_start;
			if ( function_exists( 'ufsc_lc_format_datetime' ) ) {
				$fights_start = ufsc_lc_format_datetime( $fights_start, $fights_start );
			}
			$info_rows[ __( 'Début combats', 'ufsc-licence-competition' ) ] = $fights_start;
		}

		if ( ! empty( $competition->event_start_datetime ) ) {
			$event_start = (string) $competition->event_start_datetime;
			if ( function_exists( 'ufsc_lc_format_datetime' ) ) {
				$event_start = ufsc_lc_format_datetime( $event_start, $event_start );
			}
			$info_rows[ __( 'Début événement', 'ufsc-licence-competition' ) ] = $event_start;
		}

		if ( ! empty( $competition->event_end_datetime ) ) {
			$event_end = (string) $competition->event_end_datetime;
			if ( function_exists( 'ufsc_lc_format_datetime' ) ) {
				$event_end = ufsc_lc_format_datetime( $event_end, $event_end );
			}
			$info_rows[ __( 'Fin événement', 'ufsc-licence-competition' ) ] = $event_end;
		}

		if ( ! empty( $competition->event_end_estimated ) ) {
			$event_estimated = (string) $competition->event_end_estimated;
			if ( function_exists( 'ufsc_lc_format_datetime' ) ) {
				$event_estimated = ufsc_lc_format_datetime( $event_estimated, $event_estimated );
			}
			$info_rows[ __( 'Fin prévisionnelle', 'ufsc-licence-competition' ) ] = $event_estimated;
		}

		$registration_deadline = '';
		if ( ! empty( $competition->registration_deadline ) ) {
			$registration_deadline = (string) $competition->registration_deadline;
			if ( function_exists( 'ufsc_lc_format_datetime' ) ) {
				$registration_deadline = ufsc_lc_format_datetime( $registration_deadline, $registration_deadline );
			}
		}

		$contact_bits = array();
		if ( ! empty( $competition->organizer_contact_name ) ) {
			$contact_bits[] = (string) $competition->organizer_contact_name;
		}
		if ( ! empty( $competition->organizer_phone ) ) {
			$contact_bits[] = (string) $competition->organizer_phone;
		}
		if ( ! empty( $competition->organizer_email ) ) {
			$contact_bits[] = (string) $competition->organizer_email;
		}
		if ( $contact_bits ) {
			$info_rows[ __( 'Contact organisateur', 'ufsc-licence-competition' ) ] = implode( ' · ', $contact_bits );
		}

		$club_notes = (string) ( $competition->club_notes ?? '' );
		?>
		<div class="ufsc-competition-details">
			<?php if ( $photo_html ) : ?>
				<div class="ufsc-competition-photo-wrapper">
					<?php echo wp_kses_post( $photo_html ); ?>
				</div>
			<?php endif; ?>
			<h2><?php echo esc_html( (string) ( $competition->name ?? '' ) ); ?></h2>
			<ul class="ufsc-competition-main">
				<li><strong><?php echo esc_html__( 'Discipline', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( (string) ( $competition->discipline ?? '' ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Type', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( (string) ( $competition->type ?? '' ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Saison', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( (string) ( $competition->season ?? '' ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Statut', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( (string) ( $competition->status ?? '' ) ); ?></li>
			</ul>

			<?php if ( $registration_deadline ) : ?>
				<p class="ufsc-competition-deadline" style="color:#b32d2e;font-weight:600;">
					<?php echo esc_html__( '⚠️ Date limite d’inscription :', 'ufsc-licence-competition' ); ?>
					<?php echo esc_html( $registration_deadline ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $info_rows ) : ?>
				<div class="ufsc-competition-practical">
					<h3><?php echo esc_html__( 'Infos pratiques', 'ufsc-licence-competition' ); ?></h3>
					<ul>
						<?php foreach ( $info_rows as $label => $value ) : ?>
							<li><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $value ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( $club_notes ) : ?>
				<div class="ufsc-competition-notes notice notice-info">
					<h3><?php echo esc_html__( 'Notes clubs', 'ufsc-licence-competition' ); ?></h3>
					<p><?php echo esc_html( $club_notes ); ?></p>
				</div>
			<?php endif; ?>

			<?php do_action( 'ufsc_competitions_front_after_details', $competition ); ?>

			<div class="ufsc-competition-registration">
				<p><?php echo esc_html__( 'Inscriptions clubs UFSC.', 'ufsc-licence-competition' ); ?></p>
				<p><?php echo esc_html__( 'Pour toute information, contactez le secrétariat UFSC :', 'ufsc-licence-competition' ); ?>
					<a href="mailto:secretaire@ufsc-france.org">secretaire@ufsc-france.org</a>
					<?php echo esc_html__( 'ou via le formulaire de contact.', 'ufsc-licence-competition' ); ?>
				</p>
				<?php
				$register_result = $access->can_register( (int) $competition->id, $club_id, $user_id );
				if ( $register_result->allowed ) :
					do_action( 'ufsc_competitions_front_registration_box', $competition );
				else :
					?>
					<div class="ufsc-competition-entries-locked">
						<?php
						if ( function_exists( 'ufsc_render_access_denied_notice' ) ) {
							echo wp_kses_post( ufsc_render_access_denied_notice( $register_result ) );
						} else {
							echo esc_html( $access->get_denied_message( $register_result ) );
						}
						?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function render_notice( string $message ): string {
		return sprintf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
