<?php
/**
 * UFSC Licence Competition — Club access helpers (merged, conflict-free).
 *
 * Goals:
 * - Single source of truth to resolve current club id (meta first, DB fallback).
 * - Safe sync of user<->club linkage when responsible changes.
 * - Minimal debug tooling (optional) + optional admin query monitor (OFF by default).
 *
 * Notes:
 * - This file is designed to be copied as-is, without merge markers.
 * - The admin query monitor is disabled by default to avoid perf/regression risk.
 */

use UFSC\Competitions\Repositories\ClubRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * -------------------------------------------------------------------------
 * Low-level helpers
 * -------------------------------------------------------------------------
 */

if ( ! function_exists( 'ufsc_lc_get_clubs_table_columns' ) ) {
	/**
	 * Return ufsc_clubs columns (cached per request).
	 *
	 * @return array<string>
	 */
	function ufsc_lc_get_clubs_table_columns(): array {
		static $columns = null;

		if ( is_array( $columns ) ) {
			return $columns;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_clubs';

		// Table existence check.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			$columns = array();
			return $columns;
		}

		$rows    = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = array();

		foreach ( (array) $rows as $row ) {
			$name = isset( $row->Field ) ? trim( (string) $row->Field ) : '';
			if ( '' !== $name ) {
				$columns[] = $name;
			}
		}

		return $columns;
	}
}

