<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Db;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Services\CompetitionMeta;
use UFSC\Competitions\Services\DisciplineRegistry;
use UFSC\Competitions\Services\EntryDeduplication;
use UFSC\Competitions\Services\WeightCategoryResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Entries_Import_Page {
	private const IMPORT_ACTION   = 'ufsc_competitions_entries_import_csv';
	private const TEMPLATE_ACTION = 'ufsc_competitions_entries_import_template';
	private const UNDO_ACTION     = 'ufsc_competitions_entries_import_undo_last';
	private const NONCE_ACTION    = 'ufsc_competitions_entries_import_csv';
	private const MAX_FILE_SIZE   = 5242880;
	private const NO_CLUB_LABEL   = 'noclub';
	private const UNKNOWN_SEX     = 'UNKNOWN';

	/** @var CompetitionRepository */
	private $competition_repository;

	/** @var EntryRepository */
	private $entry_repository;

	/** @var EntryFrontRepository */
	private $entry_front_repository;

	public function __construct() {
		$this->competition_repository = new CompetitionRepository();
		$this->entry_repository       = new EntryRepository();
		$this->entry_front_repository = new EntryFrontRepository();
	}

	public function register_actions(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'handle_import' ) );
		add_action( 'admin_post_' . self::TEMPLATE_ACTION, array( $this, 'handle_template_download' ) );
		add_action( 'admin_post_' . self::UNDO_ACTION, array( $this, 'handle_undo_last_import' ) );
	}

	public function render(): void {
		if ( ! Capabilities::user_can_manage_entries() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $this->competition_repository->list( $competition_filters, 500, 0 );

		$report                  = $this->get_report();
		$selected_competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$last_import             = $selected_competition_id ? $this->get_last_import_batch( $selected_competition_id ) : null;
		?>
		<div class="wrap ufsc-competitions-admin">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Intégration de données', 'ufsc-licence-competition' ); ?></p>
					<h1><?php esc_html_e( 'Import CSV des athlètes', 'ufsc-licence-competition' ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Sélectionnez une compétition, importez le CSV, puis vérifiez le rapport détaillé pour confirmer les dossiers traités.', 'ufsc-licence-competition' ); ?></p>
				</div>
			</header>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Sélectionnez une compétition, importez le CSV, puis consultez le rapport détaillé.', 'ufsc-licence-competition' ); ?></p></div>
			<?php if ( is_array( $last_import ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Dernier import détecté', 'ufsc-licence-competition' ); ?></strong><br/>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: datetime, 2: count */
								__( 'Batch du %1$s · %2$d lignes importées.', 'ufsc-licence-competition' ),
								(string) ( $last_import['imported_at'] ?? '' ),
								(int) ( $last_import['rows_count'] ?? 0 )
							)
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Confirmer l’annulation du dernier import ? Cette action supprimera uniquement les lignes de ce lot.', 'ufsc-licence-competition' ) ); ?>');">
						<?php wp_nonce_field( self::UNDO_ACTION ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::UNDO_ACTION ); ?>" />
						<input type="hidden" name="competition_id" value="<?php echo esc_attr( $selected_competition_id ); ?>" />
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Annuler le dernier import', 'ufsc-licence-competition' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="ufsc-competitions-form">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
				<div class="ufsc-admin-surface ufsc-admin-form-surface">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ufsc_entries_import_competition_id"><?php esc_html_e( 'Compétition cible', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<select name="competition_id" id="ufsc_entries_import_competition_id" class="regular-text" required>
								<option value="0"><?php esc_html_e( 'Sélectionner', 'ufsc-licence-competition' ); ?></option>
								<?php foreach ( $competitions as $competition ) : ?>
									<option value="<?php echo esc_attr( (int) $competition->id ); ?>" <?php selected( $selected_competition_id, (int) $competition->id ); ?>>
										<?php echo esc_html( sprintf( '%s (%s)', (string) $competition->name, (string) $competition->season ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ufsc_entries_import_csv"><?php esc_html_e( 'Fichier CSV', 'ufsc-licence-competition' ); ?></label></th>
						<td>
							<input type="file" name="entries_csv" id="ufsc_entries_import_csv" accept=".csv,text/csv" required />
							<p class="description"><?php esc_html_e( 'Colonnes supportées : nom, prenom, sexe, date_naissance, club_nom, club_id, discipline, niveau, categorie, poids, categorie_poids, numero_licence, numero_licence_asptt, email, telephone, certificat_medical, autorisation_parentale, statut_dossier, commentaire.', 'ufsc-licence-competition' ); ?></p>
						</td>
					</tr>
				</table>
				</div>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Importer', 'ufsc-licence-competition' ); ?></button>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => self::TEMPLATE_ACTION ), admin_url( 'admin-post.php' ) ), self::NONCE_ACTION ) ); ?>"><?php esc_html_e( 'Télécharger le modèle CSV', 'ufsc-licence-competition' ); ?></a>
				</p>
			</form>

			<?php if ( is_array( $report ) ) : ?>
				<?php $this->render_report( $report ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_template_download(): void {
		if ( ! Capabilities::user_can_manage_entries() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$headers = $this->get_supported_headers();
		$rows    = array(
			array(
				'nom'                    => 'DUPONT',
				'prenom'                 => 'Alice',
				'sexe'                   => 'F',
				'date_naissance'         => '2006-04-21',
				'club_nom'               => 'UFSC Exemple',
				'club_id'                => '',
				'discipline'             => 'kickboxing',
				'niveau'                 => 'intermediaire',
				'categorie'              => '',
				'poids'                  => '57.2',
				'categorie_poids'        => '',
				'fighter_number'         => '',
				'numero_licence'         => 'UFSC-001122',
				'numero_licence_asptt'   => '',
				'email'                  => 'alice@example.test',
				'telephone'              => '0600000000',
				'certificat_medical'     => 'oui',
				'autorisation_parentale' => 'oui',
				'statut_dossier'         => 'submitted',
				'commentaire'            => 'Exemple import avec licence',
			),
			array(
				'nom'                    => 'MARTIN',
				'prenom'                 => 'Noah',
				'sexe'                   => 'M',
				'date_naissance'         => '2008-10-12',
				'club_nom'               => '',
				'club_id'                => '',
				'discipline'             => 'kickboxing',
				'niveau'                 => '',
				'categorie'              => '',
				'poids'                  => '63',
				'categorie_poids'        => '',
				'fighter_number'         => '',
				'numero_licence'         => '',
				'numero_licence_asptt'   => '',
				'email'                  => '',
				'telephone'              => '',
				'certificat_medical'     => '1',
				'autorisation_parentale' => '0',
				'statut_dossier'         => 'draft',
				'commentaire'            => 'Exemple open sans licence',
			),
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ufsc-competition-entries-template.csv' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		fputs( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, $headers, ';' );
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $headers as $header ) {
				$line[] = (string) ( $row[ $header ] ?? '' );
			}
			fputcsv( $output, $line, ';' );
		}

		fclose( $output );
		exit;
	}

	public function handle_import(): void {
		if ( ! Capabilities::user_can_manage_entries() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		if ( ! $competition_id ) {
			$this->redirect_with_report(
				array(
					'total'    => 0,
					'imported' => 0,
					'skipped'  => 0,
					'rows'     => array(
						array(
							'line'    => 0,
							'status'  => 'error',
							'message' => __( 'Compétition introuvable.', 'ufsc-licence-competition' ),
						),
					),
				),
				0
			);
		}

		if ( class_exists( 'UFSC_LC_Scope' ) ) {
			ufsc_lc_safe_enforce_object_scope( $competition_id, 'competition' );
		} elseif ( method_exists( $this->competition_repository, 'assert_competition_in_scope' ) ) {
			$this->competition_repository->assert_competition_in_scope( $competition_id );
		}

		$competition = $this->competition_repository->get( $competition_id, true );
		if ( ! $competition ) {
			$this->redirect_with_report(
				array(
					'total'    => 0,
					'imported' => 0,
					'skipped'  => 0,
					'rows'     => array(
						array(
							'line'    => 0,
							'status'  => 'error',
							'message' => __( 'Compétition introuvable.', 'ufsc-licence-competition' ),
						),
					),
				),
				$competition_id
			);
		}

		$file             = $_FILES['entries_csv'] ?? array();
		$validation_error = $this->validate_uploaded_csv( $file );
		if ( is_wp_error( $validation_error ) ) {
			$this->redirect_with_report(
				array(
					'total'    => 0,
					'imported' => 0,
					'skipped'  => 0,
					'rows'     => array(
						array(
							'line'    => 0,
							'status'  => 'error',
							'message' => $validation_error->get_error_message(),
						),
					),
				),
				$competition_id
			);
		}

		$parsed = $this->read_csv_rows( $file['tmp_name'] );
		if ( is_wp_error( $parsed ) ) {
			$this->redirect_with_report(
				array(
					'total'    => 0,
					'imported' => 0,
					'skipped'  => 0,
					'rows'     => array(
						array(
							'line'    => 0,
							'status'  => 'error',
							'message' => $parsed->get_error_message(),
						),
					),
				),
				$competition_id
			);
		}

		$report = $this->import_rows( $competition, $parsed['rows'] );
		$this->redirect_with_report( $report, $competition_id );
	}

	public function handle_undo_last_import(): void {
		if ( ! Capabilities::user_can_manage_entries() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::UNDO_ACTION );

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$last_import    = $competition_id ? $this->get_last_import_batch( $competition_id ) : null;
		if ( ! $competition_id || ! is_array( $last_import ) ) {
			$this->redirect_with_report(
				array(
					'total'    => 0,
					'imported' => 0,
					'skipped'  => 0,
					'rows'     => array(
						array(
							'line'    => 0,
							'status'  => 'error',
							'message' => __( 'Aucun dernier import annulable trouvé.', 'ufsc-licence-competition' ),
						),
					),
				),
				$competition_id
			);
		}

		$batch_id = sanitize_text_field( (string) ( $last_import['import_batch_id'] ?? '' ) );
		if ( '' === $batch_id ) {
			$this->redirect_with_report( array( 'total' => 0, 'imported' => 0, 'skipped' => 0, 'rows' => array() ), $competition_id );
		}

		if ( $this->batch_has_linked_dependencies( $competition_id, $batch_id ) ) {
			$this->redirect_with_report(
				array(
					'total'    => 0,
					'imported' => 0,
					'skipped'  => 0,
					'rows'     => array(
						array(
							'line'    => 0,
							'status'  => 'error',
							'message' => __( 'Annulation bloquée : des dépendances (combats / pesées / participants externes) sont déjà liées à ce lot importé.', 'ufsc-licence-competition' ),
						),
					),
				),
				$competition_id
			);
		}

		global $wpdb;
		$entries_table = Db::entries_table();
		$deleted       = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$entries_table} WHERE competition_id = %d AND import_batch_id = %s",
				$competition_id,
				$batch_id
			)
		);

		$this->redirect_with_report(
			array(
				'total'    => (int) $deleted,
				'imported' => 0,
				'skipped'  => 0,
				'rows'     => array(
					array(
						'line'    => 0,
						'status'  => 'imported',
						'message' => sprintf( __( 'Dernier import annulé (%d lignes supprimées).', 'ufsc-licence-competition' ), (int) $deleted ),
					),
				),
			),
			$competition_id
		);
	}

	private function validate_uploaded_csv( array $file ) {
		if ( empty( $file ) || empty( $file['tmp_name'] ) ) {
			return new \WP_Error( 'missing_file', __( 'Fichier CSV manquant.', 'ufsc-licence-competition' ) );
		}

		if ( ! empty( $file['error'] ) ) {
			return new \WP_Error( 'upload_error', __( 'Erreur lors du téléversement.', 'ufsc-licence-competition' ) );
		}

		if ( ! empty( $file['size'] ) && (int) $file['size'] > self::MAX_FILE_SIZE ) {
			return new \WP_Error( 'too_large', __( 'Le fichier dépasse la taille maximale (5 Mo).', 'ufsc-licence-competition' ) );
		}

		$check = wp_check_filetype_and_ext(
			$file['tmp_name'],
			$file['name'],
			array(
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			)
		);
		if ( empty( $check['ext'] ) || ! in_array( strtolower( $check['ext'] ), array( 'csv', 'txt' ), true ) ) {
			return new \WP_Error( 'invalid_type', __( 'Le fichier doit être un CSV valide.', 'ufsc-licence-competition' ) );
		}

		return true;
	}

	private function read_csv_rows( string $file_path ) {
		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			return new \WP_Error( 'csv_open_failed', __( 'Impossible de lire le fichier CSV.', 'ufsc-licence-competition' ) );
		}

		$first_line = fgets( $handle );
		rewind( $handle );

		$delimiter = ';';
		if ( is_string( $first_line ) ) {
			$semicolon_count = substr_count( $first_line, ';' );
			$comma_count     = substr_count( $first_line, ',' );
			if ( $comma_count > $semicolon_count ) {
				$delimiter = ',';
			}
		}

		$headers = fgetcsv( $handle, 0, $delimiter );
		if ( ! is_array( $headers ) || empty( $headers ) ) {
			fclose( $handle );
			return new \WP_Error( 'csv_headers_missing', __( 'En-tête CSV invalide ou manquant.', 'ufsc-licence-competition' ) );
		}

		$raw_headers = $headers;
		$headers = array_map(
			function( $header ) {
				$header = is_scalar( $header ) ? (string) $header : '';
				return preg_replace( '/^\xEF\xBB\xBF/u', '', $header );
			},
			$headers
		);
		$headers = array_map( array( $this, 'normalize_header' ), $headers );
		$headers = array_map( array( $this, 'sanitize_critical_header_key' ), $headers );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				'UFSC entries CSV import csv_headers_detected ' . wp_json_encode(
					array(
						'raw_headers'        => array_map( 'strval', $raw_headers ),
						'normalized_headers' => array_map( 'strval', $headers ),
					)
				)
			);
			error_log(
				'UFSC entries CSV import csv_headers_canonical_check ' . wp_json_encode(
					array(
						'has_nom'            => in_array( 'nom', $headers, true ),
						'has_prenom'         => in_array( 'prenom', $headers, true ),
						'has_date_naissance' => in_array( 'date_naissance', $headers, true ),
					)
				)
			);
		}

		$rows        = array();
		$line_number = 1;
		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) {
			$line_number++;
			if ( $this->is_empty_csv_row( $row ) ) {
				continue;
			}

			$normalized = array();
			foreach ( $headers as $index => $header ) {
				if ( '' === $header ) {
					continue;
				}
				$normalized[ $header ] = isset( $row[ $index ] ) ? $this->clean_csv_cell( $row[ $index ] ) : '';
			}

			$rows[] = array(
				'line' => $line_number,
				'data' => $normalized,
			);
		}

		fclose( $handle );

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	private function import_rows( $competition, array $rows ): array {
		global $wpdb;
		$import_batch_id = 'imp_' . gmdate( 'YmdHis' ) . '_' . wp_generate_password( 6, false, false );

		$report = array(
			'total'                 => count( $rows ),
			'imported'              => 0,
			'skipped'               => 0,
			'non_affiliated_clubs'  => 0,
			'noclub_fallbacks'      => 0,
			'rows'                  => array(),
		);

		$competition_id         = (int) ( $competition->id ?? 0 );
		$require_valid_license  = $this->competition_requires_license( $competition_id );
		$competition_discipline = sanitize_key( (string) ( $competition->discipline ?? '' ) );
		$season_end_year        = isset( $competition->season ) ? (int) $competition->season : 0;

		foreach ( $rows as $row ) {
			$line = (int) ( $row['line'] ?? 0 );
			$data = is_array( $row['data'] ?? null ) ? $row['data'] : array();

			$normalized       = $this->normalize_import_data( $data, $competition_discipline );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log(
					'UFSC entries CSV import csv_normalized_row ' . wp_json_encode(
						array(
							'line'           => $line,
							'nom'            => (string) ( $normalized['nom'] ?? '' ),
							'prenom'         => (string) ( $normalized['prenom'] ?? '' ),
							'date_naissance' => (string) ( $normalized['date_naissance'] ?? '' ),
							'numero_licence' => (string) ( $normalized['numero_licence'] ?? '' ),
							'club_nom'       => (string) ( $normalized['club_nom'] ?? '' ),
						)
					)
				);
			}
			$validation_error = $this->validate_minimal_row( $normalized );
			if ( '' !== $validation_error ) {
				$report['skipped']++;
				$report['rows'][] = array(
					'line'    => $line,
					'status'  => 'error',
					'message' => $validation_error,
				);
				continue;
			}

			$birthdate = $this->normalize_birthdate( (string) $normalized['date_naissance'] );
			if ( '' === $birthdate ) {
				$report['skipped']++;
				$report['rows'][] = array(
					'line'    => $line,
					'status'  => 'error',
					'message' => __( 'Date de naissance invalide.', 'ufsc-licence-competition' ),
				);
				continue;
			}
			$normalized['date_naissance'] = $birthdate;

			$club_resolution = $this->resolve_club( $normalized );
			$club_id         = (int) ( $club_resolution['club_id'] ?? 0 );

			$license_lookup = $this->resolve_license( $normalized, $club_id );
			$licensee_id    = (int) ( $license_lookup['licensee_id'] ?? 0 );
			$license_number = (string) ( $license_lookup['license_number'] ?? $normalized['numero_licence'] );

			if ( $require_valid_license && ! $licensee_id ) {
				$report['skipped']++;
				$report['rows'][] = array(
					'line'    => $line,
					'status'  => 'error',
					'message' => __( 'Licence requise pour cette compétition.', 'ufsc-licence-competition' ),
				);
				continue;
			}

			if ( $licensee_id && $this->entry_repository->get_by_competition_licensee( $competition_id, $licensee_id ) ) {
				$report['skipped']++;
				$report['rows'][] = array(
					'line'    => $line,
					'status'  => 'ignored',
					'message' => __( 'Doublon détecté (ligne ignorée).', 'ufsc-licence-competition' ),
				);
				continue;
			}

			if ( $this->has_duplicate_identity_entry( $competition_id, $normalized['nom'], $normalized['prenom'], $birthdate ) ) {
				$report['skipped']++;
				$report['rows'][] = array(
					'line'    => $line,
					'status'  => 'ignored',
					'message' => __( 'Doublon détecté (ligne ignorée).', 'ufsc-licence-competition' ),
				);
				continue;
			}

			$weight_kg    = $this->sanitize_weight( $normalized['poids'] );
			$weight_class = $this->normalize_text( $normalized['categorie_poids'] );
			if ( '' === $weight_class && null !== $weight_kg ) {
				$weight_context = array(
					'discipline'      => $competition_discipline,
					'age_reference'   => sanitize_text_field( (string) ( $competition->age_reference ?? '12-31' ) ),
					'season_end_year' => $season_end_year,
				);
				$resolved     = WeightCategoryResolver::resolve_with_details( $birthdate, $normalized['sexe'], $weight_kg, $weight_context );
				$weight_class = sanitize_text_field( (string) ( $resolved['label'] ?? '' ) );
			}

			$category = $this->normalize_text( $normalized['categorie'] );
			if ( '' === $category && function_exists( 'ufsc_lc_compute_category_from_birthdate' ) ) {
				$category = sanitize_text_field( (string) ufsc_lc_compute_category_from_birthdate( $birthdate, (string) $season_end_year ) );
			}

			$completeness = $this->build_import_completeness_flags( $normalized, $weight_kg, $weight_class, $category );
			$status       = $this->resolve_import_status( $normalized['statut_dossier'], $completeness );

			if ( ! $licensee_id && ! $require_valid_license ) {
				$licensee_id = $this->build_available_fallback_licensee_id( $competition_id, $normalized['nom'], $normalized['prenom'], $birthdate );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'UFSC entries CSV import imported_external_participant_detected ' . wp_json_encode( array( 'competition_id' => $competition_id, 'line' => $line, 'generated_licensee_id' => $licensee_id ) ) );
				}
			}

			$payload = array(
				'competition_id' => $competition_id,
				'club_id'        => $club_id,
				'licensee_id'    => $licensee_id,
				'status'         => $status,
				'first_name'     => $normalized['prenom'],
				'last_name'      => $normalized['nom'],
				'birth_date'     => $birthdate,
				'sex'            => $normalized['sexe'],
				'weight'         => null !== $weight_kg ? (string) $weight_kg : '',
				'weight_class'   => $weight_class,
				'category'       => $category,
				'level'          => $this->normalize_text( $normalized['niveau'] ),
				'license_number' => $license_number,
				'fighter_number' => absint( $normalized['fighter_number'] ?? 0 ),
			);

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log(
					'UFSC entries CSV import insert start: ' . wp_json_encode(
						array(
							'competition_id' => $competition_id,
							'licensee_id'    => $licensee_id,
							'club_id'        => $club_id,
							'line'           => $line,
						)
					)
				);
			}

			$entry_id = $this->entry_front_repository->insert( $payload );
			if ( ! $entry_id ) {
				$report['skipped']++;

				$message    = __( 'Erreur lors de l’enregistrement en base.', 'ufsc-licence-competition' );
				$last_error = is_string( $wpdb->last_error ) ? trim( $wpdb->last_error ) : '';

				if ( '' !== $last_error && class_exists( EntryDeduplication::class ) && EntryDeduplication::is_duplicate_key_error( $last_error ) ) {
					$message = __( 'Doublon détecté (contrainte compétition/licencié).', 'ufsc-licence-competition' );
				}

				$report['rows'][] = array(
					'line'    => $line,
					'status'  => 'error',
					'message' => $message,
				);

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'UFSC entries CSV import insert failed: ' . $last_error );
				}
				continue;
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'UFSC entries CSV import insert success: entry_id=' . (int) $entry_id . ' line=' . (int) $line );
			}

			$this->persist_optional_csv_fields( $entry_id, $normalized, $club_resolution, $import_batch_id );

			if ( ! empty( $club_resolution['is_non_affiliated'] ) ) {
				$report['non_affiliated_clubs']++;
			}
			if ( ! empty( $club_resolution['is_noclub'] ) ) {
				$report['noclub_fallbacks']++;
			}

			$report['imported']++;
			$line_message = $this->build_import_message( $club_resolution );
			if ( $completeness['needs_review'] ) {
				$line_message .= ' ' . __( 'Entrée incomplète orientée vers la validation.', 'ufsc-licence-competition' );
			}

			$report['rows'][] = array(
				'line'    => $line,
				'status'  => 'imported',
				'message' => $line_message,
			);
		}

		return $report;
	}

	private function build_fallback_licensee_id( int $competition_id, string $nom, string $prenom, string $birthdate ): int {
		$key  = strtolower( trim( $competition_id . '|' . $nom . '|' . $prenom . '|' . $birthdate ) );
		$hash = abs( (int) sprintf( '%u', crc32( $key ) ) );

		return 1000000000 + ( $hash % 1000000000 );
	}

	private function build_available_fallback_licensee_id( int $competition_id, string $nom, string $prenom, string $birthdate ): int {
		$base = $this->build_fallback_licensee_id( $competition_id, $nom, $prenom, $birthdate );
		$max_attempts = 50;
		$candidate = $base;

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			$existing = $this->entry_repository->get_by_competition_licensee( $competition_id, $candidate );
			if ( ! $existing ) {
				return $candidate;
			}

			$candidate = 1000000000 + ( ( $base - 1000000000 + $attempt + 1 ) % 1000000000 );
		}

		return 1000000000 + random_int( 0, 999999999 );
	}

	private function persist_optional_csv_fields( int $entry_id, array $normalized, array $club_resolution, string $import_batch_id ): void {
		global $wpdb;

		$entry_id = absint( $entry_id );
		if ( ! $entry_id ) {
			return;
		}

		$table   = Db::entries_table();
		$columns = Db::get_table_columns( $table );
		if ( ! is_array( $columns ) || empty( $columns ) ) {
			return;
		}

		$updates = array();
		$this->maybe_map_column( $updates, $columns, $normalized, 'numero_licence_asptt', array( 'numero_licence_asptt', 'numero_asptt', 'asptt_number' ) );
		$this->maybe_map_column( $updates, $columns, $normalized, 'email', array( 'email', 'mail' ) );
		$this->maybe_map_column( $updates, $columns, $normalized, 'telephone', array( 'telephone', 'phone' ) );
		$this->maybe_map_column( $updates, $columns, $normalized, 'commentaire', array( 'commentaire', 'comment', 'notes' ) );
		$this->maybe_map_column( $updates, $columns, $normalized, 'discipline', array( 'discipline' ) );
		$this->maybe_map_column( $updates, $columns, $normalized, 'fighter_number', array( 'fighter_number', 'competition_number', 'dossard' ) );
		$club_label_from_import = $this->normalize_text( $club_resolution['club_nom'] ?? '' );
		if ( '' !== $club_label_from_import ) {
			if ( in_array( 'club_nom', $columns, true ) ) {
				$updates['club_nom'] = $club_label_from_import;
			}
			$this->maybe_map_column( $updates, $columns, array( 'club_nom' => $club_label_from_import ), 'club_nom', array( 'club_name' ) );
		}

		$club_source = sanitize_key( (string) ( $club_resolution['club_source'] ?? '' ) );
		if ( '' !== $club_source ) {
			$this->maybe_map_column( $updates, $columns, array( 'club_source' => $club_source ), 'club_source', array( 'club_source', 'club_status' ) );
		}
		$this->maybe_map_column( $updates, $columns, array( 'group_label' => $import_batch_id ), 'group_label', array( 'group_label' ) );
		$this->maybe_map_column( $updates, $columns, array( 'import_batch_id' => $import_batch_id ), 'import_batch_id', array( 'import_batch_id' ) );
		$this->maybe_map_column( $updates, $columns, array( 'import_source' => 'csv_admin' ), 'import_source', array( 'import_source' ) );
		$this->maybe_map_column( $updates, $columns, array( 'created_by_import' => '1' ), 'created_by_import', array( 'created_by_import' ) );
		if ( in_array( 'imported_at', $columns, true ) ) {
			$updates['imported_at'] = current_time( 'mysql' );
		}

		$this->maybe_map_bool_column( $updates, $columns, $normalized, 'certificat_medical', array( 'certificat_medical' ) );
		$this->maybe_map_bool_column( $updates, $columns, $normalized, 'autorisation_parentale', array( 'autorisation_parentale' ) );

		if ( empty( $updates ) ) {
			return;
		}

		$wpdb->update( $table, $updates, array( 'id' => $entry_id ) );
	}

	private function maybe_map_column( array &$updates, array $columns, array $normalized, string $source_key, array $target_columns ): void {
		$value = $this->normalize_text( $normalized[ $source_key ] ?? '' );
		if ( '' === $value ) {
			return;
		}

		foreach ( $target_columns as $column ) {
			if ( in_array( $column, $columns, true ) ) {
				$updates[ $column ] = $value;
				return;
			}
		}
	}

	private function maybe_map_bool_column( array &$updates, array $columns, array $normalized, string $source_key, array $target_columns ): void {
		$value = $this->normalize_boolean_value( $normalized[ $source_key ] ?? '' );
		if ( null === $value ) {
			return;
		}

		foreach ( $target_columns as $column ) {
			if ( in_array( $column, $columns, true ) ) {
				$updates[ $column ] = $value;
				return;
			}
		}
	}

	private function has_duplicate_identity_entry( int $competition_id, string $nom, string $prenom, string $birthdate ): bool {
		global $wpdb;

		$table   = Db::entries_table();
		$columns = Db::get_table_columns( $table );
		if ( ! is_array( $columns ) || empty( $columns ) ) {
			return false;
		}

		$first_col = $this->resolve_first_column( $columns, array( 'first_name', 'firstname', 'prenom', 'given_name' ) );
		$last_col  = $this->resolve_first_column( $columns, array( 'last_name', 'lastname', 'nom', 'family_name' ) );
		$birth_col = $this->resolve_first_column( $columns, array( 'birth_date', 'birthdate', 'date_of_birth', 'dob' ) );
		if ( '' === $first_col || '' === $last_col || '' === $birth_col ) {
			return false;
		}

		$where  = array(
			'competition_id = %d',
			"LOWER(TRIM({$last_col})) = LOWER(TRIM(%s))",
			"LOWER(TRIM({$first_col})) = LOWER(TRIM(%s))",
			"{$birth_col} = %s",
		);
		$params = array( $competition_id, $nom, $prenom, $birthdate );

		if ( in_array( 'deleted_at', $columns, true ) ) {
			$where[] = 'deleted_at IS NULL';
		}

		$sql   = $wpdb->prepare( "SELECT id FROM {$table} WHERE " . implode( ' AND ', $where ) . ' LIMIT 1', $params );
		$found = $wpdb->get_var( $sql );

		return ! empty( $found );
	}

	private function resolve_license( array $normalized, int $club_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licences';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array(
				'licensee_id'    => 0,
				'license_number' => $this->normalize_text( $normalized['numero_licence'] ?? '' ),
			);
		}

		$columns = Db::get_table_columns( $table );
		if ( ! is_array( $columns ) ) {
			$columns = array();
		}

		$license_number = $this->normalize_text( $normalized['numero_licence'] ?? '' );
		$asptt_number   = $this->normalize_text( $normalized['numero_licence_asptt'] ?? '' );

		$search_column = '';
		$search_value  = '';
		if ( '' !== $asptt_number ) {
			$search_column = $this->resolve_first_column( $columns, array( 'numero_licence_asptt', 'numero_asptt', 'asptt_number' ) );
			$search_value  = $asptt_number;
		}
		if ( '' === $search_column && '' !== $license_number ) {
			$search_column = $this->resolve_first_column( $columns, array( 'numero_licence', 'numero_licence_delegataire', 'num_licence', 'licence_number' ) );
			$search_value  = $license_number;
		}

		if ( '' === $search_column || '' === $search_value ) {
			return array(
				'licensee_id'    => 0,
				'license_number' => $license_number,
			);
		}

		$where  = array( "{$search_column} = %s" );
		$params = array( $search_value );

		if ( $club_id > 0 && in_array( 'club_id', $columns, true ) ) {
			$where[]  = 'club_id = %d';
			$params[] = $club_id;
		}

		$sql         = $wpdb->prepare( "SELECT id FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT 1', $params );
		$licensee_id = (int) $wpdb->get_var( $sql );

		return array(
			'licensee_id'    => $licensee_id,
			'license_number' => $license_number,
		);
	}

	private function resolve_club( array $normalized ): array {
		global $wpdb;

		$club_id   = absint( $normalized['club_id'] ?? 0 );
		$club_name = $this->normalize_text( $normalized['club_nom'] ?? '' );

		$table = $wpdb->prefix . 'ufsc_clubs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			if ( '' === $club_name ) {
				$club_name = self::NO_CLUB_LABEL;
			}
			$result = array(
				'club_id'            => $club_id ?: 0,
				'club_nom'           => $club_name,
				'club_source'        => self::NO_CLUB_LABEL === $club_name ? 'noclub' : 'csv',
				'is_non_affiliated'  => ( 0 === $club_id && self::NO_CLUB_LABEL !== $club_name ),
				'is_noclub'          => ( self::NO_CLUB_LABEL === $club_name ),
			);
			$this->maybe_log_external_club_resolution( $result );
			return $result;
		}

		// Priorité métier : utiliser club_id avant toute tentative via club_nom.
		if ( $club_id > 0 ) {
			$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d LIMIT 1", $club_id ) );
			if ( $found > 0 ) {
				$resolved_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT nom FROM {$table} WHERE id = %d LIMIT 1", $found ) );
				return array(
					'club_id'            => $found,
					'club_nom'           => $this->normalize_text( $resolved_name ),
					'club_source'        => 'affiliated',
					'is_non_affiliated'  => false,
					'is_noclub'          => false,
				);
			}
			$club_id = 0;
		}

		if ( '' === $club_name ) {
			$result = array(
				'club_id'            => 0,
				'club_nom'           => self::NO_CLUB_LABEL,
				'club_source'        => 'noclub',
				'is_non_affiliated'  => false,
				'is_noclub'          => true,
			);
			$this->maybe_log_external_club_resolution( $result );
			return $result;
		}

		$exact = $wpdb->get_row( $wpdb->prepare( "SELECT id, nom FROM {$table} WHERE nom = %s LIMIT 1", $club_name ) );
		if ( $exact && ! empty( $exact->id ) ) {
			return array(
				'club_id'            => (int) $exact->id,
				'club_nom'           => $this->normalize_text( (string) ( $exact->nom ?? $club_name ) ),
				'club_source'        => 'affiliated',
				'is_non_affiliated'  => false,
				'is_noclub'          => false,
			);
		}

		$like    = '%' . $wpdb->esc_like( $club_name ) . '%';
		$partial = $wpdb->get_row( $wpdb->prepare( "SELECT id, nom FROM {$table} WHERE nom LIKE %s ORDER BY id ASC LIMIT 1", $like ) );
		if ( $partial && ! empty( $partial->id ) ) {
			return array(
				'club_id'            => (int) $partial->id,
				'club_nom'           => $this->normalize_text( (string) ( $partial->nom ?? $club_name ) ),
				'club_source'        => 'affiliated',
				'is_non_affiliated'  => false,
				'is_noclub'          => false,
			);
		}

		$result = array(
			'club_id'            => 0,
			'club_nom'           => $club_name,
			'club_source'        => 'csv',
			'is_non_affiliated'  => true,
			'is_noclub'          => false,
		);
		$this->maybe_log_external_club_resolution( $result );
		return $result;
	}

	private function maybe_log_external_club_resolution( array $club_resolution ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		$club_source = sanitize_key( (string) ( $club_resolution['club_source'] ?? '' ) );
		if ( ! in_array( $club_source, array( 'csv', 'noclub' ), true ) ) {
			return;
		}

		error_log( 'UFSC entries CSV import external_club_resolved ' . wp_json_encode( $club_resolution ) );
	}

	private function competition_requires_license( int $competition_id ): bool {
		if ( class_exists( CompetitionMeta::class ) ) {
			$meta = CompetitionMeta::get( $competition_id );
			if ( is_array( $meta ) && array_key_exists( 'require_valid_license', $meta ) ) {
				return ! empty( $meta['require_valid_license'] );
			}
		}

		return false;
	}

	private function validate_minimal_row( array $normalized ): string {
		$required = array(
			'nom'            => __( 'Nom absent', 'ufsc-licence-competition' ),
			'prenom'         => __( 'Prénom absent', 'ufsc-licence-competition' ),
			'date_naissance' => __( 'Date de naissance absente', 'ufsc-licence-competition' ),
		);

		foreach ( $required as $key => $error_message ) {
			if ( '' === $this->normalize_text( $normalized[ $key ] ?? '' ) ) {
				return $error_message;
			}
		}

		return '';
	}

	private function has_exploitable_category_data( array $normalized ): bool {
		$category = $this->normalize_text( $normalized['categorie'] ?? '' );
		$weight_class = $this->normalize_text( $normalized['categorie_poids'] ?? '' );
		$weight = $this->normalize_text( $normalized['poids'] ?? '' );

		return '' !== $category || '' !== $weight_class || '' !== $weight;
	}

	private function build_import_message( array $club_resolution ): string {
		$club_source = sanitize_key( (string) ( $club_resolution['club_source'] ?? '' ) );
		if ( 'csv' === $club_source ) {
			return __( 'Importé (club non affilié conservé).', 'ufsc-licence-competition' );
		}
		if ( 'noclub' === $club_source ) {
			return __( 'Importé (club remplacé par noclub).', 'ufsc-licence-competition' );
		}

		return __( 'Importé', 'ufsc-licence-competition' );
	}

	private function normalize_import_data( array $data, string $competition_discipline ): array {
		$normalized = array();
		foreach ( $this->get_supported_headers() as $header ) {
			$normalized[ $header ] = $this->normalize_text( $data[ $header ] ?? '' );
		}
		foreach ( $this->get_header_aliases() as $canonical => $aliases ) {
			if ( '' !== $normalized[ $canonical ] ) {
				continue;
			}
			foreach ( $aliases as $alias ) {
				$alias_value = $this->normalize_text( $data[ $alias ] ?? '' );
				if ( '' !== $alias_value ) {
					$normalized[ $canonical ] = $alias_value;
					break;
				}
			}
		}
		if ( '' === $normalized['nom'] ) {
			$full_name = $this->resolve_full_name_from_data( $data );
			if ( '' !== $full_name ) {
				$derived = $this->derive_names_from_full_name( $full_name, $normalized['prenom'] );
				if ( '' === $normalized['prenom'] && '' !== $derived['prenom'] ) {
					$normalized['prenom'] = $derived['prenom'];
				}
				if ( '' === $normalized['nom'] && '' !== $derived['nom'] ) {
					$normalized['nom'] = $derived['nom'];
				}
			}
		}

		$normalized['discipline'] = $this->normalize_discipline( $normalized['discipline'], $competition_discipline );
		$normalized['sexe']       = $this->normalize_sex( $normalized['sexe'] );
		if ( '' === $normalized['sexe'] ) {
			$normalized['sexe'] = self::UNKNOWN_SEX;
		}
		if ( '' === $normalized['club_nom'] && 0 === absint( $normalized['club_id'] ) ) {
			$normalized['club_nom'] = self::NO_CLUB_LABEL;
		}

		return $normalized;
	}

	private function normalize_discipline( string $value, string $fallback ): string {
		$value = sanitize_key( $value );
		if ( '' === $value ) {
			return $fallback;
		}

		if ( class_exists( DisciplineRegistry::class ) ) {
			$normalized = DisciplineRegistry::normalize( $value );
			if ( '' !== $normalized ) {
				return $normalized;
			}
		}

		return $value;
	}

	private function normalize_status( string $value ): string {
		$status = sanitize_key( $value );
		if ( class_exists( '\\UFSC\\Competitions\\Entries\\EntriesWorkflow' ) ) {
			$status = \UFSC\Competitions\Entries\EntriesWorkflow::normalize_status( $status );
		}
		if ( '' === $status ) {
			$status = 'draft';
		}

		return $status;
	}

	private function resolve_import_status( string $raw_status, array $completeness ): string {
		$status = $this->normalize_status( $raw_status );
		if ( '' === sanitize_key( $raw_status ) ) {
			$status = 'submitted';
		}

		if ( ! empty( $completeness['needs_review'] ) && in_array( $status, array( 'approved', 'cancelled' ), true ) ) {
			return 'pending';
		}

		if ( ! empty( $completeness['needs_review'] ) && 'draft' === $status ) {
			return 'pending';
		}

		return $status;
	}

	private function build_import_completeness_flags( array $normalized, ?float $weight_kg, string $weight_class, string $category ): array {
		$missing = array();

		if ( self::UNKNOWN_SEX === $normalized['sexe'] ) {
			$missing[] = 'sex';
		}
		if ( '' === $this->normalize_text( $normalized['club_nom'] ) && 0 === absint( $normalized['club_id'] ) ) {
			$missing[] = 'club';
		}
		if ( '' === $this->normalize_text( $normalized['niveau'] ) ) {
			$missing[] = 'level';
		}
		if ( null === $weight_kg && '' === $weight_class ) {
			$missing[] = 'weight';
		}
		if ( '' === $category ) {
			$missing[] = 'category';
		}

		return array(
			'missing'      => $missing,
			'needs_review' => ! empty( $missing ),
		);
	}

	private function normalize_boolean_value( $value ) {
		$value = $this->normalize_text( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		$truthy = array( '1', 'true', 'oui', 'yes', 'y', 'ok' );
		$falsy  = array( '0', 'false', 'non', 'no', 'n' );
		$lower  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );

		if ( in_array( $lower, $truthy, true ) ) {
			return 1;
		}
		if ( in_array( $lower, $falsy, true ) ) {
			return 0;
		}

		return null;
	}

	private function normalize_sex( string $value ): string {
		$value = sanitize_text_field( $value );
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
		if ( in_array( $lower, array( 'm', 'male', 'homme', 'masculin' ), true ) ) {
			return 'M';
		}
		if ( in_array( $lower, array( 'f', 'female', 'femme', 'feminin' ), true ) ) {
			return 'F';
		}
		if ( '' === trim( $value ) ) {
			return '';
		}

		return strtoupper( $value );
	}

	private function normalize_birthdate( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$formats  = array( 'Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd-m-Y' );
		foreach ( $formats as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( '!' . $format, $raw, $timezone );
			if ( $parsed && $parsed->format( $format ) === $raw ) {
				return $parsed->format( 'Y-m-d' );
			}
		}

		return '';
	}

	private function sanitize_weight( string $value ): ?float {
		$value = str_replace( ',', '.', trim( $value ) );
		if ( '' === $value ) {
			return null;
		}

		$weight = (float) $value;
		if ( $weight <= 0 || $weight > 300 ) {
			return null;
		}

		return $weight;
	}

	private function clean_csv_cell( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = wp_check_invalid_utf8( (string) $value );
		$value = trim( (string) $value );

		return preg_replace( '/\s+/u', ' ', $value );
	}

	private function normalize_header( $header ): string {
		$header = is_scalar( $header ) ? (string) $header : '';
		$header = preg_replace( '/^\xEF\xBB\xBF/u', '', $header );
		$header = wp_check_invalid_utf8( $header );
		$header = trim( $header );
		if ( function_exists( 'remove_accents' ) ) {
			$header = remove_accents( $header );
		}
		$header = str_replace( array( ' ', '-', '\'', '’', '°', '.', ':' ), '_', $header );
		$header = preg_replace( '/[^a-zA-Z0-9_]+/', '_', $header );
		$header = preg_replace( '/_+/', '_', (string) $header );

		return sanitize_key( $header );
	}

	private function sanitize_critical_header_key( string $header ): string {
		$header = trim( $header );
		$critical = array(
			'_nom'            => 'nom',
			'_prenom'         => 'prenom',
			'_date_naissance' => 'date_naissance',
			'_sexe'           => 'sexe',
			'_club_nom'       => 'club_nom',
			'_club_id'        => 'club_id',
			'_numero_licence' => 'numero_licence',
		);
		if ( isset( $critical[ $header ] ) ) {
			return $critical[ $header ];
		}

		return ltrim( $header, '_' );
	}

	private function is_empty_csv_row( array $row ): bool {
		foreach ( $row as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				return false;
			}
		}

		return true;
	}

	private function normalize_text( string $value ): string {
		return trim( sanitize_text_field( $value ) );
	}

	private function resolve_first_column( array $columns, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				return $candidate;
			}
		}

		return '';
	}

	private function get_supported_headers(): array {
		return array(
			'nom',
			'prenom',
			'sexe',
			'date_naissance',
			'club_nom',
			'club_id',
			'discipline',
			'niveau',
			'categorie',
			'poids',
			'categorie_poids',
			'fighter_number',
			'numero_licence',
			'numero_licence_asptt',
			'email',
			'telephone',
			'certificat_medical',
			'autorisation_parentale',
			'statut_dossier',
			'commentaire',
		);
	}

	private function get_header_aliases(): array {
		return array(
			'nom'                  => array( 'last_name', 'lastname', 'family_name', 'surname', 'patronyme', 'nom_de_famille', 'nom_famille', 'nom_parent', 'nom_du_participant' ),
			'prenom'               => array( 'first_name', 'firstname', 'given_name', 'prenom_nom', 'prnom' ),
			'sexe'                 => array( 'sex', 'gender' ),
			'date_naissance'       => array( 'birth_date', 'birthdate', 'dob', 'date_of_birth', 'date_de_naissance' ),
			'club_nom'             => array( 'club_name', 'structure_name', 'club', 'club_label' ),
			'club_id'              => array( 'clubid' ),
			'niveau'               => array( 'level', 'classe', 'class' ),
			'categorie'            => array( 'category', 'category_name' ),
			'poids'                => array( 'weight', 'weight_kg' ),
			'categorie_poids'      => array( 'weight_class', 'weight_category', 'weight_cat' ),
			'fighter_number'       => array( 'competition_number', 'dossard', 'numero_combattant' ),
			'numero_licence'       => array( 'license_number', 'licence_number', 'licensee_number', 'numero_licence_delegataire', 'num_licence', 'n_licence' ),
			'numero_licence_asptt' => array( 'numero_asptt', 'asptt_number', 'license_asptt' ),
			'telephone'            => array( 'phone', 'mobile', 'tel' ),
			'statut_dossier'       => array( 'status', 'entry_status' ),
			'commentaire'          => array( 'comment', 'notes', 'note' ),
		);
	}

	private function resolve_full_name_from_data( array $data ): string {
		$candidates = array( 'participant_name', 'full_name', 'athlete_name', 'nom_prenom', 'nom_complet', 'name', 'participant' );
		foreach ( $candidates as $key ) {
			$value = $this->normalize_text( $data[ $key ] ?? '' );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function derive_names_from_full_name( string $full_name, string $known_first_name ): array {
		$full_name = $this->normalize_text( $full_name );
		$known_first_name = $this->normalize_text( $known_first_name );
		if ( '' === $full_name ) {
			return array( 'nom' => '', 'prenom' => '' );
		}

		$parts = preg_split( '/\s+/', $full_name );
		if ( ! is_array( $parts ) || empty( $parts ) ) {
			return array( 'nom' => '', 'prenom' => '' );
		}

		if ( '' !== $known_first_name ) {
			$normalized_known = function_exists( 'remove_accents' ) ? remove_accents( $known_first_name ) : $known_first_name;
			$normalized_known = function_exists( 'mb_strtolower' ) ? mb_strtolower( $normalized_known ) : strtolower( $normalized_known );
			$remaining = array();
			foreach ( $parts as $part ) {
				$normalized_part = function_exists( 'remove_accents' ) ? remove_accents( (string) $part ) : (string) $part;
				$normalized_part = function_exists( 'mb_strtolower' ) ? mb_strtolower( $normalized_part ) : strtolower( $normalized_part );
				if ( $normalized_part === $normalized_known ) {
					continue;
				}
				$remaining[] = (string) $part;
			}
			$nom = trim( implode( ' ', $remaining ) );
			if ( '' !== $nom ) {
				return array( 'nom' => $nom, 'prenom' => $known_first_name );
			}
		}

		if ( 1 === count( $parts ) ) {
			return array( 'nom' => sanitize_text_field( (string) $parts[0] ), 'prenom' => $known_first_name );
		}

		$prenom = '' !== $known_first_name ? $known_first_name : sanitize_text_field( (string) array_pop( $parts ) );
		$nom = sanitize_text_field( trim( implode( ' ', $parts ) ) );

		return array( 'nom' => $nom, 'prenom' => $prenom );
	}

	private function redirect_with_report( array $report, int $competition_id ): void {
		set_transient( $this->get_report_transient_key(), $report, 20 * MINUTE_IN_SECONDS );

		$url = add_query_arg(
			array(
				'page'           => Menu::PAGE_ENTRIES_IMPORT,
				'competition_id' => $competition_id,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	private function get_report(): ?array {
		$key    = $this->get_report_transient_key();
		$report = get_transient( $key );
		if ( is_array( $report ) ) {
			delete_transient( $key );
			return $report;
		}

		return null;
	}

	private function get_report_transient_key(): string {
		return 'ufsc_entries_import_report_' . get_current_user_id();
	}

	private function get_last_import_batch( int $competition_id ): ?array {
		global $wpdb;

		$entries_table = Db::entries_table();
		$columns       = Db::get_table_columns( $entries_table );
		if ( ! is_array( $columns ) || ! in_array( 'import_batch_id', $columns, true ) ) {
			return null;
		}

		$imported_col = in_array( 'imported_at', $columns, true ) ? 'imported_at' : 'created_at';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT import_batch_id, MAX({$imported_col}) AS imported_at, COUNT(*) AS rows_count
				FROM {$entries_table}
				WHERE competition_id = %d AND import_batch_id IS NOT NULL AND import_batch_id <> ''
				GROUP BY import_batch_id
				ORDER BY imported_at DESC
				LIMIT 1",
				$competition_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function batch_has_linked_dependencies( int $competition_id, string $import_batch_id ): bool {
		global $wpdb;
		$fights_table  = Db::fights_table();
		$entries_table = Db::entries_table();
		$entry_ids_subquery = "SELECT id FROM {$entries_table} WHERE competition_id = %d AND import_batch_id = %s";

		// 1) Fights.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fights_table ) ) === $fights_table ) {
			$fight_columns = Db::get_table_columns( $fights_table );
			if ( is_array( $fight_columns ) ) {
				$entry_links = array();
				foreach ( array( 'entry_id', 'red_entry_id', 'blue_entry_id', 'winner_entry_id' ) as $candidate ) {
					if ( in_array( $candidate, $fight_columns, true ) ) {
						$entry_links[] = $candidate;
					}
				}
				if ( ! empty( $entry_links ) ) {
					$or_links = array();
					foreach ( $entry_links as $column ) {
						$or_links[] = "{$column} IN ({$entry_ids_subquery})";
					}
					$sql    = "SELECT id FROM {$fights_table} WHERE competition_id = %d AND (" . implode( ' OR ', $or_links ) . ') LIMIT 1';
					$params = array( $competition_id );
					foreach ( $entry_links as $_ ) {
						$params[] = $competition_id;
						$params[] = $import_batch_id;
					}
					$found = $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
					if ( ! empty( $found ) ) {
						return true;
					}
				}
			}
		}

		// 2) Weigh-ins.
		$weighins_table = Db::weighins_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $weighins_table ) ) === $weighins_table ) {
			$weighins_columns = Db::get_table_columns( $weighins_table );
			if ( is_array( $weighins_columns ) && in_array( 'entry_id', $weighins_columns, true ) ) {
				$found = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$weighins_table} WHERE competition_id = %d AND entry_id IN ({$entry_ids_subquery}) LIMIT 1",
						$competition_id,
						$competition_id,
						$import_batch_id
					)
				);
				if ( ! empty( $found ) ) {
					return true;
				}
			}
		}

		// 3) External participants.
		$external_table = Db::external_participants_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $external_table ) ) === $external_table ) {
			$external_columns = Db::get_table_columns( $external_table );
			if ( is_array( $external_columns ) && in_array( 'entry_id', $external_columns, true ) ) {
				$found = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$external_table} WHERE entry_id IN ({$entry_ids_subquery}) LIMIT 1",
						$competition_id,
						$import_batch_id
					)
				);
				if ( ! empty( $found ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function render_report( array $report ): void {
		$total    = (int) ( $report['total'] ?? 0 );
		$imported = (int) ( $report['imported'] ?? 0 );
		$skipped  = (int) ( $report['skipped'] ?? 0 );
		$non_affiliated_clubs = (int) ( $report['non_affiliated_clubs'] ?? 0 );
		$noclub_fallbacks     = (int) ( $report['noclub_fallbacks'] ?? 0 );
		$rows     = is_array( $report['rows'] ?? null ) ? $report['rows'] : array();
		?>
		<h2><?php esc_html_e( 'Rapport d’import', 'ufsc-licence-competition' ); ?></h2>
		<ul>
			<li><?php echo esc_html( sprintf( __( 'Lignes totales : %d', 'ufsc-licence-competition' ), $total ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Lignes importées : %d', 'ufsc-licence-competition' ), $imported ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Lignes ignorées/refusées : %d', 'ufsc-licence-competition' ), $skipped ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Clubs non affiliés conservés : %d', 'ufsc-licence-competition' ), $non_affiliated_clubs ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Clubs remplacés par noclub : %d', 'ufsc-licence-competition' ), $noclub_fallbacks ) ); ?></li>
		</ul>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Ligne CSV', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Statut', 'ufsc-licence-competition' ); ?></th>
					<th><?php esc_html_e( 'Message', 'ufsc-licence-competition' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'Aucun détail disponible.', 'ufsc-licence-competition' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( (int) ( $row['line'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
