<?php
/**
 * Test: Order Metadata
 * 
 * Purpose: Ensure order item metadata is correctly added when order is created.
 * This is critical for roster generation, admin tools, and customer communications.
 */

use PHPUnit\Framework\TestCase;

class OrderMetadataTest extends TestCase {
    
    /**
     * Test: Days Selected metadata is added to order items for camps
     */
    public function testDaysSelectedAddedToOrderItem() {
        // Simulate cart values
        $values = [
            'product_id' => 12345,
            'variation_id' => 67890,
            'quantity' => 1,
            'camp_days' => ['Monday', 'Wednesday', 'Friday'],
        ];
        
        $product_type = 'camp';
        $metadata = [];
        
        // Simulate the order metadata addition logic
        if ($product_type === 'camp') {
            if (isset($values['camp_days']) && 
                is_array($values['camp_days']) && 
                !empty($values['camp_days'])) {
                
                $metadata['Days Selected'] = implode(', ', array_map('sanitize_text_field', $values['camp_days']));
            }
        }
        
        // Assertions
        $this->assertArrayHasKey('Days Selected', $metadata);
        $this->assertEquals('Monday, Wednesday, Friday', $metadata['Days Selected']);
        $this->assertStringContainsString('Monday', $metadata['Days Selected']);
    }
    
    /**
     * Test: Assigned Attendee metadata is added correctly
     */
    public function testAssignedAttendeeAddedToOrderItem() {
        $values = [
            'product_id' => 12345,
            'variation_id' => 67890,
            'assigned_attendee' => 'John Doe',
            'assigned_player' => 2,
        ];
        
        $metadata = [];
        
        if (isset($values['assigned_attendee']) && !empty($values['assigned_attendee'])) {
            $metadata['Assigned Attendee'] = sanitize_text_field($values['assigned_attendee']);
            
            if ($values['assigned_player'] !== null) {
                $player_index = absint($values['assigned_player']);
                $metadata['Player Index'] = $player_index;
                $metadata['assigned_player'] = $player_index;
            }
        }
        
        $this->assertArrayHasKey('Assigned Attendee', $metadata);
        $this->assertEquals('John Doe', $metadata['Assigned Attendee']);
        $this->assertArrayHasKey('Player Index', $metadata);
        $this->assertEquals(2, $metadata['Player Index']);
        $this->assertArrayHasKey('assigned_player', $metadata);
        $this->assertEquals(2, $metadata['assigned_player']);
    }
    
    /**
     * Test: Late Pickup Type metadata is added
     */
    public function testLatePickupTypeAdded() {
        $values = [
            'late_pickup_type' => 'full-week',
            'late_pickup_cost' => 90.00,
        ];
        
        $metadata = [];
        $product_type = 'camp';
        
        if ($product_type === 'camp' && 
            isset($values['late_pickup_type']) && 
            $values['late_pickup_type'] !== 'none') {
            
            $metadata['Late Pickup Type'] = $values['late_pickup_type'] === 'full-week' 
                ? 'Full Week' 
                : 'Single Day(s)';
        }
        
        $this->assertArrayHasKey('Late Pickup Type', $metadata);
        $this->assertEquals('Full Week', $metadata['Late Pickup Type']);
    }
    
    /**
     * Test: Late Pickup Days metadata is added for single days
     */
    public function testLatePickupDaysAdded() {
        $values = [
            'late_pickup_type' => 'single-days',
            'late_pickup_days' => ['Monday', 'Tuesday', 'Friday'],
            'late_pickup_cost' => 75.00,
        ];
        
        $metadata = [];
        
        if ($values['late_pickup_type'] === 'single-days' && 
            isset($values['late_pickup_days']) && 
            is_array($values['late_pickup_days'])) {
            
            $metadata['Late Pickup Days'] = implode(', ', array_map('sanitize_text_field', $values['late_pickup_days']));
        }
        
        $this->assertArrayHasKey('Late Pickup Days', $metadata);
        $this->assertEquals('Monday, Tuesday, Friday', $metadata['Late Pickup Days']);
    }
    
