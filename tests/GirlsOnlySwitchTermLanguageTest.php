<?php
/**
 * Repro (Tess QA PV#94 @0fdba8c): pa_girls-only "no" in every language must NOT mean girls-only.
 * Values below are the real pa_girls-only terms in the sandbox DB (WPML trid 414288 = no, 414287 = yes).
 *
 * Run: php vendor/bin/phpunit --no-configuration --do-not-cache-result tests/GirlsOnlySwitchTermLanguageTest.php
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { return true; }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { return true; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('__')) {
    function __($text, $domain = null) { return $text; }
}
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

class GirlsOnlySwitchTermLanguageTest extends TestCase {

    /** Real "no" terms (slug, name) as stored via WPML, plus slug-only / name-only forms. */
    public static function noValues(): array {
        return [
            'EN slug+name no/No'   => ['no', 'No'],
            'FR slug+name non/Non' => ['non', 'Non'],
            'DE slug+name nein/Nein' => ['nein', 'Nein'],
            'EN slug only'  => ['no', ''],
            'FR slug only'  => ['non', ''],
            'DE slug only'  => ['nein', ''],
            'EN name only'  => ['', 'No'],
            'FR name only'  => ['', 'Non'],
            'DE name only'  => ['', 'Nein'],
            'DE raw order-meta value (Girls Only => nein)' => ['nein', 'nein'],
            'DE uppercase'  => ['NEIN', 'NEIN'],
        ];
    }

    public static function yesValues(): array {
        return [
            'EN yes/Yes' => ['yes', 'Yes'],
            'FR oui/Oui' => ['oui', 'Oui'],
            'DE ja/Ja'   => ['ja', 'Ja'],
            "EN girls-only/Girl's Only" => ['girls-only', "Girl's Only"],
        ];
    }

    /** @dataProvider noValues */
    public function test_no_value_allows_boys($slug, $name) {
        $this->assertFalse(
            intersoccer_switch_term_indicates_girls_only_session($slug, $name),
            "pa_girls-only '{$slug}'/'{$name}' means NOT girls-only, boys must be allowed (girls-only-verification.php:72)"
        );
    }

    /** @dataProvider noValues */
    public function test_no_value_writes_no_girls_only_order_meta($slug, $name) {
        // order-meta-contract.php intersoccer_format_girls_only_meta_value() shares the same check.
        $this->assertSame('', (string) intersoccer_format_girls_only_meta_value($slug, $name),
            "'Girls Only' order meta must not be written for '{$slug}'/'{$name}'");
    }

    /**
     * Control: yes stays girls-only.
     * @dataProvider yesValues
     */
    public function test_yes_value_is_girls_only($slug, $name) {
        $this->assertTrue(intersoccer_switch_term_indicates_girls_only_session($slug, $name));
    }

    /** Related (bug b): PV canonical booking type has no FR/DE map. */
    public static function fullWeekValues(): array {
        return [
            'EN slug full-week' => ['full-week'],
            'EN label Full Week' => ['Full Week'],
            'FR slug semaine-complete' => ['semaine-complete'],
            'FR label Semaine complète' => ['Semaine complète'],
            'DE slug ganze-woche' => ['ganze-woche'],
            'DE label Ganze Woche' => ['Ganze Woche'],
        ];
    }

    /** @dataProvider fullWeekValues */
    public function test_canonical_booking_type_full_week($raw) {
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type($raw),
            "order-meta-contract.php intersoccer_normalize_canonical_booking_type('{$raw}')");
    }
}
