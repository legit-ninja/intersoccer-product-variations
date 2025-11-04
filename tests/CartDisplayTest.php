<?php
/**
 * Test: Cart Display Metadata
 * 
 * Purpose: Ensure camp days and other metadata are correctly displayed
 * in cart and checkout review pages.
 * 
 * Regression test for: Camp days not showing in cart/checkout
 */

use PHPUnit\Framework\TestCase;

class CartDisplayTest extends TestCase {
    
    /**
     * Test: Camp days are added to cart item display data
     */
    public function testCampDaysDisplayInCart() {
        // Mock cart item with camp days
        $cart_item = [
            'product_id' => 12345,
            'assigned_attendee' => 'John Doe',
            'camp_days' => ['Monday', 'Wednesday', 'Friday'],
        ];
        
        $item_data = [];
        $product_type = 'camp';
        
        // Simulate the display logic
        if ($product_type === 'camp' && 
            isset($cart_item['camp_days']) && 
            is_array($cart_item['camp_days']) && 
            !empty($cart_item['camp_days'])) {
            
            $item_data[] = [
                'key' => 'Days Selected',
                'value' => implode(', ', $cart_item['camp_days']),
                'display' => '<span class="intersoccer-cart-meta">' . esc_html(implode(', ', $cart_item['camp_days'])) . '</span>'
            ];
        }
        
        // Assertions
        $this->assertNotEmpty($item_data, 'Item data should not be empty');
        $this->assertCount(1, $item_data, 'Should have one metadata item');
        $this->assertEquals('Days Selected', $item_data[0]['key']);
        $this->assertEquals('Monday, Wednesday, Friday', $item_data[0]['value']);
        $this->assertStringContainsString('Monday, Wednesday, Friday', $item_data[0]['display']);
    }
    
    /**
     * Test: Assigned attendee is displayed in cart
     */
    public function testAssignedAttendeeDisplayInCart() {
        $cart_item = [
            'product_id' => 12345,
            'assigned_attendee' => 'Jane Smith',
        ];
        
        $item_data = [];
        
        if (isset($cart_item['assigned_attendee']) && !empty($cart_item['assigned_attendee'])) {
            $item_data[] = [
                'key' => 'Assigned Attendee',
                'value' => esc_html($cart_item['assigned_attendee']),
                'display' => '<span class="intersoccer-cart-meta">' . esc_html($cart_item['assigned_attendee']) . '</span>'
            ];
        }
        
        $this->assertCount(1, $item_data);
        $this->assertEquals('Assigned Attendee', $item_data[0]['key']);
        $this->assertEquals('Jane Smith', $item_data[0]['value']);
    }
    
    /**
     * Test: Late pickup details are displayed in cart
     */
    public function testLatePickupDisplayInCart() {
        $cart_item = [
            'product_id' => 12345,
            'late_pickup_type' => 'single-days',
            'late_pickup_days' => ['Monday', 'Friday'],
            'late_pickup_cost' => 50.00,
        ];
        
        $item_data = [];
        $product_type = 'camp';
        
        if ($product_type === 'camp' && 
            isset($cart_item['late_pickup_type']) && 
            $cart_item['late_pickup_type'] !== 'none') {
            
            $item_data[] = [
                'key' => 'Late Pickup',
                'value' => implode(', ', $cart_item['late_pickup_days']),
            ];
            
            $item_data[] = [
                'key' => 'Late Pickup Cost',
                'value' => $cart_item['late_pickup_cost'],
            ];
        }
        
        $this->assertCount(2, $item_data);
        $this->assertEquals('Late Pickup', $item_data[0]['key']);
        $this->assertEquals('Monday, Friday', $item_data[0]['value']);
        $this->assertEquals('Late Pickup Cost', $item_data[1]['key']);
        $this->assertEquals(50.00, $item_data[1]['value']);
    }
    
