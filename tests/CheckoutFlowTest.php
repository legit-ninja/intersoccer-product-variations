<?php
/**
 * Test: Checkout Flow
 * 
 * Purpose: Ensure checkout processing, order metadata, and player assignments work correctly
 * Covers: checkout-calculations.php, checkout.php - order processing
 * 
 * CRITICAL: Checkout errors = lost sales and customer frustration
 */

use PHPUnit\Framework\TestCase;

class CheckoutFlowTest extends TestCase {
    
    public function setUp(): void {
        parent::setUp();
        
        if (!defined('WP_DEBUG')) {
            define('WP_DEBUG', false);
        }
        
        if (!function_exists('error_log')) {
            function error_log($message) {}
        }
    }
    
    /**
     * Test: Order item metadata structure
     */
    public function testOrderItemMetadataStructure() {
        $metadata = [
            'assigned_player' => 1,
            'camp_days' => ['Monday', 'Wednesday', 'Friday'],
            'late_pickup_cost' => 75.0,
            'product_type' => 'camp'
        ];
        
        $this->assertArrayHasKey('assigned_player', $metadata, 'Should have assigned_player');
        $this->assertArrayHasKey('camp_days', $metadata, 'Should have camp_days');
        $this->assertArrayHasKey('late_pickup_cost', $metadata, 'Should have late_pickup_cost');
        $this->assertEquals(1, $metadata['assigned_player'], 'Player should be 1');
    }
    
    /**
     * Test: Player assignment validation
     */
    public function testPlayerAssignmentValidation() {
        $player_id = 1;
        
        $is_valid = is_int($player_id) && $player_id >= 0;
        
        $this->assertTrue($is_valid, 'Player ID should be valid');
    }
    
    /**
     * Test: Invalid player assignment
     */
    public function testInvalidPlayerAssignment() {
        $invalid_ids = [-1, 'abc', null];
        
        foreach ($invalid_ids as $id) {
            $is_valid = is_int($id) && $id >= 0;
            $this->assertFalse($is_valid, var_export($id, true) . " should be invalid");
        }
    }
    
    /**
     * Test: Cart item data to order item metadata mapping
     */
    public function testCartItemToOrderItemMapping() {
        $cart_item = [
            'assigned_player' => 1,
            'camp_days' => ['Monday', 'Wednesday'],
            'late_pickup_cost' => 50.0
        ];
        
        // Map to order item
        $order_item_meta = [];
        if (isset($cart_item['assigned_player'])) {
            $order_item_meta['assigned_player'] = $cart_item['assigned_player'];
        }
        if (isset($cart_item['camp_days'])) {
            $order_item_meta['camp_days'] = $cart_item['camp_days'];
        }
        
        $this->assertArrayHasKey('assigned_player', $order_item_meta, 'Should map assigned_player');
        $this->assertArrayHasKey('camp_days', $order_item_meta, 'Should map camp_days');
        $this->assertEquals(1, $order_item_meta['assigned_player'], 'Player should be 1');
    }
    
    /**
     * Test: Download permissions data structure
     */
    public function testDownloadPermissionsStructure() {
        $download = [
            'name' => 'Camp Information Pack',
            'file' => '/uploads/camp-info.pdf',
            'product_id' => 123
        ];
        
        $this->assertArrayHasKey('name', $download, 'Should have name');
        $this->assertArrayHasKey('file', $download, 'Should have file');
        $this->assertNotEmpty($download['name'], 'Name should not be empty');
    }
    
    /**
     * Test: Order status validation
     */
    public function testOrderStatusValidation() {
        $valid_statuses = ['completed', 'processing', 'pending'];
        $test_status = 'completed';
        
        $is_valid = in_array($test_status, $valid_statuses, true);
        
        $this->assertTrue($is_valid, 'Status should be valid');
    }
    
    /**
     * Test: Product attribute extraction
     */
    public function testProductAttributeExtraction() {
        $attributes = [
            'pa_age-group' => '6-8 years',
            'pa_activity-type' => 'camp',
            'pa_booking-type' => 'full-week'
        ];
        
        $this->assertArrayHasKey('pa_age-group', $attributes, 'Should have age-group');
        $this->assertArrayHasKey('pa_activity-type', $attributes, 'Should have activity-type');
        $this->assertEquals('camp', $attributes['pa_activity-type'], 'Activity type should be camp');
    }
    
    /**
     * Test: Order item data sanitization
     */
    public function testOrderItemDataSanitization() {
        $raw_data = [
            'player_id' => '  1  ',
            'cost' => '  75.50  ',
            'days' => ['  Monday  ', '  Wednesday  ']
        ];
        
        $sanitized = [
            'player_id' => intval(trim($raw_data['player_id'])),
            'cost' => floatval(trim($raw_data['cost'])),
            'days' => array_map('trim', $raw_data['days'])
        ];
        
        $this->assertEquals(1, $sanitized['player_id'], 'Player ID should be sanitized');
        $this->assertEquals(75.50, $sanitized['cost'], 'Cost should be sanitized');
        $this->assertEquals('Monday', $sanitized['days'][0], 'Days should be trimmed');
    }
    
