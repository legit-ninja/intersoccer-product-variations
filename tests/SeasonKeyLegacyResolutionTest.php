<?php
/**
 * Legacy season keys (label|year) must normalize to ps:{termId}|year across WPML request languages.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';
if (!function_exists('intersoccer_translate_string')) {
    require_once dirname(__DIR__) . '/includes/language-helpers.php';
}

if (!function_exists('intersoccer_get_discount_message')) {
    function intersoccer_get_discount_message($key, $context = 'cart_message', $fallback = '') {
        return $fallback;
    }
}

class SeasonKeyLegacyResolutionTest extends TestCase
{
    private const SEASON_TERM_EN = 88001;
    private const SEASON_TERM_FR = 88002;
    private const SEASON_TERM_DE = 88003;
    private const SEASON_TERM_SUMMER = 88004;

    private const BASE_PRICE = 525.0;
    private const SIBLING_RATE = 0.20;
    private const CHILD_UUID = 'uuid-legacy-season-child';

    /** @var array<string,array{0:int,1:int,2:string,3:string,4:string}> */
    private const PROGRAMS = [
        'en' => [51723, 51724, 'Autumn Camps 2026', '2026', 'full-week'],
        'fr' => [51732, 51758, "Camps d'automne 2026", '2026', 'semaine-complete'],
        'de' => [54216, 54231, 'Herbst Camps 2026', '2026', 'ganze-woche'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_options'] = [
            'intersoccer_enable_retroactive_sibling_discounts' => true,
            'intersoccer_enable_retroactive_camp_discounts' => true,
        ];
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'] = true;
        $GLOBALS['intersoccer_test_user_id'] = 501;

        $GLOBALS['intersoccer_test_terms'] = [
            self::SEASON_TERM_EN => [
                'name' => 'Autumn Camps 2026',
                'slug' => 'autumn-camps-2026',
                'taxonomy' => 'pa_program-season',
                'wpml_lang' => 'en',
            ],
            self::SEASON_TERM_FR => [
                'name' => "Camps d'automne 2026",
                'slug' => 'camps-dautomne-2026',
                'taxonomy' => 'pa_program-season',
                'wpml_lang' => 'fr',
            ],
            self::SEASON_TERM_DE => [
                'name' => 'Herbst Camps 2026',
                'slug' => 'herbst-camps-2026',
                'taxonomy' => 'pa_program-season',
                'wpml_lang' => 'de',
            ],
            self::SEASON_TERM_SUMMER => [
                'name' => 'Summer Camps 2026',
                'slug' => 'summer-camps-2026',
                'taxonomy' => 'pa_program-season',
                'wpml_lang' => 'en',
            ],
        ];

        MockFilters::reset();
        MockFilters::$filters['wpml_default_language'] = static function () {
            return 'en';
        };
        MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
            $map = [
                self::SEASON_TERM_FR => self::SEASON_TERM_EN,
                self::SEASON_TERM_DE => self::SEASON_TERM_EN,
            ];
            if ($lang === 'en' && isset($map[(int) $id])) {
                return $map[(int) $id];
            }
            return $id;
        };

        $terms = [];
        $term_ids = [];
        $parents = [];
        $season_term_by_lang = [
            'en' => self::SEASON_TERM_EN,
            'fr' => self::SEASON_TERM_FR,
            'de' => self::SEASON_TERM_DE,
        ];
        foreach (self::PROGRAMS as $lang => [$parent, $variation, $season, $year, $booking]) {
            $terms[$parent] = ['pa_program-season' => [$season], 'pa_program-year' => [$year]];
            $term_ids[$parent] = ['pa_program-season' => [$season_term_by_lang[$lang]]];
            $parents[$variation] = $parent;
            MockMetaData::$data[$variation]['attribute_pa_booking-type'] = $booking;
        }
        $GLOBALS['intersoccer_test_product_terms'] = $terms;
        $GLOBALS['intersoccer_test_product_term_ids'] = $term_ids;
        $GLOBALS['intersoccer_wc_get_product_callback'] = static function ($id) use ($parents) {
            $id = (int) $id;
            $parent = $parents[$id] ?? 0;
            return new class($id, $parent) {
                private $id;
                private $parent;
                public function __construct($id, $parent)
                {
                    $this->id = $id;
                    $this->parent = $parent;
                }
                public function get_id()
                {
                    return $this->id;
                }
                public function get_parent_id()
                {
                    return $this->parent;
                }
                public function get_price()
                {
                    return 525.0;
                }
                public function get_attributes()
                {
                    return [];
                }
                public function get_attribute($n)
                {
                    return '';
                }
                public function is_type($t)
                {
                    return false;
                }
                public function get_name()
                {
                    return 'Geneva Autumn Camps 2026';
                }
            };
        };
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['intersoccer_test_product_type'],
            $GLOBALS['intersoccer_test_product_terms'],
            $GLOBALS['intersoccer_test_product_term_ids'],
            $GLOBALS['intersoccer_test_terms'],
            $GLOBALS['intersoccer_wc_get_product_callback'],
            $GLOBALS['intersoccer_test_options'],
            $GLOBALS['intersoccer_test_is_admin'],
            $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'],
            $GLOBALS['intersoccer_test_wpml_current_language'],
            $GLOBALS['intersoccer_test_wc_get_orders'],
            $GLOBALS['intersoccer_test_orders'],
            $GLOBALS['intersoccer_test_user_id']
        );
        MockMetaData::$data = [];
        MockFilters::reset();
        parent::tearDown();
    }

    public static function legacySeasonKeys(): array
    {
        return [
            'fr label' => ["camps d'automne 2026|2026"],
            'de label' => ['herbst camps 2026|2026'],
            'en label' => ['autumn camps 2026|2026'],
        ];
    }

    #[DataProvider('legacySeasonKeys')]
    public function test_legacy_season_key_normalizes_to_canonical_ps_key_in_every_request_language(string $legacy_key): void
    {
        $expected = 'ps:' . self::SEASON_TERM_EN . '|2026';
        foreach (['en', 'fr', 'de'] as $lang) {
            $GLOBALS['intersoccer_test_wpml_current_language'] = $lang;
            $normalized = intersoccer_discount_normalize_season_key($legacy_key);
            $this->assertSame($expected, $normalized, "Legacy key must normalize under request language $lang");
        }
    }

    public function test_unresolved_legacy_label_keeps_label_based_key(): void
    {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'de';
        $normalized = intersoccer_discount_normalize_season_key('unknown season label|2027');
        $this->assertSame('unknown season label|2027', $normalized);
    }

    public function test_different_season_or_year_does_not_share_bucket(): void
    {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'en';
        $autumn = intersoccer_discount_normalize_season_key('autumn camps 2026|2026');
        $summer = intersoccer_discount_normalize_season_key('summer camps 2026|2026');
        $autumn_2027 = intersoccer_discount_normalize_season_key('autumn camps 2026|2027');
        $this->assertNotSame($autumn, $summer);
        $this->assertNotSame($autumn, $autumn_2027);
    }

    public function test_legacy_prior_order_and_cross_language_cart_get_sibling_discount(): void
    {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'de';
        $prior_key = "camps d'automne 2026|2026";
        $GLOBALS['intersoccer_test_orders'] = [
            9001 => $this->makePriorOrder('fr', $prior_key, 0),
        ];
        $GLOBALS['intersoccer_test_wc_get_orders'] = static function () {
            return array_values($GLOBALS['intersoccer_test_orders']);
        };

        $cart = $this->makeSiblingCart('de', 'en');
        intersoccer_apply_combo_discounts_to_items($cart);

        $discounted = 0;
        foreach ($cart->cart_contents as $line) {
            if (abs((float) ($line['intersoccer_sibling_percent'] ?? 0) - self::SIBLING_RATE) < 1e-9) {
                $discounted++;
            }
        }
        $this->assertSame(1, $discounted, 'Second child must receive sibling discount with legacy FR season key on prior order');
    }

    private function makePriorOrder(string $lang, string $legacy_season_key, int $child_index)
    {
        [$parent, $variation] = [self::PROGRAMS[$lang][0], self::PROGRAMS[$lang][1]];
        $child_uuid = self::CHILD_UUID;
        $item = new class($legacy_season_key, $child_index, $child_uuid) {
            private $season_key;
            private $child_index;
            private $child_uuid;
            public function __construct($season_key, $child_index, $child_uuid)
            {
                $this->season_key = $season_key;
                $this->child_index = $child_index;
                $this->child_uuid = $child_uuid;
            }
            public function get_meta($key, $single = true)
            {
                if ($key === '_intersoccer_discount_season_key') {
                    return $this->season_key;
                }
                if ($key === 'assigned_player') {
                    return $this->child_index;
                }
                if ($key === 'assigned_player_id') {
                    return $this->child_uuid;
                }
                return '';
            }
            public function get_product_id()
            {
                return 51723;
            }
            public function get_variation_id()
            {
                return 51724;
            }
        };
        return new class([$item]) {
            private $items;
            public function __construct($items)
            {
                $this->items = $items;
            }
            public function get_items($type = '')
            {
                return $this->items;
            }
            public function get_customer_id()
            {
                return 501;
            }
            public function get_status()
            {
                return 'completed';
            }
        };
    }

    private function makeSiblingCart(string $langA, string $langB)
    {
        $line = static function (string $lang, string $uuid, int $index) {
            [$parent, $variation] = [self::PROGRAMS[$lang][0], self::PROGRAMS[$lang][1]];
            $data = new class(self::BASE_PRICE) {
                private $p;
                public function __construct($p)
                {
                    $this->p = (float) $p;
                }
                public function get_price()
                {
                    return $this->p;
                }
                public function set_price($p)
                {
                    $this->p = (float) $p;
                }
            };
            return [
                'product_id' => $parent,
                'variation_id' => $variation,
                'quantity' => 1,
                'assigned_player' => $index,
                'assigned_player_id' => $uuid,
                'assigned_attendee' => 'Child ' . $index,
                'base_price' => self::BASE_PRICE,
                'data' => $data,
            ];
        };
        return new class([
            'line-1' => $line($langA, 'uuid-child-1', 0),
            'line-2' => $line($langB, 'uuid-child-2', 1),
        ]) {
            public $cart_contents;
            public function __construct($c)
            {
                $this->cart_contents = $c;
            }
            public function get_cart()
            {
                return $this->cart_contents;
            }
            public function get_applied_coupons()
            {
                return [];
            }
        };
    }

}
