<?php

namespace UFSC\Competitions\Services\Pancrace;

use UFSC\Competitions\Services\EventFormatRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers non-destructive Pancrace/domain extensions. */
class PancraceDomainBootstrap {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		EventFormatRegistry::register();
		add_filter( 'ufsc_competitions_weight_rules', array( __CLASS__, 'protect_pancrace_from_generic_weight_fallback' ) );
	}

	/**
	 * Prevent Pancrace from silently inheriting ASSAUT/TATAMI weights.
	 * Dedicated Pancrace classification uses PancraceReference2025 and U-codes.
	 */
	public static function protect_pancrace_from_generic_weight_fallback( array $rules ): array {
		$rules['pancrace'] = array(
			'age_groups' => array(),
			'weights' => array(),
			'table' => 'pancrace',
		);

		return $rules;
	}
}
