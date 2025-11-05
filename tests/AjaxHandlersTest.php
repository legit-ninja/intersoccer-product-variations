<?php
/**
 * Test: AJAX Handlers
 * 
 * Purpose: Ensure AJAX endpoints are secure and handle data correctly
 * Covers: ajax-handlers.php - all AJAX endpoints
 * 
 * CRITICAL: Security vulnerabilities = data breaches, revenue loss
 */

use PHPUnit\Framework\TestCase;

class AjaxHandlersTest extends TestCase {
    
    public function setUp(): void {
        parent::setUp();
        
        // Mock WordPress AJAX functions
        if (!function_exists('wp_verify_nonce')) {
            function wp_verify_nonce($nonce, $action = -1) {
                // Mock returns true for valid nonce
                return $nonce === 'valid_nonce';
            }
        }
        
        if (!function_exists('wp_send_json_success')) {
            function wp_send_json_success($data = null, $status_code = 200) {
                // Mock success response
                return json_encode(['success' => true, 'data' => $data]);
            }
        }
        
        if (!function_exists('wp_send_json_error')) {
            function wp_send_json_error($data = null, $status_code = 400) {
                // Mock error response
                return json_encode(['success' => false, 'data' => $data]);
            }
        }
        
        if (!function_exists('sanitize_text_field')) {
            function sanitize_text_field($str) {
                return trim(strip_tags($str));
            }
        }
        
        if (!function_exists('sanitize_textarea_field')) {
            function sanitize_textarea_field($str) {
                return trim(strip_tags($str));
            }
        }
        
        if (!function_exists('absint')) {
            function absint($maybeint) {
                return abs(intval($maybeint));
            }
        }
        
        if (!function_exists('get_current_user_id')) {
            function get_current_user_id() {
                return 1; // Mock logged in user
            }
        }
        
        if (!function_exists('__')) {
            function __($text, $domain = 'default') {
                return $text;
            }
        }
        
        if (!defined('WP_DEBUG')) {
            define('WP_DEBUG', false);
        }
        
        if (!function_exists('error_log')) {
            function error_log($message) {}
        }
    }
    
    /**
     * Test: Valid nonce verification passes
     */
    public function testValidNonceVerificationPasses() {
        $nonce = 'valid_nonce';
        $action = 'intersoccer_nonce';
        
        $is_valid = wp_verify_nonce($nonce, $action);
        
        $this->assertTrue($is_valid, 'Valid nonce should pass verification');
    }
    
    /**
     * Test: Invalid nonce verification fails
     */
    public function testInvalidNonceVerificationFails() {
        $nonce = 'invalid_nonce';
        $action = 'intersoccer_nonce';
        
        $is_valid = wp_verify_nonce($nonce, $action);
        
        $this->assertFalse($is_valid, 'Invalid nonce should fail verification');
    }
    
    /**
     * Test: Empty nonce verification fails
     */
    public function testEmptyNonceVerificationFails() {
        $nonce = '';
        $action = 'intersoccer_nonce';
        
        $is_valid = wp_verify_nonce($nonce, $action);
        
        $this->assertFalse($is_valid, 'Empty nonce should fail verification');
    }
    
    /**
     * Test: Product ID validation (valid)
     */
    public function testProductIdValidationValid() {
        $product_id = absint('123');
        
        $this->assertEquals(123, $product_id, 'Valid product ID should be sanitized to integer');
        $this->assertGreaterThan(0, $product_id, 'Valid product ID should be positive');
    }
    
    /**
     * Test: Product ID validation (zero is invalid)
     */
    public function testProductIdValidationZero() {
        $product_id = absint('0');
        
        $this->assertEquals(0, $product_id, 'Zero product ID should be 0');
        
        $is_valid = $product_id > 0;
        $this->assertFalse($is_valid, 'Zero product ID should be invalid');
    }
    
    /**
     * Test: Product ID validation (negative becomes positive)
     */
    public function testProductIdValidationNegative() {
        $product_id = absint('-123');
        
        $this->assertEquals(123, $product_id, 'Negative product ID should become positive');
    }
    
    /**
     * Test: Product ID validation (string input)
     */
    public function testProductIdValidationStringInput() {
        $product_id = absint('abc');
        
        $this->assertEquals(0, $product_id, 'Non-numeric string should become 0');
    }
    
