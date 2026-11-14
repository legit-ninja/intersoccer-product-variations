<?php
/**
 * Girls-only sessions are decided from the line's own terms, matched exactly.
 *
 * A parent term must not override a variation value, and a longer label that
 * merely contains "filles" or "mädchen" is not girls-only.
 *
 * Issue 175.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';

/**
 * Variation stand-in whose get_attribute map is the only attribute source.
 */
class GirlsOnlyExactTermVariationStub {
    /** @var array<string, string> */
    private $attributes;

    public function __construct(array $attributes) {
        $this->attributes = $attributes;
    }

    public function get_attribute($name) {
        return isset($this->attributes[$name]) ? (string) $this->attributes[$name] : '';
    }
}

class GirlsOnlyExactTermTest extends TestCase {

    /** @var int */
    private $nextProductId = 175100;

    /** @var int */
    private $nextTermId = 175500;

    /** @var int[] */
    private $productIds = [];

    /** @var int[] */
    private $termIds = [];

    /** @var int[] */
    private $metaIds = [];

    /**
     * Checkout display tests run in a child process. Set the flag here, after
     * other classes have cleared it, so the child bootstrap loads the real
     * checkout helpers instead of the player-details double.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        putenv('INTERSOCCER_TEST_LOAD_REAL_CHECKOUT=1');
        $_ENV['INTERSOCCER_TEST_LOAD_REAL_CHECKOUT'] = '1';
    }

    public static function tearDownAfterClass(): void {
        putenv('INTERSOCCER_TEST_LOAD_REAL_CHECKOUT');
        unset($_ENV['INTERSOCCER_TEST_LOAD_REAL_CHECKOUT']);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void {
        parent::setUp();
        $this->clearFixtures();
    }

    protected function tearDown(): void {
        $this->clearFixtures();
        parent::tearDown();
    }

    public function test_activity_term_match_is_whole_term_only(): void {
        $this->assertFalse(intersoccer_activity_type_term_is_girls_only('mixte-garcons-filles', 'Garçons et filles'));
        $this->assertFalse(intersoccer_activity_type_term_is_girls_only('jungen-und-madchen', 'Jungen und Mädchen'));
        $this->assertFalse(intersoccer_activity_type_term_is_girls_only('camp-filles-extra', 'Camp filles extra'));
        $this->assertTrue(intersoccer_activity_type_term_is_girls_only('filles', ''));
        $this->assertTrue(intersoccer_activity_type_term_is_girls_only('madchen', 'Mädchen'));
        $this->assertTrue(intersoccer_activity_type_term_is_girls_only('filles-uniquement', 'Filles uniquement'));
        $this->assertTrue(intersoccer_activity_type_term_is_girls_only('nur-madchen', 'Nur Mädchen'));
        $this->assertTrue(intersoccer_activity_type_term_is_girls_only('nur-fur-madchen', 'Nur für Mädchen'));
        $this->assertTrue(intersoccer_activity_type_term_is_girls_only('reserve-aux-filles', 'Réservé aux filles'));
        $this->assertTrue(intersoccer_activity_type_term_is_girls_only('', "Girl's Only"));
        $this->assertTrue(intersoccer_switch_term_indicates_girls_only_session('yes', 'Yes'));
        $this->assertTrue(intersoccer_switch_term_indicates_girls_only_session('girls-only', "Girl's Only"));
        $this->assertFalse(intersoccer_switch_term_indicates_girls_only_session('no', 'No'));
        $this->assertFalse(intersoccer_switch_term_indicates_girls_only_session('camp-girls-only', 'Camp girls only'));
    }

    public static function mixedActivityLabels(): array {
        return [
            'Garçons et filles' => ['garcons-et-filles', 'Garçons et filles'],
            'Jungen und Mädchen' => ['jungen-und-madchen', 'Jungen und Mädchen'],
            'mixte-garcons-filles' => ['mixte-garcons-filles', 'Mixte garçons filles'],
        ];
    }

    /**
     * Parent carries a girls-only activity term. The variation's own mixed label wins.
     */
    #[DataProvider('mixedActivityLabels')]
    public function test_mixed_activity_on_variation_allows_a_boy(string $slug, string $name): void {
        [$parentId, $variationId] = $this->activityLine(
            [
                ['camp', 'Camp'],
                ['girls-only', 'Girls Only'],
            ],
            $slug,
            $name
        );

        $pairs = intersoccer_collect_taxonomy_term_pairs_for_line($parentId, $variationId, 'pa_activity-type');
        $this->assertCount(1, $pairs);
        $this->assertSame($slug, $pairs[0]['slug']);
        $this->assertBoyAllowed($parentId, $variationId);
    }