if ( ! function_exists( 'ufsc_lc_club_link_responsable_column' ) ) {
	/**
	 * Resolve responsible user column name for ufsc_clubs table.
	 *
	 * @param array<string> $columns Table columns.
	 * @return string
	 */
	function ufsc_lc_club_link_responsable_column( array $columns ): string {
		foreach ( array( 'user_id_responsable', 'responsable_id' ) as $column ) {
			if ( in_array( $column, $columns, true ) ) {
				return $column;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'ufsc_lc_get_club_status_clause' ) ) {
	/**
	 * Build SQL condition enforcing "not inactive" when a status column exists.
	 * More permissive & case-insensitive: we only exclude explicit inactive statuses.
	 *
	 * @param string        $table   SQL table name.
	 * @param array<string> $columns Table columns.
	 * @return string
	 */
	function ufsc_lc_get_club_status_clause( string $table, array $columns ): string {
		// Only exclude explicit inactive states (avoid false negatives like "Actif"/"active"/custom labels).
		$inactive_values = "'inactif','inactive','disabled','archive','archived','deleted','supprime','supprimé'";

		if ( in_array( 'statut', $columns, true ) ) {
			return " AND LOWER(TRIM(COALESCE({$table}.statut, ''))) NOT IN ({$inactive_values})";
		}

		if ( in_array( 'status', $columns, true ) ) {
			return " AND LOWER(TRIM(COALESCE({$table}.status, ''))) NOT IN ({$inactive_values})";
		}

		return '';
	}
}

if ( ! function_exists( 'ufsc_lc_user_club_transient_key' ) ) {
	/**
	 * Short-lived cache key to store resolved club id per user.
	 */
	function ufsc_lc_user_club_transient_key( int $user_id ): string {
		return 'ufsc_lc_current_club_' . absint( $user_id );
	}
}

if ( ! function_exists( 'ufsc_lc_clear_user_club_cache' ) ) {
	/**
	 * Clear short-lived user club cache.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	function ufsc_lc_clear_user_club_cache( int $user_id ): void {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}
		delete_transient( ufsc_lc_user_club_transient_key( $user_id ) );
	}
}

if ( ! function_exists( 'ufsc_lc_get_db_club_id_for_user' ) ) {
	/**
	 * Resolve club id directly from ufsc_clubs table responsible column.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	function ufsc_lc_get_db_club_id_for_user( int $user_id ): int {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return 0;
		}

		$table   = $wpdb->prefix . 'ufsc_clubs';
		$columns = ufsc_lc_get_clubs_table_columns();
		if ( empty( $columns ) ) {
			return 0;
		}

		$responsable_column = ufsc_lc_club_link_responsable_column( $columns );
		if ( '' === $responsable_column ) {
			return 0;
		}

		$sql     = "SELECT id FROM {$table} WHERE {$responsable_column} = %d" . ufsc_lc_get_club_status_clause( $table, $columns ) . ' LIMIT 1';
		$club_id = $wpdb->get_var( $wpdb->prepare( $sql, $user_id ) );

		return absint( $club_id );
	}
}

if ( ! function_exists( 'ufsc_lc_debug_club_resolution' ) ) {
	/**
	 * Optional debug trace for club resolution.
	 */
	function ufsc_lc_debug_club_resolution( int $user_id, int $club_id, string $source ): void {
		if ( ! defined( 'UFSC_DEBUG_CLUB' ) || ! UFSC_DEBUG_CLUB ) {
			return;
		}
		error_log( sprintf( 'UFSC_DEBUG_CLUB user_id=%d club_id=%d source=%s', $user_id, $club_id, $source ) );
	}
}

/**
 * -------------------------------------------------------------------------
 * Source of truth — Resolve current club id
 * -------------------------------------------------------------------------
 */

if ( ! function_exists( 'ufsc_get_current_club_id' ) ) {
	/**
	 * Central source of truth for current club id resolution.
	 *
	 * Strategy:
	 * - If not logged in => 0
	 * - Per-request static cache
	 * - Transient cache (5 minutes)
	 * - Prefer user meta 'ufsc_club_id' if valid, else DB fallback on ufsc_clubs responsible column
	 * - Optional filter-based email fallback
	 *
	 * @param int $user_id Optional user id.
	 * @return int
	 */
	function ufsc_get_current_club_id( int $user_id = 0 ): int {
		static $request_cache = array();

		$user_id = $user_id > 0 ? (int) $user_id : ( is_user_logged_in() ? (int) get_current_user_id() : 0 );
		if ( $user_id <= 0 ) {
			return 0;
		}

		if ( array_key_exists( $user_id, $request_cache ) ) {
			return (int) $request_cache[ $user_id ];
		}

		$transient_key = ufsc_lc_user_club_transient_key( $user_id );
		$cached        = get_transient( $transient_key );
		if ( is_numeric( $cached ) ) {
			$request_cache[ $user_id ] = absint( $cached );
			return (int) $request_cache[ $user_id ];
		}

		global $wpdb;

		$table   = $wpdb->prefix . 'ufsc_clubs';
		$columns = ufsc_lc_get_clubs_table_columns();
		if ( empty( $columns ) ) {
			$request_cache[ $user_id ] = 0;
			set_transient( $transient_key, 0, 5 * MINUTE_IN_SECONDS );
			return 0;
		}

		$responsable_column = ufsc_lc_club_link_responsable_column( $columns );
		if ( '' === $responsable_column ) {
			$request_cache[ $user_id ] = 0;
			set_transient( $transient_key, 0, 5 * MINUTE_IN_SECONDS );
			return 0;
		}

		$meta_club_id = absint( get_user_meta( $user_id, 'ufsc_club_id', true ) );

		// Build WHERE: responsible match OR explicit meta club id (if present).
		$where = "WHERE ({$table}.{$responsable_column} = %d";
		$args  = array( $user_id );

		if ( $meta_club_id > 0 ) {
			$where  .= " OR {$table}.id = %d";
			$args[] = $meta_club_id;
		}
		$where .= ')';
		$where .= ufsc_lc_get_club_status_clause( $table, $columns );

		$order = '';
		if ( $meta_club_id > 0 ) {
			// Prefer meta club id if it matches.
			$order = $wpdb->prepare( " ORDER BY ({$table}.id = %d) DESC", $meta_club_id );
		}

		// We include a boolean is_meta to know which source won.
		$sql = "SELECT {$table}.id, ({$table}.id = %d) AS is_meta FROM {$table} {$where}{$order} LIMIT 1";
		array_unshift( $args, $meta_club_id );

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ) );
		$club_id = $row ? absint( $row->id ?? 0 ) : 0;

		$source = ( $club_id > 0 && ! empty( $row->is_meta ) ) ? 'meta' : ( $club_id > 0 ? 'db' : 'none' );

		// Optional email fallback via filter (kept minimal and opt-in).
		if ( ! $club_id ) {
			$user  = get_user_by( 'id', $user_id );
			$email = $user ? (string) $user->user_email : '';
			if ( '' !== $email ) {
				$fallback = apply_filters( 'ufsc_competitions_resolve_club_id_by_email', 0, $email, $user_id );
				$fallback = absint( $fallback );
				if ( $fallback > 0 ) {
					$club_id = $fallback;
					$source  = 'fallback_email';
				}
			}
		}

		$request_cache[ $user_id ] = $club_id;
		set_transient( $transient_key, $club_id, 5 * MINUTE_IN_SECONDS );

		ufsc_lc_debug_club_resolution( $user_id, $club_id, $source );

		return $club_id;
	}
}

