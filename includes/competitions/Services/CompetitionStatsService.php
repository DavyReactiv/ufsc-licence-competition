<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Repositories\WeighInRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only competition counters used by admin pages.
 *
 * The service deliberately does not mutate data. Its purpose is to keep the
 * dashboard, entries, weigh-ins and quality pages aligned on the same scope and
 * to make intentional exclusions visible to operators before the event.
 */
class CompetitionStatsService {
	private $competition_repository;
	private $entry_repository;
	private $weighin_repository;
	private $fight_repository;
	private $category_repository;

	public function __construct() {
		$this->competition_repository = new CompetitionRepository();
		$this->entry_repository       = new EntryRepository();
		$this->weighin_repository     = new WeighInRepository();
		$this->fight_repository       = new FightRepository();
		$this->category_repository    = new CategoryRepository();
	}

	public function get_competition_stats( $competition_id ): array {
		$competition_id = absint( $competition_id );
		$competition    = $competition_id ? $this->competition_repository->get( $competition_id, true ) : null;
		$entries        = $competition_id ? $this->entry_repository->list_with_details( array( 'competition_id' => $competition_id, 'view' => 'all' ), 5000, 0 ) : array();
		$entry_ids      = array();
		$by_status      = $this->get_empty_status_counters();
		$text_categories = array();
		$weighin_visible_entries = array();
		$weighin_excluded = array(
			'draft'     => 0,
			'rejected'  => 0,
			'cancelled' => 0,
			'archived'  => 0,
			'other'     => 0,
		);

		foreach ( $entries as $entry ) {
			$entry_id = absint( $entry->id ?? 0 );
			if ( $entry_id ) {
				$entry_ids[] = $entry_id;
			}

			$status = EntriesWorkflow::normalize_status( (string) ( $entry->status ?? '' ) );
			if ( ! isset( $by_status[ $status ] ) ) {
				$by_status[ $status ] = 0;
			}
			$by_status[ $status ]++;
			$by_status['total']++;

			$category_label = $this->get_entry_category_label( $entry );
			$normalized_category = self::normalize_category_label( $category_label );
			if ( '' !== $normalized_category ) {
				if ( ! isset( $text_categories[ $normalized_category ] ) ) {
					$text_categories[ $normalized_category ] = array( 'label' => $category_label, 'count' => 0 );
				}
				$text_categories[ $normalized_category ]['count']++;
			}

			if ( in_array( $status, array_merge( EntriesWorkflow::get_review_queue_statuses(), array( 'approved' ) ), true ) ) {
				$weighin_visible_entries[] = $entry;
			} elseif ( isset( $weighin_excluded[ $status ] ) ) {
				$weighin_excluded[ $status ]++;
			} else {
				$weighin_excluded['other']++;
			}
		}

		$weighins = $competition_id ? $this->weighin_repository->get_for_entries( $competition_id, $entry_ids ) : array();
		$weighin_stats = $this->build_weighin_stats( $competition, $weighin_visible_entries, $weighins );
		$fight_stats   = $this->build_fight_stats( $competition_id );
		$category_stats = $this->build_category_stats( $competition_id, $text_categories );

		return array(
			'competition_id'       => $competition_id,
			'competition_exists'   => (bool) $competition,
			'entries'              => array(
				'total'        => (int) $by_status['total'],
				'by_status'    => $by_status,
				'to_validate'  => (int) $by_status['submitted'] + (int) $by_status['pending'],
				'approved'     => (int) $by_status['approved'],
				'rejected'     => (int) $by_status['rejected'],
				'archived'     => 0,
			),
			'weighins'             => array_merge( $weighin_stats, array( 'excluded_by_status' => $weighin_excluded ) ),
			'fights'               => $fight_stats,
			'categories'           => $category_stats,
			'sources'              => array(
				'entries'    => __( 'Inscriptions actives de la compétition (EntryRepository::list_with_details, vue all, hors corbeille logique).', 'ufsc-licence-competition' ),
				'weighins'   => __( 'Périmètre Pesées actuel : inscriptions soumises + en attente + approuvées, sans rendre les statuts non validés éligibles automatiquement.', 'ufsc-licence-competition' ),
				'categories' => __( 'Référentiel configuré : table des catégories. Catégories déclarées : libellés textuels présents dans les inscriptions.', 'ufsc-licence-competition' ),
				'fights'     => __( 'Combats actifs de la compétition (hors corbeille logique si la colonne existe).', 'ufsc-licence-competition' ),
			),
		);
	}

