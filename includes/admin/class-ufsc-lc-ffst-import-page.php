<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class UFSC_LC_FFST_Import_Page {
	const PAGE_SLUG='ufsc-lc-import-ffst';

	public function register(){
		add_action('admin_menu',array($this,'register_menu'),35);
		add_action('admin_post_ufsc_lc_ffst_stage',array($this,'handle_stage'));
		add_action('admin_post_ufsc_lc_ffst_apply',array($this,'handle_apply'));
		add_action('admin_post_ufsc_lc_ffst_review',array($this,'handle_review'));
		add_action('admin_post_ufsc_lc_ffst_recheck',array($this,'handle_recheck'));
	}

	public function register_menu(){
		$hook=add_submenu_page(
			UFSC_LC_Plugin::PARENT_SLUG,
			__('Import FFST','ufsc-licence-competition'),
			__('Import FFST','ufsc-licence-competition'),
			UFSC_LC_Capabilities::get_import_capability(),
			self::PAGE_SLUG,
			array($this,'render')
		);
		if($hook) UFSC_LC_Admin_Assets::register_page($hook);
	}

	public function handle_stage(){
		if(!UFSC_LC_Capabilities::user_can_import()) wp_die(esc_html__('Accès refusé.','ufsc-licence-competition'),'',array('response'=>403));
		check_admin_referer('ufsc_lc_ffst_stage','ufsc_lc_ffst_nonce');
		$file=isset($_FILES['ffst_file'])?$_FILES['ffst_file']:array();
		$season=isset($_POST['season_end_year'])?absint($_POST['season_end_year']):$this->active_season_end_year();
		$service=new UFSC_LC_FFST_Import_Service();
		$result=$service->stage_upload($file,$season);
		if(is_wp_error($result)) $this->redirect_notice('error',$result->get_error_message());
		wp_safe_redirect(add_query_arg(array('page'=>self::PAGE_SLUG,'batch_id'=>absint($result),'ufsc_lc_notice'=>'preview'),admin_url('admin.php')));
		exit;
	}

	public function handle_apply(){
		if(!UFSC_LC_Capabilities::user_can_import()) wp_die(esc_html__('Accès refusé.','ufsc-licence-competition'),'',array('response'=>403));
		$batch_id=isset($_POST['batch_id'])?absint($_POST['batch_id']):0;
		check_admin_referer('ufsc_lc_ffst_apply_'.$batch_id,'ufsc_lc_ffst_apply_nonce');
		$selected=isset($_POST['ffst_selected'])?(array)wp_unslash($_POST['ffst_selected']):array();
		$service=new UFSC_LC_FFST_Import_Service();
		$result=$service->apply_selected_rows($batch_id,$selected);
		if(is_wp_error($result)) $this->redirect_notice('error',$result->get_error_message(),$batch_id);
		$this->redirect_notice('success',sprintf(_n('%d licence FFST synchronisée.','%d licences FFST synchronisées.',(int)$result,'ufsc-licence-competition'),(int)$result),$batch_id);
	}

	public function handle_review(){
		if(!UFSC_LC_Capabilities::user_can_import()) wp_die(esc_html__('Accès refusé.','ufsc-licence-competition'),'',array('response'=>403));
		$batch_id=isset($_POST['batch_id'])?absint($_POST['batch_id']):0;
		$row_id=isset($_POST['row_id'])?absint($_POST['row_id']):0;
		$target_id=isset($_POST['licence_id'])?absint($_POST['licence_id']):0;
		check_admin_referer('ufsc_lc_ffst_review_'.$batch_id.'_'.$row_id,'ufsc_lc_ffst_review_nonce');
		$service=new UFSC_LC_FFST_Import_Service();
		$result=$service->confirm_row_match($batch_id,$row_id,$target_id);
		if(is_wp_error($result))$this->redirect_notice('error',$result->get_error_message(),$batch_id);
		$this->redirect_notice('success',__('Rapprochement confirmé dans le lot. Aucune licence UFSC Gestion n’a encore été modifiée.','ufsc-licence-competition'),$batch_id);
	}

	public function handle_recheck(){
		if(!UFSC_LC_Capabilities::user_can_import())wp_die(esc_html__('Accès refusé.','ufsc-licence-competition'),'',array('response'=>403));
		$batch_id=isset($_POST['batch_id'])?absint($_POST['batch_id']):0;
		check_admin_referer('ufsc_lc_ffst_recheck_'.$batch_id,'ufsc_lc_ffst_recheck_nonce');
		$service=new UFSC_LC_FFST_Import_Service();
		$result=$service->repair_legacy_batch($batch_id);
		if(is_wp_error($result))$this->redirect_notice('error',$result->get_error_message(),$batch_id);
		$this->redirect_notice('success',sprintf(__('%d ligne(s) historiques réexaminée(s). Les données UFSC Gestion n’ont pas été modifiées.','ufsc-licence-competition'),$result),$batch_id);
	}

	public function render(){
		if(!UFSC_LC_Capabilities::user_can_import()) wp_die(esc_html__('Accès refusé.','ufsc-licence-competition'));
		$service=new UFSC_LC_FFST_Import_Service();
		$service->create_tables();
		$batch_id=isset($_GET['batch_id'])?absint($_GET['batch_id']):0;
		$batch=$batch_id?$service->get_batch($batch_id):null;
		$per_page=100;
		$current_page=isset($_GET['ffst_page'])?max(1,absint($_GET['ffst_page'])):1;
		$offset=($current_page-1)*$per_page;
		$rows=$batch?$service->get_batch_rows($batch_id,$per_page,$offset):array();
		$actual_rows=$batch?$service->get_batch_row_count($batch_id):0;
		$pages=$actual_rows?max(1,(int)ceil($actual_rows/$per_page)):1;
		if($current_page>$pages){
			$current_page=$pages;
			$offset=($current_page-1)*$per_page;
			$rows=$batch?$service->get_batch_rows($batch_id,$per_page,$offset):array();
		}
		$counts_consistent=$batch?((int)$batch->total_rows===(int)$actual_rows):true;
		$duplicates=$batch?$service->get_duplicate_flags($batch_id):array('numbers'=>array(),'targets'=>array());
		$review_row_id=isset($_GET['review_row'])?absint($_GET['review_row']):0;
		$review_row=($batch&&$review_row_id)?$service->get_row_in_batch($batch_id,$review_row_id):null;
		$review_candidates=$review_row?$service->get_candidates_for_row($batch_id,$review_row_id):array();
		$legacy_pending=$batch?$service->get_legacy_unreviewed_count($batch_id):0;
		$selectable_total=0;
		foreach($rows as $row){
			$number=strtoupper(trim((string)$row->ffst_number));
			if('matched'===$row->match_status && !isset($duplicates['numbers'][$number]) && !isset($duplicates['targets'][(int)$row->match_licence_id]))$selectable_total++;
		}

		$season=$this->active_season_end_year();
		?>
		<div class="wrap ufsc-lc-admin">
			<h1><?php esc_html_e('Import FFST — prévisualisation sécurisée','ufsc-licence-competition'); ?></h1>
			<?php ufsc_lc_render_scope_badge(); ?>
			<?php $this->render_notice(); ?>
			<div class="notice notice-info inline"><p><strong><?php esc_html_e('Flux sécurisé :','ufsc-licence-competition'); ?></strong>
			<?php esc_html_e('fichier → analyse → rapprochement avec UFSC Gestion → contrôle → validation. Aucune licence UFSC n’est créée automatiquement et aucun N° FFST existant différent n’est écrasé.','ufsc-licence-competition'); ?></p></div>

			<div class="card" style="max-width:1100px;padding:20px;margin-top:18px">
				<h2><?php esc_html_e('1. Charger le fichier fourni par la FFST','ufsc-licence-competition'); ?></h2>
				<p><?php esc_html_e('Formats acceptés : CSV, XLSX ou PDF texte. Pour un PDF scanné/non structuré, utilisez de préférence le tableur FFST.','ufsc-licence-competition'); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="ufsc_lc_ffst_stage">
					<?php wp_nonce_field('ufsc_lc_ffst_stage','ufsc_lc_ffst_nonce'); ?>
					<table class="form-table"><tbody>
						<tr><th><label for="ffst_file"><?php esc_html_e('Fichier FFST','ufsc-licence-competition'); ?></label></th>
						<td><input type="file" id="ffst_file" name="ffst_file" accept=".csv,.xlsx,.pdf" required></td></tr>
						<tr><th><label for="season_end_year"><?php esc_html_e('Saison','ufsc-licence-competition'); ?></label></th>
						<td><input type="number" id="season_end_year" name="season_end_year" min="2020" max="2100" value="<?php echo esc_attr($season); ?>">
						<p class="description"><?php echo esc_html(sprintf(__('Saison active : %d–%d (01/08 → 31/07).','ufsc-licence-competition'),$season-1,$season)); ?></p></td></tr>
					</tbody></table>
					<?php submit_button(__('Analyser sans modifier les licences','ufsc-licence-competition'),'primary','submit',false); ?>
				</form>
			</div>

			<?php if($batch): ?>
				<div class="card" style="max-width:1400px;padding:20px;margin-top:18px">
					<h2><?php echo esc_html(sprintf(__('2. Contrôle du lot #%d — %s','ufsc-licence-competition'),$batch->id,$batch->file_name)); ?></h2>
					<p>
						<span class="button disabled"><?php echo esc_html(sprintf(__('Total : %d','ufsc-licence-competition'),$batch->total_rows)); ?></span>
						<span class="button disabled"><?php echo esc_html(sprintf(__('Trouvées : %d','ufsc-licence-competition'),$batch->matched_rows)); ?></span>
						<span class="button disabled"><?php echo esc_html(sprintf(__('Ambiguës : %d','ufsc-licence-competition'),$batch->ambiguous_rows)); ?></span>
						<span class="button disabled"><?php echo esc_html(sprintf(__('Conflits : %d','ufsc-licence-competition'),$batch->conflict_rows)); ?></span>
						<span class="button disabled"><?php echo esc_html(sprintf(__('Introuvables : %d','ufsc-licence-competition'),$batch->not_found_rows)); ?></span>
						<span class="button disabled"><?php echo esc_html(sprintf(__('Déjà appliquées : %d','ufsc-licence-competition'),$batch->applied_rows)); ?></span>
						<span class="button disabled"><?php echo esc_html(sprintf(__('Doublons : %d','ufsc-licence-competition'),count($duplicates['numbers'])+count($duplicates['targets']))); ?></span>
					</p>
					<style>
						.ufsc-lc-ffst-import-grid{min-width:1350px;font-size:12px}
						.ufsc-lc-ffst-import-record{display:grid!important;grid-template-columns:40px 40px 105px 125px 100px 95px minmax(130px,1fr) 90px 100px minmax(170px,1.4fr) 115px;align-items:start;visibility:visible!important;opacity:1!important;height:auto!important;min-height:42px;position:relative;box-sizing:border-box;border-bottom:1px solid #dcdcde;background:#fff}
						.ufsc-lc-ffst-import-record:nth-of-type(even){background:#f6f7f7}
						.ufsc-lc-ffst-import-record>div{padding:11px 9px;word-break:break-word}
						.ufsc-lc-ffst-import-header{font-weight:700;background:#f0f0f1!important;border-bottom:2px solid #c3c4c7}
						.ufsc-lc-ffst-import-record:first-of-type{display:grid!important;visibility:visible!important}
					</style>
					<?php if($legacy_pending>0 && in_array($batch->status,array('preview','partial'),true)): ?>
						<div class="notice notice-warning inline"><p><?php echo esc_html(sprintf(__('%d ligne(s) issues d’un ancien import possèdent un statut incorrect. Réexaminez-les avant de les sélectionner.','ufsc-licence-competition'),$legacy_pending)); ?></p>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:10px 0">
								<input type="hidden" name="action" value="ufsc_lc_ffst_recheck">
								<input type="hidden" name="batch_id" value="<?php echo esc_attr($batch_id); ?>">
								<?php wp_nonce_field('ufsc_lc_ffst_recheck_'.$batch_id,'ufsc_lc_ffst_recheck_nonce'); ?>
								<?php submit_button(__('Réexaminer les anciennes lignes sans modifier UFSC Gestion','ufsc-licence-competition'),'secondary','submit',false); ?>
							</form>
						</div>
					<?php endif; ?>
					<?php if(!$counts_consistent): ?>
						<div class="notice notice-error inline"><p><?php echo esc_html(sprintf(__('Attention : %1$d licences annoncées, mais %2$d lignes enregistrées. Validation désactivée jusqu’à résolution.','ufsc-licence-competition'),(int)$batch->total_rows,(int)$actual_rows)); ?></p></div>
					<?php endif; ?>
					<p class="description">
						<?php echo esc_html(sprintf(__('Lignes stockées : %1$d — affichage %2$d à %3$d (page %4$d sur %5$d).','ufsc-licence-competition'),(int)$actual_rows,$actual_rows?$offset+1:0,min($actual_rows,$offset+count($rows)),$current_page,$pages)); ?>
					</p>
					<div style="overflow-x:auto;max-height:600px;overflow-y:auto">
						<div class="ufsc-lc-ffst-import-grid" role="table" aria-label="<?php esc_attr_e('Prévisualisation des licences FFST','ufsc-licence-competition'); ?>">
							<div class="ufsc-lc-ffst-import-record ufsc-lc-ffst-import-header" role="row">
								<div role="columnheader"><input type="checkbox" id="ffst-select-all" aria-label="<?php esc_attr_e('Sélectionner les licences sûres sur cette page','ufsc-licence-competition'); ?>" onchange="document.querySelectorAll('.ffst-line-check').forEach(function(el){el.checked=this.checked}.bind(this))"></div>
								<div role="columnheader">#</div><div role="columnheader"><?php esc_html_e('N° FFST','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Nom','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Prénom','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Naissance','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Club','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Licence UFSC','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('État','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Contrôle / doublons','ufsc-licence-competition'); ?></div>
								<div role="columnheader"><?php esc_html_e('Action','ufsc-licence-competition'); ?></div>
							</div>
							<?php foreach($rows as $row): ?>
								<?php
									$dup_number=isset($duplicates['numbers'][strtoupper(trim((string)$row->ffst_number))]);
									$dup_target=$row->match_licence_id && isset($duplicates['targets'][(int)$row->match_licence_id]);
									$can_select='matched'===$row->match_status && !$dup_number && !$dup_target && 'applied'!==$batch->status;
								?>
								<div class="ufsc-lc-ffst-import-record" role="row" data-ffst-row="<?php echo esc_attr(absint($row->row_index)); ?>">
									<div role="cell"><?php if($can_select): ?><input class="ffst-line-check" type="checkbox" form="ffst-apply-form" name="ffst_selected[]" value="<?php echo esc_attr($row->id); ?>" aria-label="<?php echo esc_attr(sprintf(__('Sélectionner la licence FFST %s','ufsc-licence-competition'),$row->ffst_number)); ?>"><?php else: ?>—<?php endif; ?></div>
									<div role="cell"><?php echo esc_html($row->row_index); ?></div>
									<div role="cell"><strong><?php echo esc_html($row->ffst_number?:'—'); ?></strong></div>
									<div role="cell"><?php echo esc_html($row->last_name?:'—'); ?></div>
									<div role="cell"><?php echo esc_html($row->first_name?:'—'); ?></div>
									<div role="cell"><?php echo esc_html($row->birthdate?:'—'); ?></div>
									<div role="cell"><?php echo esc_html($row->club_name?:'—'); ?></div>
									<div role="cell"><?php echo $row->match_licence_id?esc_html('#'.(int)$row->match_licence_id):'—'; ?></div>
									<div role="cell"><?php echo $this->status_badge($row->match_status); ?></div>
									<div role="cell">
										<?php echo esc_html($row->match_message); ?>
										<?php if($dup_number): ?><strong style="display:block;color:#b32d2e"><?php esc_html_e('Doublon du N° FFST dans ce fichier — sélection bloquée.','ufsc-licence-competition'); ?></strong><?php endif; ?>
										<?php if($dup_target): ?><strong style="display:block;color:#b32d2e"><?php esc_html_e('Plusieurs lignes visent la même licence UFSC — sélection bloquée.','ufsc-licence-competition'); ?></strong><?php endif; ?>
									</div>
									<div role="cell"><?php if('applied'!==$row->match_status): ?>
										<a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE_SLUG,'batch_id'=>$batch_id,'ffst_page'=>$current_page,'review_row'=>$row->id),admin_url('admin.php'))); ?>"><?php esc_html_e('Vérifier / lier','ufsc-licence-competition'); ?></a>
									<?php else: ?><?php esc_html_e('Appliquée','ufsc-licence-competition'); ?><?php endif; ?></div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
					<?php if($pages>1): ?>
						<nav aria-label="<?php esc_attr_e('Pages des licences du lot','ufsc-licence-competition'); ?>" style="margin-top:14px">
							<?php for($page_number=1;$page_number<=$pages;$page_number++): ?>
								<a class="button <?php echo $page_number===$current_page?'button-primary':''; ?>" href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE_SLUG,'batch_id'=>$batch_id,'ffst_page'=>$page_number),admin_url('admin.php'))); ?>"><?php echo esc_html($page_number); ?></a>
							<?php endfor; ?>
						</nav>
					<?php endif; ?>
					<?php if($review_row && 'applied'!==$review_row->match_status): ?>
						<div class="card" style="margin-top:16px;max-width:900px;padding:16px">
							<h3><?php echo esc_html(sprintf(__('Vérifier la ligne #%d — %s %s (%s)','ufsc-licence-competition'),$review_row->row_index,$review_row->last_name,$review_row->first_name,$review_row->ffst_number)); ?></h3>
							<p><?php esc_html_e('Choisissez une fiche UFSC Gestion uniquement après vérification de l’identité, de la naissance, de la saison et du club. Cette action ne modifie que le rapprochement provisoire du lot.','ufsc-licence-competition'); ?></p>
							<?php if($review_candidates): ?>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
									<input type="hidden" name="action" value="ufsc_lc_ffst_review">
									<input type="hidden" name="batch_id" value="<?php echo esc_attr($batch_id); ?>">
									<input type="hidden" name="row_id" value="<?php echo esc_attr($review_row->id); ?>">
									<?php wp_nonce_field('ufsc_lc_ffst_review_'.$batch_id.'_'.$review_row->id,'ufsc_lc_ffst_review_nonce'); ?>
									<select name="licence_id" required style="min-width:450px;max-width:100%">
										<option value=""><?php esc_html_e('Sélectionner une fiche à vérifier','ufsc-licence-competition'); ?></option>
										<?php foreach($review_candidates as $candidate): ?>
											<option value="<?php echo esc_attr($candidate->id); ?>"><?php echo esc_html(sprintf('#%d — %s %s — %s — %s — FFST : %s',$candidate->id,$candidate->nom,$candidate->prenom,$candidate->date_naissance,$candidate->club_name,$candidate->numero_licence_ffst?:'—')); ?></option>
										<?php endforeach; ?>
									</select>
									<?php submit_button(__('Confirmer le rapprochement contrôlé','ufsc-licence-competition'),'secondary','submit',false); ?>
								</form>
							<?php else: ?><p><?php esc_html_e('Aucune fiche candidate de même identité et naissance trouvée. Aucune liaison forcée possible.','ufsc-licence-competition'); ?></p><?php endif; ?>
						</div>
					<?php endif; ?>
					<?php if($counts_consistent && !$legacy_pending && 'applied'!==$batch->status && $selectable_total>0): ?>
						<form id="ffst-apply-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:18px">
							<input type="hidden" name="action" value="ufsc_lc_ffst_apply"><input type="hidden" name="batch_id" value="<?php echo esc_attr($batch->id); ?>">
							<?php wp_nonce_field('ufsc_lc_ffst_apply_'.$batch->id,'ufsc_lc_ffst_apply_nonce'); ?>
							<?php submit_button(__('Synchroniser les licences sélectionnées','ufsc-licence-competition'),'primary','submit',false,array('onclick'=>"return document.querySelectorAll('.ffst-line-check:checked').length > 0 && confirm('Confirmer uniquement les licences cochées ?');")); ?>
							<p class="description"><?php esc_html_e('Seules les cases cochées sont traitées (100 maximum par opération). Les doublons et conflits sont bloqués. La génération PDF V12 intervient après confirmation de l’enregistrement.','ufsc-licence-competition'); ?></p>
						</form>
					<?php elseif('applied'===$batch->status): ?>
						<div class="notice notice-success inline"><p><?php echo esc_html(sprintf(__('%d ligne(s) appliquée(s).','ufsc-licence-competition'),$batch->applied_rows)); ?></p></div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width:1100px;padding:20px;margin-top:18px">
				<h2><?php esc_html_e('Historique récent','ufsc-licence-competition'); ?></h2>
				<table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e('Fichier','ufsc-licence-competition'); ?></th><th><?php esc_html_e('Saison','ufsc-licence-competition'); ?></th><th><?php esc_html_e('État','ufsc-licence-competition'); ?></th><th><?php esc_html_e('Date','ufsc-licence-competition'); ?></th></tr></thead><tbody>
				<?php foreach($service->get_recent_batches(10) as $item): ?>
					<tr><td><a href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE_SLUG,'batch_id'=>$item->id),admin_url('admin.php'))); ?>">#<?php echo esc_html($item->id); ?></a></td>
					<td><?php echo esc_html($item->file_name); ?></td><td><?php echo esc_html(($item->season_end_year-1).'-'.$item->season_end_year); ?></td>
					<td><?php echo esc_html($item->status); ?></td><td><?php echo esc_html($item->created_at); ?></td></tr>
				<?php endforeach; ?></tbody></table>
			</div>
		</div>
		<?php
	}

	private function status_badge($status){
		$map=array('matched'=>array('Trouvée','#e7f6ec','#166534'),'applied'=>array('Appliquée','#dcfce7','#166534'),'ambiguous'=>array('À contrôler','#fff7d6','#92400e'),'conflict'=>array('Conflit','#fee2e2','#991b1b'),'not_found'=>array('Introuvable','#f3f4f6','#4b5563'),'0'=>array('À réexaminer','#fff4ce','#92400e'));
		$v=isset($map[$status])?$map[$status]:array($status,'#f3f4f6','#374151');
		return '<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:'.esc_attr($v[1]).';color:'.esc_attr($v[2]).';font-weight:700">'.esc_html($v[0]).'</span>';
	}
	private function active_season_end_year(){return function_exists('ufsc_lc_get_active_season_end_year')?absint(ufsc_lc_get_active_season_end_year()):2027;}
	private function render_notice(){
		if(empty($_GET['ufsc_lc_notice'])&&empty($_GET['message']))return;
		$type=isset($_GET['type'])?sanitize_key(wp_unslash($_GET['type'])):'success';$msg=isset($_GET['message'])?sanitize_text_field(wp_unslash($_GET['message'])):__('Analyse terminée : vérifiez les lignes avant validation.','ufsc-licence-competition');
		printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>',esc_attr(in_array($type,array('success','error','warning','info'),true)?$type:'info'),esc_html($msg));
	}
	private function redirect_notice($type,$message,$batch_id=0){
		$args=array('page'=>self::PAGE_SLUG,'type'=>$type,'message'=>$message);if($batch_id)$args['batch_id']=$batch_id;
		wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));exit;
	}
}
