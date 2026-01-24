<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionMeta {
	private const OPTION_PREFIX = 'ufsc_competition_meta_';

	public static function get( int $competition_id ): array {
		$competition_id = absint( $competition_id );
		if ( ! $competition_id ) {
			return self::defaults();
		}

		$stored = get_option( self::option_key( $competition_id ), array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$data = array_merge( self::defaults(), $stored );

		return array_intersect_key( $data, self::defaults() );
	}

	public static function save( int $competition_id, array $data ): bool {
		$competition_id = absint( $competition_id );
		if ( ! $competition_id ) {
			return false;
		}

		$clean = self::sanitize_fields( $data );

		return (bool) update_option( self::option_key( $competition_id ), $clean, false );
	}

	public static function apply_to_competition( $competition, ?array $meta = null ): void {
		if ( ! $competition || ! is_object( $competition ) ) {
			return;
		}

		if ( null === $meta ) {
			$meta = self::get( (int) ( $competition->id ?? 0 ) );
		}

		foreach ( $meta as $key => $value ) {
			$competition->{$key} = $value;
		}
	}

	private static function defaults(): array {
		return array(
			'lieu_name'               => '',
			'lieu_address'            => '',
			'weighin_start'           => '',
			'weighin_end'             => '',
			'registration_deadline'   => '',
			'organizer_contact_name'  => '',
			'organizer_phone'         => '',
			'organizer_email'         => '',
			'club_notes'              => '',
		);
	}

	private static function sanitize_fields( array $data ): array {
		$out = self::defaults();

		if ( isset( $data['lieu_name'] ) ) {
			$out['lieu_name'] = sanitize_text_field( (string) $data['lieu_name'] );
		}
		if ( isset( $data['lieu_address'] ) ) {
			$out['lieu_address'] = sanitize_textarea_field( (string) $data['lieu_address'] );
		}
		if ( isset( $data['weighin_start'] ) ) {
			$out['weighin_start'] = self::sanitize_datetime( (string) $data['weighin_start'] );
		}
		if ( isset( $data['weighin_end'] ) ) {
			$out['weighin_end'] = self::sanitize_datetime( (string) $data['weighin_end'] );
		}
		if ( isset( $data['registration_deadline'] ) ) {
			$out['registration_deadline'] = self::sanitize_datetime( (string) $data['registration_deadline'] );
		}
		if ( isset( $data['organizer_contact_name'] ) ) {
			$out['organizer_contact_name'] = sanitize_text_field( (string) $data['organizer_contact_name'] );
		}
		if ( isset( $data['organizer_phone'] ) ) {
			$out['organizer_phone'] = sanitize_text_field( (string) $data['organizer_phone'] );
		}
		if ( isset( $data['organizer_email'] ) ) {
			$out['organizer_email'] = sanitize_email( (string) $data['organizer_email'] );
		}
		if ( isset( $data['club_notes'] ) ) {
			$out['club_notes'] = sanitize_textarea_field( (string) $data['club_notes'] );
		}

		return $out;
	}

	private static function sanitize_datetime( string $value ): string {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return '';
		}

		$date = date_create_from_format( 'Y-m-d H:i:s', $value );
		if ( ! $date instanceof \DateTimeInterface ) {
			return '';
		}

		return $date->format( 'Y-m-d H:i:s' ) === $value ? $value : '';
	}

	private static function option_key( int $competition_id ): string {
		return self::OPTION_PREFIX . absint( $competition_id );
	}
}
