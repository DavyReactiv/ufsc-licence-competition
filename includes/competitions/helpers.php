<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UFSC\Competitions\Db;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;

function ufsc_comp_get_object_value( $source, array $keys ) {
	foreach ( $keys as $key ) {
		if ( is_array( $source ) && array_key_exists( $key, $source ) && '' !== trim( (string) $source[ $key ] ) ) {
			return $source[ $key ];
		}
		if ( is_object( $source ) && isset( $source->{$key} ) && '' !== trim( (string) $source->{$key} ) ) {
			return $source->{$key};
		}
	}
	return '';
}

function ufsc_comp_parse_date_value( $value ): ?DateTimeImmutable {
	if ( null === $value ) {
		return null;
	}
	$value = trim( (string) $value );
	if ( '' === $value || '0000-00-00' === $value || '0000-00-00 00:00:00' === $value ) {
		return null;
	}
	$timezone = wp_timezone();
	if ( is_numeric( $value ) && preg_match( '/^\d{8,10}$/', $value ) ) {
		$timestamp = (int) $value;
		if ( $timestamp > 0 ) {
			return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone );
		}
	}
	$formats = array( 'Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d' );
	foreach ( $formats as $format ) {
		$date = DateTimeImmutable::createFromFormat( '!' . $format, $value, $timezone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( $date instanceof DateTimeImmutable && ( false === $errors || ( 0 === (int) $errors['warning_count'] && 0 === (int) $errors['error_count'] ) ) ) {
			return $date;
		}
	}
	try {
		$date = new DateTimeImmutable( $value, $timezone );
		$year = (int) $date->format( 'Y' );
		return ( $year >= 1900 && $year <= 2200 ) ? $date : null;
	} catch ( Throwable $e ) {
		return null;
	}
}

function ufsc_comp_get_entry_birth_date( $entry ) {
	$keys = array( 'birth_date', 'birthdate', 'birthday', 'dob', 'date_of_birth', 'date_naissance', 'licensee_birthdate', 'licence_birthdate' );
	$value = ufsc_comp_get_object_value( $entry, $keys );
	if ( '' !== trim( (string) $value ) ) {
		return $value;
	}
	$json_value = ufsc_comp_get_object_value( $entry, array( 'raw_data', 'import_data', 'payload', 'metadata', 'meta', 'notes' ) );
	if ( '' !== trim( (string) $json_value ) ) {
		$decoded = json_decode( (string) $json_value, true );
		if ( is_array( $decoded ) ) {
			$value = ufsc_comp_get_object_value( $decoded, $keys );
			if ( '' !== trim( (string) $value ) ) {
				return $value;
			}
		}
	}

	return ufsc_comp_get_linked_license_birth_date( $entry, $keys );
}

function ufsc_comp_get_department_from_postal_code( $postal_code ): string {
	$postal_code = strtoupper( preg_replace( '/[^0-9AB]/i', '', (string) $postal_code ) );
	if ( '' === $postal_code ) {
		return '—';
	}
	if ( preg_match( '/^(2A|2B)/', $postal_code, $matches ) ) {
		return $matches[1];
	}
	if ( preg_match( '/^(97[1-6])/', $postal_code, $matches ) ) {
		return $matches[1];
	}
	if ( preg_match( '/^20/', $postal_code ) ) {
		return '20';
	}
	if ( preg_match( '/^(\d{2})/', $postal_code, $matches ) ) {
		return $matches[1];
	}
	return '—';
}

function ufsc_comp_get_club_department( $club_or_entry ): string {
	$postal_keys = array( 'club_postal_code', 'postal_code', 'postcode', 'zip', 'zip_code', 'code_postal', 'club_zip', 'club_cp', 'cp' );
	$postal_code = ufsc_comp_get_object_value( $club_or_entry, $postal_keys );
	if ( '' !== trim( (string) $postal_code ) ) {
		return ufsc_comp_get_department_from_postal_code( (string) $postal_code );
	}
	$json_value = ufsc_comp_get_object_value( $club_or_entry, array( 'raw_data', 'import_data', 'payload', 'metadata', 'meta', 'notes' ) );
	if ( '' !== trim( (string) $json_value ) ) {
		$decoded = json_decode( (string) $json_value, true );
		if ( is_array( $decoded ) ) {
			$postal_code = ufsc_comp_get_object_value( $decoded, $postal_keys );
			if ( '' !== trim( (string) $postal_code ) ) {
				return ufsc_comp_get_department_from_postal_code( (string) $postal_code );
			}
		}
	}
	return '—';
}

