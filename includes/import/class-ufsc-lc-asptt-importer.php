<?php
/**
 * File: class-ufsc-lc-asptt-import-service.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_ASPTT_Import_Service {
	const SOURCE = 'ASPTT';

	const STATUS_LINKED               = 'linked';
	const STATUS_CLUB_NOT_FOUND       = 'club_not_resolved';
	const STATUS_NEEDS_REVIEW         = 'needs_review';
	const STATUS_LICENCE_MISSING      = 'licence_not_found';
	const STATUS_INVALID_ASPTT_NUMBER = 'invalid_asptt_number';
	const STATUS_INVALID_SEASON       = 'invalid_season';
	const STATUS_INVALID_BIRTHDATE    = 'invalid_birthdate';

	const MAX_FILE_SIZE          = 5242880; // 5 MB.
	const PREVIEW_DEFAULT_LIMIT  = 50;
	const PREVIEW_MIN_LIMIT      = 10;
	const PREVIEW_MAX_LIMIT      = 200;
	const IMPORT_CHUNK_SIZE      = 200;
	const HASHES_OPTION_KEY      = 'ufsc_lc_asptt_row_hashes';
	const IMPORT_LOG_META_KEY    = 'ufsc_lc_asptt_import_log_meta';

	private $licence_columns = null;
	private $license_number_column = null;
	private $hash_table_notice = '';
	private $row_index = 0;
	private $composite_cache = array();
	private $has_asptt_column = null;

	public function validate_upload( $file ) {
		if ( empty( $file ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'missing_file', __( 'Fichier manquant.', 'ufsc-licence-competition' ) );
		}

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'upload_error', __( 'Erreur lors du téléversement.', 'ufsc-licence-competition' ) );
		}

		if ( ! empty( $file['size'] ) && (int) $file['size'] > self::MAX_FILE_SIZE ) {
			return new WP_Error( 'file_too_large', __( 'Le fichier dépasse la taille maximale autorisée (5 Mo).', 'ufsc-licence-competition' ) );
		}

		$check = wp_check_filetype_and_ext(
			$file['tmp_name'],
			$file['name'],
			array(
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			)
		);

		if ( empty( $check['ext'] ) || 'csv' !== strtolower( $check['ext'] ) ) {
			return new WP_Error( 'invalid_type', __( 'Le fichier doit être un CSV.', 'ufsc-licence-competition' ) );
		}

		return true;
	}

	public function store_upload( $file ) {
		$validated = $this->validate_upload( $file );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$upload_dir = wp_upload_dir();
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . 'ufsc-lc';
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error( 'upload_dir', __( 'Impossible de créer le dossier de destination.', 'ufsc-licence-competition' ) );
		}

		$this->ensure_upload_protection( $target_dir );

		$overrides = array(
			'test_form' => false,
			'mimes'     => array(
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			),
		);

		$movefile = wp_handle_upload( $file, $overrides );

		if ( empty( $movefile['file'] ) ) {
			$message = ! empty( $movefile['error'] ) ? $movefile['error'] : __( 'Téléversement impossible.', 'ufsc-licence-competition' );
			return new WP_Error( 'upload_error', $message );
		}

		$filename    = wp_basename( $movefile['file'] );
		$destination = trailingslashit( $target_dir ) . $filename;

		if ( ! rename( $movefile['file'], $destination ) ) {
			return new WP_Error( 'move_failed', __( 'Impossible de déplacer le fichier.', 'ufsc-licence-competition' ) );
		}

		return array(
			'path' => $destination,
			'name' => $filename,
			'size' => (int) $file['size'],
		);
	}

	public function build_preview( $file_path, $force_club_id, $mapping = array(), $limit = 0, $season_end_year_override = null ) {
		$preview_rows = array();
		$errors       = array();
		$stats        = array(
			'total'                => 0,
			'clubs_linked'         => 0,
			'licences_linked'      => 0,
			'licences_created'     => 0,
			'licences_updated'     => 0,
			'clubs_from_note'      => 0,
			'club_not_found'       => 0,
			'needs_review'         => 0,
			'licence_not_found'    => 0,
			'invalid_asptt_number' => 0,
			'invalid_season'       => 0,
			'invalid_birthdate'    => 0,
		);

		$preview_limit = $this->sanitize_preview_limit( $limit );

		$parsed = $this->read_csv( $file_path, $mapping, $preview_limit );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$headers = $parsed['headers_raw'];
		$mapping = $parsed['mapping'];

		foreach ( $parsed['rows'] as $row ) {
			$stats['total']++;
			$result = $this->process_row( $row, $force_club_id, $stats, $season_end_year_override );

			if ( $result['preview'] ) {
				$preview_rows[] = $result['preview'];
			}
			if ( ! empty( $result['error'] ) ) {
				$errors[] = $result['error'];
			}
		}

		return array(
			'rows'          => $preview_rows,
			'stats'         => $stats,
			'errors'        => $errors,
			'headers'       => $headers,
			'mapping'       => $mapping,
			'preview_limit' => $preview_limit,
		);
	}

	/*
	 * Tests manuels (prod, sans régression) :
	 * 1) Simulation + "Voir détails" : lancer un dry-run avec erreurs, vérifier que le lien "Voir détails"
	 *    ouvre le rapport dry-run sans écritures en base (licences/documents/meta/hash).
	 * 2) Migration table hash existante : sur une table de hash pré-existante, relancer un import incrémental
	 *    et vérifier l'utilisation de la clé composite club_id + licence_number (fallback licence_number seul
	 *    migré vers la clé composite).
	 */
	public function import_from_file( $file_path, $force_club_id, $mapping = array(), $auto_approve = true, $season_end_year_override = null, $auto_save_alias = true, $incremental = true, $dry_run = false ) {
		global $wpdb;

		$started_at = microtime( true );
		$batch_id   = $dry_run ? '' : $this->generate_import_batch_id();

		$use_transactions = false;
		if ( ! $dry_run && $wpdb->has_cap( 'transactions' ) ) {
			$use_transactions = false !== $wpdb->query( 'START TRANSACTION' );
		}

		$inserted          = array();
		$created_documents = array();
		$created_meta      = array();
		$created_licences  = array();
		$updated_licences  = array();
		$has_error         = false;

		$stats = array(
			'total'                => 0,
			'ok'                   => 0,
			'errors'               => 0,
			'duplicates'           => 0,
			'skipped_not_found'    => 0,
			'skipped_ambiguous'    => 0,
			'clubs_linked'         => 0,
			'licences_linked'      => 0,
			'licences_created'     => 0,
			'licences_updated'     => 0,
			'licences_skipped'     => 0,
			'clubs_from_note'      => 0,
			'club_not_found'       => 0,
			'needs_review'         => 0,
			'licence_not_found'    => 0,
			'invalid_asptt_number' => 0,
			'invalid_season'       => 0,
			'invalid_birthdate'    => 0,
			'valid_rows'           => 0,
			'invalid_rows'         => 0,
			'rejected_rows'        => 0,
			'club_resolved'        => 0,
			'club_unresolved'      => 0,
		);

		$has_meta_table       = $this->table_exists( $this->get_documents_meta_table() );
		$hash_table_available = $dry_run ? $this->is_hash_table_available() : $this->ensure_hash_table_exists();
		$incremental      = (bool) $incremental;
		$dry_run          = (bool) $dry_run;
		$report_errors    = array();
		$report_clubs     = array();
		$delta_rows       = array();
		$this->row_index  = 0;
		$this->composite_cache = array();

		$process_result = $this->iterate_csv_rows_in_chunks(
			$file_path,
			$mapping,
			self::IMPORT_CHUNK_SIZE,
			function( $rows ) use ( $force_club_id, $auto_approve, $has_meta_table, &$inserted, &$created_documents, &$created_meta, &$created_licences, &$updated_licences, &$has_error, &$stats, $wpdb, $season_end_year_override, $auto_save_alias, $hash_table_available, $incremental, $dry_run, &$report_errors, &$report_clubs, &$delta_rows, $batch_id ) {
				$license_numbers = array();
				foreach ( $rows as $row ) {
					$license_number = $this->get_license_number_from_row( $row );
					if ( '' !== $license_number ) {
						$license_numbers[] = $license_number;
					}
				}

				$license_numbers  = array_values( array_unique( $license_numbers ) );
				$existing_licences = $this->get_existing_licences_map( $license_numbers );
				$existing_hashes   = $hash_table_available ? $this->get_existing_hashes( $license_numbers, true ) : array();

				foreach ( $rows as $row ) {
					$stats['total']++;
					$this->row_index++;

					$result    = $this->process_row( $row, $force_club_id, $stats, $season_end_year_override, false, $existing_licences );
					$data      = $result['data'];
					$asptt_no  = isset( $data['asptt_number'] ) ? $data['asptt_number'] : '';
					$note      = isset( $data['note'] ) ? $data['note'] : '';
					$row_error = $result['error'];

					if ( empty( $data['status'] ) || self::STATUS_LINKED !== $data['status'] ) {
						$stats['club_unresolved']++;
					}

					if ( ! empty( $row_error ) ) {
						$stats['errors']++;
						$stats['invalid_rows']++;
						$stats['rejected_rows']++;
						$this->push_report_error( $report_errors, $row_error, $this->row_index );
						continue;
					}

					if ( empty( $data['status'] ) || self::STATUS_LINKED !== $data['status'] || '' === $data['asptt_number'] ) {
						$stats['errors']++;
						$stats['invalid_rows']++;
						$stats['rejected_rows']++;
						if ( ! empty( $result['error'] ) ) {
							$this->push_report_error( $report_errors, $result['error'], $this->row_index );
						}
						continue;
					}

					$stats['valid_rows']++;
					$stats['club_resolved']++;

					if ( '' !== $note ) {
						$note_key = $this->normalize_hash_value( $note );
						if ( '' !== $note_key ) {
							if ( ! isset( $report_clubs[ $note_key ] ) ) {
								$report_clubs[ $note_key ] = array(
									'label' => $note,
									'count' => 1,
								);
							} else {
								$report_clubs[ $note_key ]['count']++;
							}
						}
					}

					$current_hash = $this->build_row_hash( $data );
					$club_id      = isset( $data['club_id'] ) ? (int) $data['club_id'] : 0;
					$existing_id  = 0;
					$ambiguous_ids = array();

					$match = $this->resolve_licence_match(
						isset( $data['licence_id_source'] ) ? (int) $data['licence_id_source'] : 0,
						$data['nom'] ?? '',
						$data['prenom'] ?? '',
						$data['date_naissance'] ?? '',
						$data['email'] ?? ''
					);
					$existing_id = $match['id'];
					$ambiguous_ids = $match['ambiguous_ids'];

					if ( ! $existing_id ) {
						if ( ! empty( $ambiguous_ids ) ) {
							$stats['skipped_ambiguous']++;
							$stats['errors']++;
							$stats['invalid_rows']++;
							$stats['rejected_rows']++;
							$this->push_report_error(
								$report_errors,
								array(
									'nom'          => $data['nom'],
									'prenom'       => $data['prenom'],
									'asptt_number' => $data['asptt_number'],
									'error'        => __( 'Licence ambiguë : plusieurs correspondances trouvées.', 'ufsc-licence-competition' ),
								),
								$this->row_index
							);
						} else {
							$stats['skipped_not_found']++;
							$stats['licence_not_found']++;
							$stats['errors']++;
							$stats['invalid_rows']++;
							$stats['rejected_rows']++;
							$error_message = 'licence_id_missing' === ( $match['resolution'] ?? '' )
								? __( 'Licence ID invalide.', 'ufsc-licence-competition' )
								: __( 'Licence introuvable : aucune création effectuée.', 'ufsc-licence-competition' );
							$this->push_report_error(
								$report_errors,
								array(
									'nom'          => $data['nom'],
									'prenom'       => $data['prenom'],
									'asptt_number' => $data['asptt_number'],
									'error'        => $error_message,
								),
								$this->row_index
							);
						}
						continue;
					}
					$previous_hash = '';
					$used_fallback_hash = false;

					if ( $hash_table_available ) {
						$hash_key = $this->build_hash_option_key( $asptt_no, $club_id );
						if ( isset( $existing_hashes[ $hash_key ] ) ) {
							$previous_hash = (string) $existing_hashes[ $hash_key ];
						} else {
							$fallback_key = $this->build_hash_option_key( $asptt_no, 0 );
							if ( isset( $existing_hashes[ $fallback_key ] ) ) {
								$previous_hash      = (string) $existing_hashes[ $fallback_key ];
								$used_fallback_hash = true;
							}
						}
					} else {
						$previous_hash = $this->get_existing_option_hash( $asptt_no, $club_id, $used_fallback_hash );
					}

					if ( $incremental && $existing_id && $current_hash && $previous_hash && hash_equals( $previous_hash, $current_hash ) ) {
						if ( $used_fallback_hash && ! $dry_run ) {
							$this->store_hash( $data['asptt_number'], $club_id, $previous_hash, $hash_table_available );
						}
						$existing_document = $this->find_document_by_source_number( $asptt_no );
						if ( $existing_document ) {
							$stats['licences_skipped']++;
							$stats['ok']++;
							continue;
						}
					}

					$licence_payload = array(
						'club_id'        => (int) $data['club_id'],
						'nom'            => $data['nom'],
						'prenom'         => $data['prenom'],
						'date_naissance' => $data['date_naissance'],
						'genre'          => $data['genre'],
						'email'          => $data['email'],
						'adresse'        => $data['adresse'],
						'ville'          => $data['ville'],
						'code_postal'    => $data['code_postal'],
						'telephone'      => $data['telephone'],
						'activite'       => $data['activite'],
						'region'         => $data['region'],
						'import_batch_id' => $batch_id,
					);

					$licence_result = array(
						'id'      => $existing_id,
						'created' => false,
					);

					if ( ! $dry_run ) {
						$licence_result = $this->upsert_licence_by_number(
							$data['asptt_number'],
							$licence_payload,
							$existing_id
						);

						if ( is_wp_error( $licence_result ) ) {
							$stats['errors']++;
							$stats['rejected_rows']++;
							$stats['invalid_rows']++;
							$this->log_import_warning(
								$licence_result->get_error_message(),
								array( 'asptt_number' => $data['asptt_number'] )
							);
							$this->push_report_error(
								$report_errors,
								array(
									'nom'          => $data['nom'],
									'prenom'       => $data['prenom'],
									'asptt_number' => $data['asptt_number'],
									'error'        => $licence_result->get_error_message(),
								),
								$this->row_index
							);
							continue;
						}
					}

					$stats['licences_updated']++;
					if ( ! $dry_run ) {
						$updated_licences[] = (int) $licence_result['id'];
					}

					if ( ! empty( $data['club_from_note'] ) ) {
						$stats['clubs_from_note']++;
					}

					$data['licence_id'] = (int) $licence_result['id'];

					// Update season/category/age_ref on licence.
					if ( ! $dry_run && apply_filters( 'ufsc_lc_asptt_update_season_data', false, $data ) ) {
						$this->update_licence_season_data(
							(int) $data['licence_id'],
							$data['season_end_year'],
							$data['category'],
							$data['age_ref']
						);
					}

					// Save alias (force club mode).
					if ( ! $dry_run ) {
						$this->save_alias_for_row( $force_club_id, $data, $auto_save_alias );
					}

					// Upsert document.
					$doc_result = array(
						'id'      => 0,
						'created' => false,
					);

					if ( ! $dry_run ) {
						$doc_result = $this->upsert_document(
							(int) $data['licence_id'],
							$data['asptt_number'],
							$data['attachment_id'],
							$data['note'],
							$data['source_created_at']
						);

						if ( false === $doc_result || ! empty( $wpdb->last_error ) ) {
							$has_error = true;
							$this->log_import_warning(
								__( 'Erreur SQL lors de l’import ASPTT.', 'ufsc-licence-competition' ),
								array( 'error' => $wpdb->last_error )
							);
							return false;
						}

						if ( $has_meta_table ) {
							$review_status = 'pending';
							$link_mode     = ! empty( $data['link_mode'] ) ? $data['link_mode'] : 'none';

							if ( $auto_approve && ! empty( $data['auto_linked'] ) ) {
								$review_status = 'approved';
							}

							$meta_result = $this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'confidence_score', (int) $data['confidence_score'] );
							if ( $meta_result && $meta_result['created'] ) {
								$created_meta[] = (int) $meta_result['id'];
							}
							$meta_result = $this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'link_mode', $link_mode );
							if ( $meta_result && $meta_result['created'] ) {
								$created_meta[] = (int) $meta_result['id'];
							}
							$meta_result = $this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'review_status', $review_status );
							if ( $meta_result && $meta_result['created'] ) {
								$created_meta[] = (int) $meta_result['id'];
							}
							$meta_result = $this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'club_resolution', $data['club_resolution'] );
							if ( $meta_result && $meta_result['created'] ) {
								$created_meta[] = (int) $meta_result['id'];
							}
							$meta_result = $this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'person_resolution', $data['person_resolution'] );
							if ( $meta_result && $meta_result['created'] ) {
								$created_meta[] = (int) $meta_result['id'];
							}
						}
					}

					// Duplicate check for business key (ASPTT number) pointing to another licence.
					$duplicate = $this->find_document_by_source_number( $data['asptt_number'] );
					if ( $duplicate && (int) $duplicate->licence_id !== (int) $data['licence_id'] ) {
						$stats['errors']++;
						$stats['duplicates']++;
						$stats['rejected_rows']++;
						$stats['invalid_rows']++;

						$this->log_import_warning(
							__( 'Doublon détecté sur la clé métier.', 'ufsc-licence-competition' ),
							array(
								'asptt_number' => $data['asptt_number'],
								'licence_id'   => (int) $data['licence_id'],
								'existing_id'  => (int) $duplicate->licence_id,
							)
						);

						$this->push_report_error(
							$report_errors,
							array(
								'nom'          => $data['nom'],
								'prenom'       => $data['prenom'],
								'asptt_number' => $data['asptt_number'],
								'error'        => __( 'Doublon détecté sur la clé métier.', 'ufsc-licence-competition' ),
							),
							$this->row_index
						);
						continue;
					}

					if ( ! $dry_run ) {
						$inserted[] = (int) $doc_result['id'];
						if ( $doc_result['created'] ) {
							$created_documents[] = (int) $doc_result['id'];
						}
					}
					$stats['ok']++;

					if ( $current_hash ) {
						if ( ! $dry_run ) {
							$this->store_hash( $data['asptt_number'], $club_id, $current_hash, $hash_table_available );
						}
					}

					$action = $licence_result['created'] ? 'created' : 'updated';
					$delta_rows[] = array(
						'action'          => $action,
						'asptt_number'    => $data['asptt_number'],
						'nom'             => $data['nom'],
						'prenom'          => $data['prenom'],
						'date_naissance'  => $data['date_naissance'],
						'genre'           => $data['genre'],
						'email'           => $data['email'],
						'activite'        => $data['activite'],
						'note'            => $data['note'],
						'region'          => $data['region'],
					);
				}

				return true;
			}
		);

		if ( is_wp_error( $process_result ) ) {
			return $process_result;
		}

		if ( $use_transactions ) {
			if ( $has_error ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'import_failed', __( 'Import interrompu, transaction annulée.', 'ufsc-licence-competition' ) );
			}
			$wpdb->query( 'COMMIT' );
		} elseif ( $has_error ) {
			$this->rollback_inserted( $inserted );
			return new WP_Error( 'import_failed', __( 'Import interrompu, rollback partiel effectué.', 'ufsc-licence-competition' ) );
		}

		$duration_sec = max( 0.001, microtime( true ) - $started_at );
		$rows_per_sec = ( $stats['total'] > 0 ) ? ( $stats['total'] / $duration_sec ) : 0;
		$hash_storage = $hash_table_available ? 'table' : 'option';
		$hash_notice  = $this->hash_table_notice;

		if ( ! $dry_run && function_exists( 'ufsc_lc_bump_cache_version' ) ) {
			ufsc_lc_bump_cache_version( 'club_all', 0 );
			ufsc_lc_bump_cache_version( 'status', 0 );
		}

		return array(
			'inserted'          => $inserted,
			'created_documents' => array_values( array_unique( $created_documents ) ),
			'created_meta'      => array_values( array_unique( $created_meta ) ),
			'created_licences'  => array_values( array_unique( $created_licences ) ),
			'updated_licences'  => array_values( array_unique( $updated_licences ) ),
			'batch_id'          => $batch_id,
			'used_transactions' => $use_transactions,
			'stats'             => $stats,
			'duration_sec'      => $duration_sec,
			'rows_per_sec'      => $rows_per_sec,
			'hash_storage'      => $hash_storage,
			'hash_notice'       => $hash_notice,
			'report'            => array(
				'errors' => $this->finalize_report_errors( $report_errors ),
				'clubs'  => $this->finalize_report_clubs( $report_clubs ),
			),
			'delta'             => $delta_rows,
		);
	}

	public function export_errors_csv( $errors ) {
		$filename = 'asptt-errors-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );
		fwrite( $output, "\xEF\xBB\xBF" );

		fputcsv(
			$output,
			array(
				__( 'Nom', 'ufsc-licence-competition' ),
				__( 'Prenom', 'ufsc-licence-competition' ),
				__( 'DateNaissance', 'ufsc-licence-competition' ),
				__( 'Note', 'ufsc-licence-competition' ),
				__( 'N° Licence', 'ufsc-licence-competition' ),
				__( 'Date ASPTT brute', 'ufsc-licence-competition' ),
				__( 'Status', 'ufsc-licence-competition' ),
				__( 'Erreur', 'ufsc-licence-competition' ),
			),
			';'
		);

		foreach ( $errors as $error ) {
			fputcsv(
				$output,
				array(
					$this->sanitize_csv_value( $error['nom'] ),
					$this->sanitize_csv_value( $error['prenom'] ),
					$this->sanitize_csv_value( $error['date_naissance'] ),
					$this->sanitize_csv_value( $error['note'] ),
					$this->sanitize_csv_value( $error['asptt_number'] ),
					$this->sanitize_csv_value( $error['source_created_at_raw'] ),
					$this->sanitize_csv_value( $error['status'] ),
					$this->sanitize_csv_value( $error['error'] ),
				),
				';'
			);
		}

		fclose( $output );
		exit;
	}

	public function export_delta_csv( $rows ) {
		$filename = 'asptt-delta-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );
		fwrite( $output, "\xEF\xBB\xBF" );

		fputcsv(
			$output,
			array(
				__( 'N° Licence', 'ufsc-licence-competition' ),
				__( 'Nom', 'ufsc-licence-competition' ),
				__( 'Prenom', 'ufsc-licence-competition' ),
				__( 'DateNaissance', 'ufsc-licence-competition' ),
				__( 'Sexe', 'ufsc-licence-competition' ),
				__( 'Email', 'ufsc-licence-competition' ),
				__( 'Activité', 'ufsc-licence-competition' ),
				__( 'Club (Note)', 'ufsc-licence-competition' ),
				__( 'Région', 'ufsc-licence-competition' ),
				__( 'Action', 'ufsc-licence-competition' ),
			),
			';'
		);

		foreach ( (array) $rows as $row ) {
			fputcsv(
				$output,
				array(
					$this->sanitize_csv_value( isset( $row['asptt_number'] ) ? $row['asptt_number'] : '' ),
					$this->sanitize_csv_value( isset( $row['nom'] ) ? $row['nom'] : '' ),
					$this->sanitize_csv_value( isset( $row['prenom'] ) ? $row['prenom'] : '' ),
					$this->sanitize_csv_value( isset( $row['date_naissance'] ) ? $row['date_naissance'] : '' ),
					$this->sanitize_csv_value( isset( $row['genre'] ) ? $row['genre'] : '' ),
					$this->sanitize_csv_value( isset( $row['email'] ) ? $row['email'] : '' ),
					$this->sanitize_csv_value( isset( $row['activite'] ) ? $row['activite'] : '' ),
					$this->sanitize_csv_value( isset( $row['note'] ) ? $row['note'] : '' ),
					$this->sanitize_csv_value( isset( $row['region'] ) ? $row['region'] : '' ),
					$this->sanitize_csv_value( isset( $row['action'] ) ? $row['action'] : '' ),
				),
				';'
			);
		}

		fclose( $output );
		exit;
	}

	public function compute_file_hash( $file_path ) {
		if ( ! $file_path || ! is_readable( $file_path ) ) {
			return '';
		}

		$hash = sha1_file( $file_path );
		return $hash ? $hash : '';
	}

	public function sanitize_mapping( $mapping ) {
		$allowed   = $this->get_allowed_columns();
		$sanitized = array();

		foreach ( (array) $mapping as $header => $column ) {
			$header_key = sanitize_text_field( wp_unslash( $header ) );
			$column_key = sanitize_text_field( wp_unslash( $column ) );

			if ( '' === $column_key || ! in_array( $column_key, $allowed, true ) ) {
				continue;
			}

			$sanitized[ $header_key ] = $column_key;
		}

		return $sanitized;
	}

	public function sanitize_preview_limit( $limit ) {
		$limit = absint( $limit );

		if ( $limit <= 0 ) {
			return self::PREVIEW_DEFAULT_LIMIT;
		}
		if ( $limit < self::PREVIEW_MIN_LIMIT ) {
			return self::PREVIEW_MIN_LIMIT;
		}
		if ( $limit > self::PREVIEW_MAX_LIMIT ) {
			return self::PREVIEW_MAX_LIMIT;
		}

		return $limit;
	}

	private function process_row( $row, $force_club_id, &$stats = null, $season_end_year_override = null, $track_licence_actions = true, $licence_map = array() ) {
		$note               = sanitize_text_field( $this->get_row_value( $row, 'Note' ) );
		$nom                = sanitize_text_field( $this->get_row_value( $row, 'Nom' ) );
		$prenom             = sanitize_text_field( $this->get_row_value( $row, 'Prenom' ) );
		$dob_raw            = sanitize_text_field( $this->get_row_value( $row, 'Date de naissance' ) );
		$raw_season_end_year = sanitize_text_field( $this->get_row_value( $row, 'Saison (année de fin)' ) );
		$email              = sanitize_email( $this->get_row_value( $row, 'Email' ) );
		$adresse            = sanitize_text_field( $this->get_row_value( $row, 'Adresse' ) );
		$ville              = sanitize_text_field( $this->get_row_value( $row, 'Ville' ) );
		$code_postal        = sanitize_text_field( $this->get_row_value( $row, 'Code postal' ) );
		$telephone_raw      = sanitize_text_field( $this->get_row_value( $row, 'Téléphone' ) );
		$activite           = sanitize_text_field( $this->get_row_value( $row, 'Activité' ) );
		$region             = sanitize_text_field( $this->get_row_value( $row, 'Région' ) );
		$licence_id_source  = absint( $this->get_row_value( $row, 'Licence ID' ) );
		$season_end_year     = UFSC_LC_Categories::sanitize_season_end_year( $raw_season_end_year );
		$season_end_year_override = UFSC_LC_Categories::sanitize_season_end_year( $season_end_year_override );

		$asptt_no_raw = sanitize_text_field( $this->get_row_value( $row, 'N° Licence' ) );
		$asptt_no     = $this->normalize_license_number( $asptt_no_raw );

		$genre          = sanitize_text_field( $this->get_row_value( $row, 'genre' ) );
		$genre          = $this->normalize_genre( $genre );
		$raw_created_at = sanitize_text_field( $this->get_row_value( $row, 'Date de création de la licence' ) );
		$telephone      = $this->normalize_phone( $telephone_raw );

		$dob                = $this->parse_date( $dob_raw );
		$source_created_at = $this->parse_source_created_at( $raw_created_at );

		$row_errors       = array();
		$club_suggestions = array();
		$category         = '';
		$age_ref          = null;

		$licence_id       = 0;
		$status           = self::STATUS_CLUB_NOT_FOUND;
		$skip_resolution  = false;
		$person_resolution = 'none';
		$club_from_note    = false;
		$licence_action    = 'none';

		$license_number_column = $this->get_license_number_column();

		$resolved = array(
			'status'      => self::STATUS_CLUB_NOT_FOUND,
			'club_id'     => 0,
			'suggestions' => array(),
			'resolution'  => 'none',
		);

		// Season + category computation at 31/12 (handled by UFSC_LC_Categories).
		if ( null === $season_end_year && null !== $season_end_year_override ) {
			$season_end_year = $season_end_year_override;
		}

		if ( null === $season_end_year ) {
			$default_season_end_year = $this->get_default_season_end_year();
			$season_end_year         = UFSC_LC_Categories::sanitize_season_end_year( $default_season_end_year );
		}

		if ( null === $season_end_year ) {
			$status          = self::STATUS_INVALID_SEASON;
			$skip_resolution = true;

			if ( is_array( $stats ) ) {
				$stats['invalid_season']++;
			}

			$row_errors[] = __( 'Saison de fin manquante ou invalide.', 'ufsc-licence-competition' );

			$this->log_import_warning(
				'Saison de fin manquante ou invalide.',
				array(
					'nom'            => $nom,
					'prenom'         => $prenom,
					'date_naissance' => $dob,
					'saison'         => $raw_season_end_year,
				)
			);
		} else {
			$computed = UFSC_LC_Categories::category_from_birthdate( $dob, $season_end_year );
			$category = $computed['category'];
			$age_ref  = $computed['age'];

			if ( '' === $dob || null === $age_ref ) {
				$status          = self::STATUS_INVALID_BIRTHDATE;
				$skip_resolution = true;

				if ( is_array( $stats ) ) {
					$stats['invalid_birthdate']++;
				}

				$row_errors[] = __( 'Date de naissance invalide.', 'ufsc-licence-competition' );

				$this->log_import_warning(
					'Date de naissance invalide.',
					array(
						'nom'            => $nom,
						'prenom'         => $prenom,
						'date_naissance' => $dob,
					)
				);
			}
		}

		if ( '' === $asptt_no ) {
			$status          = self::STATUS_INVALID_ASPTT_NUMBER;
			$skip_resolution = true;

			if ( is_array( $stats ) ) {
				$stats['invalid_asptt_number']++;
			}

			$row_errors[] = __( 'N° Licence ASPTT manquant.', 'ufsc-licence-competition' );

			$this->log_import_warning(
				__( 'N° Licence ASPTT manquant.', 'ufsc-licence-competition' ),
				array(
					'nom'            => $nom,
					'prenom'         => $prenom,
					'date_naissance' => $dob,
					'note'           => $note,
				)
			);
		}

		if ( ! $skip_resolution && '' === $license_number_column ) {
			$status          = self::STATUS_INVALID_ASPTT_NUMBER;
			$skip_resolution = true;

			if ( is_array( $stats ) ) {
				$stats['invalid_asptt_number']++;
			}

			$row_errors[] = __( 'Colonne numéro de licence indisponible.', 'ufsc-licence-competition' );

			$this->log_import_warning(
				__( 'Colonne numéro de licence indisponible.', 'ufsc-licence-competition' ),
				array(
					'nom'            => $nom,
					'prenom'         => $prenom,
					'date_naissance' => $dob_raw,
				)
			);
		}

		if ( ! $skip_resolution && '' !== $asptt_no ) {
			$resolved         = $this->resolve_club( $note, $force_club_id );
			$club_suggestions = $resolved['suggestions'];

			if ( self::STATUS_LINKED === $resolved['status'] ) {
				if ( is_array( $stats ) ) {
					$stats['clubs_linked']++;
				}

				$match = $this->resolve_licence_match(
					$licence_id_source,
					$nom,
					$prenom,
					$dob,
					$email
				);
				$licence_id        = $match['id'];
				$person_resolution = $match['resolution'];

				if ( $licence_id ) {
					$licence_action = 'update';
					$status         = self::STATUS_LINKED;

					if ( is_array( $stats ) ) {
						$stats['licences_linked']++;
					}

					if ( $track_licence_actions && is_array( $stats ) ) {
						$stats['licences_updated']++;
					}
				} elseif ( ! empty( $match['ambiguous_ids'] ) ) {
					$status = self::STATUS_NEEDS_REVIEW;

					if ( is_array( $stats ) ) {
						$stats['needs_review']++;
					}

					$row_errors[] = __( 'Licence ambiguë : plusieurs correspondances trouvées.', 'ufsc-licence-competition' );
				} else {
					$status = self::STATUS_LICENCE_MISSING;

					if ( is_array( $stats ) ) {
						$stats['licence_not_found']++;
					}

					if ( 'licence_id_missing' === $match['resolution'] ) {
						$row_errors[] = __( 'Licence ID invalide.', 'ufsc-licence-competition' );
					} else {
						$row_errors[] = __( 'Licence introuvable : aucune création effectuée.', 'ufsc-licence-competition' );
					}
				}

				if ( 'forced' !== $resolved['resolution'] ) {
					$club_from_note = true;
					if ( $track_licence_actions && is_array( $stats ) ) {
						$stats['clubs_from_note']++;
					}
				}
			} elseif ( self::STATUS_NEEDS_REVIEW === $resolved['status'] ) {
				$status = self::STATUS_NEEDS_REVIEW;

				if ( is_array( $stats ) ) {
					$stats['needs_review']++;
				}

				$row_errors[] = __( 'Plusieurs clubs possibles.', 'ufsc-licence-competition' );
			} else {
				$status = self::STATUS_CLUB_NOT_FOUND;

				if ( is_array( $stats ) ) {
					$stats['club_not_found']++;
				}

				$row_errors[] = __( 'Club introuvable.', 'ufsc-licence-competition' );
			}
		}

		if ( '' !== $raw_created_at && null === $source_created_at ) {
			$row_errors[] = sprintf(
				/* translators: %s: raw date string */
				__( 'Date de création de la licence invalide: %s', 'ufsc-licence-competition' ),
				$raw_created_at
			);

			$this->log_import_warning(
				__( 'Date de création de la licence invalide.', 'ufsc-licence-competition' ),
				array( 'value' => $raw_created_at )
			);
		}

		$confidence = $this->compute_confidence_score(
			array(
				'status'            => $status,
				'club_resolution'   => $resolved['resolution'],
				'person_resolution' => $person_resolution,
				'raw_created_at'    => $raw_created_at,
				'source_created_at' => $source_created_at,
				'has_error'         => ! empty( $row_errors ),
			)
		);

		$review_status = $confidence['auto_linked'] ? 'auto' : 'pending';

		$preview = array(
			'nom'                  => $nom,
			'prenom'               => $prenom,
			'date_naissance'       => $dob,
			'season_end_year'      => $season_end_year,
			'category'             => $category,
			'age_ref'              => $age_ref,
			'note'                 => $note,
			'email'                => $email,
			'adresse'              => $adresse,
			'ville'                => $ville,
			'code_postal'          => $code_postal,
			'telephone'            => $telephone,
			'activite'             => $activite,
			'region'               => $region,
			'genre'                => $genre,
			'asptt_number'         => $asptt_no,
			'source_created_at'    => $source_created_at,
			'source_created_at_raw'=> $raw_created_at,
			'club_id'              => $resolved['club_id'],
			'club_suggestions'     => $club_suggestions,
			'status'               => $status,
			'licence_id_source'    => $licence_id_source,
			'licence_id'           => $licence_id,
			'attachment_id'        => 0,
			'has_error'            => ! empty( $row_errors ),
			'confidence_score'     => $confidence['confidence_score'],
			'link_mode'            => $confidence['link_mode'],
			'review_status'        => $review_status,
			'auto_linked'          => $confidence['auto_linked'],
			'club_resolution'      => $resolved['resolution'],
			'person_resolution'    => $person_resolution,
			'club_from_note'       => $club_from_note,
			'licence_action'       => $licence_action,
		);

		$error = null;
		if ( ! empty( $row_errors ) ) {
			$error = array(
				'nom'                  => $nom,
				'prenom'               => $prenom,
				'date_naissance'       => $dob,
				'note'                 => $note,
				'asptt_number'         => $asptt_no,
				'source_created_at'    => ( null !== $source_created_at ) ? $source_created_at : $raw_created_at,
				'source_created_at_raw'=> $raw_created_at,
				'status'               => $status,
				'error'                => implode( ' | ', $row_errors ),
			);
		}

		return array(
			'preview' => $preview,
			'error'   => $error,
			'data'    => $preview,
		);
	}

	private function read_csv( $file_path, $mapping, $limit = 0 ) {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_read', __( 'Fichier illisible.', 'ufsc-licence-competition' ) );
		}

		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'file_open', __( 'Impossible d’ouvrir le fichier.', 'ufsc-licence-competition' ) );
		}

		$delimiter  = $this->detect_delimiter( $handle );
		$header     = array();
		$raw_header = array();
		$rows       = array();

		while ( false !== ( $data = fgetcsv( $handle, 0, $delimiter ) ) ) {
			$data = $this->convert_row_encoding( $data );

			if ( $this->is_empty_row( $data ) ) {
				continue;
			}

			if ( empty( $header ) ) {
				$raw_header = $data;
				$header     = $this->map_headers( $data, $mapping );
				continue;
			}

			if ( $this->looks_like_header( $data, $header ) ) {
				continue;
			}

			$row = array();
			foreach ( $header as $index => $column ) {
				if ( '' === $column ) {
					continue;
				}
				$row[ $column ] = isset( $data[ $index ] ) ? $data[ $index ] : '';
			}

			$rows[] = $row;

			if ( $limit > 0 && count( $rows ) >= $limit ) {
				break;
			}
		}

		fclose( $handle );

		return array(
			'rows'        => $rows,
			'headers'     => $header,
			'headers_raw' => $raw_header,
			'mapping'     => $mapping,
		);
	}

	private function iterate_csv_rows_in_chunks( $file_path, $mapping, $chunk_size, $callback ) {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_read', __( 'Fichier illisible.', 'ufsc-licence-competition' ) );
		}

		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'file_open', __( 'Impossible d’ouvrir le fichier.', 'ufsc-licence-competition' ) );
		}

		$delimiter = $this->detect_delimiter( $handle );
		$header    = array();
		$chunk     = array();

		while ( false !== ( $data = fgetcsv( $handle, 0, $delimiter ) ) ) {
			$data = $this->convert_row_encoding( $data );

			if ( $this->is_empty_row( $data ) ) {
				continue;
			}

			if ( empty( $header ) ) {
				$header = $this->map_headers( $data, $mapping );
				continue;
			}

			if ( $this->looks_like_header( $data, $header ) ) {
				continue;
			}

			$row = array();
			foreach ( $header as $index => $column ) {
				if ( '' === $column ) {
					continue;
				}
				$row[ $column ] = isset( $data[ $index ] ) ? $data[ $index ] : '';
			}

			$chunk[] = $row;

			if ( $chunk_size > 0 && count( $chunk ) >= $chunk_size ) {
				if ( false === call_user_func( $callback, $chunk ) ) {
					fclose( $handle );
					return false;
				}
				$chunk = array();
			}
		}

		if ( ! empty( $chunk ) ) {
			if ( false === call_user_func( $callback, $chunk ) ) {
				fclose( $handle );
				return false;
			}
		}

		fclose( $handle );
		return true;
	}

	private function detect_delimiter( $handle ) {
		$line = fgets( $handle );
		rewind( $handle );

		if ( false === $line ) {
			return ';';
		}

		$semicolon = substr_count( $line, ';' );
		$comma     = substr_count( $line, ',' );
		$tab       = substr_count( $line, "\t" );

		if ( $tab > $semicolon && $tab > $comma ) {
			return "\t";
		}

		return $comma > $semicolon ? ',' : ';';
	}

	private function convert_row_encoding( array $data ) {
		foreach ( $data as $index => $value ) {
			$data[ $index ] = $this->convert_to_utf8( $value );
		}
		return $data;
	}

	private function convert_to_utf8( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'mb_detect_encoding' ) ) {
			$encoding = mb_detect_encoding( $value, array( 'UTF-8', 'ISO-8859-1', 'Windows-1252' ), true );
			if ( $encoding && 'UTF-8' !== $encoding ) {
				$value = mb_convert_encoding( $value, 'UTF-8', $encoding );
			}
		}

		return $value;
	}

	private function is_empty_row( $data ) {
		foreach ( $data as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return false;
			}
		}
		return true;
	}

	private function looks_like_header( $data, $header ) {
		$normalized = array();
		foreach ( $data as $value ) {
			$normalized[] = $this->normalize_header( $value );
		}

		$header_normalized = array();
		foreach ( $header as $value ) {
			$header_normalized[] = $this->normalize_header( $value );
		}

		return ! empty( $normalized ) && $normalized === $header_normalized;
	}

	private function map_headers( $headers, $mapping ) {
		$mapped   = array();
		$used     = array();
		$allowed  = $this->get_allowed_columns();
		$mapping  = $this->sanitize_mapping( $mapping );

		foreach ( $headers as $header ) {
			$header = trim( (string) $header );
			$key    = isset( $mapping[ $header ] ) ? $mapping[ $header ] : $this->map_header( $header );

			if ( ! in_array( $key, $allowed, true ) ) {
				$key = '';
			}
			if ( $key && isset( $used[ $key ] ) ) {
				$key = '';
			}
			if ( $key ) {
				$used[ $key ] = true;
			}

			$mapped[] = $key;
		}

		return $mapped;
	}

	private function map_header( $header ) {
		$normalized = $this->normalize_header( $header );

		$mapping = array(
			'nom'                         => 'Nom',
			'nom de famille'              => 'Nom',
			'last name'                   => 'Nom',
			'prenom'                      => 'Prenom',
			'first name'                  => 'Prenom',
			'prenom(s)'                   => 'Prenom',
			'email'                       => 'Email',
			'e mail'                      => 'Email',
			'mail'                        => 'Email',
			'adresse'                     => 'Adresse',
			'address'                     => 'Adresse',
			'ville'                       => 'Ville',
			'city'                        => 'Ville',
			'code postal'                 => 'Code postal',
			'code postale'                => 'Code postal',
			'cp'                          => 'Code postal',
			'zipcode'                     => 'Code postal',
			'postal code'                 => 'Code postal',
			'telephone'                   => 'Téléphone',
			'telephone portable'          => 'Téléphone',
			'tel'                         => 'Téléphone',
			'phone'                       => 'Téléphone',
			'genre'                       => 'genre',
			'sexe'                        => 'genre',
			'date de naissance'           => 'Date de naissance',
			'date naissance'              => 'Date de naissance',
			'date of birth'               => 'Date de naissance',
			'birth date'                  => 'Date de naissance',
			'saison'                      => 'Saison (année de fin)',
			'fin de saison'               => 'Saison (année de fin)',
			'saison fin'                  => 'Saison (année de fin)',
			'annee de fin de saison'      => 'Saison (année de fin)',
			'annee fin saison'            => 'Saison (année de fin)',
			'season end year'             => 'Saison (année de fin)',
			'season end'                  => 'Saison (année de fin)',
			'season'                      => 'Saison (année de fin)',
			'season_end_year'             => 'Saison (année de fin)',
			'saison_fin'                  => 'Saison (année de fin)',
			'activite'                    => 'Activité',
			'activite principale'         => 'Activité',
			'activity'                    => 'Activité',
			'n licence'                   => 'N° Licence',
			'n licence asptt'             => 'N° Licence',
			'no licence'                  => 'N° Licence',
			'numero licence'              => 'N° Licence',
			'numero de licence'           => 'N° Licence',
			'num licence'                 => 'N° Licence',
			'numero de licence asptt'     => 'N° Licence',
			'no license'                  => 'N° Licence',
			'licence number'              => 'N° Licence',
			'license number'              => 'N° Licence',
			'date de creation de la licence' => 'Date de création de la licence',
			'date d creation de la licence'  => 'Date de création de la licence',
			'date creation de la licence'    => 'Date de création de la licence',
			'date creation licence'          => 'Date de création de la licence',
			'date de creation licence'       => 'Date de création de la licence',
			'date creation de licence'       => 'Date de création de la licence',
			'date creation'                  => 'Date de création de la licence',
			'note'                           => 'Note',
			'club note'                      => 'Note',
			'region'                         => 'Région',
			'region club'                    => 'Région',
		);

		return isset( $mapping[ $normalized ] ) ? $mapping[ $normalized ] : '';
	}

	private function normalize_header( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = $this->strip_utf8_bom( $value );
		$value = remove_accents( $value );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/u', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return trim( $value );
	}

	private function resolve_club( $note, $force_club_id ) {
		if ( $force_club_id ) {
			return array(
				'status'      => self::STATUS_LINKED,
				'club_id'     => $force_club_id,
				'suggestions' => array(),
				'resolution'  => 'forced',
			);
		}

		if ( '' === $note ) {
			return array(
				'status'      => self::STATUS_CLUB_NOT_FOUND,
				'club_id'     => 0,
				'suggestions' => array(),
				'resolution'  => 'none',
			);
		}

		$normalized = $this->normalize_name( $note );

		$club = $this->find_club_by_name( $normalized );
		if ( $club ) {
			return array(
				'status'      => self::STATUS_LINKED,
				'club_id'     => (int) $club->id,
				'suggestions' => array(),
				'resolution'  => 'exact',
			);
		}

		$alias = $this->find_alias( $normalized );
		if ( $alias ) {
			return array(
				'status'      => self::STATUS_LINKED,
				'club_id'     => (int) $alias->club_id,
				'suggestions' => array(),
				'resolution'  => 'alias',
			);
		}

		$suggestions = $this->find_club_suggestions( $normalized );
		if ( 1 === count( $suggestions ) ) {
			return array(
				'status'      => self::STATUS_LINKED,
				'club_id'     => (int) $suggestions[0]->id,
				'suggestions' => array(),
				'resolution'  => 'suggestion',
			);
		}

		if ( count( $suggestions ) > 1 ) {
			$suggestion_rows = array();
			foreach ( $suggestions as $suggestion ) {
				$suggestion_rows[] = array(
					'id'   => (int) $suggestion->id,
					'name' => $suggestion->nom,
				);
			}

			return array(
				'status'      => self::STATUS_NEEDS_REVIEW,
				'club_id'     => 0,
				'suggestions' => $suggestion_rows,
				'resolution'  => 'none',
			);
		}

		return array(
			'status'      => self::STATUS_CLUB_NOT_FOUND,
			'club_id'     => 0,
			'suggestions' => array(),
			'resolution'  => 'none',
		);
	}

	private function find_licence_match( $club_id, $nom, $prenom, $dob, $genre ) {
		global $wpdb;

		$table = $this->get_licences_table();

		$normalized_nom    = $this->normalize_name( $nom );
		$normalized_prenom = $this->normalize_name( $prenom );
		$dob_value         = $this->parse_date( $dob );

		if ( ! $dob_value ) {
			return array(
				'id'         => 0,
				'resolution' => 'none',
			);
		}

		$has_nom         = $this->column_exists( $table, 'nom' );
		$has_nom_licence = $this->column_exists( $table, 'nom_licence' );
		$name_parts      = array();
		if ( $has_nom ) {
			$name_parts[] = "NULLIF(nom, '')";
		}
		if ( $has_nom_licence ) {
			$name_parts[] = "NULLIF(nom_licence, '')";
		}
		$nom_affiche_sql = empty( $name_parts ) ? "''" : 'COALESCE(' . implode( ', ', $name_parts ) . ')';

		$sql = "SELECT id, sexe, {$nom_affiche_sql} AS nom_affiche, prenom, date_naissance FROM {$table}
			WHERE club_id = %d AND date_naissance = %s";

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $club_id, $dob_value ) );
		if ( empty( $results ) ) {
			return array(
				'id'         => 0,
				'resolution' => 'none',
			);
		}

		$matches = array();
		foreach ( $results as $row ) {
			if ( $this->normalize_name( $row->nom_affiche ) === $normalized_nom
				&& $this->normalize_name( $row->prenom ) === $normalized_prenom ) {
				$matches[] = $row;
			}
		}

		if ( empty( $matches ) ) {
			return array(
				'id'         => 0,
				'resolution' => 'none',
			);
		}

		if ( 1 === count( $matches ) ) {
			return array(
				'id'         => (int) $matches[0]->id,
				'resolution' => 'unique',
			);
		}

		if ( $genre ) {
			$genre_normalized = strtolower( trim( $genre ) );
			foreach ( $matches as $match ) {
				if ( strtolower( $match->sexe ) === $genre_normalized ) {
					return array(
						'id'         => (int) $match->id,
						'resolution' => 'gender_tiebreak',
					);
				}
			}
		}

		return array(
			'id'         => 0,
			'resolution' => 'none',
		);
	}

	private function compute_confidence( $context ) {
		return $this->compute_confidence_score( $context );
	}

	private function compute_confidence_score( $context ) {
		$score = 0;

		$club_resolution   = isset( $context['club_resolution'] ) ? $context['club_resolution'] : 'none';
		$person_resolution = isset( $context['person_resolution'] ) ? $context['person_resolution'] : 'none';

		switch ( $club_resolution ) {
			case 'forced':
				$score += 50;
				break;
			case 'exact':
				$score += 45;
				break;
			case 'alias':
				$score += 35;
				break;
			case 'suggestion':
			case 'suggestion_unique':
				$score += 25;
				break;
		}

		switch ( $person_resolution ) {
			case 'unique':
				$score += 45;
				break;
			case 'gender_tiebreak':
				$score += 20;
				break;
			case 'license_number':
				$score += 60;
				break;
			case 'license_number_new':
				$score += 50;
				break;
		}

		$raw_created_at    = isset( $context['raw_created_at'] ) ? (string) $context['raw_created_at'] : '';
		$source_created_at = isset( $context['source_created_at'] ) ? $context['source_created_at'] : null;

		if ( '' !== $raw_created_at && null === $source_created_at ) {
			$score -= 20;
		}

		$score = max( 0, min( 100, $score ) );

		$has_error   = ! empty( $context['has_error'] );
		$status      = isset( $context['status'] ) ? $context['status'] : '';
		$auto_linked = ( self::STATUS_LINKED === $status && $score >= 85 && ! $has_error );

		if ( self::STATUS_LINKED === $status ) {
			$link_mode = $auto_linked ? 'auto' : 'manual';
		} else {
			$link_mode = 'none';
		}

		return array(
			'confidence_score' => (int) $score,
			'auto_linked'      => $auto_linked,
			'link_mode'        => $link_mode,
		);
	}

	private function save_alias_for_row( $force_club_id, $data, $auto_save_alias ) {
		if ( $auto_save_alias && ! empty( $force_club_id ) && '' !== $data['note'] ) {
			$this->save_alias( (int) $force_club_id, $data['note'] );
		}
	}

	private function get_default_season_end_year() {
		if ( class_exists( 'UFSC_LC_Settings_Page' ) ) {
			return UFSC_LC_Settings_Page::get_default_season_end_year();
		}

		$option = get_option( 'ufsc_lc_default_season_end_year', 2026 );
		$year   = UFSC_LC_Categories::sanitize_season_end_year( $option );

		return $year ? $year : 2026;
	}

	public function save_alias( $club_id, $alias ) {
		global $wpdb;

		$table = $this->get_aliases_table();

		$normalized = $this->normalize_name( $alias );
		if ( '' === $normalized ) {
			return;
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE alias_normalized = %s", $normalized )
		);

		if ( $exists ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'club_id'          => $club_id,
				'alias'            => $alias,
				'alias_normalized' => $normalized,
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	private function upsert_document( $licence_id, $source_licence_number, $attachment_id, $note, $source_created_at ) {
		global $wpdb;

		$table = $this->get_documents_table();

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s",
				$licence_id,
				self::SOURCE
			)
		);

		$data = array(
			'licence_id'             => $licence_id,
			'source'                 => self::SOURCE,
			'source_licence_number'  => $source_licence_number,
			'attachment_id'          => $attachment_id ? $attachment_id : null,
			'asptt_club_note'        => $note,
			'updated_at'             => current_time( 'mysql' ),
		);

		$field_formats = array(
			'licence_id'            => '%d',
			'source'                => '%s',
			'source_licence_number' => '%s',
			'attachment_id'         => '%d',
			'asptt_club_note'       => '%s',
			'updated_at'            => '%s',
			'source_created_at'     => '%s',
			'imported_at'           => '%s',
		);

		$formats = array();
		foreach ( array_keys( $data ) as $field ) {
			if ( isset( $field_formats[ $field ] ) ) {
				$formats[] = $field_formats[ $field ];
			}
		}

		if ( null !== $source_created_at ) {
			$data['source_created_at'] = $source_created_at;
			$formats[]                 = '%s';
		}

		if ( $existing ) {
			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $existing ),
				$formats,
				array( '%d' )
			);

			if ( false === $updated && ! empty( $wpdb->last_error ) ) {
				return false;
			}

			return array(
				'id'      => (int) $existing,
				'created' => false,
			);
		}

		$data['imported_at'] = current_time( 'mysql' );

		$formats = array();
		foreach ( array_keys( $data ) as $field ) {
			if ( isset( $field_formats[ $field ] ) ) {
				$formats[] = $field_formats[ $field ];
			}
		}

		$wpdb->insert( $table, $data, $formats );

		if ( ! empty( $wpdb->last_error ) ) {
			return false;
		}

		return array(
			'id'      => (int) $wpdb->insert_id,
			'created' => true,
		);
	}

	private function upsert_document_meta( $licence_id, $source, $meta_key, $meta_value ) {
		global $wpdb;

		$table = $this->get_documents_meta_table();
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s AND meta_key = %s",
				$licence_id,
				$source,
				$meta_key
			)
		);

		$data = array(
			'licence_id' => $licence_id,
			'source'     => $source,
			'meta_key'   => $meta_key,
			'meta_value' => maybe_serialize( $meta_value ),
			'updated_at' => current_time( 'mysql' ),
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s' );

		if ( $existing ) {
			$updated = false !== $wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $existing ),
				$formats,
				array( '%d' )
			);
			return array(
				'id'      => (int) $existing,
				'created' => false,
				'updated' => $updated,
			);
		}

		$inserted = false !== $wpdb->insert( $table, $data, $formats );
		return array(
			'id'      => $inserted ? (int) $wpdb->insert_id : 0,
			'created' => $inserted,
			'updated' => false,
		);
	}

	public function rollback_import_batch( $batch ) {
		$documents = isset( $batch['created_documents'] ) ? array_map( 'absint', (array) $batch['created_documents'] ) : array();
		$meta      = isset( $batch['created_meta'] ) ? array_map( 'absint', (array) $batch['created_meta'] ) : array();
		$licences  = isset( $batch['created_licences'] ) ? array_map( 'absint', (array) $batch['created_licences'] ) : array();
		$updated   = isset( $batch['updated_licences'] ) ? array_map( 'absint', (array) $batch['updated_licences'] ) : array();

		$documents = array_values( array_filter( array_unique( $documents ) ) );
		$meta      = array_values( array_filter( array_unique( $meta ) ) );
		$licences  = array_values( array_filter( array_unique( $licences ) ) );
		$updated   = array_values( array_filter( array_unique( $updated ) ) );

		if ( empty( $documents ) && empty( $meta ) && empty( $licences ) && empty( $updated ) ) {
			return new WP_Error( 'asptt_rollback_empty', __( 'Aucun élément à annuler.', 'ufsc-licence-competition' ) );
		}

		global $wpdb;
		$cleared_batches = 0;

		if ( ! empty( $updated ) ) {
			$table = $this->get_licences_table();
			if ( $this->table_exists( $table ) && $this->column_exists( $table, 'import_batch_id' ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $updated ), '%d' ) );
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET import_batch_id = NULL WHERE id IN ({$placeholders})",
						$updated
					)
				);
				$cleared_batches = (int) $wpdb->rows_affected;
			}
		}

		return array(
			'cleared_batches' => $cleared_batches,
			'skipped_updates' => count( $updated ),
		);
	}

	private function normalize_name( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = strtoupper( $value );
		$value = remove_accents( $value );
		$value = preg_replace( '/[^A-Z0-9]+/u', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return trim( $value );
	}

	private function get_composite_licence_map( $club_id, $season_end_year ) {
		$club_id         = (int) $club_id;
		$season_end_year = UFSC_LC_Categories::sanitize_season_end_year( $season_end_year );

		if ( ! $club_id || ! $season_end_year ) {
			return array();
		}

		$cache_key = $club_id . '|' . $season_end_year;
		if ( isset( $this->composite_cache[ $cache_key ] ) ) {
			return $this->composite_cache[ $cache_key ];
		}

		global $wpdb;

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			$this->composite_cache[ $cache_key ] = array();
			return $this->composite_cache[ $cache_key ];
		}

		$has_nom         = $this->column_exists( $table, 'nom' );
		$has_nom_licence = $this->column_exists( $table, 'nom_licence' );

		if (
			! $this->column_exists( $table, 'club_id' )
			|| ( ! $has_nom && ! $has_nom_licence )
			|| ! $this->column_exists( $table, 'prenom' )
			|| ! $this->column_exists( $table, 'date_naissance' )
			|| ! $this->column_exists( $table, 'season_end_year' )
		) {
			$this->composite_cache[ $cache_key ] = array();
			return $this->composite_cache[ $cache_key ];
		}

		$nom_parts = array();
		if ( $has_nom ) {
			$nom_parts[] = "NULLIF(nom, '')";
		}
		if ( $has_nom_licence ) {
			$nom_parts[] = "NULLIF(nom_licence, '')";
		}
		$nom_affiche_sql = empty( $nom_parts ) ? "''" : 'COALESCE(' . implode( ', ', $nom_parts ) . ')';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, {$nom_affiche_sql} AS nom_affiche, prenom, date_naissance, season_end_year
					FROM {$table}
					WHERE club_id = %d AND season_end_year = %d",
				$club_id,
				$season_end_year
			),
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			$key = $this->build_composite_key(
				$club_id,
				$row['nom_affiche'],
				$row['prenom'],
				$row['date_naissance'],
				$row['season_end_year']
			);
			if ( '' !== $key ) {
				if ( ! isset( $map[ $key ] ) ) {
					$map[ $key ] = array();
				}
				$map[ $key ][] = (int) $row['id'];
			}
		}

		$this->composite_cache[ $cache_key ] = $map;
		return $map;
	}

	private function find_existing_licence_ids_by_composite( $data, $composite_map ) {
		if ( empty( $data ) || empty( $composite_map ) ) {
			return array();
		}

		$club_id = isset( $data['club_id'] ) ? (int) $data['club_id'] : 0;
		$key     = $this->build_composite_key(
			$club_id,
			isset( $data['nom'] ) ? $data['nom'] : '',
			isset( $data['prenom'] ) ? $data['prenom'] : '',
			isset( $data['date_naissance'] ) ? $data['date_naissance'] : '',
			isset( $data['season_end_year'] ) ? $data['season_end_year'] : ''
		);

		if ( '' === $key ) {
			return array();
		}

		return isset( $composite_map[ $key ] ) ? array_values( array_unique( $composite_map[ $key ] ) ) : array();
	}

	private function build_composite_key( $club_id, $nom, $prenom, $date_naissance, $season_end_year ) {
		$club_id         = (int) $club_id;
		$season_end_year = UFSC_LC_Categories::sanitize_season_end_year( $season_end_year );
		$dob             = $this->parse_date( $date_naissance );
		$nom_key         = $this->normalize_name( $nom );
		$prenom_key      = $this->normalize_name( $prenom );

		if ( ! $club_id || ! $season_end_year || '' === $dob || '' === $nom_key || '' === $prenom_key ) {
			return '';
		}

		return implode( '|', array( $club_id, $nom_key, $prenom_key, $dob, $season_end_year ) );
	}

	private function resolve_licence_match( int $licence_id, string $nom, string $prenom, string $dob, string $email ): array {
		if ( $licence_id ) {
			if ( $this->licence_exists( $licence_id ) ) {
				return array(
					'id' => $licence_id,
					'ambiguous_ids' => array(),
					'resolution' => 'licence_id',
				);
			}

			return array(
				'id' => 0,
				'ambiguous_ids' => array(),
				'resolution' => 'licence_id_missing',
			);
		}

		$identity_ids = $this->find_existing_licence_ids_by_identity( $nom, $prenom, $dob );
		if ( count( $identity_ids ) === 1 ) {
			return array(
				'id' => (int) $identity_ids[0],
				'ambiguous_ids' => array(),
				'resolution' => 'identity',
			);
		}

		if ( count( $identity_ids ) > 1 ) {
			return array(
				'id' => 0,
				'ambiguous_ids' => $identity_ids,
				'resolution' => 'identity',
			);
		}

		$email_ids = $this->find_existing_licence_ids_by_email( $email );
		if ( count( $email_ids ) === 1 ) {
			return array(
				'id' => (int) $email_ids[0],
				'ambiguous_ids' => array(),
				'resolution' => 'email',
			);
		}

		if ( count( $email_ids ) > 1 ) {
			return array(
				'id' => 0,
				'ambiguous_ids' => $email_ids,
				'resolution' => 'email',
			);
		}

		return array(
			'id' => 0,
			'ambiguous_ids' => array(),
			'resolution' => 'none',
		);
	}

	private function licence_exists( int $licence_id ): bool {
		global $wpdb;

		if ( ! $licence_id ) {
			return false;
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE id = %d",
				$licence_id
			)
		);

		return (int) $found === $licence_id;
	}

	private function find_existing_licence_ids_by_identity( string $nom, string $prenom, string $dob ): array {
		global $wpdb;

		$nom    = trim( $nom );
		$prenom = trim( $prenom );
		$dob    = $this->parse_date( $dob );

		if ( '' === $nom || '' === $prenom || '' === $dob ) {
			return array();
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$columns = $this->get_licence_columns();
		if ( ! in_array( 'prenom', $columns, true ) || ! in_array( 'date_naissance', $columns, true ) ) {
			return array();
		}

		$last_name_columns = array();
		if ( in_array( 'nom', $columns, true ) ) {
			$last_name_columns[] = 'nom';
		}
		if ( in_array( 'nom_licence', $columns, true ) ) {
			$last_name_columns[] = 'nom_licence';
		}
		if ( empty( $last_name_columns ) ) {
			return array();
		}

		$parts = array();
		foreach ( $last_name_columns as $column ) {
			$parts[] = "NULLIF({$column}, '')";
		}
		$last_name_expr = 'COALESCE(' . implode( ', ', $parts ) . ')';

		$like_nom    = '%' . $wpdb->esc_like( $nom ) . '%';
		$like_prenom = '%' . $wpdb->esc_like( $prenom ) . '%';

		$sql = $wpdb->prepare(
			"SELECT id, {$last_name_expr} AS nom_affiche, prenom, date_naissance FROM {$table} WHERE date_naissance = %s AND {$last_name_expr} LIKE %s AND prenom LIKE %s",
			$dob,
			$like_nom,
			$like_prenom
		);

		$rows = $wpdb->get_results( $sql );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized_nom    = $this->normalize_name( $nom );
		$normalized_prenom = $this->normalize_name( $prenom );

		$ids = array();
		foreach ( $rows as $row ) {
			if ( $normalized_nom === $this->normalize_name( $row->nom_affiche ?? '' )
				&& $normalized_prenom === $this->normalize_name( $row->prenom ?? '' )
				&& $dob === (string) ( $row->date_naissance ?? '' ) ) {
				$ids[] = (int) $row->id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function find_existing_licence_ids_by_email( string $email ): array {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return array();
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$columns = $this->get_licence_columns();
		if ( ! in_array( 'email', $columns, true ) ) {
			return array();
		}

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE email = %s",
				$email
			)
		);

		$rows = is_array( $rows ) ? $rows : array();
		$ids  = array_map( 'absint', $rows );

		return array_values( array_filter( $ids ) );
	}

	private function sanitize_csv_value( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return $value;
		}

		if ( preg_match( '/^[=+\-@]/', $value ) ) {
			return "'" . $value;
		}

		return $value;
	}

	private function parse_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$parts = explode( '-', $value );
			$year  = $parts[0];
			$month = $parts[1];
			$day   = $parts[2];

			if ( ! checkdate( (int) $month, (int) $day, (int) $year ) ) {
				return '';
			}

			return $value;
		}

		$parts = explode( '/', $value );
		if ( 3 !== count( $parts ) ) {
			return '';
		}

		$day   = str_pad( $parts[0], 2, '0', STR_PAD_LEFT );
		$month = str_pad( $parts[1], 2, '0', STR_PAD_LEFT );
		$year  = $parts[2];

		if ( ! checkdate( (int) $month, (int) $day, (int) $year ) ) {
			return '';
		}

		return $year . '-' . $month . '-' . $day;
	}

	private function parse_source_created_at( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $value ) ) {
			$date = DateTime::createFromFormat( 'Y-m-d H:i:s', $value );
			return $this->validate_datetime( $date ) ? $date->format( 'Y-m-d H:i:s' ) : null;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$date = DateTime::createFromFormat( 'Y-m-d', $value );
			return $this->validate_datetime( $date ) ? $date->format( 'Y-m-d 00:00:00' ) : null;
		}

		if ( preg_match( '/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value ) ) {
			$date = DateTime::createFromFormat( 'd/m/Y', $value );
			return $this->validate_datetime( $date ) ? $date->format( 'Y-m-d 00:00:00' ) : null;
		}

		if ( preg_match( '/^\d{1,2}\/\d{1,2}\/\d{4}\s+\d{1,2}:\d{2}(:\d{2})?$/', $value ) ) {
			$format = ( 1 === substr_count( $value, ':' ) ) ? 'd/m/Y H:i' : 'd/m/Y H:i:s';
			$date   = DateTime::createFromFormat( $format, $value );
			return $this->validate_datetime( $date ) ? $date->format( 'Y-m-d H:i:s' ) : null;
		}

		return null;
	}

	private function validate_datetime( $date ) {
		if ( ! $date ) {
			return false;
		}

		$errors = DateTime::getLastErrors();
		if ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) {
			return false;
		}

		return true;
	}

	private function log_import_warning( $message, $context = array() ) {
		UFSC_LC_Logger::log( $message, $context );
	}

	private function find_club_by_name( $normalized ) {
		global $wpdb;

		$table = $this->get_clubs_table();

		$results = $wpdb->get_results( "SELECT id, nom FROM {$table}" );
		foreach ( $results as $club ) {
			if ( $this->normalize_name( $club->nom ) === $normalized ) {
				return $club;
			}
		}

		return null;
	}

	private function find_alias( $normalized ) {
		global $wpdb;

		$table = $this->get_aliases_table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT id, club_id FROM {$table} WHERE alias_normalized = %s", $normalized )
		);
	}

	private function find_document_by_source_number( $source_licence_number ) {
		global $wpdb;

		$source_licence_number = $this->normalize_license_number( $source_licence_number );
		if ( '' === $source_licence_number ) {
			return null;
		}

		$table = $this->get_documents_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, licence_id FROM {$table} WHERE source = %s AND REPLACE(source_licence_number, ' ', '') = %s",
				self::SOURCE,
				$source_licence_number
			)
		);
	}

	private function get_license_number_from_row( $row ) {
		$asptt_no = isset( $row['N° Licence'] ) ? sanitize_text_field( $row['N° Licence'] ) : '';
		return $this->normalize_license_number( $asptt_no );
	}

	private function get_existing_licences_map( $license_numbers ) {
		$license_numbers = array_values( array_filter( array_unique( array_map( array( $this, 'normalize_license_number' ), (array) $license_numbers ) ) ) );
		if ( empty( $license_numbers ) ) {
			return array();
		}

		global $wpdb;

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$license_column = $this->get_license_number_column();
		if ( '' === $license_column ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $license_numbers ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, {$license_column} as licence_number FROM {$table} WHERE REPLACE({$license_column}, ' ', '') IN ({$placeholders})",
				$license_numbers
			)
		);

		$map = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row->licence_number ) ) {
				$normalized = $this->normalize_license_number( $row->licence_number );
				if ( '' !== $normalized ) {
					$map[ $normalized ] = (int) $row->id;
				}
			}
		}

		return $map;
	}

	private function ensure_hash_table_exists() {
		global $wpdb;

		$table = $this->get_hashes_table();
		if ( $this->table_exists( $table ) ) {
			if ( $this->is_hash_table_available() ) {
				return true;
			}

			if ( ! is_admin() || ( class_exists( 'UFSC_LC_Capabilities' ) && ! UFSC_LC_Capabilities::user_can_import() ) ) {
				$this->hash_table_notice = __( 'Table de hash indisponible : stockage en option utilisé.', 'ufsc-licence-competition' );
				return false;
			}

			if ( ! $this->upgrade_hashes_table_schema( $table ) ) {
				$this->hash_table_notice = __( 'Table de hash indisponible : stockage en option utilisé.', 'ufsc-licence-competition' );
				return false;
			}

			if ( $this->is_hash_table_available() ) {
				return true;
			}

			$this->hash_table_notice = __( 'Table de hash indisponible : stockage en option utilisé.', 'ufsc-licence-competition' );
			return false;
		}

		if ( ! is_admin() ) {
			$this->hash_table_notice = __( 'Table de hash indisponible : stockage en option utilisé.', 'ufsc-licence-competition' );
			return false;
		}

		if ( class_exists( 'UFSC_LC_Capabilities' ) && ! UFSC_LC_Capabilities::user_can_import() ) {
			$this->hash_table_notice = __( 'Table de hash indisponible : stockage en option utilisé.', 'ufsc-licence-competition' );
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			club_id int NOT NULL DEFAULT 0,
			licence_number varchar(64) NOT NULL,
			row_hash char(64) NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (club_id, licence_number)
		) {$charset_collate};";

		dbDelta( $sql );

		if ( $this->is_hash_table_available() ) {
			return true;
		}

		$this->hash_table_notice = __( 'Table de hash indisponible : stockage en option utilisé.', 'ufsc-licence-competition' );
		return false;
	}

	private function is_hash_table_available() {
		$table = $this->get_hashes_table();
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		if ( ! $this->column_exists( $table, 'club_id' ) ) {
			return false;
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'row_hash' ) );
		if ( empty( $row->Type ) ) {
			return false;
		}

		if ( preg_match( '/char\((\d+)\)/i', $row->Type, $matches ) ) {
			return (int) $matches[1] >= 64;
		}

		return $this->has_hash_table_composite_key( $table );
	}

	private function get_existing_hashes( $license_numbers, $hash_table_available ) {
		$license_numbers = array_values( array_filter( array_unique( array_map( array( $this, 'normalize_license_number' ), (array) $license_numbers ) ) ) );
		if ( empty( $license_numbers ) ) {
			return array();
		}

		if ( $hash_table_available && $this->is_hash_table_available() ) {
			global $wpdb;
			$table        = $this->get_hashes_table();
			$placeholders = implode( ',', array_fill( 0, count( $license_numbers ), '%s' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT club_id, licence_number, row_hash FROM {$table} WHERE REPLACE(licence_number, ' ', '') IN ({$placeholders})",
					$license_numbers
				)
			);

			$hashes = array();
			foreach ( $rows as $row ) {
				$normalized = $this->normalize_license_number( $row->licence_number );
				if ( '' !== $normalized ) {
					$club_id = isset( $row->club_id ) ? (int) $row->club_id : 0;
					$hashes[ $this->build_hash_option_key( $normalized, $club_id ) ] = (string) $row->row_hash;
				}
			}
			return $hashes;
		}

		return array();
	}

	private function store_hash( $license_number, $club_id, $hash, $hash_table_available ) {
		$license_number = $this->normalize_license_number( $license_number );
		$club_id        = (int) $club_id;
		$hash           = (string) $hash;

		if ( '' === $license_number || '' === $hash ) {
			return;
		}

		if ( $hash_table_available && $this->is_hash_table_available() ) {
			global $wpdb;
			$table = $this->get_hashes_table();

			$wpdb->replace(
				$table,
				array(
					'club_id'        => $club_id,
					'licence_number' => $license_number,
					'row_hash'       => $hash,
					'updated_at'     => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s' )
			);
			return;
		}

		$stored = get_option( self::HASHES_OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored[ $this->build_hash_option_key( $license_number, $club_id ) ] = $hash;
		update_option( self::HASHES_OPTION_KEY, $stored, false );
	}

	private function build_row_hash( $data ) {
		if ( empty( $data['asptt_number'] ) ) {
			return '';
		}

		$normalized = $this->normalize_row_for_hash( $data );
		$payload    = array(
			$normalized['asptt_number'],
			$normalized['nom'],
			$normalized['prenom'],
			$normalized['date_naissance'],
			$normalized['genre'],
			$normalized['email'],
			$normalized['ville'],
			$normalized['code_postal'],
			$normalized['telephone'],
			$normalized['activite'],
			$normalized['club'],
			$normalized['region'],
			$normalized['source_created_at'],
		);

		$normalized_payload = implode( '|', $payload );

		return hash( 'sha256', $normalized_payload );
	}

	private function normalize_row_for_hash( $data ) {
		$birthdate = $this->parse_date( isset( $data['date_naissance'] ) ? $data['date_naissance'] : '' );
		$source_created_at = isset( $data['source_created_at'] ) ? $data['source_created_at'] : '';
		if ( empty( $source_created_at ) && ! empty( $data['source_created_at_raw'] ) ) {
			$source_created_at = $this->parse_source_created_at( $data['source_created_at_raw'] );
		}

		$club_value = '';
		if ( ! empty( $data['club_id'] ) ) {
			$club_value = 'club_id:' . (int) $data['club_id'];
		} elseif ( ! empty( $data['note'] ) ) {
			$club_value = 'note:' . $this->normalize_hash_value( $data['note'] );
		}

		return array(
			'asptt_number'      => $this->normalize_hash_value( $this->normalize_license_number( $data['asptt_number'] ) ),
			'nom'               => $this->normalize_hash_value( isset( $data['nom'] ) ? $data['nom'] : '' ),
			'prenom'            => $this->normalize_hash_value( isset( $data['prenom'] ) ? $data['prenom'] : '' ),
			'date_naissance'    => $this->normalize_hash_value( $birthdate ),
			'genre'             => $this->normalize_hash_value( $this->normalize_genre( isset( $data['genre'] ) ? $data['genre'] : '' ) ),
			'email'             => $this->normalize_hash_value( isset( $data['email'] ) ? strtolower( $data['email'] ) : '' ),
			'adresse'           => $this->normalize_hash_value( isset( $data['adresse'] ) ? $data['adresse'] : '' ),
			'ville'             => $this->normalize_hash_value( isset( $data['ville'] ) ? $data['ville'] : '' ),
			'code_postal'       => $this->normalize_hash_value( isset( $data['code_postal'] ) ? $data['code_postal'] : '' ),
			'telephone'         => $this->normalize_hash_value( $this->normalize_phone( isset( $data['telephone'] ) ? $data['telephone'] : '' ) ),
			'activite'          => $this->normalize_hash_value( isset( $data['activite'] ) ? $data['activite'] : '' ),
			'club'              => $this->normalize_hash_value( $club_value ),
			'region'            => $this->normalize_hash_value( isset( $data['region'] ) ? $data['region'] : '' ),
			'source_created_at' => $this->normalize_hash_value( $source_created_at ),
		);
	}

	private function normalize_license_number( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = strtoupper( $value );
		$value = preg_replace( '/\s+/', '', $value );
		$value = preg_replace( '/[^A-Z0-9]+/', '', $value );

		return $value ? $value : '';
	}

	private function normalize_hash_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = remove_accents( $value );
		$value = strtolower( $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return trim( $value );
	}

	private function push_report_error( array &$report_errors, $error, $line_number = 0 ) {
		if ( count( $report_errors ) >= 50 ) {
			return;
		}

		$report_errors[] = array(
			'line'         => (int) $line_number,
			'asptt_number' => isset( $error['asptt_number'] ) ? (string) $error['asptt_number'] : '',
			'error'        => isset( $error['error'] ) ? (string) $error['error'] : '',
		);
	}

	private function finalize_report_errors( $report_errors ) {
		return array_values( array_slice( (array) $report_errors, 0, 50 ) );
	}

	private function finalize_report_clubs( $report_clubs ) {
		$entries = array();
		foreach ( (array) $report_clubs as $entry ) {
			if ( empty( $entry['label'] ) ) {
				continue;
			}
			$entries[] = array(
				'label' => (string) $entry['label'],
				'count' => isset( $entry['count'] ) ? (int) $entry['count'] : 0,
			);
		}

		usort(
			$entries,
			function( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		return array_slice( $entries, 0, 20 );
	}

	public function insert_import_log( $data ) {
		global $wpdb;

		$table = $this->get_import_logs_table();

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'       => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
				'file_name'     => isset( $data['file_name'] ) ? (string) $data['file_name'] : '',
				'mode'          => isset( $data['mode'] ) ? (string) $data['mode'] : 'import',
				'total_rows'    => isset( $data['total_rows'] ) ? (int) $data['total_rows'] : 0,
				'success_rows'  => isset( $data['success_rows'] ) ? (int) $data['success_rows'] : 0,
				'error_rows'    => isset( $data['error_rows'] ) ? (int) $data['error_rows'] : 0,
				'status'        => isset( $data['status'] ) ? (string) $data['status'] : 'completed',
				'error_message' => isset( $data['error_message'] ) ? (string) $data['error_message'] : null,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	public function get_import_logs( $limit = 10 ) {
		global $wpdb;

		$table = $this->get_import_logs_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, file_name, mode, total_rows, success_rows, error_rows, status, error_message, created_at
				FROM {$table}
				ORDER BY created_at DESC
				LIMIT %d",
				absint( $limit )
			)
		);
	}

	public function record_import_log_meta( $log_id, $meta ) {
		$log_id = (int) $log_id;
		if ( $log_id <= 0 ) {
			return;
		}

		$stored = get_option( self::IMPORT_LOG_META_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$stored[ $log_id ] = $meta;

		if ( count( $stored ) > 50 ) {
			$keys = array_keys( $stored );
			sort( $keys );
			$keys_to_remove = array_slice( $keys, 0, max( 0, count( $stored ) - 50 ) );
			foreach ( $keys_to_remove as $key ) {
				unset( $stored[ $key ] );
			}
		}

		update_option( self::IMPORT_LOG_META_KEY, $stored, false );
	}

	public function get_import_log_meta( $log_id ) {
		$stored = get_option( self::IMPORT_LOG_META_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return isset( $stored[ $log_id ] ) && is_array( $stored[ $log_id ] ) ? $stored[ $log_id ] : array();
	}

	private function find_club_suggestions( $normalized ) {
		global $wpdb;

		$table  = $this->get_clubs_table();
		$tokens = array_filter( explode( ' ', $normalized ) );

		if ( empty( $tokens ) ) {
			return array();
		}

		$like_clauses = array();
		$params       = array();

		foreach ( $tokens as $token ) {
			$like_clauses[] = 'nom LIKE %s';
			$params[]       = '%' . $wpdb->esc_like( $token ) . '%';
		}

		$sql = "SELECT id, nom FROM {$table} WHERE " . implode( ' AND ', $like_clauses );

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public function search_clubs( $term ) {
		global $wpdb;

		$table = $this->get_clubs_table();
		$like  = '%' . $wpdb->esc_like( $term ) . '%';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, nom FROM {$table} WHERE nom LIKE %s ORDER BY nom ASC LIMIT 20",
				$like
			)
		);
	}

	private function get_allowed_columns() {
		return array(
			'Nom',
			'Prenom',
			'Email',
			'Adresse',
			'Ville',
			'Code postal',
			'Téléphone',
			'Date de naissance',
			'Saison (année de fin)',
			'Activité',
			'N° Licence',
			'Date de création de la licence',
			'Note',
			'Région',
			'genre',
		);
	}

	private function get_licence_columns() {
		if ( null !== $this->licence_columns ) {
			return $this->licence_columns;
		}

		global $wpdb;

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			$this->licence_columns = array();
			return $this->licence_columns;
		}

		$this->licence_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! is_array( $this->licence_columns ) ) {
			$this->licence_columns = array();
		}

		return $this->licence_columns;
	}

	private function get_license_number_column() {
		if ( null !== $this->license_number_column ) {
			return $this->license_number_column;
		}

		$columns = $this->get_licence_columns();
		$candidates = array( 'numero_licence_asptt', 'numero_licence_delegataire', 'numero_licence', 'num_licence', 'licence_numero', 'licence_number' );

		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				$this->license_number_column = $candidate;
				return $this->license_number_column;
			}
		}

		$this->license_number_column = '';
		return $this->license_number_column;
	}

	private function has_asptt_number_column() {
		if ( null !== $this->has_asptt_column ) {
			return (bool) $this->has_asptt_column;
		}

		$columns = $this->get_licence_columns();
		$this->has_asptt_column = in_array( 'numero_licence_asptt', $columns, true );
		return (bool) $this->has_asptt_column;
	}

	private function find_licence_by_number( $license_number ) {
		global $wpdb;

		$license_number = $this->normalize_license_number( $license_number );
		if ( '' === $license_number ) {
			return null;
		}

		$license_column = $this->get_license_number_column();
		if ( '' === $license_column ) {
			return null;
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE REPLACE({$license_column}, ' ', '') = %s", $license_number )
		);
	}

	private function find_existing_licence_id_by_asptt_number( $license_number, $club_id ) {
		$license_number = $this->normalize_license_number( $license_number );
		$club_id        = (int) $club_id;

		if ( '' === $license_number ) {
			return 0;
		}

		if ( ! $this->has_asptt_number_column() ) {
			return $this->find_existing_licence_id( $license_number, $club_id, array() );
		}

		global $wpdb;

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$where  = 'REPLACE(numero_licence_asptt, \' \', \'\') = %s';
		$params = array( $license_number );
		if ( $club_id > 0 && $this->column_exists( $table, 'club_id' ) ) {
			$where   .= ' AND club_id = %d';
			$params[] = $club_id;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE {$where}",
				$params
			)
		);

		return $row ? (int) $row->id : 0;
	}

	private function upsert_licence_by_number( $license_number, array $data, $existing_id = 0 ) {
		global $wpdb;

		$license_number = $this->normalize_license_number( $license_number );
		if ( '' === $license_number ) {
			return new WP_Error( 'licence_number_missing', __( 'N° Licence ASPTT manquant.', 'ufsc-licence-competition' ) );
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return new WP_Error( 'licence_table_missing', __( 'Table licences indisponible.', 'ufsc-licence-competition' ) );
		}

		$license_column = $this->get_license_number_column();
		if ( '' === $license_column ) {
			return new WP_Error( 'licence_number_missing', __( 'Colonne numéro de licence indisponible.', 'ufsc-licence-competition' ) );
		}

		$columns = $this->get_licence_columns();
		$asptt_columns = array_values( array_intersect( array( 'numero_licence_asptt', 'asptt_number' ), $columns ) );
		if ( empty( $asptt_columns ) ) {
			return new WP_Error( 'licence_asptt_missing', __( 'Colonne ASPTT indisponible.', 'ufsc-licence-competition' ) );
		}

		$fields  = array();
		$formats = array();
		foreach ( $asptt_columns as $column ) {
			$fields[ $column ] = $license_number;
			$formats[] = '%s';
		}

		$existing = null;
		if ( $existing_id ) {
			$existing = (object) array( 'id' => (int) $existing_id );
		} else {
			return new WP_Error( 'licence_not_found', __( 'Licence introuvable : import en mode mise à jour uniquement.', 'ufsc-licence-competition' ) );
		}

		if ( $existing ) {
			$updated = $wpdb->update(
				$table,
				$fields,
				array( 'id' => (int) $existing->id ),
				$formats,
				array( '%d' )
			);

			if ( false === $updated && ! empty( $wpdb->last_error ) ) {
				return new WP_Error( 'licence_update_failed', __( 'Erreur lors de la mise à jour de la licence.', 'ufsc-licence-competition' ) );
			}

			return array(
				'id'      => (int) $existing->id,
				'created' => false,
			);
		}

		return new WP_Error( 'licence_not_found', __( 'Licence introuvable : import en mode mise à jour uniquement.', 'ufsc-licence-competition' ) );
	}

	private function normalize_phone( $value ) {
		$value = (string) $value;
		return preg_replace( '/\D+/', '', $value );
	}

	private function normalize_genre( $value ) {
		$value = remove_accents( (string) $value );
		$value = strtolower( trim( $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( in_array( $value, array( 'm', 'h', 'homme', 'masculin' ), true ) ) {
			return 'M';
		}

		if ( in_array( $value, array( 'f', 'femme', 'feminin', 'féminin' ), true ) ) {
			return 'F';
		}

		return '';
	}

	private function generate_import_batch_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return uniqid( 'ufsc_asptt_', true );
	}

	private function ensure_upload_protection( $directory ) {
		$index_file = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n" );
		}

		$htaccess_file = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, "Options -Indexes\n<IfModule mod_autoindex.c>\nIndexOptions -FancyIndexing\n</IfModule>\n" );
		}
	}

	private function rollback_inserted( $ids ) {
		if ( empty( $ids ) ) {
			return;
		}

		global $wpdb;

		$table        = $this->get_documents_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id IN ({$placeholders})",
				$ids
			)
		);
	}

	private function update_licence_season_data( $licence_id, $season_end_year, $category, $age_ref ) {
		global $wpdb;

		if ( ! $licence_id ) {
			return;
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		if (
			! $this->column_exists( $table, 'season_end_year' )
			|| ! $this->column_exists( $table, 'category' )
			|| ! $this->column_exists( $table, 'age_ref' )
		) {
			return;
		}

		$season_end_year = UFSC_LC_Categories::sanitize_season_end_year( $season_end_year );
		if ( null === $season_end_year ) {
			return;
		}

		$age_ref_sql = null === $age_ref ? 'NULL' : '%d';

		$params = array( $season_end_year, $category );
		if ( null !== $age_ref ) {
			$params[] = $age_ref;
		}
		$params[] = $licence_id;

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET season_end_year = %d, category = %s, age_ref = {$age_ref_sql} WHERE id = %d",
			$params
		);

		$wpdb->query( $sql );
	}

	private function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private function column_exists( $table, $column ) {
		global $wpdb;

		$column = sanitize_key( $column );

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column )
		);
	}

	private function get_clubs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_clubs';
	}

	private function get_aliases_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_asptt_aliases';
	}

	private function get_documents_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_licence_documents';
	}

	private function get_documents_meta_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_licence_documents_meta';
	}

	private function get_import_logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_asptt_import_logs';
	}

	private function get_hashes_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_asptt_import_hashes';
	}

	private function get_licences_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_licences';
	}

	private function strip_utf8_bom( $value ) {
		$value = (string) $value;
		if ( 0 === strpos( $value, "\xEF\xBB\xBF" ) ) {
			return substr( $value, 3 );
		}
		return $value;
	}

	private function get_row_value( $row, $key ) {
		if ( ! is_array( $row ) ) {
			return '';
		}

		return isset( $row[ $key ] ) ? $row[ $key ] : '';
	}

	private function build_hash_option_key( $license_number, $club_id ) {
		$license_number = $this->normalize_license_number( $license_number );
		$club_id        = (int) $club_id;

		if ( $club_id > 0 ) {
			return $license_number . '|' . $club_id;
		}

		return $license_number;
	}

	private function get_existing_option_hash( $license_number, $club_id, &$used_fallback = false ) {
		$stored = get_option( self::HASHES_OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return '';
		}

		$key = $this->build_hash_option_key( $license_number, $club_id );
		if ( isset( $stored[ $key ] ) ) {
			return (string) $stored[ $key ];
		}

		$normalized = $this->normalize_license_number( $license_number );
		if ( isset( $stored[ $normalized ] ) ) {
			$used_fallback = true;
			return (string) $stored[ $normalized ];
		}

		return '';
	}

	private function upgrade_hashes_table_schema( $table ) {
		global $wpdb;

		$has_club_id = $this->column_exists( $table, 'club_id' );
		if ( ! $has_club_id ) {
			$altered = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN club_id int NOT NULL DEFAULT 0" );
			if ( false === $altered ) {
				return false;
			}
		}

		$primary_columns = $this->get_primary_key_columns( $table );
		$expected        = array( 'club_id', 'licence_number' );
		if ( $primary_columns !== $expected ) {
			if ( empty( $primary_columns ) ) {
				$altered = $wpdb->query( "ALTER TABLE {$table} ADD PRIMARY KEY (club_id, licence_number)" );
			} else {
				$altered = $wpdb->query( "ALTER TABLE {$table} DROP PRIMARY KEY, ADD PRIMARY KEY (club_id, licence_number)" );
			}
			if ( false === $altered ) {
				return false;
			}
		}

		return true;
	}

	private function get_primary_key_columns( $table ) {
		global $wpdb;

		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'PRIMARY'" );
		if ( empty( $rows ) ) {
			return array();
		}

		$columns = array();
		foreach ( $rows as $row ) {
			$seq = isset( $row->Seq_in_index ) ? (int) $row->Seq_in_index : 0;
			if ( $seq > 0 && ! empty( $row->Column_name ) ) {
				$columns[ $seq ] = $row->Column_name;
			}
		}

		ksort( $columns );

		return array_values( $columns );
	}

	private function has_hash_table_composite_key( $table ) {
		return $this->get_primary_key_columns( $table ) === array( 'club_id', 'licence_number' );
	}

	private function find_existing_licence_id( $license_number, $club_id, $licence_map ) {
		$license_number = $this->normalize_license_number( $license_number );
		$club_id        = (int) $club_id;

		if ( '' === $license_number ) {
			return 0;
		}

		if ( $club_id > 0 ) {
			$licence_match = $this->find_licence_by_number_and_club( $license_number, $club_id );
			return $licence_match ? (int) $licence_match->id : 0;
		}

		if ( isset( $licence_map[ $license_number ] ) ) {
			return (int) $licence_map[ $license_number ];
		}

		$licence_match = $this->find_licence_by_number( $license_number );
		return $licence_match ? (int) $licence_match->id : 0;
	}

	private function find_licence_by_number_and_club( $license_number, $club_id ) {
		global $wpdb;

		$license_number = $this->normalize_license_number( $license_number );
		if ( '' === $license_number ) {
			return null;
		}

		$license_column = $this->get_license_number_column();
		if ( '' === $license_column ) {
			return null;
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		if ( ! $this->column_exists( $table, 'club_id' ) ) {
			return $this->find_licence_by_number( $license_number );
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE club_id = %d AND REPLACE({$license_column}, ' ', '') = %s",
				$club_id,
				$license_number
			)
		);
	}
}
