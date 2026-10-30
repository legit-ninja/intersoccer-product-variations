<?php
/**
 * Customer order-meta labels must match committed FR/DE catalogs (not test-injected gettext).
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
    define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!function_exists('intersoccer_pv_resolve_textdomain_mo_path')) {
    require_once dirname(__DIR__) . '/includes/pv-textdomain.php';
}

if (!function_exists('plugin_basename')) {
    function plugin_basename($file) {
        return 'intersoccer-product-variations/intersoccer-product-variations.php';
    }
}

if (!function_exists('translate')) {
    function translate($text, $domain = 'default')
    {
        global $intersoccer_pv_test_textdomain_strings;
        if (is_array($intersoccer_pv_test_textdomain_strings) && isset($intersoccer_pv_test_textdomain_strings[$domain][$text])) {
            return $intersoccer_pv_test_textdomain_strings[$domain][$text];
        }
        return $text;
    }
}

class OrderMetaCustomerLabelCatalogTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['intersoccer_pv_test_textdomain_loaded'],
            $GLOBALS['intersoccer_pv_test_textdomain_strings'],
            $GLOBALS['intersoccer_test_is_admin']
        );
        global $intersoccer_pv_test_determine_locale;
        $intersoccer_pv_test_determine_locale = null;
        parent::tearDown();
    }

    /**
     * @return array<string,string> msgid => msgstr
     */
    private function readMoEntries(string $path): array
    {
        $this->assertFileExists($path);
        $data = file_get_contents($path);
        $this->assertIsString($data);
        $magic = unpack('V', substr($data, 0, 4))[1];
        $unpack = $magic === 0xde120495 ? 'N' : 'V';
        $count = unpack($unpack, substr($data, 8, 4))[1];
        $orig_off = unpack($unpack, substr($data, 12, 4))[1];
        $trans_off = unpack($unpack, substr($data, 16, 4))[1];
        $read_string = static function (int $offset) use ($data, $unpack): string {
            $len = unpack($unpack, substr($data, $offset, 4))[1];
            $str_off = unpack($unpack, substr($data, $offset + 4, 4))[1];
            return substr($data, $str_off, $len);
        };
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $orig = $read_string($orig_off + $i * 8);
            $trans = $read_string($trans_off + $i * 8);
            if ($orig !== '' && $trans !== '') {
                $entries[$orig] = $trans;
            }
        }
        return $entries;
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function tessOrderMetaLabels(): array
    {
        $labels = [
            'Late Pickup Days',
            'Late Pickup Cost',
            'Activity Type',
            'Attendee DOB',
            'Attendee Gender',
            'Camp Start Date',
            'Camp End Date',
            'Camp Times',
            'Canton/Region',
            'Canton / Region',
            'City',
            'Booking Type',
            'Discount Amount',
            'Booking details',
            'Assigned Attendee',
        ];
        $cases = [];
        foreach ($labels as $msgid) {
            $cases['fr ' . $msgid] = ['fr', $msgid];
            $cases['de ' . $msgid] = ['de', $msgid];
        }
        return $cases;
    }

    #[DataProvider('tessOrderMetaLabels')]
    public function test_customer_display_key_and_formatted_meta_use_catalog_translation(string $locale, string $msgid): void
    {
        $mo_file = dirname(__DIR__) . '/lang/intersoccer-product-variations-' . $locale . '.mo';
        $catalog = $this->readMoEntries($mo_file);
        $canonical = intersoccer_pv_canonical_stored_order_meta_key($msgid);
        $catalog_msgid = ($canonical !== '' && isset($catalog[$canonical])) ? $canonical : $msgid;
        $this->assertArrayHasKey(
            $catalog_msgid,
            $catalog,
            "Missing committed $locale .mo translation for msgid: $catalog_msgid (stored key: $msgid)"
        );
        $expected = $catalog[$catalog_msgid];

        global $intersoccer_pv_test_determine_locale;
        $intersoccer_pv_test_determine_locale = $locale === 'fr' ? 'fr_FR' : 'de_DE';
        $GLOBALS['intersoccer_pv_test_textdomain_loaded'] = ['intersoccer-product-variations' => true];
        $GLOBALS['intersoccer_pv_test_textdomain_strings'] = [
            'intersoccer-product-variations' => $catalog,
        ];
        $GLOBALS['intersoccer_test_is_admin'] = false;

        $display_key = intersoccer_translate_stored_order_meta_display_key($msgid, $msgid);
        $this->assertSame($expected, $display_key, 'Display-key filter');

        $meta = (object) ['key' => $msgid, 'display_key' => $msgid, 'value' => 'sample'];
        $formatted = intersoccer_translate_order_item_formatted_meta_object($meta);
        $this->assertSame($expected, $formatted->display_key, 'Formatted-meta filter');
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function assignedAttendeeLocaleCases(): array
    {
        return [
            'de_DE' => ['de_DE', 'Zugewiesener Teilnehmer'],
            'de_CH' => ['de_CH', 'Zugewiesener Teilnehmer'],
            'fr_FR' => ['fr_FR', 'Participant assigné'],
            'fr_CH' => ['fr_CH', 'Participant assigné'],
        ];
    }

    #[DataProvider('assignedAttendeeLocaleCases')]
    public function test_assigned_attendee_resolves_from_committed_mo_for_swiss_and_standard_locales(
        string $locale,
        string $expected
    ): void {
        $mo_path = intersoccer_pv_resolve_textdomain_mo_path($locale);
        $this->assertNotSame('', $mo_path, "No .mo resolved for locale $locale");
        $catalog = $this->readMoEntries($mo_path);
        $this->assertArrayHasKey('Assigned Attendee', $catalog);

        global $intersoccer_pv_test_determine_locale;
        $intersoccer_pv_test_determine_locale = $locale;
        $GLOBALS['intersoccer_pv_test_textdomain_loaded'] = ['intersoccer-product-variations' => true];
        $GLOBALS['intersoccer_pv_test_textdomain_strings'] = [
            'intersoccer-product-variations' => $catalog,
        ];
        $GLOBALS['intersoccer_test_is_admin'] = false;

        $translated = intersoccer_pv_translate_textdomain_msgid_for_customer('Assigned Attendee');
        $this->assertSame($expected, $translated);
        $display_key = intersoccer_translate_stored_order_meta_display_key('Assigned Attendee', 'Assigned Attendee');
        $this->assertSame($expected, $display_key);
    }

    public function test_admin_context_keeps_english_order_meta_labels(): void
    {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        unset($GLOBALS['intersoccer_test_doing_ajax']);
        global $intersoccer_pv_test_determine_locale;
        $intersoccer_pv_test_determine_locale = 'fr_FR';

        $label = intersoccer_translate_stored_order_meta_display_key('Activity Type', 'Activity Type');
        $this->assertSame('Activity Type', $label);
    }
}
