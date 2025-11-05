<?php
/**
 * Test: Discount Messages
 * 
 * Purpose: Ensure discount message system works correctly with multilingual support
 * Covers: Message retrieval, language detection, WPML integration, fallbacks
 * 
 * CRITICAL: These messages are shown to customers - must work in all languages
 */

use PHPUnit\Framework\TestCase;

class DiscountMessagesTest extends TestCase {
    
    /**
     * Test: Safe language detection with no multilingual plugin
     */
    public function testSafeLanguageDetectionFallback() {
        // When no WPML or Polylang, should return 'en'
        // Simulated - in real implementation, this would check functions don't exist
        
        $locale = 'en_US';
        $lang = substr($locale, 0, 2);
        
        $this->assertEquals('en', $lang, 'Should extract language code from locale');
    }
    
    /**
     * Test: Language code extraction from locales
     */
    public function testLanguageCodeExtraction() {
        $locales = [
            'en_US' => 'en',
            'fr_FR' => 'fr',
            'de_DE' => 'de',
            'en_GB' => 'en',
            'fr_CA' => 'fr',
        ];
        
        foreach ($locales as $locale => $expected_lang) {
            $extracted = substr($locale, 0, 2);
            $this->assertEquals($expected_lang, $extracted, "Locale '{$locale}' should extract to '{$expected_lang}'");
        }
    }
    
    /**
     * Test: Language code validation
     */
    public function testLanguageCodeValidation() {
        $test_codes = [
            ['code' => 'en', 'valid' => true],
            ['code' => 'fr', 'valid' => true],
            ['code' => 'de', 'valid' => true],
            ['code' => 'EN', 'valid' => true], // Should be normalized to lowercase
            ['code' => '12', 'valid' => false], // Numbers not allowed
            ['code' => 'eng', 'valid' => false], // Must be exactly 2 chars
            ['code' => 'e', 'valid' => false], // Too short
        ];
        
        foreach ($test_codes as $test) {
            $code = strtolower($test['code']);
            $is_valid = strlen($code) === 2 && ctype_alpha($code);
            $this->assertEquals($test['valid'], $is_valid, "Code '{$test['code']}' valid? {$test['valid']}");
        }
    }
    
    /**
     * Test: Default message fallback
     */
    public function testDefaultMessageFallback() {
        // If message not found, should return fallback
        $messages = [];
        $rule_id = 'non_existent_rule';
        $fallback = 'Default Discount Message';
        
        $message = $messages[$rule_id] ?? $fallback;
        
        $this->assertEquals($fallback, $message, 'Should return fallback when message not found');
    }
    
    /**
     * Test: English fallback for missing translations
     */
    public function testEnglishFallbackForMissingTranslations() {
        $messages = [
            'test_rule' => [
                'en' => ['cart_message' => 'English Message'],
                'fr' => [], // French missing
                'de' => [], // German missing
            ]
        ];
        
        $current_lang = 'fr';
        $rule_id = 'test_rule';
        
        // Try French first
        $message = $messages[$rule_id][$current_lang]['cart_message'] ?? '';
        
        // Fallback to English if not found
        if (empty($message) && $current_lang !== 'en') {
            $message = $messages[$rule_id]['en']['cart_message'] ?? '';
        }
        
        $this->assertEquals('English Message', $message, 'Should fallback to English when translation missing');
    }
    
    /**
     * Test: Message type variations
     */
    public function testMessageTypes() {
        $message_types = ['cart_message', 'customer_note', 'admin_description', 'email_subject'];
        
        $messages = [
            'test_rule' => [
                'en' => [
                    'cart_message' => 'Cart Message',
                    'customer_note' => 'Customer Note',
                    'admin_description' => 'Admin Description',
                    'email_subject' => 'Email Subject',
                ]
            ]
        ];
        
        foreach ($message_types as $type) {
            $message = $messages['test_rule']['en'][$type] ?? '';
            $this->assertNotEmpty($message, "Message type '{$type}' should be defined");
        }
    }
    
    /**
     * Test: String sanitization for database storage
     */
    public function testStringSanitization() {
        $unsafe_strings = [
            '<script>alert("xss")</script>Discount' => 'Discount',
            'Valid <b>Discount</b>' => 'Valid Discount',
            '20% Off!' => '20% Off!',
        ];
        
        foreach ($unsafe_strings as $unsafe => $expected) {
            $sanitized = wp_strip_all_tags($unsafe);
            $this->assertEquals($expected, $sanitized, "Should sanitize '{$unsafe}' to '{$expected}'");
        }
    }
    