	public static function normalize_category_label( $label ): string {
		$label = is_scalar( $label ) ? (string) $label : '';
		$label = trim( wp_strip_all_tags( $label ) );
		if ( '' === $label ) {
			return '';
		}
		if ( function_exists( 'remove_accents' ) ) {
			$label = remove_accents( $label );
		}
		$label = strtolower( $label );
		$label = str_replace( array( 'poussin/e', 'cadet/te' ), array( 'poussin', 'cadet' ), $label );
		$label = preg_replace( '/[^a-z0-9]+/u', ' ', $label );
		$label = trim( preg_replace( '/\s+/', ' ', (string) $label ) );

		$aliases = array(
			'pre poussins' => 'pre poussin',
			'pre poussin'  => 'pre poussin',
			'poussins'     => 'poussin',
			'poussin'      => 'poussin',
			'pupilles'     => 'pupille',
			'pupille'      => 'pupille',
			'benjamins'    => 'benjamin',
			'benjamin'     => 'benjamin',
			'minimes'      => 'minime',
			'minime'       => 'minime',
			'cadettes'     => 'cadette',
			'cadets'       => 'cadet',
			'cadet'        => 'cadet',
			'juniors'      => 'junior',
			'junior'       => 'junior',
			'seniors'      => 'senior',
			'senior'       => 'senior',
			'veterans'     => 'veteran',
			'veteran'      => 'veteran',
		);

		return $aliases[ $label ] ?? $label;
	}

	private function get_empty_status_counters(): array {
		return array(
			'total'     => 0,
			'draft'     => 0,
			'submitted' => 0,
			'pending'   => 0,
			'approved'  => 0,
			'rejected'  => 0,
			'cancelled' => 0,
		);
	}

	private function get_entry_category_label( $entry ): string {
		foreach ( array( 'category_name', 'category', 'category_label' ) as $key ) {
			$value = is_object( $entry ) ? ( $entry->{$key} ?? '' ) : '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private function build_weighin_stats( $competition, array $visible_entries, array $weighins ): array {
		$tolerance = isset( $competition->weighin_tolerance_kg ) ? (float) $competition->weighin_tolerance_kg : 0.0;
		$validated = 0;
		$pending = 0;
		$out_of_limit = 0;
		$eligible_generation = 0;

		foreach ( $visible_entries as $entry ) {
			$entry_id = absint( $entry->id ?? 0 );
			$row = $entry_id && isset( $weighins[ $entry_id ] ) ? $weighins[ $entry_id ] : null;
			$status = sanitize_key( (string) ( $row->status ?? '' ) );
			$entry_weight = isset( $entry->weight_kg ) && '' !== (string) $entry->weight_kg ? (float) $entry->weight_kg : null;
			$is_valid = $this->weighin_repository->is_valid_weighin_row( $row, $tolerance, $entry_weight );
			if ( $is_valid ) {
				$validated++;
				if ( 'approved' === EntriesWorkflow::normalize_status( (string) ( $entry->status ?? '' ) ) ) {
					$eligible_generation++;
				}
			} elseif ( in_array( $status, array( 'failed', 'refused', 'out_of_limit' ), true ) ) {
				$out_of_limit++;
			} else {
				$pending++;
			}
		}

		return array(
			'visible_entries'       => count( $visible_entries ),
			'validated'             => $validated,
			'pending'               => $pending,
			'out_of_limit'          => $out_of_limit,
			'eligible_generation'   => $eligible_generation,
			'remaining'             => max( 0, count( $visible_entries ) - $validated ),
		);
	}

	private function build_fight_stats( $competition_id ): array {
		$fights = $competition_id ? $this->fight_repository->list( array( 'competition_id' => $competition_id ), 10000, 0 ) : array();
		$results = 0;
		$locked = 0;
		$surfaces = array();
		foreach ( $fights as $fight ) {
			$status = $this->fight_repository->normalize_fight_status( (string) ( $fight->status ?? '' ) );
			if ( 'completed' === $status || absint( $fight->winner_entry_id ?? 0 ) > 0 || '' !== trim( (string) ( $fight->result_method ?? '' ) ) ) {
				$results++;
			}
			if ( 'locked' === $status ) {
				$locked++;
			}
			$surface = trim( (string) ( $fight->surface_label ?? $fight->surface ?? '' ) );
			if ( '' !== $surface ) {
				$surfaces[ $surface ] = true;
			}
		}

		return array(
			'generated'       => count( $fights ),
			'results_entered' => $results,
			'locked'          => $locked,
			'surfaces'        => count( $surfaces ),
		);
	}

	private function build_category_stats( $competition_id, array $text_categories ): array {
		$configured = $competition_id ? $this->category_repository->list( array( 'competition_id' => $competition_id, 'view' => 'all' ), 10000, 0 ) : array();
		$configured_labels = array();
		foreach ( $configured as $category ) {
			$normalized = self::normalize_category_label( $category->name ?? '' );
			if ( '' !== $normalized ) {
				$configured_labels[ $normalized ] = true;
			}
		}

		$unmatched = array();
		foreach ( $text_categories as $normalized => $data ) {
			if ( ! isset( $configured_labels[ $normalized ] ) ) {
				$unmatched[] = $data;
			}
		}

		return array(
			'configured_count'       => count( $configured ),
			'text_distinct_count'    => count( $text_categories ),
			'text_categories'        => array_values( $text_categories ),
			'unmatched_text_labels'  => $unmatched,
		);
	}
}
