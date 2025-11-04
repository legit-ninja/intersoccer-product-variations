<?php
/**
 * Test: Emoji in Translatable Strings
 * 
 * Purpose: Prevent 4-byte emojis from being used in _e() and __() calls
 * Reason: WPML database tables use UTF8 (not UTF8MB4) on staging
 * 
 * This test ensures emojis don't sneak into translatable strings where
 * WPML would throw a database error on staging.
 * 
 * Emojis are FINE in:
 * - Non-translated UI elements (hardcoded HTML)
 * - error_log() calls
 * - console.log() calls
 * - Admin notices
 * 
 * Emojis are NOT ALLOWED in:
 * - _e() calls
 * - __() calls
 * - Any WordPress i18n functions
 */

class EmojiTranslationTest extends PHPUnit\Framework\TestCase {
    
    private $plugin_root;
    private $emoji_pattern = '/[\x{1F300}-\x{1F9FF}]/u'; // 4-byte emoji range
    
    public function setUp(): void {
        $this->plugin_root = dirname(__DIR__);
    }
    
    /**
     * Test: No emojis in _e() translatable strings
     */
    public function testNoEmojisInECalls() {
        $violations = $this->findEmojisInFunction('_e');
        
        if (!empty($violations)) {
            $message = "Found emojis in _e() calls (will fail on staging UTF8 database):\n\n";
            foreach ($violations as $violation) {
                $message .= "  File: {$violation['file']}\n";
                $message .= "  Line: {$violation['line']}\n";
                $message .= "  Code: {$violation['code']}\n\n";
            }
            $message .= "Fix: Remove emojis from translatable strings.\n";
            $message .= "Safe alternatives: ▶ ■ ↓ ↻ ✓ ⚠ → ←\n";
            
            $this->fail($message);
        }
        
        $this->assertTrue(true, 'No emojis found in _e() calls');
    }
    
    /**
     * Test: No emojis in __() translatable strings
     */
    public function testNoEmojisInUnderscoreCalls() {
        $violations = $this->findEmojisInFunction('__');
        
        if (!empty($violations)) {
            $message = "Found emojis in __() calls (will fail on staging UTF8 database):\n\n";
            foreach ($violations as $violation) {
                $message .= "  File: {$violation['file']}\n";
                $message .= "  Line: {$violation['line']}\n";
                $message .= "  Code: {$violation['code']}\n\n";
            }
            $message .= "Fix: Remove emojis from translatable strings.\n";
            $message .= "Safe alternatives: ▶ ■ ↓ ↻ ✓ ⚠ → ←\n";
            
            $this->fail($message);
        }
        
        $this->assertTrue(true, 'No emojis found in __() calls');
    }
    
    /**
     * Test: No emojis in _n() (plural) translatable strings
     */
    public function testNoEmojisInPluralCalls() {
        $violations = $this->findEmojisInFunction('_n');
        
        if (!empty($violations)) {
            $message = "Found emojis in _n() calls (will fail on staging UTF8 database):\n\n";
            foreach ($violations as $violation) {
                $message .= "  File: {$violation['file']}\n";
                $message .= "  Line: {$violation['line']}\n";
                $message .= "  Code: {$violation['code']}\n\n";
            }
            
            $this->fail($message);
        }
        
        $this->assertTrue(true, 'No emojis found in _n() calls');
    }
    
    /**
     * Test: No emojis in _x() (context) translatable strings
     */
    public function testNoEmojisInContextCalls() {
        $violations = $this->findEmojisInFunction('_x');
        
        if (!empty($violations)) {
            $message = "Found emojis in _x() calls (will fail on staging UTF8 database):\n\n";
            foreach ($violations as $violation) {
                $message .= "  File: {$violation['file']}\n";
                $message .= "  Line: {$violation['line']}\n";
                $message .= "  Code: {$violation['code']}\n\n";
            }
            
            $this->fail($message);
        }
        
        $this->assertTrue(true, 'No emojis found in _x() calls');
    }
    
    /**
     * Find emojis in specific translation function calls
     * 
     * @param string $function Function name (_e, __, _n, _x)
     * @return array Violations found
     */
    private function findEmojisInFunction($function) {
        $violations = [];
        $files = $this->getPhpFiles();
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);
            
            foreach ($lines as $line_num => $line) {
                // Check if line contains the function call
                if (strpos($line, $function . '(') !== false) {
                    // Check if line contains emoji
                    if (preg_match($this->emoji_pattern, $line)) {
                        $violations[] = [
                            'file' => str_replace($this->plugin_root . '/', '', $file),
                            'line' => $line_num + 1,
                            'code' => trim($line),
                        ];
                    }
                }
            }
        }
        
        return $violations;
    }
    
    /**
     * Get all PHP files in the plugin (excluding vendor, tests, node_modules)
     * 
     * @return array List of PHP file paths
     */
    private function getPhpFiles() {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->plugin_root)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = $file->getPathname();
                
                // Skip vendor, tests, node_modules
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

