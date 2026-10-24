<?php
/**
 * FR/DE customer-facing PV strings: catalogs, helpers, localized Manage Players URLs.
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
    define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

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
        if ($page === 'myaccount' && is_string($intersoccer_test_myaccount_permalink) && $intersoccer_test_myaccount_permalink !== '') {
            return $intersoccer_test_myaccount_permalink;
        }
        return 'https://example.test/my-account/';
    }
}

if (!function_exists('wc_get_endpoint_url')) {
    function wc_get_endpoint_url($endpoint, $value = '', $permalink = '') {
        return trailingslashit((string) $permalink) . $endpoint . '/';
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []) {
        if (!is_array($args)) {
            return $defaults;
        }
        return array_merge($defaults, $args);
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses($string, $allowed_html, $allowed_protocols = []) {
        return (string) $string;
    }
}

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/language-helpers.php';

class CustomerStringsLocalizationTest extends TestCase {
    private const CATALOG_MSGIDS = [
        '20% Tournament Sibling Discount',
        '30% Multi-Child Tournament Discount',
        'Booking data is still saving. Please wait a moment, then try Add to cart again.',
        'Camp',
        'Condition',
        'Date',
        'Messages',
        'Total',
        'Type',
        'Select Days',
        'Late Pick Up Options',
        'Please select an attendee.',
        'Please resolve the error to continue.',
        'Request failed',
        'Please select at least one day.',
        'Select late pick up option (optional)',
        'No Late Pickup',
        'Single Days',
        'No late pick up selected',
        'Full Week (5 days): ',
        'Select at least one day',
        '%d day: ',
        '%d days: ',
        'Invalid selection',
        'Total Sessions',
        'Holidays (No Session)',
        'Start Date',
        'End Date',
        'Remaining Sessions',
        'Holidays',
    ];

    private const FR_EXPECTED = [
        'Select Days' => 'Sélectionner les jours',
        'Please select an attendee.' => 'Veuillez sélectionner un participant.',
        'Select late pick up option (optional)' => 'Sélectionnez une option de récupération tardive (facultatif)',
        '%d day: ' => '%d jour : ',
        '%d days: ' => '%d jours : ',
    ];

    private const DE_EXPECTED = [
        'Select Days' => 'Tage auswählen',
        'Late Pick Up Options' => 'Optionen für späte Abholung',
        'Please select an attendee.' => 'Bitte wählen Sie einen Teilnehmer aus.',
        '%d day: ' => '%d Tag: ',
        '%d days: ' => '%d Tage: ',
    ];

    /** @var array<string,array<string,string>> */
    private static array $moCache = [];

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

    /**
     * @return array<string,string>
     */
    private function readMo(string $locale): array {
        if (!isset(self::$moCache[$locale])) {
            self::$moCache[$locale] = $this->parseMoFile(dirname(__DIR__) . '/lang/intersoccer-product-variations-' . $locale . '.mo');
        }
        return self::$moCache[$locale];
    }

    /**
     * @return array<string,string>
     */
    private function parseMoFile(string $path): array {
        $this->assertFileExists($path);
        $data = file_get_contents($path);
        $this->assertNotFalse($data);
        $magic = unpack('V', substr($data, 0, 4));
        $le = $magic !== false && $magic[1] === 0x950412de;
        $unpack = $le ? 'V' : 'N';
        $count = unpack($unpack, substr($data, 8, 4))[1];
        $orig_off = unpack($unpack, substr($data, 12, 4))[1];
        $trans_off = unpack($unpack, substr($data, 16, 4))[1];
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $o = unpack($unpack . 'len/' . $unpack . 'off', substr($data, $orig_off + $i * 8, 8));
            $t = unpack($unpack . 'len/' . $unpack . 'off', substr($data, $trans_off + $i * 8, 8));
            $msgid = substr($data, $o['off'], $o['len']);
            $msgstr = substr($data, $t['off'], $t['len']);
            if ($msgid !== '') {
                $entries[$msgid] = $msgstr;
            }
        }
        return $entries;
    }

    private function bindMoLocale(string $locale): void {
        global $intersoccer_pv_test_textdomain_loaded, $intersoccer_pv_test_textdomain_strings;
        $intersoccer_pv_test_textdomain_loaded['intersoccer-product-variations'] = true;
        $intersoccer_pv_test_textdomain_strings['intersoccer-product-variations'] = $this->readMo($locale);
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    public static function localeMoProvider(): array {
        return [
            ['fr', 'fr_FR'],
            ['de', 'de_DE'],
        ];
    }

    /**
     * @dataProvider localeMoProvider
     */
    public function test_required_msgids_present_in_mo_files(string $short, string $locale): void {
        foreach ([$short, $locale] as $fileLocale) {
            $mo = $this->readMo($fileLocale);
            foreach (self::CATALOG_MSGIDS as $msgid) {
                $this->assertArrayHasKey($msgid, $mo, $fileLocale . ' MO missing: ' . $msgid);
                $this->assertNotSame('', $mo[$msgid], $fileLocale . ' empty translation for: ' . $msgid);
            }
        }
        $shortMo = $this->readMo($short);
        $localeMo = $this->readMo($locale);
        $this->assertSame($shortMo, $localeMo, $short . '.mo must match ' . $locale . '.mo');
    }

    public function test_late_pickup_and_course_helpers_localized_fr(): void {
        $this->bindMoLocale('fr_FR');
        $late = intersoccer_get_late_pickup_elementor_strings();
        $this->assertSame('Sélectionnez une option de récupération tardive (facultatif)', $late['selectOptional']);
        $this->assertSame('%d jour : ', $late['dayCountSingular']);
        $this->assertSame('%d jours : ', $late['dayCountPlural']);
        $course = intersoccer_get_course_display_strings();
        $this->assertSame('Date de début', $course['start_date']);
        $this->assertSame('Jours fériés (pas de séance)', $course['holidays_no_session']);
    }

    public function test_late_pickup_and_course_helpers_localized_de(): void {
        $this->bindMoLocale('de_DE');
        $late = intersoccer_get_late_pickup_elementor_strings();
        $this->assertSame('Keine Spätabholung', $late['noLatePickup']);
        $this->assertSame('%d Tag: ', $late['dayCountSingular']);
        $this->assertSame('%d Tage: ', $late['dayCountPlural']);
        $course = intersoccer_get_course_display_strings();
        $this->assertSame('Startdatum', $course['start_date']);
        $this->assertSame('Ferien (kein Training)', $course['holidays_no_session']);
    }

    public function test_player_assignment_strings_include_day_validation_fr_de(): void {
        global $intersoccer_test_myaccount_permalink;
        $intersoccer_test_myaccount_permalink = 'https://example.test/mon-compte/';
        MockFilters::$filters['wpml_current_language'] = static function () {
            return 'fr';
        };
        MockFilters::$filters['wpml_translate_single_string'] = static function ($slug) {
            return $slug === 'manage-players' ? 'gerer-les-participants' : $slug;
        };

        $this->bindMoLocale('fr_FR');
        $strings = intersoccer_get_player_assignment_strings();
        $this->assertSame('Veuillez sélectionner au moins un jour.', $strings['selectAtLeastOneDay']);
        $this->assertStringContainsString('gerer-les-participants/', $strings['noPlayersRegisteredHtml']);
        $this->assertStringContainsString('href="', $strings['noPlayersRegisteredHtml']);

        $this->bindMoLocale('de_DE');
        MockFilters::$filters['wpml_current_language'] = static function () {
            return 'de';
        };
        MockFilters::$filters['wpml_translate_single_string'] = static function ($slug) {
            return $slug === 'manage-players' ? 'teilnehmer-verwalten' : $slug;
        };
        $stringsDe = intersoccer_get_player_assignment_strings();
        $this->assertSame('Bitte wählen Sie mindestens einen Tag aus.', $stringsDe['selectAtLeastOneDay']);
        $this->assertStringContainsString('teilnehmer-verwalten/', $stringsDe['noPlayersRegisteredHtml']);
    }

    public function test_manage_players_urls_are_escaped_in_html_fragments(): void {
        global $intersoccer_test_myaccount_permalink;
        $intersoccer_test_myaccount_permalink = 'https://example.test/my-account/';
        MockFilters::$filters['wpml_translate_single_string'] = static function ($slug) {
            return $slug;
        };
        $strings = intersoccer_get_player_assignment_strings([
            'manage_players_url' => 'https://example.test/my-account/manage-players/',
        ]);
        $this->assertStringContainsString('href="https://example.test/my-account/manage-players/"', $strings['noPlayersRegisteredHtml']);
        $this->assertStringNotContainsString('href=""', $strings['noPlayersRegisteredHtml']);
    }
}
