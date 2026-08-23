<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and normalizes the internal program of one event.
 *
 * The competition type remains the event identity (gala, open, championship,
 * etc.) while program blocks describe what actually happens inside it. This
 * allows, for example, a gala card to contain a six-athlete -75 kg tournament
 * without converting the whole gala into a tournament.
 */
class EventProgramRegistry {
	private const OPTION_PREFIX = 'ufsc_competition_program_';
	private const VERSION       = 1;
	private const MAX_BLOCKS    = 50;

	public const MODE_DIRECT_FIGHTS = 'direct_fights';
	public const MODE_TOURNAMENT    = 'tournament';
	public const MODE_POOL          = 'pool';
	public const MODE_MANUAL        = 'manual';

	public static function get_mode_choices(): array {
		return array(
			self::MODE_DIRECT_FIGHTS => __( 'Combats directs / carte de gala', 'ufsc-licence-competition' ),
			self::MODE_TOURNAMENT    => __( 'Mini-tournoi / tableau', 'ufsc-licence-competition' ),
			self::MODE_POOL          => __( 'Poule', 'ufsc-licence-competition' ),
			self::MODE_MANUAL        => __( 'Bloc manuel', 'ufsc-licence-competition' ),
		);
	}

	public static function get( int $competition_id, string $event_type = '' ): array {
		$competition_id = absint( $competition_id );
		$event_type     = sanitize_key( $event_type );
		$stored         = $competition_id ? get_option( self::OPTION_PREFIX . $competition_id, null ) : null;

		if ( ! is_array( $stored ) || empty( $stored['blocks'] ) || ! is_array( $stored['blocks'] ) ) {
			return array(
				'version'       => self::VERSION,
				'configured'    => false,
				'event_type'    => $event_type,
				'blocks'        => self::get_default_blocks( $event_type ),
				'updated_at'    => '',
				'updated_by'    => 0,
			);
		}

		return array(
			'version'       => self::VERSION,
			'configured'    => true,
			'event_type'    => sanitize_key( (string) ( $stored['event_type'] ?? $event_type ) ),
			'blocks'        => self::sanitize_blocks( $stored['blocks'], $event_type ),
			'updated_at'    => sanitize_text_field( (string) ( $stored['updated_at'] ?? '' ) ),
			'updated_by'    => absint( $stored['updated_by'] ?? 0 ),
		);
	}

	public static function save( int $competition_id, string $event_type, array $blocks ): bool {
		$competition_id = absint( $competition_id );
		$event_type     = sanitize_key( $event_type );
		if ( ! $competition_id ) {
			return false;
		}

		$payload = array(
			'version'    => self::VERSION,
			'event_type' => $event_type,
			'blocks'     => self::sanitize_blocks( $blocks, $event_type ),
			'updated_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			'updated_by' => function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0,
		);

		$option_name = self::OPTION_PREFIX . $competition_id;
		$existing    = get_option( $option_name, null );
		$updated     = update_option( $option_name, $payload, false );

		return (bool) $updated || $existing === $payload;
	}

	public static function get_default_blocks( string $event_type ): array {
		$event_type = sanitize_key( $event_type );
		$strategy   = class_exists( EventFormatRegistry::class )
			? EventFormatRegistry::get_strategy( $event_type )
			: EventFormatRegistry::STRATEGY_MANUAL;

		if ( EventFormatRegistry::STRATEGY_NONE === $strategy ) {
			return array();
		}

		if ( 'gala' === $event_type ) {
			return array(
				self::new_block(
					array(
						'id'    => 'carte-principale',
						'label' => __( 'Carte principale', 'ufsc-licence-competition' ),
						'mode'  => self::MODE_DIRECT_FIGHTS,
					)
				),
			);
		}

		if ( EventFormatRegistry::STRATEGY_TOURNAMENT === $strategy ) {
			return array(
				self::new_block(
					array(
						'id'    => 'tableau-principal',
						'label' => __( 'Tableau principal', 'ufsc-licence-competition' ),
						'mode'  => self::MODE_TOURNAMENT,
					)
				),
			);
		}

		if ( EventFormatRegistry::STRATEGY_MIXED === $strategy ) {
			return array(
				self::new_block(
					array(
						'id'    => 'programme-principal',
						'label' => __( 'Programme principal', 'ufsc-licence-competition' ),
						'mode'  => self::MODE_DIRECT_FIGHTS,
					)
				),
			);
		}

		return array(
			self::new_block(
				array(
					'id'    => 'bloc-manuel',
					'label' => __( 'Bloc manuel', 'ufsc-licence-competition' ),
					'mode'  => self::MODE_MANUAL,
				)
			),
		);
	}

