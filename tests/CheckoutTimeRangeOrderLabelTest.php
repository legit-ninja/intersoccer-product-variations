<?php
/**
 * Checkout order-line camp/course time labels use per-language range formatting.
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/time-format.php';

class CheckoutTimeRangeOrderLabelTest extends TestCase
{
    private const EN_DASH = "\u{2013}";

    protected function setUp(): void
    {
        parent::setUp();
        MockFilters::reset();
        MockFilters::$filters['wpml_current_language'] = static fn () => 'en';
    }

    protected function tearDown(): void
    {
        MockFilters::reset();
        parent::tearDown();
    }

    public function test_compact_camp_time_fr_order_uses_fr_format(): void
    {
        $this->assertSame(
            '11h15' . self::EN_DASH . '12h45',
            intersoccer_format_time_range('1115-1245', 'fr')
        );
    }

    public function test_compact_camp_time_en_order_uses_en_format(): void
    {
        $this->assertSame(
            '11:15' . self::EN_DASH . '12:45',
            intersoccer_format_time_range('1115-1245', 'en')
        );
    }

    public function test_compact_camp_time_de_order_uses_de_format(): void
    {
        $this->assertSame(
            '11:15' . self::EN_DASH . '12:45',
            intersoccer_format_time_range('1115-1245', 'de')
        );
    }

    public function test_already_formatted_fr_stays_stable(): void
    {
        $formatted = '9h30' . self::EN_DASH . '11h00';
        $this->assertSame($formatted, intersoccer_format_time_range($formatted, 'fr'));
    }

    public function test_hyphenated_fr_name_normalises_to_en_dash_separator(): void
    {
        $this->assertSame(
            '9h30' . self::EN_DASH . '11h00',
            intersoccer_format_time_range('9h30-11h00', 'fr')
        );
    }

    public function test_non_time_label_unchanged(): void
    {
        $this->assertSame('Full Week', intersoccer_format_time_range('Full Week', 'fr'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_order_language_overrides_request_language_for_parent_attributes(): void
    {
        require dirname(__DIR__) . '/tests/fixtures/checkout-time-range-integration-bootstrap.php';

        seed_checkout_time_range_camp_product('1115-1245', '1115-1245');

        $order = new CheckoutTimeRangeMockOrder('fr');
        $attributes = intersoccer_get_parent_product_attributes(5001, 5002, $order);
        $this->assertSame(
            '11h15' . self::EN_DASH . '12h45',
            $attributes['Camp Times'] ?? null
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_build_order_line_meta_keeps_slug_contract_meta(): void
    {
        require dirname(__DIR__) . '/tests/fixtures/checkout-time-range-integration-bootstrap.php';

        seed_checkout_time_range_camp_product('1115-1245', '1115-1245');

        $built = intersoccer_build_order_line_meta([
            'order' => new CheckoutTimeRangeMockOrder('fr'),
            'product_id' => 5001,
            'variation_id' => 5002,
            'product_type' => 'camp',
            'cart_values' => [],
        ]);

        $this->assertSame(
            '11h15' . self::EN_DASH . '12h45',
            $built['updates']['Camp Times'] ?? null
        );
        $slug_meta = intersoccer_collect_variation_taxonomy_meta(5002);
        $this->assertSame('1115-1245', $slug_meta['attribute_pa_camp-times'] ?? null);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_booking_type_label_unchanged_on_fr_order(): void
    {
        require dirname(__DIR__) . '/tests/fixtures/checkout-time-range-integration-bootstrap.php';

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
        $this->assertSame('10h00' . self::EN_DASH . '17h00', $attributes['Camp Times'] ?? null);
    }
}
