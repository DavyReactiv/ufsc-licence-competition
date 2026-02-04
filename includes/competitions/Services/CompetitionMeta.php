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

	public static function has_rules( int $competition_id ): bool {
		$competition_id = absint( $competition_id );
		if ( ! $competition_id ) {
			return false;
		}

		$stored = get_option( self::option_key( $competition_id ), null );
		if ( ! is_array( $stored ) ) {
			return false;
		}

		return ! empty( $stored );
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
			'briefing_time'           => '',
			'fights_start'            => '',
			'event_end_estimated'     => '',
			'registration_deadline'   => '',
			'organizer_contact_name'  => '',
			'organizer_phone'         => '',
			'organizer_email'         => '',
			'club_notes'              => '',
			'access_mode'             => 'affiliated',
			'allowed_regions'         => array(),
			'allowed_disciplines'     => array(),
			'allowed_club_ids'        => array(),
			'public_read'             => false,
			'require_affiliated'      => true,
			'require_logged_in_club'  => true,
			'require_valid_license'   => false,
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
		if ( isset( $data['briefing_time'] ) ) {
			$out['briefing_time'] = self::sanitize_datetime( (string) $data['briefing_time'] );
		}
		if ( isset( $data['fights_start'] ) ) {
			$out['fights_start'] = self::sanitize_datetime( (string) $data['fights_start'] );
		}
		if ( isset( $data['event_end_estimated'] ) ) {
			$out['event_end_estimated'] = self::sanitize_datetime( (string) $data['event_end_estimated'] );
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
		if ( isset( $data['access_mode'] ) ) {
			$out['access_mode'] = sanitize_key( (string) $data['access_mode'] );
		}
		if ( isset( $data['allowed_regions'] ) ) {
			$out['allowed_regions'] = self::sanitize_string_array( $data['allowed_regions'] );
		}
		if ( isset( $data['allowed_disciplines'] ) ) {
			$out['allowed_disciplines'] = self::sanitize_key_array( $data['allowed_disciplines'] );
		}
		if ( isset( $data['allowed_club_ids'] ) ) {
			$out['allowed_club_ids'] = self::sanitize_int_array( $data['allowed_club_ids'] );
		}
		if ( isset( $data['public_read'] ) ) {
			$out['public_read'] = (bool) $data['public_read'];
		}
		if ( isset( $data['require_affiliated'] ) ) {
			$out['require_affiliated'] = (bool) $data['require_affiliated'];
		}
		if ( isset( $data['require_logged_in_club'] ) ) {
			$out['require_logged_in_club'] = (bool) $data['require_logged_in_club'];
		}
		if ( isset( $data['require_valid_license'] ) ) {
			$out['require_valid_license'] = (bool) $data['require_valid_license'];
		}

		return $out;
	}

	private static function sanitize_string_array( $values ): array {
		if ( ! is_array( $values ) ) {
			$values = array( $values );
		}

		$out = array();
		foreach ( $values as $value ) {
			$value = is_scalar( $value ) ? (string) $value : '';
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}
			$out[] = sanitize_text_field( $value );
		}

		return array_values( array_unique( $out ) );
	}

	private static function sanitize_key_array( $values ): array {
		if ( ! is_array( $values ) ) {
			$values = array( $values );
		}

		$out = array();
		foreach ( $values as $value ) {
			$value = sanitize_key( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$out[] = $value;
		}

		return array_values( array_unique( $out ) );
	}

	private static function sanitize_int_array( $values ): array {
		if ( ! is_array( $values ) ) {
			$values = array( $values );
		}

		$out = array();
		foreach ( $values as $value ) {
			$id = absint( $value );
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}

		return array_values( array_unique( $out ) );
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
