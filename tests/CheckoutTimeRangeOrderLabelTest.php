<?php
/**
 * Checkout order-line course time labels: FR compact → FR style; camp and EN/DE unchanged.
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class CheckoutTimeRangeOrderLabelTest extends TestCase
{
    private const PA_COURSE = 'pa_course-times';
    private const PA_CAMP = 'pa_camp-times';

    private const INTEGRATION_PROCESS_TESTS = [
        'test_fr_order_uses_icl_language_code_for_taxonomies_and_order_lang_for_time',
        'test_order_language_overrides_request_language_for_course_attributes',
        'test_build_order_line_meta_keeps_slug_contract_meta',
        'test_fr_camp_times_unchanged_on_fr_order',
        'test_en_order_keeps_en_course_compact',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (in_array($this->name(), self::INTEGRATION_PROCESS_TESTS, true)) {
            return;
        }
        if (!class_exists('MockFilters', false)) {
            require_once __DIR__ . '/bootstrap.php';
        }
        if (!function_exists('intersoccer_format_order_line_time_attribute_label')) {
            require_once dirname(__DIR__) . '/includes/woocommerce/time-format.php';
        }
        MockFilters::reset();
        MockFilters::$filters['wpml_current_language'] = static fn () => 'en';
    }

    private function loadCheckoutTimeRangeIntegrationBootstrap(): void
    {
        require __DIR__ . '/fixtures/checkout-time-range-integration-bootstrap.php';
    }

    protected function tearDown(): void
    {
        if (class_exists('MockFilters', false)) {
            MockFilters::reset();
        }
        parent::tearDown();
    }

    public function test_fr_course_compact_to_fr_ascii_hyphen(): void
    {
        $this->assertSame(
            '11h15-12h45',
            intersoccer_format_order_line_time_attribute_label('1115-1245', self::PA_COURSE, 'fr')
        );
    }

    public function test_fr_course_compact_with_wpml_suffix_to_fr(): void
    {
        $this->assertSame(
            '11h15-12h45',
            intersoccer_format_order_line_time_attribute_label('1115-1245-fr', self::PA_COURSE, 'fr')
        );
    }

    public function test_fr_course_already_formatted_9h30_unchanged(): void
    {
        $this->assertSame(
            '9h30-11h00',
            intersoccer_format_order_line_time_attribute_label('9h30-11h00', self::PA_COURSE, 'fr')
        );
    }

    public function test_fr_course_already_formatted_11h15_unchanged(): void
    {
        $this->assertSame(
            '11h15-12h45',
            intersoccer_format_order_line_time_attribute_label('11h15-12h45', self::PA_COURSE, 'fr')
        );
    }

    public function test_fr_camp_time_unchanged(): void
    {
        $this->assertSame(
            '1400-1530',
            intersoccer_format_order_line_time_attribute_label('1400-1530', self::PA_CAMP, 'fr')
        );
    }

    public function test_en_course_time_unchanged(): void
    {
        $this->assertSame(
            '1115-1245',
            intersoccer_format_order_line_time_attribute_label('1115-1245', self::PA_COURSE, 'en')
        );
    }

    public function test_de_course_time_unchanged(): void
    {
        $this->assertSame(
            '1115-1245',
            intersoccer_format_order_line_time_attribute_label('1115-1245', self::PA_COURSE, 'de')
        );
    }

    public function test_de_camp_time_unchanged(): void
    {
        $this->assertSame(
            '1000-1230',
            intersoccer_format_order_line_time_attribute_label('1000-1230', self::PA_CAMP, 'de')
        );
    }

    public function test_non_time_label_unchanged(): void
    {
        $this->assertSame(
            'Full Week',
            intersoccer_format_order_line_time_attribute_label('Full Week', self::PA_COURSE, 'fr')
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_fr_order_uses_icl_language_code_for_taxonomies_and_order_lang_for_time(): void
    {
        $this->loadCheckoutTimeRangeIntegrationBootstrap();

        seed_checkout_time_range_course_product('1115-1245', '1115-1245');

        $order = new CheckoutTimeRangeMockOrder('fr');
        $attributes = intersoccer_get_parent_product_attributes(5001, 5002, $order);

        $this->assertSame('11h15-12h45', $attributes['Course Times'] ?? null);
        $this->assertSame('Fixture Venue EN', $attributes['Sites InterSoccer'] ?? null);
        $this->assertSame('Fixture Age EN', $attributes['Age Group'] ?? null);

        $icl_calls = $GLOBALS['intersoccer_test_icl_object_id_calls'] ?? [];
        $this->assertNotEmpty($icl_calls);
        foreach ($icl_calls as $call) {
            $this->assertSame('en', $call['language_code'] ?? null, 'icl_object_id must use ICL_LANGUAGE_CODE for taxonomy labels');
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_order_language_overrides_request_language_for_course_attributes(): void
    {
        $this->loadCheckoutTimeRangeIntegrationBootstrap();

        seed_checkout_time_range_course_product('1115-1245', '1115-1245');

        $order = new CheckoutTimeRangeMockOrder('fr');
        $attributes = intersoccer_get_parent_product_attributes(5001, 5002, $order);
        $this->assertSame('11h15-12h45', $attributes['Course Times'] ?? null);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_build_order_line_meta_keeps_slug_contract_meta(): void
    {
        $this->loadCheckoutTimeRangeIntegrationBootstrap();

        seed_checkout_time_range_course_product('1115-1245', '1115-1245');

        $built = intersoccer_build_order_line_meta([
            'order' => new CheckoutTimeRangeMockOrder('fr'),
            'product_id' => 5001,
            'variation_id' => 5002,
            'product_type' => 'course',
            'cart_values' => [],
        ]);

        $this->assertSame('11h15-12h45', $built['updates']['Course Times'] ?? null);
        $slug_meta = intersoccer_collect_variation_taxonomy_meta(5002);
        $this->assertSame('1115-1245', $slug_meta['attribute_pa_course-times'] ?? null);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_fr_camp_times_unchanged_on_fr_order(): void
    {
        $this->loadCheckoutTimeRangeIntegrationBootstrap();

        $parent = new CheckoutTimeRangeMockParentProduct(6001, ['pa_booking-type', 'pa_camp-times']);
        $variation = new CheckoutTimeRangeMockVariationProduct(6002, [
            'attribute_pa_booking-type' => 'full-week',
            'attribute_pa_camp-times' => '1000-1700',
        ]);
        $GLOBALS['intersoccer_test_products'] = [6001 => $parent, 6002 => $variation];
        $GLOBALS['intersoccer_test_terms'] = [
            7010 => ['name' => 'Full Week', 'slug' => 'full-week', 'taxonomy' => 'pa_booking-type'],
            7011 => ['name' => '1000-1700', 'slug' => '1000-1700', 'taxonomy' => 'pa_camp-times'],
        ];

        $attributes = intersoccer_get_parent_product_attributes(6001, 6002, new CheckoutTimeRangeMockOrder('fr'));
        $this->assertSame('Full Week', $attributes['Booking Type'] ?? null);
        $this->assertSame('1000-1700', $attributes['Camp Times'] ?? null);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_en_order_keeps_en_course_compact(): void
    {
        $this->loadCheckoutTimeRangeIntegrationBootstrap();

        seed_checkout_time_range_course_product('1115-1245', '1115-1245');

        $order = new CheckoutTimeRangeMockOrder('en');
        $attributes = intersoccer_get_parent_product_attributes(5001, 5002, $order);
        $this->assertSame('1115-1245', $attributes['Course Times'] ?? null);
    }
}
