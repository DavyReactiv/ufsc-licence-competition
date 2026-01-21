<?php

namespace UFSC\Competitions\Front\Shortcodes;

use UFSC\Competitions\Front\Access\ClubAccess;
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

		$require_login = (int) $atts['require_login'] === 1;
		if ( ! is_user_logged_in() ) {
			if ( $require_login ) {
				return $this->render_notice( esc_html__( 'Vous devez être connecté pour accéder à cette compétition.', 'ufsc-licence-competition' ) );
			}

			return '';
		}

		if ( (int) $atts['require_club'] === 1 ) {
			$access = new ClubAccess();
			if ( ! $access->is_club_user( get_current_user_id() ) ) {
				return $this->render_notice( esc_html__( 'Accès réservé aux clubs affiliés.', 'ufsc-licence-competition' ) );
			}
		}

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

		ob_start();
		?>
		<div class="ufsc-competition-details">
			<h2><?php echo esc_html( (string) ( $competition->name ?? '' ) ); ?></h2>
			<ul>
				<li><strong><?php echo esc_html__( 'Discipline', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( (string) ( $competition->discipline ?? '' ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Type', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( (string) ( $competition->type ?? '' ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Saison', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( (string) ( $competition->season ?? '' ) ); ?></li>
				<li><strong><?php echo esc_html__( 'Statut', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( (string) ( $competition->status ?? '' ) ); ?></li>
				<?php if ( ! empty( $competition->event_start_datetime ) ) : ?>
					<li><strong><?php echo esc_html__( 'Début', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( (string) $competition->event_start_datetime ); ?></li>
				<?php endif; ?>
				<?php if ( ! empty( $competition->event_end_datetime ) ) : ?>
					<li><strong><?php echo esc_html__( 'Fin', 'ufsc-licence-competition' ); ?>:</strong> <?php echo esc_html( (string) $competition->event_end_datetime ); ?></li>
				<?php endif; ?>
			</ul>

			<?php do_action( 'ufsc_competitions_front_after_details', $competition ); ?>

			<div class="ufsc-competition-registration">
				<p><?php echo esc_html__( 'Inscriptions à venir.', 'ufsc-licence-competition' ); ?></p>
				<?php do_action( 'ufsc_competitions_front_registration_box', $competition ); ?>
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