    public static function switchNoValues(): array {
        return [
            'EN No' => ['no', 'No', 'yes', 'Yes'],
            'FR Non' => ['non', 'Non', 'oui', 'Oui'],
            'DE Nein' => ['nein', 'Nein', 'ja', 'Ja'],
        ];
    }

    /**
     * Variation No/Non/Nein wins over a parent that carries both yes and no.
     */
    #[DataProvider('switchNoValues')]
    public function test_switch_no_on_variation_allows_a_boy(string $noSlug, string $noName, string $yesSlug, string $yesName): void {
        [$parentId, $variationId] = $this->switchLine(
            [
                [$yesSlug, $yesName],
                [$noSlug, $noName],
            ],
            $noSlug,
            $noName
        );

        $this->assertBoyAllowed($parentId, $variationId);
    }

    public static function girlsOnlyActivityTerms(): array {
        return [
            'EN Girls Only' => ['girls-only', 'Girls Only'],
            'FR Filles uniquement' => ['filles-uniquement', 'Filles uniquement'],
            'DE Nur Mädchen' => ['nur-madchen', 'Nur Mädchen'],
        ];
    }

    #[DataProvider('girlsOnlyActivityTerms')]
    public function test_own_girls_only_activity_rejects_a_boy_and_allows_a_girl(string $slug, string $name): void {
        [$parentId, $variationId] = $this->activityLine(
            [
                ['camp', 'Camp'],
            ],
            $slug,
            $name
        );

        $this->assertBoyRejected($parentId, $variationId);
        $this->assertGirlAllowed($parentId, $variationId);
    }

    public static function switchYesValues(): array {
        return [
            'EN Yes' => ['yes', 'Yes', 'no', 'No'],
            'FR Oui' => ['oui', 'Oui', 'non', 'Non'],
            'DE Ja' => ['ja', 'Ja', 'nein', 'Nein'],
        ];
    }

    #[DataProvider('switchYesValues')]
    public function test_own_switch_yes_rejects_a_boy_and_allows_a_girl(string $yesSlug, string $yesName, string $noSlug, string $noName): void {
        [$parentId, $variationId] = $this->switchLine(
            [
                [$noSlug, $noName],
            ],
            $yesSlug,
            $yesName
        );

        $this->assertBoyRejected($parentId, $variationId);
        $this->assertGirlAllowed($parentId, $variationId);
    }

    public function test_parent_fallback_yes_only_is_girls_only(): void {
        [$parentId, $variationId] = $this->switchLine(
            [
                ['yes', 'Yes'],
            ],
            null,
            null
        );

        $this->assertBoyRejected($parentId, $variationId);
        $this->assertGirlAllowed($parentId, $variationId);
    }

    public function test_parent_fallback_yes_and_no_does_not_mark_variation_girls_only(): void {
        [$parentId, $variationId] = $this->switchLine(
            [
                ['yes', 'Yes'],
                ['no', 'No'],
            ],
            null,
            null
        );

        $this->assertBoyAllowed($parentId, $variationId);
    }

    public function test_parent_fallback_exclusive_girls_only_activity_rejects_a_boy(): void {
        [$parentId, $variationId] = $this->activityLine(
            [
                ['girls-only', 'Girls Only'],
            ],
            null,
            null
        );

        $this->assertBoyRejected($parentId, $variationId);
    }

    public function test_parent_fallback_mixed_activity_allows_a_boy(): void {
        [$parentId, $variationId] = $this->activityLine(
            [
                ['camp', 'Camp'],
                ['girls-only', 'Girls Only'],
            ],
            null,
            null
        );

        $pairs = intersoccer_collect_taxonomy_term_pairs_for_line($parentId, $variationId, 'pa_activity-type');
        $this->assertCount(2, $pairs);
        $this->assertBoyAllowed($parentId, $variationId);
    }

