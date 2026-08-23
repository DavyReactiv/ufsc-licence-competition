<?php

namespace UFSC\Competitions\Front;

use UFSC\Competitions\Services\RegistrationScopePresenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared front UX for competition registration scope and current club status. */
class PremiumRegistrationExperience {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ), 30 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'decorate_competition_details' ), 20, 4 );
	}

	public static function enqueue_styles(): void {
		if ( is_admin() ) {
			return;
		}

		$file = UFSC_LC_DIR . 'includes/competitions/assets/premium-front.css';
		if ( ! file_exists( $file ) ) {
			return;
		}

		$mtime   = filemtime( $file );
		$version = false !== $mtime ? (string) $mtime : '1.0.0';
		wp_enqueue_style(
			'ufsc-competitions-premium-front',
			UFSC_LC_URL . 'includes/competitions/assets/premium-front.css',
			array(),
			$version
		);
	}

	/**
	 * Decorate only the competition detail shortcode. Access is not recalculated
	 * here: RegistrationScopePresenter delegates the decision to CompetitionAccess.
	 */
	public static function decorate_competition_details( string $output, string $tag, array $attr, $m ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( 'ufsc_competition' !== $tag || '' === trim( $output ) ) {
			return $output;
		}

		$competition_id = isset( $attr['id'] ) ? absint( $attr['id'] ) : 0;
		if ( ! $competition_id && class_exists( Front::class ) ) {
			$competition_id = absint( Front::get_competition_id_from_request() );
		}
		if ( ! $competition_id ) {
			return $output;
		}

		$user_id = is_user_logged_in() ? absint( get_current_user_id() ) : 0;
		$club_id = function_exists( 'ufsc_lc_get_current_club_id' ) ? absint( ufsc_lc_get_current_club_id( $user_id ) ) : 0;
		$scope   = RegistrationScopePresenter::describe_current_club( $competition_id, $club_id, $user_id );
		$card    = self::render_scope_card( $scope );

		$needle = '<div class="ufsc-competition-container">';
		$pos    = strpos( $output, $needle );
		if ( false === $pos ) {
			return $card . $output;
		}

		$insert_at = $pos + strlen( $needle );
		return substr( $output, 0, $insert_at ) . $card . substr( $output, $insert_at );
	}

	private static function render_scope_card( array $scope ): string {
		$allowed      = ! empty( $scope['allowed'] );
		$region_mode  = ! empty( $scope['is_region_scoped'] );
		$regions      = isset( $scope['regions'] ) && is_array( $scope['regions'] ) ? $scope['regions'] : array();
		$requirements = isset( $scope['requirements'] ) && is_array( $scope['requirements'] ) ? $scope['requirements'] : array();
		$status       = $allowed ? 'allowed' : 'restricted';
		$status_text  = $allowed
			? __( 'Votre club peut inscrire', 'ufsc-licence-competition' )
			: __( 'Inscription non disponible pour votre club', 'ufsc-licence-competition' );
		$message      = (string) ( $scope['message'] ?? $scope['summary'] ?? '' );

		ob_start();
		?>
		<section class="ufsc-registration-scope-card is-<?php echo esc_attr( $status ); ?>" aria-label="<?php esc_attr_e( 'Conditions d’inscription', 'ufsc-licence-competition' ); ?>">
			<div class="ufsc-registration-scope-card__status" aria-hidden="true">
				<span><?php echo $allowed ? '✓' : '!'; ?></span>
			</div>
			<div class="ufsc-registration-scope-card__content">
				<div class="ufsc-registration-scope-card__heading">
					<strong><?php echo esc_html( $status_text ); ?></strong>
					<?php if ( $region_mode ) : ?>
						<span class="ufsc-registration-scope-card__badge"><?php esc_html_e( 'Accès régional', 'ufsc-licence-competition' ); ?></span>
					<?php endif; ?>
				</div>
				<p><?php echo esc_html( $message ); ?></p>
				<?php if ( $region_mode && $regions ) : ?>
					<div class="ufsc-registration-scope-card__row">
						<span><?php esc_html_e( 'Régions autorisées', 'ufsc-licence-competition' ); ?></span>
						<strong><?php echo esc_html( implode( ' · ', $regions ) ); ?></strong>
					</div>
				<?php endif; ?>
				<?php if ( $requirements ) : ?>
					<div class="ufsc-registration-scope-card__row">
						<span><?php esc_html_e( 'Conditions', 'ufsc-licence-competition' ); ?></span>
						<strong><?php echo esc_html( implode( ' · ', $requirements ) ); ?></strong>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
