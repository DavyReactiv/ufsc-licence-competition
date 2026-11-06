<?php
namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Premium CSV event import (one event per file).
 * Exclusive event flow; never writes to fights or entries.
 */
final class Premium_Event_Import_Page {
    const SLUG = 'ufsc-premium-event-import';
    const MAX_BYTES = 2097152;

    public static function columns(): array {
        return array(
            'name', 'type', 'discipline', 'season',
            'event_start_datetime', 'event_end_datetime',
            'venue_name', 'venue_address1', 'venue_address2',
            'venue_postcode', 'venue_city', 'venue_region',
            'organizer_club_id', 'organizer_club_name',
            'contact_name', 'contact_phone', 'contact_email',
            'registration_open_datetime', 'registration_close_datetime',
            'registration_deadline', 'weighin_start_datetime', 'weighin_end_datetime',
            'description', 'notes_complementaires', 'categories_combat', 'status',
        );
    }
    private static function extras(): array {
        return array('contact_name','description','notes_complementaires','categories_combat');
    }
    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
        add_action( 'admin_post_ufsc_premium_event_template', array( __CLASS__, 'template' ) );
        add_action( 'admin_post_ufsc_premium_event_preview', array( __CLASS__, 'preview' ) );
        add_action( 'admin_post_ufsc_premium_event_create', array( __CLASS__, 'create' ) );
        add_action( 'admin_post_ufsc_premium_event_export', array( __CLASS__, 'export' ) );
    }
    private static function auth( int $id = 0 ): void {
        if ( ! current_user_can( \UFSC\Competitions\Capabilities::get_edit_capability() ) ) {
            wp_die( 'Accès refusé.', '', array( 'response' => 403 ) );
        }
        if ( $id && function_exists( 'ufsc_lc_current_user_can_access_competition' ) &&
            ! ufsc_lc_current_user_can_access_competition( $id ) ) {
            wp_die( 'Événement hors périmètre.', '', array( 'response' => 403 ) );
        }
    }
    public static function menu(): void {
        add_submenu_page( 'ufsc-competitions', 'Import événements premium', 'Import événements premium',
            \UFSC\Competitions\Capabilities::get_edit_capability(), self::SLUG, array( __CLASS__, 'render' ) );
    }
    private static function endpoint( string $action, int $id = 0 ): string {
        $url = admin_url( 'admin-post.php?action=' . $action );
        if ($id) { $url = add_query_arg('competition_id', $id, $url); }
        return wp_nonce_url( $url, $action );
    }
    public static function render(): void {
        self::auth();
        $draft = get_transient( 'ufsc_premium_event_draft_' . get_current_user_id() );
        $notice = get_transient( 'ufsc_premium_event_notice_' . get_current_user_id() );
        delete_transient( 'ufsc_premium_event_notice_' . get_current_user_id() );
        echo '<div class="wrap"><h1>Fiche événement premium — import / export</h1>';
        echo '<p>Importez une seule fiche événement par CSV. L’événement sera créé en <strong>brouillon</strong> uniquement après confirmation. Les plateaux et tarifs sont gérés indépendamment.</p>';
        if ($notice) { echo '<div class="notice notice-info inline"><p>' . esc_html($notice) . '</p></div>'; }
        echo '<p><a class="button" href="' . esc_url(self::endpoint('ufsc_premium_event_template')) . '">Télécharger modèle CSV événement</a></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="ufsc_premium_event_preview">';
        wp_nonce_field('ufsc_premium_event_preview');
        echo '<p><input type="file" name="event_csv" accept=".csv,text/csv" required></p>';
        submit_button('Prévisualiser et contrôler');
        echo '</form>';
        if (is_array($draft)) {
            echo '<h2>Prévisualisation</h2><table class="widefat striped"><tbody>';
            foreach ($draft['row'] as $k=>$v) {
                echo '<tr><th style="width:260px">' . esc_html($k) . '</th><td>' . esc_html($v) . '</td></tr>';
            }
            echo '</tbody></table>';
            if (empty($draft['errors'])) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="ufsc_premium_event_create">';
                wp_nonce_field('ufsc_premium_event_create');
                submit_button('Confirmer la création du nouvel événement');
                echo '</form>';
            } else {
                foreach($draft['errors'] as $error) {
                    echo '<div class="notice notice-error inline"><p>' . esc_html($error) . '</p></div>';
                }
            }
        }
        echo '<h2>Export d’une fiche existante</h2><form method="get" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ufsc_premium_event_export">';
        wp_nonce_field('ufsc_premium_event_export');
        echo '<label>ID de l’événement <input type="number" name="competition_id" min="1" required></label> ';
        echo '<button class="button">Exporter CSV</button></form></div>';
    }
    private static function csv( string $file, array $rows ): void {
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        echo "\xEF\xBB\xBF";
        $f=fopen('php://output','w');
        foreach($rows as $row){ fputcsv($f,$row,';'); }
        fclose($f); exit;
    }
    public static function template(): void {
        self::auth(); check_admin_referer('ufsc_premium_event_template');
        self::csv('ufsc-modele-evenement-premium.csv',array(self::columns()));
    }
    private static function is_datetime(string $v): bool {
        if ($v==='') return true;
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v);
        return $d && $d->format('Y-m-d H:i:s')===$v;
    }
    private static function is_date(string $v): bool {
        if ($v==='') return true;
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        return $d && $d->format('Y-m-d')===$v;
    }
    private static function validate(array $r): array {
        $errors=array();
        foreach(array('name','type','discipline','season','event_start_datetime') as $required) {
            if(trim((string)($r[$required]??''))==='') $errors[]='Champ obligatoire manquant : '.$required;
        }
        foreach(array('event_start_datetime','event_end_datetime','registration_open_datetime',
            'registration_close_datetime','weighin_start_datetime','weighin_end_datetime') as $k) {
            if(!self::is_datetime((string)($r[$k]??''))) $errors[]='Date/heure invalide : '.$k.' (AAAA-MM-JJ HH:MM:SS)';
        }
        if(!self::is_date((string)($r['registration_deadline']??''))) $errors[]='Date de forclusion invalide (AAAA-MM-JJ).';
        if(($r['event_end_datetime']??'')!=='' && ($r['event_start_datetime']??'')!=='' &&
            $r['event_end_datetime']<$r['event_start_datetime']) $errors[]='Fin avant début.';
        if(($r['registration_close_datetime']??'')!=='' && ($r['registration_open_datetime']??'')!=='' &&
            $r['registration_close_datetime']<$r['registration_open_datetime']) $errors[]='Clôture avant ouverture des inscriptions.';
        if(!preg_match('/^\d{4}-\d{4}$/',(string)($r['season']??''))) $errors[]='Saison attendue : 2026-2027.';
        if(($r['contact_email']??'')!=='' && !is_email($r['contact_email'])) $errors[]='Email de contact invalide.';
        if(($r['organizer_club_id']??'')!=='' && !ctype_digit((string)$r['organizer_club_id'])) $errors[]='ID club non numérique.';
        if(!in_array(($r['status']??'draft'),array('draft','open','closed'),true)) $errors[]='Statut invalide : draft/open/closed.';
        return $errors;
    }
    public static function preview(): void {
        self::auth(); check_admin_referer('ufsc_premium_event_preview');
        $file=$_FILES['event_csv']??array();
        if((int)($file['error']??1)!==UPLOAD_ERR_OK || (int)($file['size']??0)>self::MAX_BYTES ||
           (int)($file['size']??0)<1 || !is_uploaded_file($file['tmp_name']??'') ||
           strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION))!=='csv') {
            wp_die('Fichier CSV invalide.', '', array('response'=>400));
        }
        $f=fopen($file['tmp_name'],'rb');
        $first=$f?fgets($f):false;
        if(!$first){ if($f)fclose($f); wp_die('CSV vide.', '', array('response'=>400)); }
        $sep=substr_count($first,';')>=substr_count($first,',')?';':',';
        rewind($f);
        $heads=fgetcsv($f,0,$sep);
        $heads=is_array($heads)?array_map('trim',$heads):array();
        if(isset($heads[0])) $heads[0]=preg_replace('/^\xEF\xBB\xBF/','',$heads[0]);
        if($heads!==self::columns()){ fclose($f); wp_die('Colonnes non conformes au modèle premium.', '', array('response'=>400)); }
        $values=fgetcsv($f,0,$sep);
        $extra=fgetcsv($f,0,$sep);
        fclose($f);
        if(!is_array($values)||count($values)!==count($heads) || ($extra!==false && count(array_filter($extra,static function($v){return trim((string)$v)!=='';}))>0)) {
            wp_die('Une et une seule ligne événement est attendue.', '', array('response'=>400));
        }
        $row=array_combine($heads,array_map('sanitize_text_field',$values));
        $errors=self::validate($row);
        set_transient('ufsc_premium_event_draft_'.get_current_user_id(),
           array('row'=>$row,'errors'=>$errors),15*MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page='.self::SLUG)); exit;
    }
    public static function create(): void {
        self::auth(); check_admin_referer('ufsc_premium_event_create');
        $key='ufsc_premium_event_draft_'.get_current_user_id();
        $draft=get_transient($key);
        if(!is_array($draft)||!empty($draft['errors'])) wp_die('Prévisualisation valide requise.', '', array('response'=>400));
        $row=$draft['row'];
        $errors=self::validate($row);
        if($errors) wp_die(esc_html(implode(' / ',$errors)),'',array('response'=>400));
        global $wpdb;
        $table=Db::competitions_table();
        $fields=array_diff_key($row,array_flip(self::extras()));
        unset($fields['status']); // always draft, never publish by import
        $fields=array_filter($fields,static function($v){return $v!=='';});
        $fields['status']='draft';
        $fields['created_by']=get_current_user_id(); $fields['updated_by']=get_current_user_id();
        $fields['created_at']=current_time('mysql'); $fields['updated_at']=current_time('mysql');
        // Drop unrecognized columns for backwards compatibility with old installs.
        $actual=$wpdb->get_col("SHOW COLUMNS FROM {$table}",0);
        $fields=array_intersect_key($fields,array_flip((array)$actual));
        $ok=$wpdb->insert($table,$fields);
        if(!$ok) wp_die('La création de l’événement a échoué. Aucun plateau modifié.', '', array('response'=>500));
        $id=(int)$wpdb->insert_id;
        $extra=array();
        foreach(self::extras() as $keyname) $extra[$keyname]=(string)($row[$keyname]??'');
        update_option('ufsc_competition_premium_meta_'.$id,$extra,false);
        delete_transient($key);
        set_transient('ufsc_premium_event_notice_'.get_current_user_id(),'Événement brouillon #'.$id.' créé. La grille tarifaire peut être importée séparément.',120);
        wp_safe_redirect(admin_url('admin.php?page='.self::SLUG)); exit;
    }
    public static function export(): void {
        $id=isset($_GET['competition_id'])?absint($_GET['competition_id']):0;
        self::auth($id); check_admin_referer('ufsc_premium_event_export');
        if(!$id) wp_die('ID manquant.','',array('response'=>400));
        global $wpdb; $table=Db::competitions_table();
        $event=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND deleted_at IS NULL LIMIT 1",$id),ARRAY_A);
        if(!$event) wp_die('Événement introuvable.','',array('response'=>404));
        $meta=get_option('ufsc_competition_premium_meta_'.$id,array());
        $line=array();
        foreach(self::columns() as $col) $line[]=(string)($meta[$col]??$event[$col]??'');
        self::csv('ufsc-evenement-'.$id.'.csv',array(self::columns(),$line));
    }
}
