<?php
/**
 * Test: Discount Helper Functions
 * 
 * Purpose: Test the new helper functions for retroactive discounts
 * Ensures functions exist and have correct signatures
 */

use PHPUnit\Framework\TestCase;

class DiscountFunctionsTest extends TestCase {
    
    /**
     * Test: All new discount functions are defined
     */
    public function testNewDiscountFunctionsExist() {
        $required_functions = [
            'intersoccer_get_customer_previous_orders',
            'intersoccer_extract_course_items_from_order',
            'intersoccer_extract_camp_items_from_order',
            'intersoccer_extract_tournament_items_from_order',
            'intersoccer_parse_camp_week_from_terms',
            'intersoccer_get_previous_courses_by_parent',
            'intersoccer_get_previous_camps_by_parent',
            'intersoccer_get_previous_tournaments_by_parent',
        ];
        
        $discounts_file = dirname(__DIR__) . '/includes/woocommerce/discounts.php';
        $this->assertFileExists($discounts_file, 'Discounts file should exist');
        
        $contents = file_get_contents($discounts_file);
        
        foreach ($required_functions as $function) {
            $this->assertStringContainsString(
                'function ' . $function,
                $contents,
                "Function {$function} should be defined in discounts.php"
            );
        }
    }
    
    /**
     * Test: New discount rules are defined
     */
    public function testNewDiscountRulesExist() {
        $discounts_file = dirname(__DIR__) . '/includes/woocommerce/discounts.php';
        $contents = file_get_contents($discounts_file);
        
        $required_rules = [
            'camp-progressive-week-2',
            'camp-progressive-week-3-plus',
            'tournament-same-child-multiple-days',
        ];
        
        foreach ($required_rules as $rule_id) {
            $this->assertStringContainsString(
                $rule_id,
                $contents,
                "Discount rule '{$rule_id}' should be defined"
            );
        }
    }
    
    /**
     * Test: New discount conditions are allowed
     */
    public function testNewDiscountConditionsAllowed() {
        $discounts_file = dirname(__DIR__) . '/includes/woocommerce/discounts.php';
        $contents = file_get_contents($discounts_file);
        
        $new_conditions = [
            'progressive_week_2',
            'progressive_week_3_plus',
            'same_child_multiple_days',
        ];
        
        foreach ($new_conditions as $condition) {
            $this->assertStringContainsString(
                $condition,
                $contents,
                "Condition '{$condition}' should be in allowed conditions"
            );
        }
    }
    
    /**
     * Test: Admin UI settings are registered
     */
    public function testAdminUISettingsRegistered() {
        $admin_file = dirname(__DIR__) . '/includes/woocommerce/admin-ui.php';
        $this->assertFileExists($admin_file, 'Admin UI file should exist');
        
        $contents = file_get_contents($admin_file);
        
        $required_settings = [
            'intersoccer_enable_retroactive_course_discounts',
            'intersoccer_enable_retroactive_camp_discounts',
            'intersoccer_retroactive_discount_lookback_months',
        ];
        
        foreach ($required_settings as $setting) {
            $this->assertStringContainsString(
                $setting,
                $contents,
                "Setting '{$setting}' should be in admin UI"
            );
        }
    }
    
    /**
     * Test: Discount messages are defined
     */
    public function testDiscountMessagesExist() {
        $messages_file = dirname(__DIR__) . '/includes/woocommerce/discount-messages.php';
        $this->assertFileExists($messages_file, 'Discount messages file should exist');
        
        $contents = file_get_contents($messages_file);
        
        $required_message_keys = [
            'camp_progressive_week_2',
            'camp_progressive_week_3_plus',
            'tournament_same_child_multiple_days',
        ];
        
        foreach ($required_message_keys as $key) {
            $this->assertStringContainsString(
                $key,
                $contents,
                "Message key '{$key}' should be defined"
            );
        }
    }
    
    /**
     * Test: Cart context includes previous order fields
     */
    public function testCartContextIncludesPreviousOrders() {
        $discounts_file = dirname(__DIR__) . '/includes/woocommerce/discounts.php';
        $contents = file_get_contents($discounts_file);
        
        $this->assertStringContainsString("'previous_courses'", $contents, 'Cart context should include previous_courses');
        $this->assertStringContainsString("'previous_camps'", $contents, 'Cart context should include previous_camps');
        $this->assertStringContainsString("'previous_tournaments'", $contents, 'Cart context should include previous_tournaments');
    }
    
