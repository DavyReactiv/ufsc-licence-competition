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
			),array('%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s'));
			if(false===$inserted){
				return new WP_Error('ffst_stage_row_insert_failed',sprintf(__('Impossible d’enregistrer la ligne FFST #%d en prévisualisation. Aucune donnée métier n’a été modifiée.','ufsc-licence-competition'),$i));
			}

			// Protect against positional wpdb format mismatches. The old format
			// accidentally cast match_status to 0 instead of retaining its text.
			// Validate the persisted row before the lot can become actionable.
			$staged=$wpdb->get_row($wpdb->prepare(
				"SELECT id,match_status,match_licence_id,ffst_number FROM {$this->rows_table()} WHERE batch_id=%d AND row_index=%d LIMIT 1",
				$batch_id,$i
			));
			if(!$staged || $staged->match_status!==$m['status']
				|| absint($staged->match_licence_id)!==absint($m['licence_id'])
				|| (string)$staged->ffst_number!==(string)$d['ffst_number']){
				return new WP_Error(
					'ffst_staging_row_verification_failed',
					sprintf(__('La ligne FFST #%d a été enregistrée de manière incohérente. Lot bloqué sans modification des licences UFSC Gestion.','ufsc-licence-competition'),$i)
				);
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
		if($actual_total!==$i || array_sum($actual)!==$i){
			return new WP_Error('ffst_stage_integrity_mismatch',sprintf(__('Intégrité du lot FFST impossible : %1$d ligne(s) analysée(s), %2$d enregistrée(s). Aucune donnée métier n’a été modifiée.','ufsc-licence-competition'),$i,$actual_total));
		}
		$batch_updated=$wpdb->update($this->batches_table(),array(
			'total_rows'=>$actual_total,
			'matched_rows'=>$actual[self::MATCHED],
			'ambiguous_rows'=>$actual[self::AMBIGUOUS],
			'conflict_rows'=>$actual[self::CONFLICT],
			'not_found_rows'=>$actual[self::NOT_FOUND]
		),array('id'=>$batch_id),array('%d','%d','%d','%d','%d'),array('%d'));
		if(false===$batch_updated){
			return new WP_Error('ffst_batch_counter_update_failed',__('Les compteurs du lot FFST n’ont pas pu être enregistrés. Aucune licence métier modifiée.','ufsc-licence-competition'));
		}
		return $batch_id;
	}

	public function apply_batch($batch_id){
		global $wpdb; $batch_id=absint($batch_id); $batch=$this->get_batch($batch_id);
		if(!$batch) return new WP_Error('ffst_batch_missing',__('Lot introuvable.','ufsc-licence-competition'));
		if('applied'===$batch->status) return new WP_Error('ffst_batch_applied',__('Ce lot est déjà appliqué.','ufsc-licence-competition'));
		$lt=$wpdb->prefix.'ufsc_licences';
		if(!$this->column_exists($lt,'numero_licence_ffst')) return new WP_Error('ffst_column_missing',__('Le champ numero_licence_ffst manque dans UFSC Gestion. Aucune donnée modifiée.','ufsc-licence-competition'));
		$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->rows_table()} WHERE batch_id=%d AND match_status=%s ORDER BY row_index",$batch_id,self::MATCHED));
		if(!$rows) return new WP_Error('ffst_no_safe_rows',__('Aucune ligne sûre à appliquer.','ufsc-licence-competition'));

		// Preflight everything before the first write. This also protects hosts
		// where the master table does not support SQL transactions.
		$plan=array();
		foreach($rows as $row){
			$id=absint($row->match_licence_id); $incoming=trim((string)$row->ffst_number);
			$current=trim((string)$wpdb->get_var($wpdb->prepare("SELECT numero_licence_ffst FROM {$lt} WHERE id=%d LIMIT 1",$id)));
			if(''===$incoming || (''!==$current && 0!==strcasecmp($current,$incoming))){
				return new WP_Error('ffst_concurrent_conflict',sprintf(__('Conflit détecté sur la licence #%d. Aucune donnée n’a été modifiée.','ufsc-licence-competition'),$id));
			}
			$plan[]=array('row'=>$row,'licence_id'=>$id,'current'=>$current,'incoming'=>$incoming);
		}

		$tx=false!==$wpdb->query('START TRANSACTION'); $changed=array(); $now=current_time('mysql');
		foreach($plan as $item){
			if(''===$item['current']){
				$result=$wpdb->update($lt,array('numero_licence_ffst'=>$item['incoming']),array('id'=>$item['licence_id']),array('%s'),array('%d'));
				if(false===$result){
					if($tx){$wpdb->query('ROLLBACK');}
					else{foreach(array_reverse($changed) as $done){$wpdb->update($lt,array('numero_licence_ffst'=>$done['previous']),array('id'=>$done['id']),array('%s'),array('%d'));}}
					return new WP_Error('ffst_update_failed',__('Échec de mise à jour FFST. Les écritures déjà effectuées ont été annulées.','ufsc-licence-competition'));
				}
				$changed[]=array('id'=>$item['licence_id'],'previous'=>$item['current']);
			}
		}
		if($tx) $wpdb->query('COMMIT');

		// Add-on metadata and PDF regeneration are intentionally post-commit:
		// they must never roll back the canonical UFSC Gestion synchronisation.
		foreach($plan as $item){
			$row=$item['row']; $this->write_pdf_meta($item['licence_id'],$row);
			$wpdb->update($this->rows_table(),array('match_status'=>self::APPLIED,'applied_at'=>$now),array('id'=>absint($row->id)),array('%s','%s'),array('%d'));
			do_action('ufsc_lc_ffst_import_synced',$item['licence_id'],'ffst-v2-'.$batch_id);
		}
		$applied=count($plan);
		$wpdb->update($this->batches_table(),array('status'=>'applied','applied_rows'=>$applied,'applied_at'=>$now),array('id'=>$batch_id),array('%s','%d','%s'),array('%d'));
		if(function_exists('ufsc_lc_bump_cache_version')) ufsc_lc_bump_cache_version('status',0);
		return $applied;
	}

	public function get_batch($id){global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->batches_table()} WHERE id=%d LIMIT 1",absint($id)));}
	public function get_batch_rows($id,$limit=250){global $wpdb; return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->rows_table()} WHERE batch_id=%d ORDER BY row_index LIMIT %d",absint($id),max(1,min(1000,absint($limit)))));}
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