function ufsc_comp_format_fighter_print_label( $entry, string $side = '' ): string {
	if ( ! $entry ) {
		return '—';
	}
	$first_name = trim( (string) ufsc_comp_get_object_value( $entry, array( 'first_name', 'prenom', 'licensee_first_name' ) ) );
	$last_name = trim( (string) ufsc_comp_get_object_value( $entry, array( 'last_name', 'nom', 'licensee_last_name' ) ) );
	$display_name = trim( (string) ufsc_comp_get_object_value( $entry, array( 'display_name', 'licensee_name', 'participant_name' ) ) );
	$name = trim( $last_name . ' ' . $first_name );
	if ( '' === $name ) {
		$name = '' !== $display_name ? $display_name : 'Combattant #' . (int) ufsc_comp_get_object_value( $entry, array( 'entry_id', 'id' ) );
	}
	$club = trim( (string) ufsc_comp_get_object_value( $entry, array( 'club_name', 'club' ) ) );
	$dept = trim( (string) ufsc_comp_get_object_value( $entry, array( 'club_department', 'department' ) ) );
	if ( '' === $dept || '—' === $dept ) {
		$dept = ufsc_comp_get_club_department( $entry );
	}
	$fighter_no = trim( (string) ufsc_comp_get_object_value( $entry, array( 'fighter_number', 'numero_combattant', 'competition_number', 'combatant_number' ) ) );
	$licence = trim( (string) ufsc_comp_get_object_value( $entry, array( 'licence_number', 'license_number' ) ) );
	$lines = array( $name, trim( ( '' !== $club ? $club : '—' ) . ' — Dept ' . ( '' !== $dept ? $dept : '—' ) ) );
	if ( '' !== $fighter_no ) {
		$lines[] = 'N° combattant : ' . $fighter_no;
	}
	if ( '' !== $licence ) {
		$lines[] = 'Licence : ' . $licence;
	}
	return implode( "\n", array_map( 'esc_html', $lines ) );
}

function ufsc_comp_get_linked_license_birth_date( $entry, array $keys ) {
	global $wpdb;

	$licensee_id = absint( ufsc_comp_get_object_value( $entry, array( 'licensee_id', 'licence_id', 'license_id' ) ) );
	if ( ! $licensee_id || ! class_exists( '\UFSC\Competitions\Db' ) ) {
		return '';
	}

	$table = $wpdb->prefix . 'ufsc_licences';
	if ( ! Db::table_exists( $table ) ) {
		return '';
	}

	$columns = Db::get_table_columns( $table );
	$available_date_columns = array_values( array_intersect( $keys, $columns ) );
	if ( empty( $available_date_columns ) ) {
		return '';
	}

	$select_parts = array_map(
		static function ( string $column ): string {
			return "NULLIF(CAST(`" . esc_sql( $column ) . "` AS CHAR), '')";
		},
		$available_date_columns
	);
	$expression = 'COALESCE(' . implode( ',', $select_parts ) . ')';

	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT {$expression} FROM {$table} WHERE id = %d LIMIT 1", $licensee_id ) );
}

function ufsc_comp_format_birth_date( $value ): string {
	$date = ufsc_comp_parse_date_value( $value );
	return $date ? $date->format( 'd/m/Y' ) : '—';
}

function ufsc_comp_calculate_age( $birth_date, $reference_date = null ): string {
	$birth = ufsc_comp_parse_date_value( $birth_date );
	if ( ! $birth ) {
		return '—';
	}
	$reference = ufsc_comp_parse_date_value( $reference_date ?: current_time( 'mysql' ) );
	if ( ! $reference ) {
		$reference = new DateTimeImmutable( 'now', wp_timezone() );
	}
	$age = (int) $birth->diff( $reference )->y;
	return ( $age >= 0 && $age <= 120 ) ? (string) $age : '—';
}

function ufsc_comp_get_entries_by_competition( int $competition_id ): array {
	$repo = new EntryRepository();
	return $repo->list_with_details( array( 'view' => 'all', 'competition_id' => absint( $competition_id ) ), 5000, 0 );
}

