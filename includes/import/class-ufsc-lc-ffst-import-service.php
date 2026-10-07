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
		$stats=array(self::MATCHED=>0,self::AMBIGUOUS=>0,self::CONFLICT=>0,self::NOT_FOUND=>0); $i=0;
		foreach($records as $record){$i++; $d=$this->normalize_record((array)$record); $m=$this->resolve_match($d,$season_end_year); $stats[$m['status']]++;
			$inserted=$wpdb->insert($this->rows_table(),array(
				'batch_id'=>$batch_id,'row_index'=>$i,'ffst_number'=>$d['ffst_number'],'last_name'=>$d['last_name'],'first_name'=>$d['first_name'],
				'birthdate'=>$d['birthdate'],'sex'=>$d['sex'],'club_name'=>$d['club_name'],'address'=>$d['address'],'postal_code'=>$d['postal_code'],
				'city'=>$d['city'],'discipline'=>$d['discipline'],'role_name'=>$d['role_name'],'federal_references'=>$d['federal_references'],
				'issued_at'=>$d['issued_at'],'source_club_code'=>$d['source_club_code'],'source_holder_ref'=>$d['source_holder_ref'],
				'source_code'=>$d['source_code'],'match_status'=>$m['status'],'match_licence_id'=>absint($m['licence_id']),
				'match_message'=>$m['message'],'previous_ffst_number'=>$m['previous_ffst'],
				'raw_payload'=>wp_json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
			),array('%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s'));
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

	public function apply_batch($batch_id){
		// Legacy API remains available, but no longer means "apply every match".
		return new WP_Error('ffst_explicit_selection_required',__('Sélectionnez explicitement les licences à rapprocher avant validation.','ufsc-licence-competition'));
	}

	/**
	 * Duplicate numbers or destinations inside the current upload must be
	 * reviewed, even if two lines otherwise match the same person.
	 */
	public function get_duplicate_flags($batch_id){
		global $wpdb;
		$rows=$this->rows_table();
		$numbers=$wpdb->get_results($wpdb->prepare(
			"SELECT UPPER(TRIM(ffst_number)) AS duplicate_key, COUNT(*) AS qty FROM {$rows}
			 WHERE batch_id=%d AND ffst_number IS NOT NULL AND TRIM(ffst_number)<>'' 
			 GROUP BY UPPER(TRIM(ffst_number)) HAVING COUNT(*)>1",
			absint($batch_id)
		),ARRAY_A);
		$targets=$wpdb->get_results($wpdb->prepare(
			"SELECT match_licence_id AS duplicate_key, COUNT(*) AS qty FROM {$rows}
			 WHERE batch_id=%d AND match_licence_id IS NOT NULL AND match_licence_id>0
			 GROUP BY match_licence_id HAVING COUNT(*)>1",
			absint($batch_id)
		),ARRAY_A);
		$result=array('numbers'=>array(),'targets'=>array());
		foreach((array)$numbers as $row)$result['numbers'][(string)$row['duplicate_key']]=true;
		foreach((array)$targets as $row)$result['targets'][(int)$row['duplicate_key']]=true;
		return $result;
	}

	/** Read-only search for matching UFSC Gestion rows; limited to exact identity. */
	public function get_candidates_for_row($batch_id,$row_id){
		global $wpdb;
		$row=$this->get_row_in_batch($batch_id,$row_id);
		if(!$row)return array();
		if(!$row->last_name||!$row->first_name||!$row->birthdate||'0000-00-00'===$row->birthdate)return array();
		$lt=$wpdb->prefix.'ufsc_licences';
		$ct=$wpdb->prefix.'ufsc_clubs';
		$last_col=$this->column_exists($lt,'nom')?'l.nom':'l.nom_licence';
		$club_col=$this->column_exists($ct,'nom')?'c.nom':'c.name';
		$has_ffst=$this->column_exists($lt,'numero_licence_ffst');
		$fields="l.id,{$last_col} AS nom,l.prenom,l.date_naissance,l.club_id,{$club_col} AS club_name";
		$fields.=$has_ffst?",l.numero_licence_ffst":" ,'' AS numero_licence_ffst";
		if($this->column_exists($lt,'season_end_year'))$fields.=',l.season_end_year';
		if($this->column_exists($lt,'saison'))$fields.=',l.saison';
		elseif($this->column_exists($lt,'season'))$fields.=',l.season';
		$sql="SELECT {$fields} FROM {$lt} l LEFT JOIN {$ct} c ON c.id=l.club_id
			WHERE UPPER(TRIM({$last_col}))=%s AND UPPER(TRIM(l.prenom))=%s AND l.date_naissance=%s ORDER BY l.id DESC LIMIT 50";
		return (array)$wpdb->get_results($wpdb->prepare(
			$sql,
			$this->norm($row->last_name),$this->norm($row->first_name),$row->birthdate
		));
	}

	public function get_row_in_batch($batch_id,$row_id){
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$this->rows_table()} WHERE batch_id=%d AND id=%d LIMIT 1",
			absint($batch_id),absint($row_id)
		));
	}

	/** Review a candidate in staging without touching UFSC Gestion. */
	public function confirm_row_match($batch_id,$row_id,$licence_id){
		global $wpdb;
		$batch=$this->get_batch($batch_id);
		$row=$this->get_row_in_batch($batch_id,$row_id);
		if(!$batch||!$row||'applied'===$row->match_status)return new WP_Error('ffst_review_unavailable',__('Cette ligne ne peut plus être rapprochée.','ufsc-licence-competition'));
		if(!in_array($batch->status,array('preview','partial'),true))return new WP_Error('ffst_review_batch_locked',__('Le lot est clôturé.','ufsc-licence-competition'));
		$chosen=null;
		foreach($this->get_candidates_for_row($batch_id,$row_id) as $candidate){
			if((int)$candidate->id===(int)$licence_id){$chosen=$candidate;break;}
		}
		if(!$chosen)return new WP_Error('ffst_invalid_candidate',__('La fiche choisie ne correspond pas strictement à l’identité et à la naissance du PDF.','ufsc-licence-competition'));
		$existing=trim((string)$chosen->numero_licence_ffst);
		if(''===$row->ffst_number || (''!==$existing && 0!==strcasecmp($existing,(string)$row->ffst_number)))
			return new WP_Error('ffst_review_conflict',__('Cette fiche possède déjà un N° FFST différent ou le numéro source manque.','ufsc-licence-competition'));
		if(!$this->candidate_season_matches($chosen,(int)$batch->season_end_year))
			return new WP_Error('ffst_review_wrong_season',__('La fiche choisie n’appartient pas à la saison du lot.','ufsc-licence-competition'));
		if($row->club_name && $this->norm($row->club_name)!==$this->norm($chosen->club_name))
			return new WP_Error('ffst_review_wrong_club',__('Le club de la fiche choisie diffère du club FFST.','ufsc-licence-competition'));
		$other=$wpdb->get_var($wpdb->prepare(
			"SELECT id FROM {$this->rows_table()} WHERE batch_id=%d AND id<>%d AND match_licence_id=%d LIMIT 1",
			absint($batch_id),absint($row_id),absint($licence_id)
		));
		if($other)return new WP_Error('ffst_review_duplicate_target',__('Une autre ligne du lot utilise déjà cette licence UFSC.','ufsc-licence-competition'));
		$ok=$wpdb->update($this->rows_table(),array(
			'match_status'=>self::MATCHED,
			'match_licence_id'=>absint($licence_id),
			'match_message'=>__('Correspondance choisie et contrôlée manuellement : identité, naissance, saison et club.','ufsc-licence-competition'),
			'previous_ffst_number'=>$existing,
		),array('id'=>absint($row_id),'batch_id'=>absint($batch_id)),array('%s','%d','%s','%s'),array('%d','%d'));
		if(false===$ok)return new WP_Error('ffst_review_save_failed',__('Impossible d’enregistrer le rapprochement.','ufsc-licence-competition'));
		$this->refresh_batch_counts($batch_id);
		return true;
	}

	private function candidate_season_matches($candidate,$season_end){
		if(!$season_end)return false;
		if(isset($candidate->season_end_year) && (int)$candidate->season_end_year===$season_end)return true;
		$value='';
		if(isset($candidate->saison))$value=trim((string)$candidate->saison);
		elseif(isset($candidate->season))$value=trim((string)$candidate->season);
		if(''===$value){
			// No observable season is unsafe for manual reassignment.
			return false;
		}
		return in_array($value,array((string)$season_end,($season_end-1).'-'.$season_end,($season_end-1).'/'.$season_end),true);
	}

	private function refresh_batch_counts($batch_id){
		global $wpdb;
		$counts=$wpdb->get_results($wpdb->prepare(
			"SELECT match_status,COUNT(*) qty FROM {$this->rows_table()} WHERE batch_id=%d GROUP BY match_status",
			absint($batch_id)
		),ARRAY_A);
		$totals=array('matched'=>0,'ambiguous'=>0,'conflict'=>0,'not_found'=>0,'applied'=>0);
		foreach((array)$counts as $item){
			if(isset($totals[$item['match_status']]))$totals[$item['match_status']]+=(int)$item['qty'];
		}
		$wpdb->update($this->batches_table(),array(
			'matched_rows'=>$totals['matched'],'ambiguous_rows'=>$totals['ambiguous'],
			'conflict_rows'=>$totals['conflict'],'not_found_rows'=>$totals['not_found'],
			'applied_rows'=>$totals['applied']
		),array('id'=>absint($batch_id)),array('%d','%d','%d','%d','%d'),array('%d'));
	}

	/** Only checked, safe, non-duplicate rows can change the master database. */
	public function apply_selected_rows($batch_id,array $selected_ids){
		global $wpdb;
		$batch_id=absint($batch_id);
		$batch=$this->get_batch($batch_id);
		if(!$batch||!in_array($batch->status,array('preview','partial'),true))
			return new WP_Error('ffst_batch_locked',__('Lot introuvable ou déjà clôturé.','ufsc-licence-competition'));
		$ids=array_values(array_unique(array_filter(array_map('absint',$selected_ids))));
		if(!$ids||count($ids)>100)
			return new WP_Error('ffst_selection_invalid',__('Sélectionnez entre 1 et 100 lignes par opération.','ufsc-licence-competition'));
		$lt=$wpdb->prefix.'ufsc_licences';
		if(!$this->column_exists($lt,'numero_licence_ffst'))
			return new WP_Error('ffst_column_missing',__('Le champ canonique FFST manque dans UFSC Gestion. Aucune modification.','ufsc-licence-competition'));

		$lock_key='ufsc_lc_ffst_batch_'.$batch_id;
		$acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 8)',$lock_key));
		if((string)$acquired!=='1')return new WP_Error('ffst_import_busy',__('Un autre traitement de ce lot est déjà en cours. Réessayez.','ufsc-licence-competition'));
		try{
			// Re-fetch after locking, so repeat clicks cannot apply twice.
			$batch=$this->get_batch($batch_id);
			if(!$batch||!in_array($batch->status,array('preview','partial'),true))
				return new WP_Error('ffst_batch_locked',__('Le lot est déjà clôturé.','ufsc-licence-competition'));
			if($this->get_batch_row_count($batch_id)!==(int)$batch->total_rows)
				return new WP_Error('ffst_count_mismatch',__('Le nombre de lignes du lot ne correspond pas aux données stockées.','ufsc-licence-competition'));
			$flags=$this->get_duplicate_flags($batch_id);
			$plan=array();
			$seen_target=array();
			$seen_ffst=array();
			foreach($ids as $id){
				$row=$this->get_row_in_batch($batch_id,$id);
				if(!$row||self::MATCHED!==$row->match_status||!(int)$row->match_licence_id)
					return new WP_Error('ffst_bad_selected_row',__('Une ligne sélectionnée n’est pas validée pour rapprochement. Aucune donnée modifiée.','ufsc-licence-competition'));
				$number=strtoupper(trim((string)$row->ffst_number));
				$target=(int)$row->match_licence_id;
				if(''===$number||isset($flags['numbers'][$number])||isset($flags['targets'][$target])||isset($seen_target[$target])||isset($seen_ffst[$number]))
					return new WP_Error('ffst_duplicate',__('Doublon de numéro FFST ou de licence UFSC dans le lot : validation bloquée.','ufsc-licence-competition'));
				$seen_target[$target]=true;$seen_ffst[$number]=true;
				$master=$wpdb->get_row($wpdb->prepare(
					"SELECT id,numero_licence_ffst FROM {$lt} WHERE id=%d LIMIT 1",$target
				));
				if(!$master)return new WP_Error('ffst_master_missing',__('Une licence cible n’existe plus. Aucune modification.','ufsc-licence-competition'));
				$current=trim((string)$master->numero_licence_ffst);
				if(''!==$current && 0!==strcasecmp($current,$number))
					return new WP_Error('ffst_number_conflict',__('Une licence UFSC porte déjà un autre numéro FFST.','ufsc-licence-competition'));
				$other=$wpdb->get_var($wpdb->prepare(
					"SELECT id FROM {$lt} WHERE UPPER(TRIM(numero_licence_ffst))=%s AND id<>%d LIMIT 1",$number,$target
				));
				if($other)return new WP_Error('ffst_number_owned',__('Ce numéro FFST est déjà rattaché à une autre licence UFSC.','ufsc-licence-competition'));
				$plan[]=array('row'=>$row,'target'=>$target,'number'=>$number,'previous'=>$current);
			}
			$transaction=false!==$wpdb->query('START TRANSACTION');
			$changed=array();
			foreach($plan as $item){
				if(''===$item['previous']){
					// Conditional update prevents overwriting a concurrent change.
					$result=$wpdb->query($wpdb->prepare(
						"UPDATE {$lt} SET numero_licence_ffst=%s WHERE id=%d AND (numero_licence_ffst IS NULL OR numero_licence_ffst='')",
						$item['number'],$item['target']
					));
					if(1!==(int)$result){
						if($transaction)$wpdb->query('ROLLBACK');
						else foreach(array_reverse($changed) as $done)$wpdb->update($lt,array('numero_licence_ffst'=>$done['previous']),array('id'=>$done['target']),array('%s'),array('%d'));
						return new WP_Error('ffst_concurrent_update',__('Modification concurrente détectée : import interrompu. Contrôlez les fiches avant de réessayer.','ufsc-licence-competition'));
					}
					$changed[]=$item;
				}
				$updated=$wpdb->update($this->rows_table(),array(
					'match_status'=>self::APPLIED,'applied_at'=>current_time('mysql')
				),array('id'=>(int)$item['row']->id,'batch_id'=>$batch_id,'match_status'=>self::MATCHED),array('%s','%s'),array('%d','%d','%s'));
				if(1!==(int)$updated){
					if($transaction)$wpdb->query('ROLLBACK');
					else foreach(array_reverse($changed) as $done)$wpdb->update($lt,array('numero_licence_ffst'=>$done['previous']),array('id'=>$done['target']),array('%s'),array('%d'));
					return new WP_Error('ffst_staging_update_failed',__('Échec de validation du staging ; vérification de la base nécessaire.','ufsc-licence-competition'));
				}
			}
			if($transaction && false===$wpdb->query('COMMIT'))
				return new WP_Error('ffst_commit_failed',__('La transaction n’a pas été confirmée ; vérifiez la base avant nouvel import.','ufsc-licence-competition'));
			$this->refresh_batch_counts($batch_id);
			$remaining=(int)$wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->rows_table()} WHERE batch_id=%d AND match_status=%s",
				$batch_id,self::MATCHED
			));
			$wpdb->update($this->batches_table(),array(
				'status'=>$remaining?'partial':'applied','applied_at'=>current_time('mysql')
			),array('id'=>$batch_id),array('%s','%s'),array('%d'));
			// The PDF hook is intentionally post-commit, never inside transaction.
			foreach($plan as $item){
				$this->write_pdf_meta($item['target'],$item['row']);
				do_action('ufsc_lc_ffst_import_synced',$item['target'],'ffst-v2-'.$batch_id);
			}
			if(function_exists('ufsc_lc_bump_cache_version'))ufsc_lc_bump_cache_version('status',0);
			return count($plan);
		}finally{
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock_key));
		}
	}

	public function get_batch($id){global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->batches_table()} WHERE id=%d LIMIT 1",absint($id)));}
	public function get_batch_rows($id,$limit=250,$offset=0){
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$this->rows_table()} WHERE batch_id=%d ORDER BY row_index ASC, id ASC LIMIT %d OFFSET %d",
			absint($id),max(1,min(500,absint($limit))),max(0,absint($offset))
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

		// Exact FFST number always wins if unique.
		if($has_ffst&&''!==$d['ffst_number']){
			$x=$wpdb->get_results($wpdb->prepare(
				"SELECT id,numero_licence_ffst FROM {$lt} WHERE UPPER(numero_licence_ffst)=%s LIMIT 3",
				strtoupper($d['ffst_number'])
			));
			if(1===count($x))return array('status'=>self::MATCHED,'licence_id'=>$x[0]->id,'message'=>__('Correspondance exacte N° FFST.','ufsc-licence-competition'),'previous_ffst'=>$x[0]->numero_licence_ffst);
			if(count($x)>1)return array('status'=>self::AMBIGUOUS,'licence_id'=>0,'message'=>__('N° FFST déjà présent plusieurs fois.','ufsc-licence-competition'),'previous_ffst'=>'');
		}

		if(''===$d['last_name']||''===$d['first_name']||''===$d['birthdate']){
			return array('status'=>self::NOT_FOUND,'licence_id'=>0,'message'=>__('Identité insuffisante pour un rapprochement sûr.','ufsc-licence-competition'),'previous_ffst'=>'');
		}

		$select=array(
			'l.id',
			$has_ffst?'l.numero_licence_ffst':"'' AS numero_licence_ffst",
			"{$clubcol} AS club_name",
		);
		if($this->column_exists($lt,'season_end_year'))$select[]='l.season_end_year';
		if($this->column_exists($lt,'saison'))$select[]='l.saison';
		elseif($this->column_exists($lt,'season'))$select[]='l.season';

		$sql="SELECT ".implode(',',$select)." FROM {$lt} l LEFT JOIN {$ct} c ON c.id=l.club_id
			WHERE UPPER(TRIM({$last}))=%s AND UPPER(TRIM(l.prenom))=%s AND l.date_naissance=%s";
		$args=array($this->norm($d['last_name']),$this->norm($d['first_name']),$d['birthdate']);
		$candidates=$wpdb->get_results($wpdb->prepare($sql.' ORDER BY l.id DESC LIMIT 25',$args));

		// Restrict duplicate identities to the season explicitly selected for the
		// FFST import. This is critical when the same member has historical rows.
		if(count($candidates)>1 && absint($season)>0){
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
			if($season_matches)$candidates=$season_matches;
		}

		// Then prefer the club provided by FFST.
		if(''!==$d['club_name']&&count($candidates)>1){
			$club_norm=$this->norm($d['club_name']);
			$club_matches=array_values(array_filter($candidates,function($candidate)use($club_norm){
				return $club_norm===$this->norm((string)$candidate->club_name);
			}));
			if($club_matches)$candidates=$club_matches;
		}

		if(1!==count($candidates)){
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
	private function norm($v){return strtoupper(preg_replace('/\\s+/',' ',trim(remove_accents((string)$v))));}
	private function xlsx_col($letters){$i=0;foreach(str_split(strtoupper($letters))as$c)$i=$i*26+(ord($c)-64);return$i-1;}
	private function batches_table(){global$wpdb;return$wpdb->prefix.'ufsc_lc_ffst_import_batches';}
	private function rows_table(){global$wpdb;return$wpdb->prefix.'ufsc_lc_ffst_import_rows';}
	private function table_exists($t){global$wpdb;return$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t))===$t;}
	private function column_exists($t,$c){global$wpdb;return(bool)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t} LIKE %s",$c));}
}
