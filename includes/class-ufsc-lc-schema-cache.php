<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Schema_Cache {
	const CACHE_GROUP = 'ufsc_licence_competition';
	const CACHE_TTL   = 21600; // 6 hours.

	private static $tables_cache = array();
	private static $columns_cache = array();
	private static $hits = 0;
	private static $misses = 0;

	public static function table_exists( $table ): bool {
		global $wpdb;

		$table = (string) $table;
		if ( '' === $table ) {
			return false;
		}

		if ( array_key_exists( $table, self::$tables_cache ) ) {
			self::$hits++;
			return (bool) self::$tables_cache[ $table ];
		}

		$cache_key = self::get_tables_cache_key();
		$cached    = self::get_persistent_cache( $cache_key );
		if ( is_array( $cached ) && array_key_exists( $table, $cached ) ) {
			self::$tables_cache[ $table ] = (bool) $cached[ $table ];
			self::$hits++;
			return (bool) self::$tables_cache[ $table ];
		}

		self::$misses++;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$value  = ( $exists === $table );

		self::$tables_cache[ $table ] = $value;
		if ( ! is_array( $cached ) ) {
			$cached = array();
		}
		$cached[ $table ] = $value;
		self::set_persistent_cache( $cache_key, $cached );

		return $value;
	}

	public static function column_exists( $table, $column ): bool {
		$column  = sanitize_key( $column );
		$columns = self::get_columns( $table );
		return in_array( $column, $columns, true );
	}

	public static function get_columns( $table, bool $retry = true ): array {
		global $wpdb;

		$table = (string) $table;
		if ( '' === $table ) {
			return array();
		}

		if ( array_key_exists( $table, self::$columns_cache ) ) {
			self::$hits++;
			return self::$columns_cache[ $table ];
		}

		$cache_key = self::get_columns_cache_key( $table );
		$cached    = self::get_persistent_cache( $cache_key );
		if ( is_array( $cached ) ) {
			self::$columns_cache[ $table ] = $cached;
			self::$hits++;
			return $cached;
		}

		self::$misses++;
		if ( ! self::table_exists( $table ) ) {
			self::$columns_cache[ $table ] = array();
			return array();
		}

		$results = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		$columns = array();
		if ( is_array( $results ) ) {
			foreach ( $results as $column ) {
				$columns[] = (string) $column;
			}
		}

		if ( $retry && $wpdb->last_error && self::is_unknown_column_error( $wpdb->last_error ) ) {
			self::refresh_table_cache( $table );
			return self::get_columns( $table, false );
		}

		self::$columns_cache[ $table ] = $columns;
		self::set_persistent_cache( $cache_key, $columns );

		return $columns;
	}

	public static function refresh_table_cache( $table ): void {
		$table = (string) $table;
		unset( self::$columns_cache[ $table ] );
		unset( self::$tables_cache[ $table ] );

		$columns_key = self::get_columns_cache_key( $table );
		$tables_key  = self::get_tables_cache_key();

		delete_transient( $columns_key );
		wp_cache_delete( $columns_key, self::CACHE_GROUP );

		$cached = self::get_persistent_cache( $tables_key );
		if ( is_array( $cached ) && array_key_exists( $table, $cached ) ) {
			unset( $cached[ $table ] );
			self::set_persistent_cache( $tables_key, $cached );
		}
	}

	public static function get_debug_stats(): array {
		return array(
			'hits'   => self::$hits,
			'misses' => self::$misses,
		);
	}

	public static function get_cache_version(): string {
		$version = get_option( 'ufsc_lc_db_version', '0' );
		return (string) $version;
	}

	private static function get_tables_cache_key(): string {
		global $wpdb;
		return 'ufsc_lc_schema_tables_' . md5( $wpdb->prefix . '|' . self::get_cache_version() );
	}

	private static function get_columns_cache_key( string $table ): string {
		global $wpdb;
		return 'ufsc_lc_schema_columns_' . md5( $wpdb->prefix . '|' . self::get_cache_version() . '|' . $table );
	}

	private static function get_persistent_cache( string $cache_key ) {
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_cache_set( $cache_key, $cached, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $cached;
	}

	private static function set_persistent_cache( string $cache_key, $value ): void {
		wp_cache_set( $cache_key, $value, self::CACHE_GROUP, self::CACHE_TTL );
		set_transient( $cache_key, $value, self::CACHE_TTL );
	}

	private static function is_unknown_column_error( string $error ): bool {
		return false !== stripos( $error, 'Unknown column' );
	}
}
