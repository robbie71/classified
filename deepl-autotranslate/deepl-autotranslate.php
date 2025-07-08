<?php
/*
Plugin Name: DeepL Auto Translate (Enhanced)
Description: Translate content automatically via LibreTranslate or DeepL with WPML integration, bulk translation, history tracking and advanced cache management
Version: 2.5
Author: Enhanced by AI
*/

// Prevent direct access
if (!defined('ABSPATH')) exit;

// Plugin constants
define('DAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DAT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DAT_CACHE_DIR', WP_CONTENT_DIR . '/uploads/deepl-cache/');
define('DAT_HISTORY_TABLE', 'dat_translation_history');
define('DAT_STATS_TABLE', 'dat_translation_stats');

// Include translation providers
require_once DAT_PLUGIN_DIR . 'inc/providers.php';

class DeepLAutoTranslate {
    
    private $translation_service;
    private $supported_languages = [
        'hu' => 'Hungarian',
        'en' => 'English', 
        'de' => 'German',
        'fr' => 'French',
        'es' => 'Spanish',
        'it' => 'Italian',
        'th' => 'Thai',
        'ru' => 'Russian',
        'pl' => 'Polish',
        'pt' => 'Portuguese',
        'nl' => 'Dutch',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'zh' => 'Chinese',
        'ar' => 'Arabic'
    ];
    
    private $monthly_limits = [
        'libre' => 1000000,     // 1M characters/month for free
        'deepl_free' => 500000, // 500K characters/month for free API
        'deepl_pro' => 10000000 // 10M characters/month for pro (example limit)
    ];
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Register hooks
        add_shortcode('deepl_translate', array($this, 'shortcode_translate'));
        add_filter('the_content', array($this, 'auto_translate_content'), 20);
        add_filter('the_title', array($this, 'auto_translate_title'), 20);
        
        // AJAX handlers
        add_action('wp_ajax_dat_translate', array($this, 'ajax_translate'));
        add_action('wp_ajax_nopriv_dat_translate', array($this, 'ajax_translate'));
        add_action('wp_ajax_dat_bulk_translate', array($this, 'ajax_bulk_translate'));
        add_action('wp_ajax_dat_get_history', array($this, 'ajax_get_history'));
        add_action('wp_ajax_dat_clear_history', array($this, 'ajax_clear_history'));
        add_action('wp_ajax_dat_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_dat_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_dat_get_posts', array($this, 'ajax_get_posts'));
        
        // Admin AJAX handlers (csak admin)
        add_action('wp_ajax_dat_admin_translate', array($this, 'ajax_admin_translate'));
        
        // Classified theme support
        add_filter('classified_listing_title', array($this, 'translate_classified_title'), 10, 2);
        add_filter('classified_listing_content', array($this, 'translate_classified_content'), 10, 2);
        
        // Database setup
        register_activation_hook(__FILE__, array($this, 'create_tables'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Cron job for cache cleanup
        add_action('dat_cleanup_cache', array($this, 'cleanup_old_cache'));
        if (!wp_next_scheduled('dat_cleanup_cache')) {
            wp_schedule_event(time(), 'daily', 'dat_cleanup_cache');
        }
    }
    
    public function init() {
        // Create cache directory
        if (!file_exists(DAT_CACHE_DIR)) {
            wp_mkdir_p(DAT_CACHE_DIR);
        }
        
        // Initialize translation service
        $this->init_translation_service();
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('deepl-auto-translate', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('dat_cleanup_cache');
    }
    
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Translation history table
        $history_table = $wpdb->prefix . DAT_HISTORY_TABLE;
        $history_sql = "CREATE TABLE $history_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            original_text longtext NOT NULL,
            translated_text longtext NOT NULL,
            from_lang varchar(5) NOT NULL,
            to_lang varchar(5) NOT NULL,
            provider varchar(20) NOT NULL,
            char_count int(11) NOT NULL,
            post_id int(11) DEFAULT NULL,
            user_id int(11) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            cache_key varchar(32) NOT NULL,
            PRIMARY KEY (id),
            KEY from_lang (from_lang),
            KEY to_lang (to_lang),
            KEY provider (provider),
            KEY created_at (created_at),
            KEY cache_key (cache_key)
        ) $charset_collate;";
        
        // Translation stats table
        $stats_table = $wpdb->prefix . DAT_STATS_TABLE;
        $stats_sql = "CREATE TABLE $stats_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            date_month varchar(7) NOT NULL,
            provider varchar(20) NOT NULL,
            language varchar(5) NOT NULL,
            char_count int(11) NOT NULL DEFAULT 0,
            translation_count int(11) NOT NULL DEFAULT 0,
            cache_hits int(11) NOT NULL DEFAULT 0,
            cache_misses int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY unique_month_provider_lang (date_month, provider, language)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($history_sql);
        dbDelta($stats_sql);
    }
    
