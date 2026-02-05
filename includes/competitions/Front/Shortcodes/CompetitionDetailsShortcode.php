<?php

namespace UFSC\Competitions\Front\Shortcodes;

use UFSC\Competitions\Access\CompetitionAccess;
use UFSC\Competitions\Front\Front;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;
use UFSC\Competitions\Services\CompetitionFilters;

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
				<li><strong><?php echo esc_html__( 'Discipline', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( CompetitionFilters::get_discipline_label( (string) ( $competition->discipline ?? '' ) ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Type', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( CompetitionFilters::get_type_label( (string) ( $competition->type ?? '' ) ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Saison', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( (string) ( $competition->season ?? '' ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Statut', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( CompetitionFilters::get_status_label( (string) ( $competition->status ?? '' ) ) ); ?></li>
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

			<div class="ufsc-competition-regulations notice notice-info">
				<h3><?php echo esc_html__( 'Règlement & obligations', 'ufsc-licence-competition' ); ?></h3>
				<ul>
					<li><?php echo esc_html__( 'Passeport sportif obligatoire pour les compétitions fédérales (disponible dans la Boutique Club).', 'ufsc-licence-competition' ); ?></li>
					<li><?php echo esc_html__( 'Certificat médical requis selon l’engagement (Loisir / Assaut / Combat) conformément à la réglementation en vigueur.', 'ufsc-licence-competition' ); ?></li>
					<li><?php echo esc_html__( 'Autorisation parentale obligatoire pour les mineurs.', 'ufsc-licence-competition' ); ?></li>
					<li><?php echo esc_html__( 'Respect strict des catégories de poids et des catégories d’âge.', 'ufsc-licence-competition' ); ?></li>
				</ul>
				<p>
					<?php echo esc_html__( 'Un rappel complet est disponible dans les documents officiels UFSC :', 'ufsc-licence-competition' ); ?>
					<a href="<?php echo esc_url( 'https://ufsc-france.fr/documents-clubs-a-telecharger/' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Documents clubs', 'ufsc-licence-competition' ); ?></a>
					· <a href="<?php echo esc_url( 'https://ufsc-france.fr/ufsc-reglements-sportifs-techniques-interieur/' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Règlements UFSC', 'ufsc-licence-competition' ); ?></a>
					· <a href="<?php echo esc_url( 'https://ufsc-france.fr/wp-content/uploads/2025/06/CATEGORIES-DE-POIDS-2024-2024.pdf' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Catégories de poids (PDF)', 'ufsc-licence-competition' ); ?></a>
				</p>
			</div>

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
						<?php echo wp_kses_post( $this->render_restricted_notice( $register_result ) ); ?>
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

	private function render_restricted_notice( $access_result ): string {
		$message = __( 'Inscriptions non disponibles pour votre club.', 'ufsc-licence-competition' );
		$reason_message = '';
		$list_url = function_exists( 'ufsc_get_competitions_list_url' ) ? ufsc_get_competitions_list_url() : '';

		$buttons = array();
		if ( $list_url ) {
			$buttons[] = sprintf(
				'<a class="button" href="%s">%s</a>',
				esc_url( $list_url ),
				esc_html__( 'Retour à la liste', 'ufsc-licence-competition' )
			);
		}

		$reason_code = is_object( $access_result ) ? (string) ( $access_result->reason_code ?? '' ) : '';
		switch ( $reason_code ) {
			case 'club_not_allowed':
			case 'not_affiliated':
			case 'not_allowed_by_rule':
				$reason_message = __( 'Votre club n’est pas éligible pour cette compétition.', 'ufsc-licence-competition' );
				break;
			case 'club_not_linked':
			case 'club_not_resolved':
				$reason_message = __( 'Votre compte n’est pas rattaché à un club UFSC.', 'ufsc-licence-competition' );
				break;
			case 'region_mismatch':
				$reason_message = __( 'Compétition réservée à certaines régions.', 'ufsc-licence-competition' );
				break;
			case 'club_region_missing':
				$reason_message = __( 'Votre région club n’est pas renseignée.', 'ufsc-licence-competition' );
				break;
			case 'discipline_mismatch':
				$reason_message = __( 'Compétition réservée à certaines disciplines.', 'ufsc-licence-competition' );
				break;
			case 'registration_closed':
				$reason_message = __( 'Les inscriptions sont actuellement closes.', 'ufsc-licence-competition' );
				break;
			case 'invalid_license':
				$reason_message = __( 'Une licence valide est requise pour s’inscrire.', 'ufsc-licence-competition' );
				break;
			default:
				break;
		}

		if ( 'club_region_missing' === $reason_code ) {
			$buttons[] = sprintf(
				'<a class="button" href="%s">%s</a>',
				esc_url( 'mailto:secretariat@ufsc-france.org' ),
				esc_html__( 'Contacter l’administration UFSC', 'ufsc-licence-competition' )
			);
		}

		return sprintf(
			'<div class="notice notice-warning ufsc-access-restricted"><p>%s</p>%s%s</div>',
			esc_html( $message ),
			$reason_message ? '<p>' . esc_html( $reason_message ) . '</p>' : '',
			$buttons ? '<p>' . implode( ' ', $buttons ) . '</p>' : ''
		);
	}
}
