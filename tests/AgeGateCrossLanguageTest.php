<?php
/**
 * FR/DE/EN age gate parity: bounds from slug, half-day slug detection, reference date, translated errors.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule.php';
require_once dirname(__DIR__) . '/includes/woocommerce/age-group-verification.php';

if (!defined('ICL_SITEPRESS_VERSION')) {
    define('ICL_SITEPRESS_VERSION', 'test');
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

class AgeGateCrossLanguageTest extends TestCase
{
    private const PARENT_ID = 910001;

    private const TERM_FULL_EN = 920001;
    private const TERM_FULL_FR = 920002;
    private const TERM_FULL_DE = 920003;

    private const TERM_HALF_EN = 920011;
    private const TERM_HALF_FR = 920012;
    private const TERM_HALF_DE = 920013;

    private const VAR_FULL_EN = 930001;
    private const VAR_FULL_FR = 930002;
    private const VAR_FULL_DE = 930003;

    private const VAR_HALF_EN = 930011;
    private const VAR_HALF_FR = 930012;
    private const VAR_HALF_DE = 930013;

    private const REF_DATE = '2026-07-06';

    protected function setUp(): void
    {
        parent::setUp();
        MockMetaData::$data = [];
        MockFilters::reset();

        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_user_id'] = 77;
        $GLOBALS['intersoccer_test_options'] = [];

        $GLOBALS['intersoccer_test_terms'] = [
            self::TERM_FULL_EN => [
                'name' => '3-5y',
                'slug' => '3-5y',
                'taxonomy' => 'pa_age-group',
            ],
            self::TERM_FULL_FR => [
                'name' => '3-5 ans',
                'slug' => '3-5-ans',
                'taxonomy' => 'pa_age-group',
            ],
            self::TERM_FULL_DE => [
                'name' => '3-5 Jahre',
                'slug' => '3-5-jahre',
                'taxonomy' => 'pa_age-group',
            ],
            self::TERM_HALF_EN => [
                'name' => '3-5y (Half-Day)',
                'slug' => '3-5y-half-day',
                'taxonomy' => 'pa_age-group',
            ],
            self::TERM_HALF_FR => [
                'name' => '3-5 ans (Demi-journée)',
                'slug' => '3-5-ans-demi-journee',
                'taxonomy' => 'pa_age-group',
            ],
            self::TERM_HALF_DE => [
                'name' => '3-5 Jahre (halber Tag)',
                'slug' => '3-5-jahre-halber-tag',
                'taxonomy' => 'pa_age-group',
            ],
        ];

        $slug_map = [
            self::VAR_FULL_EN => '3-5y',
            self::VAR_FULL_FR => '3-5-ans',
            self::VAR_FULL_DE => '3-5-jahre',
            self::VAR_HALF_EN => '3-5y-half-day',
            self::VAR_HALF_FR => '3-5-ans-demi-journee',
            self::VAR_HALF_DE => '3-5-jahre-halber-tag',
        ];
        foreach ($slug_map as $vid => $slug) {
            MockMetaData::$data[$vid]['attribute_pa_age-group'] = $slug;
            MockMetaData::$data[$vid]['_camp_start_date'] = self::REF_DATE;
            MockMetaData::$data[$vid]['_camp_end_date'] = '2026-07-10';
        }

        MockFilters::$filters['wpml_default_language'] = static function () {
            return 'en';
        };
        MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
            $term_map = [
                self::TERM_FULL_FR => self::TERM_FULL_EN,
                self::TERM_FULL_DE => self::TERM_FULL_EN,
                self::TERM_HALF_FR => self::TERM_HALF_EN,
                self::TERM_HALF_DE => self::TERM_HALF_EN,
            ];
            $variation_map = [
                self::VAR_FULL_FR => self::VAR_FULL_EN,
                self::VAR_FULL_DE => self::VAR_FULL_EN,
                self::VAR_HALF_FR => self::VAR_HALF_EN,
                self::VAR_HALF_DE => self::VAR_HALF_EN,
            ];
            if ($lang === 'en') {
                if ($type === 'product_variation' && isset($variation_map[(int) $id])) {
                    return $variation_map[(int) $id];
                }
                if (isset($term_map[(int) $id])) {
                    return $term_map[(int) $id];
                }
            }
            return $id;
        };
    }

    protected function tearDown(): void
    {
        MockMetaData::$data = [];
        MockFilters::reset();
        unset(
            $GLOBALS['intersoccer_test_product_type'],
            $GLOBALS['intersoccer_test_user_id'],
            $GLOBALS['intersoccer_test_options'],
            $GLOBALS['intersoccer_test_terms'],
            $GLOBALS['intersoccer_test_product_terms'],
            $GLOBALS['intersoccer_test_player_details'],
            $GLOBALS['intersoccer_pv_test_determine_locale'],
            $GLOBALS['intersoccer_pv_test_textdomain_strings']
        );
        parent::tearDown();
    }

    private function setPlayerDob(string $dob): void
    {
        $GLOBALS['intersoccer_test_player_details'] = [
            'name' => 'Test Child',
            'dob' => $dob,
            'gender' => 'Male',
            'medical_conditions' => '',
            'player_id' => 'uuid-age-gate',
        ];
    }

    public static function fullDayVariationIds(): array
    {
        return [
            'en' => [self::VAR_FULL_EN],
            'fr' => [self::VAR_FULL_FR],
            'de' => [self::VAR_FULL_DE],
        ];
    }

    public static function halfDayVariationIds(): array
    {
        return [
            'en' => [self::VAR_HALF_EN],
            'fr' => [self::VAR_HALF_FR],
            'de' => [self::VAR_HALF_DE],
        ];
    }

    #[DataProvider('fullDayVariationIds')]
    public function test_full_day_3_5_rejects_ages_8_and_10(int $variation_id): void
    {
        foreach (['2018-07-05', '2016-07-05'] as $dob) {
            $this->setPlayerDob($dob);
            $result = intersoccer_validate_line_player_age(77, 0, self::PARENT_ID, $variation_id);
            $this->assertInstanceOf(WP_Error::class, $result, 'DOB ' . $dob);
            $this->assertSame('intersoccer_age_out_of_range', $result->get_error_code());
        }
    }

    #[DataProvider('fullDayVariationIds')]
    public function test_full_day_3_5_accepts_age_4(int $variation_id): void
    {
        $this->setPlayerDob('2022-07-06');
        $result = intersoccer_validate_line_player_age(77, 0, self::PARENT_ID, $variation_id);
        $this->assertTrue($result);
    }

    #[DataProvider('halfDayVariationIds')]
    public function test_half_day_default_grace_accepts_ages_8_and_10(int $variation_id): void
    {
        foreach (['2018-07-05', '2016-07-05'] as $dob) {
            $this->setPlayerDob($dob);
            $result = intersoccer_validate_line_player_age(77, 0, self::PARENT_ID, $variation_id);
            $this->assertTrue($result, 'Half-day with default 96-month grace should accept DOB ' . $dob);
        }
    }

    #[DataProvider('halfDayVariationIds')]
    public function test_half_day_zero_grace_rejects_8_and_10_accepts_4(int $variation_id): void
    {
        $GLOBALS['intersoccer_test_options']['intersoccer_age_restriction_settings'] = [
            'grace_enabled' => true,
            'below_min_months' => 2,
            'above_max_months' => 1,
            'half_day_above_max_months' => 0,
            'strict_missing_reference_date' => false,
        ];
        foreach (['2018-07-05', '2016-07-05'] as $dob) {
            $this->setPlayerDob($dob);
            $result = intersoccer_validate_line_player_age(77, 0, self::PARENT_ID, $variation_id);
            $this->assertInstanceOf(WP_Error::class, $result);
        }
        $this->setPlayerDob('2022-07-06');
        $this->assertTrue(intersoccer_validate_line_player_age(77, 0, self::PARENT_ID, $variation_id));
    }

    public function test_fr_out_of_range_error_uses_french_catalog(): void
    {
        global $intersoccer_pv_test_determine_locale, $intersoccer_pv_test_textdomain_strings;
        $intersoccer_pv_test_determine_locale = 'fr_FR';
        $msgid = 'This attendee is %1$d years old on the program start date, which is outside the allowed age range (%2$s) for this session.';
        $intersoccer_pv_test_textdomain_strings = [
            'intersoccer-product-variations' => [
                $msgid => 'Ce participant a %1$d ans à la date de début du programme, ce qui est hors de la tranche d’âge autorisée (%2$s) pour cette session.',
            ],
        ];
        $GLOBALS['intersoccer_pv_test_textdomain_loaded'] = ['intersoccer-product-variations' => true];

        $this->setPlayerDob('2018-07-05');
        $result = intersoccer_validate_line_player_age(77, 0, self::PARENT_ID, self::VAR_FULL_FR);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertStringContainsString('Ce participant a 8 ans', $result->get_error_message());
        $this->assertStringContainsString('3-5 ans', $result->get_error_message());
    }

    public function test_missing_reference_date_strict_blocks_fr_line(): void
    {
        foreach ([self::VAR_FULL_EN, self::VAR_FULL_FR, self::VAR_FULL_DE] as $vid) {
            unset(MockMetaData::$data[$vid]['_camp_start_date'], MockMetaData::$data[$vid]['_camp_end_date']);
        }
        MockFilters::$filters['intersoccer_age_group_strict_missing_reference_date'] = static function () {
            return true;
        };
        $this->setPlayerDob('2022-07-06');
        $result = intersoccer_validate_line_player_age(77, 0, self::PARENT_ID, self::VAR_FULL_FR);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('intersoccer_age_no_reference_date', $result->get_error_code());
    }

    /**
     * At 9254e02: empty FR variation pa_age-group + ambiguous parent terms skipped validation (return true).
     * Fix: resolve slug from default-language WPML variation before line_has / bounds.
     */
    public function test_fr_full_day_without_local_age_slug_resolves_wpml_default_and_rejects_age_8(): void
    {
        unset(MockMetaData::$data[self::VAR_FULL_FR]['attribute_pa_age-group']);
        $GLOBALS['intersoccer_test_product_terms'][self::PARENT_ID] = [
            'pa_age-group' => ['3-5 ans', '6-8 ans'],
        ];

        $this->setPlayerDob('2018-07-05');
        $result = intersoccer_validate_line_player_age(77, 0, self::PARENT_ID, self::VAR_FULL_FR);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('intersoccer_age_out_of_range', $result->get_error_code());
    }
}