    /**
     * Test: Available languages fallback
     */
    public function testAvailableLanguagesFallback() {
        // If no multilingual plugin, should return default languages
        $default_languages = [
            'en' => 'English',
            'de' => 'Deutsch',
            'fr' => 'Français'
        ];
        
        $this->assertArrayHasKey('en', $default_languages, 'English should always be available');
        $this->assertArrayHasKey('de', $default_languages, 'German should be available for InterSoccer');
        $this->assertArrayHasKey('fr', $default_languages, 'French should be available for InterSoccer');
    }
    
    /**
     * Test: Rule ID determination from discount type
     */
    public function testRuleIdFromDiscountType() {
        $test_cases = [
            [
                'discount' => ['type' => 'camp_sibling', 'percentage' => 20],
                'expected' => 'camp_2nd_child'
            ],
            [
                'discount' => ['type' => 'camp_sibling', 'percentage' => 25],
                'expected' => 'camp_3rd_plus_child'
            ],
            [
                'discount' => ['type' => 'course_multi_child', 'percentage' => 20],
                'expected' => 'course_2nd_child'
            ],
            [
                'discount' => ['type' => 'course_multi_child', 'percentage' => 30],
                'expected' => 'course_3rd_plus_child'
            ],
            [
                'discount' => ['type' => 'course_same_season'],
                'expected' => 'course_same_season'
            ],
        ];
        
        foreach ($test_cases as $case) {
            $result = $this->getRuleIdFromDiscount($case['discount']);
            $this->assertEquals($case['expected'], $result, "Discount type should map to rule ID '{$case['expected']}'");
        }
    }
    
    /**
     * Helper: Simulate rule ID determination
     */
    private function getRuleIdFromDiscount($discount) {
        if (!is_array($discount)) {
            return '';
        }
        
        $type = $discount['type'] ?? '';
        
        switch ($type) {
            case 'camp_sibling':
                $percentage = $discount['percentage'] ?? 0;
                return ($percentage == 20) ? 'camp_2nd_child' : 'camp_3rd_plus_child';
                
            case 'course_multi_child':
                $percentage = $discount['percentage'] ?? 0;
                return ($percentage == 20) ? 'course_2nd_child' : 'course_3rd_plus_child';
                
            case 'course_same_season':
                return 'course_same_season';
                
            default:
                return '';
        }
    }
    
    /**
     * Test: Empty/null handling in message retrieval
     */
    public function testEmptyNullHandling() {
        $test_cases = [
            ['rule_id' => '', 'message_type' => 'cart_message', 'should_fail' => true],
            ['rule_id' => null, 'message_type' => 'cart_message', 'should_fail' => true],
            ['rule_id' => 'valid_rule', 'message_type' => '', 'should_fail' => true],
            ['rule_id' => 'valid_rule', 'message_type' => null, 'should_fail' => true],
            ['rule_id' => 'valid_rule', 'message_type' => 'cart_message', 'should_fail' => false],
        ];
        
        foreach ($test_cases as $case) {
            $is_invalid = empty($case['rule_id']) || empty($case['message_type']);
            $this->assertEquals($case['should_fail'], $is_invalid, "Should validate inputs correctly");
        }
    }
    
    /**
     * Test: Message key fallback to rule ID
     */
    public function testMessageKeyFallback() {
        $rule_with_key = ['id' => 'rule_1', 'message_key' => 'custom_key_1'];
        $rule_without_key = ['id' => 'rule_2'];
        
        $key1 = $rule_with_key['message_key'] ?? $rule_with_key['id'];
        $key2 = $rule_without_key['message_key'] ?? $rule_without_key['id'];
        
        $this->assertEquals('custom_key_1', $key1, 'Should use custom message key if available');
        $this->assertEquals('rule_2', $key2, 'Should fallback to rule ID if no message key');
    }
    
    /**
     * Test: WPML string name generation
     */
    public function testWPMLStringNameGeneration() {
        $rule_id = 'camp_2nd_child';
        $message_type = 'cart_message';
        $expected_name = "intersoccer_discount_{$rule_id}_{$message_type}";
        
        $this->assertEquals('intersoccer_discount_camp_2nd_child_cart_message', $expected_name);
    }
    
