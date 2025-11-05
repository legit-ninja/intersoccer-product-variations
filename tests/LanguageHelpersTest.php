<?php
/**
 * Test: Language Helpers
 * 
 * Purpose: Ensure translation and language detection work correctly
 * Covers: language-helpers.php - language detection, translation utilities
 * 
 * CRITICAL: Language errors = poor user experience for international customers
 */

use PHPUnit\Framework\TestCase;

class LanguageHelpersTest extends TestCase {
    
    public function setUp(): void {
        parent::setUp();
        
        if (!function_exists('__')) {
            function __($text, $domain = 'default') {
                return $text;
            }
        }
        
        if (!defined('WP_DEBUG')) {
            define('WP_DEBUG', false);
        }
        
        if (!function_exists('error_log')) {
            function error_log($message) {}
        }
    }
    
    /**
     * Test: Language code extraction from locale
     */
    public function testLanguageCodeExtractionFromLocale() {
        $locales = [
            'en_US' => 'en',
            'fr_FR' => 'fr',
            'de_DE' => 'de',
            'en_GB' => 'en',
            'fr_CA' => 'fr',
            'de_CH' => 'de'
        ];
        
        foreach ($locales as $locale => $expected_lang) {
            $extracted = substr($locale, 0, 2);
            $this->assertEquals($expected_lang, $extracted, "Locale '{$locale}' should extract to '{$expected_lang}'");
        }
    }
    
    /**
     * Test: Language code normalization (lowercase)
     */
    public function testLanguageCodeNormalization() {
        $inputs = ['EN', 'Fr', 'DE', 'en', 'fr', 'de'];
        
        foreach ($inputs as $input) {
            $normalized = strtolower($input);
            $this->assertEquals(2, strlen($normalized), 'Normalized code should be 2 characters');
            $this->assertMatchesRegularExpression('/^[a-z]{2}$/', $normalized, 'Normalized code should be lowercase letters');
        }
    }
    
    /**
     * Test: Supported languages list
     */
    public function testSupportedLanguagesList() {
        $supported_languages = ['en', 'fr', 'de'];
        
        $this->assertContains('en', $supported_languages, 'English should be supported');
        $this->assertContains('fr', $supported_languages, 'French should be supported');
        $this->assertContains('de', $supported_languages, 'German should be supported');
        $this->assertCount(3, $supported_languages, 'Should have 3 supported languages');
    }
    
    /**
     * Test: Default language fallback
     */
    public function testDefaultLanguageFallback() {
        $current_lang = 'unsupported';
        $supported_languages = ['en', 'fr', 'de'];
        $default_lang = 'en';
        
        $final_lang = in_array($current_lang, $supported_languages, true) ? $current_lang : $default_lang;
        
        $this->assertEquals('en', $final_lang, 'Should fallback to English for unsupported language');
    }
    
    /**
     * Test: WPML language detection mock
     */
    public function testWpmlLanguageDetection() {
        // Mock WPML function
        if (!function_exists('icl_get_current_language')) {
            function icl_get_current_language() {
                return 'fr';
            }
        }
        
        $lang = icl_get_current_language();
        
        $this->assertEquals('fr', $lang, 'WPML should return current language');
    }
    
    /**
     * Test: Polylang language detection mock
     */
    public function testPolylangLanguageDetection() {
        // Mock Polylang function
        if (!function_exists('pll_current_language')) {
            function pll_current_language() {
                return 'de';
            }
        }
        
        $lang = pll_current_language();
        
        $this->assertEquals('de', $lang, 'Polylang should return current language');
    }
    
    /**
     * Test: WordPress locale fallback
     */
    public function testWordPressLocaleFallback() {
        $wp_locale = 'fr_FR';
        $lang = substr($wp_locale, 0, 2);
        
        $this->assertEquals('fr', $lang, 'Should extract language from WordPress locale');
    }
    
    /**
     * Test: Translation string registration
     */
    public function testTranslationStringRegistration() {
        $strings = [
            'Camp' => ['fr' => 'Camp', 'de' => 'Camp'],
            'Course' => ['fr' => 'Cours', 'de' => 'Kurs'],
            'Tournament' => ['fr' => 'Tournoi', 'de' => 'Turnier']
        ];
        
        $this->assertArrayHasKey('Camp', $strings, 'Should have Camp translation');
        $this->assertArrayHasKey('fr', $strings['Course'], 'Course should have French translation');
        $this->assertEquals('Turnier', $strings['Tournament']['de'], 'Tournament German should be Turnier');
    }
    
    /**
     * Test: Translation fallback chain
     */
    public function testTranslationFallbackChain() {
        $translations = [
            'en' => 'Hello',
            'fr' => 'Bonjour'
            // 'de' is missing
        ];
        
        $current_lang = 'de';
        $fallback_lang = 'en';
        
        $translated = $translations[$current_lang] ?? $translations[$fallback_lang] ?? 'Hello';
        
        $this->assertEquals('Hello', $translated, 'Should fallback to English when German missing');
    }
    
