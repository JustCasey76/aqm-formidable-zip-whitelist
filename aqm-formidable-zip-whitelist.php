<?php
/**
 * Plugin Name: AQM Formidable ZIP & State Whitelist (Hardened)
 * Description: Server-side ZIP/State allowlist for Formidable Forms. Auto-detects ZIP/State fields; error color/size controls. Hardened against Unicode/invisible chars and double-enforced on create/update.
 * Version: 1.10.22
 * Author: AQ Marketing (Justin Casey)
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

class AQM_Formidable_Location_Whitelist {
    const OPTION    = 'aqm_ff_location_whitelist';
    const PAGE_SLUG = 'aqm-ff-location-whitelist';
    const VERSION   = '1.10.22';
    private static $script_added = false;

    public function __construct() {
        // Run migration on plugin load
        add_action('plugins_loaded', [$this, 'maybe_migrate_settings'], 5);
        
        add_action('admin_menu',            [$this, 'admin_menu']);
        add_action('admin_init',            [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_head',               [$this, 'output_frontend_css'], 99);

        // Primary validation
        add_filter('frm_validate_entry',    [$this, 'validate_location'], 10, 2);

        // Extra enforcement
        add_action('frm_before_create_entry', [$this, 'enforce_or_die'], 10, 2);
        add_action('frm_before_update_entry', [$this, 'enforce_or_die'], 10, 2);

        // GitHub update checker
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_action('load-update-core.php', [$this, 'clear_update_cache']);
        add_filter('plugin_row_meta', [$this, 'add_check_update_link'], 10, 2);
        add_action('wp_ajax_aqm_check_plugin_update', [$this, 'ajax_check_update']);
        // Custom download handler for private repositories
        // Use priority 1 to ensure we intercept before WordPress tries to download
        add_filter('upgrader_pre_download', [$this, 'handle_private_repo_download'], 1, 3);
    }

    /* ---------------- Admin UI ---------------- */

    public function admin_menu() {
        add_menu_page(
            'Formidable Location Whitelist',
            'Location Whitelist',
            'manage_options',
            self::PAGE_SLUG,
            [$this,'settings_page'],
            'dashicons-location-alt',
            30
        );
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) return;
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_add_inline_script('wp-color-picker','(function($){$(function(){$(".aqm-color-field").wpColorPicker();});})(jQuery);');
        wp_add_inline_script('jquery-core','(function($){$(function(){$(document).on("change","#aqm-apply-all-forms",function(){const c=$(this).is(":checked");$(".aqm-form-check").prop("checked",c).prop("disabled",c).closest("label").css("opacity",c?"0.6":"1");});const applyAll=$("#aqm-apply-all-forms").is(":checked");if(applyAll){$(".aqm-form-check").prop("checked",true).prop("disabled",true).closest("label").css("opacity","0.6");}});})(jQuery);');
        wp_add_inline_style('wp-color-picker','.aqm-card{border:1px solid #ddd;padding:12px;margin:12px 0;border-radius:8px;background:#fff}.aqm-row{display:flex;gap:16px;flex-wrap:wrap}.aqm-col{flex:1 1 380px;min-width:300px}.aqm-muted{color:#666;font-size:12px}');
    }

    public function register_settings() {
        register_setting(self::OPTION, self::OPTION, [$this,'sanitize']);

        /* Scope */
        add_settings_section('aqm_section_forms','Step 1: Select Forms to Apply Rules',function(){echo'<p style="font-size: 13px; color: #646970;">Choose which Formidable forms should have location validation. You can apply to all forms or select specific ones.</p>';},self::OPTION);
        add_settings_field('apply_all_forms','Apply to All Forms',function(){
            $o=$this->get_options();
            printf('<label><input type="checkbox" id="aqm-apply-all-forms" name="%s[apply_all_forms]" value="1" %s /> Enforce on every form</label>',esc_attr(self::OPTION),checked(!empty($o['apply_all_forms']),true,false));
            echo '<p class="description">Leave off to target specific forms below.</p>';
        },self::OPTION,'aqm_section_forms');

        add_settings_field('selected_form_ids','Select Forms',function(){
            $o=$this->get_options(); $forms=$this->get_all_forms();
            if(empty($forms)){echo'<p>No Formidable forms found.</p>';return;}
            $apply_all=!empty($o['apply_all_forms']);
            $disabled=$apply_all?'disabled':'';
            echo'<div class="aqm-card"><div class="aqm-row" style="margin-top:8px">';
            foreach($forms as $f){
                $ck=($apply_all || in_array((int)$f['id'],array_map('intval',(array)$o['selected_form_ids']),true))?'checked':'';
                printf('<label class="aqm-col" style="%s"><input type="checkbox" class="aqm-form-check" name="%s[selected_form_ids][]" value="%d" %s %s /> <strong>%s</strong> <span class="aqm-muted">(#%d)</span></label>',
                    $apply_all?'opacity:0.6;':'',esc_attr(self::OPTION),(int)$f['id'],$ck,$disabled,esc_html($f['name']),(int)$f['id']);
            }
            echo'</div></div><p class="description">Uncheck "Enforce on every form" above to select specific forms.</p>';
        },self::OPTION,'aqm_section_forms');

        /* ZIP */
        add_settings_section('aqm_section_zip','Step 2: Configure ZIP Code Validation',function(){echo'<p style="font-size: 13px; color: #646970;">Enable ZIP validation and enter the allowed ZIP codes. The plugin will automatically find ZIP/postal fields in your selected forms.</p>';},self::OPTION);
        add_settings_field('enable_zip_validation','Enable ZIP Validation',function(){
            $o=$this->get_options();
            printf('<label><input type="checkbox" name="%s[enable_zip_validation]" value="1" %s /> Validate ZIP codes on all selected forms</label>',esc_attr(self::OPTION),checked(!empty($o['enable_zip_validation']),true,false));
            echo '<p class="description">When enabled, automatically validates any field containing "zip", "postal", "post code", or "postcode" in its name.</p>';
        },self::OPTION,'aqm_section_zip');

        add_settings_field('allowed_zips','Allowed ZIP Codes',function(){
            $o=$this->get_options();
            printf('<textarea name="%s[allowed_zips]" rows="8" class="large-text code" placeholder="One per line or comma-separated (12345 or 12345-6789)">%s</textarea>',
                esc_attr(self::OPTION),esc_textarea($o['allowed_zips']));
            echo '<p class="description">Enter ZIP codes one per line or comma-separated. Supports 5-digit (12345) and extended format (12345-6789). Examples: "12345, 02134, 12345-6789" or one per line.</p>';
        },self::OPTION,'aqm_section_zip');

        add_settings_field('zip_error_msg','ZIP Error Message',function(){
            $o=$this->get_options();
            printf('<input type="text" name="%s[zip_error_msg]" value="%s" class="regular-text" />',esc_attr(self::OPTION),esc_attr($o['zip_error_msg']));
            echo '<p class="description">This message will be shown to users if their ZIP code is not in the allowed list.</p>';
        },self::OPTION,'aqm_section_zip');

        /* State */
        add_settings_section('aqm_section_state','Step 3: Configure State Validation',function(){echo'<p style="font-size: 13px; color: #646970;">Enable state validation and enter the allowed states. The plugin will automatically find state/province fields in your selected forms.</p>';},self::OPTION);
        add_settings_field('enable_state_validation','Enable State Validation',function(){
            $o=$this->get_options();
            printf('<label><input type="checkbox" name="%s[enable_state_validation]" value="1" %s /> Validate states on all selected forms</label>',esc_attr(self::OPTION),checked(!empty($o['enable_state_validation']),true,false));
            echo '<p class="description">When enabled, automatically validates any field containing "state" or "province" in its name.</p>';
        },self::OPTION,'aqm_section_state');

        add_settings_field('allowed_states','Allowed States (US)',function(){
            $o=$this->get_options();
            printf('<input type="text" name="%s[allowed_states]" value="%s" class="regular-text" placeholder="e.g. MA, NH (full names OK)" />',
                esc_attr(self::OPTION),esc_attr($o['allowed_states']));
            echo '<p class="description">Enter state codes or full names, separated by commas (e.g., "MA, NH" or "Massachusetts, New Hampshire").</p>';
        },self::OPTION,'aqm_section_state');

        add_settings_field('state_error_msg','State Error Message',function(){
            $o=$this->get_options();
            printf('<input type="text" name="%s[state_error_msg]" value="%s" class="regular-text" />',esc_attr(self::OPTION),esc_attr($o['state_error_msg']));
            echo '<p class="description">This message will be shown to users if their state is not in the allowed list.</p>';
        },self::OPTION,'aqm_section_state');

        /* Style */
        add_settings_section('aqm_section_style','Step 4: Customize Error Messages (Optional)',function(){echo'<p style="font-size: 13px; color: #646970;">Customize how error messages appear to users when their location is not allowed.</p>';},self::OPTION);
        add_settings_field('error_color','Error Text Color',function(){ 
            $o=$this->get_options(); 
            printf('<input type="text" class="aqm-color-field" name="%s[error_color]" value="%s" data-default-color="#C4042D" />',esc_attr(self::OPTION),esc_attr($o['error_color']));
            echo '<p class="description">Choose the color for error messages displayed to users.</p>';
        },self::OPTION,'aqm_section_style');
        add_settings_field('error_font_size','Error Font Size (px)',function(){ 
            $o=$this->get_options(); 
            printf('<input type="number" name="%s[error_font_size]" value="%s" min="10" max="40" step="1" />',esc_attr(self::OPTION),esc_attr($o['error_font_size']));
            echo '<p class="description">Set the font size for error messages (10-40 pixels).</p>';
        },self::OPTION,'aqm_section_style');
    }

    public function sanitize($in) {
        $o=$this->get_defaults();
        $o['apply_all_forms']=!empty($in['apply_all_forms'])?1:0;
        $sel=isset($in['selected_form_ids'])?(array)$in['selected_form_ids']:[];
        $o['selected_form_ids']=array_values(array_unique(array_map('absint',$sel)));
        $o['enable_zip_validation']=!empty($in['enable_zip_validation'])?1:0;
        $o['allowed_zips']=isset($in['allowed_zips'])?$this->normalize_zip_list($in['allowed_zips']):'';
        $o['zip_error_msg']=isset($in['zip_error_msg'])&&$in['zip_error_msg']!==''?wp_kses_post($in['zip_error_msg']):$o['zip_error_msg'];
        $o['enable_state_validation']=!empty($in['enable_state_validation'])?1:0;
        $o['allowed_states']=isset($in['allowed_states'])?$this->normalize_state_list($in['allowed_states']):'';
        $o['state_error_msg']=isset($in['state_error_msg'])&&$in['state_error_msg']!==''?wp_kses_post($in['state_error_msg']):$o['state_error_msg'];
        $c=isset($in['error_color'])?sanitize_hex_color($in['error_color']):''; $o['error_color']=$c?$c:'#C4042D';
        $s=isset($in['error_font_size'])?absint($in['error_font_size']):16; $o['error_font_size']=($s>=10&&$s<=40)?$s:16;
        return $o;
    }

    private function normalize_zip_list($raw){
        $raw = (string)$raw;
        $out = [];
        // Split by newlines first, then by commas within each line
        $lines = preg_split('/\R+/', $raw);
        foreach($lines as $line){
            // Also split by commas to handle comma-separated values
            $items = preg_split('/\s*,\s*/', $line);
            foreach($items as $item){
                $z = strtoupper(trim($item));
                if($z !== '' && preg_match('/^\d{5}(-\d{4})?$/', $z)){
                    $out[] = $z;
                }
            }
        }
        return implode("\n", array_values(array_unique($out)));
    }
    private function normalize_state_list($raw){$map=$this->us_state_name_to_code_map(); $codes=[]; foreach(preg_split('/\s*,\s*/',(string)$raw) as $t){$t=strtoupper(trim($t)); if($t==='')continue; $codes[] = isset($map[$t])?$map[$t]:(preg_match('/^[A-Z]{2}$/',$t)&&in_array($t,$map,true)?$t:'');} return implode(', ',array_values(array_unique(array_filter($codes))));}

    public function settings_page() {
        echo '<div class="wrap">';
        echo '<h1>Formidable Location Whitelist</h1>';
        
        // Instructions box
        echo '<div class="notice notice-info" style="padding: 15px; margin: 20px 0; background: #f0f6fc; border-left: 4px solid #2271b1;">';
        echo '<h2 style="margin-top: 0; font-size: 16px;">📋 Quick Setup Guide</h2>';
        echo '<ol style="margin-left: 20px; line-height: 1.8;">';
        echo '<li><strong>Select Forms:</strong> Choose which Formidable forms should have location validation applied. You can select specific forms or apply to all forms.</li>';
        echo '<li><strong>Enable Validation:</strong> Check the "Enable ZIP Validation" and/or "Enable State Validation" boxes if you want to restrict submissions by location.</li>';
        echo '<li><strong>Set Allowed Values:</strong> Enter the ZIP codes and/or states you want to allow. The plugin will automatically find ZIP/State fields in your forms.</li>';
        echo '<li><strong>Customize Messages (Optional):</strong> Edit the error messages users will see if their location is not allowed.</li>';
        echo '<li><strong>Save Settings:</strong> Click "Save Settings" at the bottom to activate your configuration.</li>';
        echo '</ol>';
        echo '<p style="margin-bottom: 0;"><strong>💡 Tip:</strong> The plugin automatically detects fields containing "zip", "postal", "state", or "province" in their names. No manual field selection needed!</p>';
        echo '</div>';
        
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION); 
        do_settings_sections(self::OPTION); 
        submit_button('Save Settings');
        echo '</form>';
        echo '<p class="description" style="margin-top: 15px;">Forms and fields are auto-discovered. If you add new forms or fields, reload this page to see them.</p>';
        echo '</div>';
    }

    /* ---------------- Migration ---------------- */

    public function maybe_migrate_settings() {
        $options = get_option(self::OPTION, []);
        $version = isset($options['_version']) ? $options['_version'] : '0.0.0';
        
        // Only migrate if version is older than current
        if (version_compare($version, self::VERSION, '<')) {
            $this->migrate_settings($options);
        }
    }

    private function migrate_settings($options) {
        $defaults = $this->get_defaults();
        $migrated = wp_parse_args($options, $defaults);
        
        // Migrate from old field mapping format to new enable flags
        // If old format had field mappings AND allowed values, enable validation
        if (!isset($migrated['enable_zip_validation']) || $migrated['enable_zip_validation'] == 0) {
            $has_zip_map = !empty($migrated['zip_field_map']) && is_array($migrated['zip_field_map']);
            $has_zip_list = !empty($migrated['allowed_zips']);
            if ($has_zip_map && $has_zip_list) {
                $migrated['enable_zip_validation'] = 1;
            }
        }
        
        if (!isset($migrated['enable_state_validation']) || $migrated['enable_state_validation'] == 0) {
            $has_state_map = !empty($migrated['state_field_map']) && is_array($migrated['state_field_map']);
            $has_state_list = !empty($migrated['allowed_states']);
            if ($has_state_map && $has_state_list) {
                $migrated['enable_state_validation'] = 1;
            }
        }
        
        // Remove old field maps (no longer needed with auto-detection)
        unset($migrated['zip_field_map']);
        unset($migrated['state_field_map']);
        
        // Set version
        $migrated['_version'] = self::VERSION;
        
        // Save migrated settings
        update_option(self::OPTION, $migrated);
    }

    private function get_defaults() {
        return [
            'apply_all_forms'=>0,'selected_form_ids'=>[],
            'enable_zip_validation'=>0,'allowed_zips'=>'','zip_error_msg'=>'Sorry, we don\'t service this ZIP.',
            'enable_state_validation'=>0,'allowed_states'=>'','state_error_msg'=>'Sorry, we only serve selected states.',
            'error_color'=>'#C4042D','error_font_size'=>16,
            '_version'=>self::VERSION,
        ];
    }
    private function get_options(){ return wp_parse_args(get_option(self::OPTION,[]),$this->get_defaults()); }
    private function effective_form_ids_from_options($o){ return !empty($o['apply_all_forms'])? array_map('intval',wp_list_pluck($this->get_all_forms(),'id')) : array_map('intval',(array)$o['selected_form_ids']); }

    /* ---------------- Frontend CSS ---------------- */

    public function output_frontend_css(){
        $o=$this->get_options(); $c=esc_attr($o['error_color']); $s=absint($o['error_font_size']);
        echo "<style id='aqm-ff-error-style'>
        .frm_forms .aqm-error,.frm_forms .frm_error_style,.frm_forms .frm_error,.frm_forms .frm_inline_error,.frm_forms .frm_message,.frm_forms .frm_error_style p,.frm_forms .frm_error p{
            color:{$c}!important;font-size:{$s}px!important;line-height:1.35!important;}
        </style>";
    }

    /* ---------------- Core validation ---------------- */

    public function validate_location($errors,$posted){
        $o=$this->get_options();
        if(empty($posted)||empty($posted['form_id'])) return $errors;
        $form_id=absint($posted['form_id']);
        $targets=$this->effective_form_ids_from_options($o);
        if(empty($targets)||!in_array($form_id,$targets,true)) return $errors;

        $zip_list    = array_filter(preg_split('/\R+/', (string)$o['allowed_zips']));
        $state_codes = $this->parse_allowed_state_codes($o['allowed_states']);

        // ZIP - auto-detect fields if enabled
        if(!empty($o['enable_zip_validation']) && !empty($zip_list)){
            $zip_ids = $this->auto_detect_zip_fields($form_id);
            $zip_msg = '<span class="aqm-error">'.esc_html($o['zip_error_msg']).'</span>';
            if(empty($zip_ids)){
                // No ZIP fields found, show form-level error
                $errors['form'] = isset($errors['form']) ? $errors['form'].' '.$zip_msg : $zip_msg;
            }else{
                // Check if any ZIP field is present in posted data (for multi-step forms)
                $zip_field_present = $this->is_field_present_in_posted($posted, $zip_ids);
                if($zip_field_present){
                    [$zip_val,$zip_fid] = $this->get_first_field_value($posted,$zip_ids);
                    // Only validate if user has entered a value
                    if($zip_val !== '' && $zip_val !== null){
                        $zip_val = $this->hardened_normalize_zip($zip_val);
                        // Only show error if value is not in allowed list
                        if($zip_val === '' || !in_array($zip_val,$zip_list,true)){
                            $errors["field{$zip_fid}"] = $zip_msg;
                        }
                    }
                    // If field is empty, skip validation (let required field validation handle it)
                }
                // If field not present, skip validation (we're on an earlier step)
            }
        }

        // State - auto-detect fields if enabled
        if(!empty($o['enable_state_validation']) && !empty($state_codes)){
            $state_ids = $this->auto_detect_state_fields($form_id);
            $state_msg = '<span class="aqm-error">'.esc_html($o['state_error_msg']).'</span>';
            if(empty($state_ids)){
                // No state fields found, show form-level error
                $errors['form'] = isset($errors['form']) ? $errors['form'].' '.$state_msg : $state_msg;
            }else{
                // Check if any State field is present in posted data (for multi-step forms)
                $state_field_present = $this->is_field_present_in_posted($posted, $state_ids);
                if($state_field_present){
                    [$state_val,$state_fid] = $this->get_first_field_value($posted,$state_ids);
                    // Only validate if user has entered a value
                    if($state_val !== '' && $state_val !== null){
                        $state_code = $this->hardened_normalize_state($state_val);
                        // Only show error if value is not in allowed list
                        if($state_code === '' || !in_array($state_code,$state_codes,true)){
                            $errors["field{$state_fid}"] = $state_msg;
                        }
                    }
                    // If field is empty, skip validation (let required field validation handle it)
                }
                // If field not present, skip validation (we're on an earlier step)
            }
        }

        return $errors;
    }

    /* Extra enforcement */
    public function enforce_or_die($entry,$form_id){
        if (is_admin() && !wp_doing_ajax()) return;
        $posted = [
            'form_id'   => $form_id,
            'item_meta' => isset($_POST['item_meta']) ? (array) $_POST['item_meta'] : [],
        ];
        $errors = $this->validate_location([], $posted);
        if (!empty($errors)) {
            wp_die(
                wp_kses_post('<div class="aqm-error">Submission blocked by ZIP/State rules.</div>'),
                esc_html__('Forbidden', 'aqm'),
                ['response' => 403]
            );
        }
    }

    /* ---------------- Helpers ---------------- */

    private function get_first_field_value($posted,$field_ids){
        $value=''; $fid_found = (int) reset($field_ids);
        foreach((array)$field_ids as $fid){
            $fid=(int)$fid;
            if(isset($posted['item_meta'][$fid])){
                $val=$posted['item_meta'][$fid];
                $value = is_array($val)? reset($val) : $val;
                if($value!=='' && $value!==null){ $fid_found=$fid; break; }
            }
        }
        return [$value,$fid_found];
    }

    /**
     * Check if any of the specified field IDs are present in the posted data.
     * Used for multi-step forms to determine if we're on the step with these fields.
     */
    private function is_field_present_in_posted($posted, $field_ids){
        if(empty($field_ids) || empty($posted['item_meta'])) return false;
        foreach((array)$field_ids as $fid){
            $fid = (int)$fid;
            // Field is present if it exists in item_meta (even if empty)
            if(isset($posted['item_meta'][$fid])){
                return true;
            }
        }
        return false;
    }

    private function parse_allowed_state_codes($csv){
        $codes=[]; foreach(preg_split('/\s*,\s*/',(string)$csv) as $t){ $c=$this->hardened_normalize_state($t); if($c!=='') $codes[]=$c; }
        return array_values(array_unique($codes));
    }

    private function hardened_normalize_zip($v){
        $v = $this->strip_invisible_and_fullwidth($v);
        $v = strtoupper(trim((string)$v));
        return preg_match('/^\d{5}(-\d{4})?$/',$v) ? $v : '';
    }

    private function hardened_normalize_state($v){
        $v = $this->strip_invisible_and_fullwidth($v);
        $v = strtoupper(trim((string)$v));
        $map=$this->us_state_name_to_code_map();
        if (preg_match('/^[A-Z]{2}$/',$v) && in_array($v,$map,true)) return $v;
        return isset($map[$v]) ? $map[$v] : '';
    }

    /**
     * Remove zero-widths/invisible chars and normalize common fullwidth digits/hyphens to ASCII.
     * NOTE: use an explicit map to avoid array length mismatches.
     */
    private function strip_invisible_and_fullwidth($s){
        $s = (string) $s;
        // remove zero-width and invisibles
        $s = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u','',$s);

        // normalize common fullwidth digits and dash variants
        $map = [
            // fullwidth digits
            '０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9',
            // common dash/hyphen variants -> ASCII hyphen-minus
            '－'=>'-','—'=>'-','–'=>'-','‐'=>'-','-'=>'-','‒'=>'-','﹣'=>'-','−'=>'-',
        ];
        $s = strtr($s, $map);

        // collapse whitespace
        $s = preg_replace('/\s+/u',' ', $s);
        return $s;
    }

    private function filter_fields_by_label(array $fields,array $keywords){
        if(empty($fields)||empty($keywords)) return [];
        $out=[]; foreach($fields as $f){
            $label=strtolower(trim((string)$f['name'])); if($label==='') continue;
            foreach($keywords as $k){ $k=strtolower($k); if($k!=='' && strpos($label,$k)!==false){ $out[]=$f; break; } }
        } return $out;
    }

    private function auto_detect_zip_fields($form_id){
        $fields = $this->filter_fields_by_label($this->get_fields_for_form($form_id),['zip','postal','post code','postcode']);
        return array_map(function($f){ return (int)$f['id']; }, $fields);
    }

    private function auto_detect_state_fields($form_id){
        $fields = $this->filter_fields_by_label($this->get_fields_for_form($form_id),['state','province']);
        return array_map(function($f){ return (int)$f['id']; }, $fields);
    }

    private function get_all_forms(){
        $forms=[]; if(class_exists('FrmForm')){ $rows=\FrmForm::getAll(); foreach((array)$rows as $r){ $forms[]=['id'=>(int)$r->id,'name'=>(string)$r->name]; } }
        else { global $wpdb; $rows=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}frm_forms WHERE status='published' OR status IS NULL",ARRAY_A); foreach((array)$rows as $r){ $forms[]=['id'=>(int)$r['id'],'name'=>(string)$r['name']]; } }
        return $forms;
    }

    private function get_fields_for_form($form_id){
        $out=[]; if(class_exists('FrmField')){ $rows=\FrmField::getAll(['fi.form_id'=>(int)$form_id],'field_order'); foreach((array)$rows as $f){ $out[]=['id'=>(int)$f->id,'name'=>(string)$f->name,'type'=>isset($f->type)?(string)$f->type:'text']; } }
        else { global $wpdb; $rows=$wpdb->get_results($wpdb->prepare("SELECT id,name,type FROM {$wpdb->prefix}frm_fields WHERE form_id=%d ORDER BY field_order",(int)$form_id),ARRAY_A); foreach((array)$rows as $f){ $out[]=['id'=>(int)$f['id'],'name'=>(string)$f['name'],'type'=>(string)$f['type']]; } }
        return $out;
    }

    private function us_state_name_to_code_map(){ return [
        'ALABAMA'=>'AL','ALASKA'=>'AK','ARIZONA'=>'AZ','ARKANSAS'=>'AR','CALIFORNIA'=>'CA','COLORADO'=>'CO','CONNECTICUT'=>'CT','DELAWARE'=>'DE',
        'DISTRICT OF COLUMBIA'=>'DC','WASHINGTON DC'=>'DC','DC'=>'DC','FLORIDA'=>'FL','GEORGIA'=>'GA','HAWAII'=>'HI','IDAHO'=>'ID','ILLINOIS'=>'IL',
        'INDIANA'=>'IN','IOWA'=>'IA','KANSAS'=>'KS','KENTUCKY'=>'KY','LOUISIANA'=>'LA','MAINE'=>'ME','MARYLAND'=>'MD','MASSACHUSETTS'=>'MA',
        'MICHIGAN'=>'MI','MINNESOTA'=>'MN','MISSISSIPPI'=>'MS','MISSOURI'=>'MO','MONTANA'=>'MT','NEBRASKA'=>'NE','NEVADA'=>'NV','NEW HAMPSHIRE'=>'NH',
        'NEW JERSEY'=>'NJ','NEW MEXICO'=>'NM','NEW YORK'=>'NY','NORTH CAROLINA'=>'NC','NORTH DAKOTA'=>'ND','OHIO'=>'OH','OKLAHOMA'=>'OK','OREGON'=>'OR',
        'PENNSYLVANIA'=>'PA','RHODE ISLAND'=>'RI','SOUTH CAROLINA'=>'SC','SOUTH DAKOTA'=>'SD','TENNESSEE'=>'TN','TEXAS'=>'TX','UTAH'=>'UT','VERMONT'=>'VT',
        'VIRGINIA'=>'VA','WASHINGTON'=>'WA','WEST VIRGINIA'=>'WV','WISCONSIN'=>'WI','WYOMING'=>'WY',
        'PUERTO RICO'=>'PR','GUAM'=>'GU','AMERICAN SAMOA'=>'AS','NORTHERN MARIANA ISLANDS'=>'MP','U.S. VIRGIN ISLANDS'=>'VI',
        // allow codes as keys too
        'AL'=>'AL','AK'=>'AK','AZ'=>'AZ','AR'=>'AR','CA'=>'CA','CO'=>'CO','CT'=>'CT','DE'=>'DE','FL'=>'FL','GA'=>'GA','HI'=>'HI','ID'=>'ID','IL'=>'IL',
        'IN'=>'IN','IA'=>'IA','KS'=>'KS','KY'=>'KY','LA'=>'LA','ME'=>'ME','MD'=>'MD','MA'=>'MA','MI'=>'MI','MN'=>'MN','MS'=>'MS','MO'=>'MO','MT'=>'MT',
        'NE'=>'NE','NV'=>'NV','NH'=>'NH','NJ'=>'NJ','NM'=>'NM','NY'=>'NY','NC'=>'NC','ND'=>'ND','OH'=>'OH','OK'=>'OK','OR'=>'OR','PA'=>'PA','RI'=>'RI',
        'SC'=>'SC','SD'=>'SD','TN'=>'TN','TX'=>'TX','UT'=>'UT','VT'=>'VT','VA'=>'VA','WA'=>'WA','WV'=>'WV','WI'=>'WI','WY'=>'WY','PR'=>'PR','GU'=>'GU',
        'AS'=>'AS','MP'=>'MP','VI'=>'VI'
    ]; }

    /* ---------------- GitHub Update Checker ---------------- */

    public function check_for_updates($transient) {
        if (empty($transient->checked)) return $transient;
        
        $plugin_file = plugin_basename(__FILE__);
        $current_version = self::VERSION;
        
        // Check GitHub for latest release
        $latest_version = $this->get_latest_github_version();
        
        if ($latest_version && version_compare($current_version, $latest_version, '<')) {
            // Verify the release actually exists and has assets before adding to update response
            // This prevents the "Not Found" error and JavaScript issues
            $release_verified = $this->verify_release_exists($latest_version);
            
            if ($release_verified) {
                $transient->response[$plugin_file] = (object) [
                    'slug' => 'aqm-formidable-zip-whitelist',
                    'plugin' => $plugin_file,
                    'new_version' => $latest_version,
                    'url' => 'https://github.com/JustCasey76/aqm-formidable-zip-whitelist',
                    'package' => $this->get_github_download_url($latest_version),
                    'id' => $plugin_file,
                    'tested' => get_bloginfo('version'),
                    'requires' => '5.0',
                    'requires_php' => '7.2',
                    'compatibility' => new stdClass(),
                ];
            } else {
                // Release doesn't exist or has no assets - don't show update
                error_log('AQM Plugin Update: Release v' . $latest_version . ' exists but has no downloadable assets, skipping update notification');
                unset($transient->response[$plugin_file]);
            }
        } else {
            // Remove from response if no update (prevents stale updates)
            unset($transient->response[$plugin_file]);
        }
        
        return $transient;
    }
    
    /**
     * Verify that a release exists and has downloadable assets
     */
    private function verify_release_exists($version) {
        // Try with 'v' prefix first
        $api_url = 'https://api.github.com/repos/JustCasey76/aqm-formidable-zip-whitelist/releases/tags/v' . $version;
        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version'),
        ];
        
        $response = wp_remote_get($api_url, [
            'timeout' => 10,
            'headers' => $headers,
        ]);
        
        // If 404, try without 'v' prefix
        if (!is_wp_error($response)) {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 404) {
                $api_url = 'https://api.github.com/repos/JustCasey76/aqm-formidable-zip-whitelist/releases/tags/' . $version;
                $response = wp_remote_get($api_url, [
                    'timeout' => 10,
                    'headers' => $headers,
                ]);
            }
        }
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data) || empty($data['assets']) || !is_array($data['assets'])) {
            return false;
        }
        
        // Check if there's at least one ZIP asset
        foreach ($data['assets'] as $asset) {
            if (isset($asset['name']) && strpos(strtolower($asset['name']), '.zip') !== false) {
                return true;
            }
        }
        
        return false;
    }

    public function clear_update_cache() {
        delete_transient('aqm_ff_whitelist_latest_version');
        // Also clear WordPress update transient for this plugin
        $transient = get_site_transient('update_plugins');
        if ($transient && isset($transient->response[plugin_basename(__FILE__)])) {
            unset($transient->response[plugin_basename(__FILE__)]);
            set_site_transient('update_plugins', $transient);
        }
    }

    public function plugin_info($false, $action, $response) {
        if ($action !== 'plugin_information' || $response->slug !== 'aqm-formidable-zip-whitelist') {
            return $false;
        }
        
        $latest_version = $this->get_latest_github_version();
        
        $response->name = 'AQM Formidable ZIP & State Whitelist (Hardened)';
        $response->version = $latest_version ?: self::VERSION;
        $response->download_link = $latest_version ? $this->get_github_download_url($latest_version) : '';
        $response->sections = [
            'description' => 'Server-side ZIP/State allowlist for Formidable Forms. Auto-detects location fields, validates submissions, and blocks unauthorized locations with hardened security.',
        ];
        
        return $response;
    }

    private function get_latest_github_version($force_fresh = false, &$error_info = null) {
        $cache_key = 'aqm_ff_whitelist_latest_version';
        
        // Check if we're on the updates page - if so, reduce cache time for faster detection
        $is_update_page = (isset($_GET['page']) && $_GET['page'] === 'update-core.php') || 
                          (isset($_GET['action']) && $_GET['action'] === 'upgrade-plugin');
        
        // Use shorter cache (5 minutes) when on update page, otherwise 1 hour
        $cache_time = $is_update_page ? 5 * MINUTE_IN_SECONDS : 1 * HOUR_IN_SECONDS;
        
        $cached = get_transient($cache_key);
        
        // If forcing fresh check or on update page, always check fresh. Otherwise use cache.
        if ($cached !== false && !$is_update_page && !$force_fresh) {
            $error_info = ['type' => 'cached', 'version' => $cached];
            return $cached;
        }
        
        // Repository is public, so we don't need authentication
        // Skip token authentication to avoid 401 errors with invalid/expired tokens
        $api_url = 'https://api.github.com/repos/JustCasey76/aqm-formidable-zip-whitelist/releases';
        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version'),
        ];
        
        // Don't use authentication for public repos
        error_log('AQM Plugin Update Check: Checking /releases endpoint: ' . $api_url . ' (public repo, no authentication)');
        
        $response = wp_remote_get($api_url, [
            'timeout' => 15,
            'headers' => $headers,
        ]);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_code = $response->get_error_code();
            error_log('AQM Plugin Update Check: GitHub API error - ' . $error_code . ': ' . $error_message);
            $error_info = [
                'type' => 'wp_error',
                'code' => $error_code,
                'message' => $error_message,
            ];
            // Return cached version if API fails (better than nothing)
            return $cached !== false ? $cached : false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        // Log response headers for debugging
        $response_headers = wp_remote_retrieve_headers($response);
        $rate_limit_remaining = isset($response_headers['x-ratelimit-remaining']) ? $response_headers['x-ratelimit-remaining'] : 'unknown';
        error_log('AQM Plugin Update Check: Response code: ' . $response_code . ', Rate limit remaining: ' . $rate_limit_remaining);
        
        if ($response_code === 404) {
            // No releases found
            $body = wp_remote_retrieve_body($response);
            $error_body = substr($body, 0, 500);
            error_log('AQM Plugin Update Check: 404 error - No releases found. Response: ' . $error_body);
            
            $error_info = [
                'type' => 'http_error',
                'code' => 404,
                'body' => $error_body,
                'message' => 'No releases found. The GitHub Actions workflow may not have created a release yet, or releases may be drafts.',
            ];
            return $cached !== false ? $cached : false;
        }
        
        if ($response_code === 401) {
            // Unauthorized - shouldn't happen for public repos, but log it
            error_log('AQM Plugin Update Check: 401 Unauthorized - Unexpected for public repository.');
            $body = wp_remote_retrieve_body($response);
            $error_body = substr($body, 0, 500);
            $error_info = [
                'type' => 'http_error',
                'code' => 401,
                'body' => $error_body,
                'message' => 'Unexpected authentication error. Repository should be public.',
            ];
            return $cached !== false ? $cached : false;
        }
        
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $error_body = substr($body, 0, 500);
            error_log('AQM Plugin Update Check: GitHub API returned code ' . $response_code . ' - ' . $error_body);
            $error_info = [
                'type' => 'http_error',
                'code' => $response_code,
                'body' => $error_body,
            ];
            return $cached !== false ? $cached : false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('AQM Plugin Update Check: JSON decode error - ' . json_last_error_msg());
            $error_info = [
                'type' => 'json_error',
                'message' => json_last_error_msg(),
                'body_preview' => substr($body, 0, 500),
            ];
            return $cached !== false ? $cached : false;
        }
        
        // $data is an array of releases, find the first non-draft, non-prerelease release
        if (is_array($data) && !empty($data)) {
            error_log('AQM Plugin Update Check: Found ' . count($data) . ' releases');
            foreach ($data as $release) {
                if (isset($release['tag_name']) && !empty($release['tag_name']) && 
                    empty($release['draft']) && empty($release['prerelease'])) {
                    $version = ltrim($release['tag_name'], 'v'); // Remove 'v' prefix if present
                    error_log('AQM Plugin Update Check: Found valid release version: ' . $version);
                    set_transient($cache_key, $version, $cache_time);
                    $error_info = null; // Clear error info on success
                    return $version;
                }
            }
            error_log('AQM Plugin Update Check: All releases are drafts or prereleases');
        }
        
        // Log if no valid releases found
        $debug_body = substr($body, 0, 1000);
        $response_keys = isset($data) && is_array($data) ? array_keys($data) : [];
        error_log('AQM Plugin Update Check: No valid releases found. Response code: ' . $response_code . ' | Keys: ' . implode(', ', $response_keys) . ' | Response: ' . $debug_body);
        
        // Always set error_info when no releases found
        $error_info = [
            'type' => 'no_releases',
            'response_keys' => $response_keys,
            'body_preview' => $debug_body,
            'has_data' => isset($data) && is_array($data),
            'data_type' => gettype($data),
            'response_code' => $response_code,
            'release_count' => is_array($data) ? count($data) : 0,
        ];
        
        return false;
    }

    private function get_github_download_url($version) {
        // Return the direct GitHub release download URL
        // We'll intercept the download via upgrader_pre_download filter to get the correct asset URL
        return "https://github.com/JustCasey76/aqm-formidable-zip-whitelist/releases/download/v{$version}/aqm-formidable-zip-whitelist.zip";
    }
    
    public function handle_private_repo_download($reply, $package, $upgrader) {
        // Static counter to prevent infinite recursion
        static $recursion_depth = 0;
        $recursion_depth++;
        
        error_log('AQM Plugin Update: ========== DOWNLOAD HANDLER STARTED (depth: ' . $recursion_depth . ') ==========');
        error_log('AQM Plugin Update: Package URL: ' . $package);
        error_log('AQM Plugin Update: Reply type: ' . gettype($reply));
        error_log('AQM Plugin Update: Upgrader class: ' . (is_object($upgrader) ? get_class($upgrader) : 'not an object'));
        
        // Only handle downloads for this plugin
        if (strpos($package, 'aqm-formidable-zip-whitelist') === false) {
            error_log('AQM Plugin Update: Package does not match plugin name, returning original reply');
            $recursion_depth = 0; // Reset on exit
            return $reply;
        }
        
        // Prevent infinite recursion
        if ($recursion_depth > 3) {
            error_log('AQM Plugin Update: ERROR - Recursion depth exceeded, returning false');
            $recursion_depth = 0;
            return false;
        }
        
        error_log('AQM Plugin Update: Package matches plugin name, processing...');
        
        // Repository is public, so we don't need authentication
        // Extract version from package URL - handle multiple URL formats
        $version = null;
        if (preg_match('/v([\d.]+)\/aqm-formidable-zip-whitelist\.zip$/', $package, $matches)) {
            $version = $matches[1];
        } elseif (preg_match('/releases\/download\/v?([\d.]+)\//', $package, $matches)) {
            $version = $matches[1];
        } elseif (preg_match('/tag\/v?([\d.]+)/', $package, $matches)) {
            $version = $matches[1];
        }
        
        if ($version) {
            error_log('AQM Plugin Update: Extracted version from URL: ' . $version);
            
            // Get the release assets from GitHub API to find the correct download URL
            // Try with 'v' prefix first, then without if that fails
            $api_url = 'https://api.github.com/repos/JustCasey76/aqm-formidable-zip-whitelist/releases/tags/v' . $version;
            error_log('AQM Plugin Update: API URL: ' . $api_url);
            
            $headers = [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
            ];
            
            // Don't use authentication for public repos
            error_log('AQM Plugin Update: Using unauthenticated API request (public repo)');
            error_log('AQM Plugin Update: Request headers: ' . print_r($headers, true));
            
            error_log('AQM Plugin Update: Making API request to GitHub...');
            $response = wp_remote_get($api_url, [
                'timeout' => 15,
                'headers' => $headers,
            ]);
            
            if (is_wp_error($response)) {
                error_log('AQM Plugin Update: API request failed with WP_Error: ' . $response->get_error_message());
                error_log('AQM Plugin Update: Error code: ' . $response->get_error_code());
                $recursion_depth = 0;
                return new WP_Error('api_request_failed', 'Failed to fetch release info: ' . $response->get_error_message());
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_headers = wp_remote_retrieve_headers($response);
            error_log('AQM Plugin Update: Release API response code: ' . $response_code);
            error_log('AQM Plugin Update: Response headers: ' . print_r($response_headers, true));
            
            // If 404, try without 'v' prefix
            if ($response_code === 404) {
                error_log('AQM Plugin Update: Tag with "v" prefix not found, trying without prefix...');
                $api_url_no_v = 'https://api.github.com/repos/JustCasey76/aqm-formidable-zip-whitelist/releases/tags/' . $version;
                $response = wp_remote_get($api_url_no_v, [
                    'timeout' => 15,
                    'headers' => $headers,
                ]);
                
                if (is_wp_error($response)) {
                    error_log('AQM Plugin Update: Retry API request failed with WP_Error: ' . $response->get_error_message());
                    $recursion_depth = 0;
                    return new WP_Error('api_request_failed', 'Failed to fetch release info: ' . $response->get_error_message());
                }
                
                $response_code = wp_remote_retrieve_response_code($response);
                error_log('AQM Plugin Update: Retry API response code: ' . $response_code);
            }
            
            if ($response_code === 200) {
                error_log('AQM Plugin Update: API request successful (200 OK)');
                $body = wp_remote_retrieve_body($response);
                $body_length = strlen($body);
                error_log('AQM Plugin Update: Response body length: ' . $body_length . ' bytes');
                error_log('AQM Plugin Update: Response body preview (first 500 chars): ' . substr($body, 0, 500));
                
                $data = json_decode($body, true);
                $json_error = json_last_error();
                if ($json_error !== JSON_ERROR_NONE) {
                    error_log('AQM Plugin Update: JSON decode failed with error: ' . json_last_error_msg());
                    $recursion_depth = 0;
                    return new WP_Error('json_decode_failed', 'Failed to decode GitHub API response: ' . json_last_error_msg());
                }
                
                error_log('AQM Plugin Update: JSON decoded successfully');
                error_log('AQM Plugin Update: Release data keys: ' . implode(', ', array_keys($data)));
                
                if (isset($data['assets']) && is_array($data['assets'])) {
                    $asset_count = count($data['assets']);
                    error_log('AQM Plugin Update: Found ' . $asset_count . ' assets in release');
                    
                    // Log all assets for debugging
                    foreach ($data['assets'] as $idx => $asset) {
                        error_log('AQM Plugin Update: Asset #' . $idx . ': ' . print_r([
                            'id' => isset($asset['id']) ? $asset['id'] : 'N/A',
                            'name' => isset($asset['name']) ? $asset['name'] : 'N/A',
                            'size' => isset($asset['size']) ? $asset['size'] : 'N/A',
                            'browser_download_url' => isset($asset['browser_download_url']) ? $asset['browser_download_url'] : 'N/A',
                            'url' => isset($asset['url']) ? $asset['url'] : 'N/A',
                        ], true));
                    }
                    
                    // First, try to find exact match
                    error_log('AQM Plugin Update: Searching for exact match: aqm-formidable-zip-whitelist.zip');
                    $matching_asset = null;
                    foreach ($data['assets'] as $asset) {
                        if (isset($asset['name']) && strpos($asset['name'], 'aqm-formidable-zip-whitelist.zip') !== false) {
                            error_log('AQM Plugin Update: Found exact match: ' . $asset['name']);
                            $matching_asset = $asset;
                            break;
                        }
                    }
                    
                    // If no exact match, try any .zip file
                    if (!$matching_asset) {
                        error_log('AQM Plugin Update: No exact match found, searching for any .zip file');
                        foreach ($data['assets'] as $asset) {
                            if (isset($asset['name']) && strpos(strtolower($asset['name']), '.zip') !== false) {
                                error_log('AQM Plugin Update: Using fallback ZIP asset: ' . $asset['name']);
                                $matching_asset = $asset;
                                break;
                            }
                        }
                    }
                    
                    if ($matching_asset) {
                        $asset = $matching_asset;
                        error_log('AQM Plugin Update: ========== PROCESSING MATCHING ASSET ==========');
                        error_log('AQM Plugin Update: Asset name: ' . (isset($asset['name']) ? $asset['name'] : 'N/A'));
                        error_log('AQM Plugin Update: Asset ID: ' . (isset($asset['id']) ? $asset['id'] : 'N/A'));
                        error_log('AQM Plugin Update: Asset size: ' . (isset($asset['size']) ? $asset['size'] : 'N/A'));
                        error_log('AQM Plugin Update: Asset browser_download_url: ' . (isset($asset['browser_download_url']) ? $asset['browser_download_url'] : 'NOT SET'));
                        error_log('AQM Plugin Update: Asset url: ' . (isset($asset['url']) ? $asset['url'] : 'NOT SET'));
                        
                        // For public repos, always use browser_download_url (no authentication needed)
                        if (isset($asset['browser_download_url'])) {
                            // Public repo - use direct download URL
                            $download_url = $asset['browser_download_url'];
                            error_log('AQM Plugin Update: Using browser_download_url: ' . $download_url);
                        } else {
                            // Fallback to url field if browser_download_url is not available
                            $download_url = isset($asset['url']) ? $asset['url'] : '';
                            error_log('AQM Plugin Update: browser_download_url not available, using url field: ' . ($download_url ? $download_url : 'NOT SET'));
                        }
                        
                        if (empty($download_url)) {
                            error_log('AQM Plugin Update: ERROR - No download URL available for asset');
                            error_log('AQM Plugin Update: Full asset data: ' . print_r($asset, true));
                        } else {
                            error_log('AQM Plugin Update: ========== STARTING DOWNLOAD ==========');
                            error_log('AQM Plugin Update: Download URL: ' . $download_url);
                            
                            // Set up headers for binary download (no authentication for public repos)
                            $download_headers = [
                                'Accept' => 'application/octet-stream',
                                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
                            ];
                            
                            error_log('AQM Plugin Update: Download headers: ' . print_r($download_headers, true));
                            error_log('AQM Plugin Update: Downloading without authentication (public repo)');
                            
                            // Create temp file path - ensure directory separator is correct
                            $temp_dir = get_temp_dir();
                            error_log('AQM Plugin Update: Temp directory: ' . $temp_dir);
                            error_log('AQM Plugin Update: Temp directory exists: ' . (is_dir($temp_dir) ? 'YES' : 'NO'));
                            error_log('AQM Plugin Update: Temp directory writable: ' . (is_writable($temp_dir) ? 'YES' : 'NO'));
                            
                            if (!is_dir($temp_dir)) {
                                error_log('AQM Plugin Update: ERROR - Temp directory does not exist: ' . $temp_dir);
                                $recursion_depth = 0;
                                return new WP_Error('temp_dir_missing', 'Temporary directory does not exist: ' . $temp_dir);
                            }
                            
                            $file_path = trailingslashit($temp_dir) . 'aqm-formidable-zip-whitelist-' . $version . '-' . time() . '.zip';
                            error_log('AQM Plugin Update: Target file path: ' . $file_path);
                            
                            // Try downloading to memory first (more reliable)
                            error_log('AQM Plugin Update: Making download request...');
                            $download_start_time = microtime(true);
                            $download_response = wp_remote_get($download_url, [
                                'timeout' => 300,
                                'headers' => $download_headers,
                                'stream' => false, // Download to memory
                            ]);
                            $download_time = microtime(true) - $download_start_time;
                            error_log('AQM Plugin Update: Download request completed in ' . round($download_time, 2) . ' seconds');
                            
                            if (is_wp_error($download_response)) {
                                $error_message = $download_response->get_error_message();
                                $error_code = $download_response->get_error_code();
                                error_log('AQM Plugin Update: ERROR - Download failed with WP_Error');
                                error_log('AQM Plugin Update: Error code: ' . $error_code);
                                error_log('AQM Plugin Update: Error message: ' . $error_message);
                                $recursion_depth = 0;
                                return new WP_Error('download_error', 'Download failed: ' . $error_message);
                            }
                            
                            $response_code = wp_remote_retrieve_response_code($download_response);
                            $response_headers = wp_remote_retrieve_headers($download_response);
                            error_log('AQM Plugin Update: Download response code: ' . $response_code);
                            error_log('AQM Plugin Update: Download response headers: ' . print_r($response_headers, true));
                            
                            if ($response_code === 200) {
                                error_log('AQM Plugin Update: Download successful (200 OK)');
                                $file_content = wp_remote_retrieve_body($download_response);
                                $content_size = strlen($file_content);
                                error_log('AQM Plugin Update: Downloaded content size: ' . $content_size . ' bytes');
                                
                                if ($content_size > 0) {
                                    error_log('AQM Plugin Update: Writing file to disk...');
                                    $bytes_written = file_put_contents($file_path, $file_content);
                                    error_log('AQM Plugin Update: file_put_contents returned: ' . ($bytes_written !== false ? $bytes_written . ' bytes written' : 'FALSE'));
                                    
                                    if ($bytes_written !== false) {
                                        $file_size = filesize($file_path);
                                        error_log('AQM Plugin Update: File size on disk: ' . $file_size . ' bytes');
                                        error_log('AQM Plugin Update: File exists: ' . (file_exists($file_path) ? 'YES' : 'NO'));
                                        error_log('AQM Plugin Update: File readable: ' . (is_readable($file_path) ? 'YES' : 'NO'));
                                        
                                        if ($file_size > 0 && $file_size === $content_size) {
                                            error_log('AQM Plugin Update: ========== DOWNLOAD SUCCESSFUL ==========');
                                            error_log('AQM Plugin Update: File saved to: ' . $file_path);
                                            error_log('AQM Plugin Update: File size: ' . $file_size . ' bytes');
                                            
                                            // Verify file is readable
                                            if (is_readable($file_path)) {
                                                error_log('AQM Plugin Update: File is readable, returning path to WordPress');
                                                $recursion_depth = 0; // Reset on success
                                                return $file_path; // Return file path for WordPress to use
                                            } else {
                                                error_log('AQM Plugin Update: ERROR - File saved but is not readable');
                                                error_log('AQM Plugin Update: File permissions: ' . substr(sprintf('%o', fileperms($file_path)), -4));
                                                $recursion_depth = 0;
                                                return new WP_Error('file_not_readable', 'Downloaded file is not readable: ' . $file_path);
                                            }
                                        } else {
                                            error_log('AQM Plugin Update: ERROR - File size mismatch');
                                            error_log('AQM Plugin Update: Expected size: ' . $content_size . ' bytes');
                                            error_log('AQM Plugin Update: Actual size: ' . $file_size . ' bytes');
                                            $recursion_depth = 0;
                                            return new WP_Error('file_size_mismatch', 'Downloaded file size does not match expected size.');
                                        }
                                    } else {
                                        error_log('AQM Plugin Update: ERROR - Failed to write file to disk');
                                        error_log('AQM Plugin Update: File path: ' . $file_path);
                                        error_log('AQM Plugin Update: Directory writable: ' . (is_writable(dirname($file_path)) ? 'YES' : 'NO'));
                                        $recursion_depth = 0;
                                        return new WP_Error('file_write_failed', 'Failed to write downloaded file. Check directory permissions.');
                                    }
                                } else {
                                    error_log('AQM Plugin Update: ERROR - Downloaded content is empty');
                                    $recursion_depth = 0;
                                    return new WP_Error('empty_download', 'Downloaded file is empty.');
                                }
                            } else {
                                $error_body = wp_remote_retrieve_body($download_response);
                                $error_body_preview = substr($error_body, 0, 500);
                                error_log('AQM Plugin Update: ERROR - Download failed with code ' . $response_code);
                                error_log('AQM Plugin Update: Error response body: ' . $error_body_preview);
                                $recursion_depth = 0;
                                return new WP_Error('download_failed', 'Download failed with error code ' . $response_code . '.');
                            }
                        }
                    } else {
                        // No matching asset found
                        error_log('AQM Plugin Update: ========== NO MATCHING ASSET FOUND ==========');
                        if (empty($data['assets']) || count($data['assets']) === 0) {
                            error_log('AQM Plugin Update: No assets found in release v' . $version);
                            error_log('AQM Plugin Update: Release exists but has no downloadable assets. Please create a GitHub Release with a ZIP file attached.');
                        } else {
                            error_log('AQM Plugin Update: Assets found but none matched. Available assets:');
                            foreach ($data['assets'] as $asset) {
                                error_log('AQM Plugin Update: Available asset: ' . (isset($asset['name']) ? $asset['name'] : 'unnamed'));
                            }
                        }
                        
                        // Return a clear error message instead of letting WordPress fail with "Not Found"
                        $recursion_depth = 0;
                        return new WP_Error(
                            'no_assets_found',
                            'Release v' . $version . ' exists but has no downloadable ZIP file. Please create a GitHub Release with a ZIP asset attached.',
                            [
                                'version' => $version,
                                'release_url' => isset($data['html_url']) ? $data['html_url'] : 'https://github.com/JustCasey76/aqm-formidable-zip-whitelist/releases',
                            ]
                        );
                    }
                } else {
                    error_log('AQM Plugin Update: ERROR - Release data does not contain assets array');
                    error_log('AQM Plugin Update: Available keys in data: ' . implode(', ', array_keys($data)));
                    error_log('AQM Plugin Update: Full release data: ' . print_r($data, true));
                    $recursion_depth = 0;
                    return new WP_Error('invalid_release_data', 'Release data is missing assets array. Available keys: ' . implode(', ', array_keys($data)));
                }
            } else {
                $error_body = wp_remote_retrieve_body($response);
                $error_body_preview = substr($error_body, 0, 500);
                error_log('AQM Plugin Update: ERROR - Failed to get release info');
                error_log('AQM Plugin Update: Response code: ' . $response_code);
                error_log('AQM Plugin Update: Response body: ' . $error_body_preview);
                
                if ($response_code === 404) {
                    error_log('AQM Plugin Update: Release not found (404)');
                    $recursion_depth = 0;
                    return new WP_Error('release_not_found', 'Release v' . $version . ' not found. It may not exist yet.');
                } elseif ($response_code === 401) {
                    error_log('AQM Plugin Update: Unauthorized (401) - unexpected for public repo');
                    $recursion_depth = 0;
                    return new WP_Error('unauthorized', 'GitHub API returned 401 Unauthorized. This is unexpected for a public repository.');
                } else {
                    error_log('AQM Plugin Update: API error with code ' . $response_code);
                    $recursion_depth = 0;
                    return new WP_Error('api_error', 'GitHub API error (code ' . $response_code . ').');
                }
            }
        } else {
            error_log('AQM Plugin Update: ERROR - Could not extract version from package URL');
            error_log('AQM Plugin Update: Package URL: ' . $package);
            error_log('AQM Plugin Update: Attempted patterns: /v([\d.]+)\/aqm-formidable-zip-whitelist\.zip$/, /releases\/download\/v?([\d.]+)\//, /tag\/v?([\d.]+)/');
            
            // Try to get the latest release version and use that
            error_log('AQM Plugin Update: Attempting to get latest release version as fallback...');
            $error_info = null;
            $latest_version = $this->get_latest_github_version(false, $error_info);
            
            if ($latest_version) {
                error_log('AQM Plugin Update: Found latest version: ' . $latest_version . ', retrying download...');
                // Recursively call ourselves with a constructed package URL
                $fallback_package = $this->get_github_download_url($latest_version);
                return $this->handle_private_repo_download($reply, $fallback_package, $upgrader);
            } else {
                error_log('AQM Plugin Update: Could not get latest version, returning false to let WordPress handle it');
                // Return false to let WordPress try the original URL
                return false;
            }
        }
        
        error_log('AQM Plugin Update: ========== DOWNLOAD HANDLER FAILED ==========');
        // Return false instead of WP_Error to let WordPress try the original URL
        // This prevents the JavaScript error if our handler fails
        $recursion_depth = 0;
        return false;
    }

    public function add_check_update_link($links, $file) {
        if ($file !== plugin_basename(__FILE__)) {
            return $links;
        }
        
        $links[] = '<a href="#" class="aqm-check-update-link" data-plugin="' . esc_attr($file) . '">Check for Updates</a>';
        
        // Add inline script for AJAX handling (only once)
        if (!self::$script_added) {
            add_action('admin_footer', [$this, 'add_check_update_script']);
            self::$script_added = true;
        }
        
        return $links;
    }

    public function add_check_update_script() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'plugins') {
            return; // Only on plugins page
        }
        ?>
        <script type="text/javascript">
        (function($) {
            console.log('AQM Update Checker: Script loaded');
            $(document).ready(function() {
                console.log('AQM Update Checker: Document ready, binding click handler');
                $(document).on('click', '.aqm-check-update-link', function(e) {
                e.preventDefault();
                var $link = $(this);
                var originalText = $link.text();
                $link.text('Checking...').css('pointer-events', 'none');
                
                var ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo admin_url('admin-ajax.php'); ?>';
                console.log('AQM Update Checker: Making AJAX call to', ajaxUrl);
                
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'aqm_check_plugin_update',
                        _ajax_nonce: '<?php echo wp_create_nonce('aqm_check_update'); ?>'
                    },
                    success: function(response) {
                        console.log('AQM Update Check Response:', response);
                        if (response.success) {
                            if (response.data.has_update) {
                                // Refresh the page to show the update button
                                window.location.reload();
                            } else {
                                var msg = response.data.latest_version ? 'Up to Date (v' + response.data.latest_version + ')' : response.data.message;
                                $link.text(msg).css('color', '#00a32a');
                                if (response.data.debug) {
                                    console.log('AQM Update Debug:', response.data.debug);
                                    if (response.data.debug.error_info) {
                                        console.log('AQM Error Info Details:', JSON.stringify(response.data.debug.error_info, null, 2));
                                    }
                                }
                                setTimeout(function() {
                                    $link.text(originalText).css('color', '');
                                }, 5000);
                            }
                        } else {
                            $link.text('Error: ' + (response.data || 'Unknown error')).css('color', '#d63638');
                            console.error('AQM Update Check Error:', response);
                            setTimeout(function() {
                                $link.text(originalText).css('color', '');
                            }, 5000);
                        }
                        $link.css('pointer-events', 'auto');
                    },
                    error: function(xhr, status, error) {
                        console.error('AQM Update Check AJAX Error:', status, error, xhr);
                        $link.text('Error checking updates').css('color', '#d63638');
                        setTimeout(function() {
                            $link.text(originalText).css('color', '');
                        }, 5000);
                        $link.css('pointer-events', 'auto');
                    }
                });
                }); // Close click handler
            }); // Close ready
        })(jQuery);
        </script>
        <?php
    }

    public function ajax_check_update() {
        // Verify nonce
        if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'aqm_check_update')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        if (!current_user_can('update_plugins')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Clear cache to force fresh check
        delete_transient('aqm_ff_whitelist_latest_version');
        
        // Also clear WordPress update transient
        $transient = get_site_transient('update_plugins');
        if ($transient && isset($transient->response[plugin_basename(__FILE__)])) {
            unset($transient->response[plugin_basename(__FILE__)]);
            set_site_transient('update_plugins', $transient);
        }
        
        // Force fresh check
        $error_info = null;
        $latest_version = $this->get_latest_github_version(true, $error_info);
        $current_version = self::VERSION;
        
        // Debug info
        $debug_info = [
            'current_version' => $current_version,
            'latest_version' => $latest_version,
            'version_compare' => $latest_version ? version_compare($current_version, $latest_version, '<') : false,
            'error_info' => $error_info,
        ];
        
        if ($latest_version && version_compare($current_version, $latest_version, '<')) {
            // Trigger WordPress to check again
            delete_site_transient('update_plugins');
            
            wp_send_json_success([
                'has_update' => true,
                'current_version' => $current_version,
                'latest_version' => $latest_version,
                'message' => 'New version ' . $latest_version . ' is available!',
                'debug' => $debug_info,
            ]);
        } else {
            $message = 'You are running the latest version.';
            if (!$latest_version) {
                $message = 'Could not check for updates. The GitHub API may be unavailable or rate-limited.';
            }
            wp_send_json_success([
                'has_update' => false,
                'current_version' => $current_version,
                'latest_version' => $latest_version ?: $current_version,
                'message' => $message,
                'debug' => $debug_info,
            ]);
        }
    }
}

new AQM_Formidable_Location_Whitelist();