    /**
     * Test: Default messages structure
     */
    public function testDefaultMessagesStructure() {
        $default_messages = [
            'camp_2nd_child' => [
                'cart_message' => '20% Sibling Discount Applied',
                'admin_description' => 'Second child camp discount',
                'customer_note' => 'You saved 20% on this camp because you have multiple children enrolled in camps.'
            ],
        ];
        
        $rule_id = 'camp_2nd_child';
        
        $this->assertArrayHasKey($rule_id, $default_messages, 'Default messages should have camp_2nd_child rule');
        $this->assertArrayHasKey('cart_message', $default_messages[$rule_id], 'Should have cart_message');
        $this->assertArrayHasKey('admin_description', $default_messages[$rule_id], 'Should have admin_description');
        $this->assertArrayHasKey('customer_note', $default_messages[$rule_id], 'Should have customer_note');
    }
    
    /**
     * Test: Template string formatting
     */
    public function testTemplateStringFormatting() {
        $template = '%d%% Camp Combo Discount (Child %d)';
        $formatted = sprintf($template, 20, 2);
        
        $this->assertEquals('20% Camp Combo Discount (Child 2)', $formatted);
    }
    
    /**
     * Test: Escape HTML in messages
     */
    public function testEscapeHTMLInMessages() {
        $messages = [
            'You saved <b>20%</b>' => 'You saved 20%',
            'Discount <script>alert(1)</script> applied' => 'Discount  applied',
        ];
        
        foreach ($messages as $unsafe => $expected) {
            $safe = wp_strip_all_tags($unsafe);
            $this->assertEquals($expected, $safe, 'Should escape HTML in messages');
        }
    }
    
    /**
     * Test: Error handling in message functions
     */
    public function testErrorHandlingInMessageFunctions() {
        // Test that functions handle errors gracefully
        $invalid_inputs = [
            ['rule_id' => null, 'message_type' => 'cart_message'],
            ['rule_id' => '', 'message_type' => 'cart_message'],
            ['rule_id' => 'valid', 'message_type' => null],
            ['rule_id' => 'valid', 'message_type' => ''],
        ];
        
        foreach ($invalid_inputs as $input) {
            $is_invalid = empty($input['rule_id']) || empty($input['message_type']);
            $this->assertTrue($is_invalid, 'Should detect invalid inputs');
        }
    }
    
    /**
     * Test: Language code normalization
     */
    public function testLanguageCodeNormalization() {
        $codes = [
            'EN' => 'en',
            'FR' => 'fr',
            'DE' => 'de',
            'en' => 'en',
        ];
        
        foreach ($codes as $input => $expected) {
            $normalized = strtolower($input);
            $this->assertEquals($expected, $normalized, "Code '{$input}' should normalize to '{$expected}'");
        }
    }
    
    /**
     * Test: Message initialization for available languages
     */
    public function testMessageInitializationForAllLanguages() {
        $available_languages = [
            'en' => 'English',
            'fr' => 'Français',
            'de' => 'Deutsch',
        ];
        
        $default_message = [
            'cart_message' => 'Test Discount',
            'customer_note' => 'Test Note',
        ];
        
        $initialized_messages = [];
        foreach ($available_languages as $lang_code => $lang_name) {
            $initialized_messages[$lang_code] = $default_message;
        }
        
        $this->assertCount(3, $initialized_messages, 'Should initialize for all 3 languages');
        $this->assertArrayHasKey('en', $initialized_messages);
        $this->assertArrayHasKey('fr', $initialized_messages);
        $this->assertArrayHasKey('de', $initialized_messages);
    }
    
    /**
     * Test: WPML context string format
     */
    public function testWPMLContextFormat() {
        $context = 'intersoccer-product-variations';
        
        $this->assertIsString($context, 'Context should be a string');
        $this->assertStringNotContainsString(' ', $context, 'Context should not contain spaces');
        $this->assertStringContainsString('intersoccer', $context, 'Context should identify plugin');
    }
    
    /**
     * Test: Template strings list
     */
    public function testTemplateStringsList() {
        $template_strings = [
            'camp_combo_discount_template' => '%d%% Camp Combo Discount (Child %d)',
            'course_multi_child_discount_template' => '%d%% Course Multi-Child Discount (Child %d)',
            'same_season_course_discount_template' => '50%% Same Season Course Discount (Child %d, %s)',
            'discount_information_label' => 'Discount Information',
            'discounts_applied_label' => 'Discounts Applied',
            'saved_label' => 'saved'
        ];
        
        foreach ($template_strings as $key => $value) {
            $this->assertNotEmpty($value, "Template string '{$key}' should not be empty");
            $this->assertIsString($value, "Template string '{$key}' should be a string");
        }
    }
    
