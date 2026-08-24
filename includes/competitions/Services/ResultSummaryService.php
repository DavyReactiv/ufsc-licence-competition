<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ResultSummaryService {
	private $fights;
	private $entries;

	public function __construct() {
		$this->fights  = new FightRepository();
		$this->entries = new EntryRepository();
	}

	public function build_competition_summary( int $competition_id ): array {
		$fights  = $this->fights->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );
		$entries = $this->entries->list_with_details( array( 'view' => 'all', 'competition_id' => $competition_id ), 5000, 0 );
		$entry_map = array();
		foreach ( $entries as $entry ) {
			$entry_map[ (int) $entry->id ] = $entry;
		}

		$by_category = array();
		$completed   = 0;
		$no_result   = 0;
		$litiges     = 0;
		$absents     = 0;
		$forfaits    = 0;

		foreach ( $fights as $fight ) {
			$category_id = (int) ( $fight->category_id ?? 0 );
			if ( ! isset( $by_category[ $category_id ] ) ) {
				$by_category[ $category_id ] = array(
					'fights'       => array(),
					'podium'       => array(),
					'pool_ranking' => array(),
					'status'       => 'provisoire',
					'notes'        => array(),
				);
			}

			$by_category[ $category_id ]['fights'][] = $fight;
			$status = (string) ( $fight->status ?? '' );
			if ( 'completed' === $status ) {
				++$completed;
			}

			$method = FightResultPersistence::get_method( $fight );
			$winner = (int) ( $fight->winner_entry_id ?? 0 );
			if ( 'completed' !== $status || ( $winner <= 0 && ! in_array( $method, array( 'no_contest', 'litige', 'annule' ), true ) ) ) {
				++$no_result;
			}
			if ( 'litige' === $method || 'disputed' === $status ) {
				++$litiges;
			}
			if ( 'absence' === $method || 'absent' === $status ) {
				++$absents;
			}
			if ( 'forfait' === $method ) {
				++$forfaits;
			}
		}

		foreach ( $by_category as $category_id => &$data ) {
			$data['pool_ranking'] = $this->build_pool_ranking( $data['fights'], $entry_map );
			$data['podium']       = $this->build_provisional_podium( $data['fights'], $entry_map );
			if ( ! empty( $data['pool_ranking']['warnings'] ) || ! empty( $data['podium']['warnings'] ) ) {
				$data['status'] = 'a_verifier';
				$data['notes']  = array_merge( $data['pool_ranking']['warnings'], $data['podium']['warnings'] );
			}
		}
		unset( $data );

		return array(
			'completed_fights'      => $completed,
			'fights_without_result' => $no_result,
			'litiges'               => $litiges,
			'absents'               => $absents,
			'forfaits'              => $forfaits,
			'categories'            => $by_category,
		);
	}

	private function build_pool_ranking( array $fights, array $entry_map ): array {
		$rows     = array();
		$warnings = array();
		$has_pool = false;

		foreach ( $fights as $fight ) {
			$phase = sanitize_key( (string) ( $fight->phase ?? '' ) );
			if ( ! in_array( $phase, array( 'pool', 'poule' ), true ) ) {
				continue;
			}
			$has_pool = true;

			$red  = (int) ( $fight->red_entry_id ?? 0 );
			$blue = (int) ( $fight->blue_entry_id ?? 0 );
			if ( $red <= 0 || $blue <= 0 ) {
				continue;
			}

			foreach ( array( $red, $blue ) as $entry_id ) {
				if ( ! isset( $rows[ $entry_id ] ) ) {
					$entry = $entry_map[ $entry_id ] ?? null;
					$rows[ $entry_id ] = array(
						'entry_id'       => $entry_id,
						'name'           => $this->entry_name( $entry ),
						'club'           => (string) ( $entry->club_name ?? '' ),
						'fights'         => 0,
						'wins'           => 0,
						'losses'         => 0,
						'points_for'     => 0.0,
						'points_against' => 0.0,
						'diff'           => 0.0,
						'forfaits'       => 0,
						'litiges'        => 0,
						'status'         => 'provisoire',
					);
				}
			}

			++$rows[ $red ]['fights'];
			++$rows[ $blue ]['fights'];
			$method = FightResultPersistence::get_method( $fight );
			$winner = (int) ( $fight->winner_entry_id ?? 0 );

			if ( 'litige' === $method ) {
				++$rows[ $red ]['litiges'];
				++$rows[ $blue ]['litiges'];
				$warnings[] = 'Classement provisoire — litige.';
			}
			if ( 'forfait' === $method ) {
				if ( $winner === $red ) {
					++$rows[ $blue ]['forfaits'];
				} elseif ( $winner === $blue ) {
					++$rows[ $red ]['forfaits'];
				}
			}
			if ( $winner === $red ) {
				++$rows[ $red ]['wins'];
				++$rows[ $blue ]['losses'];
			} elseif ( $winner === $blue ) {
				++$rows[ $blue ]['wins'];
				++$rows[ $red ]['losses'];
			} else {
				$warnings[] = 'Classement provisoire — résultat manquant.';
			}

			$score_red  = str_replace( ',', '.', trim( (string) ( $fight->score_red ?? '' ) ) );
			$score_blue = str_replace( ',', '.', trim( (string) ( $fight->score_blue ?? '' ) ) );
			if ( is_numeric( $score_red ) && is_numeric( $score_blue ) ) {
				$rows[ $red ]['points_for']     += (float) $score_red;
				$rows[ $red ]['points_against'] += (float) $score_blue;
				$rows[ $blue ]['points_for']     += (float) $score_blue;
				$rows[ $blue ]['points_against'] += (float) $score_red;
			} else {
				$warnings[] = 'Classement provisoire — scores incomplets.';
			}
		}

		foreach ( $rows as &$row ) {
			$row['diff'] = $row['points_for'] - $row['points_against'];
			if ( $row['litiges'] > 0 ) {
				$row['status'] = 'a_verifier';
			}
		}
		unset( $row );

		usort(
			$rows,
			static function ( $a, $b ): int {
				return array( $b['wins'], $b['diff'], $b['points_for'], $a['losses'] ) <=> array( $a['wins'], $a['diff'], $a['points_for'], $b['losses'] );
			}
		);

		if ( $has_pool && count( $rows ) >= 2 && $rows[0]['wins'] === $rows[1]['wins'] ) {
			$warnings[] = 'Égalité à départager manuellement.';
		}

		return array(
			'rows'     => $rows,
			'warnings' => array_values( array_unique( $warnings ) ),
			'has_pool' => $has_pool,
		);
	}

	private function build_provisional_podium( array $fights, array $entry_map ): array {
		$warnings = array();
		$completed = array_values(
			array_filter(
				$fights,
				static function ( $fight ): bool {
					return (string) ( $fight->status ?? '' ) === 'completed';
				}
			)
		);
		if ( empty( $completed ) ) {
			return array(
				'top3'     => array(),
				'warnings' => array( 'Données insuffisantes pour podium.' ),
			);
		}

		usort(
			$completed,
			static function ( $a, $b ): int {
				$round_compare = (int) ( $b->round_no ?? 0 ) <=> (int) ( $a->round_no ?? 0 );
				return 0 !== $round_compare ? $round_compare : (int) ( $b->fight_no ?? 0 ) <=> (int) ( $a->fight_no ?? 0 );
			}
		);

		$final  = $completed[0];
		$winner = (int) ( $final->winner_entry_id ?? 0 );
		if ( $winner <= 0 ) {
			$warnings[] = 'Podium provisoire à vérifier (finale sans vainqueur).';
		}

		$red      = (int) ( $final->red_entry_id ?? 0 );
		$blue     = (int) ( $final->blue_entry_id ?? 0 );
		$finalist = $winner > 0 ? ( $winner === $red ? $blue : $red ) : 0;
		$semi_losers = array();
		foreach ( $completed as $fight ) {
			if ( (int) ( $fight->round_no ?? 0 ) !== (int) ( $final->round_no ?? 1 ) - 1 ) {
				continue;
			}
			$semi_winner = (int) ( $fight->winner_entry_id ?? 0 );
			$semi_red    = (int) ( $fight->red_entry_id ?? 0 );
			$semi_blue   = (int) ( $fight->blue_entry_id ?? 0 );
			$semi_losers[] = $semi_winner === $semi_red ? $semi_blue : ( $semi_winner === $semi_blue ? $semi_red : 0 );
		}

		$top3 = array_filter( array( $winner, $finalist, $semi_losers[0] ?? 0 ) );
		return array(
			'top3' => array_map(
				function ( $entry_id ) use ( $entry_map ): array {
					return array(
						'entry_id' => $entry_id,
						'label'    => $this->entry_name( $entry_map[ $entry_id ] ?? null ),
					);
				},
				$top3
			),
			'warnings' => $warnings,
		);
	}

	private function entry_name( $entry ): string {
		if ( ! $entry ) {
			return 'N/A';
		}

		$name = trim( (string) ( $entry->participant_name ?? '' ) );
		if ( '' === $name ) {
			$name = trim( (string) ( $entry->licensee_last_name ?? '' ) . ' ' . (string) ( $entry->licensee_first_name ?? '' ) );
		}

		return '' !== $name ? $name : 'Entry #' . (int) ( $entry->id ?? 0 );
	}
}
