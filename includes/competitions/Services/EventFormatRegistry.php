<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Separates the event identity from the fight generation/program capabilities.
 *
 * A gala remains a gala and defaults to a fight card, but can contain isolated
 * tournament or pool blocks. This prevents the whole event from being forced
 * into one algorithm when only part of its program is a tournament.
 */
class EventFormatRegistry {
	public const STRATEGY_TOURNAMENT = 'tournament';
	public const STRATEGY_CARD       = 'card';
	public const STRATEGY_MIXED      = 'mixed';
	public const STRATEGY_NONE       = 'none';
	public const STRATEGY_MANUAL     = 'manual';

	public static function register(): void {
		add_filter( 'ufsc_competitions_type_choices', array( __CLASS__, 'extend_type_choices' ) );
	}

	public static function extend_type_choices( array $choices ): array {
		$additional = array(
			'competition' => __( 'Compétition', 'ufsc-licence-competition' ),
			'tournoi'     => __( 'Tournoi', 'ufsc-licence-competition' ),
			'coupe'       => __( 'Coupe', 'ufsc-licence-competition' ),
		);

		return $additional + $choices;
	}

	public static function strategies(): array {
		return array(
			'competition'          => self::STRATEGY_TOURNAMENT,
			'tournoi'              => self::STRATEGY_TOURNAMENT,
			'open'                 => self::STRATEGY_TOURNAMENT,
			'coupe'                => self::STRATEGY_TOURNAMENT,
			'championnat_regional' => self::STRATEGY_TOURNAMENT,
			'selection_regionale'  => self::STRATEGY_TOURNAMENT,
			'championnat_national' => self::STRATEGY_TOURNAMENT,
			'gala'                 => self::STRATEGY_CARD,
			'interclub'            => self::STRATEGY_MIXED,
			'stage'                => self::STRATEGY_NONE,
			'autre'                => self::STRATEGY_MANUAL,
		);
	}

	public static function get_strategy( string $event_type ): string {
		$event_type = sanitize_key( $event_type );
		$strategies = self::strategies();

		return $strategies[ $event_type ] ?? self::STRATEGY_MANUAL;
	}

	/**
	 * Return program modes supported inside the event.
	 *
	 * These capabilities are intentionally broader than the default strategy.
	 * A gala defaults to a direct fight card but can also host a mini-tournament
	 * or a pool for a title/category.
	 */
	public static function get_supported_program_modes( string $event_type ): array {
		$event_type = sanitize_key( $event_type );

		switch ( $event_type ) {
			case 'competition':
			case 'tournoi':
			case 'open':
			case 'coupe':
			case 'championnat_regional':
			case 'selection_regionale':
			case 'championnat_national':
				return array(
					EventProgramRegistry::MODE_TOURNAMENT,
					EventProgramRegistry::MODE_POOL,
				);
			case 'gala':
			case 'interclub':
				return array(
					EventProgramRegistry::MODE_DIRECT_FIGHTS,
					EventProgramRegistry::MODE_TOURNAMENT,
					EventProgramRegistry::MODE_POOL,
					EventProgramRegistry::MODE_MANUAL,
				);
			case 'stage':
				return array();
			case 'autre':
			default:
				return array(
					EventProgramRegistry::MODE_MANUAL,
					EventProgramRegistry::MODE_DIRECT_FIGHTS,
				);
		}
	}

	public static function supports_program_mode( string $event_type, string $mode ): bool {
		$mode = sanitize_key( $mode );
		return in_array( $mode, self::get_supported_program_modes( $event_type ), true );
	}

	public static function supports_brackets( string $event_type ): bool {
		return self::supports_program_mode( $event_type, EventProgramRegistry::MODE_TOURNAMENT );
	}

	public static function supports_pools( string $event_type ): bool {
		return self::supports_program_mode( $event_type, EventProgramRegistry::MODE_POOL );
	}

	public static function uses_direct_fight_card( string $event_type ): bool {
		return self::supports_program_mode( $event_type, EventProgramRegistry::MODE_DIRECT_FIGHTS );
	}

	public static function supports_mixed_program( string $event_type ): bool {
		$modes = self::get_supported_program_modes( $event_type );
		return count( $modes ) > 1;
	}

	public static function supports_automatic_fights( string $event_type ): bool {
		return self::STRATEGY_NONE !== self::get_strategy( $event_type );
	}
}
