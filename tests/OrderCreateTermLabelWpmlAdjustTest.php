<?php
/**
 * Creation labels must follow the order language when WPML adjusts get_term()
 * to the current language. On the storefront, wpml_switch_language('all') is
 * not a legal language and resolves to the default language, so a translated
 * term id still comes back with the default-language name unless the read
 * itself runs in the order language.
 *
 * Synthetic terms only. Expected labels are the fixture term names.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class OrderCreateTermLabelWpmlAdjustTest extends TestCase
{
    private const TAX_COURSE_DAY = 'pa_course-day';
    private const TAX_DAYS = 'pa_days-of-week';
    private const TAX_CANTON = 'pa_canton-region';
    private const TAX_VENUE = 'pa_intersoccer-venues';

    private const PARENT_COURSE = 1365001;
    private const VAR_COURSE = 1365002;
    private const PARENT_CAMP = 1365011;
    private const VAR_CAMP = 1365012;
    private const PARENT_VENUE = 1365021;
    private const VAR_VENUE = 1365022;

    private const ATTENDEE = 'Synthetic Attendee';

    /** @var array<string,array<string,string>> */
    private const WEEKDAY_SLUGS = [
        'en' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
        'fr' => ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'],
        'de' => ['montag', 'dienstag', 'mittwoch', 'donnerstag', 'freitag', 'samstag', 'sonntag'],
    ];

    /** @var array<string,array<string,string>> */
    private const WEEKDAY_NAMES = [
        'en' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        'fr' => ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'],
        'de' => ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'],
    ];

    /** @var array<string,array{slug:string,name:string}> */
    private const GENEVA = [
        'en' => ['slug' => 'geneva', 'name' => 'Geneva'],
        'fr' => ['slug' => 'geneve', 'name' => 'Genève'],
        'de' => ['slug' => 'genf', 'name' => 'Genf'],
    ];

    /**
     * EN catalog name is the unaccented form. DE is the translated term name.
     *
     * @var array<string,array{slug:string,name:string}>
     */
    private const ZURICH = [
        'en' => ['slug' => 'zurich', 'name' => 'Zurich'],
        'fr' => ['slug' => 'zurich-fr', 'name' => 'Zurich-FR'],
        'de' => ['slug' => 'zuerich', 'name' => 'Zürich'],
    ];

    /** @var array{env: string|false, superglobal: mixed}|null */
    private $savedLoadRealCheckoutEnv = null;

    public static function setUpBeforeClass(): void
    {
        putenv('INTERSOCCER_TEST_LOAD_REAL_CHECKOUT=1');
        $_ENV['INTERSOCCER_TEST_LOAD_REAL_CHECKOUT'] = '1';
    }

    public static function tearDownAfterClass(): void
    {
        putenv('INTERSOCCER_TEST_LOAD_REAL_CHECKOUT');
        unset($_ENV['INTERSOCCER_TEST_LOAD_REAL_CHECKOUT']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedLoadRealCheckoutEnv = [
            'env' => getenv('INTERSOCCER_TEST_LOAD_REAL_CHECKOUT'),
            'superglobal' => $_ENV['INTERSOCCER_TEST_LOAD_REAL_CHECKOUT'] ?? null,
        ];
        putenv('INTERSOCCER_TEST_LOAD_REAL_CHECKOUT=1');
        $_ENV['INTERSOCCER_TEST_LOAD_REAL_CHECKOUT'] = '1';
        require __DIR__ . '/fixtures/order-create-language-parity-bootstrap.php';
        TestProductTypeRegistry::reset();
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_get_term_throw_on_adjust'] = false;
        unset($GLOBALS['intersoccer_test_language_during_throw']);
    }

    protected function tearDown(): void
    {
        if ($this->savedLoadRealCheckoutEnv !== null) {
            if ($this->savedLoadRealCheckoutEnv['env'] === false) {
                putenv('INTERSOCCER_TEST_LOAD_REAL_CHECKOUT');
            } else {
                putenv('INTERSOCCER_TEST_LOAD_REAL_CHECKOUT=' . $this->savedLoadRealCheckoutEnv['env']);
            }
            if ($this->savedLoadRealCheckoutEnv['superglobal'] === null) {
                unset($_ENV['INTERSOCCER_TEST_LOAD_REAL_CHECKOUT']);
            } else {
                $_ENV['INTERSOCCER_TEST_LOAD_REAL_CHECKOUT'] = $this->savedLoadRealCheckoutEnv['superglobal'];
            }
        }
        parent::tearDown();
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function requestOrderLanguageProvider(): array
    {
        $cases = [];
        foreach (['en', 'fr', 'de'] as $request) {
            foreach (['en', 'fr', 'de'] as $order) {
                $cases['request_' . $request . '_order_' . $order] = [$request, $order];
            }
        }

        return $cases;
    }

    #[DataProvider('requestOrderLanguageProvider')]
    public function test_course_day_and_canton_use_order_language_term_names(string $request, string $order): void
    {
        $this->seedGroups([
            $this->weekdayGroup(self::TAX_COURSE_DAY, 1364100, 6),
            $this->cantonGroup(1364200, self::GENEVA),
        ]);

        $updates = $this->buildLine(
            'course',
            self::PARENT_COURSE,
            self::VAR_COURSE,
            [self::TAX_COURSE_DAY, self::TAX_CANTON],
            [
                'attribute_pa_course-day' => self::WEEKDAY_SLUGS[$request][6],
                'attribute_pa_canton-region' => self::GENEVA[$request]['slug'],
            ],
            $request,
            $order
        );

        $this->assertSame(self::WEEKDAY_NAMES[$order][6], $updates['Course Day'] ?? null);
        $this->assertSame(self::GENEVA[$order]['name'], $updates['Canton / Region'] ?? null);
        $this->assertSame(self::GENEVA['en']['slug'], $updates['_intersoccer_canonical_canton'] ?? null);
    }

    #[DataProvider('requestOrderLanguageProvider')]
    public function test_camp_weekdays_and_zurich_canton_use_order_language_term_names(string $request, string $order): void
    {
        $this->assertSame('Zurich', self::ZURICH['en']['name']);
        $this->assertSame('Zürich', self::ZURICH['de']['name']);

        $groups = [$this->cantonGroup(1364900, self::ZURICH)];
        for ($day = 0; $day < 7; $day++) {
            $groups[] = $this->weekdayGroup(self::TAX_DAYS, 1364300 + ($day * 10), $day);
        }
        $this->seedGroups($groups);

        $request_slugs = self::WEEKDAY_SLUGS[$request];
        $updates = $this->buildLine(
            'camp',
            self::PARENT_CAMP,
            self::VAR_CAMP,
            [self::TAX_DAYS, self::TAX_CANTON],
            [
                'attribute_pa_days-of-week' => implode(',', array_reverse($request_slugs)),
                'attribute_pa_canton-region' => self::ZURICH[$request]['slug'],
            ],
            $request,
            $order
        );

        $this->assertSame(implode(', ', self::WEEKDAY_NAMES[$order]), $updates['Days of Week'] ?? null);
        $this->assertSame(self::ZURICH[$order]['name'], $updates['Canton / Region'] ?? null);
        $this->assertSame(self::ZURICH['en']['slug'], $updates['_intersoccer_canonical_canton'] ?? null);
    }

    public function test_missing_translation_falls_back_to_english_term_name(): void
    {
        $this->seedGroups([
            [
                'taxonomy' => self::TAX_VENUE,
                'ids' => ['en' => 1364801],
                'langs' => [
                    'en' => ['slug' => 'fixture-venue-en-only', 'name' => 'Fixture Venue EN Only'],
                ],
            ],
        ]);

        $updates = $this->buildLine(
            'course',
            self::PARENT_VENUE,
            self::VAR_VENUE,
            [self::TAX_VENUE],
            [
                'attribute_pa_intersoccer-venues' => 'fixture-venue-en-only',
            ],
            'de',
            'fr'
        );

        $this->assertSame('Fixture Venue EN Only', $updates['Sites InterSoccer'] ?? null);
    }

    public function test_language_is_restored_when_term_read_throws(): void
    {
        $this->seedGroups([
            $this->weekdayGroup(self::TAX_COURSE_DAY, 1364100, 6),
        ]);
        $this->setRequestLanguage('fr');
        $GLOBALS['intersoccer_test_get_term_throw_on_adjust'] = true;

        $en_id = 1364100 + 1;
        $term = (object) [
            'term_id' => $en_id,
            'name' => self::WEEKDAY_NAMES['en'][6],
            'slug' => self::WEEKDAY_SLUGS['en'][6],
            'taxonomy' => self::TAX_COURSE_DAY,
        ];

        try {
            intersoccer_pv_order_meta_term_display_name($term, self::TAX_COURSE_DAY, 'de');
            $this->fail('Term read failure should surface to the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('term-adjust', $exception->getMessage());
        }

        $this->assertSame('de', $GLOBALS['intersoccer_test_language_during_throw'] ?? null);
        $this->assertSame('fr', $GLOBALS['intersoccer_test_wpml_current_language'] ?? null);
        $this->assertSame('fr', $this->effectiveLanguage());
    }

    /**
     * @param array<int,array<string,mixed>> $groups
     */
    private function seedGroups(array $groups): void
    {
        $terms = [];
        $en_map = [];
        foreach ($groups as $group) {
            $ids = [];
            foreach (['en', 'fr', 'de'] as $lang) {
                if (!isset($group['langs'][$lang], $group['ids'][$lang])) {
                    continue;
                }
                $id = (int) $group['ids'][$lang];
                $ids[$lang] = $id;
                $terms[$id] = [
                    'slug' => $group['langs'][$lang]['slug'],
                    'name' => $group['langs'][$lang]['name'],
                    'taxonomy' => $group['taxonomy'],
                    'wpml_lang' => $lang,
                ];
            }
            $twins = [];
            if (isset($ids['fr'])) {
                $twins['fr'] = $ids['fr'];
            }
            if (isset($ids['de'])) {
                $twins['de'] = $ids['de'];
            }
            $en_map[$ids['en']] = $twins;
        }

        order_create_language_register_wpml_term_twins($terms, $en_map);
        $this->installFrontendTermAdjuster();
    }

    /**
     * @return array{taxonomy:string,ids:array<string,int>,langs:array<string,array{slug:string,name:string}>}
     */
    private function weekdayGroup(string $taxonomy, int $base, int $day): array
    {
        $langs = [];
        $ids = [];
        foreach (['en' => 1, 'fr' => 2, 'de' => 3] as $lang => $offset) {
            $ids[$lang] = $base + $offset;
            $langs[$lang] = [
                'slug' => self::WEEKDAY_SLUGS[$lang][$day],
                'name' => self::WEEKDAY_NAMES[$lang][$day],
            ];
        }

        return [
            'taxonomy' => $taxonomy,
            'ids' => $ids,
            'langs' => $langs,
        ];
    }

    /**
     * @param array<string,array{slug:string,name:string}> $catalog
     * @return array{taxonomy:string,ids:array<string,int>,langs:array<string,array{slug:string,name:string}>}
     */
    private function cantonGroup(int $base, array $catalog): array
    {
        $ids = [];
        foreach (['en' => 1, 'fr' => 2, 'de' => 3] as $lang => $offset) {
            $ids[$lang] = $base + $offset;
        }

        return [
            'taxonomy' => self::TAX_CANTON,
            'ids' => $ids,
            'langs' => $catalog,
        ];
    }

    /**
     * @param string[]             $taxonomies
     * @param array<string,string> $variation_attributes
     * @return array<string,mixed>
     */
    private function buildLine(
        string $type,
        int $parent,
        int $variation,
        array $taxonomies,
        array $variation_attributes,
        string $request,
        string $order
    ): array {
        $this->setRequestLanguage($request);
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_products'] = [
            $parent => new OrderCreateLanguageMockParentProduct($parent, $taxonomies),
            $variation => new OrderCreateLanguageMockVariationProduct($variation, $variation_attributes),
        ];
        TestProductTypeRegistry::set($parent, $type);
        MockFilters::$filters['intersoccer_order_activity_type_is_girls_only'] = static fn () => false;

        $built = intersoccer_build_order_line_meta([
            'order' => new OrderCreateLanguageMockOrder($order),
            'product_id' => $parent,
            'variation_id' => $variation,
            'product_type' => $type,
            'cart_values' => [
                'assigned_attendee' => self::ATTENDEE,
            ],
        ]);

        $this->assertSame($request, $GLOBALS['intersoccer_test_wpml_current_language'] ?? null);
        $this->assertSame($request, $this->effectiveLanguage());
        $this->assertSame(self::ATTENDEE, $built['updates']['Assigned Attendee'] ?? null);

        return $built['updates'];
    }

    private function setRequestLanguage(string $lang): void
    {
        $GLOBALS['intersoccer_test_wpml_current_language'] = $lang;
    }

    private function effectiveLanguage(): string
    {
        return (string) apply_filters('wpml_current_language', null);
    }

    private function installFrontendTermAdjuster(): void
    {
        $GLOBALS['intersoccer_test_wpml_simulate_term_filters'] = true;
        $GLOBALS['intersoccer_test_wpml_suppress_term_filters'] = false;
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_wpml_term_id_for_current_lang'] = static function ($term_id) {
            $current = function_exists('apply_filters')
                ? (string) apply_filters('wpml_current_language', 'en')
                : 'en';
            if (!empty($GLOBALS['intersoccer_test_get_term_throw_on_adjust'])) {
                $GLOBALS['intersoccer_test_language_during_throw'] = $current;
                throw new RuntimeException('term-adjust');
            }

            $term_id = (int) $term_id;
            if ($current === 'all') {
                return $term_id;
            }

            $group = $GLOBALS['intersoccer_test_icl_translations'][$term_id] ?? null;
            if (!is_array($group) || !isset($group[$current])) {
                return $term_id;
            }

            return (int) $group[$current];
        };

        MockFilters::$filters['wpml_current_language'] = static function () {
            $lang = (string) ($GLOBALS['intersoccer_test_wpml_current_language'] ?? 'en');
            if ($lang === 'all' && empty($GLOBALS['intersoccer_test_is_admin'])) {
                $default = apply_filters('wpml_default_language', null);

                return is_string($default) && $default !== '' ? $default : 'en';
            }

            return $lang;
        };
    }
}
