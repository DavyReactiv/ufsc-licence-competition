<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Repositories\CompetitionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a clean draft from an existing competition without copying live data.
 *
 * Only reusable configuration is copied: event identity, access/meta settings
 * and the internal event program. Entries, weigh-ins, fights, results, exports
 * and officials remain attached to the source event and are never duplicated.
 */
class CompetitionDuplicationService {
	/** @var CompetitionRepository */
	private $competitions;

	public function __construct( ?CompetitionRepository $competitions = null ) {
		$this->competitions = $competitions instanceof CompetitionRepository ? $competitions : new CompetitionRepository();
	}

	/**
	 * Duplicate one event as a clean draft.
	 *
	 * @param int   $source_id Source competition ID.
	 * @param array $overrides Optional name/season overrides.
	 * @return array{ok:bool,id:int,error:string}
	 */
	public function duplicate( int $source_id, array $overrides = array() ): array {
		$source_id = absint( $source_id );
		if ( ! $source_id ) {
			return self::failure( 'invalid_source' );
		}

		$source = $this->competitions->get( $source_id, true );
		if ( ! $source || ! empty( $source->deleted_at ) ) {
			return self::failure( 'source_not_found' );
		}

		if ( method_exists( $this->competitions, 'assert_competition_in_scope' ) ) {
			$this->competitions->assert_competition_in_scope( $source_id );
		}

		$name = isset( $overrides['name'] ) ? sanitize_text_field( (string) $overrides['name'] ) : '';
		if ( '' === $name ) {
			$name = sprintf(
				/* translators: %s: original event name */
				__( '%s — nouvelle édition', 'ufsc-licence-competition' ),
				trim( (string) ( $source->name ?? '' ) )
			);
		}

		$season = isset( $overrides['season'] ) ? sanitize_text_field( (string) $overrides['season'] ) : '';
		if ( '' === $season ) {
			$season = self::current_season();
		}

		$new_id = $this->competitions->save(
			array(
				'name'                 => $name,
				'discipline'           => sanitize_text_field( (string) ( $source->discipline ?? '' ) ),
				'type'                 => sanitize_text_field( (string) ( $source->type ?? '' ) ),
				'season'               => $season,
				'status'               => 'draft',
				'event_start_datetime' => '',
				'event_end_datetime'   => '',
			)
		);

		if ( ! $new_id ) {
			return self::failure( 'save_failed' );
		}

		$this->copy_reusable_meta( $source_id, $new_id );
		$this->copy_program( $source_id, $new_id, (string) ( $source->type ?? '' ) );

		if ( class_exists( LogService::class ) ) {
			( new LogService() )->audit(
				'competition_duplicated',
				$new_id,
				'competition',
				$new_id,
				array(
					'source_competition_id' => $source_id,
					'target_season'         => $season,
					'copied_live_data'      => false,
				),
				'Nouvelle édition créée depuis une compétition existante.'
			);
		}

		return array(
			'ok'    => true,
			'id'    => (int) $new_id,
			'error' => '',
		);
	}

	public static function current_season( ?int $timestamp = null ): string {
		if ( null === $timestamp ) {
			$timestamp = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();
		}

		$year  = (int) gmdate( 'Y', $timestamp );
		$month = (int) gmdate( 'n', $timestamp );
		$start = $month >= 8 ? $year : $year - 1;

		return sprintf( '%d-%d', $start, $start + 1 );
	}

	private function copy_reusable_meta( int $source_id, int $new_id ): void {
		if ( ! class_exists( CompetitionMeta::class ) ) {
			return;
		}

		$meta = CompetitionMeta::get( $source_id );

		// Operational dates must be intentionally re-entered for the new edition.
		foreach ( array( 'weighin_start', 'weighin_end', 'briefing_time', 'fights_start', 'event_end_estimated', 'registration_deadline' ) as $field ) {
			$meta[ $field ] = '';
		}

		CompetitionMeta::save( $new_id, $meta );
	}

	private function copy_program( int $source_id, int $new_id, string $event_type ): void {
		if ( ! class_exists( EventProgramRegistry::class ) ) {
			return;
		}

		$event_type = CompetitionFilters::normalize_type_key( $event_type );
		$program    = EventProgramRegistry::get( $source_id, $event_type );
		$blocks     = isset( $program['blocks'] ) && is_array( $program['blocks'] ) ? $program['blocks'] : array();

		if ( ! empty( $blocks ) ) {
			EventProgramRegistry::save( $new_id, $event_type, $blocks );
		}
	}

	private static function failure( string $error ): array {
		return array(
			'ok'    => false,
			'id'    => 0,
			'error' => sanitize_key( $error ),
		);
	}
}
