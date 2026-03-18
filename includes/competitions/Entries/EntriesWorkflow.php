<?php

namespace UFSC\Competitions\Entries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntriesWorkflow {
	public static function get_storage_statuses(): array {
		return array( 'draft', 'submitted', 'pending', 'approved', 'rejected', 'cancelled' );
	}

	public static function get_status_labels(): array {
		$statuses = array(
			'draft'     => __( 'Brouillon', 'ufsc-licence-competition' ),
			'submitted' => __( 'Soumise (club)', 'ufsc-licence-competition' ),
			'pending'   => __( 'En attente admin', 'ufsc-licence-competition' ),
			'approved'  => __( 'Validée (engagée)', 'ufsc-licence-competition' ),
			'rejected'  => __( 'Rejetée', 'ufsc-licence-competition' ),
			'cancelled' => __( 'Annulée', 'ufsc-licence-competition' ),
		);

		$filtered = apply_filters( 'ufsc_entries_allowed_statuses', self::get_storage_statuses() );
		if ( is_array( $filtered ) ) {
			$filtered = array_map( 'sanitize_key', $filtered );
			$statuses = array_intersect_key( $statuses, array_flip( $filtered ) );
		}

		return $statuses;
	}

	public static function get_filter_status_labels(): array {
		return array(
			'review_queue' => __( 'À valider (Soumise + En attente)', 'ufsc-licence-competition' ),
		) + self::get_status_labels();
	}

	public static function get_review_queue_statuses(): array {
		return array( 'submitted', 'pending' );
	}

	public static function get_allowed_transitions(): array {
		return array(
			'draft'     => array( 'submitted', 'cancelled' ),
			'submitted' => array( 'pending', 'approved', 'rejected', 'draft', 'cancelled' ),
			'pending'   => array( 'approved', 'rejected', 'draft', 'cancelled' ),
			'approved'  => array(),
			'rejected'  => array( 'draft' ),
			'cancelled' => array(),
		);
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
			case 'ready':
			case 'prete_au_tirage':
			case 'weighed':
			case 'weigh_in_ok':
			case 'pesee_ok':
			case 'approved':
			case 'approuvee':
			case 'approuve':
				return 'approved';
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
			case 'approved':
				return 'ufsc-badge--success';
			case 'rejected':
				return 'ufsc-badge--danger';
			case 'cancelled':
				return 'ufsc-badge--muted';
			default:
				return 'ufsc-badge--info';
		}
	}

	public static function get_status_variants( string $status ): array {
		$normalized = self::normalize_status( $status );
		$slug       = self::normalize_status_slug( $status );
		if ( 'review_queue' === $slug ) {
			return array_values(
				array_unique(
					array_merge(
						self::get_status_variants( 'submitted' ),
						self::get_status_variants( 'pending' )
					)
				)
			);
		}

		$variants_map = array(
			'submitted' => array(
				'submitted',
				'Soumise',
				'soumise',
				'Soumis',
				'soumis',
			),
			'pending' => array(
				'pending',
				'En attente',
				'en attente',
				'En attente validation',
				'en attente validation',
				'en_attente_validation',
				'en-attente-validation',
				'en_attente',
				'en-attente',
			),
			'approved' => array(
				'approved',
				'Approuvée',
				'approuvee',
				'approuvée',
				'validated',
				'Validée',
				'validee',
				'validée',
			),
			'rejected' => array(
				'rejected',
				'Rejetée',
				'rejetee',
				'rejetée',
				'Refusée',
				'refusee',
				'refusée',
			),
			'cancelled' => array(
				'cancelled',
				'withdrawn',
				'Annulée',
				'annulee',
				'annulée',
			),
			'draft' => array(
				'draft',
				'Brouillon',
				'brouillon',
			),
		);

		return $variants_map[ $normalized ] ?? $variants_map['draft'];
	}

	public static function get_timeline_label( string $status ): string {
		$status = self::normalize_status( $status );

		switch ( $status ) {
			case 'draft':
				return __( 'Brouillon → Soumise → Approuvée/Rejetée', 'ufsc-licence-competition' );
			case 'submitted':
			case 'pending':
				return __( 'Brouillon → Soumise (en attente) → Approuvée/Rejetée', 'ufsc-licence-competition' );
			case 'approved':
				return __( 'Brouillon → Soumise → Approuvée', 'ufsc-licence-competition' );
			case 'rejected':
				return __( 'Brouillon → Soumise → Rejetée', 'ufsc-licence-competition' );
			case 'cancelled':
				return __( 'Brouillon → Soumise → Annulée', 'ufsc-licence-competition' );
			default:
				return __( 'Brouillon → Soumise → Approuvée/Rejetée', 'ufsc-licence-competition' );
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