function ufsc_comp_group_entries_by_category( array $entries ): array {
	$groups = array();
	foreach ( $entries as $entry ) {
		$parts = array(
			sanitize_text_field( (string) ufsc_comp_get_object_value( $entry, array( 'discipline' ) ) ),
			sanitize_text_field( (string) ufsc_comp_get_object_value( $entry, array( 'sex', 'sexe', 'licensee_sex', 'gender' ) ) ),
			sanitize_text_field( (string) ufsc_comp_get_object_value( $entry, array( 'age_category', 'age_group', 'categorie_age', 'category', 'category_name' ) ) ),
			sanitize_text_field( (string) ufsc_comp_get_object_value( $entry, array( 'weight_class', 'weight_category', 'weight_cat', 'categorie_poids' ) ) ),
			sanitize_text_field( (string) ufsc_comp_get_object_value( $entry, array( 'level', 'classe', 'class', 'niveau' ) ) ),
		);
		$key = implode( ' | ', array_map( static function ( $part ) { return '' !== $part ? $part : '—'; }, $parts ) );
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array( 'key' => $key, 'discipline' => $parts[0], 'sex' => $parts[1], 'category' => $parts[2], 'weight' => $parts[3], 'level' => $parts[4], 'entries' => array(), 'eligible' => array(), 'missing_data' => 0 );
		}
		$groups[ $key ]['entries'][] = $entry;
		$status = sanitize_key( (string) ( $entry->status ?? '' ) );
		if ( in_array( $status, array( 'approved', 'validated', 'valid', 'confirmed' ), true ) ) {
			$groups[ $key ]['eligible'][] = $entry;
		}
		if ( '' === $parts[1] || '' === $parts[2] || '' === $parts[3] ) {
			$groups[ $key ]['missing_data']++;
		}
	}
	return $groups;
}

function ufsc_comp_get_category_diagnostics( int $competition_id ): array {
	$groups = ufsc_comp_group_entries_by_category( ufsc_comp_get_entries_by_competition( $competition_id ) );
	$rows = array();
	foreach ( $groups as $group ) {
		$total = count( $group['entries'] );
		$eligible = count( $group['eligible'] );
		if ( $group['missing_data'] > 0 ) {
			$status = 'missing'; $label = __( 'Données manquantes', 'ufsc-licence-competition' ); $recommendation = __( 'Compléter sexe, catégorie ou poids avant génération.', 'ufsc-licence-competition' );
		} elseif ( 0 === $eligible ) {
			$status = 'empty'; $label = __( 'Catégorie vide', 'ufsc-licence-competition' ); $recommendation = __( 'Aucun combattant éligible.', 'ufsc-licence-competition' );
		} elseif ( 1 === $eligible ) {
			$status = 'lone'; $label = __( 'Pas d’opposant', 'ufsc-licence-competition' ); $recommendation = __( 'Regrouper ou laisser en attente.', 'ufsc-licence-competition' );
		} elseif ( 2 === $eligible ) {
			$status = 'ready'; $label = __( 'Prête', 'ufsc-licence-competition' ); $recommendation = __( 'Combat simple possible.', 'ufsc-licence-competition' );
		} elseif ( $eligible <= 6 ) {
			$status = 'pool'; $label = __( 'Générable en poule', 'ufsc-licence-competition' ); $recommendation = sprintf( __( '%d combattants : poule ou tableau possible.', 'ufsc-licence-competition' ), $eligible );
		} else {
			$status = 'bracket'; $label = __( 'Générable en tableau', 'ufsc-licence-competition' ); $recommendation = __( 'Tableau recommandé.', 'ufsc-licence-competition' );
		}
		$rows[] = array_merge( $group, compact( 'total', 'eligible', 'status', 'label', 'recommendation' ) );
	}
	return $rows;
}

function ufsc_comp_detect_categories_without_opponents( int $competition_id ): array {
	return array_values( array_filter( ufsc_comp_get_category_diagnostics( $competition_id ), static function ( $row ) { return (int) ( $row['eligible'] ?? 0 ) < 2; } ) );
}

