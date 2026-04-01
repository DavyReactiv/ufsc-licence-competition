<?php

namespace UFSC\Competitions\Front\Entries;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Access\AccessResult;
use UFSC\Competitions\Access\CompetitionAccess;
use UFSC\Competitions\Front\Front;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Entries\ParticipantTypes;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Services\EntryDeduplication;
use UFSC\Competitions\Services\ExternalParticipantEligibility;
use UFSC\Competitions\Services\ExternalParticipantService;
use UFSC\Competitions\Services\ExternalParticipantValidator;
use UFSC\Competitions\Services\CompetitionMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryActions {

	public static function handle_create(): void {
		self::handle_action( 'create' );
	}

	public static function handle_update(): void {
		self::handle_action( 'update' );
	}

	public static function handle_delete(): void {
		self::handle_action( 'delete' );
	}

	public static function handle_submit(): void {
		self::handle_status_action( 'submit' );
	}

	public static function handle_withdraw(): void {
		self::handle_status_action( 'withdraw' );
	}

	public static function handle_cancel(): void {
		self::handle_status_action( 'cancel' );
	}

	public static function handle_admin_validate(): void {
		self::handle_admin_action( 'validate' );
	}

	public static function handle_admin_reject(): void {
		self::handle_admin_action( 'reject' );
	}

	public static function handle_admin_reopen(): void {
		self::handle_admin_action( 'reopen' );
	}

	public static function handle_not_logged_in(): void {
		$competition_id = isset( $_REQUEST['competition_id'] ) ? absint( $_REQUEST['competition_id'] ) : 0;
		self::redirect_with_notice( $competition_id, 'error_forbidden' );
	}

	private static function handle_action( string $action ): void {
		if ( ! is_user_logged_in() ) {
			self::redirect_with_notice( 0, 'error_forbidden' );
		}

		self::debug_log( 'entry_action_start', array( 'action' => $action ) );

		// Capability (club access) - avoid namespace issues by referencing the global class.
		$required_capability = class_exists( '\\UFSC_LC_Settings_Page' ) ? \UFSC_LC_Settings_Page::get_club_access_capability() : '';
		$required_capability = apply_filters( 'ufsc_competitions_front_entry_capability', $required_capability );

		if ( $required_capability && ! current_user_can( $required_capability ) ) {
			self::redirect_with_notice( 0, 'error_forbidden' );
		}

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$competition    = EntriesModule::get_competition( $competition_id );

		if ( ! $competition || empty( $competition->id ) ) {
			self::redirect_with_notice( $competition_id, 'error_not_found' );
		}

		$user_id = (int) get_current_user_id();
		$club_id = function_exists( 'ufsc_lc_get_current_club_id' ) ? (int) ufsc_lc_get_current_club_id( $user_id ) : 0;
		self::debug_log(
			'entry_action_access_context',
			array(
				'action'         => $action,
				'user_id'        => $user_id,
				'club_id'        => $club_id,
				'competition_id' => $competition_id,
			)
		);

		if ( ! $club_id ) {
			self::redirect_with_notice( $competition_id, 'error_forbidden' );
		}

		$access = new CompetitionAccess();
		$access_result = $access->can_register( $competition_id, (int) $club_id, $user_id );
		if ( ! $access_result->allowed ) {
			self::redirect_with_notice( $competition_id, 'access_denied', $access_result );
		}

		if ( 'create' === $action ) {
			self::verify_nonce_or_redirect( 'ufsc_competitions_entry_create', $competition_id );
		} elseif ( 'update' === $action ) {
			self::verify_nonce_or_redirect( 'ufsc_competitions_entry_update', $competition_id );
		} else {
			self::verify_nonce_or_redirect( 'ufsc_competitions_entry_delete', $competition_id );
		}

		$repo     = new EntryFrontRepository();
		$entry    = null;
		$entry_id = 0;

		if ( 'update' === $action || 'delete' === $action ) {
			$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
			$entry    = $entry_id ? $repo->get( $entry_id ) : null;

			if ( ! $entry ) {
				self::redirect_with_notice( $competition_id, 'error_not_found' );
			}

			if ( absint( $entry->club_id ?? 0 ) !== absint( $club_id ) ) {
				self::redirect_with_notice( $competition_id, 'error_forbidden' );
			}

			if ( absint( $entry->competition_id ?? 0 ) !== absint( $competition_id ) ) {
				self::redirect_with_notice( $competition_id, 'error_forbidden' );
			}

			$status = (string) $repo->get_entry_status( $entry );
			$status = EntriesWorkflow::normalize_status( $status );

			if ( 'draft' !== $status ) {
				self::redirect_with_notice( $competition_id, 'error_locked' );
			}
		}

		if ( 'delete' === $action ) {
			$result = $repo->delete( $entry_id );
			if ( $result ) {
				do_action( 'ufsc_competitions_entry_after_delete', $entry_id, $entry, $competition, $club_id );
			}
			self::redirect_with_notice( $competition_id, $result ? 'entry_deleted' : 'error' );
		}

		$license_id        = isset( $_POST['ufsc_license_id'] ) ? absint( $_POST['ufsc_license_id'] ) : 0;
		$license_term      = isset( $_POST['ufsc_license_term'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_license_term'] ) ) : '';
		$license_number    = isset( $_POST['ufsc_license_number'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_license_number'] ) ) : '';
		$license_birthdate = isset( $_POST['ufsc_license_birthdate'] ) ? sanitize_text_field( wp_unslash( $_POST['ufsc_license_birthdate'] ) ) : '';
		$license_birthdate = self::normalize_birthdate_input( $license_birthdate );

		$license = null;
		self::debug_log(
			'entry_action_license_input',
			array(
				'action'         => $action,
				'competition_id' => $competition_id,
				'club_id'        => $club_id,
				'license_id'     => $license_id,
				'license_term'   => $license_term,
				'license_number' => $license_number,
				'license_birthdate' => $license_birthdate,
			)
		);

		if ( $license_id ) {
			$license_data = apply_filters( 'ufsc_competitions_front_license_by_id', null, $license_id, $club_id );
			if ( is_array( $license_data ) ) {
				$license = $repo->normalize_license_result( $license_data );
			}
		}

		if ( $license_id && ! $license ) {
			self::debug_log(
				'entry_action_license_lookup_failed',
				array(
					'action'         => $action,
					'competition_id' => $competition_id,
					'club_id'        => $club_id,
					'license_id'     => $license_id,
				)
			);
			self::redirect_with_notice( $competition_id, 'error_invalid_fields' );
		}

		if ( 'create' === $action && ! $license ) {
			if ( '' !== $license_term || '' !== $license_number ) {
				$results = apply_filters(
					'ufsc_competitions_front_license_search_results',
					array(),
					$license_term,
					$club_id,
					$license_number,
					$license_birthdate
				);

				if ( is_array( $results ) ) {
					$normalized = $repo->normalize_license_results( $results, 2 );
					self::debug_log(
						'entry_action_license_search_fallback',
						array(
							'action'         => $action,
							'competition_id' => $competition_id,
							'club_id'        => $club_id,
							'fallback_count' => count( $normalized ),
							'fallback_ids'   => array_map(
								static function( $row ) {
									return (int) ( $row['id'] ?? 0 );
								},
								$normalized
							),
						)
					);
					if ( 1 === count( $normalized ) && ! empty( $normalized[0]['id'] ) ) {
						$license_id   = (int) $normalized[0]['id'];
						$license_data = apply_filters( 'ufsc_competitions_front_license_by_id', null, $license_id, $club_id );
						if ( is_array( $license_data ) ) {
							$license = $repo->normalize_license_result( $license_data );
						}
					}
				}
			}

			if ( ! $license ) {
				self::debug_log(
					'entry_action_license_missing',
					array(
						'action'    => $action,
						'club_id'   => $club_id,
						'term'      => $license_term,
						'number'    => $license_number,
						'birthdate' => $license_birthdate,
					)
				);
			}
		}

		$participant_type = class_exists( ParticipantTypes::class )
			? ParticipantTypes::normalize( (string) ( $_POST['participant_type'] ?? ParticipantTypes::get_default() ) )
			: 'licensed_ufsc';

		$is_external_participant = class_exists( ParticipantTypes::class )
			? ParticipantTypes::is_external( $participant_type )
			: false;

		if ( $is_external_participant && class_exists( CompetitionMeta::class ) && class_exists( ExternalParticipantEligibility::class ) ) {
			$competition_meta = CompetitionMeta::get( (int) $competition_id );
			if ( ! ExternalParticipantEligibility::is_external_entry_allowed( $competition_meta ) ) {
				self::redirect_with_notice( $competition_id, 'error_invalid_fields' );
			}
		}

		if ( 'create' === $action && ! $is_external_participant && self::is_strict_license_linkage_required( $competition, (int) $club_id, $user_id ) ) {
			if ( ! self::is_strictly_valid_linked_license( $license, (int) $competition_id, (int) $club_id, $user_id ) ) {
				self::strict_debug_log(
					'entry_action_strict_license_linkage_rejected',
					array(
						'competition_id' => $competition_id,
						'club_id'        => $club_id,
						'user_id'        => $user_id,
						'license_id'     => (int) ( $license['id'] ?? 0 ),
					)
				);
				self::redirect_with_notice(
					$competition_id,
					'access_denied',
					AccessResult::deny( 'invalid_license', array( 'scope' => 'register' ) )
				);
			}
		}

		$prefill = $license ? array(
			'first_name'   => $license['first_name'] ?? '',
			'last_name'    => $license['last_name'] ?? '',
			'birth_date'   => $license['birthdate'] ?? '',
			'sex'          => $license['sex'] ?? '',
			'weight'       => isset( $license['weight'] ) ? (string) $license['weight'] : '',
			'weight_class' => $license['weight_class'] ?? '',
		) : array();

		$payload = self::build_payload_from_request( $competition, $prefill );
		$category_from_post = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		if ( '' === $category_from_post && isset( $_POST['category_name'] ) ) {
			$category_from_post = sanitize_text_field( wp_unslash( $_POST['category_name'] ) );
		}
		$category_id_from_post = isset( $_POST['category_id'] ) ? intval( $_POST['category_id'] ) : 0;
		$level_from_post       = isset( $_POST['level'] ) ? sanitize_text_field( wp_unslash( $_POST['level'] ) ) : '';
		if ( '' === $level_from_post && isset( $_POST['classe'] ) ) {
			$level_from_post = sanitize_text_field( wp_unslash( $_POST['classe'] ) );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				'UFSC DEBUG ENTRY ACTION CATEGORY: ' . print_r(
					array(
						'category'    => $category_from_post,
						'category_id' => $category_id_from_post ?: null,
						'level'       => $level_from_post,
					),
					true
				)
			);
		}

		if ( '' !== $category_from_post ) {
			$payload['data']['category'] = $category_from_post;
		}
		if ( $category_id_from_post > 0 ) {
			$payload['data']['category_id'] = $category_id_from_post;
		}
		if ( '' !== $level_from_post ) {
			$payload['data']['level'] = $level_from_post;
		}
		self::debug_log(
			'entry_action_payload_received',
			array(
				'action'            => $action,
				'competition_id'    => $competition_id,
				'club_id'           => $club_id,
				'posted_license_id' => $license_id,
				'posted_fields'     => array(
					'first_name'     => (string) ( $payload['data']['first_name'] ?? '' ),
					'last_name'      => (string) ( $payload['data']['last_name'] ?? '' ),
					'birth_date'     => (string) ( $payload['data']['birth_date'] ?? '' ),
					'sex'            => (string) ( $payload['data']['sex'] ?? '' ),
					'license_number' => (string) ( $payload['data']['license_number'] ?? '' ),
					'category'       => (string) ( $payload['data']['category'] ?? '' ),
					'weight'         => (string) ( $payload['data']['weight'] ?? '' ),
					'weight_class'   => (string) ( $payload['data']['weight_class'] ?? '' ),
				),
			)
		);
		if ( $payload['errors'] ) {
			self::debug_log(
				'entry_action_payload_invalid',
				array(
					'action'         => $action,
					'competition_id' => $competition_id,
					'club_id'        => $club_id,
					'errors'         => array_keys( $payload['errors'] ),
				)
			);
			self::redirect_with_notice( $competition_id, 'error_invalid_fields' );
		}

		$data = array_merge(
			$payload['data'],
			array(
				'competition_id' => $competition_id,
				'club_id'        => $club_id,
				'participant_type' => $participant_type,
			)
		);

		if ( $license ) {
			$data = $repo->merge_license_payload( $data, $license );
		}

		self::debug_log(
			'entry_action_payload_merged',
			array(
				'action'         => $action,
				'competition_id' => $competition_id,
				'club_id'        => $club_id,
				'licensee_id'    => (int) ( $data['licensee_id'] ?? 0 ),
				'license_id'     => (int) ( $license['id'] ?? 0 ),
				'license_number' => (string) ( $data['license_number'] ?? '' ),
				'first_name'     => (string) ( $data['first_name'] ?? '' ),
				'last_name'      => (string) ( $data['last_name'] ?? '' ),
				'birth_date'     => (string) ( $data['birth_date'] ?? '' ),
				'category'       => (string) ( $data['category'] ?? '' ),
			)
		);

		if ( empty( $data['category'] ) && ! empty( $data['birth_date'] ) ) {
			$category = EntriesModule::get_category_from_birthdate( $data['birth_date'], $data, $competition );
			if ( '' !== $category ) {
				$data['category'] = $category;
			}
		}

		$resolved_category_id = self::resolve_category_id_from_label(
			(string) ( $data['category'] ?? '' ),
			(int) ( $competition->id ?? 0 )
		);
		if ( $resolved_category_id > 0 ) {
			$data['category_id'] = $resolved_category_id;
		}

		if ( empty( $data['weight_class'] ) && ! empty( $data['birth_date'] ) && ! empty( $data['weight'] ) ) {
			$weight_context = array(
				'discipline'      => sanitize_key( (string) ( $competition->discipline ?? '' ) ),
				'age_reference'   => sanitize_text_field( (string) ( $competition->age_reference ?? '12-31' ) ),
				'season_end_year' => isset( $competition->season ) ? (int) $competition->season : 0,
			);

			$data['weight_class'] = \UFSC\Competitions\Services\WeightCategoryResolver::resolve(
				$data['birth_date'],
				$data['sex'] ?? '',
				$data['weight'],
				$weight_context
			);
		}

		$new_status = '';
		if ( 'create' === $action ) {
			$status_field = $repo->get_status_storage_field();
			$new_status   = 'draft';

			if ( 'status' === $status_field ) {
				$data['status'] = $new_status;
			} elseif ( '' !== $status_field ) {
				$data[ $status_field ] = $repo->append_status_note( (string) ( $data[ $status_field ] ?? '' ), $new_status );
			}
		}

		$data = apply_filters( 'ufsc_competitions_entry_payload', $data, $competition, $club_id );
		$external_payload = array();
		if ( $is_external_participant ) {
			$external_payload = self::collect_external_participant_payload( $payload['data'], $competition );
			if ( class_exists( ExternalParticipantValidator::class ) ) {
				$external_errors = ExternalParticipantValidator::validate( $external_payload );
				if ( ! empty( $external_errors ) ) {
					self::debug_log(
						'entry_action_external_validation_failed',
						array(
							'action' => $action,
							'competition_id' => $competition_id,
							'errors' => $external_errors,
						)
					);
					self::redirect_with_notice( $competition_id, 'error_invalid_fields' );
				}
			}
		}

		if ( 'create' === $action ) {
			$existing_entry = null;
			if ( ! empty( $data['licensee_id'] ) && method_exists( $repo, 'find_active_by_competition_licensee' ) ) {
				$existing_entry = $repo->find_active_by_competition_licensee( $competition_id, (int) $data['licensee_id'] );
			}
			if ( $existing_entry ) {
				self::debug_log(
					'entry_action_create_duplicate_detected',
					array(
						'competition_id' => $competition_id,
						'club_id'        => $club_id,
						'licensee_id'    => (int) ( $data['licensee_id'] ?? 0 ),
						'existing_entry_id' => (int) ( $existing_entry->id ?? 0 ),
					)
				);
				self::redirect_with_notice( $competition_id, 'error_duplicate_entry' );
			}

			do_action( 'ufsc_competitions_entry_before_create', $data, $competition, $club_id );

			$entry_id = $repo->insert( $data );
			if ( ! $entry_id ) {
				global $wpdb;
				if ( class_exists( EntryDeduplication::class ) && EntryDeduplication::is_duplicate_key_error( (string) $wpdb->last_error ) ) {
					self::debug_log(
						'entry_action_create_duplicate_conflict',
						array(
							'competition_id' => $competition_id,
							'club_id'        => $club_id,
							'licensee_id'    => (int) ( $data['licensee_id'] ?? 0 ),
							'db_error'       => (string) $wpdb->last_error,
						)
					);
					self::redirect_with_notice( $competition_id, 'error_duplicate_entry' );
				}
				self::debug_log( 'entry_action_create_failed', array( 'competition_id' => $competition_id, 'club_id' => $club_id ) );
				self::redirect_with_notice( $competition_id, 'error' );
			}

			do_action( 'ufsc_competitions_entry_after_create', $entry_id, $data, $competition, $club_id );
			if ( $is_external_participant && class_exists( ExternalParticipantService::class ) ) {
				$external_service = new ExternalParticipantService();
				$external_service->save_external_participant( (int) $entry_id, $external_payload );
			}
			do_action( 'ufsc_competitions_entry_status_changed', $entry_id, '', $new_status ?: 'draft', $competition, $club_id );
			self::debug_log(
				'entry_action_create_success',
				array(
					'entry_id'        => $entry_id,
					'competition_id'  => $competition_id,
					'club_id'         => $club_id,
					'licensee_id'     => (int) ( $data['licensee_id'] ?? 0 ),
					'license_number'  => (string) ( $data['license_number'] ?? '' ),
				)
			);

			self::redirect_with_notice( $competition_id, 'created' );
		}

		$result = $repo->update( $entry_id, $data );
		if ( $result ) {
			if ( $is_external_participant && class_exists( ExternalParticipantService::class ) ) {
				$external_service = new ExternalParticipantService();
				$external_service->save_external_participant( (int) $entry_id, $external_payload );
			}
			do_action( 'ufsc_competitions_entry_after_update', $entry_id, $data, $competition, $club_id, $entry );
		}

		self::redirect_with_notice( $competition_id, $result ? 'entry_updated' : 'error' );
	}

	private static function handle_status_action( string $action ): void {
		if ( ! is_user_logged_in() ) {
			self::redirect_with_notice( 0, 'error_forbidden' );
		}

		self::debug_log( 'entry_status_action_start', array( 'action' => $action ) );

		// Capability (club access) - avoid namespace issues by referencing the global class.
		$required_capability = class_exists( '\\UFSC_LC_Settings_Page' ) ? \UFSC_LC_Settings_Page::get_club_access_capability() : '';
		$required_capability = apply_filters( 'ufsc_competitions_front_entry_capability', $required_capability );

		if ( $required_capability && ! current_user_can( $required_capability ) ) {
			self::redirect_with_notice( 0, 'error_forbidden' );
		}

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$competition    = EntriesModule::get_competition( $competition_id );

		if ( ! $competition || empty( $competition->id ) ) {
			self::redirect_with_notice( $competition_id, 'error_not_found' );
		}

		$user_id = (int) get_current_user_id();
		$club_id = function_exists( 'ufsc_lc_get_current_club_id' ) ? (int) ufsc_lc_get_current_club_id( $user_id ) : 0;
		if ( ! $club_id ) {
			self::redirect_with_notice( $competition_id, 'error_forbidden' );
		}

		$access = new CompetitionAccess();
		$access_result = $access->can_register( $competition_id, (int) $club_id, $user_id );
		if ( ! $access_result->allowed ) {
			self::redirect_with_notice( $competition_id, 'access_denied', $access_result );
		}

		if ( 'submit' === $action ) {
			self::verify_nonce_or_redirect( 'ufsc_entry_submit', $competition_id );
		} elseif ( 'withdraw' === $action ) {
			self::verify_nonce_or_redirect( 'ufsc_entry_withdraw', $competition_id );
		} else {
			self::verify_nonce_or_redirect( 'ufsc_entry_cancel', $competition_id );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$repo     = new EntryFrontRepository();
		$entry    = $entry_id ? $repo->get( $entry_id ) : null;

		if ( ! $entry ) {
			self::redirect_with_notice( $competition_id, 'error_not_found' );
		}

		if ( absint( $entry->club_id ?? 0 ) !== absint( $club_id ) ) {
			self::redirect_with_notice( $competition_id, 'error_forbidden' );
		}

		if ( absint( $entry->competition_id ?? 0 ) !== absint( $competition_id ) ) {
			self::redirect_with_notice( $competition_id, 'error_forbidden' );
		}

		if ( 'submit' === $action ) {
			$can_submit = (bool) apply_filters( 'ufsc_entries_can_submit', true, $competition, $club_id );
			if ( ! $can_submit ) {
				self::redirect_with_notice( $competition_id, 'error_forbidden' );
			}

			$weight_context = array(
				'discipline'      => sanitize_key( (string) ( $competition->discipline ?? '' ) ),
				'age_reference'   => sanitize_text_field( (string) ( $competition->age_reference ?? '12-31' ) ),
				'season_end_year' => isset( $competition->season ) ? (int) $competition->season : 0,
			);

			if ( \UFSC\Competitions\Services\WeightCategoryResolver::requires_weight( $weight_context ) ) {
				$weight_value = '';
				foreach ( array( 'weight', 'weight_kg', 'poids' ) as $key ) {
					if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
						$weight_value = (string) $entry->{$key};
						break;
					}
				}
				if ( '' === $weight_value ) {
					self::redirect_with_notice( $competition_id, 'error_weight_required' );
				}
			}

			$quota_result = apply_filters(
				'ufsc_entries_quota_check',
				array( 'ok' => true, 'message' => '' ),
				$competition,
				$club_id
			);

			if ( is_array( $quota_result ) && empty( $quota_result['ok'] ) ) {
				self::redirect_with_notice( $competition_id, 'error_quota' );
			}

			$payment_required = (bool) apply_filters( 'ufsc_entries_payment_required', false, $competition, $club_id );
			if ( $payment_required ) {
				self::redirect_with_notice( $competition_id, 'error_payment_required' );
			}

			$result = $repo->submit( $entry_id, (int) $club_id );
			if ( ! empty( $result['ok'] ) ) {
				do_action( 'ufsc_entries_after_submit', $entry_id, $entry, $competition, $club_id );
				self::redirect_with_notice( $competition_id, 'entry_submitted' );
			}

			self::redirect_with_notice( $competition_id, 'error_invalid_status' );
		}

		if ( 'withdraw' === $action ) {
			// ✅ Normalisation robuste (évite les variations "Validé", "validated", etc.)
			$current_status = EntriesWorkflow::normalize_status( (string) $repo->get_entry_status( $entry ) );

			// ✅ Retrait interdit APRES validation admin : le club doit contacter l’UFSC.
			$locked_statuses = array( 'approved', 'validated', 'valid', 'valide' );
			if ( in_array( $current_status, $locked_statuses, true ) ) {
				self::redirect_with_notice( $competition_id, 'error_withdraw_approved' );
			}

			$withdrawable_statuses = array( 'draft', 'submitted', 'pending', 'rejected' );
			if ( ! in_array( $current_status, $withdrawable_statuses, true ) ) {
				self::redirect_with_notice( $competition_id, 'error_invalid_status' );
			}

			$can_withdraw = (bool) apply_filters( 'ufsc_entries_can_withdraw', true, $entry, $competition, $club_id );
			if ( ! $can_withdraw ) {
				self::redirect_with_notice( $competition_id, 'error_forbidden' );
			}

			$result = $repo->withdraw( $entry_id, (int) $club_id );
			if ( ! empty( $result['ok'] ) ) {
				do_action( 'ufsc_entries_after_withdraw', $entry_id, $entry, $competition, $club_id );
				self::redirect_with_notice( $competition_id, 'entry_withdrawn' );
			}

			self::redirect_with_notice( $competition_id, 'error_invalid_status' );
		}

		$result = $repo->cancel( $entry_id, (int) $club_id );
		if ( ! empty( $result['ok'] ) ) {
			do_action( 'ufsc_entries_after_cancel', $entry_id, $entry, $competition, $club_id );
			self::redirect_with_notice( $competition_id, 'entry_cancelled' );
		}

		self::redirect_with_notice( $competition_id, 'error_invalid_status' );
	}

	private static function handle_admin_action( string $action ): void {
		if ( ! is_user_logged_in() ) {
			self::redirect_admin_with_notice( 'error_forbidden' );
		}

		if ( ! Capabilities::user_can_validate_entries() ) {
			self::redirect_admin_with_notice( 'error_forbidden' );
		}

		$entry_id = isset( $_REQUEST['entry_id'] ) ? absint( $_REQUEST['entry_id'] ) : 0;
		if ( ! $entry_id ) {
			self::redirect_admin_with_notice( 'error_not_found' );
		}

		if ( 'validate' === $action ) {
			check_admin_referer( 'ufsc_entry_admin_validate_' . $entry_id );
		} elseif ( 'reject' === $action ) {
			check_admin_referer( 'ufsc_entry_admin_reject_' . $entry_id );
		} else {
			check_admin_referer( 'ufsc_entry_admin_reopen_' . $entry_id );
		}

		$repo  = new EntryFrontRepository();
		$entry = $repo->get( $entry_id );

		if ( ! $entry ) {
			self::redirect_admin_with_notice( 'error_not_found' );
		}

		$user_id     = get_current_user_id();
		$competition = EntriesModule::get_competition( (int) ( $entry->competition_id ?? 0 ) );

		if ( 'validate' === $action ) {
			$weight_context = array(
				'discipline'      => sanitize_key( (string) ( $competition->discipline ?? '' ) ),
				'age_reference'   => sanitize_text_field( (string) ( $competition->age_reference ?? '12-31' ) ),
				'season_end_year' => isset( $competition->season ) ? (int) $competition->season : 0,
			);

			if ( \UFSC\Competitions\Services\WeightCategoryResolver::requires_weight( $weight_context ) ) {
				$weight_value = '';
				foreach ( array( 'weight', 'weight_kg', 'poids' ) as $key ) {
					if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
						$weight_value = (string) $entry->{$key};
						break;
					}
				}
				if ( '' === $weight_value ) {
					self::redirect_admin_with_notice( 'error_weight_required' );
				}
			}

			$result = $repo->validate( $entry_id, $user_id );
			if ( ! empty( $result['ok'] ) ) {
				do_action( 'ufsc_entries_after_validate', $entry_id, $entry, $competition, $entry->club_id ?? 0 );
				self::redirect_admin_with_notice( 'entry_validated' );
			}

			self::redirect_admin_with_notice( 'error_invalid_status' );
		}

		if ( 'reject' === $action ) {
			$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
			if ( '' === $reason ) {
				self::redirect_admin_with_notice( 'error_invalid_fields' );
			}

			$result = $repo->reject( $entry_id, $user_id, $reason );
			if ( ! empty( $result['ok'] ) ) {
				do_action( 'ufsc_entries_after_reject', $entry_id, $entry, $competition, $entry->club_id ?? 0, $reason );
				self::redirect_admin_with_notice( 'entry_rejected' );
			}

			self::redirect_admin_with_notice( 'error_invalid_status' );
		}

		$result = $repo->reopen( $entry_id, $user_id );
		if ( ! empty( $result['ok'] ) ) {
			do_action( 'ufsc_entries_after_reopen', $entry_id, $entry, $competition, $entry->club_id ?? 0 );
			self::redirect_admin_with_notice( 'entry_reopened' );
		}

		self::redirect_admin_with_notice( 'error_invalid_status' );
	}

	private static function build_payload_from_request( $competition, array $prefill = array() ): array {
		$data   = array();
		$errors = array();

		foreach ( EntriesModule::get_fields_schema( $competition ) as $field ) {
			$name = $field['name'] ?? '';
			if ( ! $name ) {
				continue;
			}
			if ( ! empty( $field['readonly'] ) ) {
				continue;
			}

			$raw   = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';
			$value = is_string( $raw ) ? $raw : '';

			if ( 'birth_date' === $name ) {
				$value = sanitize_text_field( $value );
				if ( '' === $value && ! empty( $prefill['birth_date'] ) ) {
					$value = sanitize_text_field( $prefill['birth_date'] );
				}
				if ( $value ) {
					$date = date_create( $value );
					if ( ! $date ) {
						$value = '';
					}
				}
				if ( $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
					$value = '';
				}
			} elseif ( 'weight' === $name ) {
				$value = sanitize_text_field( $value );
				$value = '' !== $value ? (string) (float) str_replace( ',', '.', $value ) : '';
				if ( '' !== $value ) {
					$weight = (float) $value;
					if ( $weight <= 0 || $weight > 300 ) {
						$value = '';
					}
				}
			} elseif ( 'weight_class' === $name ) {
				$value = sanitize_text_field( $value );
			} elseif ( 'sex' === $name ) {
				$value = sanitize_text_field( $value );
				if ( '' !== $value && ! in_array( $value, array( 'm', 'f', 'x' ), true ) ) {
					$value = '';
				}
			} else {
				$value = sanitize_text_field( $value );
			}

			if ( '' === $value && isset( $prefill[ $name ] ) ) {
				$value = sanitize_text_field( (string) $prefill[ $name ] );
			}

			if ( ! empty( $field['required'] ) && '' === $value ) {
				$errors[ $name ] = true;
			}

			$data[ $name ] = $value;
		}

		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}

	private static function collect_external_participant_payload( array $data, $competition ): array {
		$payload = array(
			'participant_type' => 'external_non_licensed',
			'first_name' => sanitize_text_field( (string) ( $data['first_name'] ?? '' ) ),
			'last_name' => sanitize_text_field( (string) ( $data['last_name'] ?? '' ) ),
			'birth_date' => sanitize_text_field( (string) ( $data['birth_date'] ?? '' ) ),
			'sex' => sanitize_key( (string) ( $data['sex'] ?? '' ) ),
			'club_name' => isset( $_POST['external_club_name'] ) ? sanitize_text_field( wp_unslash( $_POST['external_club_name'] ) ) : '',
			'structure_name' => isset( $_POST['external_structure_name'] ) ? sanitize_text_field( wp_unslash( $_POST['external_structure_name'] ) ) : '',
			'city' => isset( $_POST['external_city'] ) ? sanitize_text_field( wp_unslash( $_POST['external_city'] ) ) : '',
			'discipline' => sanitize_text_field( (string) ( $competition->discipline ?? '' ) ),
			'category_label' => sanitize_text_field( (string) ( $data['category'] ?? '' ) ),
			'weight_kg' => isset( $data['weight'] ) ? (float) str_replace( ',', '.', (string) $data['weight'] ) : null,
			'weight_class' => sanitize_text_field( (string) ( $data['weight_class'] ?? '' ) ),
			'level' => sanitize_text_field( (string) ( $data['level'] ?? '' ) ),
			'medical_notes' => isset( $_POST['external_medical_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['external_medical_notes'] ) ) : '',
			'legal_guardian_name' => isset( $_POST['external_legal_guardian_name'] ) ? sanitize_text_field( wp_unslash( $_POST['external_legal_guardian_name'] ) ) : '',
			'legal_guardian_phone' => isset( $_POST['external_legal_guardian_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['external_legal_guardian_phone'] ) ) : '',
			'legal_guardian_email' => isset( $_POST['external_legal_guardian_email'] ) ? sanitize_email( wp_unslash( $_POST['external_legal_guardian_email'] ) ) : '',
			'validation_status' => 'draft',
		);

		return $payload;
	}

	private static function redirect_with_notice( int $competition_id, string $notice, ?AccessResult $access_result = null ): void {
		$url = self::get_return_url_from_request();
		if ( ! $url ) {
			$url = $competition_id ? Front::get_competition_details_url( $competition_id ) : '';
		}
		if ( ! $url ) {
			$url = wp_get_referer();
		}
		if ( ! $url ) {
			$url = home_url( '/' );
		}

		$args = array(
			'ufsc_notice' => $notice,
		);
		if ( $access_result instanceof AccessResult && ! $access_result->allowed && $access_result->reason_code ) {
			$args['ufsc_access_reason'] = $access_result->reason_code;
			$scope = $access_result->context['scope'] ?? 'register';
			if ( $scope ) {
				$args['ufsc_access_scope'] = $scope;
			}
		}

		$url  = add_query_arg( $args, $url );
		$url .= '#ufsc-inscriptions';

		wp_safe_redirect( $url );
		exit;
	}

	private static function verify_nonce_or_redirect( string $action, int $competition_id ): void {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, $action ) ) {
			self::redirect_with_notice( $competition_id, 'error_forbidden' );
		}
	}

	private static function resolve_category_id_from_label( string $category_label, int $competition_id ): int {
		$category_label = trim( sanitize_text_field( $category_label ) );
		$competition_id = absint( $competition_id );
		if ( '' === $category_label || ! $competition_id || ! class_exists( CategoryRepository::class ) ) {
			return 0;
		}

		$repo = new CategoryRepository();
		$categories = $repo->list(
			array(
				'competition_id' => $competition_id,
				'view'           => 'all',
			),
			500,
			0
		);

		if ( ! is_array( $categories ) || empty( $categories ) ) {
			return 0;
		}

		$normalized_label = self::normalize_category_label( $category_label );
		foreach ( $categories as $category ) {
			$candidate = trim( sanitize_text_field( (string) ( $category->name ?? '' ) ) );
			if ( '' === $candidate ) {
				continue;
			}
			if ( self::normalize_category_label( $candidate ) === $normalized_label ) {
				return (int) ( $category->id ?? 0 );
			}
		}

		return 0;
	}

	private static function normalize_category_label( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = function_exists( 'remove_accents' ) ? remove_accents( $value ) : $value;
		$value = preg_replace( '/\s+/', ' ', $value );

		return is_string( $value ) ? $value : '';
	}

	private static function redirect_admin_with_notice( string $notice ): void {
		$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=ufsc-competitions-entry-validation' );
		}

		$redirect = add_query_arg( 'ufsc_notice', $notice, $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	private static function get_return_url_from_request(): string {
		if ( isset( $_REQUEST['ufsc_return_url'] ) ) {
			$return_url = esc_url_raw( wp_unslash( $_REQUEST['ufsc_return_url'] ) );
			if ( $return_url ) {
				return $return_url;
			}
		}

		return '';
	}

	private static function normalize_birthdate_input( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^\d{2}\/\d{2}\/\d{4}$/', $value ) ) {
			$parts = explode( '/', $value );
			if ( 3 === count( $parts ) ) {
				$value = sprintf( '%04d-%02d-%02d', (int) $parts[2], (int) $parts[1], (int) $parts[0] );
			}
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}

		$date = date_create( $value );
		if ( $date ) {
			return $date->format( 'Y-m-d' );
		}

		return '';
	}

	private static function is_strict_license_linkage_required( $competition, int $club_id, int $user_id ): bool {
		$default = (bool) get_option( 'ufsc_competitions_front_strict_license_linkage', false );
		$enabled = (bool) apply_filters(
			'ufsc_competitions_front_create_require_license_linkage',
			$default,
			$competition,
			$club_id,
			$user_id
		);

		return $enabled;
	}

	private static function is_strictly_valid_linked_license( ?array $license, int $competition_id, int $club_id, int $user_id ): bool {
		$license_id    = absint( $license['id'] ?? 0 );
		$native_result = self::has_valid_license_record( $license_id, $club_id );
		$validated     = (bool) apply_filters(
			'ufsc_competitions_front_create_license_linkage_valid',
			$native_result,
			$license,
			$competition_id,
			$club_id,
			$user_id
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $native_result !== $validated ) {
			self::strict_debug_log(
				'entry_action_strict_license_linkage_override',
				array(
					'competition_id' => $competition_id,
					'club_id'        => $club_id,
					'user_id'        => $user_id,
					'license_id'     => $license_id,
					'native'         => $native_result,
					'filtered'       => $validated,
				)
			);
		}

		return $validated;
	}

	private static function has_valid_license_record( int $license_id, int $club_id ): bool {
		$license_id = absint( $license_id );
		$club_id    = absint( $club_id );
		if ( $license_id <= 0 || $club_id <= 0 ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licences';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			return false;
		}

		$columns_raw = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		$columns = is_array( $columns_raw ) ? array_map( 'strtolower', $columns_raw ) : array();
		if ( empty( $columns ) ) {
			return false;
		}

		$where  = array( 'id = %d' );
		$params = array( $license_id );

		if ( in_array( 'club_id', $columns, true ) ) {
			$where[]  = 'club_id = %d';
			$params[] = $club_id;
		}

		if ( in_array( 'deleted_at', $columns, true ) ) {
			$where[] = "(deleted_at IS NULL OR deleted_at = '')";
		}

		$status_column = self::resolve_first_existing_column(
			$columns,
			array( 'status', 'statut', 'etat', 'state', 'license_status', 'licence_status' )
		);
		if ( '' !== $status_column ) {
			$invalid_statuses = array(
				'inactive',
				'inactif',
				'invalide',
				'invalid',
				'expired',
				'expire',
				'suspended',
				'suspendu',
				'cancelled',
				'annule',
				'deleted',
				'supprime',
				'rejected',
				'refused',
				'blocked',
				'bloque',
			);
			$placeholders = implode( ', ', array_fill( 0, count( $invalid_statuses ), '%s' ) );
			$where[] = "( {$status_column} IS NULL OR {$status_column} = '' OR LOWER({$status_column}) NOT IN ({$placeholders}) )";
			$params  = array_merge( $params, $invalid_statuses );
		}

		$expiry_column = self::resolve_first_existing_column(
			$columns,
			array(
				'date_fin_validite',
				'date_validite',
				'valid_until',
				'expires_at',
				'expiration_date',
				'date_expiration',
				'date_fin_licence',
				'date_fin_licence_asptt',
				'date_echeance',
			)
		);
		if ( '' !== $expiry_column ) {
			$where[]  = "( {$expiry_column} IS NULL OR {$expiry_column} = '' OR {$expiry_column} >= %s )";
			$params[] = current_time( 'Y-m-d' );
		}

		$sql = "SELECT id FROM {$table} WHERE " . implode( ' AND ', $where ) . ' LIMIT 1';
		$sql = $wpdb->prepare( $sql, $params );

		return (int) $wpdb->get_var( $sql ) > 0;
	}

	private static function resolve_first_existing_column( array $columns, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			$name = strtolower( (string) $candidate );
			if ( in_array( $name, $columns, true ) ) {
				return $name;
			}
		}

		return '';
	}

	private static function strict_debug_log( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$payload = $context ? wp_json_encode( $context ) : '';
		error_log( sprintf( '[UFSC LC] %s %s', $message, $payload ) );
	}

	private static function debug_log( string $message, array $context = array() ): void {
		$enabled = ( defined( 'UFSC_LC_DEBUG' ) && UFSC_LC_DEBUG )
			|| ( defined( 'WP_DEBUG' ) && WP_DEBUG );

		if ( ! $enabled ) {
			return;
		}

		$payload = $context ? wp_json_encode( $context ) : '';
		error_log( sprintf( '[UFSC LC] %s %s', $message, $payload ) );
	}
}