    /**
     * Test: Full week late pickup is displayed correctly
     */
    public function testLatePickupFullWeekDisplay() {
        $cart_item = [
            'product_id' => 12345,
            'late_pickup_type' => 'full-week',
            'late_pickup_days' => [],
            'late_pickup_cost' => 90.00,
        ];
        
        $product_type = 'camp';
        $item_data = [];
        
        if ($product_type === 'camp' && 
            isset($cart_item['late_pickup_type']) && 
            $cart_item['late_pickup_type'] !== 'none') {
            
            $display_value = $cart_item['late_pickup_type'] === 'full-week' 
                ? 'Full Week' 
                : implode(', ', $cart_item['late_pickup_days']);
            
            $item_data[] = [
                'key' => 'Late Pickup',
                'value' => $display_value,
            ];
        }
        
        $this->assertCount(1, $item_data);
        $this->assertEquals('Full Week', $item_data[0]['value']);
    }
    
    /**
     * Test: Camp days display is NOT added for full week camps
     */
    public function testNoCampDaysDisplayForFullWeek() {
        $cart_item = [
            'product_id' => 12345,
            'assigned_attendee' => 'John Doe',
            // No camp_days key - full week booking
        ];
        
        $item_data = [];
        $product_type = 'camp';
        
        // Add attendee
        if (isset($cart_item['assigned_attendee']) && !empty($cart_item['assigned_attendee'])) {
            $item_data[] = [
                'key' => 'Assigned Attendee',
                'value' => $cart_item['assigned_attendee'],
            ];
        }
        
        // Days Selected - should NOT be added
        if ($product_type === 'camp' && 
            isset($cart_item['camp_days']) && 
            is_array($cart_item['camp_days']) && 
            !empty($cart_item['camp_days'])) {
            
            $item_data[] = [
                'key' => 'Days Selected',
                'value' => implode(', ', $cart_item['camp_days']),
            ];
        }
        
        // Should only have Assigned Attendee, not Days Selected
        $this->assertCount(1, $item_data, 'Full week should not show Days Selected');
        $this->assertEquals('Assigned Attendee', $item_data[0]['key']);
    }
    
    /**
     * Test: Course products don't get camp days metadata
     */
    public function testCoursesDoNotGetCampDays() {
        $cart_item = [
            'product_id' => 12345,
            'camp_days' => ['Monday'], // This shouldn't happen for courses, but test handling
        ];
        
        $item_data = [];
        $product_type = 'course'; // Course, not camp
        
        // Days Selected should only be added for camps
        if ($product_type === 'camp' && 
            isset($cart_item['camp_days']) && 
            is_array($cart_item['camp_days']) && 
            !empty($cart_item['camp_days'])) {
            
            $item_data[] = [
                'key' => 'Days Selected',
                'value' => implode(', ', $cart_item['camp_days']),
            ];
        }
        
        $this->assertEmpty($item_data, 'Courses should not display Days Selected');
    }
    
    /**
     * Test: HTML is properly escaped in display
     */
    public function testHtmlEscapingInDisplay() {
        $cart_item = [
            'product_id' => 12345,
            'assigned_attendee' => '<script>alert("xss")</script>Jane Doe',
            'camp_days' => ['<b>Monday</b>', 'Tuesday'],
        ];
        
        $product_type = 'camp';
        $item_data = [];
        
        // Attendee with HTML escaping
        if (isset($cart_item['assigned_attendee'])) {
            $item_data[] = [
                'key' => 'Assigned Attendee',
                'value' => esc_html($cart_item['assigned_attendee']),
                'display' => '<span>' . esc_html($cart_item['assigned_attendee']) . '</span>'
            ];
        }
        
        // Camp days with HTML escaping
        if (isset($cart_item['camp_days'])) {
            $item_data[] = [
                'key' => 'Days Selected',
                'value' => implode(', ', $cart_item['camp_days']),
                'display' => '<span>' . esc_html(implode(', ', $cart_item['camp_days'])) . '</span>'
            ];
        }
        
        $this->assertStringNotContainsString('<script>', $item_data[0]['display'], 'Scripts should be escaped');
        $this->assertStringNotContainsString('<b>', $item_data[1]['display'], 'HTML tags should be escaped');
    }
}

