<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class UFSC_LC_FFST_Import_Service {
	const MATCHED='matched', AMBIGUOUS='ambiguous', NOT_FOUND='not_found', CONFLICT='conflict', APPLIED='applied';
	const MAX_UPLOAD_BYTES=10485760;

	public function create_tables(){
		global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
		$c=$wpdb->get_charset_collate(); $b=$this->batches_table(); $r=$this->rows_table();
		dbDelta("CREATE TABLE {$b} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			file_name varchar(255) NOT NULL,file_type varchar(16) NOT NULL,
			season_end_year smallint unsigned NOT NULL DEFAULT 0,status varchar(24) NOT NULL DEFAULT 'preview',
			total_rows int unsigned NOT NULL DEFAULT 0,matched_rows int unsigned NOT NULL DEFAULT 0,
			ambiguous_rows int unsigned NOT NULL DEFAULT 0,conflict_rows int unsigned NOT NULL DEFAULT 0,
			not_found_rows int unsigned NOT NULL DEFAULT 0,applied_rows int unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,applied_at datetime NULL,
			PRIMARY KEY (id),KEY idx_status (status),KEY idx_created (created_at)
		) {$c};");
		dbDelta("CREATE TABLE {$r} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,batch_id bigint(20) unsigned NOT NULL,row_index int unsigned NOT NULL,
			ffst_number varchar(64) NULL,last_name varchar(190) NULL,first_name varchar(190) NULL,birthdate date NULL,
			sex varchar(16) NULL,club_name varchar(255) NULL,address varchar(255) NULL,postal_code varchar(20) NULL,city varchar(190) NULL,
			discipline varchar(190) NULL,role_name varchar(190) NULL,federal_references varchar(255) NULL,issued_at date NULL,
			source_club_code varchar(190) NULL,source_holder_ref varchar(190) NULL,source_code varchar(64) NULL,
			match_status varchar(24) NOT NULL DEFAULT 'pending',match_licence_id bigint(20) unsigned NULL,match_message text NULL,
			previous_ffst_number varchar(64) NULL,raw_payload longtext NULL,applied_at datetime NULL,
			PRIMARY KEY (id),UNIQUE KEY uniq_batch_row (batch_id,row_index),KEY idx_batch_status (batch_id,match_status),
			KEY idx_match_licence (match_licence_id),KEY idx_ffst (ffst_number)
		) {$c};");
	}

	public function stage_upload(array $file,$season_end_year){
		$v=$this->validate_upload($file); if(is_wp_error($v)) return $v;
		$s=$this->store_upload($file); if(is_wp_error($s)) return $s;
		try{$records=$this->parse_file($s['path'],$s['ext']);}finally{if(is_file($s['path'])) @unlink($s['path']);}
		if(is_wp_error($records)) return $records;
		if(!$records) return new WP_Error('ffst_empty_import',__('Aucune licence exploitable détectée.','ufsc-licence-competition'));
		return $this->stage_records($records,$file['name'],$s['ext'],absint($season_end_year));
	}

	public function stage_records(array $records,$file_name,$file_type,$season_end_year){
		global $wpdb; $this->create_tables(); $now=current_time('mysql');
		$wpdb->insert($this->batches_table(),array('user_id'=>get_current_user_id(),'file_name'=>sanitize_file_name($file_name),
			'file_type'=>sanitize_key($file_type),'season_end_year'=>absint($season_end_year),'status'=>'preview','created_at'=>$now),
			array('%d','%s','%s','%d','%s','%s'));
		$batch_id=absint($wpdb->insert_id); if(!$batch_id) return new WP_Error('ffst_batch_create_failed',__('Création du lot impossible.','ufsc-licence-competition'));
		$stats=array(self::MATCHED=>0,self::AMBIGUOUS=>0,self::CONFLICT=>0,self::NOT_FOUND=>0); $i=0; $seen_ffst=array(); $seen_licence=array();
		foreach($records as $record){$i++; $d=$this->normalize_record((array)$record); $m=$this->resolve_match($d,$season_end_year);
			$ffst_key=strtoupper(trim((string)$d['ffst_number']));
			$target=absint($m['licence_id']);
			if(''===$ffst_key){
				$m=array('status'=>self::NOT_FOUND,'licence_id'=>0,'message'=>__('N° FFST manquant : rapprochement bloqué.','ufsc-licence-competition'),'previous_ffst'=>'');
			}elseif(isset($seen_ffst[$ffst_key])){
				$m=array('status'=>self::CONFLICT,'licence_id'=>0,'message'=>sprintf(__('Doublon FFST dans ce fichier : ligne %d.','ufsc-licence-competition'),$seen_ffst[$ffst_key]),'previous_ffst'=>'');
			}elseif($target && isset($seen_licence[$target])){
				$m=array('status'=>self::CONFLICT,'licence_id'=>0,'message'=>sprintf(__('Plusieurs N° FFST associés à la licence UFSC #%1$d (ligne %2$d).','ufsc-licence-competition'),$target,$seen_licence[$target]),'previous_ffst'=>'');
			}
			if(''!==$ffst_key&&!isset($seen_ffst[$ffst_key]))$seen_ffst[$ffst_key]=$i;
			if(self::MATCHED===$m['status']&&absint($m['licence_id']))$seen_licence[absint($m['licence_id'])]=$i;
			$stats[$m['status']]++;
			$inserted=$wpdb->insert($this->rows_table(),array(
				'batch_id'=>$batch_id,'row_index'=>$i,'ffst_number'=>$d['ffst_number'],'last_name'=>$d['last_name'],'first_name'=>$d['first_name'],
				'birthdate'=>$d['birthdate'],'sex'=>$d['sex'],'club_name'=>$d['club_name'],'address'=>$d['address'],'postal_code'=>$d['postal_code'],
				'city'=>$d['city'],'discipline'=>$d['discipline'],'role_name'=>$d['role_name'],'federal_references'=>$d['federal_references'],
				'issued_at'=>$d['issued_at'],'source_club_code'=>$d['source_club_code'],'source_holder_ref'=>$d['source_holder_ref'],
				'source_code'=>$d['source_code'],'match_status'=>$m['status'],'match_licence_id'=>absint($m['licence_id']),
				'match_message'=>$m['message'],'previous_ffst_number'=>$m['previous_ffst'],
				'raw_payload'=>wp_json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
			),array('%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s'));
			if(false===$inserted){
				return new WP_Error('ffst_stage_row_insert_failed',sprintf(__('Impossible d’enregistrer la ligne FFST #%d en prévisualisation. Aucune donnée métier n’a été modifiée.','ufsc-licence-competition'),$i));
			}
		}
		// Recompute counters from rows actually persisted in staging so the UI
		// can never announce 3 rows while only 2 are stored/displayable.
		$persisted=$wpdb->get_results($wpdb->prepare(
			"SELECT match_status,COUNT(*) qty FROM {$this->rows_table()} WHERE batch_id=%d GROUP BY match_status",
			$batch_id
		),ARRAY_A);
		$actual_total=0;
		$actual=array(self::MATCHED=>0,self::AMBIGUOUS=>0,self::CONFLICT=>0,self::NOT_FOUND=>0);
		foreach((array)$persisted as $group){
			$status=(string)$group['match_status'];$qty=absint($group['qty']);$actual_total+=$qty;
			if(isset($actual[$status]))$actual[$status]+=$qty;
		}
		if($actual_total!==$i){
			return new WP_Error('ffst_stage_integrity_mismatch',sprintf(__('Intégrité du lot FFST impossible : %1$d ligne(s) analysée(s), %2$d enregistrée(s). Aucune donnée métier n’a été modifiée.','ufsc-licence-competition'),$i,$actual_total));
		}
		$wpdb->update($this->batches_table(),array(
			'total_rows'=>$actual_total,
			'matched_rows'=>$actual[self::MATCHED],
			'ambiguous_rows'=>$actual[self::AMBIGUOUS],
			'conflict_rows'=>$actual[self::CONFLICT],
			'not_found_rows'=>$actual[self::NOT_FOUND]
		),array('id'=>$batch_id),array('%d','%d','%d','%d','%d'),array('%d'));
		return $batch_id;
	}


	/**
	 * Reassess ONLY previously not-found rows of an existing FFST import.
	 * This does not edit UFSC licences, issue numbers, or change already-applied rows.
	 */
	/**
	 * Compatibility entry: scan only one bounded chunk. The old integer
	 * return contract is retained for club mapping and existing callers.
	 */
	public function reanalyse_not_found($batch_id){
		$result=$this->reanalyse_not_found_chunk($batch_id,0);
		return is_wp_error($result)?$result:(int)$result['matched'];
	}

	/**
	 * Continue a large import through stable keyset pagination.
	 * The cursor is a last-seen row ID, not an offset: changing match_status
	 * while scanning cannot skip rows. Neither UFSC master licences nor
	 * rows already applied are touched.
	 *
	 * @return array|WP_Error matched, processed, next_cursor, has_more
	 */
	public function reanalyse_not_found_chunk($batch_id,$after_id=0){
		global $wpdb;
		$batch_id=absint($batch_id);
		$after_id=absint($after_id);
		$batch=$this->get_batch($batch_id);
		if(!$batch)return new WP_Error('ffst_batch_missing',__('Lot FFST introuvable.','ufsc-licence-competition'));
		if((int)$batch->total_rows!==$this->get_batch_row_count($batch_id)){
			return new WP_Error('ffst_batch_count_mismatch',__('Le lot présente une incohérence de données : réanalyse refusée sans modification.','ufsc-licence-competition'));
		}
		// 100 rows/request caps execution time and prevents request timeouts.
		$rows=$wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$this->rows_table()} WHERE batch_id=%d AND match_status=%s AND id>%d ORDER BY id ASC LIMIT 100",
			$batch_id,self::NOT_FOUND,$after_id
		));
		$matched=0;
		$last_id=$after_id;
		foreach((array)$rows as $row){
			$last_id=absint($row->id);
			$d=array(
				'ffst_number'=>(string)$row->ffst_number,'last_name'=>(string)$row->last_name,
				'first_name'=>(string)$row->first_name,'birthdate'=>(string)$row->birthdate,
				'club_name'=>(string)$row->club_name,
			);
			$match=$this->resolve_match($d,absint($batch->season_end_year));
			if(self::MATCHED!==$match['status']||!absint($match['licence_id']))continue;
			$conflicting_number=(int)$wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->rows_table()} WHERE batch_id=%d AND id<>%d AND ffst_number=%s",
				$batch_id,absint($row->id),$row->ffst_number
			));
			$conflicting_person=(int)$wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->rows_table()} WHERE batch_id=%d AND id<>%d AND match_licence_id=%d AND match_status IN (%s,%s)",
				$batch_id,absint($row->id),absint($match['licence_id']),self::MATCHED,self::APPLIED
			));
			if($conflicting_number||$conflicting_person)continue;
			$written=$wpdb->update($this->rows_table(),array(
				'match_status'=>self::MATCHED,
				'match_licence_id'=>absint($match['licence_id']),
				'match_message'=>__('Identité retrouvée lors de la réanalyse du lot : à confirmer avant application.','ufsc-licence-competition'),
				'previous_ffst_number'=>$match['previous_ffst'],
			),array('id'=>absint($row->id),'batch_id'=>$batch_id,'match_status'=>self::NOT_FOUND),
			array('%s','%d','%s','%s'),array('%d','%d','%s'));
			if(false===$written)return new WP_Error('ffst_reanalyse_failed',__('Erreur de réanalyse du lot : les licences UFSC n’ont pas été modifiées.','ufsc-licence-competition'));
			if(1===$written)$matched++;
		}
		$updated=$wpdb->update($this->batches_table(),array(
			'matched_rows'=>$this->get_batch_status_count($batch_id,self::MATCHED),
			'ambiguous_rows'=>$this->get_batch_status_count($batch_id,self::AMBIGUOUS),
			'conflict_rows'=>$this->get_batch_status_count($batch_id,self::CONFLICT),
			'not_found_rows'=>$this->get_batch_status_count($batch_id,self::NOT_FOUND)
		),array('id'=>$batch_id),array('%d','%d','%d','%d'),array('%d'));
		if(false===$updated)return new WP_Error('ffst_reanalyse_counts',__('Compteurs du lot non actualisés. Les licences UFSC n’ont pas été modifiées.','ufsc-licence-competition'));
		$has_more=0<(int)$wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->rows_table()} WHERE batch_id=%d AND match_status=%s AND id>%d",
			$batch_id,self::NOT_FOUND,$last_id
		));
		return array(
			'matched'=>$matched,'processed'=>count((array)$rows),
			'next_cursor'=>$last_id,'has_more'=>$has_more
		);
	}


    /**
     * Find eligible UFSC records in a specifically chosen club, for manual
     * resolution of a staged FFST row. This method never alters master data.
     */
    public function get_manual_candidates( $batch_id, $row_id, $club_id ) {
        global $wpdb;
        $batch = $this->get_batch( absint( $batch_id ) );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->rows_table()} WHERE batch_id=%d AND id=%d LIMIT 1",
            absint( $batch_id ), absint( $row_id )
        ) );
        if ( ! $batch || ! $row || ! in_array( $row->match_status, array( self::NOT_FOUND, self::AMBIGUOUS ), true ) ) {
            return new WP_Error( 'ffst_resolution_invalid', __( 'Cette ligne ne peut plus être rapprochée manuellement.', 'ufsc-licence-competition' ) );
        }
        if ( ! $club_id || ! $row->birthdate ) {
            return new WP_Error( 'ffst_resolution_data', __( 'Club et date de naissance indispensables.', 'ufsc-licence-competition' ) );
        }
        $table = $wpdb->prefix . 'ufsc_licences';
        $name = $this->column_exists( $table, 'nom' ) ? 'nom' : 'nom_licence';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE club_id=%d AND date_naissance=%s ORDER BY id DESC LIMIT 201",
            absint( $club_id ), $row->birthdate
        ) );
        if ( count( $rows ) > 200 ) {
            return new WP_Error( 'ffst_resolution_limit', __( 'Trop de candidatures : affinez le club.', 'ufsc-licence-competition' ) );
        }
        $result = array();
        $end = absint( $batch->season_end_year );
        foreach ( $rows as $candidate ) {
            $season_ok = false;
            foreach ( array( 'season_end_year', 'saison', 'season' ) as $field ) {
                if ( ! property_exists( $candidate, $field ) ) { continue; }
                $value = trim( (string) $candidate->{$field} );
                if ( in_array( $value, array( (string) $end, ( $end - 1 ) . '-' . $end, ( $end - 1 ) . '/' . $end ), true ) ) { $season_ok = true; break; }
            }
            if ( ! $season_ok ) { continue; }
            $candidate->last_name = (string) $candidate->{$name};
            $candidate->first_name = (string) $candidate->prenom;
            // The same canonical comparison is used by the import service.
            // Extra slug comparisons would reject some legitimate dash variants.
            $candidate->eligible = $this->norm( $candidate->last_name ) === $this->norm( $row->last_name )
                && $this->norm( $candidate->first_name ) === $this->norm( $row->first_name );
            $result[] = $candidate;
        }
        return $result;
    }

    /**
     * Approve only an unambiguous manual staging match. Applying the number
     * still requires the usual independent Apply button and all its guards.
     */
    public function manual_stage_match( $batch_id, $row_id, $licence_id, $club_id ) {
        global $wpdb;
        $batch_id = absint( $batch_id ); $row_id = absint( $row_id );
        $licence_id = absint( $licence_id ); $club_id = absint( $club_id );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->rows_table()} WHERE batch_id=%d AND id=%d LIMIT 1",
            $batch_id, $row_id
        ) );
        if ( ! $row || ! in_array( $row->match_status, array( self::NOT_FOUND, self::AMBIGUOUS ), true ) ) {
            return new WP_Error( 'ffst_manual_invalid', __( 'La ligne a changé, aucune modification.', 'ufsc-licence-competition' ) );
        }
        $candidates = $this->get_manual_candidates( $batch_id, $row_id, $club_id );
        if ( is_wp_error( $candidates ) ) { return $candidates; }
        $found = null;
        foreach ( $candidates as $candidate ) {
            if ( absint( $candidate->id ) === $licence_id && $candidate->eligible ) { $found = $candidate; break; }
        }
        if ( ! $found ) {
            return new WP_Error( 'ffst_manual_identity', __( 'Identité complète, naissance, club ou saison non concordants.', 'ufsc-licence-competition' ) );
        }
        $eligible_count = count( array_filter( $candidates, static function ( $candidate ) {
            return ! empty( $candidate->eligible );
        } ) );
        if ( 1 !== $eligible_count ) {
            return new WP_Error( 'ffst_manual_ambiguous', __( 'Plusieurs fiches UFSC ont la même identité et date de naissance dans ce club pour la saison. Vérifiez et corrigez le doublon avant de continuer.', 'ufsc-licence-competition' ) );
        }
        $prev = trim( (string) ( $found->numero_licence_ffst ?? '' ) );
        $incoming = trim( (string) $row->ffst_number );
        if ( ! $incoming || ( $prev && 0 !== strcasecmp( $prev, $incoming ) ) ) {
            return new WP_Error( 'ffst_manual_number', __( 'N° FFST absent ou incompatible avec la licence choisie.', 'ufsc-licence-competition' ) );
        }
        $licences_table = $wpdb->prefix . 'ufsc_licences';
        $taken = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$licences_table} WHERE UPPER(numero_licence_ffst)=%s AND id<>%d",
            strtoupper( $incoming ), $licence_id
        ) );
        $already = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->rows_table()} WHERE batch_id=%d AND id<>%d AND (UPPER(ffst_number)=%s OR (match_licence_id=%d AND match_status IN (%s,%s)))",
            $batch_id, $row_id, strtoupper( $incoming ), $licence_id, self::MATCHED, self::APPLIED
        ) );
        if ( $taken || $already ) {
            return new WP_Error( 'ffst_manual_collision', __( 'Doublon de numéro ou de destinataire : aucune modification.', 'ufsc-licence-competition' ) );
        }
        $result = $wpdb->update( $this->rows_table(), array(
            'match_status' => self::MATCHED,
            'match_licence_id' => $licence_id,
            'match_message' => __( 'Rapprochement manuel confirmé après contrôle identité, naissance, saison, club et numéro.', 'ufsc-licence-competition' ),
            'previous_ffst_number' => $prev,
        ), array( 'id' => $row_id, 'batch_id' => $batch_id, 'match_status' => $row->match_status ),
        array( '%s', '%d', '%s', '%s' ), array( '%d', '%d', '%s' ) );
        if ( 1 !== $result ) {
            return new WP_Error( 'ffst_manual_race', __( 'La ligne a été modifiée simultanément ou n’a pas pu être enregistrée.', 'ufsc-licence-competition' ) );
        }
        $this->update_batch_counters( $batch_id );
        return array( 'ffst_number' => $incoming, 'licence_id' => $licence_id );
    }

    public function update_batch_counters( $batch_id ) {
        global $wpdb;
        return $wpdb->update( $this->batches_table(), array(
            'matched_rows' => $this->get_batch_status_count( $batch_id, self::MATCHED ),
            'ambiguous_rows' => $this->get_batch_status_count( $batch_id, self::AMBIGUOUS ),
            'conflict_rows' => $this->get_batch_status_count( $batch_id, self::CONFLICT ),
            'not_found_rows' => $this->get_batch_status_count( $batch_id, self::NOT_FOUND ),
        ), array( 'id' => absint( $batch_id ) ), array( '%d', '%d', '%d', '%d' ), array( '%d' ) );
    }

	public function apply_batch($batch_id,array $selected_ids=array()){
		global $wpdb;
		$batch_id=absint($batch_id);
		$batch=$this->get_batch($batch_id);
		if(!$batch)return new WP_Error('ffst_batch_missing',__('Lot introuvable.','ufsc-licence-competition'));
		if('applied'===$batch->status && 0===$this->get_batch_status_count($batch_id,self::MATCHED))return new WP_Error('ffst_batch_applied',__('Ce lot ne contient plus de rapprochement à appliquer.','ufsc-licence-competition'));
		$selected_ids=array_values(array_unique(array_filter(array_map('absint',$selected_ids))));
		if(!$selected_ids)return new WP_Error('ffst_no_selection',__('Sélectionnez au moins une licence à rapprocher.','ufsc-licence-competition'));
		if(count($selected_ids)>500)return new WP_Error('ffst_selection_limit',__('Sélectionnez au maximum 500 licences par opération.','ufsc-licence-competition'));
		$lt=$wpdb->prefix.'ufsc_licences';
		if(!$this->column_exists($lt,'numero_licence_ffst'))return new WP_Error('ffst_column_missing',__('Le champ FFST canonique manque dans UFSC Gestion.','ufsc-licence-competition'));
		$placeholder=implode(',',array_fill(0,count($selected_ids),'%d'));
		$args=array_merge(array($batch_id),$selected_ids,array(self::MATCHED));
		$rows=$wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$this->rows_table()} WHERE batch_id=%d AND id IN ({$placeholder}) AND match_status=%s ORDER BY row_index ASC",$args
		));
		if(count($rows)!==count($selected_ids))return new WP_Error('ffst_selection_invalid',__('Sélection invalide : au moins une ligne est non rapprochée, bloquée ou déjà appliquée. Aucune modification.','ufsc-licence-competition'));

		// Revalidate identities, ownership, and all FFST numbers against live
		// UFSC Gestion data immediately before any write.
		$plan=array();$seen_ffst=array();$seen_licence=array();
		$names=$this->column_exists($lt,'nom')?'nom':'nom_licence';
		$season_columns=array();
		foreach(array('season_end_year','saison','season') as $season_field){
			if($this->column_exists($lt,$season_field))$season_columns[]=$season_field;
		}
		if(!$season_columns)return new WP_Error('ffst_season_column_missing',__('Impossible de vérifier la saison des licences : import arrêté sans modification.','ufsc-licence-competition'));
		$expected_end=absint($batch->season_end_year);
		if(!$expected_end)return new WP_Error('ffst_invalid_batch_season',__('Saison du lot FFST invalide.','ufsc-licence-competition'));

		foreach($rows as $row){
			$id=absint($row->match_licence_id);$incoming=strtoupper(trim((string)$row->ffst_number));
			if(!$id||''===$incoming||isset($seen_ffst[$incoming])||isset($seen_licence[$id])){
				return new WP_Error('ffst_selection_duplicate',__('Doublon dans la sélection : aucune modification.','ufsc-licence-competition'));
			}
			$seen_ffst[$incoming]=true;$seen_licence[$id]=true;
			$master=$wpdb->get_row($wpdb->prepare(
				"SELECT id,{$names} AS last_name,prenom AS first_name,date_naissance AS birthdate,numero_licence_ffst,".implode(',',array_map(static function($field){return "`{$field}`";},$season_columns))." FROM {$lt} WHERE id=%d LIMIT 1",$id
			));
			if(!$master||$this->norm($master->last_name)!==$this->norm($row->last_name)||$this->norm($master->first_name)!==$this->norm($row->first_name)||(string)$master->birthdate!==(string)$row->birthdate){
				return new WP_Error('ffst_identity_changed',sprintf(__('Identité non vérifiée pour la licence UFSC #%d : aucune modification.','ufsc-licence-competition'),$id));
			}
            // Re-check the exact same club/identity/season resolution on the
            // live database just before writing, including any saved club alias.
            $verified = $this->resolve_match(array(
                'ffst_number' => $incoming,
                'last_name' => (string) $row->last_name,
                'first_name' => (string) $row->first_name,
                'birthdate' => (string) $row->birthdate,
                'club_name' => (string) $row->club_name,
            ), $expected_end);
            if(self::MATCHED!==$verified['status'] || absint($verified['licence_id'])!==$id){
                return new WP_Error('ffst_recheck_mismatch',sprintf(__('Rapprochement devenu incertain pour la licence UFSC #%d : associez le bon club ou vérifiez le lot avant de réessayer. Aucune écriture.','ufsc-licence-competition'),$id));
            }
			$season_ok=false;
			foreach($season_columns as $season_field){
				$value=trim((string)($master->{$season_field}??''));
				if(('season_end_year'===$season_field && absint($value)===$expected_end) ||
					in_array($value,array((string)$expected_end,($expected_end-1).'-'.$expected_end,($expected_end-1).'/'.$expected_end),true)){
					$season_ok=true;break;
				}
			}
			if(!$season_ok)return new WP_Error('ffst_batch_season_mismatch',sprintf(__('La licence UFSC #%d ne correspond pas à la saison du lot FFST. Aucune modification.','ufsc-licence-competition'),$id));
			$current=trim((string)$master->numero_licence_ffst);
			if(''!==$current&&0!==strcasecmp($current,$incoming)){
				return new WP_Error('ffst_concurrent_conflict',sprintf(__('La licence UFSC #%d possède déjà un autre N° FFST.','ufsc-licence-competition'),$id));
			}
			$duplicates=(int)$wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$lt} WHERE UPPER(numero_licence_ffst)=%s AND id<>%d", $incoming,$id
			));
			if($duplicates){
				return new WP_Error('ffst_existing_duplicate',sprintf(__('Le N° FFST %s est déjà associé à une autre licence : validation bloquée.','ufsc-licence-competition'),$incoming));
			}
			$plan[]=array('row'=>$row,'licence_id'=>$id,'current'=>$current,'incoming'=>$incoming);
		}

		// Do not claim atomicity on non-transactional tables.
		$transaction=false!==$wpdb->query('START TRANSACTION');
		$changed=array();$now=current_time('mysql');
		foreach($plan as $item){
			if(''===$item['current']){
				$result=$wpdb->query($wpdb->prepare(
					"UPDATE {$lt} SET numero_licence_ffst=%s WHERE id=%d AND (numero_licence_ffst='' OR numero_licence_ffst IS NULL)",
					$item['incoming'],$item['licence_id']
				));
				if(1!==$result){
					if($transaction)$wpdb->query('ROLLBACK');
					else foreach(array_reverse($changed) as $done){
						$wpdb->query($wpdb->prepare("UPDATE {$lt} SET numero_licence_ffst=%s WHERE id=%d AND numero_licence_ffst=%s",$done['previous'],$done['id'],$done['incoming']));
					}
					return new WP_Error('ffst_write_failed',__('La licence a été modifiée entre le contrôle et la validation, ou l’écriture a échoué. Vérifiez les données avant de réessayer.','ufsc-licence-competition'));
				}
				$changed[]=array('id'=>$item['licence_id'],'previous'=>$item['current'],'incoming'=>$item['incoming']);
			}
		}
		if($transaction && false===$wpdb->query('COMMIT'))return new WP_Error('ffst_commit_failed',__('Validation SQL non confirmée : contrôlez les données avant toute nouvelle tentative.','ufsc-licence-competition'));
		foreach($plan as $item){
			$row=$item['row'];
			$this->write_pdf_meta($item['licence_id'],$row);
			$wpdb->update($this->rows_table(),array('match_status'=>self::APPLIED,'applied_at'=>$now),array('id'=>absint($row->id)),array('%s','%s'),array('%d'));
			do_action('ufsc_lc_ffst_import_synced',$item['licence_id'],'ffst-v2-'.$batch_id);
		}
		$applied=(int)$wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->rows_table()} WHERE batch_id=%d AND match_status=%s",$batch_id,self::APPLIED
		));
		$remaining=(int)$wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->rows_table()} WHERE batch_id=%d AND match_status=%s",$batch_id,self::MATCHED
		));
		$wpdb->update($this->batches_table(),array('status'=>$remaining?'partial':'applied','applied_rows'=>$applied,'applied_at'=>$now),array('id'=>$batch_id),array('%s','%d','%s'),array('%d'));
		if(function_exists('ufsc_lc_bump_cache_version'))ufsc_lc_bump_cache_version('status',0);
		return count($plan);
	}

	/**
	 * Find the next unresolved case in a specific batch, wrapping once.
	 * Only returns staging rows; no master licence mutation.
	 */
	public function get_next_unresolved_row($batch_id,$after_id=0){
		global $wpdb;
		$batch_id=absint($batch_id);
		$after_id=absint($after_id);
		$row=$wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$this->rows_table()} WHERE batch_id=%d AND match_status IN (%s,%s) AND id>%d ORDER BY id ASC LIMIT 1",
			$batch_id,self::NOT_FOUND,self::AMBIGUOUS,$after_id
		));
		if(!$row && $after_id){
			$row=$wpdb->get_row($wpdb->prepare(
				"SELECT * FROM {$this->rows_table()} WHERE batch_id=%d AND match_status IN (%s,%s) ORDER BY id ASC LIMIT 1",
				$batch_id,self::NOT_FOUND,self::AMBIGUOUS
			));
		}
		return $row;
	}

	public function get_batch($id){global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->batches_table()} WHERE id=%d LIMIT 1",absint($id)));}
	public function get_batch_rows($id,$limit=250,$offset=0){
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$this->rows_table()} WHERE batch_id=%d ORDER BY row_index ASC, id ASC LIMIT %d OFFSET %d",
			absint($id),max(1,min(500,absint($limit))),max(0,absint($offset))
		));
	}
    /**
     * Global status filter with server pagination; the original unfiltered
     * get_batch_rows() API is preserved for existing callers.
     */
    public function get_batch_rows_by_status( $id, $status, $limit = 100, $offset = 0 ) {
        global $wpdb;
        $allowed = array( self::MATCHED, self::AMBIGUOUS, self::NOT_FOUND, self::CONFLICT, self::APPLIED );
        if ( ! in_array( $status, $allowed, true ) ) { return array(); }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->rows_table()} WHERE batch_id=%d AND match_status=%s ORDER BY row_index ASC,id ASC LIMIT %d OFFSET %d",
            absint( $id ), $status, max( 1, min( 500, absint( $limit ) ) ), max( 0, absint( $offset ) )
        ) );
    }


    /**
     * Read-only query across the ENTIRE staged FFST batch.
     * Existing retrieval methods are intentionally preserved for callers.
     */
    private function build_batch_search_conditions( $id, $status, $search ) {
        global $wpdb;
        $allowed = array( self::MATCHED, self::AMBIGUOUS, self::NOT_FOUND, self::CONFLICT, self::APPLIED );
        $args = array( absint( $id ) );
        $where = 'batch_id=%d';
        if ( in_array( $status, $allowed, true ) ) {
            $where .= ' AND match_status=%s';
            $args[] = $status;
        }
        $search = trim( (string) $search );
        if ( '' !== $search ) {
            // Bounded, escaped LIKE prevents wildcard expansion and SQL injection.
            $search = function_exists( 'mb_substr' ) ? mb_substr( $search, 0, 100 ) : substr( $search, 0, 100 );
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $fields = array( 'ffst_number', 'first_name', 'last_name', 'birthdate', 'club_name', 'match_message' );
            $clauses = array();
            foreach ( $fields as $field ) {
                $clauses[] = "{$field} LIKE %s";
                $args[] = $like;
            }
            $where .= ' AND (' . implode( ' OR ', $clauses ) . ')';
        }
        return array( $where, $args );
    }

    public function get_batch_rows_filtered( $id, $status = '', $search = '', $limit = 100, $offset = 0 ) {
        global $wpdb;
        list( $where, $args ) = $this->build_batch_search_conditions( $id, $status, $search );
        $args[] = max( 1, min( 500, absint( $limit ) ) );
        $args[] = max( 0, absint( $offset ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->rows_table()} WHERE {$where} ORDER BY row_index ASC, id ASC LIMIT %d OFFSET %d",
            $args
        ) );
    }

    public function count_batch_rows_filtered( $id, $status = '', $search = '' ) {
        global $wpdb;
        list( $where, $args ) = $this->build_batch_search_conditions( $id, $status, $search );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->rows_table()} WHERE {$where}",
            $args
        ) );
    }

	public function get_batch_status_count($id,$status){
		global $wpdb;
		return (int)$wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->rows_table()} WHERE batch_id=%d AND match_status=%s",
			absint($id),sanitize_key($status)
		));
	}
	public function get_batch_unknown_status_count($id){
		global $wpdb;
		return (int)$wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->rows_table()} WHERE batch_id=%d AND match_status NOT IN (%s,%s,%s,%s,%s)",
			absint($id),self::MATCHED,self::AMBIGUOUS,self::NOT_FOUND,self::CONFLICT,self::APPLIED
		));
	}
	public function get_batch_row_count($id){
		global $wpdb;
		return (int)$wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->rows_table()} WHERE batch_id=%d",absint($id)
		));
	}
	public function get_recent_batches($limit=10){global $wpdb; return $wpdb->get_results("SELECT * FROM {$this->batches_table()} ORDER BY id DESC LIMIT ".max(1,min(50,absint($limit))));}

	private function validate_upload(array $file){
		if(empty($file['tmp_name'])||!is_uploaded_file($file['tmp_name'])) return new WP_Error('ffst_upload_missing',__('Aucun fichier valide reçu.','ufsc-licence-competition'));
		if(!empty($file['size'])&&(int)$file['size']>self::MAX_UPLOAD_BYTES) return new WP_Error('ffst_upload_too_large',__('Le fichier dépasse 10 Mo.','ufsc-licence-competition'));
		$ext=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));
		return in_array($ext,array('csv','xlsx','pdf'),true)?true:new WP_Error('ffst_upload_type',__('Formats acceptés : CSV, XLSX ou PDF.','ufsc-licence-competition'));
	}
	private function store_upload(array $file){
		$ext=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION)); $u=wp_upload_dir(); $dir=trailingslashit($u['basedir']).'ufsc-lc/ffst-staging';
		if(!wp_mkdir_p($dir)) return new WP_Error('ffst_upload_dir',__('Dossier temporaire impossible à créer.','ufsc-licence-competition'));
		$target=trailingslashit($dir).wp_unique_filename($dir,sanitize_file_name($file['name']));
		if(!@move_uploaded_file($file['tmp_name'],$target)) return new WP_Error('ffst_upload_move',__('Téléversement temporaire impossible.','ufsc-licence-competition'));
		return array('path'=>$target,'ext'=>$ext);
	}
	private function parse_file($path,$ext){if('csv'===$ext)return $this->parse_csv($path);if('xlsx'===$ext)return $this->parse_xlsx($path);if('pdf'===$ext)return $this->parse_pdf($path);return new WP_Error('ffst_parser_missing',__('Format non pris en charge.','ufsc-licence-competition'));}
	private function parse_csv($path){
		$h=fopen($path,'rb'); if(!$h)return new WP_Error('ffst_csv_open',__('CSV illisible.','ufsc-licence-competition'));
		$first=fgets($h); if(false===$first){fclose($h);return array();} $del=substr_count($first,';')>=substr_count($first,',')?';':','; rewind($h);
		$headers=fgetcsv($h,0,$del); $rows=array();
		while(($vals=fgetcsv($h,0,$del))!==false){if(!array_filter($vals,'strlen'))continue;$r=array();foreach((array)$headers as $i=>$head)$r[(string)$head]=isset($vals[$i])?$vals[$i]:'';$rows[]=$r;}fclose($h);return $rows;
	}
	private function parse_xlsx($path){
		if(!class_exists('ZipArchive'))return new WP_Error('ffst_xlsx_zip_missing',__('ZipArchive requis pour XLSX.','ufsc-licence-competition'));
		$z=new ZipArchive(); if(true!==$z->open($path))return new WP_Error('ffst_xlsx_open',__('XLSX illisible.','ufsc-licence-competition'));
		$shared=array();$sx=$z->getFromName('xl/sharedStrings.xml');if($sx&&($x=simplexml_load_string($sx)))foreach($x->si as $si){$t=(string)$si->t;if(''===$t&&isset($si->r))foreach($si->r as $run)$t.=(string)$run->t;$shared[]=$t;}
		$sheet=$z->getFromName('xl/worksheets/sheet1.xml');$z->close();if(!$sheet||!($x=simplexml_load_string($sheet)))return new WP_Error('ffst_xlsx_sheet',__('Premier onglet XLSX introuvable.','ufsc-licence-competition'));
		$matrix=array();foreach($x->sheetData->row as $row){$line=array();foreach($row->c as $cell){preg_match('/^[A-Z]+/',(string)$cell['r'],$m);$col=$this->xlsx_col($m?$m[0]:'A');$v=isset($cell->v)?(string)$cell->v:'';if('s'===(string)$cell['t']&&isset($shared[(int)$v]))$v=$shared[(int)$v];$line[$col]=$v;}if($line){ksort($line);$matrix[]=$line;}}
		if(count($matrix)<2)return array();$headers=array_shift($matrix);$rows=array();foreach($matrix as $line){$r=array();foreach($headers as $col=>$head)$r[(string)$head]=isset($line[$col])?$line[$col]:'';$rows[]=$r;}return $rows;
	}
	private function parse_pdf($path){
		if(!class_exists('\\Smalot\\PdfParser\\Parser'))return new WP_Error('ffst_pdf_parser_missing',__('Parseur PDF absent : utilisez le ZIP de production.','ufsc-licence-competition'));
		try{
			$p=new \Smalot\PdfParser\Parser();
			$pdf=$p->parseFile($path);
			$pages=$pdf->getPages();
		}catch(Exception $e){
			return new WP_Error('ffst_pdf_parse_failed',__('PDF FFST non analysable.','ufsc-licence-competition'));
		}
		$rows=array();
		foreach((array)$pages as $page){
			$text=trim((string)$page->getText());
			if(''===$text)continue;

			$row=$this->parse_ffst_native_page($text);
			if($row){
				$rows[]=$row;
				continue;
			}

			$row=$this->parse_pdf_block($text);
			if(!empty($row)){
				if(preg_match('/LICENCE\\s+FFST\\s+N[°º]?\\s*([A-Z0-9\\-]{4,})/iu',$text,$m)){
					$row['N° FFST']=$m[1];
				}elseif(preg_match('/\\bLicence\\s*[:\\-]\\s*([A-Z0-9\\-]{4,})/iu',$text,$m)){
					$row['N° FFST']=$m[1];
				}
				if(!empty($row['N° FFST']))$rows[]=$row;
			}
		}
		return $rows?:new WP_Error('ffst_pdf_layout_unknown',__('PDF lisible mais structure non reconnue. Aucune donnée écrite : utilisez le tableur/CSV ou adaptons le parseur à ce modèle.','ufsc-licence-competition'));
	}

	private function parse_ffst_native_page($text){
		$lines=preg_split('/\\R/u',(string)$text);
		$lines=array_values(array_filter(array_map('trim',(array)$lines),'strlen'));
		if(!$lines)return array();

		$joined=implode("\n",$lines);
		if(!preg_match('/LICENCE\\s+FFST\\s+N[°º]?\\s*([A-Z0-9\\-]{4,})/iu',$joined,$licence_match)){
			return array();
		}
		$ffst=strtoupper(trim($licence_match[1]));
		$r=array('N° FFST'=>$ffst);

		if(isset($lines[0]))$r['Club']=$lines[0];

		foreach($lines as $line){
			if(stripos($line,$ffst)!==false && stripos($line,'LICENCE FFST')===false && stripos($line,'Licence :')===false){
				$refs=trim(str_ireplace($ffst,'',$line));
				if(''!==$refs)$r['Références fédérales']=$refs;
				break;
			}
		}
		foreach($lines as $line){
			if(0===stripos($line,'UFSC - ')){
				$r['Discipline']=preg_replace('/\\s+PHOTO$/iu','',$line);
				break;
			}
		}

		// Smalot flattens the native FFST layout aggressively on production:
		// examples are "M11/09/1983né le" and "F12/05/1988née le".
		if(preg_match('/\\b([MF])\\s*(\\d{1,2}\\/\\d{1,2}\\/\\d{4})\\s*n[ée]e?\\s+le\\b/iu',$joined,$m)
			|| preg_match('/\\b([MF])\\s*n[ée]e?\\s+le\\s*(\\d{1,2}\\/\\d{1,2}\\/\\d{4})\\b/iu',$joined,$m)){
			$r['Sexe']=strtoupper($m[1]);
			$r['Date de naissance']=$m[2];
		}

		// The footer is also flattened, e.g.
		// "M M F C 03100D.CRUSOE2026-2027 ULicence : M211074".
		$holder_surname='';
		$holder_initial='';
		if(preg_match('/([A-ZÀ-ÖØ-Ý])\\.([A-ZÀ-ÖØ-Ý][A-ZÀ-ÖØ-Ý\' -]{1,60}?)(?=20\\d{2}-20\\d{2}|\\s+[A-Z]Licence|\\s+Licence|$)/u',$joined,$m)){
			$holder_initial=$m[1];
			$holder_surname=trim($m[2]);
			$r['Référence titulaire']=$holder_initial.'.'.$holder_surname;
		}

		$name_index=-1;
		if(''!==$holder_surname){
			foreach($lines as $i=>$line){
				// Production extraction often concatenates columns: CRUSOEDavy.
				if(0===stripos($line,$holder_surname) && strlen($line)>strlen($holder_surname)){
					$first=trim(substr($line,strlen($holder_surname)));
					if($this->is_person_first_name($first)){
						$r['Nom']=$holder_surname;
						$r['Prénom']=$first;
						$name_index=$i;
						break;
					}
				}
				if(preg_match('/^'.preg_quote($holder_surname,'/').'\\s+(.+)$/iu',$line,$m)){
					$first=trim($m[1]);
					if($this->is_person_first_name($first)){
						$r['Nom']=$holder_surname;
						$r['Prénom']=$first;
						$name_index=$i;
						break;
					}
				}
				if(0===strcasecmp($line,$holder_surname)){
					for($j=$i+1,$max=min(count($lines),$i+5);$j<$max;$j++){
						if($this->is_person_first_name($lines[$j])){
							$r['Nom']=$holder_surname;
							$r['Prénom']=$lines[$j];
							$name_index=$j;
							break 2;
						}
					}
				}
			}
		}

		// Signature-zone fallback when the footer reference cannot be recovered.
		if(empty($r['Nom'])||empty($r['Prénom'])){
			$sig=-1;
			foreach($lines as $i=>$line){
				if(false!==stripos($line,'Signature')){$sig=$i;break;}
			}
			if($sig>=0){
				for($i=$sig+1,$n=min(count($lines),$sig+8);$i<$n;$i++){
					$line=$lines[$i];
					if(preg_match('/^([A-ZÀ-ÖØ-Ý][A-ZÀ-ÖØ-Ý\' -]{1,})\\s+([A-ZÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ\' -]{1,})$/u',$line,$m)){
						$r['Nom']=trim($m[1]);$r['Prénom']=trim($m[2]);$name_index=$i;break;
					}
					if(preg_match('/^[A-ZÀ-ÖØ-Ý][A-ZÀ-ÖØ-Ý\' -]{1,}$/u',$line) && isset($lines[$i+1]) && $this->is_person_first_name($lines[$i+1])){
						$r['Nom']=trim($line);$r['Prénom']=trim($lines[$i+1]);$name_index=$i+1;break;
					}
				}
			}
		}

		if($name_index>=0){
			for($i=$name_index+1,$n=count($lines);$i<$n;$i++){
				$line=$lines[$i];
				if(preg_match('/^(Je soussign|Porteur|Fait à|Docteur|Cachet|LICENCE FFST)/iu',$line))break;
				if(preg_match('/^[MF]\\s*(?:\\d{1,2}\\/\\d{1,2}\\/\\d{4}\\s*n[ée]e?\\s+le|n[ée]e?\\s+le)/iu',$line))continue;
				if(empty($r['Adresse']) && preg_match('/\\d/u',$line) && !preg_match('/^\\d{5}\\s+/u',$line)){
					$r['Adresse']=$line;
					continue;
				}
				if(preg_match('/^(\\d{5})\\s+(.+)$/u',$line,$m)){
					$r['Code postal']=$m[1];
					$r['Ville']=$m[2];
					$r['Adresse']=trim((isset($r['Adresse'])?$r['Adresse'].' ':'').$line);
					break;
				}
			}
		}

		$discipline_index=-1;
		foreach($lines as $i=>$line){if(0===stripos($line,'UFSC - ')){$discipline_index=$i;break;}}
		if($discipline_index>=0){
			for($i=$discipline_index+1,$n=min(count($lines),$discipline_index+8);$i<$n;$i++){
				$line=$lines[$i];
				$clean=trim(preg_replace('/\\bPHOTO\\b/iu','',$line));
				if(''===$clean||0===strcasecmp($clean,'Signature'))continue;
				if(preg_match('/^[MF]\\s*(?:\\d{1,2}\\/\\d{1,2}\\/\\d{4}\\s*n[ée]e?\\s+le|n[ée]e?\\s+le)/iu',$clean))continue;
				if(!empty($r['Nom']) && false!==stripos($clean,$r['Nom']))break;
				if(preg_match('/^[A-ZÀ-ÖØ-Ý][A-ZÀ-ÖØ-Ý \'-]{2,}$/u',$clean)){
					$r['Fonction']=$clean;
					break;
				}
			}
		}

		if(preg_match('/D[ée]livr[ée]e?\\s+le\\s*:\\s*(\\d{1,2}\\/\\d{1,2}\\/\\d{4})/iu',$joined,$m))$r['Délivrée le']=$m[1];
		if(preg_match('/(20\\d{2}-20\\d{2})\\s+([A-Z])/u',$joined,$m))$r['Code source']=$m[2];

		// Code club may be glued to holder reference in the flattened footer.
		if(preg_match('/([A-Z](?:\\s+[A-Z]){1,5}\\s+\\d{5})(?=[A-Z]\\.)/u',$joined,$m)
			|| preg_match('/\\b([A-Z](?:\\s+[A-Z]){1,5}\\s+\\d{5})\\b/u',$joined,$m)){
			$r['Code club']=$m[1];
		}

		return (!empty($r['Nom'])&&!empty($r['Prénom'])&&!empty($r['Date de naissance']))?$r:array();
	}


	private function is_person_first_name($value){
		$value=trim((string)$value);
		if(''===$value||preg_match('/\\d/u',$value))return false;
		if(preg_match('/^(PHOTO|SIGNATURE|PRESIDENT|SECRETAIRE|TRESORIER|ENTRAINEUR|JE SOUSSIGN|PORTEUR|FAIT|DOCTEUR|CACHET)$/iu',$value))return false;
		return (bool)preg_match('/^[A-ZÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ\'’-]{1,40}(?:[ -][A-ZÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ\'’-]{1,40})*$/u',$value);
	}

	private function parse_pdf_block($b){$r=array();$p=array('Nom'=>'/\\bNom\\s*[:\\-]\\s*([^\\r\\n]+)/iu','Prénom'=>'/\\bPr[ée]nom\\s*[:\\-]\\s*([^\\r\\n]+)/iu','Date de naissance'=>'/(?:Date de naissance|N[ée]\\s+le)\\s*[:\\-]?\\s*(\\d{1,2}[\\/\\-.]\\d{1,2}[\\/\\-.]\\d{2,4})/iu','Sexe'=>'/\\bSexe\\s*[:\\-]\\s*([MF])/iu','Club'=>'/\\bClub\\s*[:\\-]\\s*([^\\r\\n]+)/iu','Adresse'=>'/\\bAdresse\\s*[:\\-]\\s*([^\\r\\n]+)/iu','Discipline'=>'/\\bDiscipline\\s*[:\\-]\\s*([^\\r\\n]+)/iu','Fonction'=>'/\\bFonction\\s*[:\\-]\\s*([^\\r\\n]+)/iu','Délivrée le'=>'/(?:D[ée]livr[ée]e?\\s+le)\\s*[:\\-]?\\s*(\\d{1,2}[\\/\\-.]\\d{1,2}[\\/\\-.]\\d{2,4})/iu');foreach($p as $k=>$re)if(preg_match($re,$b,$m))$r[$k]=trim($m[1]);return $r;}

	private function normalize_record(array $record){
		$m=array();foreach($record as $k=>$v)$m[$this->key($k)]=is_scalar($v)?trim((string)$v):'';
		$get=function($keys)use($m){foreach($keys as $k){$k=$this->key($k);if(isset($m[$k])&&''!==$m[$k])return$m[$k];}return'';};
		$a=$get(array('adresse','address'));$cp=$get(array('code postal','cp'));$city=$get(array('ville','city'));if((''===$cp||''===$city)&&preg_match('/\\b(\\d{5})\\s+([^,]+)$/u',$a,$x)){$cp=$cp?:$x[1];$city=$city?:trim($x[2]);}
		return array('ffst_number'=>strtoupper($get(array('n ffst','numero ffst','n licence ffst','licence ffst','licence'))),'last_name'=>strtoupper($get(array('nom','nom licencie'))),'first_name'=>$get(array('prenom','prenom licencie')),'birthdate'=>$this->date($get(array('date de naissance','ne le','naissance'))),'sex'=>strtoupper($get(array('sexe','genre'))),'club_name'=>$get(array('club','nom club','association')),'address'=>$a,'postal_code'=>$cp,'city'=>$city,'discipline'=>$get(array('discipline','activite')),'role_name'=>$get(array('fonction','role','qualite')),'federal_references'=>$get(array('references federales','references','refs federales')),'issued_at'=>$this->date($get(array('delivree le','date delivrance'))),'source_club_code'=>$get(array('code club','mention source','code club source')),'source_holder_ref'=>$get(array('reference titulaire','ref titulaire')),'source_code'=>$get(array('code source','source code')));
	}
	private function resolve_match(array $d,$season){
		global $wpdb;
		$lt=$wpdb->prefix.'ufsc_licences';
		$ct=$wpdb->prefix.'ufsc_clubs';
		$has_ffst=$this->column_exists($lt,'numero_licence_ffst');
		$last=$this->column_exists($lt,'nom')?'l.nom':'l.nom_licence';
		$clubcol=$this->column_exists($ct,'nom')?'c.nom':'c.name';

		// A matching FFST number is NOT sufficient to identify someone:
		// always verify full name, birth date, club and season too.
		$number_owner_id = 0;
		if($has_ffst&&''!==$d['ffst_number']){
			$x=$wpdb->get_results($wpdb->prepare(
				"SELECT id,numero_licence_ffst FROM {$lt} WHERE UPPER(numero_licence_ffst)=%s LIMIT 3",
				strtoupper($d['ffst_number'])
			));
			if(1===count($x))$number_owner_id=absint($x[0]->id);
			if(count($x)>1)return array('status'=>self::AMBIGUOUS,'licence_id'=>0,'message'=>__('N° FFST déjà présent plusieurs fois.','ufsc-licence-competition'),'previous_ffst'=>'');
		}

		if(''===$d['last_name']||''===$d['first_name']||''===$d['birthdate']){
			return array('status'=>self::NOT_FOUND,'licence_id'=>0,'message'=>__('Identité insuffisante pour un rapprochement sûr.','ufsc-licence-competition'),'previous_ffst'=>'');
		}

		$select=array(
			'l.id',
            'l.club_id',
			$has_ffst?'l.numero_licence_ffst':"'' AS numero_licence_ffst",
			"{$clubcol} AS club_name",
		);
		if($this->column_exists($lt,'season_end_year'))$select[]='l.season_end_year';
		if($this->column_exists($lt,'saison'))$select[]='l.saison';
		elseif($this->column_exists($lt,'season'))$select[]='l.season';

		// Search on the exact birth date, then compare both names in PHP.
		// Strict SQL first-name equality rejects Jean-Christophe / Jean Christophe.
		// Never fuzzy-match identities; ambiguous candidates remain blocked.
		$select[]="{$last} AS last_name";
		$select[]='l.prenom AS first_name';
		$sql="SELECT ".implode(',',$select)." FROM {$lt} l LEFT JOIN {$ct} c ON c.id=l.club_id
			WHERE l.date_naissance=%s ORDER BY l.id DESC LIMIT 501";
		$birth_candidates=$wpdb->get_results($wpdb->prepare($sql,$d['birthdate']));
		if(count($birth_candidates)>500){
			return array('status'=>self::AMBIGUOUS,'licence_id'=>0,'message'=>__('Trop de candidats pour cette date de naissance : vérification manuelle obligatoire.','ufsc-licence-competition'),'previous_ffst'=>'');
		}
		$candidates=array_values(array_filter($birth_candidates,function($candidate)use($d){
			return $this->norm((string)$candidate->last_name)===$this->norm($d['last_name'])
				&& $this->norm((string)$candidate->first_name)===$this->norm($d['first_name']);
		}));

		// Restrict duplicate identities to the season explicitly selected for the
		// FFST import. This is critical when the same member has historical rows.
		if($candidates && absint($season)>0){
			$season_end=absint($season);
			$season_label=sprintf('%d-%d',$season_end-1,$season_end);
			$season_label_alt=sprintf('%d/%d',$season_end-1,$season_end);
			$season_matches=array_values(array_filter($candidates,function($candidate)use($season_end,$season_label,$season_label_alt){
				if(isset($candidate->season_end_year) && absint($candidate->season_end_year)===$season_end)return true;
				$value='';
				if(isset($candidate->saison))$value=trim((string)$candidate->saison);
				elseif(isset($candidate->season))$value=trim((string)$candidate->season);
				if(''===$value)return false;
				return in_array($value,array((string)$season_end,$season_label,$season_label_alt),true);
			}));
			$candidates=$season_matches;
		}

		// Explicitly approved alias between the FFST and UFSC club names.
        // It only filters the search: never overrides a member's identity.
        $mapped_club = class_exists( 'UFSC_LC_FFST_Resolution_Admin', false )
            ? UFSC_LC_FFST_Resolution_Admin::target_club_id( $d['club_name'] ) : 0;
        if ( $mapped_club ) {
            $candidates = array_values( array_filter( $candidates, static function( $candidate ) use( $mapped_club ) {
                return absint( $candidate->club_id ) === $mapped_club;
            } ) );
        }
		// Then prefer the club provided by FFST when no alias has been set.
		if(!$mapped_club && ''!==$d['club_name']&&count($candidates)>1){
			$club_norm=$this->norm($d['club_name']);
			$club_matches=array_values(array_filter($candidates,function($candidate)use($club_norm){
				return $club_norm===$this->norm((string)$candidate->club_name);
			}));
			if($club_matches)$candidates=$club_matches;
		}

		if(1!==count($candidates)){
            if($number_owner_id){
                return array('status'=>self::CONFLICT,'licence_id'=>0,'message'=>__('N° FFST déjà attribué : identité, club ou saison non concordants. Vérification manuelle obligatoire.','ufsc-licence-competition'),'previous_ffst'=>'');
            }
			$message=count($candidates)>1
				? __('Plusieurs licences correspondent encore après filtrage saison + club. Vérification manuelle requise.','ufsc-licence-competition')
				: __('Aucune licence UFSC Gestion correspondante pour la saison sélectionnée.','ufsc-licence-competition');
			return array(
				'status'=>count($candidates)>1?self::AMBIGUOUS:self::NOT_FOUND,
				'licence_id'=>0,
				'message'=>$message,
				'previous_ffst'=>''
			);
		}

		$candidate=$candidates[0];
        if($number_owner_id && $number_owner_id!==absint($candidate->id)){
            return array('status'=>self::CONFLICT,'licence_id'=>0,'message'=>__('Ce numéro FFST appartient à une autre fiche UFSC. Aucune association autorisée.','ufsc-licence-competition'),'previous_ffst'=>'');
        }
        // A unique homonym in a different club is NOT a verified match.
        // The administrator can explicitly map the FFST club name first.
        if(!$mapped_club && ''!==trim((string)$d['club_name']) &&
            $this->norm($d['club_name'])!==$this->norm((string)$candidate->club_name)){
            return array('status'=>self::NOT_FOUND,'licence_id'=>0,'message'=>__('Nom du club FFST différent du club UFSC : associez le club cible avant de rapprocher.','ufsc-licence-competition'),'previous_ffst'=>'');
        }
		$prev=trim((string)$candidate->numero_licence_ffst);
		if(''!==$prev&&''!==$d['ffst_number']&&0!==strcasecmp($prev,$d['ffst_number'])){
			return array('status'=>self::CONFLICT,'licence_id'=>$candidate->id,'message'=>__('Conflit : un autre N° FFST existe déjà. Aucune écriture automatique.','ufsc-licence-competition'),'previous_ffst'=>$prev);
		}

		return array(
			'status'=>self::MATCHED,
			'licence_id'=>$candidate->id,
			'message'=>__('Correspondance sûre identité + naissance + saison + club.','ufsc-licence-competition'),
			'previous_ffst'=>$prev
		);
	}

	private function write_pdf_meta($id,$r){foreach(array('ffst_references'=>$r->federal_references,'ffst_issued_at'=>$r->issued_at,'ffst_club_code'=>$r->source_club_code,'ffst_holder_reference'=>$r->source_holder_ref,'ffst_source_code'=>$r->source_code) as $k=>$v)if(''!==trim((string)$v))$this->meta($id,$k,$v);}
	private function meta($id,$k,$v){global$wpdb;$t=$wpdb->prefix.'ufsc_licence_documents_meta';if(!$this->table_exists($t))return;$mid=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE licence_id=%d AND source=%s AND meta_key=%s LIMIT 1",$id,'UFSC',$k));$d=array('licence_id'=>$id,'source'=>'UFSC','meta_key'=>$k,'meta_value'=>maybe_serialize($v),'updated_at'=>current_time('mysql'));if($mid)$wpdb->update($t,$d,array('id'=>absint($mid)),array('%d','%s','%s','%s','%s'),array('%d'));else$wpdb->insert($t,$d,array('%d','%s','%s','%s','%s'));}
	private function key($v){$v=remove_accents(strtolower(trim((string)$v)));return trim(preg_replace('/[^a-z0-9]+/',' ',$v));}
	private function date($v){$v=trim((string)$v);foreach(array('d/m/Y','d-m-Y','d.m.Y','Y-m-d','d/m/y') as$f){$d=DateTime::createFromFormat($f,$v);if($d instanceof DateTime)return$d->format('Y-m-d');}return'';}
	private function norm($v){
		$value=remove_accents(trim((string)$v));
		// Only normalize hyphens/whitespace; never guess a different identity.
		$value=preg_replace('/[\\p{Pd}-]+/u',' ',$value);
		return strtoupper(trim(preg_replace('/\\s+/u',' ',$value)));
	}
	private function xlsx_col($letters){$i=0;foreach(str_split(strtoupper($letters))as$c)$i=$i*26+(ord($c)-64);return$i-1;}
	private function batches_table(){global$wpdb;return$wpdb->prefix.'ufsc_lc_ffst_import_batches';}
	private function rows_table(){global$wpdb;return$wpdb->prefix.'ufsc_lc_ffst_import_rows';}
	private function table_exists($t){global$wpdb;return$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t))===$t;}
	private function column_exists($t,$c){global$wpdb;return(bool)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t} LIKE %s",$c));}
}
