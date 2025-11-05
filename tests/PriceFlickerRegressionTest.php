<?php
/**
 * Test: Price Flicker Regression
 * 
 * Purpose: Prevent the price flickering bug from returning
 * Bug Fixed: November 5, 2025
 * Impact: Customers saw rapidly changing prices (CHF 110 → 135 → 160 → 245)
 * 
 * Root Cause:
 * - Base price was cleared on every variation event (even when ID unchanged)
 * - Code fell back to reading displayed price as base (which already included late pickup)
 * - This caused compounding: (110 + 25) stored as new base 135 + 25 = 160, etc.
 * 
 * Fix Location: includes/elementor-widgets.php
 * - Lines 1389-1404: Track variation ID, only reset on actual change
 * - Lines 945-957: Never use displayed price as base fallback
 * - Lines 733-738: Properly update base from AJAX response
 * 
 * This test ensures these fixes remain in place.
 */

use PHPUnit\Framework\TestCase;

class PriceFlickerRegressionTest extends TestCase {
    
    /**
     * Test: Base price stored from variation data (not displayed HTML)
     * 
     * Bug: Code read displayed HTML price (which included late pickup) as base
     * Fix: Base price comes from variation.display_price
     */
    public function testBasePriceFromVariationData() {
        $file_path = dirname(__DIR__) . '/includes/elementor-widgets.php';
        $file_contents = file_get_contents($file_path);
        
        // Find the found_variation event handler
        $this->assertStringContainsString(
            'found_variation',
            $file_contents,
            'Should have found_variation event handler'
        );
        
        // Should store base price from variation.display_price
        $this->assertStringContainsString(
            'variation.display_price',
            $file_contents,
            'Should store base price from variation.display_price'
        );
        
        // Should store variation ID for tracking
        $this->assertStringContainsString(
            'intersoccer-variation-id',
            $file_contents,
            'Should track variation ID to detect changes'
        );
        
        // Extract found_variation handler
        preg_match(
            '/\$form\.on\([\'"]found_variation[\'"](.*?)}\s*\);/s',
            $file_contents,
            $matches
        );
        
        if (!empty($matches[1])) {
            $handler_code = $matches[1];
            
            // Should check if variation ID changed
            $this->assertMatchesRegularExpression(
                '/previousVariationId.*variation\.variation_id/s',
                $handler_code,
                'Should compare previous and current variation IDs'
            );
            
            // Should store base price from variation data
            $this->assertMatchesRegularExpression(
                '/basePrice.*parseFloat\(variation\.display_price\)/s',
                $handler_code,
                'Should parse base price from variation.display_price'
            );
            
            // Should preserve base price when variation ID unchanged
            $this->assertStringContainsString(
                'preserving stored base price',
                $handler_code,
                'Should log preservation of base price for same variation'
            );
        }
    }
    
    /**
     * Test: Base price NOT cleared on same variation (critical fix)
     * 
     * Bug: Every event cleared base price, even when variation unchanged
     * Fix: Only clear/reset when variation_id actually changes
     */
    public function testBasePricePreservedOnSameVariation() {
        $file_path = dirname(__DIR__) . '/includes/elementor-widgets.php';
        $file_contents = file_get_contents($file_path);
        
        // Should NOT unconditionally call removeData('intersoccer-base-price')
        // The old buggy code had this line:
        // jQuery('.woocommerce-variation-price').removeData('intersoccer-base-price');
        
        // Extract found_variation handler
        preg_match(
            '/\$form\.on\([\'"]found_variation[\'"](.*?)}\s*\);/s',
            $file_contents,
            $matches
        );
        
        if (!empty($matches[1])) {
            $handler_code = $matches[1];
            
            // Should have conditional logic for clearing base price
            $this->assertMatchesRegularExpression(
                '/if\s*\([^)]*previousVariationId/s',
                $handler_code,
                'Should conditionally clear base price based on variation ID change'
            );
            
            // Should NOT have unconditional removeData
            $lines = explode("\n", $handler_code);
            foreach ($lines as $line) {
                if (strpos($line, 'removeData') !== false && 
                    strpos($line, 'intersoccer-base-price') !== false) {
                    
                    // If removeData exists, it must be inside a conditional
                    $this->fail(
                        'Found unconditional removeData for base price. ' .
                        'Base price should only be cleared when variation ID changes. ' .
                        'Line: ' . trim($line)
                    );
                }
            }
            
            // Should log preservation message
            $this->assertStringContainsString(
                'Same variation, preserving',
                $handler_code,
                'Should log when preserving base price'
            );
        }
    }
    
