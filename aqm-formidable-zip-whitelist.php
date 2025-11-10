<?php
/**
 * Plugin Name: AQM Formidable ZIP & State Whitelist (Hardened)
 * Description: Server-side ZIP/State allowlist for Formidable Forms. Auto-detects ZIP/State fields; error color/size controls. Hardened against Unicode/invisible chars and double-enforced on create/update.
 * Version: 1.8.4
 * Author: AQ Marketing (Justin Casey)
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

class AQM_Formidable_Location_Whitelist {
    const OPTION    = 'aqm_ff_location_whitelist';
    const PAGE_SLUG = 'aqm-ff-location-whitelist';
    const VERSION   = '1.8.4';

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
            $transient->response[$plugin_file] = (object) [
                'slug' => 'aqm-formidable-zip-whitelist',
                'plugin' => $plugin_file,
                'new_version' => $latest_version,
                'url' => 'https://github.com/JustCasey76/aqm-formidable-zip-whitelist',
                'package' => $this->get_github_download_url($latest_version),
            ];
        }
        
        return $transient;
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

    private function get_latest_github_version() {
        $cache_key = 'aqm_ff_whitelist_latest_version';
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $api_url = 'https://api.github.com/repos/JustCasey76/aqm-formidable-zip-whitelist/releases/latest';
        $response = wp_remote_get($api_url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['tag_name'])) {
            $version = ltrim($data['tag_name'], 'v'); // Remove 'v' prefix if present
            set_transient($cache_key, $version, 12 * HOUR_IN_SECONDS); // Cache for 12 hours
            return $version;
        }
        
        return false;
    }

    private function get_github_download_url($version) {
        return "https://github.com/JustCasey76/aqm-formidable-zip-whitelist/releases/download/v{$version}/aqm-formidable-zip-whitelist.zip";
    }
}

new AQM_Formidable_Location_Whitelist();