/**
 * -------------------------------------------------------------------------
 * Sync user<->club linkage
 * -------------------------------------------------------------------------
 */

if ( ! function_exists( 'ufsc_sync_user_club_link' ) ) {
	/**
	 * Synchronize user meta link for the club responsible.
	 *
	 * - Ensures new responsible has ufsc_club_id = club_id
	 * - Removes ufsc_club_id from other users that still point to this club (only if equal)
	 * - Purges short-lived caches for impacted users
	 *
	 * @param int $club_id     Club id.
	 * @param int $old_user_id Old responsible user id when known (optional).
	 * @param int $new_user_id New responsible user id when known (optional).
	 * @return void
	 */
	function ufsc_sync_user_club_link( int $club_id, int $old_user_id = 0, int $new_user_id = 0 ): void {
		global $wpdb;

		$club_id     = absint( $club_id );
		$old_user_id = absint( $old_user_id );
		$new_user_id = absint( $new_user_id );

		if ( $club_id <= 0 ) {
			return;
		}

		$table   = $wpdb->prefix . 'ufsc_clubs';
		$columns = ufsc_lc_get_clubs_table_columns();
		if ( empty( $columns ) ) {
			return;
		}

		$responsable_column = ufsc_lc_club_link_responsable_column( $columns );
		if ( '' === $responsable_column ) {
			return;
		}

		// Load current responsible from DB to ensure correctness.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, {$responsable_column} AS responsable_user_id FROM {$table} WHERE id = %d LIMIT 1",
				$club_id
			)
		);

		if ( ! $row ) {
			return;
		}

		$current_responsable_user_id = absint( $row->responsable_user_id ?? 0 );
		if ( $current_responsable_user_id <= 0 ) {
			return;
		}

		if ( $new_user_id <= 0 ) {
			$new_user_id = $current_responsable_user_id;
		}

		// Link the current responsible.
		if ( (int) get_user_meta( $new_user_id, 'ufsc_club_id', true ) !== $club_id ) {
			update_user_meta( $new_user_id, 'ufsc_club_id', $club_id );
		}

		// Clean other users still linked to this club (only if they truly point to this club).
		$linked_user_ids = get_users(
			array(
				'fields'     => 'ids',
				'meta_key'   => 'ufsc_club_id',
				'meta_value' => $club_id,
				'number'     => 200,
			)
		);

		foreach ( (array) $linked_user_ids as $linked_user_id ) {
			$linked_user_id = absint( $linked_user_id );
			if ( $linked_user_id <= 0 || $linked_user_id === $new_user_id ) {
				continue;
			}

			if ( (int) get_user_meta( $linked_user_id, 'ufsc_club_id', true ) === $club_id ) {
				delete_user_meta( $linked_user_id, 'ufsc_club_id' );
			}

			ufsc_lc_clear_user_club_cache( $linked_user_id );
		}

		// Purge caches for old/new responsible if provided.
		if ( $old_user_id > 0 ) {
			ufsc_lc_clear_user_club_cache( $old_user_id );
		}
		if ( $new_user_id > 0 ) {
			ufsc_lc_clear_user_club_cache( $new_user_id );
		}
	}
}

/**
 * Compatibility alias.
 */
if ( ! function_exists( 'ufsc_lc_get_current_club_id' ) ) {
	/**
	 * Resolve current club id for a given user (or current user if omitted).
	 *
	 * @param int $user_id Optional user id.
	 * @return int
	 */
	function ufsc_lc_get_current_club_id( int $user_id = 0 ): int {
		return ufsc_get_current_club_id( $user_id );
	}
}