    /**
     * Test: NEVER read displayed price as base fallback (critical fix)
     * 
     * Bug: updateMainPriceWithLatePickup() read displayed HTML if no base price
     * Fix: Return early if no base price, never fall back to displayed price
     */
    public function testNeverUseDisplayedPriceAsBase() {
        $file_path = dirname(__DIR__) . '/includes/elementor-widgets.php';
        $file_contents = file_get_contents($file_path);
        
        // Find updateMainPriceWithLatePickup function
        $this->assertStringContainsString(
            'function updateMainPriceWithLatePickup',
            $file_contents,
            'Should have updateMainPriceWithLatePickup function'
        );
        
        // Extract the function
        preg_match(
            '/function updateMainPriceWithLatePickup\((.*?)\n            function/s',
            $file_contents,
            $matches
        );
        
        if (!empty($matches[1])) {
            $function_code = $matches[1];
            
            // Should get base price from data attribute
            $this->assertStringContainsString(
                'data(\'intersoccer-base-price\')',
                $function_code,
                'Should read base price from data attribute'
            );
            
            // Should return early if no base price
            $this->assertMatchesRegularExpression(
                '/if\s*\(!basePrice.*\)\s*{\s*.*return/s',
                $function_code,
                'Should return early if base price not available'
            );
            
            // Should NOT extract price from HTML as fallback
            // The old buggy code had:
            // var priceMatch = currentPriceHtml.match(/[\d,]+\.?\d*/);
            // basePrice = parseFloat(priceMatch[0].replace(',', ''));
            
            $this->assertStringNotContainsString(
                'currentPriceHtml',
                $function_code,
                'Should NOT read current price HTML (was used as buggy fallback)'
            );
            
            $this->assertStringNotContainsString(
                'priceMatch',
                $function_code,
                'Should NOT extract price from HTML text (was used as buggy fallback)'
            );
            
            // Should log if base price not available
            $this->assertStringContainsString(
                'Base price not available',
                $function_code,
                'Should log when base price is missing'
            );
        }
    }
    
    /**
     * Test: AJAX updates base price correctly
     * 
     * When camp days change, AJAX returns new camp price
     * This should update the base price for late pickup recalculation
     */
    public function testAjaxUpdatesBasePrice() {
        $file_path = dirname(__DIR__) . '/includes/elementor-widgets.php';
        $file_contents = file_get_contents($file_path);
        
        // Should have intersoccer_price_updated event listener
        $this->assertStringContainsString(
            'intersoccer_price_updated',
            $file_contents,
            'Should listen for price update events'
        );
        
        // Extract the event handler
        preg_match(
            '/\.on\([\'"]intersoccer_price_updated[^\)]*\)(.*?)}\);/s',
            $file_contents,
            $matches
        );
        
        if (!empty($matches[1])) {
            $handler_code = $matches[1];
            
            // Should update base price from AJAX data
            $this->assertStringContainsString(
                'data.rawPrice',
                $handler_code,
                'Should read rawPrice from AJAX response'
            );
            
            $this->assertMatchesRegularExpression(
                '/data\([\'"]intersoccer-base-price[\'"], *newBasePrice\)/s',
                $handler_code,
                'Should store new base price from AJAX'
            );
            
            // Should recalculate late pickup with new base
            $this->assertStringContainsString(
                'updateLatePickupCost',
                $handler_code,
                'Should recalculate late pickup cost after price update'
            );
            
            // Should log the update
            $this->assertStringContainsString(
                'Updated base price from AJAX',
                $handler_code,
                'Should log AJAX base price update'
            );
        }
    }
    
    /**
     * Test: Variation ID tracking prevents unnecessary resets
     * 
     * Same variation ID = preserve base price
     * Different variation ID = store new base price
     */
    public function testVariationIdTracking() {
        $file_path = dirname(__DIR__) . '/includes/elementor-widgets.php';
        $file_contents = file_get_contents($file_path);
        
        // Extract found_variation handler
        preg_match(
            '/\$form\.on\([\'"]found_variation[\'"](.*?)}\s*\);/s',
            $file_contents,
            $matches
        );
        
        if (!empty($matches[1])) {
            $handler_code = $matches[1];
            
            // Should read previous variation ID
            $this->assertMatchesRegularExpression(
                '/previousVariationId.*data\([\'"]intersoccer-variation-id/s',
                $handler_code,
                'Should read previous variation ID from data attribute'
            );
            
            // Should compare with current variation ID
            $this->assertMatchesRegularExpression(
                '/previousVariationId.*variation\.variation_id/s',
                $handler_code,
                'Should compare previous with current variation ID'
            );
            
            // Should store variation ID with base price
            $this->assertMatchesRegularExpression(
                '/data\([\'"]intersoccer-variation-id[\'"], *variation\.variation_id\)/s',
                $handler_code,
                'Should store current variation ID'
            );
            
            // Should have different behavior for same vs different variation
            $this->assertStringContainsString(
                'New variation detected',
                $handler_code,
                'Should log when variation ID changes'
            );
            
            $this->assertStringContainsString(
                'Same variation',
                $handler_code,
                'Should log when variation ID unchanged'
            );
        }
    }
    