    public function test_get_attribute_own_value_beats_parent_term(): void {
        $parentId = $this->allocProductId();
        $variationId = $this->allocProductId();
        $camp = $this->registerTerm('camp', 'Camp', 'pa_activity-type');
        $girls = $this->registerTerm('girls-only', 'Girls Only', 'pa_activity-type');
        $this->assignParentTerms($parentId, 'pa_activity-type', [$camp, $girls]);
        $GLOBALS['intersoccer_test_products'][$variationId] = new GirlsOnlyExactTermVariationStub([
            'pa_activity-type' => 'Garçons et filles',
        ]);

        $this->assertBoyAllowed($parentId, $variationId);

        $girlsVariation = $this->allocProductId();
        $this->registerTerm('girls-only', 'Girls Only', 'pa_activity-type');
        $GLOBALS['intersoccer_test_products'][$girlsVariation] = new GirlsOnlyExactTermVariationStub([
            'pa_activity-type' => 'Girls Only',
        ]);
        $this->assertBoyRejected($parentId, $girlsVariation);
        $this->assertGirlAllowed($parentId, $girlsVariation);
    }

    public function test_simple_product_own_girls_only_term_rejects_a_boy(): void {
        $productId = $this->allocProductId();
        $term = $this->registerTerm('filles-uniquement', 'Filles uniquement', 'pa_activity-type');
        $this->assignParentTerms($productId, 'pa_activity-type', [$term]);

        $this->assertBoyRejected($productId, 0);
        $this->assertGirlAllowed($productId, 0);
    }

    public function test_simple_product_mixed_label_allows_a_boy(): void {
        $productId = $this->allocProductId();
        $term = $this->registerTerm('jungen-und-madchen', 'Jungen und Mädchen', 'pa_activity-type');
        $this->assignParentTerms($productId, 'pa_activity-type', [$term]);

        $this->assertBoyAllowed($productId, 0);
    }

    public function test_simple_product_with_camp_and_girls_only_terms_rejects_a_boy(): void {
        $productId = $this->allocProductId();
        $camp = $this->registerTerm('camp', 'Camp', 'pa_activity-type');
        $girls = $this->registerTerm('girls-only', 'Girls Only', 'pa_activity-type');
        $this->assignParentTerms($productId, 'pa_activity-type', [$camp, $girls]);

        $this->assertBoyRejected($productId, 0);
    }

    public static function mixedTitles(): array {
        return [
            'Garçons et filles' => ['Garçons et filles'],
            'Jungen und Mädchen' => ['Jungen und Mädchen'],
        ];
    }

    #[DataProvider('mixedTitles')]
    public function test_mixed_title_is_not_girls_only(string $title): void {
        $productId = $this->allocProductId();
        $this->setTitle($productId, $title);

        $this->assertBoyAllowed($productId, 0);
    }

    public static function girlsOnlyTitles(): array {
        return [
            'Girls Only Camp' => ['Girls Only Camp'],
            'Filles uniquement' => ['Filles uniquement'],
            'Nur Mädchen' => ['Nur Mädchen'],
        ];
    }

    #[DataProvider('girlsOnlyTitles')]
    public function test_title_phrase_still_marks_girls_only(string $title): void {
        $productId = $this->allocProductId();
        $this->setTitle($productId, $title);

        $this->assertBoyRejected($productId, 0);
        $this->assertGirlAllowed($productId, 0);
    }

    public function test_product_cat_girls_only_still_rejects_a_boy(): void {
        [$parentId, $variationId] = $this->activityLine(
            [
                ['camp', 'Camp'],
            ],
            'garcons-et-filles',
            'Garçons et filles'
        );
        $GLOBALS['intersoccer_test_post_terms'][$parentId]['product_cat'] = ['girls-only'];

        $this->assertBoyRejected($parentId, $variationId);
    }

    public function test_product_cat_requires_exact_girls_only_slug(): void {
        $productId = $this->allocProductId();
        $GLOBALS['intersoccer_test_post_terms'][$productId]['product_cat'] = ['girls-only-camp'];

        $this->assertBoyAllowed($productId, 0);
    }

    public function test_line_is_girls_only_program_filter_still_applies(): void {
        [$parentId, $variationId] = $this->activityLine(
            [
                ['girls-only', 'Girls Only'],
            ],
            'girls-only',
            'Girls Only'
        );
        MockFilters::$filters['intersoccer_line_is_girls_only_program'] = static function () {
            return false;
        };

        $this->assertFalse(intersoccer_line_is_girls_only_program($parentId, $variationId));
        $this->setPlayer('male');
        $this->assertTrue(intersoccer_validate_line_player_girls_only(1, 0, $parentId, $variationId));
    }

