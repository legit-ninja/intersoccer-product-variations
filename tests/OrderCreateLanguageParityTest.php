<?php
/**
 * Regression #136: EN/FR/DE order-create line meta keys and canonical slugs must match.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class OrderCreateLanguageParityTest extends TestCase
{
    private const PARENT_COURSE = 13601;
    private const VAR_COURSE = 13602;
    private const PARENT_CAMP = 13611;
    private const VAR_CAMP = 13612;

    private const TAX_COURSE_DAY = 'pa_course-day';
    private const TAX_CANTON = 'pa_canton-region';
    private const TAX_DAYS = 'pa_days-of-week';

    private const EN_DAY_ID = 136101;
    private const FR_DAY_ID = 136102;
    private const DE_DAY_ID = 136103;

    private const EN_CANTON_ID = 136201;
    private const FR_CANTON_ID = 136202;
    private const DE_CANTON_ID = 136203;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('INTERSOCCER_TEST_LOAD_REAL_CHECKOUT=1');
        $_ENV['INTERSOCCER_TEST_LOAD_REAL_CHECKOUT'] = '1';
        require __DIR__ . '/fixtures/order-create-language-parity-bootstrap.php';
        TestProductTypeRegistry::reset();
        MockFilters::$filters['intersoccer_order_activity_type_is_girls_only'] = static fn () => false;
    }

    protected function tearDown(): void
    {
        putenv('INTERSOCCER_TEST_LOAD_REAL_CHECKOUT');
        unset($_ENV['INTERSOCCER_TEST_LOAD_REAL_CHECKOUT']);
        parent::tearDown();
    }

    /**
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string,5:string,6:string}>
     */
    public static function localizedOrderLanguageProvider(): array
    {
        return [
            'english' => ['en', 'wednesday', 'Wednesday', 'geneva', 'Geneva', 'monday', 'Monday'],
            'french' => ['fr', 'mercredi', 'Mercredi', 'geneve', 'Genève', 'lundi', 'Lundi'],
            'german' => ['de', 'mittwoch', 'Mittwoch', 'genf', 'Genf', 'montag', 'Montag'],
        ];
    }

    #[DataProvider('localizedOrderLanguageProvider')]
    public function test_course_order_create_writes_course_day_and_canonical_canton(
        string $order_lang,
        string $course_day_slug,
        string $expected_course_day_label,
        string $canton_slug,
        string $expected_canton_label,
        string $unused_camp_slug,
        string $unused_camp_label
    ): void {
        unset($unused_camp_slug, $unused_camp_label);

        $this->seedCourseCatalog($course_day_slug, $canton_slug);
        $built = intersoccer_build_order_line_meta([
            'order' => new OrderCreateLanguageMockOrder($order_lang),
            'product_id' => self::PARENT_COURSE,
            'variation_id' => self::VAR_COURSE,
            'product_type' => 'course',
            'cart_values' => [],
        ]);

        $updates = $built['updates'];
        $this->assertArrayHasKey('Course Day', $updates);
        $this->assertSame($expected_course_day_label, $updates['Course Day']);
        $this->assertSame('geneva', $updates['_intersoccer_canonical_canton'] ?? null);
        $this->assertArrayHasKey('Canton / Region', $updates);
        $this->assertSame($expected_canton_label, $updates['Canton / Region']);
    }

    #[DataProvider('localizedOrderLanguageProvider')]
    public function test_camp_order_create_writes_days_of_week_and_canonical_canton(
        string $order_lang,
        string $unused_course_slug,
        string $unused_course_label,
        string $canton_slug,
        string $expected_canton_label,
        string $camp_day_slug,
        string $expected_day_label
    ): void {
        unset($unused_course_slug, $unused_course_label);

        $this->seedCampCatalog($camp_day_slug, $canton_slug);
        $built = intersoccer_build_order_line_meta([
            'order' => new OrderCreateLanguageMockOrder($order_lang),
            'product_id' => self::PARENT_CAMP,
            'variation_id' => self::VAR_CAMP,
            'product_type' => 'camp',
            'cart_values' => [],
        ]);

        $updates = $built['updates'];
        $this->assertArrayHasKey('Days of Week', $updates);
        $this->assertSame($expected_day_label, $updates['Days of Week']);
        $this->assertSame('geneva', $updates['_intersoccer_canonical_canton'] ?? null);
        $this->assertSame($expected_canton_label, $updates['Canton / Region'] ?? null);
    }

    public function test_en_fr_de_course_lines_share_meta_keys_and_canonical_facets(): void
    {
        $reference_keys = null;
        $reference_canonical = null;

        foreach (self::localizedOrderLanguageProvider() as $case) {
            [$order_lang, $course_day_slug, , $canton_slug] = $case;
            $this->seedCourseCatalog($course_day_slug, $canton_slug);
            $built = intersoccer_build_order_line_meta([
                'order' => new OrderCreateLanguageMockOrder($order_lang),
                'product_id' => self::PARENT_COURSE,
                'variation_id' => self::VAR_COURSE,
                'product_type' => 'course',
                'cart_values' => [],
            ]);
            $keys = array_keys($built['updates']);
            sort($keys);

            $canonical = [
                'canton' => $built['updates']['_intersoccer_canonical_canton'] ?? '',
                'course_day_present' => array_key_exists('Course Day', $built['updates']),
            ];

            if ($reference_keys === null) {
                $reference_keys = $keys;
                $reference_canonical = $canonical;
                continue;
            }

            $this->assertSame($reference_keys, $keys, 'Meta keys must match EN for language ' . $order_lang);
            $this->assertSame($reference_canonical, $canonical, 'Canonical facets must match EN for language ' . $order_lang);
            $this->assertSame('geneva', $built['updates']['_intersoccer_canonical_canton'] ?? null);
        }
    }

    private function seedCourseCatalog(string $course_day_slug, string $canton_slug): void
    {
        $parent = new OrderCreateLanguageMockParentProduct(self::PARENT_COURSE, [
            self::TAX_COURSE_DAY,
            self::TAX_CANTON,
        ]);
        $variation = new OrderCreateLanguageMockVariationProduct(self::VAR_COURSE, [
            'attribute_pa_course-day' => $course_day_slug,
            'attribute_pa_canton-region' => $canton_slug,
        ]);
        $GLOBALS['intersoccer_test_products'] = [
            self::PARENT_COURSE => $parent,
            self::VAR_COURSE => $variation,
        ];
        TestProductTypeRegistry::set(self::PARENT_COURSE, 'course');

        order_create_language_register_wpml_term_twins(
            [
                self::EN_DAY_ID => [
                    'slug' => 'wednesday',
                    'name' => 'Wednesday',
                    'taxonomy' => self::TAX_COURSE_DAY,
                    'wpml_lang' => 'en',
                ],
                self::FR_DAY_ID => [
                    'slug' => 'mercredi',
                    'name' => 'Mercredi',
                    'taxonomy' => self::TAX_COURSE_DAY,
                    'wpml_lang' => 'fr',
                ],
                self::DE_DAY_ID => [
                    'slug' => 'mittwoch',
                    'name' => 'Mittwoch',
                    'taxonomy' => self::TAX_COURSE_DAY,
                    'wpml_lang' => 'de',
                ],
                self::EN_CANTON_ID => [
                    'slug' => 'geneva',
                    'name' => 'Geneva',
                    'taxonomy' => self::TAX_CANTON,
                    'wpml_lang' => 'en',
                ],
                self::FR_CANTON_ID => [
                    'slug' => 'geneve',
                    'name' => 'Genève',
                    'taxonomy' => self::TAX_CANTON,
                    'wpml_lang' => 'fr',
                ],
                self::DE_CANTON_ID => [
                    'slug' => 'genf',
                    'name' => 'Genf',
                    'taxonomy' => self::TAX_CANTON,
                    'wpml_lang' => 'de',
                ],
            ],
            [
                self::EN_DAY_ID => ['fr' => self::FR_DAY_ID, 'de' => self::DE_DAY_ID],
                self::EN_CANTON_ID => ['fr' => self::FR_CANTON_ID, 'de' => self::DE_CANTON_ID],
            ]
        );
    }

    private function seedCampCatalog(string $camp_day_slug, string $canton_slug): void
    {
        $parent = new OrderCreateLanguageMockParentProduct(self::PARENT_CAMP, [
            self::TAX_DAYS,
            self::TAX_CANTON,
        ]);
        $variation = new OrderCreateLanguageMockVariationProduct(self::VAR_CAMP, [
            'attribute_pa_days-of-week' => $camp_day_slug,
            'attribute_pa_canton-region' => $canton_slug,
        ]);
        $GLOBALS['intersoccer_test_products'] = [
            self::PARENT_CAMP => $parent,
            self::VAR_CAMP => $variation,
        ];
        TestProductTypeRegistry::set(self::PARENT_CAMP, 'camp');

        order_create_language_register_wpml_term_twins(
            [
                136301 => [
                    'slug' => 'monday',
                    'name' => 'Monday',
                    'taxonomy' => self::TAX_DAYS,
                    'wpml_lang' => 'en',
                ],
                136302 => [
                    'slug' => 'lundi',
                    'name' => 'Lundi',
                    'taxonomy' => self::TAX_DAYS,
                    'wpml_lang' => 'fr',
                ],
                136303 => [
                    'slug' => 'montag',
                    'name' => 'Montag',
                    'taxonomy' => self::TAX_DAYS,
                    'wpml_lang' => 'de',
                ],
                self::EN_CANTON_ID => [
                    'slug' => 'geneva',
                    'name' => 'Geneva',
                    'taxonomy' => self::TAX_CANTON,
                    'wpml_lang' => 'en',
                ],
                self::FR_CANTON_ID => [
                    'slug' => 'geneve',
                    'name' => 'Genève',
                    'taxonomy' => self::TAX_CANTON,
                    'wpml_lang' => 'fr',
                ],
                self::DE_CANTON_ID => [
                    'slug' => 'genf',
                    'name' => 'Genf',
                    'taxonomy' => self::TAX_CANTON,
                    'wpml_lang' => 'de',
                ],
            ],
            [
                136301 => ['fr' => 136302, 'de' => 136303],
                self::EN_CANTON_ID => ['fr' => self::FR_CANTON_ID, 'de' => self::DE_CANTON_ID],
            ]
        );
    }
}
