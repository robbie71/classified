<?php
/**
 * Translation service providers
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

/**
 * Translation Service Manager
 */
class TranslationService {
    private $provider;
    
    public function __construct($provider) {
        $this->provider = $provider;
    }
    
    public function translate($text, $from_lang, $to_lang) {
        return $this->provider->translate($text, $from_lang, $to_lang);
    }
    
    public function get_provider_name() {
        return $this->provider->get_name();
    }
    
    public function is_available() {
        return $this->provider->is_available();
    }
}

/**
 * LibreTranslate Provider
 */
class LibreTranslateProvider {
    private $api_url;
    
    public function __construct($api_url = 'https://libretranslate.com') {
        $this->api_url = rtrim($api_url, '/') . '/translate';
    }
    
    public function translate($text, $from_lang, $to_lang) {
        if (empty($text) || $from_lang === $to_lang) {
            return $text;
        }
        
        $data = array(
            'q' => $text,
            'source' => $from_lang,
            'target' => $to_lang,
            'format' => 'text'
        );
        
        $response = wp_remote_post($this->api_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('LibreTranslate API Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (isset($result['translatedText'])) {
            return $result['translatedText'];
        }
        
        if (isset($result['error'])) {
            error_log('LibreTranslate API Error: ' . $result['error']);
        }
        
        return false;
    }
    
    public function get_name() {
        return 'libre';
    }
    
    public function is_available() {
        $response = wp_remote_get($this->api_url, array('timeout' => 5));
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
}

/**
 * DeepL Provider
 */
class DeepLProvider {
    private $api_key;
    private $api_url;
    private $is_pro;
    
    public function __construct($api_key = null) {
        $this->api_key = $api_key ?: (defined('DEEPL_API_KEY') ? DEEPL_API_KEY : '');
        $this->is_pro = $this->is_pro_key($this->api_key);
        $this->api_url = $this->is_pro 
            ? 'https://api.deepl.com/v2/translate'
            : 'https://api-free.deepl.com/v2/translate';
    }
    
    private function is_pro_key($key) {
        return !empty($key) && !str_ends_with($key, ':fx');
    }
    
    public function translate($text, $from_lang, $to_lang) {
        if (empty($this->api_key) || empty($text) || $from_lang === $to_lang) {
            return false;
        }
        
        // Map language codes for DeepL
        $deepl_from = $this->map_language_code($from_lang);
        $deepl_to = $this->map_language_code($to_lang);
        
        if (!$deepl_from || !$deepl_to) {
            return false;
        }
        
        $data = array(
            'text' => $text,
            'source_lang' => strtoupper($deepl_from),
            'target_lang' => strtoupper($deepl_to),
            'preserve_formatting' => '1'
        );
        
        $response = wp_remote_post($this->api_url, array(
            'headers' => array(
                'Authorization' => 'DeepL-Auth-Key ' . $this->api_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => http_build_query($data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('DeepL API Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (isset($result['translations'][0]['text'])) {
            return $result['translations'][0]['text'];
        }
        
        if (isset($result['message'])) {
            error_log('DeepL API Error: ' . $result['message']);
        }
        
        return false;
    }
    
    private function map_language_code($lang_code) {
        $mapping = array(
            'en' => 'EN',
            'de' => 'DE',
            'fr' => 'FR',
            'es' => 'ES',
            'it' => 'IT',
            'pt' => 'PT',
            'ru' => 'RU',
            'ja' => 'JA',
            'zh' => 'ZH',
            'pl' => 'PL',
            'nl' => 'NL',
            'hu' => 'HU',
            'ko' => 'KO',
            'ar' => 'AR',
            'th' => 'TH'  // Note: Thai may not be supported by DeepL
        );
        
        return $mapping[$lang_code] ?? null;
    }
    
    public function get_name() {
        return 'deepl';
    }
    
    public function is_available() {
        if (empty($this->api_key)) {
            return false;
        }
        
        $response = wp_remote_get($this->api_url . '/../usage', array(
            'headers' => array(
                'Authorization' => 'DeepL-Auth-Key ' . $this->api_key,
            ),
            'timeout' => 5
        ));
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    
    public function get_usage() {
        if (empty($this->api_key)) {
            return false;
        }
        
        $response = wp_remote_get($this->api_url . '/../usage', array(
            'headers' => array(
                'Authorization' => 'DeepL-Auth-Key ' . $this->api_key,
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
}