if ( ! function_exists( 'ufsc_lc_resolve_current_club_id' ) ) {
	/**
	 * Resolve current club id with fallback sources (diagnostic-friendly).
	 *
	 * @param int $user_id Optional user id.
	 * @return array{club_id:int,source:string,source_meta_key:string}
	 */
	function ufsc_lc_resolve_current_club_id( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? (int) $user_id : ( is_user_logged_in() ? (int) get_current_user_id() : 0 );

		if ( $user_id <= 0 ) {
			return array(
				'club_id'          => 0,
				'source'           => 'missing_user',
				'source_meta_key'  => '',
			);
		}

		$club_id = ufsc_get_current_club_id( $user_id );

		$source         = 'none';
		$source_meta_key = '';

		if ( $club_id > 0 ) {
			$meta_link = absint( get_user_meta( $user_id, 'ufsc_club_id', true ) );
			$source    = ( $meta_link > 0 && $meta_link === $club_id ) ? 'meta' : 'db';
		}

		// Extra compatibility probes (kept conservative).
		if ( ! $club_id ) {
			$compat_meta_keys = array( 'ufsc_lc_club_id', 'ufsc_asptt_club_id', 'club_id' );
			$compat_candidates = array();

			foreach ( $compat_meta_keys as $key ) {
				$meta_id = absint( get_user_meta( $user_id, $key, true ) );
				if ( $meta_id > 0 ) {
					$compat_candidates[ $key ] = $meta_id;
				}
			}

			if ( ! empty( $compat_candidates ) ) {
				$unique_values = array_values( array_unique( array_values( $compat_candidates ) ) );
				if ( 1 === count( $unique_values ) ) {
					$club_id         = (int) $unique_values[0];
					$source_meta_key = (string) key( $compat_candidates );
					$source          = 'compat_meta';

					// Self-heal: write the canonical meta if missing.
					if ( ! get_user_meta( $user_id, 'ufsc_club_id', true ) ) {
						update_user_meta( $user_id, 'ufsc_club_id', $club_id );
					}
				}
			}
		}

		if ( ! $club_id ) {
			$club_id = absint( apply_filters( 'ufsc_competitions_resolve_club_id', 0, $user_id ) );
			if ( $club_id > 0 ) {
				$source         = 'filter';
				$source_meta_key = 'ufsc_competitions_resolve_club_id';
			}
		}

		$club_id = absint( apply_filters( 'ufsc_lc_current_club_id', $club_id, $user_id ) );

		return array(
			'club_id'         => $club_id ? (int) $club_id : 0,
			'source'          => $source,
			'source_meta_key' => $source_meta_key,
		);
	}
}

/**
 * -------------------------------------------------------------------------
 * Optional: Debug shortcode (admin-only)
 * -------------------------------------------------------------------------
 */

if ( ! function_exists( 'ufsc_lc_register_debug_club_shortcode' ) ) {
	/**
	 * Register debug shortcode used to inspect current club resolution on frontend.
	 *
	 * Usage: [ufsc_debug_club]
	 *
	 * @return void
	 */
	function ufsc_lc_register_debug_club_shortcode(): void {
		add_shortcode(
			'ufsc_debug_club',
			static function(): string {
				if ( ! is_user_logged_in() ) {
					return '<p>' . esc_html__( 'Utilisateur non connecté.', 'ufsc-licence-competition' ) . '</p>';
				}

				$user_id = (int) get_current_user_id();

				// Capability gate (admin only by default).
				$capability = class_exists( 'UFSC_LC_Settings_Page' ) ? (string) UFSC_LC_Settings_Page::get_club_access_capability() : '';
				$allowed    = current_user_can( 'manage_options' ) || ( '' !== $capability && user_can( $user_id, $capability ) );

				if ( ! $allowed ) {
					return '<p>' . esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) . '</p>';
				}

				$meta_club_id = absint( get_user_meta( $user_id, 'ufsc_club_id', true ) );
				$db_club_id   = ufsc_lc_get_db_club_id_for_user( $user_id );
				$resolved     = ufsc_lc_resolve_current_club_id( $user_id );

				$lines = array(
					'current_user_id: ' . $user_id,
					'ufsc_club_id meta: ' . $meta_club_id,
					'club_id via DB fallback: ' . $db_club_id,
					'source choisie: ' . (string) ( $resolved['source'] ?? 'none' ),
					'club_id final: ' . absint( $resolved['club_id'] ?? 0 ),
				);

				return '<pre class="ufsc-debug-club">' . esc_html( implode( "\n", $lines ) ) . '</pre>';
			}
		);
	}
}
add_action( 'init', 'ufsc_lc_register_debug_club_shortcode', 20 );