    /**
     * Test: Console log messages for debugging
     * 
     * Helpful logs were critical in diagnosing the original bug
     * They should remain for future debugging
     */
    public function testDebuggingConsoleLogs() {
        $file_path = dirname(__DIR__) . '/includes/elementor-widgets.php';
        $file_contents = file_get_contents($file_path);
        
        // Critical debugging messages
        $required_logs = [
            'New variation detected, storing base price from variation data',
            'Same variation, preserving stored base price',
            'Updated base price from AJAX',
            'Base price not available yet',
            'Camp price updated, recalculating with late pickup',
        ];
        
        foreach ($required_logs as $log) {
            $this->assertStringContainsString(
                $log,
                $file_contents,
                "Should have console log: '$log' (critical for debugging)"
            );
        }
    }
    
    /**
     * Test: No compounding price pattern in code
     * 
     * The bug pattern was:
     * 1. Read displayed price (135)
     * 2. Store as base (135)
     * 3. Add late pickup (135 + 25 = 160)
     * 4. Repeat (160 + 25 = 185)
     * 
     * This pattern should NOT be possible in the code
     */
    public function testNoCompoundingPattern() {
        $file_path = dirname(__DIR__) . '/includes/elementor-widgets.php';
        $file_contents = file_get_contents($file_path);
        
        // Extract updateMainPriceWithLatePickup function
        preg_match(
            '/function updateMainPriceWithLatePickup\((.*?)\n            function/s',
            $file_contents,
            $matches
        );
        
        if (!empty($matches[1])) {
            $function_code = $matches[1];
            
            // Calculate total should be: base + late pickup
            $this->assertMatchesRegularExpression(
                '/totalPrice\s*=\s*basePrice\s*\+\s*.*latePickupCost/s',
                $function_code,
                'Total should be base + late pickup'
            );
            
            // Base price should ONLY come from data attribute
            // Count how many times basePrice is assigned
            preg_match_all('/basePrice\s*=/', $function_code, $assignments);
            $assignment_count = count($assignments[0]);
            
            $this->assertEquals(
                1,
                $assignment_count,
                'basePrice should be assigned exactly once (from data attribute only)'
            );
            
            // That one assignment should be from data attribute
            preg_match('/basePrice\s*=\s*([^;]+);/', $function_code, $assignment);
            if (!empty($assignment[1])) {
                $this->assertStringContainsString(
                    'data(\'intersoccer-base-price\')',
                    $assignment[1],
                    'basePrice assignment should be from data attribute'
                );
            }
        }
    }
    
    /**
     * Test: Documentation of fix exists
     * 
     * Ensure the fix is documented for future maintainers
     */
    public function testFixIsDocumented() {
        $doc_path = dirname(__DIR__) . '/docs/PRICE-FLICKER-FIX.md';
        
        $this->assertFileExists(
            $doc_path,
            'Price flicker fix should be documented in docs/PRICE-FLICKER-FIX.md'
        );
        
        $doc_contents = file_get_contents($doc_path);
        
        // Should document the root cause
        $this->assertStringContainsString(
            'Root Cause',
            $doc_contents,
            'Documentation should explain root cause'
        );
        
        // Should document the solution
        $this->assertStringContainsString(
            'Solution',
            $doc_contents,
            'Documentation should explain solution'
        );
        
        // Should document testing
        $this->assertStringContainsString(
            'Testing',
            $doc_contents,
            'Documentation should include testing checklist'
        );
        
        // Should mention the key fix
        $this->assertStringContainsString(
            'variation.display_price',
            $doc_contents,
            'Should document use of variation.display_price'
        );
    }
    
    /**
     * Test: Related files haven't introduced similar bugs
     * 
     * Check other JavaScript files for similar patterns
     */
    public function testNoSimilarBugsInOtherFiles() {
        $js_files = [
            dirname(__DIR__) . '/js/late-pickup.js',
            dirname(__DIR__) . '/js/variation-details.js',
        ];
        
        foreach ($js_files as $file) {
            if (!file_exists($file)) {
                continue;
            }
            
            $contents = file_get_contents($file);
            $filename = basename($file);
            
            // If these files store base price, they should do it correctly
            if (strpos($contents, 'base_price') !== false || 
                strpos($contents, 'basePrice') !== false) {
                
                // Should not extract price from HTML text for storage
                $lines = explode("\n", $contents);
                foreach ($lines as $line_num => $line) {
                    // If line stores base price
                    if (preg_match('/base.*price.*=/', $line, $matches)) {
                        // It should not come from HTML parsing
                        $this->assertStringNotContainsString(
                            '.text()',
                            $line,
                            "$filename:$line_num stores base price from .text() - potential compounding bug"
                        );
                        
                        $this->assertStringNotContainsString(
                            '.html()',
                            $line,
                            "$filename:$line_num stores base price from .html() - potential compounding bug"
                        );
                    }
                }
            }
        }
    }
}