    public function test_girls_only_attribute_taxonomies_filter_still_applies(): void {
        $parentId = $this->allocProductId();
        $variationId = $this->allocProductId();
        $camp = $this->registerTerm('camp', 'Camp', 'pa_activity-type');
        $this->assignParentTerms($parentId, 'pa_activity-type', [$camp]);
        $this->registerTerm('girls-only', 'Girls Only', 'pa_audience');
        $this->setVariationMeta($variationId, 'pa_audience', 'girls-only');
        MockFilters::$filters['intersoccer_girls_only_attribute_taxonomies'] = static function () {
            return ['pa_audience'];
        };

        $this->assertBoyRejected($parentId, $variationId);
        $this->assertGirlAllowed($parentId, $variationId);
    }

    /**
     * Parent Yes and No, variation has no own value: not a girls-only line, so
     * checkout display meta omits Girls Only and a boy can book.
     */
    #[RunInSeparateProcess]
    public function test_parent_yes_and_no_omits_girls_only_display_meta_and_allows_a_boy(): void {
        $this->loadCheckoutAttributeBuilder();
        [$parentId, $variationId] = $this->switchLine(
            [
                ['yes', 'Yes'],
                ['no', 'No'],
            ],
            null,
            null
        );
        $this->installCheckoutAttributeProducts($parentId, $variationId, 'pa_girls-only');

        $attributes = intersoccer_get_parent_product_attributes($parentId, $variationId);
        $label = intersoccer_attr_order_meta_label('girls-only');
        $this->assertNotSame('', $label);
        $this->assertArrayNotHasKey($label, $attributes);
        $this->assertBoyAllowed($parentId, $variationId);
    }

    /**
     * Variation's own Yes is girls-only, so the Girls Only display entry is written.
     */
    #[RunInSeparateProcess]
    public function test_variation_own_yes_writes_girls_only_display_meta(): void {
        $this->loadCheckoutAttributeBuilder();
        [$parentId, $variationId] = $this->switchLine(
            [
                ['no', 'No'],
            ],
            'yes',
            'Yes'
        );
        $this->installCheckoutAttributeProducts($parentId, $variationId, 'pa_girls-only');

        $attributes = intersoccer_get_parent_product_attributes($parentId, $variationId);
        $label = intersoccer_attr_order_meta_label('girls-only');
        $this->assertSame('Yes', $attributes[$label] ?? null);
    }

    private function loadCheckoutAttributeBuilder(): void {
        if (!function_exists('intersoccer_attr_order_meta_label')) {
            require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
        }
        if (!function_exists('intersoccer_format_girls_only_meta_value')) {
            require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
        }
        if (!function_exists('intersoccer_get_parent_product_attributes')) {
            require_once dirname(__DIR__) . '/includes/woocommerce/checkout-calculations.php';
        }
    }

    private function installCheckoutAttributeProducts(int $parentId, int $variationId, string $taxonomy): void {
        $attribute = new class {
            public function is_taxonomy() {
                return true;
            }
        };
        $GLOBALS['intersoccer_test_products'][$parentId] = new class($taxonomy, $attribute) {
            private $taxonomy;
            private $attribute;

            public function __construct($taxonomy, $attribute) {
                $this->taxonomy = $taxonomy;
                $this->attribute = $attribute;
            }

            public function get_attributes() {
                return [$this->taxonomy => $this->attribute];
            }
        };
        $GLOBALS['intersoccer_test_products'][$variationId] = new class {
            public function get_variation_attributes() {
                return [];
            }

            public function get_attribute($name) {
                unset($name);
                return '';
            }
        };
    }

