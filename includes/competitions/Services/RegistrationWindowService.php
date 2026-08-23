<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for front registration opening and forclusion.
 *
 * It deliberately does not decide club/region eligibility. That remains the
 * responsibility of CompetitionAccess.
 */
class RegistrationWindowService {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'ufsc_competitions_front_registration_is_open', array( __CLASS__, 'filter_registration_open' ), 20, 3 );
	}

	public static function filter_registration_open( $is_open, $competition, $club_id = 0 ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $is_open ) {
			return false;
		}

		$state = self::evaluate( $competition );
		return ! empty( $state['is_open'] );
	}

	/**
	 * @return array{is_open:bool,state:string,opens_at:string,closes_at:string,message:string}
	 */
	public static function evaluate( $competition ): array {
		$result = array(
			'is_open'   => false,
			'state'     => 'invalid',
			'opens_at'  => '',
			'closes_at' => '',
			'message'   => __( 'Inscriptions indisponibles.', 'ufsc-licence-competition' ),
		);

		if ( ! is_object( $competition ) ) {
			return $result;
		}

		$status = sanitize_key( (string) ( $competition->status ?? '' ) );
		if ( 'open' !== $status ) {
			$result['state'] = 'competition_closed';
			$result['message'] = __( 'Les inscriptions sont fermées par l’organisateur.', 'ufsc-licence-competition' );
			return $result;
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$now      = new \DateTimeImmutable( 'now', $timezone );

		$opens_at = self::parse_datetime( (string) ( $competition->registration_open_datetime ?? '' ), $timezone, false );
		$closing_candidates = array(
			self::parse_datetime( (string) ( $competition->registration_close_datetime ?? '' ), $timezone, true ),
			self::parse_datetime( (string) ( $competition->registration_deadline ?? '' ), $timezone, true ),
		);

		$competition_id = absint( $competition->id ?? 0 );
		if ( $competition_id && class_exists( CompetitionMeta::class ) ) {
			$meta = CompetitionMeta::get( $competition_id );
			$closing_candidates[] = self::parse_datetime( (string) ( $meta['registration_deadline'] ?? '' ), $timezone, true );
		}

		$closes_at = self::earliest_datetime( $closing_candidates );

		$result['opens_at'] = $opens_at ? $opens_at->format( 'Y-m-d H:i:s' ) : '';
		$result['closes_at'] = $closes_at ? $closes_at->format( 'Y-m-d H:i:s' ) : '';

		if ( $opens_at && $now < $opens_at ) {
			$result['state'] = 'not_open_yet';
			$result['message'] = sprintf(
				/* translators: %s: date/time */
				__( 'Les inscriptions ouvriront le %s.', 'ufsc-licence-competition' ),
				self::format_datetime( $opens_at )
			);
			return $result;
		}

		if ( $closes_at && $now > $closes_at ) {
			$result['state'] = 'deadline_passed';
			$result['message'] = sprintf(
				/* translators: %s: date/time */
				__( 'La date de forclusion est dépassée depuis le %s. Les inscriptions restent consultables mais ne sont plus modifiables par les clubs.', 'ufsc-licence-competition' ),
				self::format_datetime( $closes_at )
			);
			return $result;
		}

		$result['is_open'] = true;
		$result['state'] = 'open';
		$result['message'] = $closes_at
			? sprintf(
				/* translators: %s: date/time */
				__( 'Inscriptions ouvertes jusqu’au %s.', 'ufsc-licence-competition' ),
				self::format_datetime( $closes_at )
			)
			: __( 'Inscriptions ouvertes.', 'ufsc-licence-competition' );

		return $result;
	}

	private static function parse_datetime( string $value, \DateTimeZone $timezone, bool $end_of_day_for_date ) {
		$value = trim( $value );
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$value .= $end_of_day_for_date ? ' 23:59:59' : ' 00:00:00';
		}

		$value = str_replace( 'T', ' ', $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
			$value .= ':00';
		}

		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $timezone );
		if ( ! $date instanceof \DateTimeImmutable ) {
			return null;
		}

		return $date;
	}

	private static function earliest_datetime( array $dates ) {
		$valid = array_values(
			array_filter(
				$dates,
				static function( $date ) {
					return $date instanceof \DateTimeImmutable;
				}
			)
		);
		if ( empty( $valid ) ) {
			return null;
		}

		usort(
			$valid,
			static function( \DateTimeImmutable $a, \DateTimeImmutable $b ): int {
				return $a->getTimestamp() <=> $b->getTimestamp();
			}
		);

		return $valid[0];
	}

	private static function format_datetime( \DateTimeInterface $date ): string {
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date->getTimestamp(), $date->getTimezone() );
		}

		return $date->format( 'd/m/Y H:i' );
	}
}