    private function init_translation_service() {
        $provider = $this->get_option('provider', 'libre');
        
        if ($provider === 'deepl_free' || $provider === 'deepl_pro') {
            $api_key = $this->get_option('deepl_api_key', '');
            if (!empty($api_key)) {
                define('DEEPL_API_KEY', $api_key);
                $this->translation_service = new TranslationService(new DeepLProvider($api_key, $provider));
            } else {
                // Fallback to LibreTranslate if no API key
                $this->translation_service = new TranslationService(new LibreTranslateProvider());
            }
        } else {
            $libre_url = $this->get_option('libre_url', 'https://libretranslate.com');
            $this->translation_service = new TranslationService(new LibreTranslateProvider($libre_url));
        }
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('dat-ajax', DAT_PLUGIN_URL . 'js/ajax-translate.js', array('jquery'), '2.5', true);
        wp_localize_script('dat-ajax', 'dat_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dat_translate_nonce'),
            'current_lang' => $this->get_current_language(),
            'supported_languages' => $this->supported_languages,
            'strings' => array(
                'translating' => __('Translating...', 'deepl-auto-translate'),
                'translate_content' => __('Translate Content', 'deepl-auto-translate'),
                'translation_failed' => __('Translation failed. Please try again.', 'deepl-auto-translate'),
                'service_unavailable' => __('Translation service is temporarily unavailable.', 'deepl-auto-translate'),
                'no_content' => __('No content found to translate.', 'deepl-auto-translate'),
                'enter_text' => __('Please enter text to translate.', 'deepl-auto-translate'),
                'same_language' => __('Source and target languages cannot be the same.', 'deepl-auto-translate'),
                'copied_clipboard' => __('Translation copied to clipboard!', 'deepl-auto-translate'),
                'quick_translate' => __('Quick Translate', 'deepl-auto-translate'),
                'close' => __('Close', 'deepl-auto-translate')
            )
        ));
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'deepl-') === false) return;
        
        wp_enqueue_script('dat-admin', DAT_PLUGIN_URL . 'js/admin.js', array('jquery'), '2.5', true);
        wp_enqueue_style('dat-admin', DAT_PLUGIN_URL . 'css/admin.css', array(), '2.5');
        
        wp_localize_script('dat-admin', 'dat_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dat_admin_nonce'),
            'supported_languages' => $this->supported_languages
        ));
    }
    
    public function get_current_language() {
        // WPML integration
        if (function_exists('icl_get_current_language')) {
            return icl_get_current_language();
        }
        
        // Polylang integration
        if (function_exists('pll_current_language')) {
            return pll_current_language();
        }
        
        // URL-based detection (fallback)
        if (isset($_SERVER['REQUEST_URI'])) {
            $uri = $_SERVER['REQUEST_URI'];
            if (preg_match('~/([a-z]{2})(/|$)~', $uri, $matches)) {
                $lang = strtolower($matches[1]);
                if (array_key_exists($lang, $this->supported_languages)) {
                    return $lang;
                }
            }
        }
        
        // Default language
        return 'en';
    }
    
    public function shortcode_translate($atts, $content = null) {
        if (is_admin() || !$content) {
            return $content;
        }
        
        $atts = shortcode_atts(array(
            'from' => 'en',
            'to' => $this->get_current_language(),
            'cache' => 'true'
        ), $atts);
        
        // Don't translate if target language is same as source
        if ($atts['from'] === $atts['to']) {
            return $content;
        }
        
        return $this->translate_text($content, $atts['from'], $atts['to'], $atts['cache'] === 'true');
    }
    
    public function auto_translate_content($content) {
        if (is_admin() || empty($content)) {
            return $content;
        }
        
        $auto_translate = $this->get_option('auto_translate', false);
        if (!$auto_translate) {
            return $content;
        }
        
        $current_lang = $this->get_current_language();
        $post_id = get_the_ID();
        
        // Skip if English or contains shortcodes
        if ($current_lang === 'en' || strpos($content, '[deepl_translate') !== false) {
            return $content;
        }
        
        // Check for cached translation in post meta
        $cached_content = get_post_meta($post_id, 'content_' . $current_lang, true);
        if (!empty($cached_content)) {
            return $cached_content;
        }
        
        // Translate and cache
        $translated = $this->translate_text($content, 'en', $current_lang, true);
        if ($translated !== $content) {
            update_post_meta($post_id, 'content_' . $current_lang, $translated);
            return $translated;
        }
        
        return $content;
    }
    
    public function auto_translate_title($title) {
        if (is_admin() || empty($title)) {
            return $title;
        }
        
        $auto_translate = $this->get_option('auto_translate', false);
        if (!$auto_translate) {
            return $title;
        }
        
        $current_lang = $this->get_current_language();
        $post_id = get_the_ID();
        
        if ($current_lang === 'en' || !$post_id) {
            return $title;
        }
        
        // Check for cached translation in post meta
        $cached_title = get_post_meta($post_id, 'title_' . $current_lang, true);
        if (!empty($cached_title)) {
            return $cached_title;
        }
        
        // Translate and cache
        $translated = $this->translate_text($title, 'en', $current_lang, true);
        if ($translated !== $title) {
            update_post_meta($post_id, 'title_' . $current_lang, $translated);
            return $translated;
        }
        
        return $title;
    }
    
    // Classified theme integration
    public function translate_classified_title($title, $post_id) {
        $current_lang = $this->get_current_language();
        if ($current_lang === 'en') return $title;
        
        // Check for stored translation
        $translated_title = get_post_meta($post_id, 'title_' . $current_lang, true);
        if (!empty($translated_title)) {
            return $translated_title;
        }
        
        // Translate and store
        $translated = $this->translate_text($title, 'en', $current_lang, true);
        if ($translated !== $title) {
            update_post_meta($post_id, 'title_' . $current_lang, $translated);
        }
        
        return $translated;
    }
    
    public function translate_classified_content($content, $post_id) {
        $current_lang = $this->get_current_language();
        if ($current_lang === 'en') return $content;
        
        // Check for stored translation
        $translated_content = get_post_meta($post_id, 'content_' . $current_lang, true);
        if (!empty($translated_content)) {
            return $translated_content;
        }
        
        // Translate and store
        $translated = $this->translate_text($content, 'en', $current_lang, true);
        if ($translated !== $content) {
            update_post_meta($post_id, 'content_' . $current_lang, $translated);
        }
        
        return $translated;
    }
    
    private function translate_text($text, $from_lang, $to_lang, $use_cache = true) {
        if (empty($text) || $from_lang === $to_lang) {
            return $text;
        }
        
        // Check monthly limits
        if (!$this->check_monthly_limits($to_lang, strlen($text))) {
            $this->log_debug("Monthly limit exceeded for {$to_lang}");
            return $text;
        }
        
        // Generate cache key
        $cache_key = md5($text . $from_lang . $to_lang);
        $cache_file = DAT_CACHE_DIR . $to_lang . '/' . $cache_key . '.json';
        
        // Check cache first
        if ($use_cache && file_exists($cache_file)) {
            $cached_data = json_decode(file_get_contents($cache_file), true);
            if (isset($cached_data['translated']) && $this->is_cache_valid($cached_data)) {
                $this->log_debug("Cache HIT: {$from_lang} -> {$to_lang}");
                $this->update_stats($from_lang, $to_lang, strlen($text), true);
                return $cached_data['translated'];
            }
        }
        
        // Translate using service
        if (!$this->translation_service) {
            $this->log_debug("Translation service not initialized");
            return $text;
        }
        
        $translated = $this->translation_service->translate($text, $from_lang, $to_lang);
        
        if ($translated && $translated !== $text) {
            // Save to cache
            if ($use_cache) {
                $cache_dir = dirname($cache_file);
                if (!file_exists($cache_dir)) {
                    wp_mkdir_p($cache_dir);
                }
                
                $cache_data = array(
                    'original' => $text,
                    'translated' => $translated,
                    'from_lang' => $from_lang,
                    'to_lang' => $to_lang,
                    'provider' => $this->translation_service->get_provider_name(),
                    'timestamp' => current_time('mysql'),
                    'chars' => strlen($text),
                    'expires' => time() + (30 * 24 * 60 * 60) // 30 days
                );
                
                file_put_contents($cache_file, json_encode($cache_data));
                $this->log_usage($to_lang, $cache_key, strlen($text), $this->translation_service->get_provider_name());
            }
            
            // Save to history
            $this->save_to_history($text, $translated, $from_lang, $to_lang, $cache_key);
            
            // Update stats
            $this->update_stats($from_lang, $to_lang, strlen($text), false);
            
            $this->log_debug("Translation SUCCESS: {$from_lang} -> {$to_lang}");
            return $translated;
        }
        
        $this->log_debug("Translation FAILED: {$from_lang} -> {$to_lang}");
        return $text;
    }
    
    private function is_cache_valid($cached_data) {
        if (!isset($cached_data['expires'])) {
            return true; // Old cache entries without expiration
        }
        return time() < $cached_data['expires'];
    }
    
    private function check_monthly_limits($lang, $char_count) {
        $provider = $this->get_option('provider', 'libre');
        $current_month = date('Y-m');
        
        global $wpdb;
        $stats_table = $wpdb->prefix . DAT_STATS_TABLE;
        
        $current_usage = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(char_count) FROM $stats_table WHERE date_month = %s AND provider = %s AND language = %s",
            $current_month, $provider, $lang
        ));
        
        // Get custom limits or use defaults
        $custom_limits = $this->get_option('monthly_limits', array());
        $default_limits = array(
            'libre' => 1000000,
            'deepl_free' => 500000,
            'deepl_pro' => 10000000
        );
        $monthly_limits = array_merge($default_limits, $custom_limits);
        
        $limit = $monthly_limits[$provider] ?? 500000;
        
        return ($current_usage + $char_count) <= $limit;
    }
    
    private function save_to_history($original, $translated, $from_lang, $to_lang, $cache_key) {
        global $wpdb;
        $history_table = $wpdb->prefix . DAT_HISTORY_TABLE;
        
        $wpdb->insert(
            $history_table,
            array(
                'original_text' => $original,
                'translated_text' => $translated,
                'from_lang' => $from_lang,
                'to_lang' => $to_lang,
                'provider' => $this->translation_service->get_provider_name(),
                'char_count' => strlen($original),
                'post_id' => get_the_ID(),
                'user_id' => get_current_user_id(),
                'cache_key' => $cache_key
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s')
        );
    }
    
    private function update_stats($from_lang, $to_lang, $char_count, $cache_hit) {
        global $wpdb;
        $stats_table = $wpdb->prefix . DAT_STATS_TABLE;
        $current_month = date('Y-m');
        $provider = $this->translation_service->get_provider_name();
        
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $stats_table (date_month, provider, language, char_count, translation_count, cache_hits, cache_misses) 
             VALUES (%s, %s, %s, %d, 1, %d, %d)
             ON DUPLICATE KEY UPDATE 
             char_count = char_count + %d,
             translation_count = translation_count + 1,
             cache_hits = cache_hits + %d,
             cache_misses = cache_misses + %d",
            $current_month, $provider, $to_lang, $char_count, 
            $cache_hit ? 1 : 0, $cache_hit ? 0 : 1,
            $char_count, $cache_hit ? 1 : 0, $cache_hit ? 0 : 1
        ));
    }
    
    private function log_usage($lang, $cache_key, $char_count, $provider) {
        $log_file = DAT_CACHE_DIR . 'usage.log';
        $log_entry = date('Y-m-d H:i:s') . " - {$provider} - {$lang} - {$char_count} chars - {$cache_key}\n";
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    private function log_debug($message) {
        if (WP_DEBUG && WP_DEBUG_LOG) {
            error_log("DAT Plugin: " . $message);
        }
    }
    
    // AJAX handlers
    public function ajax_translate() {
        check_ajax_referer('dat_translate_nonce', 'nonce');
        
        $text = sanitize_textarea_field($_POST['text']);
        $from_lang = sanitize_text_field($_POST['from_lang']);
        $to_lang = sanitize_text_field($_POST['to_lang']);
        
        if (empty($text)) {
            wp_die('No text provided');
        }
        
        $translated = $this->translate_text($text, $from_lang, $to_lang, true);
        
        wp_send_json_success(array(
            'original' => $text,
            'translated' => $translated,
            'from_lang' => $from_lang,
            'to_lang' => $to_lang
        ));
    }
    
    public function ajax_bulk_translate() {
        check_ajax_referer('dat_admin_nonce', 'nonce');
        
        $post_ids = array_map('intval', $_POST['post_ids']);
        $from_lang = sanitize_text_field($_POST['from_lang']);
        $to_lang = sanitize_text_field($_POST['to_lang']);
        $content_type = sanitize_text_field($_POST['content_type']); // 'title', 'content', 'both'
        
        $results = array();
        
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) continue;
            
            $result = array('post_id' => $post_id, 'title' => '', 'content' => '');
            
            if ($content_type === 'title' || $content_type === 'both') {
                $translated_title = $this->translate_text($post->post_title, $from_lang, $to_lang, true);
                $result['title'] = $translated_title;
                
                // Save translated title as meta
                update_post_meta($post_id, 'title_' . $to_lang, $translated_title);
            }
            
            if ($content_type === 'content' || $content_type === 'both') {
                $translated_content = $this->translate_text($post->post_content, $from_lang, $to_lang, true);
                $result['content'] = $translated_content;
                
                // Save translated content as meta
                update_post_meta($post_id, 'content_' . $to_lang, $translated_content);
            }
            
            $results[] = $result;
        }
        
        wp_send_json_success($results);
    }
    
    public function ajax_get_history() {
        check_ajax_referer('dat_admin_nonce', 'nonce');
        
        global $wpdb;
        $history_table = $wpdb->prefix . DAT_HISTORY_TABLE;
        
        $page = intval($_POST['page']) ?: 1;
        $per_page = intval($_POST['per_page']) ?: 20;
        $offset = ($page - 1) * $per_page;
        
        $filter_lang = sanitize_text_field($_POST['filter_lang']);
        $filter_provider = sanitize_text_field($_POST['filter_provider']);
        
        $where_clause = "WHERE 1=1";
        $params = array();
        
        if ($filter_lang) {
            $where_clause .= " AND (from_lang = %s OR to_lang = %s)";
            $params[] = $filter_lang;
            $params[] = $filter_lang;
        }
        
        if ($filter_provider) {
            $where_clause .= " AND provider = %s";
            $params[] = $filter_provider;
        }
        
        $params[] = $per_page;
        $params[] = $offset;
        
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $history_table $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...$params
        ));
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $history_table $where_clause",
            ...array_slice($params, 0, -2)
        ));
        
        wp_send_json_success(array(
            'history' => $history,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page
        ));
    }
    
    public function ajax_clear_history() {
        check_ajax_referer('dat_admin_nonce', 'nonce');
        
        global $wpdb;
        $history_table = $wpdb->prefix . DAT_HISTORY_TABLE;
        
        $days = intval($_POST['days']) ?: 30;
        
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM $history_table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        wp_send_json_success(array(
            'deleted' => $result,
            'message' => sprintf('Deleted %d history entries older than %d days', $result, $days)
        ));
    }
    
    public function ajax_get_stats() {
        check_ajax_referer('dat_admin_nonce', 'nonce');
        
        global $wpdb;
        $stats_table = $wpdb->prefix . DAT_STATS_TABLE;
        
        $current_month = date('Y-m');
        $provider = $this->get_option('provider', 'libre');
        
        $monthly_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT language, SUM(char_count) as chars, SUM(translation_count) as translations, 
                    SUM(cache_hits) as hits, SUM(cache_misses) as misses
             FROM $stats_table 
             WHERE date_month = %s AND provider = %s
             GROUP BY language",
            $current_month, $provider
        ));
        
        // Get custom limits or use defaults
        $custom_limits = $this->get_option('monthly_limits', array());
        $default_limits = array(
            'libre' => 1000000,
            'deepl_free' => 500000,
            'deepl_pro' => 10000000
        );
        $monthly_limits = array_merge($default_limits, $custom_limits);
        
        $limit = $monthly_limits[$provider] ?? 500000;
        $total_chars = array_sum(array_column($monthly_stats, 'chars'));
        
        wp_send_json_success(array(
            'monthly_stats' => $monthly_stats,
            'total_chars' => $total_chars,
            'limit' => $limit,
            'remaining' => $limit - $total_chars,
            'provider' => $provider
        ));
    }
    
    public function ajax_clear_cache() {
        check_ajax_referer('dat_admin_nonce', 'nonce');
        
        $cache_type = sanitize_text_field($_POST['cache_type']);
        $language = sanitize_text_field($_POST['language']);
        
        $cleared = 0;
        
        if ($cache_type === 'all') {
            $cleared = $this->clear_all_cache();
        } elseif ($cache_type === 'expired') {
            $cleared = $this->clear_expired_cache();
        } elseif ($cache_type === 'language' && $language) {
            $cleared = $this->clear_language_cache($language);
        }
        
        wp_send_json_success(array(
            'cleared' => $cleared,
            'message' => sprintf('Cleared %d cache files', $cleared)
        ));
    }
    
    public function ajax_get_posts() {
        check_ajax_referer('dat_admin_nonce', 'nonce');
        
        $post_type = sanitize_text_field($_POST['post_type']);
        $limit = intval($_POST['limit']);
        
        if ($limit > 100) $limit = 100; // Safety limit
        
        $args = array(
            'post_type' => $post_type === 'any' ? array('post', 'page') : $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC'
        );
        
        $posts = get_posts($args);
        
        wp_send_json_success(array(
            'posts' => $posts,
            'total' => count($posts)
        ));
    }
    
    public function ajax_admin_translate() {
        check_ajax_referer('dat_admin_nonce', 'nonce');
        
        $text = sanitize_textarea_field($_POST['text']);
        $from_lang = sanitize_text_field($_POST['from_lang']);
        $to_lang = sanitize_text_field($_POST['to_lang']);
        
        if (empty($text)) {
            wp_send_json_error(array('message' => 'No text provided'));
        }
        
        $translated = $this->translate_text($text, $from_lang, $to_lang, true);
        
        wp_send_json_success(array(
            'original' => $text,
            'translated' => $translated,
            'from_lang' => $from_lang,
            'to_lang' => $to_lang
        ));
    }
    
    public function cleanup_old_cache() {
        if (!is_dir(DAT_CACHE_DIR)) return;
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(DAT_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        $cleaned = 0;
        foreach ($files as $fileinfo) {
            if ($fileinfo->isFile() && $fileinfo->getExtension() === 'json') {
                $content = file_get_contents($fileinfo->getRealPath());
                $data = json_decode($content, true);
                
                if (isset($data['expires']) && time() > $data['expires']) {
                    unlink($fileinfo->getRealPath());
                    $cleaned++;
                }
            }
        }
        
        $this->log_debug("Cleaned up $cleaned expired cache files");
    }
    
    private function clear_all_cache() {
        if (!is_dir(DAT_CACHE_DIR)) return 0;
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(DAT_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        $cleared = 0;
        foreach ($files as $fileinfo) {
            if ($fileinfo->isFile() && $fileinfo->getExtension() === 'json') {
                unlink($fileinfo->getRealPath());
                $cleared++;
            }
        }
        
        return $cleared;
    }
    
    private function clear_expired_cache() {
        return $this->cleanup_old_cache();
    }
    
    private function clear_language_cache($language) {
        $lang_dir = DAT_CACHE_DIR . $language . '/';
        if (!is_dir($lang_dir)) return 0;
        
        $files = glob($lang_dir . '*.json');
        $cleared = 0;
        
        foreach ($files as $file) {
            if (unlink($file)) {
                $cleared++;
            }
        }
        
        return $cleared;
    }
    
    // Admin functionality
    public function admin_menu() {
        add_menu_page(
            'Auto Translate',
            'Auto Translate',
            'manage_options',
            'deepl-auto-translate',
            array($this, 'admin_settings_page'),
            'dashicons-translation',
            81
        );
        
        add_submenu_page(
            'deepl-auto-translate',
            'Bulk Translate',
            'Bulk Translate',
            'manage_options',
            'deepl-bulk-translate',
            array($this, 'admin_bulk_page')
        );
        
        add_submenu_page(
            'deepl-auto-translate',
            'History',
            'History',
            'manage_options',
            'deepl-history',
            array($this, 'admin_history_page')
        );
        
        add_submenu_page(
            'deepl-auto-translate',
            'Cache Manager',
            'Cache Manager',
            'manage_options',
            'deepl-cache-manager',
            array($this, 'admin_cache_page')
        );
        
        add_submenu_page(
            'deepl-auto-translate',
            'Usage & Limits',
            'Usage & Limits',
            'manage_options',
            'deepl-usage-limits',
            array($this, 'admin_usage_page')
        );
        
        add_submenu_page(
            'deepl-auto-translate',
            'Debug Info',
            'Debug Info',
            'manage_options',
            'deepl-debug-info',
            array($this, 'admin_debug_page')
        );
    }
    
    public function admin_settings_page() {
        if (isset($_POST['dat_save_settings']) && check_admin_referer('dat_save_settings')) {
            $this->set_option('provider', sanitize_text_field($_POST['provider']));
            $this->set_option('deepl_api_key', sanitize_text_field($_POST['deepl_api_key']));
            $this->set_option('default_from_lang', sanitize_text_field($_POST['default_from_lang']));
            $this->set_option('enabled_languages', array_map('sanitize_text_field', $_POST['enabled_languages'] ?? []));
            $this->set_option('auto_translate', isset($_POST['auto_translate']));
            $this->set_option('cache_ttl', intval($_POST['cache_ttl']));
            $this->set_option('libre_url', sanitize_url($_POST['libre_url']));
            
            // Save custom monthly limits
            $custom_limits = array(
                'libre' => intval($_POST['limit_libre']),
                'deepl_free' => intval($_POST['limit_deepl_free']),
                'deepl_pro' => intval($_POST['limit_deepl_pro'])
            );
            $this->set_option('monthly_limits', $custom_limits);
            
            echo '<div class="updated"><p>Settings saved successfully!</p></div>';
            
            // Reinitialize translation service
            $this->init_translation_service();
        }
        
        $provider = $this->get_option('provider', 'libre');
        $deepl_api_key = $this->get_option('deepl_api_key', '');
        $default_from_lang = $this->get_option('default_from_lang', 'en');
        $enabled_languages = $this->get_option('enabled_languages', array('hu', 'en', 'de'));
        $auto_translate = $this->get_option('auto_translate', false);
        $cache_ttl = $this->get_option('cache_ttl', 30);
        $libre_url = $this->get_option('libre_url', 'https://libretranslate.com');
        
        // Get custom limits or use defaults
        $custom_limits = $this->get_option('monthly_limits', array());
        $default_limits = array(
            'libre' => 1000000,
            'deepl_free' => 500000,
            'deepl_pro' => 10000000
        );
        $monthly_limits = array_merge($default_limits, $custom_limits);
        
        ?>
        <div class="wrap">
            <h1>Auto Translate Settings</h1>
            <form method="post">
                <?php wp_nonce_field('dat_save_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th>Translation Provider</th>
                        <td>
                            <select name="provider" id="provider">
                                <option value="libre" <?php selected($provider, 'libre'); ?>>LibreTranslate (Free)</option>
                                <option value="deepl_free" <?php selected($provider, 'deepl_free'); ?>>DeepL Free API</option>
                                <option value="deepl_pro" <?php selected($provider, 'deepl_pro'); ?>>DeepL Pro API</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="deepl-api-key-row" <?php echo $provider !== 'deepl' ? 'style="display:none;"' : ''; ?>>
                        <th>DeepL API Key</th>
                        <td>
                            <input type="text" name="deepl_api_key" value="<?php echo esc_attr($deepl_api_key); ?>" size="48" />
                            <p class="description">Get your free API key from <a href="https://www.deepl.com/pro-api" target="_blank">DeepL</a>.</p>
                        </td>
                    </tr>
                    <tr id="libre-url-row" <?php echo $provider !== 'libre' ? 'style="display:none;"' : ''; ?>>
                        <th>LibreTranslate URL</th>
                        <td>
                            <input type="url" name="libre_url" value="<?php echo esc_attr($libre_url); ?>" size="48" />
                            <p class="description">LibreTranslate instance URL. Default: https://libretranslate.com</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Default Source Language</th>
                        <td>
                            <select name="default_from_lang">
                                <?php foreach ($this->supported_languages as $code => $name): ?>
                                    <option value="<?php echo $code; ?>" <?php selected($default_from_lang, $code); ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Enabled Languages</th>
                        <td>
                            <?php foreach ($this->supported_languages as $code => $name): ?>
                                <label style="display: inline-block; width: 120px; margin-right: 10px;">
                                    <input type="checkbox" name="enabled_languages[]" value="<?php echo $code; ?>" <?php checked(in_array($code, $enabled_languages)); ?> />
                                    <?php echo $name; ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Auto Translation</th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_translate" <?php checked($auto_translate); ?> />
                                Enable automatic translation of content and titles
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Cache TTL (days)</th>
                        <td>
                            <input type="number" name="cache_ttl" value="<?php echo $cache_ttl; ?>" min="1" max="365" />
                            <p class="description">How long to keep cached translations (1-365 days)</p>
                        </td>
                    </tr>
                </table>
                
                <h2>Monthly Character Limits</h2>
                <table class="form-table">
                    <tr>
                        <th>LibreTranslate Limit</th>
                        <td>
                            <input type="number" name="limit_libre" value="<?php echo $monthly_limits['libre']; ?>" min="0" step="1000" />
                            <p class="description">Monthly character limit for LibreTranslate (default: 1,000,000)</p>
                        </td>
                    </tr>
                    <tr>
                        <th>DeepL Free API Limit</th>
                        <td>
                            <input type="number" name="limit_deepl_free" value="<?php echo $monthly_limits['deepl_free']; ?>" min="0" step="1000" />
                            <p class="description">Monthly character limit for DeepL Free API (default: 500,000)</p>
                        </td>
                    </tr>
                    <tr>
                        <th>DeepL Pro API Limit</th>
                        <td>
                            <input type="number" name="limit_deepl_pro" value="<?php echo $monthly_limits['deepl_pro']; ?>" min="0" step="1000" />
                            <p class="description">Monthly character limit for DeepL Pro API (default: 10,000,000)</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Settings', 'primary', 'dat_save_settings'); ?>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                $('#provider').change(function() {
                    var provider = $(this).val();
                    if (provider === 'deepl_free' || provider === 'deepl_pro') {
                        $('#deepl-api-key-row').show();
                        $('#libre-url-row').hide();
                    } else if (provider === 'libre') {
                        $('#deepl-api-key-row').hide();
                        $('#libre-url-row').show();
                    } else {
                        $('#deepl-api-key-row').hide();
                        $('#libre-url-row').hide();
                    }
                });
            });
            </script>
        </div>
        <?php
    }
    
    public function admin_bulk_page() {
        ?>
        <div class="wrap">
            <h1>Bulk Translate</h1>
            <div id="bulk-translate-form">
                <table class="form-table">
                    <tr>
                        <th>From Language</th>
                        <td>
                            <select id="bulk-from-lang">
                                <?php foreach ($this->supported_languages as $code => $name): ?>
                                    <option value="<?php echo $code; ?>" <?php selected($code, 'en'); ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>To Language</th>
                        <td>
                            <select id="bulk-to-lang">
                                <?php foreach ($this->supported_languages as $code => $name): ?>
                                    <option value="<?php echo $code; ?>" <?php selected($code, 'hu'); ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Content Type</th>
                        <td>
                            <select id="bulk-content-type">
                                <option value="both">Title and Content</option>
                                <option value="title">Title Only</option>
                                <option value="content">Content Only</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Post Type</th>
                        <td>
                            <select id="bulk-post-type">
                                <option value="post">Posts</option>
                                <option value="page">Pages</option>
                                <option value="any">All Post Types</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Limit</th>
                        <td>
                            <input type="number" id="bulk-limit" value="10" min="1" max="100" />
                            <p class="description">Number of posts to translate (1-100)</p>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="button" id="start-bulk-translate" class="button button-primary">Start Bulk Translation</button>
                    <button type="button" id="stop-bulk-translate" class="button" style="display:none;">Stop</button>
                </p>
            </div>
            
            <div id="bulk-progress" style="display:none;">
                <h3>Translation Progress</h3>
                <div id="progress-bar" style="width: 100%; background: #f1f1f1; border-radius: 3px;">
                    <div id="progress-fill" style="width: 0%; height: 20px; background: #0073aa; border-radius: 3px; transition: width 0.3s;"></div>
                </div>
                <p id="progress-text">0 / 0 posts translated</p>
            </div>
            
            <div id="bulk-results" style="display:none;">
                <h3>Translation Results</h3>
                <div id="results-list"></div>
            </div>
        </div>
        <?php
    }
    
    public function admin_history_page() {
        ?>
        <div class="wrap">
            <h1>Translation History</h1>
            
            <div id="history-filters">
                <select id="filter-language">
                    <option value="">All Languages</option>
                    <?php foreach ($this->supported_languages as $code => $name): ?>
                        <option value="<?php echo $code; ?>"><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select id="filter-provider">
                    <option value="">All Providers</option>
                    <option value="libre">LibreTranslate</option>
                    <option value="deepl">DeepL</option>
                </select>
                
                <button type="button" id="apply-filters" class="button">Apply Filters</button>
                <button type="button" id="clear-history-btn" class="button button-secondary">Clear Old History</button>
            </div>
            
            <div id="history-table">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>From → To</th>
                            <th>Provider</th>
                            <th>Characters</th>
                            <th>Original Text</th>
                            <th>Translated Text</th>
                        </tr>
                    </thead>
                    <tbody id="history-tbody">
                        <!-- History entries will be loaded here via AJAX -->
                    </tbody>
                </table>
            </div>
            
            <div id="history-pagination"></div>
        </div>
        
        <!-- Clear History Modal -->
        <div id="clear-history-modal" style="display: none;">
            <div class="modal-content">
                <h3>Clear Translation History</h3>
                <p>Delete history entries older than:</p>
                <select id="clear-history-days">
                    <option value="7">7 days</option>
                    <option value="30" selected>30 days</option>
                    <option value="90">90 days</option>
                    <option value="365">1 year</option>
                </select>
                <p>
                    <button type="button" id="confirm-clear-history" class="button button-primary">Clear History</button>
                    <button type="button" id="cancel-clear-history" class="button">Cancel</button>
                </p>
            </div>
        </div>
        <?php
    }
    
    public function admin_cache_page() {
        $cache_stats = $this->get_cache_stats();
        ?>
        <div class="wrap">
            <h1>Cache Manager</h1>
            
            <div class="cache-stats">
                <h3>Cache Statistics</h3>
                <table class="form-table">
                    <tr>
                        <th>Total Cache Files</th>
                        <td><?php echo number_format($cache_stats['total_files']); ?></td>
                    </tr>
                    <tr>
                        <th>Total Cache Size</th>
                        <td><?php echo $this->format_bytes($cache_stats['total_size']); ?></td>
                    </tr>
                    <tr>
                        <th>Cache Directory</th>
                        <td><?php echo DAT_CACHE_DIR; ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="cache-actions">
                <h3>Cache Actions</h3>
                <p>
                    <button type="button" id="clear-all-cache" class="button button-primary">Clear All Cache</button>
                    <button type="button" id="clear-expired-cache" class="button">Clear Expired Cache</button>
                </p>
                
                <h4>Clear by Language</h4>
                <select id="cache-language">
                    <?php foreach ($this->supported_languages as $code => $name): ?>
                        <option value="<?php echo $code; ?>"><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="clear-language-cache" class="button">Clear Language Cache</button>
            </div>
            
            <div id="cache-results" style="display:none;">
                <h3>Results</h3>
                <div id="cache-message"></div>
            </div>
        </div>
        <?php
    }
    
    public function admin_usage_page() {
        if (isset($_POST['dat_save_limits']) && check_admin_referer('dat_save_limits')) {
            $custom_limits = array(
                'libre' => intval($_POST['limit_libre']),
                'deepl_free' => intval($_POST['limit_deepl_free']),
                'deepl_pro' => intval($_POST['limit_deepl_pro'])
            );
            $this->set_option('monthly_limits', $custom_limits);
            echo '<div class="updated"><p>Limits updated successfully!</p></div>';
        }
        
        // Get current limits
        $custom_limits = $this->get_option('monthly_limits', array());
        $default_limits = array(
            'libre' => 1000000,
            'deepl_free' => 500000,
            'deepl_pro' => 10000000
        );
        $monthly_limits = array_merge($default_limits, $custom_limits);
        
        // Get current usage stats
        global $wpdb;
        $stats_table = $wpdb->prefix . DAT_STATS_TABLE;
        $current_month = date('Y-m');
        
        $usage_stats = array();
        foreach (array('libre', 'deepl_free', 'deepl_pro') as $provider) {
            $usage = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(char_count) FROM $stats_table WHERE date_month = %s AND provider = %s",
                $current_month, $provider
            ));
            $usage_stats[$provider] = intval($usage);
        }
        
        ?>
        <div class="wrap">
            <h1>Usage & Limits Management</h1>
            
            <div class="stats-grid">
                <?php foreach (array('libre' => 'LibreTranslate', 'deepl_free' => 'DeepL Free', 'deepl_pro' => 'DeepL Pro') as $provider => $name): ?>
                    <div class="stats-card provider-<?php echo $provider; ?>">
                        <h3><?php echo $name; ?></h3>
                        <?php
                        $used = $usage_stats[$provider];
                        $limit = $monthly_limits[$provider];
                        $percentage = $limit > 0 ? ($used / $limit) * 100 : 0;
                        $status_class = '';
                        if ($percentage >= 90) $status_class = 'danger';
                        elseif ($percentage >= 75) $status_class = 'warning';
                        ?>
                        <div class="usage-meter">
                            <div class="usage-fill <?php echo $status_class; ?>" style="width: <?php echo min($percentage, 100); ?>%;"></div>
                        </div>
                        <p><strong>Used:</strong> <?php echo number_format($used); ?> / <?php echo number_format($limit); ?> characters</p>
                        <p><strong>Remaining:</strong> <?php echo number_format(max(0, $limit - $used)); ?> characters</p>
                        <p><strong>Usage:</strong> <?php echo number_format($percentage, 1); ?>%</p>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <h2>Update Monthly Limits</h2>
            <form method="post">
                <?php wp_nonce_field('dat_save_limits'); ?>
                <table class="form-table">
                    <tr>
                        <th>LibreTranslate Limit</th>
                        <td>
                            <input type="number" name="limit_libre" value="<?php echo $monthly_limits['libre']; ?>" min="0" step="1000" style="width: 150px;" />
                            <span class="description">characters per month</span>
                        </td>
                    </tr>
                    <tr>
                        <th>DeepL Free API Limit</th>
                        <td>
                            <input type="number" name="limit_deepl_free" value="<?php echo $monthly_limits['deepl_free']; ?>" min="0" step="1000" style="width: 150px;" />
                            <span class="description">characters per month</span>
                        </td>
                    </tr>
                    <tr>
                        <th>DeepL Pro API Limit</th>
                        <td>
                            <input type="number" name="limit_deepl_pro" value="<?php echo $monthly_limits['deepl_pro']; ?>" min="0" step="1000" style="width: 150px;" />
                            <span class="description">characters per month</span>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Update Limits', 'primary', 'dat_save_limits'); ?>
            </form>
            
            <h2>Usage History</h2>
            <div id="usage-history">
                <?php
                // Get last 6 months usage
                $history = $wpdb->get_results("
                    SELECT date_month, provider, SUM(char_count) as total_chars 
                    FROM $stats_table 
                    WHERE date_month >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 6 MONTH), '%Y-%m')
                    GROUP BY date_month, provider 
                    ORDER BY date_month DESC, provider
                ");
                
                if ($history): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Provider</th>
                                <th>Characters Used</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row): ?>
                                <tr>
                                    <td><?php echo $row->date_month; ?></td>
                                    <td>
                                        <?php 
                                        $provider_names = array(
                                            'libre' => 'LibreTranslate',
                                            'deepl_free' => 'DeepL Free',
                                            'deepl_pro' => 'DeepL Pro'
                                        );
                                        echo $provider_names[$row->provider] ?? $row->provider;
                                        ?>
                                    </td>
                                    <td><?php echo number_format($row->total_chars); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No usage history available.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    public function admin_debug_page() {
        $debug_info = $this->get_debug_info();
        ?>
        <div class="wrap">
            <h1>Debug Information</h1>
            
            <div class="debug-section">
                <h3>Plugin Information</h3>
                <table class="form-table">
                    <tr>
                        <th>Plugin Version</th>
                        <td>2.5</td>
                    </tr>
                    <tr>
                        <th>WordPress Version</th>
                        <td><?php echo get_bloginfo('version'); ?></td>
                    </tr>
                    <tr>
                        <th>PHP Version</th>
                        <td><?php echo phpversion(); ?></td>
                    </tr>
                    <tr>
                        <th>Current Language</th>
                        <td><?php echo $this->get_current_language(); ?></td>
                    </tr>
                    <tr>
                        <th>Translation Provider</th>
                        <td><?php echo $this->get_option('provider', 'libre'); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="debug-section">
                <h3>Plugin Status</h3>
                <table class="form-table">
                    <tr>
                        <th>Cache Directory</th>
                        <td>
                            <?php if (is_writable(DAT_CACHE_DIR)): ?>
                                <span style="color: green;">✓ Writable</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Not writable</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Database Tables</th>
                        <td>
                            <?php if ($debug_info['tables_exist']): ?>
                                <span style="color: green;">✓ Created</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Missing</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>WPML Integration</th>
                        <td>
                            <?php if (function_exists('icl_get_current_language')): ?>
                                <span style="color: green;">✓ Active</span>
                            <?php else: ?>
                                <span style="color: orange;">- Not detected</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Polylang Integration</th>
                        <td>
                            <?php if (function_exists('pll_current_language')): ?>
                                <span style="color: green;">✓ Active</span>
                            <?php else: ?>
                                <span style="color: orange;">- Not detected</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="debug-section">
                <h3>Recent Activity</h3>
                <div id="debug-stats">
                    <!-- Stats will be loaded via AJAX -->
                </div>
            </div>
            
            <div class="debug-section">
                <h3>Test Translation</h3>
                <form id="test-translation-form">
                    <table class="form-table">
                        <tr>
                            <th>Text</th>
                            <td><textarea id="test-text" rows="3" cols="50">Hello, this is a test translation.</textarea></td>
                        </tr>
                        <tr>
                            <th>From</th>
                            <td>
                                <select id="test-from-lang">
                                    <?php foreach ($this->supported_languages as $code => $name): ?>
                                        <option value="<?php echo $code; ?>" <?php selected($code, 'en'); ?>><?php echo $name; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>To</th>
                            <td>
                                <select id="test-to-lang">
                                    <?php foreach ($this->supported_languages as $code => $name): ?>
                                        <option value="<?php echo $code; ?>" <?php selected($code, 'hu'); ?>><?php echo $name; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" id="test-translate" class="button button-primary">Test Translation</button>
                    </p>
                </form>
                
                <div id="test-results" style="display:none;">
                    <h4>Result:</h4>
                    <div id="test-translation-result"></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    // Helper methods
    private function get_option($key, $default = null) {
        return get_option('dat_' . $key, $default);
    }
    
    private function set_option($key, $value) {
        return update_option('dat_' . $key, $value);
    }
    
    private function get_cache_stats() {
        $stats = array(
            'total_files' => 0,
            'total_size' => 0
        );
        
        if (!is_dir(DAT_CACHE_DIR)) {
            return $stats;
        }
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(DAT_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $stats['total_files']++;
                $stats['total_size'] += $file->getSize();
            }
        }
        
        return $stats;
    }
    
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    private function get_debug_info() {
        global $wpdb;
        
        $history_table = $wpdb->prefix . DAT_HISTORY_TABLE;
        $stats_table = $wpdb->prefix . DAT_STATS_TABLE;
        
        $tables_exist = (
            $wpdb->get_var("SHOW TABLES LIKE '$history_table'") === $history_table &&
            $wpdb->get_var("SHOW TABLES LIKE '$stats_table'") === $stats_table
        );
        
        return array(
            'tables_exist' => $tables_exist
        );
    }
}

// Initialize the plugin
new DeepLAutoTranslate();