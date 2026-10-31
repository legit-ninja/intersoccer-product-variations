<?php
/**
 * Girls-only gender-required notice: localized Manage Players link in WP_Error message.
 */

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
    define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

const INTERSOCCER_PV_GIRLS_ONLY_LINK_MSGID = 'This session is for girls only. The selected attendee needs gender set to female in your account. Please update it under <a href="%s">Manage Players</a>.';

if (!function_exists('is_textdomain_loaded')) {
    function is_textdomain_loaded($domain) {
        global $intersoccer_pv_test_textdomain_loaded;
        return !empty($intersoccer_pv_test_textdomain_loaded[$domain]);
    }
}

if (!function_exists('translate')) {
    function translate($text, $domain = 'default') {
        global $intersoccer_pv_test_textdomain_strings;
        if (is_array($intersoccer_pv_test_textdomain_strings) && isset($intersoccer_pv_test_textdomain_strings[$domain][$text])) {
            return $intersoccer_pv_test_textdomain_strings[$domain][$text];
        }
        return $text;
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('wc_get_page_permalink')) {
    function wc_get_page_permalink($page) {
        global $intersoccer_test_myaccount_permalink;
        if ($page === 'myaccount') {
            if (is_string($intersoccer_test_myaccount_permalink)) {
                return $intersoccer_test_myaccount_permalink;
            }
            return 'https://example.test/my-account/';
        }
        return 'https://example.test/my-account/';
    }
}

if (!function_exists('wc_get_endpoint_url')) {
    function wc_get_endpoint_url($endpoint, $value = '', $permalink = '') {
        return trailingslashit((string) $permalink) . $endpoint . '/';
    }
}

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

class GirlsOnlyGenderRequiredNoticeTest extends TestCase {
    private const ALLOWED_LINK_TAGS = [
        'a' => [
            'href' => [],
        ],
    ];

    protected function setUp(): void {
        parent::setUp();
        MockFilters::reset();
        global $intersoccer_test_myaccount_permalink, $intersoccer_pv_test_textdomain_loaded, $intersoccer_pv_test_textdomain_strings;
        $intersoccer_test_myaccount_permalink = '';
        $intersoccer_pv_test_textdomain_loaded = [];
        $intersoccer_pv_test_textdomain_strings = [];
    }

    protected function tearDown(): void {
        MockFilters::reset();
        parent::tearDown();
    }

    #[Test]
    public function test_english_message_contains_localized_manage_players_link(): void {
        global $intersoccer_test_myaccount_permalink;
        $intersoccer_test_myaccount_permalink = 'https://example.test/my-account/';
        MockFilters::$filters['wpml_translate_single_string'] = static function ($slug) {
            return $slug;
        };

        $url = intersoccer_pv_manage_players_url();
        $message = intersoccer_pv_girls_only_gender_required_message();

        $this->assertStringContainsString('<a href="', $message);
        $this->assertStringContainsString('href="' . esc_url($url) . '"', $message);
        $this->assertStringContainsString('>Manage Players</a>', $message);
        $this->assertSame($message, wp_kses($message, self::ALLOWED_LINK_TAGS));
    }

    #[Test]
    public function test_french_message_uses_localized_manage_players_url(): void {
        global $intersoccer_test_myaccount_permalink, $intersoccer_pv_test_textdomain_loaded, $intersoccer_pv_test_textdomain_strings;
        $intersoccer_test_myaccount_permalink = 'https://example.test/fr/mon-compte/';
        $intersoccer_pv_test_textdomain_loaded['intersoccer-product-variations'] = true;
        $intersoccer_pv_test_textdomain_strings['intersoccer-product-variations'] = [
            INTERSOCCER_PV_GIRLS_ONLY_LINK_MSGID => 'Cette séance est réservée aux filles. Le participant sélectionné doit avoir le genre « femme » dans votre compte. Veuillez le mettre à jour dans <a href="%s">Gérer les participants</a>.',
        ];

        MockFilters::$filters['wpml_current_language'] = static function () {
            return 'fr';
        };
        MockFilters::$filters['wpml_translate_single_string'] = static function ($slug) {
            return $slug === 'manage-players' ? 'gerer-participants' : $slug;
        };

        $url = intersoccer_pv_manage_players_url();
        $expected_url = 'https://example.test/fr/mon-compte/gerer-participants/';
        $this->assertSame($expected_url, $url);

        $message = intersoccer_pv_girls_only_gender_required_message();
        $this->assertStringContainsString('href="' . esc_url($url) . '"', $message);
        $this->assertStringContainsString('>Gérer les participants</a>', $message);
        $this->assertSame($message, wp_kses($message, self::ALLOWED_LINK_TAGS));
    }

    #[Test]
    public function test_fallback_plain_text_when_manage_players_url_unavailable(): void {
        if (!function_exists('intersoccer_pv_manage_players_url')) {
            $this->markTestSkipped('Manage Players URL helper unavailable');
        }

        MockFilters::$filters['wpml_translate_single_string'] = static function () {
            return '';
        };
        global $intersoccer_test_myaccount_permalink;
        $intersoccer_test_myaccount_permalink = '';

        $message = intersoccer_pv_girls_only_gender_required_message();
        $this->assertStringNotContainsString('<a href="', $message);
        $this->assertStringContainsString('Please update Manage Players before booking.', $message);
    }
}
