<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Admin\Tables\Quality_Table;
use UFSC\Competitions\Services\DisciplineRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quality_Page {
	private $entries;
	private $competitions;

	public function __construct() {
		$this->entries = new EntryRepository();
		$this->competitions = new CompetitionRepository();
	}

	public function render() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$issues = $this->collect_issues();
		$table = new Quality_Table( $issues );
		$table->prepare_items();

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php esc_html_e( 'Contrôles qualité', 'ufsc-licence-competition' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Contrôlez les inscriptions avant génération des combats.', 'ufsc-licence-competition' ); ?></p>
			<?php $table->display(); ?>
		</div>
		<?php
	}

	private function collect_issues() {
		$entries = $this->entries->list( array( 'view' => 'all' ), 500, 0 );
		$competitions = $this->competitions->list( array( 'view' => 'all' ), 200, 0 );
		$index = array();

		foreach ( $competitions as $competition ) {
			$index[ $competition->id ] = $competition;
		}

		$issues = array();
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry->deleted_at ) ) {
				continue;
			}
			if ( empty( $entry->category_id ) ) {
				$competition = $index[ $entry->competition_id ] ?? null;
				$issues[] = array(
					'issue'       => __( 'Catégorie manquante', 'ufsc-licence-competition' ),
					'competition' => $competition ? $competition->name : '',
					'licensee'    => sprintf( '#%d', (int) $entry->licensee_id ),
					'details'     => $competition ? DisciplineRegistry::get_label( $competition->discipline ) : '',
				);
			}
			if ( 'submitted' === $entry->status ) {
				$competition = $index[ $entry->competition_id ] ?? null;
				$issues[] = array(
					'issue'       => __( 'Inscription en attente de validation', 'ufsc-licence-competition' ),
					'competition' => $competition ? $competition->name : '',
					'licensee'    => sprintf( '#%d', (int) $entry->licensee_id ),
					'details'     => $competition ? DisciplineRegistry::get_label( $competition->discipline ) : '',
				);
			}
		}

		return $issues;
	}
}