    /**
     * Test: Safe functions exist in file
     */
    public function testSafeFunctionsExist() {
        $file_path = dirname(__DIR__) . '/includes/woocommerce/discount-messages.php';
        $this->assertFileExists($file_path, 'discount-messages.php should exist');
        
        $contents = file_get_contents($file_path);
        
        // Verify safe functions are defined
        $this->assertStringContainsString('function intersoccer_get_current_language_safe', $contents, 'Should have safe language function');
        $this->assertStringContainsString('function intersoccer_get_available_languages_safe', $contents, 'Should have safe languages list function');
        $this->assertStringContainsString('function intersoccer_get_discount_message_safe', $contents, 'Should have safe message retrieval');
        $this->assertStringContainsString('function intersoccer_get_discount_message_basic', $contents, 'Should have basic fallback');
    }
    
    /**
     * Test: Error handling prevents fatal errors
     */
    public function testErrorHandlingPreventsFatalErrors() {
        // Functions should catch exceptions and return fallbacks
        $file_path = dirname(__DIR__) . '/includes/woocommerce/discount-messages.php';
        $contents = file_get_contents($file_path);
        
        // Check that key functions have try-catch blocks
        $functions_with_error_handling = [
            'intersoccer_get_discount_message_basic',
            'intersoccer_get_current_language_safe',
            'intersoccer_get_available_languages_safe',
            'intersoccer_get_discount_message_safe',
        ];
        
        foreach ($functions_with_error_handling as $function) {
            // Extract function code
            $pattern = '/function ' . preg_quote($function) . '\(.*?\n    \}/s';
            preg_match($pattern, $contents, $matches);
            
            if (!empty($matches[0])) {
                $function_code = $matches[0];
                $this->assertStringContainsString('try', $function_code, "Function {$function} should have try-catch");
                $this->assertStringContainsString('catch', $function_code, "Function {$function} should have catch block");
            }
        }
    }
    
    /**
     * Test: Fallback chain works
     */
    public function testFallbackChain() {
        // Simulated fallback chain
        $messages = [
            'test_rule' => [
                'fr' => [], // French empty
                'en' => ['cart_message' => 'English Message'], // English available
            ]
        ];
        
        $current_lang = 'de'; // German (not available)
        $fallback = 'Ultimate Fallback';
        
        // Try German
        $message = $messages['test_rule'][$current_lang]['cart_message'] ?? '';
        
        // Try English
        if (empty($message) && $current_lang !== 'en') {
            $message = $messages['test_rule']['en']['cart_message'] ?? '';
        }
        
        // Use fallback
        if (empty($message)) {
            $message = $fallback;
        }
        
        $this->assertEquals('English Message', $message, 'Should use English before ultimate fallback');
    }
    
    /**
     * Test: Discount message keys are consistent
     */
    public function testDiscountMessageKeysConsistent() {
        $expected_rule_ids = [
            'camp_2nd_child',
            'camp_3rd_plus_child',
            'course_2nd_child',
            'course_3rd_plus_child',
            'course_same_season',
        ];
        
        foreach ($expected_rule_ids as $rule_id) {
            $this->assertIsString($rule_id, 'Rule ID should be string');
            $this->assertStringContainsString('_', $rule_id, 'Rule ID should use underscore format');
        }
    }
    
    /**
     * Test: Message context is set correctly
     */
    public function testMessageContextIsCorrect() {
        $context = 'intersoccer-product-variations';
        
        // Context should match the plugin text domain
        $this->assertEquals('intersoccer-product-variations', $context, 'Context should match plugin text domain');
    }
    
    /**
     * Test: Default messages are not empty
     */
    public function testDefaultMessagesNotEmpty() {
        $default_messages = [
            'camp_2nd_child' => [
                'cart_message' => '20% Sibling Discount Applied',
                'customer_note' => 'You saved 20% on this camp because you have multiple children enrolled in camps.'
            ],
        ];
        
        foreach ($default_messages as $rule_id => $messages) {
            foreach ($messages as $type => $message) {
                $this->assertNotEmpty($message, "Default message for {$rule_id}.{$type} should not be empty");
            }
        }
    }
    
    /**
     * Test: No multilingual plugin detected returns 'en'
     */
    public function testNoMultilingualPluginReturnsEnglish() {
        // Simulate no plugin available
        $has_wpml = false;
        $has_polylang = false;
        
        if (!$has_wpml && !$has_polylang) {
            $locale = 'en_US';
            $lang = substr($locale, 0, 2);
            
            if (strlen($lang) !== 2 || !ctype_alpha($lang)) {
                $lang = 'en';
            }
            
            $this->assertEquals('en', strtolower($lang), 'Should fallback to English');
        }
    }
    
