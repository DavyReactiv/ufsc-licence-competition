<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Separates the event's business format from the fight generation algorithm.
 *
 * A gala is a fight card, while a tournament/open/championship typically uses
 * brackets or pools. Keeping this distinction explicit prevents the generator
 * from applying the same workflow to every event type.
 */
class EventFormatRegistry {
	public const STRATEGY_TOURNAMENT = 'tournament';
	public const STRATEGY_CARD = 'card';
	public const STRATEGY_MIXED = 'mixed';
	public const STRATEGY_NONE = 'none';
	public const STRATEGY_MANUAL = 'manual';

	public static function register(): void {
		add_filter( 'ufsc_competitions_type_choices', array( __CLASS__, 'extend_type_choices' ) );
	}

	public static function extend_type_choices( array $choices ): array {
		$additional = array(
			'competition' => __( 'Compétition', 'ufsc-licence-competition' ),
			'tournoi' => __( 'Tournoi', 'ufsc-licence-competition' ),
			'coupe' => __( 'Coupe', 'ufsc-licence-competition' ),
		);

		return $additional + $choices;
	}

	public static function strategies(): array {
		return array(
			'competition' => self::STRATEGY_TOURNAMENT,
			'tournoi' => self::STRATEGY_TOURNAMENT,
			'open' => self::STRATEGY_TOURNAMENT,
			'coupe' => self::STRATEGY_TOURNAMENT,
			'championnat_regional' => self::STRATEGY_TOURNAMENT,
			'selection_regionale' => self::STRATEGY_TOURNAMENT,
			'championnat_national' => self::STRATEGY_TOURNAMENT,
			'gala' => self::STRATEGY_CARD,
			'interclub' => self::STRATEGY_MIXED,
			'stage' => self::STRATEGY_NONE,
			'autre' => self::STRATEGY_MANUAL,
		);
	}

	public static function get_strategy( string $event_type ): string {
		$event_type = sanitize_key( $event_type );
		$strategies = self::strategies();

		return $strategies[ $event_type ] ?? self::STRATEGY_MANUAL;
	}

	public static function supports_brackets( string $event_type ): bool {
		return in_array( self::get_strategy( $event_type ), array( self::STRATEGY_TOURNAMENT, self::STRATEGY_MIXED ), true );
	}

	public static function supports_pools( string $event_type ): bool {
		return self::supports_brackets( $event_type );
	}

	public static function uses_direct_fight_card( string $event_type ): bool {
		return self::STRATEGY_CARD === self::get_strategy( $event_type );
	}

	public static function supports_automatic_fights( string $event_type ): bool {
		return self::STRATEGY_NONE !== self::get_strategy( $event_type );
	}
}
