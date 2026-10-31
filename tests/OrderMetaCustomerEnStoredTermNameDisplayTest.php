<?php
/**
 * Roster-move lines may store EN attribute term names; display resolves to localized labels.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return !empty($GLOBALS['intersoccer_test_is_admin']);
    }
}

if (!function_exists('icl_object_id')) {
    function icl_object_id($element_id, $element_type = '', $return_original_if_missing = false, $language_code = null) {
        if (function_exists('apply_filters')) {
            return apply_filters('wpml_object_id', (int) $element_id, $element_type, $return_original_if_missing, $language_code);
        }

        return (int) $element_id;
    }
}

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/language-helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

class OrderMetaCustomerEnStoredTermNameDisplayTest extends TestCase {

    private const TERM_EN = 13801;
    private const TERM_FR = 13802;
    private const TERM_DE = 13803;

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'] = true;
        $GLOBALS['intersoccer_test_terms'] = [
            self::TERM_EN => [
                'taxonomy' => 'pa_age-group',
                'slug' => '5-13y-full-day',
                'name' => '5-13y (Full Day)',
                'wpml_lang' => 'en',
            ],
            self::TERM_FR => [
                'taxonomy' => 'pa_age-group',
                'slug' => '5-13y-full-day',
                'name' => '5-13 ans (journée complète)',
                'wpml_lang' => 'fr',
            ],
            self::TERM_DE => [
                'taxonomy' => 'pa_age-group',
                'slug' => '5-13y-full-day',
                'name' => '5-13 Jahre (Ganztag)',
                'wpml_lang' => 'de',
            ],
        ];
        MockFilters::reset();
        MockFilters::$filters['wpml_default_language'] = static function () {
            return 'en';
        };
        MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
            unset($type, $return_original);
            $id = (int) $id;
            $map = [
                'fr' => [self::TERM_EN => self::TERM_FR],
                'de' => [self::TERM_EN => self::TERM_DE],
            ];
            if ($lang !== null && isset($map[$lang][$id])) {
                return $map[$lang][$id];
            }

            return $id;
        };
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_is_admin'],
            $GLOBALS['intersoccer_test_terms'],
            $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'],
            $GLOBALS['intersoccer_test_wpml_current_language']
        );
        MockFilters::reset();
        parent::tearDown();
    }

    private function setLanguage(string $lang): void {
        $GLOBALS['intersoccer_test_wpml_current_language'] = $lang;
        MockFilters::$filters['wpml_current_language'] = static function () use ($lang) {
            return $lang;
        };
    }

    private function displayValue(string $stored, string $meta_key): string {
        $display = intersoccer_translate_stored_order_meta_display_value($stored, $meta_key);

        return is_scalar($display) ? (string) $display : '';
    }

    public function test_fr_shows_translated_label_when_en_term_name_stored(): void {
        $this->setLanguage('fr');
        $this->assertSame(
            '5-13 ans (journée complète)',
            $this->displayValue('5-13y (Full Day)', 'Age Group')
        );
    }

    public function test_de_shows_translated_label_when_en_term_name_stored(): void {
        $this->setLanguage('de');
        $this->assertSame(
            '5-13 Jahre (Ganztag)',
            $this->displayValue('5-13y (Full Day)', 'Age Group')
        );
    }

    public function test_en_display_unchanged_when_en_term_name_stored(): void {
        $this->setLanguage('en');
        $stored = '5-13y (Full Day)';
        $this->assertSame($stored, $this->displayValue($stored, 'Age Group'));
    }

    public function test_slug_stored_path_unchanged_in_french(): void {
        $this->setLanguage('fr');
        $this->assertSame(
            '5-13 ans (journée complète)',
            $this->displayValue('5-13y-full-day', 'Age Group')
        );
    }

    public function test_unknown_attribute_value_passes_through(): void {
        $this->setLanguage('fr');
        $unknown = 'Not A Real Term Label';
        $this->assertSame($unknown, $this->displayValue($unknown, 'Age Group'));
    }

    /**
     * @return array<string,array{0:string,1:string,2:string,3:string}>
     */
    public static function enNameVenueCityCantonCases(): array {
        return [
            'venue' => ['pa_intersoccer-venues', 'Sites InterSoccer', 'geneva-international-school', 'École internationale de Genève'],
            'city' => ['pa_city', 'City', 'geneva', 'Genève'],
            'canton' => ['pa_canton-region', 'Canton / Region', 'geneva', 'Genève'],
        ];
    }

    #[DataProvider('enNameVenueCityCantonCases')]
    public function test_fr_resolves_en_stored_name_for_venue_city_canton(
        string $taxonomy,
        string $meta_key,
        string $slug,
        string $fr_name
    ): void {
        $en_name = 'Fixture EN ' . $taxonomy;
        $en_id = 13810 + crc32($taxonomy) % 100;
        $fr_id = $en_id + 1;
        $GLOBALS['intersoccer_test_terms'][$en_id] = [
            'taxonomy' => $taxonomy,
            'slug' => $slug,
            'name' => $en_name,
            'wpml_lang' => 'en',
        ];
        $GLOBALS['intersoccer_test_terms'][$fr_id] = [
            'taxonomy' => $taxonomy,
            'slug' => $slug,
            'name' => $fr_name,
            'wpml_lang' => 'fr',
        ];
        $prior = MockFilters::$filters['wpml_object_id'] ?? null;
        MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) use ($en_id, $fr_id, $prior) {
            if ($lang === 'fr' && (int) $id === $en_id) {
                return $fr_id;
            }
            if (is_callable($prior)) {
                return $prior($id, $type, $return_original, $lang);
            }

            return $id;
        };
        $this->setLanguage('fr');
        $this->assertSame($fr_name, $this->displayValue($en_name, $meta_key));
    }
}