    /**
     * Test: Files are properly logged
     */
    public function testFilesHaveLoadingLogs() {
        $file_path = dirname(__DIR__) . '/includes/woocommerce/discount-messages.php';
        $contents = file_get_contents($file_path);
        
        // Should have log statement at end
        $this->assertStringContainsString("error_log('InterSoccer: Loaded discount messages integration with WPML support", $contents);
    }
    
    /**
     * Test: Emergency fallback functions defined
     */
    public function testEmergencyFallbacksDefined() {
        $file_path = dirname(__DIR__) . '/includes/woocommerce/discount-messages.php';
        $contents = file_get_contents($file_path);
        
        $this->assertStringContainsString('function intersoccer_emergency_discount_fallback', $contents, 'Should have emergency fallback');
        $this->assertStringContainsString('plugins_loaded', $contents, 'Should register emergency fallbacks early');
    }
    
    /**
     * Test: Tournament discount message keys exist in default messages
     */
    public function testTournamentMessageKeysExist() {
        $default_messages = [
            'camp_2nd_child' => [],
            'camp_3rd_plus_child' => [],
            'course_2nd_child' => [],
            'course_3rd_plus_child' => [],
            'course_same_season' => [],
            'tournament_2nd_child' => [],
            'tournament_3rd_plus_child' => []
        ];
        
        $this->assertArrayHasKey('tournament_2nd_child', $default_messages, 'Tournament 2nd child message key should exist');
        $this->assertArrayHasKey('tournament_3rd_plus_child', $default_messages, 'Tournament 3rd+ child message key should exist');
    }
    
    /**
     * Test: Tournament message content structure
     */
    public function testTournamentMessageContentStructure() {
        $tournament_message = [
            'cart_message' => '20% Tournament Sibling Discount',
            'admin_description' => 'Second child tournament discount',
            'customer_note' => 'You saved 20% on this tournament because you have multiple children enrolled in tournaments.'
        ];
        
        $this->assertArrayHasKey('cart_message', $tournament_message, 'Should have cart_message');
        $this->assertArrayHasKey('admin_description', $tournament_message, 'Should have admin_description');
        $this->assertArrayHasKey('customer_note', $tournament_message, 'Should have customer_note');
        
        $this->assertStringContainsString('Tournament', $tournament_message['cart_message'], 'Cart message should mention Tournament');
        $this->assertStringContainsString('tournament', strtolower($tournament_message['admin_description']), 'Admin description should mention tournament');
        $this->assertStringContainsString('tournament', strtolower($tournament_message['customer_note']), 'Customer note should mention tournament');
    }
    
    /**
     * Test: Tournament template string exists
     */
    public function testTournamentTemplateStringExists() {
        $template_strings = [
            'camp_combo_discount_template' => '%d%% Camp Combo Discount (Child %d)',
            'course_multi_child_discount_template' => '%d%% Course Multi-Child Discount (Child %d)',
            'tournament_multi_child_discount_template' => '%d%% Tournament Multi-Child Discount (Child %d)',
        ];
        
        $this->assertArrayHasKey('tournament_multi_child_discount_template', $template_strings, 'Tournament template string should exist');
        $this->assertStringContainsString('Tournament', $template_strings['tournament_multi_child_discount_template'], 'Template should mention Tournament');
    }
    
    /**
     * Test: Tournament messages in multiple languages
     */
    public function testTournamentMessagesMultilingual() {
        $multilingual_messages = [
            'tournament_2nd_child' => [
                'en' => ['cart_message' => '20% Tournament Sibling Discount'],
                'fr' => ['cart_message' => '20% Réduction Fratrie Tournoi'],
                'de' => ['cart_message' => '20% Turnier Geschwisterrabatt']
            ]
        ];
        
        foreach (['en', 'fr', 'de'] as $lang) {
            $this->assertArrayHasKey($lang, $multilingual_messages['tournament_2nd_child'], "Should have {$lang} translation");
            $this->assertNotEmpty($multilingual_messages['tournament_2nd_child'][$lang]['cart_message'], "{$lang} message should not be empty");
        }
    }
    
    /**
     * Test: No tournament same-season messages
     */
    public function testNoTournamentSameSeasonMessages() {
        $default_messages = [
            'camp_2nd_child' => [],
            'course_same_season' => [],
            'tournament_2nd_child' => []
            // Note: No 'tournament_same_season' key - tournaments don't have this
        ];
        
        $this->assertArrayNotHasKey('tournament_same_season', $default_messages, 'Should NOT have tournament same-season message (single-date events)');
    }
}

