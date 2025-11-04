<?php
/**
 * Test: Regression Prevention
 * 
 * Purpose: Prevent specific bugs we've encountered from returning.
 * Each test represents a real bug that was fixed.
 */

use PHPUnit\Framework\TestCase;

class RegressionTest extends TestCase {
    
    /**
     * Regression Test: Undefined $product_type variable
     * 
     * Bug: cart-calculations.php line 317 used undefined $product_type variable
     * Fixed: November 2025
     * Impact: PHP warnings on production French site
     */
    public function testNoUndefinedProductTypeVariable() {
        // This test ensures the bug doesn't return
        
        // The function intersoccer_modify_price_html() should NOT use $product_type
        // because it's already filtered by intersoccer_is_camp() before
        
        $file_path = dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';
        $this->assertFileExists($file_path, 'cart-calculations.php should exist');
        
        $file_contents = file_get_contents($file_path);
        
        // Find the function
        $this->assertStringContainsString('function intersoccer_modify_price_html', $file_contents);
        
        // Check that within this function, $product_type is not used without being defined
        // Extract the function
        preg_match('/function intersoccer_modify_price_html\(.*?\n\}/s', $file_contents, $matches);
        
        if (!empty($matches[0])) {
            $function_code = $matches[0];
            
            // If $product_type is used, it must be defined first
            if (strpos($function_code, '$product_type') !== false) {
                // Check that it's defined before use
                $this->assertMatchesRegularExpression(
                    '/\$product_type\s*=.*?;/',
                    $function_code,
                    'If $product_type is used, it must be defined first'
                );
            }
            
            // The fixed version should NOT have $product_type === 'camp' check
            // because intersoccer_is_camp() already filtered it
            $this->assertStringNotContainsString(
                '$product_type === \'camp\'',
                $function_code,
                'Should not check $product_type === \'camp\' (redundant after intersoccer_is_camp())'
            );
            
            $this->assertStringNotContainsString(
                '$product_type === \'course\'',
                $function_code,
                'Should not check $product_type === \'course\' (filtered to camps only)'
            );
        }
    }
    
    /**
     * Regression Test: Camp days missing from cart display
     * 
     * Bug: Camp days were captured and saved but not displayed in cart
     * Fixed: November 2025
     * Impact: Customers couldn't verify selected days before purchase
     */
    public function testCampDaysDisplayLogicExists() {
        $file_path = dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';
        $file_contents = file_get_contents($file_path);
        
        // The function intersoccer_display_cart_item_metadata should exist
        $this->assertStringContainsString(
            'function intersoccer_display_cart_item_metadata',
            $file_contents,
            'Cart display function should exist'
        );
        
        // It should handle camp_days display
        $this->assertStringContainsString(
            'Days Selected',
            $file_contents,
            'Should have "Days Selected" metadata key'
        );
        
        // It should check for camp_days in cart item
        $this->assertStringContainsString(
            'camp_days',
            $file_contents,
            'Should reference camp_days cart item data'
        );
        
        // Extract the function
        preg_match('/function intersoccer_display_cart_item_metadata\(.*?\n\}/s', $file_contents, $matches);
        
        if (!empty($matches[0])) {
            $function_code = $matches[0];
            
            // Should have camp days display logic
            $this->assertMatchesRegularExpression(
                '/camp_days.*Days Selected/s',
                $function_code,
                'Should have logic to display camp days'
            );
        }
    }
    
    /**
     * Regression Test: Emojis in translatable strings
     * 
     * Bug: Emojis like 🚀, 📥, 🔄 in _e() and __() calls caused WPML UTF8 database errors
     * Fixed: November 2025
     * Impact: Plugin activation failed on staging
     */
    public function testNoEmojisInTranslatableStrings() {
        // This is redundant with EmojiTranslationTest.php but kept for clarity
        
        $files = $this->getAllPhpFiles();
        $violations = [];
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);
            
            foreach ($lines as $line_num => $line) {
                // Check for _e() or __() with emojis
                if (preg_match('/_[_e]\(/', $line) && 
                    preg_match('/[\x{1F300}-\x{1F9FF}]/u', $line)) {
                    
                    $violations[] = [
                        'file' => basename($file),
                        'line' => $line_num + 1,
                        'code' => trim($line),
                    ];
                }
            }
        }
        
        if (!empty($violations)) {
            $message = "Found 4-byte emojis in translatable strings:\n";
            foreach ($violations as $v) {
                $message .= "  {$v['file']}:{$v['line']}\n";
            }
            $this->fail($message);
        }
        
        $this->assertTrue(true, 'No emojis in translatable strings');
    }
    
    /**
     * Regression Test: Translation files must be compiled
     * 
     * Bug: .mo files weren't deployed, translations didn't work on production
     * Fixed: November 2025
     * Impact: Multilingual sites showed English text
     */
    public function testCompiledTranslationFilesExist() {
        $languages_dir = dirname(__DIR__) . '/languages';
        
        // If languages directory doesn't exist, test is not applicable
        if (!is_dir($languages_dir)) {
            $this->markTestSkipped('Languages directory does not exist');
            return;
        }
        
        // Get all .po files
        $po_files = glob($languages_dir . '/*.po');
        
        if (empty($po_files)) {
            $this->markTestSkipped('No .po files found');
            return;
        }
        
        // For each .po file, there should be a corresponding .mo file
        foreach ($po_files as $po_file) {
            $mo_file = str_replace('.po', '.mo', $po_file);
            
            $this->assertFileExists(
                $mo_file,
                basename($po_file) . ' should have corresponding .mo file'
            );
            
            // .mo file should not be empty
            $this->assertGreaterThan(
                0,
                filesize($mo_file),
                basename($mo_file) . ' should not be empty'
            );
        }
    }
    
    /**
     * Regression Test: Undefined $variation variable
     * 
     * Bug: Code referenced $variation->get_id() without $variation being defined
     * Fixed: November 2025 (part of $product_type fix)
     * Impact: Potential PHP errors
     */
    public function testNoUndefinedVariationVariable() {
        $file_path = dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';
        $file_contents = file_get_contents($file_path);
        
        // Extract intersoccer_modify_price_html function
        preg_match('/function intersoccer_modify_price_html\(.*?\n\}/s', $file_contents, $matches);
        
        if (!empty($matches[0])) {
            $function_code = $matches[0];
            
            // Should NOT reference $variation variable
            // (it's not available in woocommerce_get_price_html filter)
            $this->assertStringNotContainsString(
                '$variation->',
                $function_code,
                'Should not use $variation in this filter (not available)'
            );
        }
    }
    
    /**
     * Helper: Get all PHP files in plugin (excluding vendor, tests, node_modules)
     */
    private function getAllPhpFiles() {
        $files = [];
        $plugin_root = dirname(__DIR__);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($plugin_root)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = $file->getPathname();
                
                // Skip excluded directories
                if (strpos($path, '/vendor/') !== false ||
                    strpos($path, '/node_modules/') !== false ||
                    strpos($path, '/tests/') !== false) {
                    continue;
                }
                
                $files[] = $path;
            }
        }
        
        return $files;
    }
}