/**
 * -------------------------------------------------------------------------
 * OPTIONAL (OFF by default): Admin query monitor
 * -------------------------------------------------------------------------
 *
 * This is intentionally disabled by default to avoid regressions/perf hits.
 * Only enable if you truly cannot add a proper do_action() in your admin save handler.
 *
 * Enable by adding to wp-config.php:
 * define('UFSC_LC_ENABLE_ADMIN_QUERY_MONITOR', true);
 */

if ( ! function_exists( 'ufsc_lc_register_club_responsable_query_monitor' ) ) {
	/**
	 * Detect responsible-user updates on ufsc_clubs when no explicit action is fired by admin save handler.
	 *
	 * @return void
	 */
	function ufsc_lc_register_club_responsable_query_monitor(): void {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! defined( 'UFSC_LC_ENABLE_ADMIN_QUERY_MONITOR' ) || ! UFSC_LC_ENABLE_ADMIN_QUERY_MONITOR ) {
			return;
		}

		add_filter(
			'query',
			static function( string $query ): string {
				static $queued = array();

				global $wpdb;

				$table_pattern = preg_quote( $wpdb->prefix . 'ufsc_clubs', '/' );
				if ( ! preg_match( '/^\s*UPDATE\s+`?' . $table_pattern . '`?\s+SET\s+/i', $query ) ) {
					return $query;
				}

				if ( ! preg_match( '/\b(?:`?user_id_responsable`?|`?responsable_id`?)\s*=\s*(NULL|\d+)/i', $query, $new_match ) ) {
					return $query;
				}

				if ( ! preg_match( '/\bWHERE\b.+?\b`?id`?\s*=\s*(\d+)/i', $query, $club_match ) ) {
					return $query;
				}

				$club_id     = absint( $club_match[1] );
				$new_user_id = ( 'NULL' === strtoupper( $new_match[1] ) ) ? 0 : absint( $new_match[1] );

				if ( $club_id <= 0 ) {
					return $query;
				}

				$columns            = ufsc_lc_get_clubs_table_columns();
				$responsable_column = ufsc_lc_club_link_responsable_column( $columns );
				if ( '' === $responsable_column ) {
					return $query;
				}

				$old_user_id = absint(
					$wpdb->get_var(
						$wpdb->prepare(
							"SELECT {$responsable_column} FROM {$wpdb->prefix}ufsc_clubs WHERE id = %d LIMIT 1",
							$club_id
						)
					)
				);

				$queued[ $club_id ] = array(
					'old_user_id' => $old_user_id,
					'new_user_id' => $new_user_id,
				);

				static $shutdown_registered = false;
				if ( ! $shutdown_registered ) {
					$shutdown_registered = true;

					add_action(
						'shutdown',
						static function() use ( &$queued, $wpdb ) {
							if ( empty( $queued ) ) {
								return;
							}

							$columns            = ufsc_lc_get_clubs_table_columns();
							$responsable_column = ufsc_lc_club_link_responsable_column( $columns );
							if ( '' === $responsable_column ) {
								return;
							}

							foreach ( $queued as $club_id => $sync_data ) {
								$club_id     = absint( $club_id );
								$old_user_id = absint( $sync_data['old_user_id'] ?? 0 );

								$new_user_id = absint(
									$wpdb->get_var(
										$wpdb->prepare(
											"SELECT {$responsable_column} FROM {$wpdb->prefix}ufsc_clubs WHERE id = %d LIMIT 1",
											$club_id
										)
									)
								);

								if ( $club_id <= 0 || $old_user_id === $new_user_id ) {
									continue;
								}

								do_action( 'ufsc_lc_club_responsable_updated', $club_id, $old_user_id, $new_user_id );
							}
						}
					);
				}

				return $query;
			}
		);
	}
}
add_action( 'admin_init', 'ufsc_lc_register_club_responsable_query_monitor', 20 );

