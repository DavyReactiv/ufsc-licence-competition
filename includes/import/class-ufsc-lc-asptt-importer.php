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
	const STATUS_MINIMAL_MISSING_FIELDS = 'minimal_missing_fields';
	const STATUS_MINIMAL_NOT_FOUND      = 'not_found_minimal_match';

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
	private $licence_lookup = array();
	private $licence_lookup_ready = false;
	private $club_lookup = array();
	private $club_lookup_ready = false;
	private $transactions_notice = '';
	private $table_engine_cache = array();
	private $minimal_mode = false;
	private $update_only_minimal = true;
	private $preview_licence_ids = array();

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


	public function set_preview_licence_ids( array $licence_ids ) {
		$this->preview_licence_ids = array();

		foreach ( $licence_ids as $line_number => $licence_id ) {
			$line_number = absint( $line_number );
			$licence_id  = absint( $licence_id );
			if ( $line_number > 0 && $licence_id > 0 ) {
				$this->preview_licence_ids[ $line_number ] = $licence_id;
			}
		}
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

	public function build_preview( $file_path, $force_club_id, $mapping = array(), $limit = 0, $season_end_year_override = null, $minimal_mode = false, $update_only_minimal = true ) {
		$this->set_minimal_mode( (bool) $minimal_mode, (bool) $update_only_minimal );
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

		$preview_line_number = 0;
		foreach ( $parsed['rows'] as $row ) {
			$stats['total']++;
			$preview_line_number++;
			$result = $this->process_row( $row, $force_club_id, $stats, $season_end_year_override );

			if ( $result['preview'] ) {
				$result['preview']['line_number'] = $preview_line_number;
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
	public function import_from_file( $file_path, $force_club_id, $mapping = array(), $auto_approve = true, $season_end_year_override = null, $auto_save_alias = true, $incremental = true, $dry_run = false, $minimal_mode = false, $update_only_minimal = true ) {
		global $wpdb;

		$this->preview_licence_ids = array_map( 'absint', $this->preview_licence_ids );

		$this->set_minimal_mode( (bool) $minimal_mode, (bool) $update_only_minimal );

		if ( $this->minimal_mode ) {
			return $this->import_from_file_minimal( $file_path, $mapping, $dry_run );
		}

		$started_at = microtime( true );
		$import_batch_id = $dry_run ? '' : $this->generate_import_batch_id();
		$batch_ids       = array();

		$inserted          = array();
		$created_documents = array();
		$created_meta      = array();
		$created_licences  = array();
		$updated_licences  = array();

		$stats = array(
			'total'                => 0,
			'ok'                   => 0,
			'errors'               => 0,
			'duplicates'           => 0,
			'skipped_not_found'    => 0,
			'skipped_ambiguous'    => 0,
			'clubs_processed'      => 0,
			'clubs_skipped'        => 0,
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
			'ignored_rows'         => 0,
			'club_resolved'        => 0,
			'club_unresolved'      => 0,
		);

		$has_meta_table       = $this->table_exists( $this->get_documents_meta_table() );
		$hash_table_available = $dry_run ? $this->is_hash_table_available() : $this->ensure_hash_table_exists();
		$incremental      = (bool) $incremental;
		$dry_run          = (bool) $dry_run;
		$auto_save_alias  = $dry_run ? false : (bool) $auto_save_alias;
		$report_errors    = array();
		$report_line_logs = array();
		$report_club_logs = array();
		$report_clubs     = array();
		$delta_rows       = array();
		$this->row_index  = 0;
		$this->composite_cache = array();
		$this->licence_lookup = array();
		$this->licence_lookup_ready = false;

		$transactions_state   = $dry_run ? array( 'available' => false, 'mode' => 'dry_run', 'reasons' => array() ) : $this->detect_transactions_support();
		$transactions_enabled = ! $dry_run && ! empty( $transactions_state['available'] );

		if ( ! $dry_run && ! $transactions_enabled ) {
			$this->transactions_notice = __( 'Transactions SQL indisponibles : bascule en mode fallback batch_id.', 'ufsc-licence-competition' );
			$this->log_import_warning(
				$this->transactions_notice,
				array(
					'reasons' => isset( $transactions_state['reasons'] ) ? $transactions_state['reasons'] : array(),
				)
			);
		}

		$parsed = $this->read_csv( $file_path, $mapping, 0 );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$headers = $parsed['headers'];
		$rows    = $parsed['rows'];

		$required_check = $this->validate_required_columns( $headers, $force_club_id );
		if ( is_wp_error( $required_check ) ) {
			return $required_check;
		}

		$this->prime_club_lookup_cache();
		$club_ids = $this->collect_club_ids_from_rows( $rows, $force_club_id );
		$this->prime_licence_lookup_cache( $club_ids );
		$this->licence_lookup_ready = true;

		$license_numbers = array();
		foreach ( $rows as $row ) {
			$license_number = $this->get_license_number_from_row( $row );
			if ( '' !== $license_number ) {
				$license_numbers[] = $license_number;
			}
		}
		$license_numbers  = array_values( array_unique( $license_numbers ) );
		$existing_hashes  = $hash_table_available ? $this->get_existing_hashes( $license_numbers, true ) : array();
		$existing_docs    = $this->get_existing_documents_map( $license_numbers );

		$rows_by_club = array();
		$club_blocking = array();

		foreach ( $rows as $row ) {
			$stats['total']++;
			$this->row_index++;
			$line_number = $this->row_index;

			$row_stats = null;
			$result   = $this->process_row( $row, $force_club_id, $row_stats, $season_end_year_override, false );
			$data     = $result['data'];

			if ( isset( $this->preview_licence_ids[ $line_number ] ) ) {
				$data['licence_id'] = (int) $this->preview_licence_ids[ $line_number ];
				if ( self::STATUS_LINKED !== $data['status'] && empty( $result['error'] ) ) {
					$data['status'] = self::STATUS_LINKED;
					$data['person_resolution'] = 'preview';
				}
			}
			$row_error = $result['error'];
			$club_id  = isset( $data['club_id'] ) ? (int) $data['club_id'] : 0;
			$status   = isset( $data['status'] ) ? $data['status'] : '';
			$asptt_no = isset( $data['asptt_number'] ) ? $data['asptt_number'] : '';

			if ( self::STATUS_LINKED === $status ) {
				$stats['club_resolved']++;
				$stats['clubs_linked']++;
			} else {
				$stats['club_unresolved']++;
			}

			if ( self::STATUS_CLUB_NOT_FOUND === $status ) {
				$stats['club_not_found']++;
			}
			if ( self::STATUS_NEEDS_REVIEW === $status ) {
				$stats['needs_review']++;
			}
			if ( self::STATUS_LICENCE_MISSING === $status ) {
				$stats['licence_not_found']++;
				$stats['skipped_not_found']++;
			}
			if ( self::STATUS_INVALID_ASPTT_NUMBER === $status ) {
				$stats['invalid_asptt_number']++;
			}
			if ( self::STATUS_INVALID_SEASON === $status ) {
				$stats['invalid_season']++;
			}
			if ( self::STATUS_INVALID_BIRTHDATE === $status ) {
				$stats['invalid_birthdate']++;
			}
			if ( self::STATUS_LINKED === $status && ! empty( $data['licence_id'] ) ) {
				$stats['licences_linked']++;
			}

			$is_ambiguous_licence = self::STATUS_NEEDS_REVIEW === $status && ! empty( $data['licence_ambiguous_ids'] );

			if ( $is_ambiguous_licence ) {
				$stats['skipped_ambiguous']++;
				$stats['errors']++;
				$stats['invalid_rows']++;
				$stats['rejected_rows']++;
				$stats['ignored_rows']++;

				$this->push_line_log(
					$report_line_logs,
					$this->build_line_log_entry( $data, $line_number, __( 'Licence ambiguë : plusieurs correspondances trouvées.', 'ufsc-licence-competition' ), self::STATUS_NEEDS_REVIEW, 'ambiguous_licence' )
				);
				$this->push_report_error(
					$report_errors,
					array(
						'nom'          => $data['nom'],
						'prenom'       => $data['prenom'],
						'asptt_number' => $data['asptt_number'],
						'error'        => __( 'Licence ambiguë : plusieurs correspondances trouvées.', 'ufsc-licence-competition' ),
						'error_code'   => 'ambiguous_licence',
						'error_field'  => $this->map_error_field( 'ambiguous_licence' ),
						'error_message'=> __( 'Licence ambiguë : plusieurs correspondances trouvées.', 'ufsc-licence-competition' ),
						'club_resolution_status' => isset( $data['club_status'] ) ? (string) $data['club_status'] : '',
						'club_id_resolved' => (int) $club_id,
						'batch_id'     => isset( $data['batch_id'] ) ? (string) $data['batch_id'] : '',
					),
					$line_number
				);

				if ( $club_id ) {
					$club_blocking[ $club_id ] = __( 'Licence ambiguë.', 'ufsc-licence-competition' );
				}
				continue;
			}

			if ( ! empty( $row_error ) ) {
				$stats['errors']++;
				$stats['invalid_rows']++;
				$stats['rejected_rows']++;
				$stats['ignored_rows']++;

				$this->push_line_log(
					$report_line_logs,
					$this->build_line_log_entry( $data, $line_number, $row_error['error'] ?? '', $status )
				);
				$this->push_report_error( $report_errors, $row_error, $line_number );
				continue;
			}

			if ( '' === $asptt_no || self::STATUS_LINKED !== $status ) {
				$stats['errors']++;
				$stats['invalid_rows']++;
				$stats['rejected_rows']++;
				$stats['ignored_rows']++;

				$error_message = isset( $row_error['error'] ) ? $row_error['error'] : __( 'Ligne ignorée.', 'ufsc-licence-competition' );
				$this->push_line_log(
					$report_line_logs,
					$this->build_line_log_entry( $data, $line_number, $error_message, $status )
				);
				continue;
			}

			$stats['valid_rows']++;

			if ( '' !== $data['note'] ) {
				$note_key = $this->normalize_hash_value( $data['note'] );
				if ( '' !== $note_key ) {
					if ( ! isset( $report_clubs[ $note_key ] ) ) {
						$report_clubs[ $note_key ] = array(
							'label' => $data['note'],
							'count' => 1,
						);
					} else {
						$report_clubs[ $note_key ]['count']++;
					}
				}
			}

			$data['line_number'] = $line_number;

			if ( ! isset( $rows_by_club[ $club_id ] ) ) {
				$rows_by_club[ $club_id ] = array();
			}

			$rows_by_club[ $club_id ][] = $data;
		}

		$stats['clubs_processed'] = count( $rows_by_club );

		foreach ( $rows_by_club as $club_id => $club_rows ) {
			$club_batch_id = $dry_run ? '' : $this->generate_import_batch_id();
			if ( $club_batch_id ) {
				$batch_ids[] = $club_batch_id;
			}
			$club_log = array(
				'club_id' => (int) $club_id,
				'status'  => 'committed',
				'reason'  => '',
				'created' => 0,
				'updated' => 0,
				'ignored' => 0,
				'batch_id' => $club_batch_id ? $club_batch_id : null,
				'transaction_mode' => $transactions_enabled ? 'transaction' : 'fallback',
			);
			$club_inserted = array();
			$club_created_documents = array();
			$club_created_meta = array();
			$club_updated_licences = array();
			$club_updated_licences_previous = array();
			$club_updated_documents = array();
			$club_updated_meta = array();
			$club_hash_updates = array();
			$club_existing_docs_changes = array();
			$club_delta_rows = array();
			$club_ok = 0;
			$club_licences_updated = 0;
			$club_licences_skipped = 0;

			if ( isset( $club_blocking[ $club_id ] ) ) {
				$club_log['status'] = 'skipped';
				$club_log['reason'] = $club_blocking[ $club_id ];
				$club_log['ignored'] = count( $club_rows );
				$stats['clubs_skipped']++;
				$stats['ignored_rows'] += count( $club_rows );
				$report_club_logs[] = $club_log;
				continue;
			}

			$club_has_error = false;
			$club_error_reason = '';
			$use_transactions = $transactions_enabled;
			$fallback_mode = ! $dry_run && ! $use_transactions;

			if ( $use_transactions ) {
				if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
					$use_transactions = false;
					$fallback_mode = ! $dry_run;
					$club_log['transaction_mode'] = 'fallback';
					$this->log_import_warning(
						__( 'Impossible de démarrer la transaction SQL, bascule en mode fallback batch_id.', 'ufsc-licence-competition' ),
						array( 'club_id' => (int) $club_id )
					);
				}
			}

			foreach ( $club_rows as $data ) {
				if ( $club_has_error ) {
					break;
				}
				$line_number = isset( $data['line_number'] ) ? (int) $data['line_number'] : 0;
				$asptt_no    = $data['asptt_number'];
				$club_id     = (int) $data['club_id'];
				$licence_id  = (int) $data['licence_id'];
				$data['batch_id'] = $club_batch_id;

				$current_hash = $this->build_row_hash( $data );
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

				$licence_needs_sync = $licence_id ? $this->licence_requires_asptt_sync( $licence_id, $asptt_no, $data['source_created_at'] ) : false;

				if ( $incremental && ! $licence_needs_sync && $licence_id && $current_hash && $previous_hash && hash_equals( $previous_hash, $current_hash ) ) {
					if ( $used_fallback_hash && ! $dry_run ) {
						$club_hash_updates[] = array(
							'asptt_number' => $asptt_no,
							'club_id'      => $club_id,
							'hash'         => $previous_hash,
						);
					}

					if ( isset( $existing_docs[ $asptt_no ] ) ) {
						$club_licences_skipped++;
						$club_ok++;
						$club_log['ignored']++;
						continue;
					}
				}

				if ( isset( $existing_docs[ $asptt_no ] ) && (int) $existing_docs[ $asptt_no ] !== $licence_id ) {
					$stats['errors']++;
					$stats['duplicates']++;
					$stats['rejected_rows']++;
					$stats['invalid_rows']++;
					$stats['ignored_rows']++;
					$club_log['ignored']++;

					$this->log_import_warning(
						__( 'Doublon détecté sur la clé métier.', 'ufsc-licence-competition' ),
						array(
							'asptt_number' => $asptt_no,
							'licence_id'   => $licence_id,
							'existing_id'  => (int) $existing_docs[ $asptt_no ],
						)
					);

					$this->push_line_log(
						$report_line_logs,
						$this->build_line_log_entry( $data, $line_number, __( 'Doublon détecté sur la clé métier.', 'ufsc-licence-competition' ), 'duplicate', 'duplicate_business_key' )
					);
					$this->push_report_error(
						$report_errors,
						array(
							'nom'          => $data['nom'],
							'prenom'       => $data['prenom'],
							'asptt_number' => $asptt_no,
							'error'        => __( 'Doublon détecté sur la clé métier.', 'ufsc-licence-competition' ),
							'error_code'   => 'duplicate_business_key',
							'error_field'  => $this->map_error_field( 'duplicate_business_key' ),
							'error_message'=> __( 'Doublon détecté sur la clé métier.', 'ufsc-licence-competition' ),
							'club_resolution_status' => isset( $data['club_status'] ) ? (string) $data['club_status'] : '',
							'club_id_resolved' => (int) $club_id,
							'batch_id'     => $club_batch_id,
						),
						$line_number
					);
					continue;
				}

				$licence_payload = array(
					'club_id'         => (int) $data['club_id'],
					'nom'             => $data['nom'],
					'prenom'          => $data['prenom'],
					'date_naissance'  => $data['date_naissance'],
					'genre'           => $data['genre'],
					'email'           => $data['email'],
					'adresse'         => $data['adresse'],
					'ville'           => $data['ville'],
					'code_postal'     => $data['code_postal'],
					'telephone'       => $data['telephone'],
					'activite'        => $data['activite'],
					'region'          => $data['region'],
					'date_asptt'      => $data['source_created_at'],
					'import_batch_id' => $club_batch_id,
				);

				$licence_result = array(
					'id'      => $licence_id,
					'created' => false,
				);

				if ( ! $dry_run ) {
					$licence_result = $this->upsert_licence_by_number(
						$asptt_no,
						$licence_payload,
						$licence_id,
						$club_batch_id,
						$fallback_mode
					);

					if ( is_wp_error( $licence_result ) ) {
						$stats['errors']++;
						$stats['rejected_rows']++;
						$stats['invalid_rows']++;
						$stats['ignored_rows']++;
						$club_has_error = true;
						$club_error_reason = $licence_result->get_error_message();
						$this->log_import_warning(
							$licence_result->get_error_message(),
							array( 'asptt_number' => $asptt_no )
						);
						$this->push_line_log(
							$report_line_logs,
							$this->build_line_log_entry( $data, $line_number, $licence_result->get_error_message(), 'licence_update_failed', 'licence_update_failed' )
						);
						break;
					}
				}

				$club_licences_updated++;
				$club_log['updated']++;
				if ( ! $dry_run ) {
					$club_updated_licences[] = (int) $licence_result['id'];
					if ( $fallback_mode && ! empty( $licence_result['previous'] ) ) {
						$club_updated_licences_previous[ (int) $licence_result['id'] ] = (array) $licence_result['previous'];
					}
				}

				if ( ! empty( $data['club_from_note'] ) ) {
					$stats['clubs_from_note']++;
				}

				$data['licence_id'] = (int) $licence_result['id'];

				if ( ! $dry_run && apply_filters( 'ufsc_lc_asptt_update_season_data', false, $data ) ) {
					$this->update_licence_season_data(
						(int) $data['licence_id'],
						$data['season_end_year'],
						$data['category'],
						$data['age_ref']
					);
				}

				if ( ! $dry_run ) {
					$this->save_alias_for_row( $force_club_id, $data, $auto_save_alias );
				}

				$doc_result = array(
					'id'      => 0,
					'created' => false,
				);

				if ( ! $dry_run ) {
					$doc_result = $this->upsert_document(
						(int) $data['licence_id'],
						$asptt_no,
						$data['attachment_id'],
						$data['note'],
						$data['source_created_at'],
						$club_batch_id,
						$fallback_mode
					);

					if ( false === $doc_result || ! empty( $wpdb->last_error ) ) {
						$club_has_error = true;
						$club_error_reason = __( 'Erreur SQL lors de l’import ASPTT.', 'ufsc-licence-competition' );
						$this->log_import_warning(
							__( 'Erreur SQL lors de l’import ASPTT.', 'ufsc-licence-competition' ),
							array( 'error' => $wpdb->last_error )
						);
						$this->push_line_log(
							$report_line_logs,
							$this->build_line_log_entry( $data, $line_number, __( 'Erreur SQL lors de l’import ASPTT.', 'ufsc-licence-competition' ), 'db_error', 'db_error' )
						);
						$this->push_report_error(
							$report_errors,
							array(
								'nom'          => $data['nom'],
								'prenom'       => $data['prenom'],
								'asptt_number' => $asptt_no,
								'error'        => __( 'Erreur SQL lors de l’import ASPTT.', 'ufsc-licence-competition' ),
								'error_code'   => 'db_error',
								'error_field'  => $this->map_error_field( 'db_error' ),
								'error_message'=> __( 'Erreur SQL lors de l’import ASPTT.', 'ufsc-licence-competition' ),
								'club_resolution_status' => isset( $data['club_status'] ) ? (string) $data['club_status'] : '',
								'club_id_resolved' => (int) $club_id,
								'batch_id'     => $club_batch_id,
							),
							$line_number
						);
						break;
					}
					if ( $fallback_mode && ! empty( $doc_result['previous'] ) ) {
						$club_updated_documents[ (int) $doc_result['id'] ] = (array) $doc_result['previous'];
					}

					if ( $has_meta_table ) {
						$review_status = 'pending';
						$link_mode     = ! empty( $data['link_mode'] ) ? $data['link_mode'] : 'none';

						if ( $auto_approve && ! empty( $data['auto_linked'] ) ) {
							$review_status = 'approved';
						}

						$meta_result = $this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'confidence_score', (int) $data['confidence_score'], $club_batch_id, $fallback_mode );
						if ( $meta_result && $meta_result['created'] ) {
							$club_created_meta[] = (int) $meta_result['id'];
						}
						if ( $fallback_mode && ! empty( $meta_result['previous'] ) ) {
							$club_updated_meta[ (int) $meta_result['id'] ] = (array) $meta_result['previous'];
						}
						$meta_result = $this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'link_mode', $link_mode, $club_batch_id, $fallback_mode );
						if ( $meta_result && $meta_result['created'] ) {
							$club_created_meta[] = (int) $meta_result['id'];
						}
						if ( $fallback_mode && ! empty( $meta_result['previous'] ) ) {
							$club_updated_meta[ (int) $meta_result['id'] ] = (array) $meta_result['previous'];
						}
						$meta_result = $this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'review_status', $review_status, $club_batch_id, $fallback_mode );
						if ( $meta_result && $meta_result['created'] ) {
							$club_created_meta[] = (int) $meta_result['id'];
						}
						if ( $fallback_mode && ! empty( $meta_result['previous'] ) ) {
							$club_updated_meta[ (int) $meta_result['id'] ] = (array) $meta_result['previous'];
						}
						$meta_result = $this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'club_resolution', $data['club_resolution'], $club_batch_id, $fallback_mode );
						if ( $meta_result && $meta_result['created'] ) {
							$club_created_meta[] = (int) $meta_result['id'];
						}
						if ( $fallback_mode && ! empty( $meta_result['previous'] ) ) {
							$club_updated_meta[ (int) $meta_result['id'] ] = (array) $meta_result['previous'];
						}
						$meta_result = $this->upsert_document_meta( (int) $data['licence_id'], self::SOURCE, 'person_resolution', $data['person_resolution'], $club_batch_id, $fallback_mode );
						if ( $meta_result && $meta_result['created'] ) {
							$club_created_meta[] = (int) $meta_result['id'];
						}
						if ( $fallback_mode && ! empty( $meta_result['previous'] ) ) {
							$club_updated_meta[ (int) $meta_result['id'] ] = (array) $meta_result['previous'];
						}
					}
				}

				if ( ! $dry_run ) {
					$club_inserted[] = (int) $doc_result['id'];
					if ( $doc_result['created'] ) {
						$club_created_documents[] = (int) $doc_result['id'];
					}
				}
				if ( ! array_key_exists( $asptt_no, $club_existing_docs_changes ) ) {
					$club_existing_docs_changes[ $asptt_no ] = isset( $existing_docs[ $asptt_no ] ) ? (int) $existing_docs[ $asptt_no ] : null;
				}
				$existing_docs[ $asptt_no ] = (int) $data['licence_id'];

				$club_ok++;

				if ( $current_hash && ! $dry_run ) {
					$club_hash_updates[] = array(
						'asptt_number' => $asptt_no,
						'club_id'      => $club_id,
						'hash'         => $current_hash,
					);
				}

				$action = $licence_result['created'] ? 'created' : 'updated';
				$club_delta_rows[] = array(
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

			if ( $use_transactions ) {
				if ( $club_has_error ) {
					$wpdb->query( 'ROLLBACK' );
				} else {
					$wpdb->query( 'COMMIT' );
				}
			}

			if ( $club_has_error ) {
				if ( $fallback_mode ) {
					$this->cleanup_failed_club_batch(
						$club_batch_id,
						array(
							'documents'       => $club_created_documents,
							'meta'            => $club_created_meta,
							'licence_updates' => $club_updated_licences_previous,
							'document_updates'=> $club_updated_documents,
							'meta_updates'    => $club_updated_meta,
						)
					);
					foreach ( $club_existing_docs_changes as $asptt_key => $previous_value ) {
						if ( null === $previous_value ) {
							unset( $existing_docs[ $asptt_key ] );
						} else {
							$existing_docs[ $asptt_key ] = $previous_value;
						}
					}
				}
				$club_log['status'] = 'skipped';
				$club_log['reason'] = $club_error_reason;
				$club_log['ignored'] = count( $club_rows );
				$club_log['updated'] = 0;
				$stats['clubs_skipped']++;
				$stats['ignored_rows'] += count( $club_rows );
			} else {
				$stats['ok'] += $club_ok;
				$stats['licences_updated'] += $club_licences_updated;
				$stats['licences_skipped'] += $club_licences_skipped;
				$inserted = array_merge( $inserted, $club_inserted );
				$created_documents = array_merge( $created_documents, $club_created_documents );
				$created_meta = array_merge( $created_meta, $club_created_meta );
				$updated_licences = array_merge( $updated_licences, $club_updated_licences );
				$delta_rows = array_merge( $delta_rows, $club_delta_rows );
				if ( ! $dry_run ) {
					foreach ( $club_hash_updates as $hash_update ) {
						$this->store_hash(
							$hash_update['asptt_number'],
							$hash_update['club_id'],
							$hash_update['hash'],
							$hash_table_available
						);
					}
				}
			}

			$report_club_logs[] = $club_log;
		}

		$duration_sec = max( 0.001, microtime( true ) - $started_at );
		$rows_per_sec = ( $stats['total'] > 0 ) ? ( $stats['total'] / $duration_sec ) : 0;
		$hash_storage = $hash_table_available ? 'table' : 'option';
		$hash_notice  = $this->hash_table_notice;

		$this->preview_licence_ids = array();

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
			'batch_id'          => $import_batch_id,
			'batch_ids'         => array_values( array_unique( array_filter( $batch_ids ) ) ),
			'used_transactions' => (bool) $transactions_enabled,
			'stats'             => $stats,
			'duration_sec'      => $duration_sec,
			'rows_per_sec'      => $rows_per_sec,
			'hash_storage'      => $hash_storage,
			'hash_notice'       => $hash_notice,
			'transaction_notice' => $this->transactions_notice,
			'report'            => array(
				'errors' => $this->finalize_report_errors( $report_errors ),
				'line_logs' => $report_line_logs,
				'club_logs' => $report_club_logs,
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
				__( 'Code erreur', 'ufsc-licence-competition' ),
				__( 'Champ erreur', 'ufsc-licence-competition' ),
				__( 'Message erreur', 'ufsc-licence-competition' ),
				__( 'Statut club', 'ufsc-licence-competition' ),
				__( 'Batch ID', 'ufsc-licence-competition' ),
				__( 'Club ID résolu', 'ufsc-licence-competition' ),
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
					$this->sanitize_csv_value( isset( $error['error_code'] ) ? $error['error_code'] : '' ),
					$this->sanitize_csv_value( isset( $error['error_field'] ) ? $error['error_field'] : '' ),
					$this->sanitize_csv_value( isset( $error['error_message'] ) ? $error['error_message'] : '' ),
					$this->sanitize_csv_value( isset( $error['club_resolution_status'] ) ? $error['club_resolution_status'] : '' ),
					$this->sanitize_csv_value( isset( $error['batch_id'] ) ? $error['batch_id'] : '' ),
					$this->sanitize_csv_value( isset( $error['club_id_resolved'] ) ? $error['club_id_resolved'] : '' ),
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

	private function set_minimal_mode( bool $minimal_mode, bool $update_only_minimal ): void {
		$this->minimal_mode = $minimal_mode;
		$this->update_only_minimal = $update_only_minimal;
	}

	private function process_row( $row, $force_club_id, &$stats = null, $season_end_year_override = null, $track_licence_actions = true ) {
		if ( $this->minimal_mode ) {
			return $this->process_row_minimal( $row, $stats, $track_licence_actions );
		}
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
		$licence_ambiguous_ids = array();

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

		if ( ! $skip_resolution && empty( $this->get_asptt_target_columns() ) ) {
			$status          = self::STATUS_INVALID_ASPTT_NUMBER;
			$skip_resolution = true;

			if ( is_array( $stats ) ) {
				$stats['invalid_asptt_number']++;
			}

			$row_errors[] = __( 'Colonne cible ASPTT indisponible (numero_licence_asptt / asptt_number / numero_asptt / licence_asptt / no_asptt / n_asptt).', 'ufsc-licence-competition' );

			$this->log_import_warning(
				__( 'Colonne cible ASPTT indisponible.', 'ufsc-licence-competition' ),
				array(
					'nom'    => $nom,
					'prenom' => $prenom,
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
						$asptt_no,
						(int) $resolved['club_id'],
						$nom,
						$prenom,
						$dob,
						$genre,
						$email
					);
				$licence_id        = $match['id'];
				$person_resolution = $match['resolution'];
				$licence_ambiguous_ids = $match['ambiguous_ids'];

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
			'licence_ambiguous_ids'=> $licence_ambiguous_ids,
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
			'club_status'          => $resolved['status'],
		);

		$error = null;
		if ( ! empty( $row_errors ) ) {
			$error_code = $this->map_status_to_error_code( $status );
			$error_field = $this->map_error_field( $error_code );
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
				'error_code'           => $error_code,
				'error_field'          => $error_field,
				'error_message'        => implode( ' | ', $row_errors ),
				'club_resolution_status' => $resolved['status'],
				'club_id_resolved'     => (int) $resolved['club_id'],
			);
		}

		return array(
			'preview' => $preview,
			'error'   => $error,
			'data'    => $preview,
		);
	}

	private function process_row_minimal( $row, &$stats = null, $track_licence_actions = true ) {
		$nom_raw    = sanitize_text_field( $this->get_row_value( $row, 'Nom' ) );
		$prenom_raw = sanitize_text_field( $this->get_row_value( $row, 'Prenom' ) );
		$dob_raw    = sanitize_text_field( $this->get_row_value( $row, 'Date de naissance' ) );
		$genre_raw  = sanitize_text_field( $this->get_row_value( $row, 'genre' ) );

		$nom_normalized    = $this->normalize_identity_value( $nom_raw );
		$prenom_normalized = $this->normalize_identity_value( $prenom_raw );
		$genre_normalized  = $this->normalize_genre( $genre_raw );
		$dob               = $this->parse_date( $dob_raw );

		$row_errors = array();
		$status     = self::STATUS_LINKED;
		$licence_id = 0;
		$warning_code = '';

		if ( '' === $nom_normalized ) {
			$row_errors[] = __( 'Nom manquant.', 'ufsc-licence-competition' );
		}
		if ( '' === $prenom_normalized ) {
			$row_errors[] = __( 'Prénom manquant.', 'ufsc-licence-competition' );
		}
		if ( '' === $dob_raw ) {
			$row_errors[] = __( 'Date de naissance manquante.', 'ufsc-licence-competition' );
		}
		if ( '' === $genre_raw ) {
			$row_errors[] = __( 'Sexe/genre manquant.', 'ufsc-licence-competition' );
		}

		if ( '' !== $dob_raw && '' === $dob ) {
			$row_errors[] = __( 'Date de naissance invalide.', 'ufsc-licence-competition' );
			$status = self::STATUS_INVALID_BIRTHDATE;
		} elseif ( ! empty( $row_errors ) ) {
			$status = self::STATUS_MINIMAL_MISSING_FIELDS;
		}

		$person_resolution = 'none';
		$licence_action    = 'none';
		$licence_ambiguous_ids = array();

		if ( empty( $row_errors ) ) {
			$match = $this->find_existing_licence_id_by_minimal_identity(
				$nom_normalized,
				$prenom_normalized,
				$dob,
				$genre_normalized
			);

			$licence_id = (int) $match['id'];
			$person_resolution = $match['resolution'];

			if ( ! empty( $match['ambiguous_ids'] ) ) {
				$licence_ambiguous_ids = $match['ambiguous_ids'];
			}
			if ( ! empty( $match['warning_code'] ) ) {
				$warning_code = $match['warning_code'];
			}

			if ( $licence_id ) {
				$licence_action = 'update';
				if ( is_array( $stats ) ) {
					$stats['licences_linked']++;
					if ( $track_licence_actions ) {
						$stats['licences_updated']++;
					}
				}
			} elseif ( $this->update_only_minimal ) {
				$status = self::STATUS_MINIMAL_NOT_FOUND;
				$row_errors[] = __( 'Licence introuvable : import minimal en mode mise à jour uniquement.', 'ufsc-licence-competition' );
				if ( is_array( $stats ) ) {
					$stats['licence_not_found']++;
				}
			} else {
				$licence_action = 'create';
			}
		} elseif ( self::STATUS_INVALID_BIRTHDATE === $status && is_array( $stats ) ) {
			$stats['invalid_birthdate']++;
		} elseif ( self::STATUS_MINIMAL_MISSING_FIELDS === $status && is_array( $stats ) ) {
			$stats['invalid_rows']++;
		}

		if ( ! empty( $row_errors ) && is_array( $stats ) ) {
			$stats['errors']++;
			$stats['rejected_rows']++;
			$stats['ignored_rows']++;
		}

		$preview = array(
			'nom'                  => $nom_raw,
			'prenom'               => $prenom_raw,
			'date_naissance'       => $dob,
			'season_end_year'      => null,
			'category'             => '',
			'age_ref'              => null,
			'note'                 => '',
			'email'                => '',
			'adresse'              => '',
			'ville'                => '',
			'code_postal'          => '',
			'telephone'            => '',
			'activite'             => '',
			'region'               => '',
			'genre'                => $genre_normalized,
			'asptt_number'         => '',
			'source_created_at'    => null,
			'source_created_at_raw'=> '',
			'club_id'              => 0,
			'club_suggestions'     => array(),
			'status'               => $status,
			'licence_id_source'    => 0,
			'licence_id'           => $licence_id,
			'licence_ambiguous_ids'=> $licence_ambiguous_ids,
			'attachment_id'        => 0,
			'has_error'            => ! empty( $row_errors ),
			'confidence_score'     => 0,
			'link_mode'            => 'minimal',
			'review_status'        => 'pending',
			'auto_linked'          => false,
			'club_resolution'      => 'minimal',
			'person_resolution'    => $person_resolution,
			'club_from_note'       => false,
			'licence_action'       => $licence_action,
			'club_status'          => '',
			'warning_code'         => $warning_code,
		);

		$error = null;
		if ( ! empty( $row_errors ) ) {
			$error_code  = self::STATUS_MINIMAL_NOT_FOUND === $status ? 'not_found_minimal_match' : $this->map_status_to_error_code( $status );
			$error_field = $this->map_error_field( $error_code );
			$error = array(
				'nom'                  => $nom_raw,
				'prenom'               => $prenom_raw,
				'date_naissance'       => $dob,
				'note'                 => '',
				'asptt_number'         => '',
				'source_created_at'    => '',
				'source_created_at_raw'=> '',
				'status'               => $status,
				'error'                => implode( ' | ', $row_errors ),
				'error_code'           => $error_code,
				'error_field'          => $error_field,
				'error_message'        => implode( ' | ', $row_errors ),
				'club_resolution_status' => '',
				'club_id_resolved'     => 0,
			);
		}

		return array(
			'preview' => $preview,
			'error'   => $error,
			'data'    => $preview,
		);
	}

	private function normalize_identity_value( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = remove_accents( $value );

		if ( function_exists( 'mb_strtolower' ) ) {
			$value = mb_strtolower( $value, 'UTF-8' );
		} else {
			$value = strtolower( $value );
		}

		return trim( $value );
	}

	private function find_existing_licence_id_by_minimal_identity( string $nom, string $prenom, string $dob, string $genre ): array {
		global $wpdb;

		$nom    = $this->normalize_identity_value( $nom );
		$prenom = $this->normalize_identity_value( $prenom );
		$dob    = $this->parse_date( $dob );
		$genre  = $this->normalize_genre( $genre );

		if ( '' === $nom || '' === $prenom || '' === $dob || '' === $genre ) {
			return array(
				'id' => 0,
				'ambiguous_ids' => array(),
				'resolution' => 'none',
				'warning_code' => '',
			);
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return array(
				'id' => 0,
				'ambiguous_ids' => array(),
				'resolution' => 'none',
				'warning_code' => '',
			);
		}

		$columns = $this->get_licence_columns();

		$nom_column = '';
		if ( in_array( 'nom', $columns, true ) ) {
			$nom_column = 'nom';
		} elseif ( in_array( 'nom_licence', $columns, true ) ) {
			$nom_column = 'nom_licence';
		}
		if ( '' === $nom_column
			|| ! in_array( 'prenom', $columns, true )
			|| ! in_array( 'date_naissance', $columns, true ) ) {
			return array(
				'id' => 0,
				'ambiguous_ids' => array(),
				'resolution' => 'none',
				'warning_code' => '',
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, sexe, {$nom_column} as nom_value, prenom, date_naissance
				FROM {$table}
				WHERE date_naissance = %s
				ORDER BY id DESC",
				$dob
			)
		);

$nom_column = '';
if ( in_array( 'nom', $columns, true ) ) {
	$nom_column = 'nom';
} elseif ( in_array( 'nom_licence', $columns, true ) ) {
	$nom_column = 'nom_licence';
}

if (
	'' === $nom_column
	|| ! in_array( 'prenom', $columns, true )
	|| ! in_array( 'date_naissance', $columns, true )
	|| ! in_array( 'sexe', $columns, true )
) {
	return array(
		'id'            => 0,
		'ambiguous_ids' => array(),
		'resolution'    => 'none',
		'warning_code'  => '',
	);
}


$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, sexe, {$nom_column} as nom_value, prenom, date_naissance
		FROM {$table}
		WHERE date_naissance = %s
		ORDER BY id DESC",
		$dob
	)
);


		if ( empty( $rows ) ) {
			return array(
				'id' => 0,
				'ambiguous_ids' => array(),
				'resolution' => 'none',
				'warning_code' => '',
			);
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$row_nom = $this->normalize_identity_value( $row->nom_value ?? '' );
			$row_prenom = $this->normalize_identity_value( $row->prenom ?? '' );
			if ( '' === $row_nom || '' === $row_prenom ) {
				continue;
			}
			if ( $row_nom !== $nom || $row_prenom !== $prenom ) {
				continue;
			}

			$row_sexe = $this->normalize_genre( $row->sexe ?? '' );
			if ( '' !== $row_sexe && $row_sexe !== $genre ) {
				continue;
			}

			$ids[] = (int) $row->id;
		}

		$ids = array_values( array_unique( $ids ) );
		if ( empty( $ids ) ) {
			return array(
				'id' => 0,
				'ambiguous_ids' => array(),
				'resolution' => 'none',
				'warning_code' => '',
			);
		}

$ids = array();
foreach ( $rows as $row ) {
	$row_nom    = $this->normalize_identity_value( $row->nom_value ?? '' );
	$row_prenom = $this->normalize_identity_value( $row->prenom ?? '' );

	if ( '' === $row_nom || '' === $row_prenom ) {
		continue;
	}

	if ( $row_nom !== $nom || $row_prenom !== $prenom ) {
		continue;
	}

	$row_sexe = $this->normalize_genre( $row->sexe ?? '' );
	if ( '' !== $row_sexe && $row_sexe !== $genre ) {
		continue;
	}

	$ids[] = (int) $row->id;
}

$ids = array_values( array_unique( $ids ) );



		$warning_code = '';
		if ( count( $ids ) > 1 ) {
			$warning_code = 'multiple_matches';
			$this->log_import_warning(
				__( 'Licence ambiguë : plusieurs correspondances trouvées (mode minimal).', 'ufsc-licence-competition' ),
				array(
					'nom'            => $nom,
					'prenom'         => $prenom,
					'date_naissance' => $dob,
					'genre'          => $genre,
					'ids'            => $ids,
				)
			);
		}

		return array(
			'id' => (int) $ids[0],
			'ambiguous_ids' => array_slice( $ids, 1 ),
			'resolution' => 'identity_minimal',
			'warning_code' => $warning_code,
		);
	}

	private function import_from_file_minimal( $file_path, $mapping = array(), $dry_run = false ) {
		$started_at = microtime( true );
		$import_batch_id = $dry_run ? '' : $this->generate_import_batch_id();
		$this->row_index = 0;

		$stats = array(
			'total'                => 0,
			'ok'                   => 0,
			'errors'               => 0,
			'duplicates'           => 0,
			'skipped_not_found'    => 0,
			'skipped_ambiguous'    => 0,
			'clubs_processed'      => 0,
			'clubs_skipped'        => 0,
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
			'ignored_rows'         => 0,
			'club_resolved'        => 0,
			'club_unresolved'      => 0,
		);

		$parsed = $this->read_csv( $file_path, $mapping, 0 );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$headers = $parsed['headers'];
		$required_check = $this->validate_required_columns( $headers, 0 );
		if ( is_wp_error( $required_check ) ) {
			return $required_check;
		}

		$updated_licences = array();
		$created_licences = array();
		$report_errors    = array();
		$report_line_logs = array();

		$rows = $parsed['rows'];
		foreach ( $rows as $row ) {
			$stats['total']++;
			$this->row_index++;
			$line_number = $this->row_index;

			$row_stats = null;
			$result = $this->process_row_minimal( $row, $row_stats, true );
			$data = $result['data'];
			$row_error = $result['error'];

			if ( ! empty( $row_error ) ) {
				$stats['errors']++;
				$stats['invalid_rows']++;
				$stats['rejected_rows']++;
				$stats['ignored_rows']++;
				$this->push_line_log(
					$report_line_logs,
					$this->build_line_log_entry( $data, $line_number, $row_error['error'] ?? '', $data['status'] ?? self::STATUS_MINIMAL_MISSING_FIELDS )
				);
				$this->push_report_error( $report_errors, $row_error, $line_number );
				continue;
			}

			$licence_id = isset( $data['licence_id'] ) ? (int) $data['licence_id'] : 0;

			if ( ! $licence_id && $this->update_only_minimal ) {
				$stats['errors']++;
				$stats['invalid_rows']++;
				$stats['rejected_rows']++;
				$stats['ignored_rows']++;
				$this->push_line_log(
					$report_line_logs,
					$this->build_line_log_entry( $data, $line_number, __( 'Licence introuvable : import minimal en mode mise à jour uniquement.', 'ufsc-licence-competition' ), self::STATUS_MINIMAL_NOT_FOUND, 'not_found_minimal_match' )
				);
				$this->push_report_error(
					$report_errors,
					array_merge(
						$row_error ?? array(),
						array(
							'nom'          => $data['nom'],
							'prenom'       => $data['prenom'],
							'date_naissance' => $data['date_naissance'],
							'status'       => self::STATUS_MINIMAL_NOT_FOUND,
							'error'        => __( 'Licence introuvable : import minimal en mode mise à jour uniquement.', 'ufsc-licence-competition' ),
							'error_code'   => 'not_found_minimal_match',
							'error_field'  => $this->map_error_field( 'not_found_minimal_match' ),
							'error_message'=> __( 'Licence introuvable : import minimal en mode mise à jour uniquement.', 'ufsc-licence-competition' ),
						)
					),
					$line_number
				);
				continue;
			}

			if ( $dry_run ) {
				$stats['ok']++;
				$stats['valid_rows']++;
				continue;
			}

			if ( $licence_id ) {
				$update_result = $this->update_minimal_licence(
					$licence_id,
					array(
						'nom'            => $data['nom'],
						'prenom'         => $data['prenom'],
						'date_naissance' => $data['date_naissance'],
						'sexe'           => $data['genre'],
					)
				);

$payload = array(
	'nom'            => $data['nom'],
	'prenom'         => $data['prenom'],
	'date_naissance' => $data['date_naissance'],
	'sexe'           => $data['genre'],
);

// Ajout trace batch si présent (sans rendre l'import obligatoire)
if ( isset( $import_batch_id ) && '' !== (string) $import_batch_id ) {
	$payload['import_batch_id'] = (string) $import_batch_id;
}

$update_result = $this->update_minimal_licence(
	$licence_id,
	$payload
);


				if ( is_wp_error( $update_result ) ) {
					$stats['errors']++;
					$stats['invalid_rows']++;
					$stats['rejected_rows']++;
					$stats['ignored_rows']++;
					$this->push_line_log(
						$report_line_logs,
						$this->build_line_log_entry( $data, $line_number, $update_result->get_error_message(), 'licence_update_failed', 'licence_update_failed' )
					);
					$this->push_report_error(
						$report_errors,
						array(
							'nom'          => $data['nom'],
							'prenom'       => $data['prenom'],
							'date_naissance' => $data['date_naissance'],
							'status'       => 'licence_update_failed',
							'error'        => $update_result->get_error_message(),
							'error_code'   => 'licence_update_failed',
							'error_field'  => $this->map_error_field( 'licence_update_failed' ),
							'error_message'=> $update_result->get_error_message(),
						),
						$line_number
					);
					continue;
				}

				if ( ! empty( $update_result['updated'] ) ) {
					$stats['licences_updated']++;
				}
				$updated_licences[] = $licence_id;
			} elseif ( ! $this->update_only_minimal ) {
				$create_result = $this->insert_minimal_licence(
					array(
						'nom'            => $data['nom'],
						'prenom'         => $data['prenom'],
						'date_naissance' => $data['date_naissance'],
						'sexe'           => $data['genre'],

					)
				);

if ( isset( $data['import_batch_id'] ) && in_array( 'import_batch_id', $columns, true ) && '' !== (string) $data['import_batch_id'] ) {
	$fields['import_batch_id'] = (string) $data['import_batch_id'];
	$formats[] = '%s';
}


				if ( is_wp_error( $create_result ) ) {
					$stats['errors']++;
					$stats['invalid_rows']++;
					$stats['rejected_rows']++;
					$stats['ignored_rows']++;
					$this->push_line_log(
						$report_line_logs,
						$this->build_line_log_entry( $data, $line_number, $create_result->get_error_message(), 'licence_update_failed', 'licence_update_failed' )
					);
					$this->push_report_error(
						$report_errors,
						array(
							'nom'          => $data['nom'],
							'prenom'       => $data['prenom'],
							'date_naissance' => $data['date_naissance'],
							'status'       => 'licence_update_failed',
							'error'        => $create_result->get_error_message(),
							'error_code'   => 'licence_update_failed',
							'error_field'  => $this->map_error_field( 'licence_update_failed' ),
							'error_message'=> $create_result->get_error_message(),
						),
						$line_number
					);
					continue;
				}

				$stats['licences_created']++;
				$created_licences[] = (int) $create_result['id'];
			}

			$stats['ok']++;
			$stats['valid_rows']++;
		}

		$duration_sec = max( 0.001, microtime( true ) - $started_at );
		$rows_per_sec = ( $stats['total'] > 0 ) ? ( $stats['total'] / $duration_sec ) : 0;

		return array(
			'inserted'          => array(),
			'created_documents' => array(),
			'created_meta'      => array(),
			'created_licences'  => array_values( array_unique( $created_licences ) ),
			'updated_licences'  => array_values( array_unique( $updated_licences ) ),
			'batch_id'          => $import_batch_id,
			'batch_ids'         => $import_batch_id ? array( $import_batch_id ) : array(),
			'used_transactions' => false,
			'stats'             => $stats,
			'duration_sec'      => $duration_sec,
			'rows_per_sec'      => $rows_per_sec,
			'hash_storage'      => '',
			'hash_notice'       => '',
			'transaction_notice' => '',
			'report'            => array(
				'errors' => $this->finalize_report_errors( $report_errors ),
				'line_logs' => $report_line_logs,
				'club_logs' => array(),
				'clubs'  => array(),
			),
			'delta'             => array(),
		);
	}

	private function update_minimal_licence( int $licence_id, array $data ) {
		global $wpdb;

		if ( ! $licence_id ) {
			return new WP_Error( 'licence_update_failed', __( 'Licence introuvable.', 'ufsc-licence-competition' ) );
		}

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return new WP_Error( 'licence_update_failed', __( 'Table des licences introuvable.', 'ufsc-licence-competition' ) );
		}

		$columns = $this->get_licence_columns();
		if ( empty( $columns ) ) {
			return new WP_Error( 'licence_update_failed', __( 'Colonnes des licences indisponibles.', 'ufsc-licence-competition' ) );
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$licence_id
			),
			ARRAY_A
		);

		if ( empty( $existing ) ) {
			return new WP_Error( 'licence_update_failed', __( 'Licence introuvable.', 'ufsc-licence-competition' ) );
		}

		if ( isset( $existing['deleted_at'] ) && '' !== (string) $existing['deleted_at'] ) {
			return new WP_Error( 'licence_update_failed', __( 'Licence supprimée (soft delete).', 'ufsc-licence-competition' ) );
		}

		$fields  = array();
		$formats = array();

		$nom_value = isset( $data['nom'] ) ? sanitize_text_field( $data['nom'] ) : '';
		$prenom_value = isset( $data['prenom'] ) ? sanitize_text_field( $data['prenom'] ) : '';
		$dob_value = isset( $data['date_naissance'] ) ? $this->parse_date( $data['date_naissance'] ) : '';
		$sexe_value = isset( $data['sexe'] ) ? $this->normalize_genre( $data['sexe'] ) : '';

		if ( in_array( 'nom', $columns, true ) ) {
			$existing_nom = $this->normalize_identity_value( $existing['nom'] ?? '' );
			if ( '' === $existing_nom || $existing_nom !== $this->normalize_identity_value( $nom_value ) ) {
				$fields['nom'] = $nom_value;
				$formats[] = '%s';
			}
		}

		if ( in_array( 'prenom', $columns, true ) ) {
			$existing_prenom = $this->normalize_identity_value( $existing['prenom'] ?? '' );
			if ( '' === $existing_prenom || $existing_prenom !== $this->normalize_identity_value( $prenom_value ) ) {
				$fields['prenom'] = $prenom_value;
				$formats[] = '%s';
			}
		}

		if ( in_array( 'date_naissance', $columns, true ) && '' !== $dob_value ) {
			$existing_dob = (string) ( $existing['date_naissance'] ?? '' );
			if ( '' === $existing_dob || $existing_dob !== $dob_value ) {
				$fields['date_naissance'] = $dob_value;
				$formats[] = '%s';
			}
		}

		if ( in_array( 'sexe', $columns, true ) ) {
			$existing_sexe = $this->normalize_genre( $existing['sexe'] ?? '' );
			if ( '' === $existing_sexe || $existing_sexe !== $sexe_value ) {
				$fields['sexe'] = $sexe_value;
				$formats[] = '%s';
			}
		}


