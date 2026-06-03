<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Non-destructive preview/import helper for category reference presets. */
class CategoryPresetService {
	private $categories;
	private $competitions;

	public function __construct() {
		$this->categories   = new CategoryRepository();
		$this->competitions = new CompetitionRepository();
	}

	public function get_available_seasons(): array {
		return array( '2025-2026' => __( '2025/2026', 'ufsc-licence-competition' ) );
	}

	public function get_tatami_disciplines(): array {
		$output = array();
		foreach ( DisciplineRegistry::get_disciplines_with_types() as $key => $data ) {
			if ( DisciplineRegistry::TYPE_TATAMI === ( $data['type'] ?? '' ) ) {
				$output[ $key ] = (string) ( $data['label'] ?? $key );
			}
		}
		return $output;
	}

	public function preview( $competition_id, $discipline, $season ): array {
		$competition_id = absint( $competition_id );
		$discipline     = sanitize_key( $discipline );
		$season         = sanitize_text_field( (string) $season );
		$existing       = $competition_id ? $this->categories->list( array( 'competition_id' => $competition_id, 'view' => 'all' ), 10000, 0 ) : array();
		$existing_keys  = array();
		$existing_names = array();
		foreach ( $existing as $category ) {
			$key = $this->category_key( $category );
			$name_key = $this->category_name_key( $category );
			$existing_keys[ $key ] = true;
			if ( '' !== $name_key ) {
				$existing_names[ $name_key ][ $key ] = true;
			}
		}

		$rows = array();
		$preset_keys = array();
		foreach ( $this->get_preset_rows( $discipline, $season ) as $preset ) {
			$key = $this->category_key( (object) $preset );
			$name_key = $this->category_name_key( (object) $preset );
			$preset['exists'] = isset( $existing_keys[ $key ] );
			$preset['conflict'] = false;
			$preset['conflict_reason'] = '';
			if ( ! $preset['exists'] && isset( $existing_names[ $name_key ] ) && ! isset( $existing_names[ $name_key ][ $key ] ) ) {
				$preset['conflict'] = true;
				$preset['conflict_reason'] = __( 'Un libellé proche existe déjà avec des paramètres âge/poids/sexe différents.', 'ufsc-licence-competition' );
			}
			if ( isset( $preset_keys[ $key ] ) ) {
				$preset['conflict'] = true;
				$preset['conflict_reason'] = __( 'Doublon détecté dans le référentiel préprogrammé.', 'ufsc-licence-competition' );
			}
			$preset_keys[ $key ] = true;
			$rows[] = $preset;
		}

		return array(
			'competition_id' => $competition_id,
			'discipline'     => $discipline,
			'season'         => $season,
			'rows'           => $rows,
			'existing_count' => count( $existing ),
		);
	}

	public function import_missing( $competition_id, $discipline, $season ): array {
		$preview = $this->preview( $competition_id, $discipline, $season );
		$created = 0;
		$ignored = 0;
		$conflicts = 0;

		foreach ( $preview['rows'] as $row ) {
			if ( ! empty( $row['exists'] ) ) {
				$ignored++;
				continue;
			}
			if ( ! empty( $row['conflict'] ) ) {
				$conflicts++;
				continue;
			}
			$id = $this->categories->insert(
				array(
					'competition_id' => (int) $preview['competition_id'],
					'discipline'     => (string) $preview['discipline'],
					'name'           => (string) $row['name'],
					'age_min'        => $row['age_min'],
					'age_max'        => $row['age_max'],
					'weight_min'     => $row['weight_min'],
					'weight_max'     => $row['weight_max'],
					'sex'            => (string) $row['sex'],
					'level'          => (string) $row['level'],
					'format'         => (string) $row['format'],
				)
			);
			if ( $id ) {
				$created++;
			} else {
				$conflicts++;
			}
		}

		return array( 'created' => $created, 'ignored' => $ignored, 'conflicts' => $conflicts );
	}

	private function get_preset_rows( $discipline, $season ): array {
		if ( '2025-2026' !== (string) $season || ! isset( $this->get_tatami_disciplines()[ $discipline ] ) ) {
			return array();
		}
		$preset = CategoryPresetRegistry::get_preset( DisciplineRegistry::TYPE_TATAMI );
		$rows = isset( $preset['categories'] ) && is_array( $preset['categories'] ) ? $preset['categories'] : array();
		foreach ( $rows as &$row ) {
			$row['discipline'] = $discipline;
		}
		return $rows;
	}

	private function category_name_key( $category ): string {
		return CompetitionStatsService::normalize_category_label( $category->name ?? '' );
	}

	private function category_key( $category ): string {
		return implode(
			'|',
			array(
				$this->category_name_key( $category ),
				(string) ( $category->age_min ?? '' ),
				(string) ( $category->age_max ?? '' ),
				(string) ( $category->weight_min ?? '' ),
				(string) ( $category->weight_max ?? '' ),
				strtolower( (string) ( $category->sex ?? '' ) ),
			)
		);
	}
}
