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

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$competition = EntriesModule::get_competition( $competition_id );

		if ( ! $competition || empty( $competition->id ) ) {
			self::redirect_with_notice( $competition_id, 'error_not_found' );
		}

		$club_access = new ClubAccess();
		$club_id = $club_access->get_club_id_for_user( get_current_user_id() );
		if ( ! $club_id ) {
			self::redirect_with_notice( $competition_id, 'error_forbidden' );
		}

		if ( 'create' === $action ) {
			check_admin_referer( 'ufsc_competitions_entry_create' );
		} elseif ( 'update' === $action ) {
			check_admin_referer( 'ufsc_competitions_entry_update' );
		} else {
			check_admin_referer( 'ufsc_competitions_entry_delete' );
		}

		$repo = new EntryFrontRepository();
		$entry = null;
		$entry_id = 0;

		if ( 'update' === $action || 'delete' === $action ) {
			$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
			$entry = $entry_id ? $repo->get( $entry_id ) : null;

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
			self::redirect_with_notice( $competition_id, $result ? 'entry_deleted' : 'error' );
		}

		$license_id = isset( $_POST['ufsc_license_id'] ) ? absint( $_POST['ufsc_license_id'] ) : 0;
		$license = null;
		if ( $license_id ) {
			$license_data = apply_filters( 'ufsc_competitions_front_license_by_id', null, $license_id, $club_id );
			if ( is_array( $license_data ) ) {
				$license = $repo->normalize_license_result( $license_data );
			}
		}

		$prefill = $license ? array(
			'first_name' => $license['first_name'] ?? '',
			'last_name' => $license['last_name'] ?? '',
			'birth_date' => $license['birthdate'] ?? '',
			'sex' => $license['sex'] ?? '',
		) : array();

		$payload = self::build_payload_from_request( $competition, $prefill );
		if ( $payload['errors'] ) {
			self::redirect_with_notice( $competition_id, 'error_invalid_fields' );
		}

		$data = array_merge(
			$payload['data'],
			array(
				'competition_id' => $competition_id,
				'club_id' => $club_id,
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

		$new_status = '';
		if ( 'create' === $action ) {
			$status_field = $repo->get_status_storage_field();
			$new_status = 'draft';
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
				self::redirect_with_notice( $competition_id, 'error' );
			}
			do_action( 'ufsc_competitions_entry_after_create', $entry_id, $data, $competition, $club_id );
			do_action( 'ufsc_competitions_entry_status_changed', $entry_id, '', $new_status ?: 'draft', $competition, $club_id );
			self::redirect_with_notice( $competition_id, 'entry_created' );
		}

		$result = $repo->update( $entry_id, $data );
		self::redirect_with_notice( $competition_id, $result ? 'entry_updated' : 'error' );
	}

	private static function handle_status_action( string $action ): void {
		if ( ! is_user_logged_in() ) {
			self::redirect_with_notice( 0, 'error_forbidden' );
		}

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$competition = EntriesModule::get_competition( $competition_id );

		if ( ! $competition || empty( $competition->id ) ) {
			self::redirect_with_notice( $competition_id, 'error_not_found' );
		}

		$club_access = new ClubAccess();
		$club_id = $club_access->get_club_id_for_user( get_current_user_id() );
		if ( ! $club_id ) {
			self::redirect_with_notice( $competition_id, 'error_forbidden' );
		}

		if ( 'submit' === $action ) {
			check_admin_referer( 'ufsc_entry_submit' );
		} elseif ( 'withdraw' === $action ) {
			check_admin_referer( 'ufsc_entry_withdraw' );
		} else {
			check_admin_referer( 'ufsc_entry_cancel' );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$repo = new EntryFrontRepository();
		$entry = $entry_id ? $repo->get( $entry_id ) : null;
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
			$can_withdraw = (bool) apply_filters( 'ufsc_entries_can_withdraw', true, $entry, $competition, $club_id );
			if ( ! $can_withdraw ) {
				self::redirect_with_notice( $competition_id, 'error_forbidden' );
			}
			$result = $repo->withdraw( $entry_id, (int) $club_id );
			if ( ! empty( $result['ok'] ) ) {
				self::redirect_with_notice( $competition_id, 'entry_withdrawn' );
			}
			self::redirect_with_notice( $competition_id, 'error_invalid_status' );
		}

		$result = $repo->cancel( $entry_id, (int) $club_id );
		if ( ! empty( $result['ok'] ) ) {
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

		$repo = new EntryFrontRepository();
		$entry = $repo->get( $entry_id );
		if ( ! $entry ) {
			self::redirect_admin_with_notice( 'error_not_found' );
		}

		$user_id = get_current_user_id();
		$competition = EntriesModule::get_competition( (int) ( $entry->competition_id ?? 0 ) );

		if ( 'validate' === $action ) {
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
			self::redirect_admin_with_notice( 'entry_reopened' );
		}
		self::redirect_admin_with_notice( 'error_invalid_status' );
	}

	private static function build_payload_from_request( $competition, array $prefill = array() ): array {
		$data = array();
		$errors = array();

		foreach ( EntriesModule::get_fields_schema( $competition ) as $field ) {
			$name = $field['name'] ?? '';
			if ( ! $name ) {
				continue;
			}

			$raw = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';
			$value = is_string( $raw ) ? $raw : '';

			if ( 'birth_date' === $name ) {
				$value = sanitize_text_field( $value );
				if ( '' === $value && ! empty( $prefill['birth_date'] ) ) {
					$value = sanitize_text_field( $prefill['birth_date'] );
				}
				if ( $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
					$value = '';
				}
			} elseif ( 'weight' === $name ) {
				$value = sanitize_text_field( $value );
				$value = '' !== $value ? (string) (float) str_replace( ',', '.', $value ) : '';
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
			'data' => $data,
			'errors' => $errors,
		);
	}

	private static function redirect_with_notice( int $competition_id, string $notice ): void {
		$url = $competition_id ? Front::get_competition_details_url( $competition_id ) : '';
		if ( ! $url ) {
			$url = wp_get_referer();
		}
		if ( ! $url ) {
			$url = home_url( '/' );
		}

		$url = add_query_arg( 'ufsc_notice', $notice, $url );
		$url .= '#ufsc-inscriptions';

		wp_safe_redirect( $url );
		exit;
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
}
