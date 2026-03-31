<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Entries\ParticipantTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExternalParticipantEligibility {
	public static function resolve_type( $entry, ?array $external_data = null ): string {
		if ( is_array( $external_data ) && ! empty( $external_data['participant_type'] ) ) {
			return ParticipantTypes::normalize( (string) $external_data['participant_type'] );
		}

		if ( is_object( $entry ) && ! empty( $entry->participant_type ) ) {
			return ParticipantTypes::normalize( (string) $entry->participant_type );
		}

		return ParticipantTypes::get_default();
	}

	public static function is_external_entry_allowed( array $competition_meta ): bool {
		return ! empty( $competition_meta['allow_external_non_licensed'] );
	}
}
