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
}