/**
 * -------------------------------------------------------------------------
 * Hooks: login + explicit "responsable updated"
 * -------------------------------------------------------------------------
 */

add_action(
	'wp_login',
	static function( $user_login, $user ) {
		$user_id = (int) ( $user->ID ?? 0 );
		if ( $user_id <= 0 ) {
			return;
		}

		$club_id = ufsc_get_current_club_id( $user_id );
		if ( $club_id > 0 ) {
			ufsc_sync_user_club_link( $club_id, 0, $user_id );
		}
	},
	10,
	2
);

add_action(
	'ufsc_lc_club_responsable_updated',
	static function( int $club_id, int $old_user_id, int $new_user_id ) {
		$club_id     = absint( $club_id );
		$old_user_id = absint( $old_user_id );
		$new_user_id = absint( $new_user_id );

		if ( $club_id <= 0 ) {
			return;
		}

		// Remove old responsible meta only if it pointed to this club.
		if ( $old_user_id > 0 && (int) get_user_meta( $old_user_id, 'ufsc_club_id', true ) === $club_id ) {
			delete_user_meta( $old_user_id, 'ufsc_club_id' );
		}

		// Assign new responsible meta (canonical).
		if ( $new_user_id > 0 ) {
			update_user_meta( $new_user_id, 'ufsc_club_id', $club_id );
		}

		// Purge caches.
		if ( $old_user_id > 0 ) {
			ufsc_lc_clear_user_club_cache( $old_user_id );
		}
		if ( $new_user_id > 0 ) {
			ufsc_lc_clear_user_club_cache( $new_user_id );
		}

		// Perform broader clean-up (linked users, etc.).
		ufsc_sync_user_club_link( $club_id, $old_user_id, $new_user_id );
	},
	10,
	3
);

/**
 * -------------------------------------------------------------------------
 * Club context helper (used by front modules)
 * -------------------------------------------------------------------------
 */

if ( ! function_exists( 'ufsc_lc_current_club_context' ) ) {
	/**
	 * Resolve current club context for a user (or current user if omitted).
	 *
	 * @param int $user_id Optional user id.
	 * @return array{club_id:int,club_name:string,region:string,affiliated:bool,source:string,source_meta_key:string}
	 */
	function ufsc_lc_current_club_context( int $user_id = 0 ): array {
		$user_id  = $user_id > 0 ? (int) $user_id : ( is_user_logged_in() ? (int) get_current_user_id() : 0 );
		$resolved = function_exists( 'ufsc_lc_resolve_current_club_id' ) ? ufsc_lc_resolve_current_club_id( $user_id ) : array();

		$club_id         = absint( $resolved['club_id'] ?? 0 );
		$source          = (string) ( $resolved['source'] ?? '' );
		$source_meta_key = (string) ( $resolved['source_meta_key'] ?? '' );

		$club_name = '';
		$region    = '';

		if ( $club_id && class_exists( ClubRepository::class ) ) {
			$club_repo = new ClubRepository();
			$club      = $club_repo->get( $club_id );

			if ( $club ) {
				$club_name = trim( wp_strip_all_tags( (string) ( $club->nom ?? '' ) ) );

				$region_fields = array( 'region', 'ufsc_region', 'club_region', 'region_name' );
				foreach ( $region_fields as $field ) {
					if ( isset( $club->{$field} ) ) {
						$value = trim( wp_strip_all_tags( (string) $club->{$field} ) );
						if ( '' !== $value ) {
							$region = $value;
							break;
						}
					}
				}
			}
		}

		$affiliated = false;
		if ( $user_id > 0 && $club_id > 0 ) {
			$required_capability = class_exists( 'UFSC_LC_Settings_Page' ) ? (string) \UFSC_LC_Settings_Page::get_club_access_capability() : '';
			$required_capability = apply_filters( 'ufsc_competitions_access_affiliation_capability', $required_capability, $club_id, $user_id );
			$affiliated          = '' === $required_capability ? true : user_can( $user_id, $required_capability );
		}

		return array(
			'club_id'         => $club_id,
			'club_name'       => $club_name,
			'region'          => $region,
			'affiliated'      => $affiliated,
			'source'          => $source,
			'source_meta_key' => $source_meta_key,
		);
	}
}