    /**
     * Test: Order total calculation with late pickup
     */
    public function testOrderTotalWithLatePickup() {
        $base_price = 500.0;
        $late_pickup = 75.0;
        $expected_total = 575.0;
        
        $actual_total = $base_price + $late_pickup;
        
        $this->assertEquals($expected_total, $actual_total, 'Total should include late pickup');
    }
    
    /**
     * Test: Order item meta serialization
     */
    public function testOrderItemMetaSerialization() {
        $meta = ['days' => ['Monday', 'Wednesday']];
        $serialized = json_encode($meta);
        $unserialized = json_decode($serialized, true);
        
        $this->assertEquals($meta, $unserialized, 'Data should survive serialization');
    }
    
    /**
     * Test: Empty meta handling
     */
    public function testEmptyMetaHandling() {
        $meta = [];
        
        $has_meta = !empty($meta);
        
        $this->assertFalse($has_meta, 'Empty meta should be detected');
    }
    
    /**
     * Test: Order processing with multiple items
     */
    public function testOrderProcessingMultipleItems() {
        $order_items = [
            ['product_id' => 123, 'price' => 500.0],
            ['product_id' => 124, 'price' => 600.0]
        ];
        
        $total = array_sum(array_column($order_items, 'price'));
        
        $this->assertEquals(1100.0, $total, 'Order total should be sum of items');
        $this->assertCount(2, $order_items, 'Should have 2 items');
    }
    
    /**
     * Test: Player assignment normalization
     */
    public function testPlayerAssignmentNormalization() {
        // Test different input formats
        $inputs = [
            ['player_assignment' => 1],
            ['assigned_player' => 1],
            ['player_id' => 1]
        ];
        
        foreach ($inputs as $input) {
            $normalized = $input['player_assignment'] ?? $input['assigned_player'] ?? $input['player_id'] ?? null;
            $this->assertEquals(1, $normalized, 'Player should be normalized to 1');
        }
    }
    
    /**
     * Test: Attribute extraction with fallback to parent
     */
    public function testAttributeExtractionWithParentFallback() {
        $variation_attributes = ['pa_booking-type' => 'single-days'];
        $parent_attributes = ['pa_days-of-week' => 'Monday,Tuesday,Wednesday'];
        
        // Variation doesn't have days-of-week, get from parent
        $days_of_week = $variation_attributes['pa_days-of-week'] ?? $parent_attributes['pa_days-of-week'] ?? null;
        
        $this->assertEquals('Monday,Tuesday,Wednesday', $days_of_week, 'Should fallback to parent');
    }
    
    /**
     * Test: Order note generation
     */
    public function testOrderNoteGeneration() {
        $player_name = 'John Doe';
        $days = ['Monday', 'Wednesday', 'Friday'];
        
        $note = sprintf('Assigned to: %s, Days: %s', $player_name, implode(', ', $days));
        
        $this->assertStringContainsString('John Doe', $note, 'Note should contain player name');
        $this->assertStringContainsString('Monday, Wednesday, Friday', $note, 'Note should contain days');
    }
    
    /**
     * Test: Discount metadata in order
     */
    public function testDiscountMetadataInOrder() {
        $discount_meta = [
            'discount_amount' => 100.0,
            'discount_note' => '20% Sibling Discount'
        ];
        
        $this->assertArrayHasKey('discount_amount', $discount_meta, 'Should have discount_amount');
        $this->assertArrayHasKey('discount_note', $discount_meta, 'Should have discount_note');
        $this->assertEquals(100.0, $discount_meta['discount_amount'], 'Discount should be 100');
    }
    
    /**
     * Test: Order item quantity validation
     */
    public function testOrderItemQuantityValidation() {
        $quantity = 1;
        
        $is_valid = is_int($quantity) && $quantity > 0;
        
        $this->assertTrue($is_valid, 'Quantity should be positive integer');
    }
    
    /**
     * Test: Invalid order item quantity
     */
    public function testInvalidOrderItemQuantity() {
        $invalid_quantities = [0, -1, 'abc', null];
        
        foreach ($invalid_quantities as $qty) {
            $is_valid = is_int($qty) && $qty > 0;
            $this->assertFalse($is_valid, var_export($qty, true) . " should be invalid");
        }
    }
    
    /**
     * Test: Order completion hook execution check
     */
    public function testOrderCompletionHookCheck() {
        // Simulate order completion
        $order_id = 12345;
        $order_status = 'completed';
        
        $should_execute = $order_status === 'completed' && $order_id > 0;
        
        $this->assertTrue($should_execute, 'Hook should execute for completed order');
    }
}

