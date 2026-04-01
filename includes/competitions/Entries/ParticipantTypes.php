<?php

namespace UFSC\Competitions\Entries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ParticipantTypes {
	public const LICENSED_UFSC = 'licensed_ufsc';
	public const EXTERNAL_NON_LICENSED = 'external_non_licensed';

	public static function get_default(): string {
		return self::LICENSED_UFSC;
	}

	public static function all(): array {
		return array(
			self::LICENSED_UFSC,
			self::EXTERNAL_NON_LICENSED,
		);
	}

	public static function normalize( string $type ): string {
		$type = sanitize_key( $type );
		if ( in_array( $type, self::all(), true ) ) {
			return $type;
		}

		return self::get_default();
	}

	public static function is_external( string $type ): bool {
		return self::EXTERNAL_NON_LICENSED === self::normalize( $type );
	}
}
