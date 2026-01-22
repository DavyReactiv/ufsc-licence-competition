<?php

namespace UFSC\Competitions\Entries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntriesWorkflow {
	public static function get_status_labels(): array {
		$statuses = array(
			'draft' => __( 'Brouillon', 'ufsc-licence-competition' ),
			'submitted' => __( 'Soumise', 'ufsc-licence-competition' ),
			'validated' => __( 'Validée', 'ufsc-licence-competition' ),
			'rejected' => __( 'Rejetée', 'ufsc-licence-competition' ),
			'cancelled' => __( 'Annulée', 'ufsc-licence-competition' ),
			'withdrawn' => __( 'Annulée', 'ufsc-licence-competition' ),
		);

		$filtered = apply_filters( 'ufsc_entries_allowed_statuses', array_keys( $statuses ) );
		if ( is_array( $filtered ) ) {
			$filtered = array_map( 'sanitize_key', $filtered );
			$statuses = array_intersect_key( $statuses, array_flip( $filtered ) );
		}

		return $statuses;
	}

	public static function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		if ( 'withdrawn' === $status ) {
			$status = 'cancelled';
		}

		$labels = self::get_status_labels();
		if ( ! isset( $labels[ $status ] ) ) {
			return 'draft';
		}

		return $status;
	}

	public static function get_status_label( string $status ): string {
		$status = self::normalize_status( $status );
		$labels = self::get_status_labels();

		return $labels[ $status ] ?? $status;
	}

	public static function get_status_badge_class( string $status ): string {
		$status = self::normalize_status( $status );

		switch ( $status ) {
			case 'submitted':
				return 'ufsc-badge--warning';
			case 'validated':
				return 'ufsc-badge--success';
			case 'rejected':
				return 'ufsc-badge--danger';
			case 'cancelled':
				return 'ufsc-badge--muted';
			default:
				return 'ufsc-badge--info';
		}
	}

	public static function get_timeline_label( string $status ): string {
		$status = self::normalize_status( $status );

		switch ( $status ) {
			case 'draft':
				return __( 'Brouillon → Soumise → Validée/Rejetée', 'ufsc-licence-competition' );
			case 'submitted':
				return __( 'Brouillon → Soumise (en attente) → Validée/Rejetée', 'ufsc-licence-competition' );
			case 'validated':
				return __( 'Brouillon → Soumise → Validée', 'ufsc-licence-competition' );
			case 'rejected':
				return __( 'Brouillon → Soumise → Rejetée', 'ufsc-licence-competition' );
			case 'cancelled':
				return __( 'Brouillon → Soumise → Annulée', 'ufsc-licence-competition' );
			default:
				return __( 'Brouillon → Soumise → Validée/Rejetée', 'ufsc-licence-competition' );
		}
	}
}
