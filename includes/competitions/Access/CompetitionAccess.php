<?php

namespace UFSC\Competitions\Access;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;
use UFSC\Competitions\Repositories\ClubRepository;
use UFSC\Competitions\Services\CompetitionMeta;
use UFSC\Competitions\Services\DisciplineRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionAccess {
	private const MODE_AFFILIATED = 'affiliated';
	private const MODE_ALL_CLUBS = 'all_clubs';
	private const MODE_CLUBS = 'clubs';
	private const MODE_REGIONS = 'regions';
	private const MODE_DISCIPLINES = 'disciplines';
	private const MODE_REGION_DISCIPLINE = 'region_discipline';

	public function can_view_competition( int $competition_id, int $club_id = 0, int $user_id = 0 ): AccessResult {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $this->is_admin_bypass( $user_id ) ) {
			return AccessResult::allow( array( 'scope' => 'view', 'admin' => true ) );
		}

		$settings = $this->get_settings( $competition_id );

		if ( $settings['public_read'] ) {
			$result = AccessResult::allow( array( 'scope' => 'view', 'public_read' => true ) );
			$this->debug_log( 'view', $competition_id, $user_id, $club_id, $settings, $result );
			return $result;
		}

		$result = $this->evaluate_access( $competition_id, $settings, $club_id, $user_id, 'view' );
		$this->debug_log( 'view', $competition_id, $user_id, $club_id, $settings, $result );

		return $result;
	}

	public function can_register( int $competition_id, int $club_id = 0, int $user_id = 0 ): AccessResult {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $this->is_admin_bypass( $user_id ) ) {
			return AccessResult::allow( array( 'scope' => 'register', 'admin' => true ) );
		}

		$settings = $this->get_settings( $competition_id );
		$result = $this->evaluate_access( $competition_id, $settings, $club_id, $user_id, 'register' );
		if ( ! $result->allowed ) {
			$this->debug_log( 'register', $competition_id, $user_id, $club_id, $settings, $result );
			return $result;
		}

		if ( ! $this->is_registration_open( $competition_id, $result->context['club_id'] ?? 0 ) ) {
			$result = AccessResult::deny( 'registration_closed', array( 'scope' => 'register' ) );
			$this->debug_log( 'register', $competition_id, $user_id, $club_id, $settings, $result );
			return $result;
		}

		if ( $settings['require_valid_license'] && ! $this->has_valid_license( $competition_id, $result->context['club_id'] ?? 0, $user_id ) ) {
			$result = AccessResult::deny( 'invalid_license', array( 'scope' => 'register' ) );
			$this->debug_log( 'register', $competition_id, $user_id, $club_id, $settings, $result );
			return $result;
		}

		$this->debug_log( 'register', $competition_id, $user_id, $club_id, $settings, $result );
		return $result;
	}

	public function get_denied_message( AccessResult $result ): string {
		$scope = $result->context['scope'] ?? '';

		switch ( $result->reason_code ) {
			case 'not_logged_in':
				return 'register' === $scope
					? __( 'Vous devez être connecté pour vous inscrire à cette compétition.', 'ufsc-licence-competition' )
					: __( 'Vous devez être connecté pour accéder à cette compétition.', 'ufsc-licence-competition' );
			case 'not_club':
				return __( 'Accès réservé : aucun club associé à votre compte.', 'ufsc-licence-competition' );
			case 'not_affiliated':
				return __( 'Accès réservé aux clubs affiliés UFSC.', 'ufsc-licence-competition' );
			case 'club_not_allowed':
				return __( 'Accès réservé : votre club ne fait pas partie des clubs autorisés pour cette compétition.', 'ufsc-licence-competition' );
			case 'region_mismatch':
				$regions = $this->format_list( $result->context['allowed_regions'] ?? array() );
				if ( $regions ) {
					return sprintf(
						/* translators: %s: regions list */
						__( 'Accès réservé aux clubs de la région : %s.', 'ufsc-licence-competition' ),
						$regions
					);
				}
				return __( 'Accès réservé aux clubs de la région autorisée.', 'ufsc-licence-competition' );
			case 'discipline_mismatch':
				$disciplines = $this->format_list( $result->context['allowed_disciplines_labels'] ?? array() );
				if ( $disciplines ) {
					return sprintf(
						/* translators: %s: disciplines list */
						__( 'Accès réservé aux clubs de la discipline : %s.', 'ufsc-licence-competition' ),
						$disciplines
					);
				}
				return __( 'Accès réservé aux clubs de la discipline autorisée.', 'ufsc-licence-competition' );
			case 'registration_closed':
				return __( 'Les inscriptions sont fermées pour cette compétition.', 'ufsc-licence-competition' );
			case 'invalid_license':
				return __( 'Accès réservé : une licence valide est requise pour s’inscrire.', 'ufsc-licence-competition' );
			default:
				return __( 'Accès refusé.', 'ufsc-licence-competition' );
		}
	}

	public function get_access_summary( int $competition_id ): string {
		$settings = $this->get_settings( $competition_id );

		$labels = array();
		if ( $settings['public_read'] ) {
			$labels[] = __( 'Public (lecture)', 'ufsc-licence-competition' );
		}

		switch ( $settings['access_mode'] ) {
			case self::MODE_ALL_CLUBS:
				$labels[] = __( 'Tous les clubs', 'ufsc-licence-competition' );
				break;
			case self::MODE_CLUBS:
				$labels[] = __( 'Clubs sélectionnés', 'ufsc-licence-competition' );
				break;
			case self::MODE_REGIONS:
				$labels[] = __( 'Par régions', 'ufsc-licence-competition' );
				break;
			case self::MODE_DISCIPLINES:
				$labels[] = __( 'Par disciplines', 'ufsc-licence-competition' );
				break;
			case self::MODE_REGION_DISCIPLINE:
				$labels[] = __( 'Région + discipline', 'ufsc-licence-competition' );
				break;
			case self::MODE_AFFILIATED:
			default:
				$labels[] = __( 'Clubs affiliés', 'ufsc-licence-competition' );
				break;
		}

		if ( $settings['require_affiliated'] && self::MODE_AFFILIATED !== $settings['access_mode'] ) {
			$labels[] = __( 'Affiliation requise', 'ufsc-licence-competition' );
		}

		return implode( ' · ', array_filter( $labels ) );
	}

	private function evaluate_access( int $competition_id, array $settings, int $club_id, int $user_id, string $scope ): AccessResult {
		$club_id = $club_id ?: $this->resolve_club_id( $user_id );

		if ( $settings['require_logged_in_club'] && ! $user_id ) {
			return AccessResult::deny( 'not_logged_in', array( 'scope' => $scope ) );
		}

		$mode = $settings['access_mode'];
		$requires_club = $settings['require_logged_in_club'] || in_array(
			$mode,
			array(
				self::MODE_AFFILIATED,
				self::MODE_ALL_CLUBS,
				self::MODE_CLUBS,
				self::MODE_REGIONS,
				self::MODE_DISCIPLINES,
				self::MODE_REGION_DISCIPLINE,
			),
			true
		);

		if ( $requires_club && ! $club_id ) {
			return AccessResult::deny( 'not_club', array( 'scope' => $scope ) );
		}

		$club_repo = new ClubRepository();
		$club = $club_id ? $club_repo->get( $club_id ) : null;
		if ( $requires_club && ! $club ) {
			return AccessResult::deny( 'not_club', array( 'scope' => $scope, 'club_id' => $club_id ) );
		}

		if ( $settings['require_affiliated'] && ! $this->is_affiliated( $user_id, $club_id ) ) {
			return AccessResult::deny( 'not_affiliated', array( 'scope' => $scope, 'club_id' => $club_id ) );
		}

		$context = array(
			'scope' => $scope,
			'club_id' => $club_id,
			'club_region' => $club ? (string) ( $club->region ?? '' ) : '',
		);

		switch ( $mode ) {
			case self::MODE_ALL_CLUBS:
				return AccessResult::allow( $context );
			case self::MODE_CLUBS:
				if ( in_array( $club_id, $settings['allowed_club_ids'], true ) ) {
					return AccessResult::allow( $context );
				}
				return AccessResult::deny( 'club_not_allowed', $context );
			case self::MODE_REGIONS:
				if ( $this->club_matches_region( $club, $settings['allowed_regions'] ) ) {
					return AccessResult::allow( $context );
				}
				return AccessResult::deny(
					'region_mismatch',
					array_merge(
						$context,
						array(
							'allowed_regions' => $settings['allowed_regions'],
						)
					)
				);
			case self::MODE_DISCIPLINES:
				if ( $this->club_matches_disciplines( $club, $settings['allowed_disciplines'] ) ) {
					return AccessResult::allow( $context );
				}
				return AccessResult::deny(
					'discipline_mismatch',
					array_merge(
						$context,
						array(
							'allowed_disciplines' => $settings['allowed_disciplines'],
							'allowed_disciplines_labels' => $this->get_discipline_labels( $settings['allowed_disciplines'] ),
						)
					)
				);
			case self::MODE_REGION_DISCIPLINE:
				$region_ok = $this->club_matches_region( $club, $settings['allowed_regions'] );
				$discipline_ok = $this->club_matches_disciplines( $club, $settings['allowed_disciplines'] );
				if ( $region_ok && $discipline_ok ) {
					return AccessResult::allow( $context );
				}
				$reason = $region_ok ? 'discipline_mismatch' : 'region_mismatch';
				$extra_context = array(
					'allowed_regions' => $settings['allowed_regions'],
					'allowed_disciplines' => $settings['allowed_disciplines'],
					'allowed_disciplines_labels' => $this->get_discipline_labels( $settings['allowed_disciplines'] ),
				);
				return AccessResult::deny( $reason, array_merge( $context, $extra_context ) );
			case self::MODE_AFFILIATED:
			default:
				return AccessResult::allow( $context );
		}
	}

	private function get_settings( int $competition_id ): array {
		$meta = CompetitionMeta::get( $competition_id );

		$access_mode = sanitize_key( (string) ( $meta['access_mode'] ?? self::MODE_AFFILIATED ) );
		$allowed_modes = array(
			self::MODE_AFFILIATED,
			self::MODE_ALL_CLUBS,
			self::MODE_CLUBS,
			self::MODE_REGIONS,
			self::MODE_DISCIPLINES,
			self::MODE_REGION_DISCIPLINE,
		);
		if ( ! in_array( $access_mode, $allowed_modes, true ) ) {
			$access_mode = self::MODE_AFFILIATED;
		}

		$allowed_regions = $this->normalize_string_list( $meta['allowed_regions'] ?? array() );
		$allowed_disciplines = $this->normalize_string_list( $meta['allowed_disciplines'] ?? array(), true );
		$allowed_club_ids = $this->normalize_int_list( $meta['allowed_club_ids'] ?? array() );

		return array(
			'access_mode' => $access_mode,
			'allowed_regions' => $allowed_regions,
			'allowed_disciplines' => $allowed_disciplines,
			'allowed_club_ids' => $allowed_club_ids,
			'public_read' => ! empty( $meta['public_read'] ),
			'require_affiliated' => isset( $meta['require_affiliated'] ) ? (bool) $meta['require_affiliated'] : true,
			'require_logged_in_club' => isset( $meta['require_logged_in_club'] ) ? (bool) $meta['require_logged_in_club'] : true,
			'require_valid_license' => ! empty( $meta['require_valid_license'] ),
		);
	}

	private function resolve_user_id( int $user_id ): int {
		if ( $user_id > 0 ) {
			return $user_id;
		}

		return is_user_logged_in() ? (int) get_current_user_id() : 0;
	}

	private function resolve_club_id( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		if ( function_exists( 'ufsc_get_current_club_id' ) ) {
			return (int) ufsc_get_current_club_id( $user_id );
		}

		return 0;
	}

	private function is_admin_bypass( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( class_exists( Capabilities::class ) && Capabilities::user_can_manage() ) {
			return true;
		}

		return false;
	}

	private function is_affiliated( int $user_id, int $club_id ): bool {
		if ( $user_id <= 0 || $club_id <= 0 ) {
			return false;
		}

		$required_capability = class_exists( 'UFSC_LC_Settings_Page' ) ? \UFSC_LC_Settings_Page::get_club_access_capability() : '';
		$required_capability = apply_filters( 'ufsc_competitions_access_affiliation_capability', $required_capability, $club_id, $user_id );

		if ( '' === $required_capability ) {
			return true;
		}

		return user_can( $user_id, $required_capability );
	}

	private function club_matches_region( $club, array $allowed_regions ): bool {
		if ( ! is_object( $club ) ) {
			return false;
		}

		if ( empty( $allowed_regions ) ) {
			return false;
		}

		$club_region = strtolower( trim( (string) ( $club->region ?? '' ) ) );
		if ( '' === $club_region ) {
			return false;
		}

		foreach ( $allowed_regions as $region ) {
			if ( strtolower( $region ) === $club_region ) {
				return true;
			}
		}

		return false;
	}

	private function club_matches_disciplines( $club, array $allowed_disciplines ): bool {
		if ( ! is_object( $club ) ) {
			return false;
		}

		if ( empty( $allowed_disciplines ) ) {
			return false;
		}

		$club_disciplines = $this->extract_club_disciplines( $club );
		if ( empty( $club_disciplines ) ) {
			return false;
		}

		return (bool) array_intersect( $club_disciplines, $allowed_disciplines );
	}

	private function extract_club_disciplines( $club ): array {
		if ( ! is_object( $club ) ) {
			return array();
		}

		$raw = '';
		if ( isset( $club->discipline ) ) {
			$raw = (string) $club->discipline;
		} elseif ( isset( $club->disciplines ) ) {
			$raw = (string) $club->disciplines;
		}

		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}

		$parts = array_map( 'trim', preg_split( '/[;,]/', $raw ) );
		$parts = array_filter( $parts );

		$disciplines = array();
		foreach ( $parts as $part ) {
			$key = sanitize_key( $part );
			if ( '' !== $key ) {
				$disciplines[] = $key;
			}
		}

		return array_values( array_unique( $disciplines ) );
	}

	private function normalize_string_list( $values, bool $use_keys = false ): array {
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
			$clean = $use_keys ? sanitize_key( $value ) : sanitize_text_field( $value );
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}

		return array_values( array_unique( $out ) );
	}

	private function normalize_int_list( $values ): array {
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

	private function format_list( array $values ): string {
		$values = array_filter( array_map( 'trim', $values ) );
		if ( empty( $values ) ) {
			return '';
		}

		return implode( ' / ', $values );
	}

	private function get_discipline_labels( array $disciplines ): array {
		$labels = array();
		foreach ( $disciplines as $discipline ) {
			$labels[] = DisciplineRegistry::get_label( $discipline );
		}

		return $labels;
	}

	private function is_registration_open( int $competition_id, int $club_id = 0 ): bool {
		if ( ! class_exists( CompetitionReadRepository::class ) ) {
			return false;
		}

		$repo = new CompetitionReadRepository();
		$competition = $repo->get( $competition_id );
		if ( ! $competition ) {
			return false;
		}

		if ( class_exists( '\UFSC\\Competitions\\Front\\Entries\\EntriesModule' ) ) {
			return \UFSC\Competitions\Front\Entries\EntriesModule::is_registration_open( $competition, $club_id );
		}

		if ( empty( $competition->status ) || 'open' !== (string) $competition->status ) {
			return false;
		}

		return true;
	}

	private function has_valid_license( int $competition_id, int $club_id, int $user_id ): bool {
		$result = apply_filters( 'ufsc_competitions_access_valid_license', true, $competition_id, $club_id, $user_id );
		return (bool) $result;
	}

	private function debug_log( string $scope, int $competition_id, int $user_id, int $club_id, array $settings, AccessResult $result ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		if ( ! Capabilities::user_can_manage() ) {
			return;
		}

		$payload = array(
			'scope' => $scope,
			'competition_id' => $competition_id,
			'user_id' => $user_id,
			'club_id' => $club_id,
			'access_mode' => $settings['access_mode'] ?? '',
			'public_read' => $settings['public_read'] ?? false,
			'allowed_regions' => $settings['allowed_regions'] ?? array(),
			'allowed_disciplines' => $settings['allowed_disciplines'] ?? array(),
			'allowed_club_ids' => $settings['allowed_club_ids'] ?? array(),
			'require_affiliated' => $settings['require_affiliated'] ?? false,
			'require_logged_in_club' => $settings['require_logged_in_club'] ?? false,
			'require_valid_license' => $settings['require_valid_license'] ?? false,
			'result_allowed' => $result->allowed,
			'reason_code' => $result->reason_code,
			'context' => $result->context,
		);

		error_log( 'UFSC Competitions access: ' . wp_json_encode( $payload ) );
	}
}