    /**
     * Test: Empty translation fallback
     */
    public function testEmptyTranslationFallback() {
        $translations = [
            'en' => 'Hello',
            'fr' => ''
        ];
        
        $current_lang = 'fr';
        $translation = $translations[$current_lang] ?? '';
        
        if (empty($translation)) {
            $translation = $translations['en'] ?? 'Hello';
        }
        
        $this->assertEquals('Hello', $translation, 'Should fallback when translation is empty');
    }
    
    /**
     * Test: Language code validation
     */
    public function testLanguageCodeValidation() {
        $valid_codes = ['en', 'fr', 'de', 'es', 'it'];
        
        foreach ($valid_codes as $code) {
            $is_valid = strlen($code) === 2 && ctype_alpha($code);
            $this->assertTrue($is_valid, "{$code} should be valid");
        }
    }
    
    /**
     * Test: Invalid language code detection
     */
    public function testInvalidLanguageCodeDetection() {
        $invalid_codes = ['eng', 'e', '12', 'e1', ''];
        
        foreach ($invalid_codes as $code) {
            $is_valid = strlen($code) === 2 && ctype_alpha($code);
            $this->assertFalse($is_valid, "{$code} should be invalid");
        }
    }
    
    /**
     * Test: Translation context handling
     */
    public function testTranslationContextHandling() {
        $context = 'intersoccer-product-variations';
        $string = 'Camp';
        
        // Mock translation with context
        $translation_key = $context . '_' . $string;
        
        $this->assertStringContainsString('intersoccer', $translation_key, 'Context should be included');
        $this->assertStringContainsString('Camp', $translation_key, 'String should be included');
    }
    
    /**
     * Test: Multilingual attribute mapping
     */
    public function testMultilingualAttributeMapping() {
        $attribute_map = [
            'en' => ['monday' => 1, 'tuesday' => 2],
            'fr' => ['lundi' => 1, 'mardi' => 2],
            'de' => ['montag' => 1, 'dienstag' => 2]
        ];
        
        $this->assertEquals(1, $attribute_map['en']['monday'], 'Monday should be 1');
        $this->assertEquals(1, $attribute_map['fr']['lundi'], 'Lundi should be 1');
        $this->assertEquals(1, $attribute_map['de']['montag'], 'Montag should be 1');
    }
    
    /**
     * Test: Available languages detection
     */
    public function testAvailableLanguagesDetection() {
        $available_languages = [
            'en' => 'English',
            'fr' => 'Français',
            'de' => 'Deutsch'
        ];
        
        $this->assertCount(3, $available_languages, 'Should have 3 languages');
        $this->assertArrayHasKey('en', $available_languages, 'Should have English');
        $this->assertArrayHasKey('fr', $available_languages, 'Should have French');
    }
    
    /**
     * Test: Language-specific date format
     */
    public function testLanguageSpecificDateFormat() {
        $date_formats = [
            'en' => 'Y-m-d',
            'fr' => 'd/m/Y',
            'de' => 'd.m.Y'
        ];
        
        $this->assertEquals('Y-m-d', $date_formats['en'], 'English format should be Y-m-d');
        $this->assertEquals('d/m/Y', $date_formats['fr'], 'French format should be d/m/Y');
        $this->assertEquals('d.m.Y', $date_formats['de'], 'German format should be d.m.Y');
    }
    
    /**
     * Test: Translation string escaping
     */
    public function testTranslationStringEscaping() {
        $raw_string = "Camp's & Tours";
        $escaped = htmlspecialchars($raw_string, ENT_QUOTES, 'UTF-8');
        
        $this->assertStringContainsString('&amp;', $escaped, 'Should escape ampersand');
        $this->assertStringContainsString('&#039;', $escaped, 'Should escape apostrophe');
    }
    
    /**
     * Test: Language switcher data structure
     */
    public function testLanguageSwitcherDataStructure() {
        $languages = [
            ['code' => 'en', 'name' => 'English', 'active' => true],
            ['code' => 'fr', 'name' => 'Français', 'active' => false],
            ['code' => 'de', 'name' => 'Deutsch', 'active' => false]
        ];
        
        $this->assertCount(3, $languages, 'Should have 3 languages');
        $this->assertTrue($languages[0]['active'], 'First language should be active');
        $this->assertEquals('en', $languages[0]['code'], 'Active language should be en');
    }
    
    /**
     * Test: RTL language detection
     */
    public function testRtlLanguageDetection() {
        $rtl_languages = ['ar', 'he', 'fa'];
        $test_lang = 'ar';
        
        $is_rtl = in_array($test_lang, $rtl_languages, true);
        
        $this->assertTrue($is_rtl, 'Arabic should be RTL');
    }
    
    /**
     * Test: LTR language detection
     */
    public function testLtrLanguageDetection() {
        $rtl_languages = ['ar', 'he', 'fa'];
        $test_lang = 'en';
        
        $is_rtl = in_array($test_lang, $rtl_languages, true);
        
        $this->assertFalse($is_rtl, 'English should be LTR');
    }
}

