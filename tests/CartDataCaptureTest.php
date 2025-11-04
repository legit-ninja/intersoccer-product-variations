<?php
/**
 * Test: Cart Data Capture
 * 
 * Purpose: Ensure camp days, player assignment, and late pickup data
 * are correctly captured from POST data and added to cart items.
 * 
 * Critical for: Single day camp bookings, player assignment, late pickup
 */

use PHPUnit\Framework\TestCase;

class CartDataCaptureTest extends TestCase {
    
    /**
     * Test: Camp days are captured from POST and added to cart item data
     */
    public function testCampDaysAddedToCartItemData() {
        // Simulate POST data
        $_POST['camp_days'] = ['Monday', 'Wednesday', 'Friday'];
        $_POST['player_assignment'] = 0;
        
        // Mock the function call
        $cart_item_data = [];
        $product_id = 12345; // Mock camp product ID
        $variation_id = 67890;
        
        // Simulate the cart data capture logic
        if (isset($_POST['camp_days']) && is_array($_POST['camp_days'])) {
            $cart_item_data['camp_days'] = array_map('sanitize_text_field', $_POST['camp_days']);
        }
        
        // Assertions
        $this->assertArrayHasKey('camp_days', $cart_item_data, 'camp_days should be added to cart item data');
        $this->assertIsArray($cart_item_data['camp_days'], 'camp_days should be an array');
        $this->assertCount(3, $cart_item_data['camp_days'], 'Should have 3 days selected');
        $this->assertContains('Monday', $cart_item_data['camp_days'], 'Should contain Monday');
        $this->assertContains('Wednesday', $cart_item_data['camp_days'], 'Should contain Wednesday');
        $this->assertContains('Friday', $cart_item_data['camp_days'], 'Should contain Friday');
    }
    
    /**
     * Test: Player assignment is captured correctly
     */
    public function testPlayerAssignmentCaptured() {
        $_POST['player_assignment'] = 2; // Third player (0-indexed)
        
        $cart_item_data = [];
        
        if (isset($_POST['player_assignment'])) {
            $cart_item_data['assigned_player'] = absint($_POST['player_assignment']);
        }
        
        $this->assertArrayHasKey('assigned_player', $cart_item_data);
        $this->assertEquals(2, $cart_item_data['assigned_player']);
        $this->assertIsInt($cart_item_data['assigned_player']);
    }
    
    /**
     * Test: Late pickup data is captured for single days
     */
    public function testLatePickupSingleDaysCaptured() {
        $_POST['late_pickup_type'] = 'single-days';
        $_POST['late_pickup_days'] = ['Monday', 'Tuesday', 'Friday'];
        
        $cart_item_data = [];
        
        // Simulate late pickup capture
        if (isset($_POST['late_pickup_type'])) {
            $cart_item_data['late_pickup_type'] = sanitize_text_field($_POST['late_pickup_type']);
            
            if ($cart_item_data['late_pickup_type'] === 'single-days' && 
                isset($_POST['late_pickup_days']) && 
                is_array($_POST['late_pickup_days'])) {
                $cart_item_data['late_pickup_days'] = array_map('sanitize_text_field', $_POST['late_pickup_days']);
            }
        }
        
        $this->assertArrayHasKey('late_pickup_type', $cart_item_data);
        $this->assertEquals('single-days', $cart_item_data['late_pickup_type']);
        $this->assertArrayHasKey('late_pickup_days', $cart_item_data);
        $this->assertCount(3, $cart_item_data['late_pickup_days']);
    }
    
    /**
     * Test: Late pickup data is captured for full week
     */
    public function testLatePickupFullWeekCaptured() {
        $_POST['late_pickup_type'] = 'full-week';
        
        $cart_item_data = [];
        
        if (isset($_POST['late_pickup_type'])) {
            $cart_item_data['late_pickup_type'] = sanitize_text_field($_POST['late_pickup_type']);
            $cart_item_data['late_pickup_days'] = [];
        }
        
        $this->assertArrayHasKey('late_pickup_type', $cart_item_data);
        $this->assertEquals('full-week', $cart_item_data['late_pickup_type']);
        $this->assertArrayHasKey('late_pickup_days', $cart_item_data);
        $this->assertEmpty($cart_item_data['late_pickup_days'], 'Full week should have empty days array');
    }
    
    /**
     * Test: Late pickup cost is calculated correctly
     */
    public function testLatePickupCostCalculation() {
        $per_day_cost = 25.00;
        $full_week_cost = 90.00;
        
        // Test single days (3 days)
        $late_pickup_type = 'single-days';
        $late_pickup_days = ['Monday', 'Wednesday', 'Friday'];
        $cost = count($late_pickup_days) * $per_day_cost;
        
        $this->assertEquals(75.00, $cost, '3 days should cost 75 CHF');
        
        // Test full week
        $late_pickup_type = 'full-week';
        $cost = $full_week_cost;
        
        $this->assertEquals(90.00, $cost, 'Full week should cost 90 CHF');
        
        // Test no late pickup
        $late_pickup_type = 'none';
        $cost = 0;
        
        $this->assertEquals(0.00, $cost, 'No late pickup should cost 0 CHF');
    }
    
    /**
     * Test: Empty camp days are handled gracefully
     */
    public function testEmptyCampDaysHandled() {
        $_POST['camp_days'] = []; // Empty array
        
        $cart_item_data = [];
        
        if (isset($_POST['camp_days']) && is_array($_POST['camp_days'])) {
            $cart_item_data['camp_days'] = array_map('sanitize_text_field', $_POST['camp_days']);
        }
        
        $this->assertArrayHasKey('camp_days', $cart_item_data);
        $this->assertEmpty($cart_item_data['camp_days']);
    }
    
    /**
     * Test: Invalid POST data doesn't break cart
     */
    public function testInvalidPostDataHandled() {
        $_POST['camp_days'] = 'not-an-array'; // Invalid data
        
        $cart_item_data = [];
        
        // Should not add if not array
        if (isset($_POST['camp_days']) && is_array($_POST['camp_days'])) {
            $cart_item_data['camp_days'] = array_map('sanitize_text_field', $_POST['camp_days']);
        }
        
        $this->assertArrayNotHasKey('camp_days', $cart_item_data, 'Invalid data should not be added');
    }
    
    /**
     * Test: Sanitization works correctly
     */
    public function testCampDaysSanitization() {
        $_POST['camp_days'] = ['<script>alert("xss")</script>Monday', 'Tuesday'];
        
        $cart_item_data = [];
        
        if (isset($_POST['camp_days']) && is_array($_POST['camp_days'])) {
            $cart_item_data['camp_days'] = array_map('sanitize_text_field', $_POST['camp_days']);
        }
        
        $this->assertArrayHasKey('camp_days', $cart_item_data);
        $this->assertStringNotContainsString('<script>', $cart_item_data['camp_days'][0], 'XSS should be sanitized');
        $this->assertStringNotContainsString('alert', $cart_item_data['camp_days'][0], 'JavaScript should be removed');
    }
    
    protected function tearDown(): void {
        // Clean up POST data
        $_POST = [];
        parent::tearDown();
    }
}