    /**
     * @param array<int, array{0: string, 1: string}> $parentTerms
     * @return array{0: int, 1: int}
     */
    private function activityLine(array $parentTerms, ?string $variationSlug, ?string $variationName): array {
        return $this->termLine('pa_activity-type', $parentTerms, $variationSlug, $variationName);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $parentTerms
     * @return array{0: int, 1: int}
     */
    private function switchLine(array $parentTerms, ?string $variationSlug, ?string $variationName): array {
        return $this->termLine('pa_girls-only', $parentTerms, $variationSlug, $variationName);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $parentTerms
     * @return array{0: int, 1: int}
     */
    private function termLine(string $taxonomy, array $parentTerms, ?string $variationSlug, ?string $variationName): array {
        $parentId = $this->allocProductId();
        $variationId = $this->allocProductId();
        $objects = [];
        foreach ($parentTerms as $row) {
            $objects[] = $this->registerTerm($row[0], $row[1], $taxonomy);
        }
        if ($objects !== []) {
            $this->assignParentTerms($parentId, $taxonomy, $objects);
        }
        if ($variationSlug !== null) {
            $this->registerTerm($variationSlug, $variationName === null ? $variationSlug : $variationName, $taxonomy);
            $this->setVariationMeta($variationId, $taxonomy, $variationSlug);
        }
        return [$parentId, $variationId];
    }

    private function assertBoyAllowed(int $productId, int $variationId): void {
        $this->setPlayer('male');
        $this->assertFalse(intersoccer_line_is_girls_only_program($productId, $variationId));
        $this->assertTrue(intersoccer_validate_line_player_girls_only(1, 0, $productId, $variationId));
    }

    private function assertBoyRejected(int $productId, int $variationId): void {
        $this->setPlayer('male');
        $this->assertTrue(intersoccer_line_is_girls_only_program($productId, $variationId));
        $result = intersoccer_validate_line_player_girls_only(1, 0, $productId, $variationId);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('intersoccer_girls_only_wrong_gender', $result->get_error_code());
    }

    private function assertGirlAllowed(int $productId, int $variationId): void {
        $this->setPlayer('female');
        $this->assertTrue(intersoccer_line_is_girls_only_program($productId, $variationId));
        $this->assertTrue(intersoccer_validate_line_player_girls_only(1, 0, $productId, $variationId));
    }

    private function setPlayer(string $gender): void {
        $GLOBALS['intersoccer_test_player_details'] = [
            'first_name' => 'Example',
            'last_name' => 'Player',
            'gender' => $gender,
        ];
    }

    private function setTitle(int $postId, string $title): void {
        $GLOBALS['intersoccer_test_post_fields'][$postId]['post_title'] = $title;
    }

    /**
     * @param object[] $terms
     */
    private function assignParentTerms(int $productId, string $taxonomy, array $terms): void {
        $GLOBALS['intersoccer_test_product_terms'][$productId][$taxonomy] = $terms;
    }

    private function setVariationMeta(int $variationId, string $taxonomy, string $slug): void {
        $this->metaIds[] = $variationId;
        MockMetaData::$data[$variationId]['attribute_' . $taxonomy] = $slug;
    }

    /**
     * @return object
     */
    private function registerTerm(string $slug, string $name, string $taxonomy) {
        $termId = $this->nextTermId;
        $this->nextTermId++;
        $this->termIds[] = $termId;
        $GLOBALS['intersoccer_test_terms'][$termId] = [
            'slug' => $slug,
            'name' => $name,
            'taxonomy' => $taxonomy,
        ];
        return (object) [
            'term_id' => $termId,
            'slug' => $slug,
            'name' => $name,
            'taxonomy' => $taxonomy,
        ];
    }

    private function allocProductId(): int {
        $id = $this->nextProductId;
        $this->nextProductId++;
        $this->productIds[] = $id;
        return $id;
    }

    private function clearFixtures(): void {
        foreach ($this->productIds as $id) {
            unset(
                $GLOBALS['intersoccer_test_products'][$id],
                $GLOBALS['intersoccer_test_product_terms'][$id],
                $GLOBALS['intersoccer_test_post_fields'][$id],
                $GLOBALS['intersoccer_test_post_terms'][$id]
            );
        }
        foreach ($this->termIds as $id) {
            unset($GLOBALS['intersoccer_test_terms'][$id]);
        }
        foreach ($this->metaIds as $id) {
            unset(MockMetaData::$data[$id]);
        }
        $this->productIds = [];
        $this->termIds = [];
        $this->metaIds = [];
        unset($GLOBALS['intersoccer_test_player_details']);
        unset(
            MockFilters::$filters['intersoccer_line_is_girls_only_program'],
            MockFilters::$filters['intersoccer_girls_only_attribute_taxonomies'],
            MockFilters::$filters['intersoccer_taxonomy_is_girls_only_switch_attribute']
        );
    }
}