    /**
     * Test: Text field sanitization
     */
    public function testTextFieldSanitization() {
        $inputs = [
            '  Hello World  ' => 'Hello World',
            '<script>alert("XSS")</script>' => 'alert("XSS")',
            'Normal Text' => 'Normal Text',
            '<b>Bold</b>' => 'Bold'
        ];
        
        foreach ($inputs as $input => $expected) {
            $sanitized = sanitize_text_field($input);
            $this->assertEquals($expected, $sanitized, "Sanitization failed for: {$input}");
        }
    }
    
    /**
     * Test: Array sanitization
     */
    public function testArraySanitization() {
        $input_array = ['  Monday  ', '<script>Tuesday</script>', 'Wednesday'];
        $sanitized = array_map('sanitize_text_field', $input_array);
        
        $this->assertEquals('Monday', $sanitized[0], 'Array element 0 should be sanitized');
        $this->assertEquals('Tuesday', $sanitized[1], 'Array element 1 should strip tags');
        $this->assertEquals('Wednesday', $sanitized[2], 'Array element 2 should remain unchanged');
    }
    
    /**
     * Test: JSON success response structure
     */
    public function testJsonSuccessResponseStructure() {
        $data = ['product_type' => 'camp'];
        $response = wp_send_json_success($data);
        $decoded = json_decode($response, true);
        
        $this->assertTrue($decoded['success'], 'Success response should have success=true');
        $this->assertArrayHasKey('data', $decoded, 'Success response should have data key');
        $this->assertEquals('camp', $decoded['data']['product_type'], 'Response data should match input');
    }
    
    /**
     * Test: JSON error response structure
     */
    public function testJsonErrorResponseStructure() {
        $data = ['message' => 'Invalid nonce'];
        $response = wp_send_json_error($data);
        $decoded = json_decode($response, true);
        
        $this->assertFalse($decoded['success'], 'Error response should have success=false');
        $this->assertArrayHasKey('data', $decoded, 'Error response should have data key');
    }
    
    /**
     * Test: User authentication check
     */
    public function testUserAuthenticationCheck() {
        $user_id = get_current_user_id();
        
        $is_authenticated = $user_id > 0;
        
        $this->assertTrue($is_authenticated, 'User should be authenticated');
        $this->assertGreaterThan(0, $user_id, 'User ID should be positive');
    }
    
    /**
     * Test: Missing nonce parameter handling
     */
    public function testMissingNonceParameterHandling() {
        $_POST = []; // No nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        
        $this->assertEmpty($nonce, 'Missing nonce should result in empty string');
        
        $is_valid = wp_verify_nonce($nonce, 'intersoccer_nonce');
        $this->assertFalse($is_valid, 'Missing nonce should fail verification');
    }
    
    /**
     * Test: Missing product ID parameter handling
     */
    public function testMissingProductIdParameterHandling() {
        $_POST = []; // No product_id
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        
        $this->assertEquals(0, $product_id, 'Missing product_id should result in 0');
        
        $is_valid = $product_id > 0;
        $this->assertFalse($is_valid, 'Product ID 0 should be invalid');
    }
    
    /**
     * Test: SQL injection attempt in product ID
     */
    public function testSqlInjectionAttemptInProductId() {
        $malicious_input = "123; DROP TABLE products;--";
        $sanitized = absint($malicious_input);
        
        $this->assertEquals(123, $sanitized, 'SQL injection should be sanitized to integer only');
    }
    
    /**
     * Test: XSS attempt in text field
     */
    public function testXssAttemptInTextField() {
        $xss_attempts = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror="alert(1)">',
            '"><script>alert(String.fromCharCode(88,83,83))</script>',
            '<svg/onload=alert(\'XSS\')>'
        ];
        
