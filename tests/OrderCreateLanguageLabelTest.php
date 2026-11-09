<?php
/**
 * Regression #136: order-language labels for taxonomy meta at creation (request vs order language).
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class OrderCreateLanguageLabelTest extends TestCase
{
    private const TAX_VENUE = 'pa_intersoccer-venues';
    private const TAX_CANTON = 'pa_canton-region';

    private const EN_VENUE_ID = 137101;
    private const FR_VENUE_ID = 137102;
    private const DE_VENUE_ID = 137103;

    private const EN_CANTON_ID = 137201;
    private const FR_CANTON_ID = 137202;
    private const DE_CANTON_ID = 137203;

    private const EN_ONLY_VENUE_ID = 137104;

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
        MockFilters::reset();
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
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string,5:string}>
     */
    public static function requestVsOrderLanguageProvider(): array
    {
        return [
            'request_en_order_fr' => ['en', 'fr', 'geneva', 'fixture-venue-a', 'Genève', 'Fixture Venue FR'],
            'request_fr_order_de' => ['fr', 'de', 'geneve', 'fixture-venue-fr', 'Genf', 'Fixture Venue DE'],
        ];
    }

    #[DataProvider('requestVsOrderLanguageProvider')]
    public function test_variation_canton_and_venue_labels_use_order_language_not_request(
        string $request_lang,
        string $order_lang,
        string $canton_slug,
        string $venue_slug,
        string $expected_canton_label,
        string $expected_venue_label
    ): void {
        $this->registerVenueAndCantonTerms();
        $this->setRequestLanguage($request_lang);

        $parent = new OrderCreateLanguageMockParentProduct(13701, [self::TAX_VENUE, self::TAX_CANTON]);
        $variation = new OrderCreateLanguageMockVariationProduct(13702, [
            'attribute_pa_intersoccer-venues' => $venue_slug,
            'attribute_pa_canton-region' => $canton_slug,
        ]);
        $GLOBALS['intersoccer_test_products'] = [13701 => $parent, 13702 => $variation];

        $attributes = intersoccer_get_parent_product_attributes(
            13701,
            13702,
            new OrderCreateLanguageMockOrder($order_lang)
        );

        $this->assertSame($expected_canton_label, $attributes['Canton / Region'] ?? null);
        $this->assertSame($expected_venue_label, $attributes['Sites InterSoccer'] ?? null);
    }

    public function test_parent_term_fallback_label_uses_order_language_when_request_differs(): void
    {
        $this->registerVenueAndCantonTerms();
        $this->setRequestLanguage('en');

        $parent = new OrderCreateLanguageMockParentProduct(13711, [self::TAX_CANTON]);
        $variation = new OrderCreateLanguageMockVariationProduct(13712, []);
        $GLOBALS['intersoccer_test_products'] = [13711 => $parent, 13712 => $variation];
        $GLOBALS['intersoccer_test_product_terms'] = [
            13711 => [
                self::TAX_CANTON => ['geneva'],
            ],
        ];

        $attributes = intersoccer_get_parent_product_attributes(
            13711,
            13712,
            new OrderCreateLanguageMockOrder('fr')
        );

        $this->assertSame('Genève', $attributes['Canton / Region'] ?? null);
    }

    public function test_en_only_venue_label_falls_back_to_english_when_order_has_no_translation(): void
    {
        $this->registerVenueAndCantonTerms();
        $this->registerEnOnlyVenueTerm();
        $this->setRequestLanguage('en');

        $parent = new OrderCreateLanguageMockParentProduct(13721, [self::TAX_VENUE, self::TAX_CANTON]);
        $variation = new OrderCreateLanguageMockVariationProduct(13722, [
            'attribute_pa_intersoccer-venues' => 'fixture-venue-en-only',
            'attribute_pa_canton-region' => 'geneva',
        ]);
        $GLOBALS['intersoccer_test_products'] = [13721 => $parent, 13722 => $variation];

        $attributes = intersoccer_get_parent_product_attributes(
            13721,
            13722,
            new OrderCreateLanguageMockOrder('fr')
        );

        $this->assertSame('Fixture Venue EN Only', $attributes['Sites InterSoccer'] ?? null);
        $this->assertSame('Genève', $attributes['Canton / Region'] ?? null);
    }

    private function setRequestLanguage(string $lang): void
    {
        $GLOBALS['intersoccer_test_wpml_current_language'] = $lang;
        MockFilters::$filters['wpml_current_language'] = static fn () => $lang;
    }

    private function registerVenueAndCantonTerms(): void
    {
        $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'] = true;
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'all';

        order_create_language_register_wpml_term_twins(
            [
                self::EN_VENUE_ID => [
                    'slug' => 'fixture-venue-a',
                    'name' => 'Fixture Venue EN',
                    'taxonomy' => self::TAX_VENUE,
                    'wpml_lang' => 'en',
                ],
                self::FR_VENUE_ID => [
                    'slug' => 'fixture-venue-fr',
                    'name' => 'Fixture Venue FR',
                    'taxonomy' => self::TAX_VENUE,
                    'wpml_lang' => 'fr',
                ],
                self::DE_VENUE_ID => [
                    'slug' => 'fixture-venue-de',
                    'name' => 'Fixture Venue DE',
                    'taxonomy' => self::TAX_VENUE,
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
                self::EN_VENUE_ID => ['fr' => self::FR_VENUE_ID, 'de' => self::DE_VENUE_ID],
                self::EN_CANTON_ID => ['fr' => self::FR_CANTON_ID, 'de' => self::DE_CANTON_ID],
            ]
        );
    }

    private function registerEnOnlyVenueTerm(): void
    {
        $GLOBALS['intersoccer_test_terms'][self::EN_ONLY_VENUE_ID] = [
            'slug' => 'fixture-venue-en-only',
            'name' => 'Fixture Venue EN Only',
            'taxonomy' => self::TAX_VENUE,
            'wpml_lang' => 'en',
        ];
    }
}
