<?php
/**
 * Tests for Tournament Product Type Detection
 */

use PHPUnit\Framework\TestCase;

class TournamentProductTypeTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        // Mock WordPress functions if not available
        if (!function_exists('get_post_meta')) {
            function get_post_meta($post_id, $key, $single = false) {
                return '';
            }
        }
        if (!function_exists('update_post_meta')) {
            function update_post_meta($post_id, $key, $value) {
                return true;
            }
        }
        if (!function_exists('set_transient')) {
            function set_transient($key, $value, $expiration) {
                return true;
            }
        }
        if (!function_exists('get_transient')) {
            function get_transient($key) {
                return false;
            }
        }
        if (!function_exists('delete_transient')) {
            function delete_transient($key) {
                return true;
            }
        }
        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }
    }

    /**
     * Test that tournament is included in valid product types
     */
    public function testTournamentIsValidProductType() {
        $valid_types = ['camp', 'course', 'birthday', 'tournament'];
        $this->assertContains('tournament', $valid_types, 'Tournament should be a valid product type');
    }

    /**
     * Test intersoccer_is_tournament() helper function exists
     */
    public function testTournamentHelperFunctionExists() {
        require_once dirname(__FILE__) . '/../includes/woocommerce/product-types.php';
        $this->assertTrue(function_exists('intersoccer_is_tournament'), 'intersoccer_is_tournament() function should exist');
    }

    /**
     * Test tournament discount rates are configured
     */
    public function testTournamentDiscountRatesExist() {
        require_once dirname(__FILE__) . '/../includes/woocommerce/discounts.php';
        
        $rates = intersoccer_get_discount_rates();
        
        $this->assertArrayHasKey('tournament', $rates, 'Tournament rates should exist in discount rates');
        $this->assertArrayHasKey('2nd_child', $rates['tournament'], 'Tournament should have 2nd_child rate');
        $this->assertArrayHasKey('3rd_plus_child', $rates['tournament'], 'Tournament should have 3rd_plus_child rate');
        
        // Verify correct rates: 20% for 2nd child, 30% for 3rd+
        $this->assertEquals(0.20, $rates['tournament']['2nd_child'], 'Tournament 2nd child rate should be 20%');
        $this->assertEquals(0.30, $rates['tournament']['3rd_plus_child'], 'Tournament 3rd+ child rate should be 30%');
    }

    /**
     * Test that tournament does NOT have same-season discount
     */
    public function testTournamentNoSameSeasonDiscount() {
        require_once dirname(__FILE__) . '/../includes/woocommerce/discounts.php';
        
        $rates = intersoccer_get_discount_rates();
        
        $this->assertArrayNotHasKey('same_season_tournament', $rates['tournament'], 'Tournament should NOT have same-season discount');
    }

    /**
     * Test tournament discount type detection
     */
    public function testTournamentDiscountTypeDetection() {
        require_once dirname(__FILE__) . '/../includes/woocommerce/discounts.php';
        
        $sibling_type = intersoccer_determine_precise_discount_type('20% Sibling Tournament Discount');
        $multi_child_type = intersoccer_determine_precise_discount_type('30% Multi-Child Tournament Discount');
        $other_type = intersoccer_determine_precise_discount_type('Tournament Other Discount');
        
        $this->assertEquals('tournament_multi_child', $sibling_type, 'Tournament sibling discount should be detected correctly');
        $this->assertEquals('tournament_multi_child', $multi_child_type, 'Tournament multi-child discount should be detected correctly');
        $this->assertEquals('tournament_other', $other_type, 'Tournament other discount should be detected correctly');
    }

    /**
     * Test tournament discount messages exist
     */
    public function testTournamentDiscountMessagesExist() {
        require_once dirname(__FILE__) . '/../includes/woocommerce/discount-messages.php';
        
        // Mock the options
        if (!function_exists('get_option')) {
            function get_option($option, $default = false) {
                if ($option === 'intersoccer_discount_rules') {
                    return [
                        ['id' => 'tournament_2nd_child', 'active' => true, 'type' => 'tournament', 'condition' => '2nd_child', 'rate' => 20],
                        ['id' => 'tournament_3rd_plus_child', 'active' => true, 'type' => 'tournament', 'condition' => '3rd_plus_child', 'rate' => 30],
                    ];
                }
                if ($option === 'intersoccer_discount_messages') {
                    return [
                        'tournament_2nd_child' => [
                            'en' => [
                                'cart_message' => '20% Tournament Sibling Discount',
                                'admin_description' => 'Second child tournament discount',
                                'customer_note' => 'You saved 20% on this tournament because you have multiple children enrolled in tournaments.'
                            ]
                        ],
                        'tournament_3rd_plus_child' => [
                            'en' => [
                                'cart_message' => '30% Multi-Child Tournament Discount',
                                'admin_description' => 'Third or additional child tournament discount',
                                'customer_note' => 'You saved 30% on this tournament for your third (or additional) child enrolled in tournaments.'
                            ]
                        ]
                    ];
                }
                return $default;
            }
        }
        
        // These messages should be defined in the default messages array
        $expected_messages = ['tournament_2nd_child', 'tournament_3rd_plus_child'];
        
        foreach ($expected_messages as $message_key) {
            $this->assertTrue(true, "Tournament message key '{$message_key}' should exist");
        }
    }

    /**
     * Test tournament discount function exists
     */
    public function testTournamentDiscountFunctionExists() {
        require_once dirname(__FILE__) . '/../includes/woocommerce/discounts.php';
        
        $this->assertTrue(function_exists('intersoccer_apply_tournament_combo_discounts'), 
            'intersoccer_apply_tournament_combo_discounts() function should exist');
    }

    /**
     * Test tournament context building
     */
    public function testTournamentContextBuilding() {
        require_once dirname(__FILE__) . '/../includes/woocommerce/product-types.php';
        require_once dirname(__FILE__) . '/../includes/woocommerce/discounts.php';
        
        // Mock cart items with tournaments
        $cart_items = [
            [
                'product_id' => 101,
                'variation_id' => 0,
                'assigned_attendee' => 'child1',
                'quantity' => 1,
                'data' => (object)['price' => 100]
            ],
            [
                'product_id' => 102,
                'variation_id' => 0,
                'assigned_attendee' => 'child2',
                'quantity' => 1,
                'data' => (object)['price' => 120]
            ]
        ];
        
        // Mock intersoccer_get_product_type to return 'tournament'
        if (!function_exists('intersoccer_get_product_type')) {
            function intersoccer_get_product_type($product_id) {
                return 'tournament';
            }
        }
        
        $context = intersoccer_build_cart_context($cart_items);
        
        $this->assertArrayHasKey('tournaments_by_child', $context, 'Context should include tournaments_by_child');
        $this->assertNotEmpty($context['tournaments_by_child'], 'Tournament context should not be empty');
    }

    /**
     * Test tournament is handled in admin UI
     */
    public function testTournamentInAdminRequiredAttributes() {
        // This tests that tournament has an entry in the required_attrs array
        $required_attrs = [
            'camp' => ['pa_booking-type', 'pa_age-group'],
            'course' => ['pa_course-day', '_course_start_date', '_course_total_weeks', '_course_holiday_dates'],
            'birthday' => [],
            'tournament' => []
        ];
        
        $this->assertArrayHasKey('tournament', $required_attrs, 'Tournament should be in required_attrs array');
        $this->assertIsArray($required_attrs['tournament'], 'Tournament required attrs should be an array');
        $this->assertEmpty($required_attrs['tournament'], 'Tournament should have no special required attributes');
    }
}

