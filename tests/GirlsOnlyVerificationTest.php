<?php
/**
 * Unit tests for girls-only markers and gender bucket normalization.
 *
 * Run: php vendor/phpunit/phpunit/phpunit --no-configuration tests/GirlsOnlyVerificationTest.php
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';

class GirlsOnlyVerificationTest extends TestCase {

    public function test_normalize_player_gender_bucket_female() {
        $this->assertSame('female', intersoccer_normalize_player_gender_bucket('female'));
        $this->assertSame('female', intersoccer_normalize_player_gender_bucket('Female'));
        $this->assertSame('female', intersoccer_normalize_player_gender_bucket('f'));
        $this->assertSame('female', intersoccer_normalize_player_gender_bucket('Fille'));
    }

    public function test_normalize_player_gender_bucket_male() {
        $this->assertSame('male', intersoccer_normalize_player_gender_bucket('male'));
        $this->assertSame('male', intersoccer_normalize_player_gender_bucket('Male'));
        $this->assertSame('male', intersoccer_normalize_player_gender_bucket('M'));
        $this->assertSame('male', intersoccer_normalize_player_gender_bucket('Garçon'));
    }

    public function test_normalize_player_gender_bucket_other() {
        $this->assertSame('other', intersoccer_normalize_player_gender_bucket('other'));
        $this->assertSame('other', intersoccer_normalize_player_gender_bucket('non-binary'));
    }

    public function test_normalize_player_gender_bucket_unknown() {
        $this->assertSame('unknown', intersoccer_normalize_player_gender_bucket('not-a-gender'));
    }

    public function test_normalize_player_gender_bucket_empty() {
        $this->assertSame('', intersoccer_normalize_player_gender_bucket(''));
        $this->assertSame('', intersoccer_normalize_player_gender_bucket('   '));
    }

    public function test_activity_type_term_is_girls_only_slug() {
        $this->assertTrue(intersoccer_activity_type_term_is_girls_only('girls-only', ''));
        $this->assertTrue(intersoccer_activity_type_term_is_girls_only('girls_only', ''));
        $this->assertTrue(intersoccer_activity_type_term_is_girls_only('filles', ''));
    }

    public function test_activity_type_term_is_girls_only_name() {
        $this->assertTrue(intersoccer_activity_type_term_is_girls_only('', 'Girls only'));
    }

    public function test_activity_type_term_is_not_girls_only_generic_camp() {
        $this->assertFalse(intersoccer_activity_type_term_is_girls_only('camp', 'Camp'));
        $this->assertFalse(intersoccer_activity_type_term_is_girls_only('event', 'Event'));
    }

    public function test_activity_type_term_regex_only_girls_slug() {
        $this->assertTrue(intersoccer_activity_type_term_is_girls_only('only-girls', ''));
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_player_details']);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        parent::tearDown();
    }

    private function girlsOnlyLineFilterAlwaysTrue(): void {
        if (!class_exists('MockFilters')) {
            $this->markTestSkipped('MockFilters requires tests/bootstrap.php');
        }
        MockFilters::$filters['intersoccer_line_is_girls_only_program'] = function ($is_girls_only, $product_id, $variation_id) {
            return true;
        };
    }

    private function wpErrorMessage($error, string $code): string {
        $this->assertTrue(is_wp_error($error));
        $this->assertArrayHasKey($code, $error->errors);
        return (string) $error->errors[$code][0];
    }

    private function assertPlainTranslatedMessage(string $message): void {
        $this->assertStringNotContainsString('&amp;', $message);
        $this->assertStringNotContainsString('&#039;', $message);
        $this->assertDoesNotMatchRegularExpression('/&(?:#x?[0-9a-f]+|amp|lt|gt|quot);/i', $message);
    }

    public function test_validate_line_player_girls_only_wrong_gender_message_is_plain_text() {
        $this->girlsOnlyLineFilterAlwaysTrue();
        $GLOBALS['intersoccer_test_player_details'] = [
            'first_name' => 'Test',
            'last_name' => 'Kid',
            'gender' => 'male',
        ];

        $result = intersoccer_validate_line_player_girls_only(1, 0, 100, 0);
        $message = $this->wpErrorMessage($result, 'intersoccer_girls_only_wrong_gender');

        $this->assertSame(
            'This session is for girls only. Please choose a female attendee or pick a different session.',
            $message
        );
        $this->assertPlainTranslatedMessage($message);
    }

    public function test_validate_line_player_girls_only_gender_required_message_includes_raw_manage_url() {
        $this->girlsOnlyLineFilterAlwaysTrue();
        $GLOBALS['intersoccer_test_player_details'] = [
            'first_name' => 'Test',
            'last_name' => 'Kid',
            'gender' => '',
        ];

        $result = intersoccer_validate_line_player_girls_only(1, 0, 100, 0);
        $message = $this->wpErrorMessage($result, 'intersoccer_girls_only_gender_required');

        $manage_url = function_exists('wc_get_account_endpoint_url')
            ? wc_get_account_endpoint_url('manage-players')
            : '';
        $this->assertNotSame('', $manage_url);
        $expected = sprintf(
            'This session is for girls only. The selected attendee needs gender set to female in your account. Update under Manage Players: %s',
            $manage_url
        );
        $this->assertSame($expected, $message);
        $this->assertPlainTranslatedMessage($message);
        $this->assertStringContainsString('http://', $message);
    }
}
