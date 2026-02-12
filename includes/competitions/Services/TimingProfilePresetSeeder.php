<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Repositories\TimingProfileRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TimingProfilePresetSeeder {
	public static function install_missing(): array {
		$repo = new TimingProfileRepository();
		$existing = $repo->list( array(), 1000, 0 );
		$existing_hashes = array();

		foreach ( $existing as $profile ) {
			$existing_hashes[ self::hash_row( (array) $profile ) ] = true;
		}

		$inserted = 0;
		$skipped = 0;
		foreach ( self::presets() as $preset ) {
			$hash = self::hash_row( $preset );
			if ( isset( $existing_hashes[ $hash ] ) ) {
				$skipped++;
				continue;
			}

			$repo->insert( $preset );
			$existing_hashes[ $hash ] = true;
			$inserted++;
		}

		return array(
			'inserted' => $inserted,
			'skipped'  => $skipped,
		);
	}

	private static function hash_row( array $row ): string {
		$data = array(
			'discipline' => (string) ( $row['discipline'] ?? '' ),
			'competition_type' => (string) ( $row['competition_type'] ?? '' ),
			'surface_type' => (string) ( $row['surface_type'] ?? '' ),
			'age_min' => (int) ( $row['age_min'] ?? 0 ),
			'age_max' => (int) ( $row['age_max'] ?? 0 ),
			'level' => (string) ( $row['level'] ?? '' ),
			'format' => (string) ( $row['format'] ?? '' ),
			'round_duration' => (float) ( $row['round_duration'] ?? 0 ),
			'rounds' => (int) ( $row['rounds'] ?? 0 ),
			'break_duration' => (float) ( $row['break_duration'] ?? 0 ),
		);

		return md5( wp_json_encode( $data ) );
	}

	private static function presets(): array {
		$shared = array(
			'discipline' => '',
			'competition_type' => '',
			'surface_type' => '',
			'format' => '',
			'fight_pause' => 0,
		);

		return array(
			array_merge( $shared, array( 'name' => 'UFSC Poussins/Pupilles/Benjamins', 'age_min' => 6, 'age_max' => 11, 'level' => 'Poussins/Pupilles/Benjamins', 'round_duration' => 1.0, 'rounds' => 3, 'break_duration' => 1.0 ) ),
			array_merge( $shared, array( 'name' => 'UFSC Minimes', 'age_min' => 12, 'age_max' => 13, 'level' => 'Minimes', 'round_duration' => 1.5, 'rounds' => 3, 'break_duration' => 1.0 ) ),
			array_merge( $shared, array( 'name' => 'UFSC Cadets', 'age_min' => 14, 'age_max' => 15, 'level' => 'Cadets', 'round_duration' => 1.5, 'rounds' => 3, 'break_duration' => 1.0 ) ),
			array_merge( $shared, array( 'name' => 'UFSC Juniors Assaut', 'age_min' => 16, 'age_max' => 17, 'level' => 'Juniors Assaut', 'round_duration' => 2.0, 'rounds' => 3, 'break_duration' => 1.5 ) ),
			array_merge( $shared, array( 'name' => 'UFSC Juniors Combat', 'age_min' => 17, 'age_max' => 18, 'level' => 'Juniors Combat', 'round_duration' => 2.0, 'rounds' => 3, 'break_duration' => 1.5 ) ),
			array_merge( $shared, array( 'name' => 'UFSC Classe D/C/B/A', 'age_min' => 18, 'age_max' => 40, 'level' => 'Classe D/C/B/A', 'round_duration' => 2.0, 'rounds' => 3, 'break_duration' => 2.0 ) ),
			array_merge( $shared, array( 'name' => 'UFSC Vétérans', 'age_min' => 41, 'age_max' => 50, 'level' => 'Vétérans', 'round_duration' => 2.0, 'rounds' => 3, 'break_duration' => 2.0 ) ),
		);
	}
}