	public static function sanitize_blocks( array $blocks, string $event_type ): array {
		$event_type    = sanitize_key( $event_type );
		$allowed_modes = class_exists( EventFormatRegistry::class )
			? EventFormatRegistry::get_supported_program_modes( $event_type )
			: array( self::MODE_MANUAL );
		$mode_choices  = self::get_mode_choices();
		$output        = array();
		$used_ids      = array();

		foreach ( array_slice( $blocks, 0, self::MAX_BLOCKS ) as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$mode = sanitize_key( (string) ( $block['mode'] ?? '' ) );
			if ( ! isset( $mode_choices[ $mode ] ) || ( ! empty( $allowed_modes ) && ! in_array( $mode, $allowed_modes, true ) ) ) {
				$mode = self::get_default_mode( $event_type );
			}

			$label = sanitize_text_field( (string) ( $block['label'] ?? '' ) );
			if ( '' === $label ) {
				$label = sprintf( __( 'Bloc %d', 'ufsc-licence-competition' ), (int) $index + 1 );
			}

			$id = sanitize_key( (string) ( $block['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = sanitize_title( $label );
			}
			if ( '' === $id ) {
				$id = 'bloc-' . ( (int) $index + 1 );
			}
			$base_id = $id;
			$suffix  = 2;
			while ( isset( $used_ids[ $id ] ) ) {
				$id = $base_id . '-' . $suffix;
				$suffix++;
			}
			$used_ids[ $id ] = true;

			$participant_target = absint( $block['participant_target'] ?? 0 );
			if ( $participant_target > 0 ) {
				$participant_target = min( 256, max( 2, $participant_target ) );
			}

			$output[] = array(
				'id'                 => $id,
				'label'              => $label,
				'mode'               => $mode,
				'discipline'         => sanitize_key( (string) ( $block['discipline'] ?? '' ) ),
				'category'           => sanitize_text_field( (string) ( $block['category'] ?? '' ) ),
				'weight_class'       => sanitize_text_field( (string) ( $block['weight_class'] ?? '' ) ),
				'participant_target' => $participant_target,
				'trophy'             => sanitize_text_field( (string) ( $block['trophy'] ?? '' ) ),
				'notes'              => sanitize_textarea_field( (string) ( $block['notes'] ?? '' ) ),
				'active'             => ! isset( $block['active'] ) || ! empty( $block['active'] ),
				'sort_order'         => count( $output ) + 1,
			);
		}

		return array_values( $output );
	}

	public static function filter_entries_for_block( array $entries, array $block ): array {
		$block = self::new_block( $block );

		return array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $block ): bool {
					if ( ! is_object( $entry ) && ! is_array( $entry ) ) {
						return false;
					}

					$get = static function ( $source, string $key ): string {
						$value = is_array( $source ) ? ( $source[ $key ] ?? '' ) : ( $source->{$key} ?? '' );
						return trim( (string) $value );
					};

					if ( '' !== $block['discipline'] ) {
						$entry_discipline = sanitize_key( $get( $entry, 'discipline' ) );
						if ( $entry_discipline !== $block['discipline'] ) {
							return false;
						}
					}

					if ( '' !== $block['category'] ) {
						$entry_category = $get( $entry, 'category' );
						if ( '' === $entry_category ) {
							$entry_category = $get( $entry, 'category_name' );
						}
						if ( 0 !== strcasecmp( $entry_category, $block['category'] ) ) {
							return false;
						}
					}

					if ( '' !== $block['weight_class'] ) {
						$entry_weight = $get( $entry, 'weight_class' );
						if ( '' === $entry_weight ) {
							$entry_weight = $get( $entry, 'weight_category' );
						}
						if ( 0 !== strcasecmp( $entry_weight, $block['weight_class'] ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	public static function get_summary( array $program ): array {
		$blocks             = isset( $program['blocks'] ) && is_array( $program['blocks'] ) ? $program['blocks'] : array();
		$active_blocks      = array_values( array_filter( $blocks, static function ( array $block ): bool { return ! empty( $block['active'] ); } ) );
		$tournament_blocks  = array_values( array_filter( $active_blocks, static function ( array $block ): bool { return self::MODE_TOURNAMENT === ( $block['mode'] ?? '' ); } ) );
		$direct_blocks      = array_values( array_filter( $active_blocks, static function ( array $block ): bool { return self::MODE_DIRECT_FIGHTS === ( $block['mode'] ?? '' ); } ) );

		return array(
			'active_blocks'     => count( $active_blocks ),
			'tournament_blocks' => count( $tournament_blocks ),
			'direct_blocks'     => count( $direct_blocks ),
			'is_hybrid'         => ! empty( $tournament_blocks ) && ! empty( $direct_blocks ),
		);
	}

	private static function new_block( array $values = array() ): array {
		$defaults = array(
			'id'                 => '',
			'label'              => '',
			'mode'               => self::MODE_MANUAL,
			'discipline'         => '',
			'category'           => '',
			'weight_class'       => '',
			'participant_target' => 0,
			'trophy'             => '',
			'notes'              => '',
			'active'             => true,
			'sort_order'         => 1,
		);

		return array_merge( $defaults, $values );
	}

	private static function get_default_mode( string $event_type ): string {
		$event_type = sanitize_key( $event_type );
		$strategy   = class_exists( EventFormatRegistry::class )
			? EventFormatRegistry::get_strategy( $event_type )
			: EventFormatRegistry::STRATEGY_MANUAL;

		switch ( $strategy ) {
			case EventFormatRegistry::STRATEGY_CARD:
				return self::MODE_DIRECT_FIGHTS;
			case EventFormatRegistry::STRATEGY_TOURNAMENT:
				return self::MODE_TOURNAMENT;
			case EventFormatRegistry::STRATEGY_MIXED:
				return self::MODE_DIRECT_FIGHTS;
			case EventFormatRegistry::STRATEGY_NONE:
			case EventFormatRegistry::STRATEGY_MANUAL:
			default:
				return self::MODE_MANUAL;
		}
	}
}
