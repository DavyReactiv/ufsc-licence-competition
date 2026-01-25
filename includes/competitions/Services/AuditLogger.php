<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Front\Repositories\EntryFrontRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AuditLogger {
	public const OPTION_LOGS = 'ufsc_competitions_audit_logs';
	public const OPTION_ENABLED = 'ufsc_competitions_audit_enabled';
	public const OPTION_RETENTION_DAYS = 'ufsc_competitions_audit_retention_days';
	public const MAX_LOGS = 2000;
	public const DEFAULT_RETENTION_DAYS = 30;

	public static function enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	public static function log( string $action, array $data = array() ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$action = sanitize_key( $action );
		if ( '' === $action ) {
			return;
		}

		$entry = self::build_entry( $action, $data );
		$logs  = get_option( self::OPTION_LOGS, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		$logs[] = $entry;
		$logs   = self::purge_logs( $logs );

		if ( count( $logs ) > self::MAX_LOGS ) {
			$logs = array_slice( $logs, -1 * self::MAX_LOGS );
		}

		update_option( self::OPTION_LOGS, $logs, false );
	}

	public static function purge_if_needed(): void {
		$logs = get_option( self::OPTION_LOGS, array() );
		if ( ! is_array( $logs ) ) {
			return;
		}

		$cleaned = self::purge_logs( $logs );
		if ( $cleaned !== $logs ) {
			update_option( self::OPTION_LOGS, $cleaned, false );
		}
	}

	public static function get_logs( array $filters = array() ): array {
		self::purge_if_needed();

		$logs = get_option( self::OPTION_LOGS, array() );
		if ( ! is_array( $logs ) ) {
			return array();
		}

		$competition_id = isset( $filters['competition_id'] ) ? absint( $filters['competition_id'] ) : 0;
		$club_id        = isset( $filters['club_id'] ) ? absint( $filters['club_id'] ) : 0;
		$action         = isset( $filters['action'] ) ? sanitize_key( $filters['action'] ) : '';

		$logs = array_filter(
			$logs,
			static function( $entry ) use ( $competition_id, $club_id, $action ) {
				if ( ! is_array( $entry ) ) {
					return false;
				}
				if ( $competition_id && absint( $entry['competition_id'] ?? 0 ) !== $competition_id ) {
					return false;
				}
				if ( $club_id && absint( $entry['club_id'] ?? 0 ) !== $club_id ) {
					return false;
				}
				if ( '' !== $action && ( $entry['action'] ?? '' ) !== $action ) {
					return false;
				}

				return true;
			}
		);

		return array_reverse( array_values( $logs ) );
	}

	public static function purge_all(): void {
		delete_option( self::OPTION_LOGS );
	}

	public static function register_hooks(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_action( 'ufsc_competitions_entry_after_create', array( self::class, 'handle_entry_create' ), 10, 4 );
		add_action( 'ufsc_competitions_entry_after_update', array( self::class, 'handle_entry_update' ), 10, 5 );
		add_action( 'ufsc_competitions_entry_after_delete', array( self::class, 'handle_entry_delete' ), 10, 4 );
		add_action( 'ufsc_entries_after_submit', array( self::class, 'handle_entry_submit' ), 10, 4 );
		add_action( 'ufsc_entries_after_withdraw', array( self::class, 'handle_entry_withdraw' ), 10, 4 );
		add_action( 'ufsc_entries_after_cancel', array( self::class, 'handle_entry_cancel' ), 10, 4 );
		add_action( 'ufsc_entries_after_validate', array( self::class, 'handle_entry_validate' ), 10, 4 );
		add_action( 'ufsc_entries_after_reject', array( self::class, 'handle_entry_reject' ), 10, 5 );
		add_action( 'ufsc_entries_after_reopen', array( self::class, 'handle_entry_reopen' ), 10, 4 );
		add_action( 'ufsc_competitions_plateau_export_before', array( self::class, 'handle_plateau_export' ), 10, 3 );
	}

	public static function handle_entry_create( int $entry_id, array $data, $competition, int $club_id ): void {
		self::log(
			'entry_create',
			array(
				'competition_id' => (int) ( $competition->id ?? 0 ),
				'entry_id'       => $entry_id,
				'club_id'        => $club_id,
				'status_from'    => '',
				'status_to'      => 'draft',
				'context'        => array(
					'source' => 'front',
				),
			)
		);
	}

	public static function handle_entry_update( int $entry_id, array $data, $competition, int $club_id, $entry ): void {
		$status = self::get_entry_status( $entry );
		self::log(
			'entry_update',
			array(
				'competition_id' => (int) ( $competition->id ?? 0 ),
				'entry_id'       => $entry_id,
				'club_id'        => $club_id,
				'status_from'    => $status,
				'status_to'      => $status,
				'context'        => array(
					'source' => 'front',
				),
			)
		);
	}

	public static function handle_entry_delete( int $entry_id, $entry, $competition, int $club_id ): void {
		$status = self::get_entry_status( $entry );
		self::log(
			'entry_delete',
			array(
				'competition_id' => (int) ( $competition->id ?? 0 ),
				'entry_id'       => $entry_id,
				'club_id'        => $club_id,
				'status_from'    => $status,
				'status_to'      => '',
				'context'        => array(
					'source' => 'front',
				),
			)
		);
	}

	public static function handle_entry_submit( int $entry_id, $entry, $competition, int $club_id ): void {
		$status = self::get_entry_status( $entry );
		self::log(
			'entry_submit',
			array(
				'competition_id' => (int) ( $competition->id ?? 0 ),
				'entry_id'       => $entry_id,
				'club_id'        => $club_id,
				'status_from'    => $status,
				'status_to'      => 'submitted',
				'context'        => array(
					'source' => 'front',
				),
			)
		);
	}

	public static function handle_entry_withdraw( int $entry_id, $entry, $competition, int $club_id ): void {
		$status = self::get_entry_status( $entry );
		self::log(
			'entry_withdraw',
			array(
				'competition_id' => (int) ( $competition->id ?? 0 ),
				'entry_id'       => $entry_id,
				'club_id'        => $club_id,
				'status_from'    => $status,
				'status_to'      => 'draft',
				'context'        => array(
					'source' => 'front',
				),
			)
		);
	}

	public static function handle_entry_cancel( int $entry_id, $entry, $competition, int $club_id ): void {
		$status = self::get_entry_status( $entry );
		self::log(
			'entry_cancel',
			array(
				'competition_id' => (int) ( $competition->id ?? 0 ),
				'entry_id'       => $entry_id,
				'club_id'        => $club_id,
				'status_from'    => $status,
				'status_to'      => 'cancelled',
				'context'        => array(
					'source' => 'front',
				),
			)
		);
	}

	public static function handle_entry_validate( int $entry_id, $entry, $competition, int $club_id ): void {
		$status = self::get_entry_status( $entry );
		self::log(
			'entry_validate',
			array(
				'competition_id' => (int) ( $competition->id ?? 0 ),
				'entry_id'       => $entry_id,
				'club_id'        => $club_id,
				'status_from'    => $status,
				'status_to'      => 'validated',
				'context'        => array(
					'source' => 'admin',
				),
			)
		);
	}

	public static function handle_entry_reject( int $entry_id, $entry, $competition, int $club_id, string $reason ): void {
		$status = self::get_entry_status( $entry );
		$reason = function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, 140 ) : substr( $reason, 0, 140 );
		self::log(
			'entry_reject',
			array(
				'competition_id' => (int) ( $competition->id ?? 0 ),
				'entry_id'       => $entry_id,
				'club_id'        => $club_id,
				'status_from'    => $status,
				'status_to'      => 'rejected',
				'context'        => array(
					'source' => 'admin',
					'reason' => $reason,
				),
			)
		);
	}

	public static function handle_entry_reopen( int $entry_id, $entry, $competition, int $club_id ): void {
		$status = self::get_entry_status( $entry );
		self::log(
			'entry_reopen',
			array(
				'competition_id' => (int) ( $competition->id ?? 0 ),
				'entry_id'       => $entry_id,
				'club_id'        => $club_id,
				'status_from'    => $status,
				'status_to'      => 'draft',
				'context'        => array(
					'source' => 'admin',
				),
			)
		);
	}

	public static function handle_plateau_export( $competition, string $status, array $filters ): void {
		if ( 'validated' !== $status ) {
			return;
		}

		$club_id = isset( $filters['club_id'] ) ? absint( $filters['club_id'] ) : 0;
		self::log(
			'export_validated_csv',
			array(
				'competition_id' => (int) ( $competition->id ?? 0 ),
				'club_id'        => $club_id,
				'context'        => array(
					'source' => 'admin',
				),
			)
		);
	}

	private static function build_entry( string $action, array $data ): array {
		$timestamp = current_time( 'mysql' );
		$user_id   = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : get_current_user_id();
		$context   = isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : array();

		return array(
			'timestamp'      => $timestamp,
			'action'         => $action,
			'competition_id' => isset( $data['competition_id'] ) ? absint( $data['competition_id'] ) : 0,
			'entry_id'       => isset( $data['entry_id'] ) ? absint( $data['entry_id'] ) : 0,
			'club_id'        => isset( $data['club_id'] ) ? absint( $data['club_id'] ) : 0,
			'user_id'        => $user_id,
			'status_from'    => isset( $data['status_from'] ) ? sanitize_key( (string) $data['status_from'] ) : '',
			'status_to'      => isset( $data['status_to'] ) ? sanitize_key( (string) $data['status_to'] ) : '',
			'ip'             => self::get_request_ip(),
			'user_agent'     => self::get_user_agent(),
			'context'        => self::sanitize_context( $context ),
		);
	}

	private static function sanitize_context( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_scalar( $value ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $clean;
	}

	private static function get_request_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		$ip = sanitize_text_field( (string) $ip );
		return $ip;
	}

	private static function get_user_agent(): string {
		$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
		$ua = sanitize_text_field( (string) $ua );
		if ( '' === $ua ) {
			return '';
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $ua ) : strlen( $ua );
		if ( $length > 255 ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $ua, 0, 255 ) : substr( $ua, 0, 255 );
		}

		return $ua;
	}

	private static function purge_logs( array $logs ): array {
		$retention_days = absint( get_option( self::OPTION_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS ) );
		if ( $retention_days <= 0 ) {
			return $logs;
		}

		$cutoff = strtotime( '-' . $retention_days . ' days' );
		if ( ! $cutoff ) {
			return $logs;
		}

		return array_values(
			array_filter(
				$logs,
				static function( $entry ) use ( $cutoff ) {
					if ( ! is_array( $entry ) ) {
						return false;
					}
					$timestamp = $entry['timestamp'] ?? '';
					if ( '' === $timestamp ) {
						return true;
					}
					$time = strtotime( $timestamp );
					if ( ! $time ) {
						return true;
					}
					return $time >= $cutoff;
				}
			)
		);
	}

	private static function get_entry_status( $entry ): string {
		if ( ! $entry || ! class_exists( EntryFrontRepository::class ) ) {
			return '';
		}

		$repo = new EntryFrontRepository();
		return $repo->get_entry_status( $entry );
	}
}