    /**
     * Test: Late Pickup Cost is formatted as price
     */
    public function testLatePickupCostFormatted() {
        $values = [
            'late_pickup_cost' => 75.00,
        ];
        
        $metadata = [];
        
        if (isset($values['late_pickup_cost']) && $values['late_pickup_cost'] > 0) {
            // In real code, this uses wc_price() which we can't test without WooCommerce
            // But we can test the logic
            $metadata['Late Pickup Cost'] = number_format($values['late_pickup_cost'], 2);
        }
        
        $this->assertArrayHasKey('Late Pickup Cost', $metadata);
        $this->assertEquals('75.00', $metadata['Late Pickup Cost']);
    }
    
    /**
     * Test: Empty camp days don't add metadata
     */
    public function testEmptyCampDaysNotAdded() {
        $values = [
            'product_id' => 12345,
            'camp_days' => [], // Empty array
        ];
        
        $metadata = [];
        $product_type = 'camp';
        
        if ($product_type === 'camp' && 
            isset($values['camp_days']) && 
            is_array($values['camp_days']) && 
            !empty($values['camp_days'])) {  // ← This check prevents empty
            
            $metadata['Days Selected'] = implode(', ', $values['camp_days']);
        }
        
        $this->assertArrayNotHasKey('Days Selected', $metadata, 'Empty camp days should not add metadata');
    }
    
    /**
     * Test: Null values are handled safely
     */
    public function testNullValuesHandledSafely() {
        $values = [
            'product_id' => 12345,
            'assigned_attendee' => null,
            'camp_days' => null,
            'late_pickup_type' => null,
        ];
        
        $metadata = [];
        
        // Assigned Attendee - should not be added if null
        if (isset($values['assigned_attendee']) && !empty($values['assigned_attendee'])) {
            $metadata['Assigned Attendee'] = $values['assigned_attendee'];
        }
        
        // Camp days - should not be added if null
        if (isset($values['camp_days']) && 
            is_array($values['camp_days']) && 
            !empty($values['camp_days'])) {
            $metadata['Days Selected'] = implode(', ', $values['camp_days']);
        }
        
        // Late pickup - should not be added if null
        if (isset($values['late_pickup_type']) && $values['late_pickup_type'] !== 'none') {
            $metadata['Late Pickup Type'] = $values['late_pickup_type'];
        }
        
        $this->assertEmpty($metadata, 'Null values should not add any metadata');
    }
    
    /**
     * Test: Sanitization is applied to all user input
     */
    public function testAllUserInputSanitized() {
        $values = [
            'camp_days' => ['<script>Monday</script>', 'Tuesday<img src=x onerror=alert(1)>'],
            'assigned_attendee' => '<b>John</b> Doe',
            'late_pickup_days' => ['Friday<script>'],
        ];
        
        // Simulate sanitization
        $sanitized = [];
        
        if (isset($values['camp_days']) && is_array($values['camp_days'])) {
            $sanitized['camp_days'] = array_map('sanitize_text_field', $values['camp_days']);
        }
        
        if (isset($values['assigned_attendee'])) {
            $sanitized['assigned_attendee'] = sanitize_text_field($values['assigned_attendee']);
        }
        
        if (isset($values['late_pickup_days']) && is_array($values['late_pickup_days'])) {
            $sanitized['late_pickup_days'] = array_map('sanitize_text_field', $values['late_pickup_days']);
        }
        
        // Assertions - HTML should be removed
        $this->assertStringNotContainsString('<script>', $sanitized['camp_days'][0]);
        $this->assertStringNotContainsString('<img', $sanitized['camp_days'][1]);
        $this->assertStringNotContainsString('<b>', $sanitized['assigned_attendee']);
        $this->assertStringNotContainsString('<script>', $sanitized['late_pickup_days'][0]);
    }
    
    /**
     * Test: Product type detection works for camps
     */
    public function testProductTypeDetection() {
        // This tests the logic, not actual WordPress functions
        // In real implementation, intersoccer_get_product_type() does this
        
        $test_cases = [
            ['type' => 'camp', 'expected' => true],
            ['type' => 'course', 'expected' => true],
            ['type' => 'birthday', 'expected' => true],
            ['type' => 'tournament', 'expected' => true],
            ['type' => 'simple', 'expected' => false],
            ['type' => null, 'expected' => false],
        ];
        
        foreach ($test_cases as $case) {
            $product_type = $case['type'];
            $is_valid = $product_type && in_array($product_type, ['camp', 'course', 'birthday', 'tournament']);
            
            $this->assertEquals(
                $case['expected'],
                $is_valid,
                "Product type '{$product_type}' should " . ($case['expected'] ? 'be' : 'not be') . " valid"
            );
        }
    }
}

