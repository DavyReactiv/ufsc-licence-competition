<?php

namespace UFSC\Competitions\Front;

use UFSC\Competitions\Services\LiveCompetitionService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Public read-only live competition page and REST feed. */
class LiveCompetitionExperience {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_shortcode( 'ufsc_competition_live', array( __CLASS__, 'render_shortcode' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'ufsc-competitions/v1',
			'/live/(?P<competition_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_snapshot' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'competition_id' => array(
						'sanitize_callback' => 'absint',
					),
					'surface' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public static function rest_snapshot( $request ) {
		$competition_id = absint( $request['competition_id'] ?? 0 );
		$surface        = sanitize_text_field( (string) ( $request['surface'] ?? '' ) );

		if ( ! LiveCompetitionService::is_enabled( $competition_id ) ) {
			return new \WP_Error(
				'ufsc_live_disabled',
				__( 'La diffusion publique de cette compétition n’est pas activée.', 'ufsc-licence-competition' ),
				array( 'status' => 404 )
			);
		}

		$snapshot = LiveCompetitionService::get_snapshot( $competition_id, $surface );
		if ( ! $snapshot ) {
			return new \WP_Error(
				'ufsc_live_not_found',
				__( 'Compétition introuvable.', 'ufsc-licence-competition' ),
				array( 'status' => 404 )
			);
		}

		$response = rest_ensure_response( $snapshot );
		$response->header( 'Cache-Control', 'public, max-age=5, must-revalidate' );
		return $response;
	}

	public static function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'      => 0,
				'surface' => '',
				'mode'    => 'standard',
			),
			$atts,
			'ufsc_competition_live'
		);

		$competition_id = absint( $atts['id'] );
		$surface        = sanitize_text_field( (string) $atts['surface'] );
		$mode           = 'screen' === sanitize_key( (string) $atts['mode'] ) ? 'screen' : 'standard';

		if ( $competition_id <= 0 ) {
			return '<div class="ufsc-live-error">' . esc_html__( 'Identifiant de compétition manquant.', 'ufsc-licence-competition' ) . '</div>';
		}
		if ( ! LiveCompetitionService::is_enabled( $competition_id ) ) {
			return '<div class="ufsc-live-error">' . esc_html__( 'Le LIVE de cette compétition n’est pas encore public.', 'ufsc-licence-competition' ) . '</div>';
		}

		wp_enqueue_style(
			'ufsc-competition-live',
			UFSC_LC_URL . 'includes/competitions/assets/competition-live.css',
			array(),
			'1.0.0'
		);
		wp_enqueue_script(
			'ufsc-competition-live',
			UFSC_LC_URL . 'includes/competitions/assets/competition-live.js',
			array(),
			'1.0.0',
			true
		);

		$endpoint = rest_url( 'ufsc-competitions/v1/live/' . $competition_id );
		if ( '' !== $surface ) {
			$endpoint = add_query_arg( 'surface', $surface, $endpoint );
		}

		return sprintf(
			'<section class="ufsc-live ufsc-live--%1$s" data-ufsc-live data-endpoint="%2$s"><div class="ufsc-live__loading">%3$s</div><noscript>%4$s</noscript></section>',
			esc_attr( $mode ),
			esc_url( $endpoint ),
			esc_html__( 'Chargement du direct…', 'ufsc-licence-competition' ),
			esc_html__( 'Activez JavaScript pour suivre les combats en direct.', 'ufsc-licence-competition' )
		);
	}
}
