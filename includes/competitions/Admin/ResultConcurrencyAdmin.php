<?php

namespace UFSC\Competitions\Admin;

use UFSC\Competitions\Repositories\FightRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adds optimistic revision tokens to existing result forms without duplicating result UI. */
class ResultConcurrencyAdmin {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 70 );
	}

	public static function enqueue(): void {
		if ( ! is_admin() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( Menu::PAGE_RESULTS !== $page ) {
			return;
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $competition_id ) {
			return;
		}

		$repo = new FightRepository();
		$fights = $repo->list(
			array(
				'view'           => 'all',
				'competition_id' => $competition_id,
			),
			5000,
			0
		);
		$revisions = array();
		foreach ( (array) $fights as $fight ) {
			$id = absint( $fight->id ?? 0 );
			$updated_at = trim( (string) ( $fight->updated_at ?? '' ) );
			if ( $id && '' !== $updated_at ) {
				$revisions[ (string) $id ] = $updated_at;
			}
		}

		$file = UFSC_LC_DIR . 'includes/competitions/assets/result-concurrency.js';
		if ( ! file_exists( $file ) ) {
			return;
		}

		wp_enqueue_script(
			'ufsc-competition-result-concurrency',
			UFSC_LC_URL . 'includes/competitions/assets/result-concurrency.js',
			array(),
			(string) filemtime( $file ),
			true
		);
		wp_localize_script(
			'ufsc-competition-result-concurrency',
			'ufscResultConcurrency',
			array(
				'revisions' => $revisions,
				'message'   => __( 'Ce résultat sera refusé si le combat a été modifié depuis l’ouverture de la page.', 'ufsc-licence-competition' ),
			)
		);
	}
}