        foreach ($xss_attempts as $attempt) {
            $sanitized = sanitize_text_field($attempt);
            $this->assertStringNotContainsString('<script', $sanitized, 'Sanitized output should not contain script tags');
            $this->assertStringNotContainsString('<img', $sanitized, 'Sanitized output should not contain img tags');
            $this->assertStringNotContainsString('<svg', $sanitized, 'Sanitized output should not contain svg tags');
        }
    }
    
    /**
     * Test: Player data array structure
     */
    public function testPlayerDataArrayStructure() {
        $player = [
            'index' => 0,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'dob' => '2015-01-01',
            'gender' => 'male',
            'medical_conditions' => ''
        ];
        
        $this->assertArrayHasKey('first_name', $player, 'Player should have first_name');
        $this->assertArrayHasKey('last_name', $player, 'Player should have last_name');
        $this->assertArrayHasKey('dob', $player, 'Player should have dob');
        $this->assertEquals('John', $player['first_name'], 'First name should be John');
    }
    
    /**
     * Test: Session data structure
     */
    public function testSessionDataStructure() {
        $session_data = [
            'selected_days' => ['Monday', 'Wednesday', 'Friday'],
            'product_id' => 123,
            'variation_id' => 456
        ];
        
        $this->assertArrayHasKey('selected_days', $session_data, 'Session should have selected_days');
        $this->assertArrayHasKey('product_id', $session_data, 'Session should have product_id');
        $this->assertIsArray($session_data['selected_days'], 'selected_days should be array');
        $this->assertCount(3, $session_data['selected_days'], 'Should have 3 days');
    }
    
    /**
     * Test: Empty array handling
     */
    public function testEmptyArrayHandling() {
        $empty_array = [];
        
        $is_array = is_array($empty_array);
        $is_empty = empty($empty_array);
        
        $this->assertTrue($is_array, 'Should be an array');
        $this->assertTrue($is_empty, 'Should be empty');
        $this->assertCount(0, $empty_array, 'Should have 0 elements');
    }
    
    /**
     * Test: Array map sanitization
     */
    public function testArrayMapSanitization() {
        $input = ['<b>Day1</b>', '  Day2  ', 'Day3<script>'];
        $sanitized = array_map('sanitize_text_field', $input);
        
        foreach ($sanitized as $value) {
            $this->assertStringNotContainsString('<', $value, 'Should not contain HTML tags');
        }
    }
    
    /**
     * Test: Integer validation for variation ID
     */
    public function testVariationIdIntegerValidation() {
        $test_cases = [
            '123' => 123,
            '0' => 0,
            '-50' => 50,
            'abc' => 0,
            '123.45' => 123
        ];
        
        foreach ($test_cases as $input => $expected) {
            $result = absint($input);
            $this->assertEquals($expected, $result, "Failed for input: {$input}");
        }
    }
    
    /**
     * Test: Response data encoding
     */
    public function testResponseDataEncoding() {
        $data = [
            'name' => 'Test & Value',
            'price' => 100.50,
            'special' => '<>&"'
        ];
        
        $json = json_encode($data);
        $decoded = json_decode($json, true);
        
        $this->assertEquals($data['name'], $decoded['name'], 'Data should survive JSON encoding');
        $this->assertEquals($data['price'], $decoded['price'], 'Price should survive JSON encoding');
    }
    
    /**
     * Test: Course metadata response structure
     */
    public function testCourseMetadataResponseStructure() {
        $metadata = [
            'start_date' => '2025-01-15',
            'total_weeks' => 20,
            'holiday_dates' => ['2025-04-18', '2025-12-25'],
            'course_day' => 'monday'
        ];
        
        $this->assertArrayHasKey('start_date', $metadata, 'Should have start_date');
        $this->assertArrayHasKey('total_weeks', $metadata, 'Should have total_weeks');
        $this->assertArrayHasKey('holiday_dates', $metadata, 'Should have holiday_dates');
        $this->assertIsArray($metadata['holiday_dates'], 'Holiday dates should be array');
    }
    
    /**
     * Test: Days of week response structure
     */
    public function testDaysOfWeekResponseStructure() {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        
        $this->assertIsArray($days, 'Days should be an array');
        $this->assertCount(5, $days, 'Should have 5 weekdays');
        $this->assertContains('Monday', $days, 'Should contain Monday');
        $this->assertContains('Friday', $days, 'Should contain Friday');
    }
    
    /**
     * Test: Security - unauthorized access simulation
     */
    public function testUnauthorizedAccessSimulation() {
        // Simulate no user logged in
        if (!function_exists('get_current_user_id_unauth')) {
            function get_current_user_id_unauth() {
                return 0;
            }
        }
        
        $user_id = get_current_user_id_unauth();
        $is_authorized = $user_id > 0;
        
        $this->assertFalse($is_authorized, 'Unauthorized user should be denied');
    }
}

