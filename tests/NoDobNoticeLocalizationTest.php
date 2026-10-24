<?php
/**
 * FR/DE no-DOB add-to-cart notice: gettext strings, MO files, localized Manage Players link.
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
    define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

const INTERSOCCER_PV_NO_DOB_MSGID = 'The selected attendee needs a valid date of birth. Update it under Manage Players: %s';

const INTERSOCCER_PV_NO_DOB_MSGSTR_FR = 'Le participant sélectionné doit avoir une date de naissance valide. Mettez-la à jour dans Gérer les participants : %s';

const INTERSOCCER_PV_NO_DOB_MSGSTR_DE = 'Für den ausgewählten Teilnehmer ist ein gültiges Geburtsdatum erforderlich. Bitte aktualisieren Sie es unter „Teilnehmer verwalten“: %s';

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

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

class NoDobNoticeLocalizationTest extends TestCase {
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
        global $intersoccer_test_myaccount_permalink, $intersoccer_pv_test_textdomain_loaded, $intersoccer_pv_test_textdomain_strings;
        $intersoccer_test_myaccount_permalink = '';
        $intersoccer_pv_test_textdomain_loaded = [];
        $intersoccer_pv_test_textdomain_strings = [];
        parent::tearDown();
    }

    private function bindTextdomainFromMo(string $domain, string $moPath): void {
        global $intersoccer_pv_test_textdomain_loaded, $intersoccer_pv_test_textdomain_strings;
        $intersoccer_pv_test_textdomain_loaded[$domain] = true;
        $intersoccer_pv_test_textdomain_strings[$domain] = $this->parseMoFile($moPath);
    }

    /**
     * @return array<string,string>
     */
    private function parseMoFile(string $path): array {
        $this->assertFileExists($path);
        $data = file_get_contents($path);
        $this->assertNotFalse($data);
        $len = strlen($data);
        if ($len < 28) {
            $this->fail('Invalid MO file: ' . $path);
        }
        $magic = unpack('V', substr($data, 0, 4));
        if ($magic === false) {
            $this->fail('Invalid MO magic: ' . $path);
        }
        $le = $magic[1] === 0x950412de;
        $be = $magic[1] === 0xde120495;
        if (!$le && !$be) {
            $this->fail('Unsupported MO magic: ' . $path);
        }
        $fmt = $le ? 'Vrevision/Vcount/Voffset_orig/Voffset_trans/Vhash_size/Vhash_offset' : 'Nrevision/Ncount/Noffset_orig/Noffset_trans/Nhash_size/Nhash_offset';
        $unpack = unpack($fmt, substr($data, 4, 24));
        if ($unpack === false || (int) $unpack['revision'] !== 0) {
            $this->fail('Unsupported MO revision: ' . $path);
        }
        $count = (int) $unpack['count'];
        $offset_orig = (int) $unpack['offset_orig'];
        $offset_trans = (int) $unpack['offset_trans'];
        $len_fmt = $le ? 'V' : 'N';
        $table_len = 8 * $count;
        $orig_table = substr($data, $offset_orig, $table_len);
        $trans_table = substr($data, $offset_trans, $table_len);
        $strings = [];
        for ($i = 0; $i < $count; $i++) {
            $o = unpack($len_fmt . 'length/' . $len_fmt . 'offset', substr($orig_table, $i * 8, 8));
            $t = unpack($len_fmt . 'length/' . $len_fmt . 'offset', substr($trans_table, $i * 8, 8));
            if ($o === false || $t === false) {
                continue;
            }
            $msgid = substr($data, $o['offset'], $o['length']);
            $msgstr = substr($data, $t['offset'], $t['length']);
            if ($msgid !== '') {
                $strings[$msgid] = $msgstr;
            }
        }
        return $strings;
    }

    private function expectLocalizedMessage(string $lang, string $slug, string $template, string $accountBase, ?string $moFile = null): void {
        global $intersoccer_test_myaccount_permalink;
        if ($moFile !== null) {
            $this->bindTextdomainFromMo('intersoccer-product-variations', dirname(__DIR__) . '/lang/' . $moFile);
        }
        $intersoccer_test_myaccount_permalink = $accountBase;

        MockFilters::$filters['wpml_current_language'] = static function () use ($lang) {
            return $lang;
        };
        MockFilters::$filters['wpml_translate_single_string'] = static function ($slug_in) {
            return $slug_in;
        };

        $url = intersoccer_pv_manage_players_url();
        $expected_url = trailingslashit($accountBase) . $slug . '/';
        $this->assertSame($expected_url, $url);

        $message = intersoccer_pv_age_no_dob_error_message();
        $link = '<a href="' . esc_url($url) . '">' . esc_html($url) . '</a>';
        $expected = sprintf(esc_html($template), $link);
        $this->assertSame($expected, $message);
        $this->assertStringContainsString('<a href="', $message);
        $this->assertStringContainsString($expected_url, $message);
    }

    public function testFrenchNoticeUsesLocalizedSlugAndTranslation(): void {
        $this->expectLocalizedMessage(
            'fr',
            'gerer-participants',
            INTERSOCCER_PV_NO_DOB_MSGSTR_FR,
            'https://example.test/fr/mon-compte/',
            'intersoccer-product-variations-fr.mo'
        );
    }

    public function testGermanNoticeUsesLocalizedSlugAndTranslation(): void {
        $this->expectLocalizedMessage(
            'de',
            'teilnehmer-verwalten',
            INTERSOCCER_PV_NO_DOB_MSGSTR_DE,
            'https://example.test/de/mein-konto/',
            'intersoccer-product-variations-de.mo'
        );
    }

    public function testEnglishNoticeUsesManagePlayersSlug(): void {
        $this->expectLocalizedMessage(
            'en',
            'manage-players',
            INTERSOCCER_PV_NO_DOB_MSGID,
            'https://example.test/my-account/'
        );
    }

    public function testWpmlEnSlugFallsBackWhenLanguageIsFrench(): void {
        global $intersoccer_test_myaccount_permalink;
        $this->bindTextdomainFromMo('intersoccer-product-variations', dirname(__DIR__) . '/lang/intersoccer-product-variations-fr.mo');
        $intersoccer_test_myaccount_permalink = 'https://example.test/fr/mon-compte/';

        MockFilters::$filters['wpml_current_language'] = static function () {
            return 'fr';
        };
        MockFilters::$filters['wpml_translate_single_string'] = static function ($slug) {
            return $slug;
        };

        $this->assertSame('gerer-participants', intersoccer_pv_manage_players_endpoint_slug());
        $url = intersoccer_pv_manage_players_url();
        $this->assertSame('https://example.test/fr/mon-compte/gerer-participants/', $url);
    }

    public function testWpmlEnSlugFallsBackWhenLanguageIsGerman(): void {
        MockFilters::$filters['wpml_current_language'] = static function () {
            return 'de';
        };
        MockFilters::$filters['wpml_translate_single_string'] = static function ($slug) {
            return 'manage-players';
        };

        $this->assertSame('teilnehmer-verwalten', intersoccer_pv_manage_players_endpoint_slug());
    }

    public function testPoFilesContainExactNoDobPairs(): void {
        $lang_dir = dirname(__DIR__) . '/lang/';
        $fr = file_get_contents($lang_dir . 'intersoccer-product-variations-fr.po');
        $de = file_get_contents($lang_dir . 'intersoccer-product-variations-de.po');
        $this->assertNotFalse($fr);
        $this->assertNotFalse($de);
        $this->assertStringContainsString('msgid "' . INTERSOCCER_PV_NO_DOB_MSGID . '"', $fr);
        $this->assertStringContainsString('msgstr "' . INTERSOCCER_PV_NO_DOB_MSGSTR_FR . '"', $fr);
        $this->assertStringContainsString('msgid "' . INTERSOCCER_PV_NO_DOB_MSGID . '"', $de);
        $this->assertStringContainsString('msgstr "' . INTERSOCCER_PV_NO_DOB_MSGSTR_DE . '"', $de);
    }

    public function testMoFilesContainNoDobTranslation(): void {
        $lang_dir = dirname(__DIR__) . '/lang/';
        $files = [
            'intersoccer-product-variations-fr.mo',
            'intersoccer-product-variations-fr_FR.mo',
            'intersoccer-product-variations-de.mo',
            'intersoccer-product-variations-de_DE.mo',
        ];
        foreach ($files as $file) {
            $strings = $this->parseMoFile($lang_dir . $file);
            $this->assertArrayHasKey(INTERSOCCER_PV_NO_DOB_MSGID, $strings, $file);
            if (strpos($file, '-fr') !== false) {
                $this->assertSame(INTERSOCCER_PV_NO_DOB_MSGSTR_FR, $strings[INTERSOCCER_PV_NO_DOB_MSGID], $file);
            } else {
                $this->assertSame(INTERSOCCER_PV_NO_DOB_MSGSTR_DE, $strings[INTERSOCCER_PV_NO_DOB_MSGID], $file);
            }
        }
    }

    public function testDangerousCharactersEscapedInLink(): void {
        global $intersoccer_test_myaccount_permalink;
        $intersoccer_test_myaccount_permalink = 'https://example.test/my-account/" onclick="alert(1)/';

        MockFilters::$filters['wpml_current_language'] = static function () {
            return 'en';
        };
        MockFilters::$filters['wpml_translate_single_string'] = static function ($slug) {
            return $slug;
        };

        $url = intersoccer_pv_manage_players_url();
        $message = intersoccer_pv_age_no_dob_error_message();
        $this->assertStringContainsString('href="' . esc_url($url) . '"', $message);
        $this->assertStringNotContainsString('" onclick', $message);
        $this->assertStringContainsString(esc_html($url), $message);
    }
}
