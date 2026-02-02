<?php

namespace UFSC\Competitions\Front\Entries;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Front\Access\ClubAccess;
use UFSC\Competitions\Front\Front;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;

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

		$club_access = new ClubAccess();
		$club_id     = $club_access->get_club_id_for_user( get_current_user_id() );

		if ( ! $club_id ) {
			self::redirect_with_notice( $competition_id, 'error_forbidden' );
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

			$status = $repo->get_entry_status( $entry );
			if ( 'draft' !== $status ) {
				self::redirect_with_notice( $competition_id, 'error_locked' );
			}
		}

		if ( ! EntriesModule::is_registration_open( $competition, (int) $club_id ) ) {
			self::redirect_with_notice( $competition_id, 'error_closed' );
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

		if ( $license_id ) {
			$license_data = apply_filters( 'ufsc_competitions_front_license_by_id', null, $license_id, $club_id );
			if ( is_array( $license_data ) ) {
				$license = $repo->normalize_license_result( $license_data );
			}
		}

		if ( $license_id && ! $license ) {
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

		$prefill = $license ? array(
			'first_name'    => $license['first_name'] ?? '',
			'last_name'     => $license['last_name'] ?? '',
			'birth_date'    => $license['birthdate'] ?? '',
			'sex'           => $license['sex'] ?? '',
			'weight'        => isset( $license['weight'] ) ? (string) $license['weight'] : '',
			'weight_class'  => $license['weight_class'] ?? '',
		) : array();

		$payload = self::build_payload_from_request( $competition, $prefill );
		if ( $payload['errors'] ) {
			self::redirect_with_notice( $competition_id, 'error_invalid_fields' );
		}

		$data = array_merge(
			$payload['data'],
			array(
				'competition_id' => $competition_id,
				'club_id'        => $club_id,
			)
		);

		if ( $license ) {
			$data = $repo->merge_license_payload( $data, $license );
		}

		if ( empty( $data['category'] ) && ! empty( $data['birth_date'] ) ) {
			$category = EntriesModule::get_category_from_birthdate( $data['birth_date'], $data, $competition );
			if ( '' !== $category ) {
				$data['category'] = $category;
			}
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

		if ( 'create' === $action ) {
			do_action( 'ufsc_competitions_entry_before_create', $data, $competition, $club_id );

			$entry_id = $repo->insert( $data );
			if ( ! $entry_id ) {
				self::debug_log( 'entry_action_create_failed', array( 'competition_id' => $competition_id, 'club_id' => $club_id ) );
				self::redirect_with_notice( $competition_id, 'error' );
			}

			do_action( 'ufsc_competitions_entry_after_create', $entry_id, $data, $competition, $club_id );
			do_action( 'ufsc_competitions_entry_status_changed', $entry_id, '', $new_status ?: 'draft', $competition, $club_id );

			self::redirect_with_notice( $competition_id, 'created' );
		}

		$result = $repo->update( $entry_id, $data );
		if ( $result ) {
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

		$club_access = new ClubAccess();
		$club_id     = $club_access->get_club_id_for_user( get_current_user_id() );
		if ( ! $club_id ) {
			self::redirect_with_notice( $competition_id, 'error_forbidden' );
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

		if ( ! EntriesModule::is_registration_open( $competition, (int) $club_id ) ) {
			self::redirect_with_notice( $competition_id, 'error_closed' );
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
			$current_status = (string) $repo->get_entry_status( $entry );

			// ✅ Withdraw allowed only BEFORE admin validation.
			// If approved/validated/valid/valide => club must contact admin by email.
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

	private static function redirect_with_notice( int $competition_id, string $notice ): void {
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

		$url  = add_query_arg( 'ufsc_notice', $notice, $url );
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

		if ( preg_match( '/^\\d{2}\\/\\d{2}\\/\\d{4}$/', $value ) ) {
			$parts = explode( '/', $value );
			if ( 3 === count( $parts ) ) {
				$value = sprintf( '%04d-%02d-%02d', (int) $parts[2], (int) $parts[1], (int) $parts[0] );
			}
		}

		if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $value ) ) {
			return $value;
		}

		$date = date_create( $value );
		if ( $date ) {
			return $date->format( 'Y-m-d' );
		}

		return '';
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