if ( isset( $data['import_batch_id'] ) && in_array( 'import_batch_id', $columns, true ) && '' !== (string) $data['import_batch_id'] ) {
	$fields['import_batch_id'] = (string) $data['import_batch_id'];
	$formats[] = '%s';
}

		if ( empty( $fields ) ) {
			return array(
				'id'      => $licence_id,
				'updated' => false,
			);
		}

		$updated = $wpdb->update(
			$table,
			$fields,
			array( 'id' => $licence_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $updated && ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'licence_update_failed', __( 'Erreur lors de la mise à jour de la licence.', 'ufsc-licence-competition' ) );
		}

		return array(
			'id'      => $licence_id,
			'updated' => (bool) $updated,
		);
	}

	private function insert_minimal_licence( array $data ) {
		global $wpdb;

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return new WP_Error( 'licence_create_failed', __( 'Table des licences introuvable.', 'ufsc-licence-competition' ) );
		}

		$columns = $this->get_licence_columns();
		if ( empty( $columns ) ) {
			return new WP_Error( 'licence_create_failed', __( 'Colonnes des licences indisponibles.', 'ufsc-licence-competition' ) );
		}

		$fields  = array();
		$formats = array();

		$nom_value = isset( $data['nom'] ) ? sanitize_text_field( $data['nom'] ) : '';
		$prenom_value = isset( $data['prenom'] ) ? sanitize_text_field( $data['prenom'] ) : '';
		$dob_value = isset( $data['date_naissance'] ) ? $this->parse_date( $data['date_naissance'] ) : '';
		$sexe_value = isset( $data['sexe'] ) ? $this->normalize_genre( $data['sexe'] ) : '';

		if ( in_array( 'nom', $columns, true ) ) {
			$fields['nom'] = $nom_value;
			$formats[] = '%s';
		}
		if ( in_array( 'prenom', $columns, true ) ) {
			$fields['prenom'] = $prenom_value;
			$formats[] = '%s';
		}
		if ( in_array( 'date_naissance', $columns, true ) && '' !== $dob_value ) {
			$fields['date_naissance'] = $dob_value;
			$formats[] = '%s';
		}
		if ( in_array( 'sexe', $columns, true ) ) {
			$fields['sexe'] = $sexe_value;
			$formats[] = '%s';
		}
