<?php

namespace UFSC\Competitions\Front\Entries;

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

	public static function handle_not_logged_in(): void {
		$competition_id = isset( $_REQUEST['competition_id'] ) ? absint( $_REQUEST['competition_id'] ) : 0;
		self::redirect_with_notice( $competition_id, 'forbidden' );
	}

	private static function handle_action( string $action ): void {
		if ( ! is_user_logged_in() ) {
			self::redirect_with_notice( 0, 'forbidden' );
		}

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$competition = EntriesModule::get_competition( $competition_id );

		if ( ! $competition || empty( $competition->id ) ) {
			self::redirect_with_notice( $competition_id, 'not_found' );
		}

		if ( empty( $competition->status ) || 'open' !== (string) $competition->status ) {
			self::redirect_with_notice( $competition_id, 'not_open' );
		}

		$club_access = new ClubAccess();
		$club_id = $club_access->get_club_id_for_user( get_current_user_id() );
		if ( ! $club_id ) {
			self::redirect_with_notice( $competition_id, 'forbidden' );
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
				self::redirect_with_notice( $competition_id, 'not_found' );
			}

			if ( absint( $entry->club_id ?? 0 ) !== absint( $club_id ) ) {
				self::redirect_with_notice( $competition_id, 'forbidden' );
			}
			if ( absint( $entry->competition_id ?? 0 ) !== absint( $competition_id ) ) {
				self::redirect_with_notice( $competition_id, 'forbidden' );
			}
		}

		if ( 'delete' === $action ) {
			$result = $repo->delete( $entry_id );
			self::redirect_with_notice( $competition_id, $result ? 'deleted' : 'error' );
		}

		$payload = self::build_payload_from_request( $competition );
		if ( $payload['errors'] ) {
			self::redirect_with_notice( $competition_id, 'missing_fields' );
		}

		$data = array_merge(
			$payload['data'],
			array(
				'competition_id' => $competition_id,
				'club_id' => $club_id,
			)
		);

		$data = apply_filters( 'ufsc_competitions_entry_payload', $data, $competition, $club_id );

		if ( 'create' === $action ) {
			do_action( 'ufsc_competitions_entry_before_create', $data, $competition, $club_id );
			$entry_id = $repo->insert( $data );
			if ( ! $entry_id ) {
				self::redirect_with_notice( $competition_id, 'error' );
			}
			do_action( 'ufsc_competitions_entry_after_create', $entry_id, $data, $competition, $club_id );
			self::redirect_with_notice( $competition_id, 'created' );
		}

		$result = $repo->update( $entry_id, $data );
		self::redirect_with_notice( $competition_id, $result ? 'updated' : 'error' );
	}

	private static function build_payload_from_request( $competition ): array {
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
				if ( $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
					$value = '';
				}
			} elseif ( 'weight' === $name ) {
				$value = sanitize_text_field( $value );
				$value = '' !== $value ? (string) (float) str_replace( ',', '.', $value ) : '';
			} else {
				$value = sanitize_text_field( $value );
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

		$url = add_query_arg( 'ufsc_entry_notice', $notice, $url );
		$url .= '#ufsc-competition-entries';

		wp_safe_redirect( $url );
		exit;
	}
}