function ufsc_comp_get_competition_summary( int $competition_id ): array {
	global $wpdb;
	$competition_id = absint( $competition_id );
	$competition = $competition_id ? ( new CompetitionRepository() )->get( $competition_id, true ) : null;
	$entries = $competition_id ? ufsc_comp_get_entries_by_competition( $competition_id ) : array();
	$statuses = array( 'draft' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0 );
	$eligible = 0;
	foreach ( $entries as $entry ) {
		$status = sanitize_key( (string) ( $entry->status ?? 'draft' ) );
		if ( ! isset( $statuses[ $status ] ) ) { $statuses[ $status ] = 0; }
		$statuses[ $status ]++;
		if ( in_array( $status, array( 'approved', 'validated', 'valid', 'confirmed' ), true ) ) { $eligible++; }
	}
	$weighins = array( 'todo' => count( $entries ), 'ok' => 0, 'over' => 0 );
	if ( $competition_id && Db::table_exists( Db::weighins_table() ) && Db::has_table_column( Db::weighins_table(), 'status' ) ) {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) total FROM ' . Db::weighins_table() . ' WHERE competition_id = %d GROUP BY status', $competition_id ) );
		$done = 0;
		foreach ( (array) $rows as $row ) {
			$status = sanitize_key( (string) ( $row->status ?? '' ) );
			$total = (int) ( $row->total ?? 0 );
			$done += $total;
			if ( in_array( $status, array( 'ok', 'valid', 'validated' ), true ) ) { $weighins['ok'] += $total; }
			if ( in_array( $status, array( 'over', 'out', 'ko', 'hors_limite' ), true ) ) { $weighins['over'] += $total; }
		}
		$weighins['todo'] = max( 0, count( $entries ) - $done );
	}
	$fights = array( 'total' => 0, 'scheduled' => 0, 'completed' => 0, 'locked' => 0 );
	if ( $competition_id && Db::table_exists( Db::fights_table() ) && Db::has_table_column( Db::fights_table(), 'status' ) ) {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) total FROM ' . Db::fights_table() . ' WHERE competition_id = %d GROUP BY status', $competition_id ) );
		foreach ( (array) $rows as $row ) {
			$status = sanitize_key( (string) ( $row->status ?? 'scheduled' ) );
			$total = (int) ( $row->total ?? 0 );
			$fights['total'] += $total;
			if ( isset( $fights[ $status ] ) ) { $fights[ $status ] += $total; }
		}
	}
	$diagnostics = $competition_id ? ufsc_comp_get_category_diagnostics( $competition_id ) : array();
	$ready = count( array_filter( $diagnostics, static function ( $row ) { return (int) ( $row['eligible'] ?? 0 ) >= 2; } ) );
	$lone = count( array_filter( $diagnostics, static function ( $row ) { return (int) ( $row['eligible'] ?? 0 ) === 1; } ) );
	return compact( 'competition', 'statuses', 'eligible', 'weighins', 'fights', 'diagnostics', 'ready', 'lone' ) + array( 'total_entries' => count( $entries ), 'non_eligible' => max( 0, count( $entries ) - $eligible ) );
}

function ufsc_comp_render_admin_back_button( string $target = 'bouts', array $args = array() ): void {
	$pages = array( 'competitions' => 'ufsc-competitions', 'competition' => 'ufsc-competitions', 'bouts' => 'ufsc-competitions-bouts', 'weighins' => 'ufsc-competitions-weighins', 'entries' => 'ufsc-competitions-entries', 'print' => 'ufsc-competitions-print' );
	$page = $pages[ $target ] ?? $pages['bouts'];
	$url = add_query_arg( array_merge( array( 'page' => $page ), array_map( 'sanitize_text_field', $args ) ), admin_url( 'admin.php' ) );
	echo '<p><a class="button" href="' . esc_url( $url ) . '">← ' . esc_html__( 'Retour', 'ufsc-licence-competition' ) . '</a></p>';
}

function ufsc_comp_assign_surfaces_to_fights( array $fights, array $surfaces = array() ): array {
	if ( empty( $surfaces ) ) {
		$surfaces = array( 'Tatami 1', 'Tatami 2', 'Ring 1', 'Ring 2' );
	}
	$surfaces = array_values( array_filter( array_map( 'sanitize_text_field', $surfaces ) ) );
	if ( ! $surfaces ) { return $fights; }
	foreach ( $fights as $index => $fight ) {
		$surface = $surfaces[ $index % count( $surfaces ) ];
		if ( is_array( $fight ) ) { $fights[ $index ]['ring'] = $surface; }
		elseif ( is_object( $fight ) ) { $fight->ring = $surface; }
	}
	return $fights;
}


if ( ! function_exists( 'ufsc_comp_current_user_can' ) ) {
	function ufsc_comp_current_user_can( string $capability, int $competition_id = 0 ): bool {
		if ( class_exists( '\UFSC\Competitions\Capabilities' ) && method_exists( '\UFSC\Competitions\Capabilities', 'current_user_can' ) ) {
			return \UFSC\Competitions\Capabilities::current_user_can( $capability, $competition_id );
		}
		return function_exists( 'ufsc_lc_user_can' ) ? ufsc_lc_user_can( sanitize_key( $capability ) ) : current_user_can( sanitize_key( $capability ) );
	}
}