if ( isset( $data['import_batch_id'] ) && in_array( 'import_batch_id', $columns, true ) && '' !== (string) $data['import_batch_id'] ) {
	$fields['import_batch_id'] = (string) $data['import_batch_id'];
	$formats[] = '%s';
}


		if ( empty( $fields ) ) {
			return new WP_Error( 'licence_create_failed', __( 'Champs insuffisants pour créer une licence.', 'ufsc-licence-competition' ) );
		}

		$inserted = $wpdb->insert( $table, $fields, $formats );
		if ( false === $inserted || empty( $wpdb->insert_id ) ) {
			return new WP_Error( 'licence_create_failed', __( 'Erreur lors de la création de la licence.', 'ufsc-licence-competition' ) );
		}

		return array(
			'id'      => (int) $wpdb->insert_id,
			'created' => true,
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
		$normalized_mapping = array();

		foreach ( $mapping as $source_header => $target_column ) {
			$normalized_mapping[ $this->normalize_header( $source_header ) ] = $target_column;
		}

		foreach ( $headers as $header ) {
			$header = trim( (string) $header );
			$key    = isset( $mapping[ $header ] ) ? $mapping[ $header ] : '';

			if ( '' === $key ) {
				$normalized_header = $this->normalize_header( $header );
				$key = isset( $normalized_mapping[ $normalized_header ] ) ? $normalized_mapping[ $normalized_header ] : '';
			}

			if ( '' === $key ) {
				$key = $this->map_header( $header );
			}

			$key = $this->normalize_target_column( $key );

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

		$normalized_mapping = array();
		foreach ( $mapping as $key => $value ) {
			$normalized_mapping[ $this->normalize_header( $key ) ] = $value;
		}

		return isset( $normalized_mapping[ $normalized ] ) ? $normalized_mapping[ $normalized ] : '';
	}

	private function normalize_header( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = $this->convert_to_utf8( $this->strip_utf8_bom( $value ) );
		$value = remove_accents( $value );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/u', '_', $value );
		$value = preg_replace( '/_+/', '_', $value );

		return trim( $value, '_' );
	}

	private function normalize_target_column( $column ) {
		$column = trim( (string) $column );

		if ( '' === $column ) {
			return '';
		}

		$normalized = $this->normalize_header( $column );
		$aliases = array(
			'numero_asptt'         => 'N° Licence',
			'numero_licence'       => 'N° Licence',
			'numero_licence_asptt' => 'N° Licence',
			'n_licence'            => 'N° Licence',
			'licence_asptt'        => 'N° Licence',
		);

		if ( isset( $aliases[ $normalized ] ) ) {
			return $aliases[ $normalized ];
		}

		return $column;
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

		if ( $this->club_lookup_ready ) {
			if ( isset( $this->club_lookup['by_name'][ $normalized ] ) ) {
				return array(
					'status'      => self::STATUS_LINKED,
					'club_id'     => (int) $this->club_lookup['by_name'][ $normalized ],
					'suggestions' => array(),
					'resolution'  => 'exact',
				);
			}

			if ( isset( $this->club_lookup['aliases'][ $normalized ] ) ) {
				return array(
					'status'      => self::STATUS_LINKED,
					'club_id'     => (int) $this->club_lookup['aliases'][ $normalized ],
					'suggestions' => array(),
					'resolution'  => 'alias',
				);
			}

			$suggestions = $this->find_club_suggestions_from_cache( $normalized );
		} else {
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
		}
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

	private function upsert_document( $licence_id, $source_licence_number, $attachment_id, $note, $source_created_at, $batch_id = '', $track_previous = false ) {
		global $wpdb;

		$table = $this->get_documents_table();
		$has_batch_column = $batch_id && $this->column_exists( $table, 'import_batch_id' );

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s",
				$licence_id,
				self::SOURCE
			)
		);

		$previous = array();
		if ( $existing && $track_previous ) {
			$select_fields = array( 'source_licence_number', 'attachment_id', 'asptt_club_note', 'source_created_at' );
			if ( $this->column_exists( $table, 'import_batch_id' ) ) {
				$select_fields[] = 'import_batch_id';
			}
			$previous = (array) $wpdb->get_row(
				$wpdb->prepare(
					'SELECT ' . implode( ', ', $select_fields ) . " FROM {$table} WHERE id = %d",
					(int) $existing
				),
				ARRAY_A
			);
		}

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
		if ( $has_batch_column ) {
			$data['import_batch_id'] = $batch_id;
			$formats[] = '%s';
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
				'previous'=> $previous,
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
			'previous'=> array(),
		);
	}

	private function upsert_document_meta( $licence_id, $source, $meta_key, $meta_value, $batch_id = '', $track_previous = false ) {
		global $wpdb;

		$table = $this->get_documents_meta_table();
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}
		$has_batch_column = $batch_id && $this->column_exists( $table, 'import_batch_id' );

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE licence_id = %d AND source = %s AND meta_key = %s",
				$licence_id,
				$source,
				$meta_key
			)
		);

		$previous = array();
		if ( $existing && $track_previous ) {
			$select_fields = array( 'meta_value' );
			if ( $this->column_exists( $table, 'import_batch_id' ) ) {
				$select_fields[] = 'import_batch_id';
			}
			$previous = (array) $wpdb->get_row(
				$wpdb->prepare(
					'SELECT ' . implode( ', ', $select_fields ) . " FROM {$table} WHERE id = %d",
					(int) $existing
				),
				ARRAY_A
			);
		}

		$data = array(
			'licence_id' => $licence_id,
			'source'     => $source,
			'meta_key'   => $meta_key,
			'meta_value' => maybe_serialize( $meta_value ),
			'updated_at' => current_time( 'mysql' ),
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s' );
		if ( $has_batch_column ) {
			$data['import_batch_id'] = $batch_id;
			$formats[] = '%s';
		}

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
				'previous'=> $previous,
			);
		}

		$inserted = false !== $wpdb->insert( $table, $data, $formats );
		return array(
			'id'      => $inserted ? (int) $wpdb->insert_id : 0,
			'created' => $inserted,
			'updated' => false,
			'previous'=> array(),
		);
	}

	private function detect_transactions_support() {
		global $wpdb;

		$reasons = array();
		$engines = array();
		$available = true;

		if ( ! $wpdb->has_cap( 'transactions' ) ) {
			$available = false;
			$reasons[] = 'wpdb_no_transactions';
		}

		$tables = $this->get_transaction_tables();
		foreach ( $tables as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				$available = false;
				$reasons[] = 'table_missing:' . $table;
				continue;
			}
			$engine = $this->get_table_engine( $table );
			$engines[ $table ] = $engine;
			if ( '' === $engine || 0 !== strcasecmp( 'InnoDB', $engine ) ) {
				$available = false;
				$reasons[] = 'table_engine:' . $table . ':' . ( $engine ? $engine : 'unknown' );
			}
		}

		if ( $available ) {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				$available = false;
				$reasons[] = 'start_transaction_failed';
			} elseif ( false === $wpdb->query( 'ROLLBACK' ) ) {
				$available = false;
				$reasons[] = 'rollback_failed';
			}
		}

		return array(
			'available' => $available,
			'reasons'   => $reasons,
			'engines'   => $engines,
		);
	}

	private function cleanup_failed_club_batch( $batch_id, array $payload ) {
		global $wpdb;

		if ( ! $batch_id ) {
			return;
		}

		$documents_table = $this->get_documents_table();
		$meta_table      = $this->get_documents_meta_table();
		$licences_table  = $this->get_licences_table();

		if ( ! empty( $payload['document_updates'] ) && $this->table_exists( $documents_table ) ) {
			foreach ( (array) $payload['document_updates'] as $doc_id => $previous ) {
				$data = array(
					'source_licence_number' => isset( $previous['source_licence_number'] ) ? $previous['source_licence_number'] : '',
					'attachment_id'         => isset( $previous['attachment_id'] ) ? $previous['attachment_id'] : null,
					'asptt_club_note'       => isset( $previous['asptt_club_note'] ) ? $previous['asptt_club_note'] : '',
					'source_created_at'     => isset( $previous['source_created_at'] ) ? $previous['source_created_at'] : null,
					'updated_at'            => current_time( 'mysql' ),
				);
				$formats = array( '%s', '%d', '%s', '%s', '%s' );
				if ( $this->column_exists( $documents_table, 'import_batch_id' ) ) {
					$data['import_batch_id'] = isset( $previous['import_batch_id'] ) ? $previous['import_batch_id'] : null;
					$formats[] = '%s';
				}
				$wpdb->update(
					$documents_table,
					$data,
					array( 'id' => (int) $doc_id ),
					$formats,
					array( '%d' )
				);
			}
		}

		if ( ! empty( $payload['meta_updates'] ) && $this->table_exists( $meta_table ) ) {
			foreach ( (array) $payload['meta_updates'] as $meta_id => $previous ) {
				$data = array(
					'meta_value' => isset( $previous['meta_value'] ) ? $previous['meta_value'] : null,
					'updated_at' => current_time( 'mysql' ),
				);
				$formats = array( '%s', '%s' );
				if ( $this->column_exists( $meta_table, 'import_batch_id' ) ) {
					$data['import_batch_id'] = isset( $previous['import_batch_id'] ) ? $previous['import_batch_id'] : null;
					$formats[] = '%s';
				}
				$wpdb->update(
					$meta_table,
					$data,
					array( 'id' => (int) $meta_id ),
					$formats,
					array( '%d' )
				);
			}
		}

		if ( ! empty( $payload['licence_updates'] ) && $this->table_exists( $licences_table ) ) {
			foreach ( (array) $payload['licence_updates'] as $licence_id => $previous ) {
				if ( empty( $previous ) || ! is_array( $previous ) ) {
					continue;
				}
				$data = array();
				$formats = array();
				foreach ( $previous as $column => $value ) {
					$data[ $column ] = $value;
					$formats[] = '%s';
				}
				if ( $data ) {
					$wpdb->update(
						$licences_table,
						$data,
						array( 'id' => (int) $licence_id ),
						$formats,
						array( '%d' )
					);
				}
			}
		}

		if ( $this->table_exists( $documents_table ) && $this->column_exists( $documents_table, 'import_batch_id' ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$documents_table} WHERE import_batch_id = %s",
					$batch_id
				)
			);
		} elseif ( ! empty( $payload['documents'] ) && $this->table_exists( $documents_table ) ) {
			$ids = array_map( 'absint', (array) $payload['documents'] );
			$ids = array_values( array_filter( $ids ) );
			if ( $ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$documents_table} WHERE id IN ({$placeholders})",
						$ids
					)
				);
			}
		}

		if ( $this->table_exists( $meta_table ) && $this->column_exists( $meta_table, 'import_batch_id' ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$meta_table} WHERE import_batch_id = %s",
					$batch_id
				)
			);
		} elseif ( ! empty( $payload['meta'] ) && $this->table_exists( $meta_table ) ) {
			$ids = array_map( 'absint', (array) $payload['meta'] );
			$ids = array_values( array_filter( $ids ) );
			if ( $ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$meta_table} WHERE id IN ({$placeholders})",
						$ids
					)
				);
			}
		}
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

	private function resolve_licence_match( int $licence_id, string $asptt_number, int $club_id, string $nom, string $prenom, string $dob, string $genre = '', string $email = '' ): array {
		if ( $licence_id ) {
			$licence_exists = $this->licence_lookup_ready
				? isset( $this->licence_lookup['by_id'][ $licence_id ] )
				: $this->licence_exists( $licence_id );

			if ( $licence_exists ) {
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

		$identity_ids = $this->licence_lookup_ready
			? $this->get_identity_ids_from_cache( $club_id, $nom, $prenom, $dob, $genre )
			: $this->find_existing_licence_ids_by_identity( $nom, $prenom, $dob, $club_id, $genre );

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

	private function find_existing_licence_ids_by_identity( string $nom, string $prenom, string $dob, int $club_id = 0, string $genre = '' ): array {
		global $wpdb;

		$nom    = trim( $nom );
		$prenom = trim( $prenom );
		$dob    = $this->parse_date( $dob );
		$genre  = $this->normalize_genre( $genre );

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
		$has_club_column = in_array( 'club_id', $columns, true );

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

		$sql = "SELECT id, {$last_name_expr} AS nom_affiche, prenom, date_naissance,";
		$sql .= in_array( 'sexe', $columns, true ) ? " sexe " : " '' AS sexe ";
		$sql .= "FROM {$table} WHERE date_naissance = %s";
		$params = array( $dob );

		if ( $club_id > 0 && $has_club_column ) {
			$sql .= ' AND club_id = %d';
			$params[] = $club_id;
		}

		$sql .= " AND {$last_name_expr} LIKE %s AND prenom LIKE %s";
		$params[] = $like_nom;
		$params[] = $like_prenom;

		$sql = $wpdb->prepare( $sql, $params );

		$rows = $wpdb->get_results( $sql );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized_nom    = $this->normalize_name( $nom );
		$normalized_prenom = $this->normalize_name( $prenom );

		$ids = array();
		foreach ( $rows as $row ) {
			if ( $normalized_nom !== $this->normalize_name( $row->nom_affiche ?? '' )
				|| $normalized_prenom !== $this->normalize_name( $row->prenom ?? '' )
				|| $dob !== (string) ( $row->date_naissance ?? '' ) ) {
				continue;
			}

			if ( '' !== $genre ) {
				$row_genre = $this->normalize_genre( (string) ( $row->sexe ?? '' ) );
				if ( '' !== $row_genre && $row_genre !== $genre ) {
					continue;
				}
			}

			$ids[] = (int) $row->id;
		}

		return array_values( array_unique( $ids ) );
	}

	private function find_existing_licence_ids_by_email( string $email, int $club_id = 0, string $dob = '' ): array {
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

		$sql    = "SELECT id FROM {$table} WHERE email = %s";
		$params = array( $email );

		if ( $club_id > 0 && in_array( 'club_id', $columns, true ) ) {
			$sql .= ' AND club_id = %d';
			$params[] = $club_id;
		}

		$dob = $this->parse_date( $dob );
		if ( '' !== $dob && in_array( 'date_naissance', $columns, true ) ) {
			$sql .= ' AND date_naissance = %s';
			$params[] = $dob;
		}

		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		$rows = is_array( $rows ) ? $rows : array();
		$ids  = array_map( 'absint', $rows );

		return array_values( array_filter( $ids ) );
	}

	private function get_email_ids_from_cache( int $club_id, string $email, string $dob ): array {
		$email = sanitize_email( $email );
		$dob   = $this->parse_date( $dob );

		if ( ! $this->licence_lookup_ready || '' === $email || '' === $dob || ! $club_id ) {
			return array();
		}

		$matches = array();
		foreach ( $this->licence_lookup['by_id'] as $id => $row ) {
			if ( (int) ( $row->club_id ?? 0 ) !== $club_id ) {
				continue;
			}
			if ( $email !== sanitize_email( (string) ( $row->email ?? '' ) ) ) {
				continue;
			}
			if ( $dob !== $this->parse_date( (string) ( $row->date_naissance ?? '' ) ) ) {
				continue;
			}
			$matches[] = (int) $id;
		}

		return array_values( array_unique( array_filter( $matches ) ) );
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

		$value = $this->convert_to_utf8( $value );
		$value = preg_replace( '/\s+/', '', $value );

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
			$year  = (int) $matches[1];
			$month = (int) $matches[2];
			$day   = (int) $matches[3];
			return checkdate( $month, $day, $year ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : '';
		}

		if ( preg_match( '/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $value, $matches ) ) {
			$day   = (int) $matches[1];
			$month = (int) $matches[2];
			$year  = (int) $matches[3];
			return checkdate( $month, $day, $year ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : '';
		}

		return '';
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

	private function debug_asptt_event( $event, array $context = array() ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		do_action(
			'ufsc_lc_asptt_debug',
			array(
				'component' => 'import_service',
				'event'     => (string) $event,
				'context'   => $context,
			)
		);
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
			'club_id'           => (string) (int) ( isset( $data['club_id'] ) ? $data['club_id'] : 0 ),
			'nom'               => $this->normalize_hash_value( isset( $data['nom'] ) ? $data['nom'] : '' ),
			'prenom'            => $this->normalize_hash_value( isset( $data['prenom'] ) ? $data['prenom'] : '' ),
			'date_naissance'    => $this->normalize_hash_value( $birthdate ),
			'date_asptt'        => $this->normalize_hash_value( $source_created_at ),
			'saison'            => $this->normalize_hash_value( isset( $data['season_end_year'] ) ? (string) $data['season_end_year'] : '' ),
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

	public function apply_asptt_data_to_licence( $licence_id, $asptt_number, $date_asptt = null ) {
		$licence_id    = absint( $licence_id );
		$asptt_number  = $this->normalize_license_number( $asptt_number );
		$date_asptt    = $this->parse_source_created_at( $date_asptt );

		if ( ! $licence_id ) {
			return new WP_Error( 'licence_missing', __( 'Licence introuvable.', 'ufsc-licence-competition' ) );
		}

		if ( '' === $asptt_number ) {
			return new WP_Error( 'asptt_missing', __( 'N° Licence ASPTT manquant.', 'ufsc-licence-competition' ) );
		}

		return $this->upsert_licence_by_number(
			$asptt_number,
			array(
				'date_asptt' => $date_asptt,
			),
			$licence_id,
			'',
			false
		);
	}

public function mark_review_approval_hash( $club_id, $asptt_number, $date_asptt = null ) {
	$club_id      = absint( $club_id );
	$asptt_number = $this->normalize_license_number( $asptt_number );

	if ( ! $club_id || '' === $asptt_number ) {
		return false;
	}

	// On tente d'utiliser la table hash si possible, sinon fallback options.
	$hash_table_available = $this->ensure_hash_table_exists();

	$row_hash = $this->build_row_hash(
		array(
			'club_id'          => $club_id,
			'asptt_number'     => $asptt_number,
			'source_created_at'=> $date_asptt,
		)
	);

	if ( '' === $row_hash ) {
		return false;
	}

	$this->store_hash( $asptt_number, $club_id, $row_hash, (bool) $hash_table_available );

	return true;
}

private function licence_requires_asptt_sync( $licence_id, $asptt_number, $date_asptt = null ) {
	global $wpdb;

	$licence_id   = absint( $licence_id );
	$asptt_number = $this->normalize_license_number( $asptt_number );

	if ( ! $licence_id || '' === $asptt_number ) {
		return false;
	}

	$table   = $this->get_licences_table();
	$columns = $this->get_licence_columns();

	if ( empty( $columns ) || ! $this->table_exists( $table ) ) {
		return false;
	}

	// (Le calcul des colonnes ASPTT + dates est géré dans le conflit 2 ci-dessous)


	$target_asptt_columns = method_exists( $this, 'get_asptt_target_columns' )
		? (array) $this->get_asptt_target_columns()
		: array( 'numero_licence_asptt', 'asptt_number', 'numero_asptt', 'licence_asptt', 'no_asptt', 'n_asptt' );

	$asptt_columns = array_values( array_intersect( $target_asptt_columns, $columns ) );

	// Fallback ultra-safe (ancienne implémentation)
	if ( empty( $asptt_columns ) ) {
		$asptt_columns = array_values( array_intersect( array( 'numero_licence_asptt', 'asptt_number' ), $columns ) );
	}

	$date_columns = array_values(
		array_intersect(
			array( 'date_asptt', 'asptt_date', 'date_licence_asptt', 'date_asptt_licence' ),
			$columns
		)
	);

	$select_fields = array_unique( array_merge( $asptt_columns, $date_columns ) );
	if ( empty( $select_fields ) ) {
		return false;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT " . implode( ', ', $select_fields ) . " FROM {$table} WHERE id = %d",
			$licence_id
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) || empty( $row ) ) {
		return false;
	}

	// Valeur ASPTT actuelle en base (première colonne non vide)
	$stored_number = '';
	foreach ( $asptt_columns as $col ) {
		if ( isset( $row[ $col ] ) && '' !== (string) $row[ $col ] ) {
			$stored_number = $this->normalize_license_number( (string) $row[ $col ] );
			break;
		}
	}

	// Date ASPTT actuelle en base (si présente)
	$stored_date = null;
	foreach ( $date_columns as $col ) {
		if ( isset( $row[ $col ] ) && '' !== (string) $row[ $col ] ) {
			$stored_date = $this->parse_source_created_at( (string) $row[ $col ] );
			if ( null !== $stored_date ) {
				break;
			}
		}
	}

	$incoming_date = ( null !== $date_asptt && '' !== (string) $date_asptt )
		? $this->parse_source_created_at( (string) $date_asptt )
		: null;

	// Règles de sync : on sync si vide, différent, ou date entrante plus récente.
	if ( '' === $stored_number ) {
		return true;
	}

	if ( $stored_number !== $asptt_number ) {
		$this->debug_asptt_event(
			'licence_requires_asptt_sync_number_diff',
			array(
				'licence_id'     => $licence_id,
				'stored_number'  => $stored_number,
				'incoming_number'=> $asptt_number,
			)
		);
		return true;
	}

	if ( $incoming_date && ( ! $stored_date || strtotime( $incoming_date ) > strtotime( $stored_date ) ) ) {
		return true;
	}

	return false;
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

		$error_code = isset( $error['error_code'] ) ? (string) $error['error_code'] : $this->map_status_to_error_code( isset( $error['status'] ) ? $error['status'] : '' );
		$error_field = isset( $error['error_field'] ) ? (string) $error['error_field'] : $this->map_error_field( $error_code );

		$report_errors[] = array(
			'line'         => (int) $line_number,
			'asptt_number' => isset( $error['asptt_number'] ) ? (string) $error['asptt_number'] : '',
			'error'        => isset( $error['error'] ) ? (string) $error['error'] : '',
			'error_code'   => $error_code,
			'error_field'  => $error_field,
			'error_message' => isset( $error['error_message'] ) ? (string) $error['error_message'] : ( isset( $error['error'] ) ? (string) $error['error'] : '' ),
			'status'       => isset( $error['status'] ) ? (string) $error['status'] : '',
			'club_resolution_status' => isset( $error['club_resolution_status'] ) ? (string) $error['club_resolution_status'] : '',
			'batch_id'     => isset( $error['batch_id'] ) ? (string) $error['batch_id'] : '',
			'club_id_resolved' => isset( $error['club_id_resolved'] ) ? (int) $error['club_id_resolved'] : 0,
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

	private function find_club_suggestions_from_cache( $normalized ) {
		$tokens = array_filter( explode( ' ', $normalized ) );
		if ( empty( $tokens ) || empty( $this->club_lookup['list'] ) ) {
			return array();
		}

		$suggestions = array();
		foreach ( $this->club_lookup['list'] as $club ) {
			$matches = true;
			foreach ( $tokens as $token ) {
				if ( false === strpos( $club['normalized'], $token ) ) {
					$matches = false;
					break;
				}
			}
			if ( $matches ) {
				$suggestions[] = (object) array(
					'id'  => $club['id'],
					'nom' => $club['name'],
				);
			}
		}

		return $suggestions;
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

	private function prime_club_lookup_cache() {
		if ( $this->club_lookup_ready ) {
			return;
		}

		global $wpdb;

		$clubs_table  = $this->get_clubs_table();
		$aliases_table = $this->get_aliases_table();

		$clubs = $this->table_exists( $clubs_table )
			? $wpdb->get_results( "SELECT id, nom FROM {$clubs_table}" )
			: array();
		$aliases = $this->table_exists( $aliases_table )
			? $wpdb->get_results( "SELECT club_id, alias_normalized FROM {$aliases_table}" )
			: array();

		$by_name = array();
		$list = array();
		foreach ( (array) $clubs as $club ) {
			$normalized = $this->normalize_name( $club->nom );
			if ( '' !== $normalized ) {
				$by_name[ $normalized ] = (int) $club->id;
				$list[] = array(
					'id'         => (int) $club->id,
					'name'       => $club->nom,
					'normalized' => $normalized,
				);
			}
		}

		$aliases_map = array();
		foreach ( (array) $aliases as $alias ) {
			if ( '' !== $alias->alias_normalized ) {
				$aliases_map[ $alias->alias_normalized ] = (int) $alias->club_id;
			}
		}

		$this->club_lookup = array(
			'by_name' => $by_name,
			'aliases' => $aliases_map,
			'list'    => $list,
		);
		$this->club_lookup_ready = true;
	}

	private function collect_club_ids_from_rows( array $rows, $force_club_id ) {
		$club_ids = array();

		foreach ( $rows as $row ) {
			$note = sanitize_text_field( $this->get_row_value( $row, 'Note' ) );
			$resolved = $this->resolve_club( $note, $force_club_id );
			if ( self::STATUS_LINKED === $resolved['status'] ) {
				$club_ids[] = (int) $resolved['club_id'];
			}
		}

		return array_values( array_unique( array_filter( $club_ids ) ) );
	}

	private function prime_licence_lookup_cache( array $club_ids ) {
		$club_ids = array_values( array_unique( array_filter( array_map( 'absint', $club_ids ) ) ) );
		if ( empty( $club_ids ) ) {
			$this->licence_lookup = array(
				'by_id' => array(),
				'by_number' => array(),
				'by_identity' => array(),
			);
			return;
		}

		global $wpdb;

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			$this->licence_lookup = array(
				'by_id' => array(),
				'by_number' => array(),
				'by_identity' => array(),
			);
			return;
		}

		$columns = $this->get_licence_columns();
		$asptt_columns = $this->get_asptt_target_columns();
		$asptt_column  = ! empty( $asptt_columns ) ? $asptt_columns[0] : '';

		$last_name_columns = array();
		if ( in_array( 'nom', $columns, true ) ) {
			$last_name_columns[] = 'nom';
		}
		if ( in_array( 'nom_licence', $columns, true ) ) {
			$last_name_columns[] = 'nom_licence';
		}

		$select_parts = array( 'id', 'club_id', 'prenom', 'date_naissance' );
		$select_parts[] = in_array( 'email', $columns, true ) ? 'email' : "'' AS email";
		$select_parts[] = in_array( 'sexe', $columns, true ) ? 'sexe' : "'' AS sexe";
		if ( $asptt_column ) {
			$select_parts[] = $asptt_column . ' AS asptt_number';
		} else {
			$select_parts[] = "'' AS asptt_number";
		}
		if ( ! empty( $last_name_columns ) ) {
			$select_parts[] = 'COALESCE(' . implode( ', ', $last_name_columns ) . ") AS nom_affiche";
		} else {
			$select_parts[] = "'' AS nom_affiche";
		}

		$placeholders = implode( ',', array_fill( 0, count( $club_ids ), '%d' ) );
		$sql = $wpdb->prepare(
			"SELECT " . implode( ', ', $select_parts ) . " FROM {$table} WHERE club_id IN ({$placeholders})",
			$club_ids
		);

		$rows = $wpdb->get_results( $sql );

		$by_id = array();
		$by_number = array();
		$by_identity = array();

		foreach ( (array) $rows as $row ) {
			$licence_id = (int) $row->id;
			$club_id = (int) $row->club_id;
			$by_id[ $licence_id ] = $row;

			$asptt_number = $this->normalize_license_number( $row->asptt_number ?? '' );
			if ( '' !== $asptt_number ) {
				if ( ! isset( $by_number[ $club_id ] ) ) {
					$by_number[ $club_id ] = array();
				}
				if ( ! isset( $by_number[ $club_id ][ $asptt_number ] ) ) {
					$by_number[ $club_id ][ $asptt_number ] = array();
				}
				$by_number[ $club_id ][ $asptt_number ][] = $licence_id;
			}

			$identity_key = $this->build_identity_key( $club_id, $row->nom_affiche ?? '', $row->prenom ?? '', $row->date_naissance ?? '' );
			if ( '' !== $identity_key ) {
				if ( ! isset( $by_identity[ $club_id ] ) ) {
					$by_identity[ $club_id ] = array();
				}
				if ( ! isset( $by_identity[ $club_id ][ $identity_key ] ) ) {
					$by_identity[ $club_id ][ $identity_key ] = array();
				}
				$by_identity[ $club_id ][ $identity_key ][] = $licence_id;
			}
		}

		$this->licence_lookup = array(
			'by_id' => $by_id,
			'by_number' => $by_number,
			'by_identity' => $by_identity,
		);
	}

	private function build_identity_key( $club_id, $nom, $prenom, $dob ) {
		$club_id = (int) $club_id;
		$nom_key = $this->normalize_name( $nom );
		$prenom_key = $this->normalize_name( $prenom );
		$dob_key = $this->parse_date( $dob );

		if ( ! $club_id || '' === $nom_key || '' === $prenom_key || '' === $dob_key ) {
			return '';
		}

		return implode( '|', array( $club_id, $nom_key, $prenom_key, $dob_key ) );
	}

	private function get_identity_ids_from_cache( $club_id, $nom, $prenom, $dob, $genre = '' ) {
		$key = $this->build_identity_key( $club_id, $nom, $prenom, $dob );
		if ( '' === $key ) {
			return array();
		}

		$club_id = (int) $club_id;
		if ( ! isset( $this->licence_lookup['by_identity'][ $club_id ][ $key ] ) ) {
			return array();
		}

		$ids   = array_values( array_unique( $this->licence_lookup['by_identity'][ $club_id ][ $key ] ) );
		$genre = $this->normalize_genre( $genre );
		if ( '' === $genre ) {
			return $ids;
		}

		$filtered = array();
		foreach ( $ids as $id ) {
			$row = $this->licence_lookup['by_id'][ $id ] ?? null;
			if ( ! $row ) {
				continue;
			}

			$row_genre = $this->normalize_genre( (string) ( $row->sexe ?? '' ) );
			if ( '' !== $row_genre && $row_genre !== $genre ) {
				continue;
			}
			$filtered[] = (int) $id;
		}

		return array_values( array_unique( $filtered ) );
	}

	private function validate_required_columns( array $headers, $force_club_id ) {
		if ( $this->minimal_mode ) {
			$required = array(
				'Nom',
				'Prenom',
				'Date de naissance',
				'genre',
			);
		} else {
			$required = array(
				'Nom',
				'Prenom',
				'Date de naissance',
				'N° Licence',
			);

			if ( ! $force_club_id ) {
				$required[] = 'Note';
			}
		}

		$missing = array();
		foreach ( $required as $column ) {
			if ( ! in_array( $column, $headers, true ) ) {
				$missing[] = $column;
			}
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'missing_columns',
				sprintf(
					/* translators: %s: missing columns */
					__( 'Colonnes manquantes: %s', 'ufsc-licence-competition' ),
					implode( ', ', $missing )
				)
			);
		}

		return true;
	}

	private function get_existing_documents_map( array $license_numbers ) {
		$license_numbers = array_values( array_filter( array_unique( array_map( array( $this, 'normalize_license_number' ), $license_numbers ) ) ) );
		if ( empty( $license_numbers ) ) {
			return array();
		}

		global $wpdb;

		$table = $this->get_documents_table();
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $license_numbers ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_licence_number, licence_id FROM {$table} WHERE source = %s AND REPLACE(source_licence_number, ' ', '') IN ({$placeholders})",
				array_merge( array( self::SOURCE ), $license_numbers )
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$key = $this->normalize_license_number( $row->source_licence_number ?? '' );
			if ( '' !== $key ) {
				$map[ $key ] = (int) $row->licence_id;
			}
		}

		return $map;
	}

	private function push_line_log( array &$report_line_logs, array $entry ) {
		$report_line_logs[] = $entry;
	}

	private function build_line_log_entry( array $data, int $line_number, string $message, string $status, string $error_code = '' ) {
		$club_id = isset( $data['club_id'] ) ? (int) $data['club_id'] : 0;
		$licence_id = isset( $data['licence_id'] ) ? (int) $data['licence_id'] : 0;
		$batch_id = isset( $data['batch_id'] ) ? (string) $data['batch_id'] : '';
		$error_code = '' !== $error_code ? $error_code : $this->map_status_to_error_code( $status );

		return array(
			'line_number' => $line_number,
			'club_id'     => $club_id ? $club_id : null,
			'licence_id'  => $licence_id ? $licence_id : null,
			'error_code'  => $error_code,
			'field'       => $this->map_error_field( $error_code ),
			'message'     => $message,
			'message_humain' => $message,
			'status'      => $status,
			'asptt_number'=> isset( $data['asptt_number'] ) ? (string) $data['asptt_number'] : '',
			'club_resolution_status' => isset( $data['club_status'] ) ? (string) $data['club_status'] : '',
			'batch_id'    => $batch_id ? $batch_id : null,
		);
	}

	private function map_status_to_error_code( $status ) {
		switch ( $status ) {
			case self::STATUS_CLUB_NOT_FOUND:
				return 'club_not_resolved';
			case self::STATUS_NEEDS_REVIEW:
				return 'needs_review';
			case self::STATUS_LICENCE_MISSING:
				return 'licence_not_found';
			case self::STATUS_INVALID_ASPTT_NUMBER:
				return 'invalid_asptt_number';
			case self::STATUS_INVALID_SEASON:
				return 'invalid_season';
			case self::STATUS_INVALID_BIRTHDATE:
				return 'invalid_birthdate';
			case self::STATUS_MINIMAL_MISSING_FIELDS:
				return 'missing_required_fields';
			case self::STATUS_MINIMAL_NOT_FOUND:
				return 'not_found_minimal_match';
			default:
				return 'invalid_row';
		}
	}

	private function map_error_field( $error_code ) {
		switch ( $error_code ) {
			case 'club_not_resolved':
			case 'needs_review':
				return 'note';
			case 'ambiguous_licence':
			case 'licence_not_found':
				return 'licence';
			case 'invalid_asptt_number':
				return 'asptt_number';
			case 'invalid_season':
				return 'season_end_year';
			case 'invalid_birthdate':
				return 'date_naissance';
			case 'missing_required_fields':
				return 'required_fields';
			case 'not_found_minimal_match':
				return 'licence';
			case 'duplicate_business_key':
				return 'asptt_number';
			case 'licence_update_failed':
				return 'licence';
			case 'db_error':
				return 'database';
			default:
				return '';
		}
	}

	private function get_allowed_columns() {
		if ( $this->minimal_mode ) {
			return array(
				'Nom',
				'Prenom',
				'Date de naissance',
				'genre',
			);
		}

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

		if ( class_exists( 'UFSC_LC_Schema_Cache' ) ) {
			$this->licence_columns = UFSC_LC_Schema_Cache::get_columns( $table );
		} else {
			$this->licence_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
			if ( ! is_array( $this->licence_columns ) ) {
				$this->licence_columns = array();
			}
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


	private function get_asptt_target_columns() {
		$columns = $this->get_licence_columns();
		if ( empty( $columns ) ) {
			return array();
		}

		$candidates = array(
			'numero_licence_asptt',
			'asptt_number',
			'numero_asptt',
			'licence_asptt',
			'no_asptt',
			'n_asptt',
		);

		return array_values( array_intersect( $candidates, $columns ) );
	}

	private function has_asptt_number_column() {
		if ( null !== $this->has_asptt_column ) {
			return (bool) $this->has_asptt_column;
		}

		$this->has_asptt_column = ! empty( $this->get_asptt_target_columns() );
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

		$asptt_columns = $this->get_asptt_target_columns();
		$asptt_column  = ! empty( $asptt_columns ) ? $asptt_columns[0] : '';
		if ( '' === $asptt_column ) {
			return 0;
		}

		$where  = 'REPLACE(' . $asptt_column . ', \' \', \'\') = %s';
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

	private function upsert_licence_by_number( $license_number, array $data, $existing_id = 0, $batch_id = '', $track_previous = false ) {
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
		$asptt_columns = array_values( array_intersect( $this->get_asptt_target_columns(), $columns ) );
		if ( empty( $asptt_columns ) ) {
			return new WP_Error( 'licence_asptt_missing', __( 'Colonne ASPTT indisponible.', 'ufsc-licence-competition' ) );
		}

		$fields  = array();
		$formats = array();
		$target_asptt_column = $asptt_columns[0];
		$fields[ $target_asptt_column ] = $license_number;
		$formats[] = '%s';

		$date_asptt = isset( $data['date_asptt'] ) ? $this->parse_source_created_at( $data['date_asptt'] ) : null;
		if ( null !== $date_asptt ) {
			$date_columns = array_values( array_intersect( array( 'date_asptt', 'asptt_date', 'date_licence_asptt', 'date_asptt_licence' ), $columns ) );
			foreach ( $date_columns as $date_column ) {
				$fields[ $date_column ] = $date_asptt;
				$formats[] = '%s';
			}
		}

		if ( $batch_id && $this->column_exists( $table, 'import_batch_id' ) ) {
			$fields['import_batch_id'] = $batch_id;
			$formats[] = '%s';
		}

		$existing = null;
		if ( $existing_id ) {
			$existing = (object) array( 'id' => (int) $existing_id );
		} else {
			return new WP_Error( 'licence_not_found', __( 'Licence introuvable : import en mode mise à jour uniquement.', 'ufsc-licence-competition' ) );
		}

		$previous = array();
		if ( $existing && $track_previous ) {
			$select_fields = $asptt_columns;
			if ( $this->column_exists( $table, 'import_batch_id' ) ) {
				$select_fields[] = 'import_batch_id';
			}
			$previous = (array) $wpdb->get_row(
				$wpdb->prepare(
					'SELECT ' . implode( ', ', $select_fields ) . " FROM {$table} WHERE id = %d",
					(int) $existing->id
				),
				ARRAY_A
			);
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
				'previous'=> $previous,
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
		if ( class_exists( 'UFSC_LC_Schema_Cache' ) ) {
			return UFSC_LC_Schema_Cache::table_exists( $table );
		}
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private function column_exists( $table, $column ) {
		global $wpdb;

		$column = sanitize_key( $column );

		if ( class_exists( 'UFSC_LC_Schema_Cache' ) ) {
			return UFSC_LC_Schema_Cache::column_exists( $table, $column );
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column )
		);
	}

	private function get_table_engine( $table ) {
		global $wpdb;

		if ( isset( $this->table_engine_cache[ $table ] ) ) {
			return $this->table_engine_cache[ $table ];
		}

		$engine = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
				$table
			)
		);
		$engine = $engine ? (string) $engine : '';
		$this->table_engine_cache[ $table ] = $engine;

		return $engine;
	}

	private function get_transaction_tables() {
		$tables = array(
			$this->get_licences_table(),
			$this->get_documents_table(),
			$this->get_documents_meta_table(),
		);
		if ( $this->is_hash_table_available() ) {
			$tables[] = $this->get_hashes_table();
		}

		return $tables;
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