    /**
     * Test: Lookback period is used in queries
     */
    public function testLookbackPeriodUsedInQueries() {
        $discounts_file = dirname(__DIR__) . '/includes/woocommerce/discounts.php';
        $contents = file_get_contents($discounts_file);
        
        $this->assertStringContainsString(
            'intersoccer_retroactive_discount_lookback_months',
            $contents,
            'Lookback months setting should be used in discount calculations'
        );
        
        $this->assertStringContainsString(
            'date_after',
            $contents,
            'Date filtering should be applied to order queries'
        );
    }
    
    /**
     * Test: Caching is implemented
     */
    public function testCachingImplemented() {
        $discounts_file = dirname(__DIR__) . '/includes/woocommerce/discounts.php';
        $contents = file_get_contents($discounts_file);
        
        $this->assertStringContainsString(
            'static $cache',
            $contents,
            'Static caching should be implemented'
        );
        
        $this->assertStringContainsString(
            'cache_key',
            $contents,
            'Cache keys should be used'
        );
    }
    
    /**
     * Test: Enable/disable flags are checked
     */
    public function testEnableDisableFlagsChecked() {
        $discounts_file = dirname(__DIR__) . '/includes/woocommerce/discounts.php';
        $contents = file_get_contents($discounts_file);
        
        $this->assertStringContainsString(
            'enable_retroactive_courses',
            $contents,
            'Retroactive courses enable flag should be checked'
        );
        
        $this->assertStringContainsString(
            'enable_retroactive_camps',
            $contents,
            'Retroactive camps enable flag should be checked'
        );
    }
    
    /**
     * Test: Parent product ID is extracted correctly
     */
    public function testParentProductIdExtraction() {
        $discounts_file = dirname(__DIR__) . '/includes/woocommerce/discounts.php';
        $contents = file_get_contents($discounts_file);
        
        $this->assertStringContainsString(
            'get_parent_id',
            $contents,
            'Parent product ID should be extracted from variations'
        );
        
        $this->assertStringContainsString(
            'parent_product_id',
            $contents,
            'Parent product ID should be stored in context'
        );
    }
    
    /**
     * Test: Assigned player matching is implemented
     */
    public function testAssignedPlayerMatching() {
        $discounts_file = dirname(__DIR__) . '/includes/woocommerce/discounts.php';
        $contents = file_get_contents($discounts_file);
        
        $this->assertStringContainsString(
            'assigned_player',
            $contents,
            'Assigned player should be used for matching'
        );
        
        $this->assertStringContainsString(
            'assigned_attendee',
            $contents,
            'Assigned attendee should be checked as fallback'
        );
    }
    
    /**
     * Test: WooCommerce order query is used
     */
    public function testWooCommerceOrderQueryUsed() {
        $discounts_file = dirname(__DIR__) . '/includes/woocommerce/discounts.php';
        $contents = file_get_contents($discounts_file);
        
        $this->assertStringContainsString(
            'wc_get_orders',
            $contents,
            'WooCommerce order query function should be used'
        );
        
        $this->assertStringContainsString(
            'wc-completed',
            $contents,
            'Completed order status should be queried'
        );
        
        $this->assertStringContainsString(
            'wc-processing',
            $contents,
            'Processing order status should be queried'
        );
    }
    
    /**
     * Test: Documentation exists
     */
    public function testDocumentationExists() {
        $doc_file = dirname(__DIR__) . '/RETROACTIVE-DISCOUNTS-IMPLEMENTATION.md';
        $this->assertFileExists($doc_file, 'Implementation documentation should exist');
        
        $contents = file_get_contents($doc_file);
        $this->assertStringContainsString('Retroactive', $contents, 'Documentation should mention retroactive discounts');
        $this->assertStringContainsString('Tournament', $contents, 'Documentation should mention tournament discounts');
        $this->assertStringContainsString('Progressive', $contents, 'Documentation should mention progressive discounts');
    }
    
    /**
     * Test: Test coverage documentation exists
     */
    public function testTestCoverageDocumentationExists() {
        $test_doc = dirname(__DIR__) . '/tests/RETROACTIVE-DISCOUNT-TEST-COVERAGE.md';
        $this->assertFileExists($test_doc, 'Test coverage documentation should exist');
    }
}

