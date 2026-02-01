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
			'pending' => __( 'En attente', 'ufsc-licence-competition' ),
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
		$slug = self::normalize_status_slug( $status );

		switch ( $slug ) {
			case 'soumise':
			case 'soumis':
			case 'submitted':
				return 'submitted';
			case 'pending':
			case 'en_attente':
			case 'en_attente_validation':
				return 'pending';
			case 'validee':
			case 'valide':
			case 'validated':
			case 'approved':
			case 'approuvee':
			case 'approuve':
				return 'validated';
			case 'rejected':
			case 'refusee':
			case 'refuse':
				return 'rejected';
			case 'withdrawn':
			case 'cancelled':
			case 'annulee':
			case 'annule':
				return 'cancelled';
			case 'draft':
			case 'brouillon':
				return 'draft';
			default:
				$labels = self::get_status_labels();
				if ( isset( $labels[ $slug ] ) ) {
					return $slug;
				}

				return 'draft';
		}
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
			case 'pending':
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
			case 'pending':
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

	private static function normalize_status_slug( string $status ): string {
		$status = trim( (string) $status );
		if ( '' === $status ) {
			return '';
		}

		$status = strtolower( $status );
		$replace = array(
			'á' => 'a',
			'à' => 'a',
			'â' => 'a',
			'ä' => 'a',
			'é' => 'e',
			'è' => 'e',
			'ê' => 'e',
			'ë' => 'e',
			'í' => 'i',
			'ì' => 'i',
			'î' => 'i',
			'ï' => 'i',
			'ó' => 'o',
			'ò' => 'o',
			'ô' => 'o',
			'ö' => 'o',
			'ú' => 'u',
			'ù' => 'u',
			'û' => 'u',
			'ü' => 'u',
			'ç' => 'c',
		);
		$status = strtr( $status, $replace );
		$status = preg_replace( '/[^a-z0-9]+/', '_', $status );
		$status = trim( (string) $status, '_' );

		return sanitize_key( $status );
	}
}
