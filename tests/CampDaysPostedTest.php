<?php
/**
 * Posted single-day camp days must be real days of that variation.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-camp.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';
require_once dirname(__DIR__) . '/includes/woocommerce/late-pickup.php';
require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

class CampDaysPostedTest extends TestCase
{
    private $product_id = 89000;
    private $variation_id = 89001;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $GLOBALS['wc_notices'] = [];
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_options']['intersoccer_late_pickup_per_day'] = 25.0;
        $GLOBALS['intersoccer_test_options']['intersoccer_late_pickup_full_week'] = 90.0;
        update_post_meta($this->variation_id, '_intersoccer_enable_late_pickup', 'yes');
        update_post_meta($this->variation_id, 'attribute_pa_booking-type', 'single-days');
        update_post_meta($this->variation_id, '_intersoccer_camp_days_available', [
            'Monday' => true,
            'Tuesday' => true,
            'Wednesday' => true,
            'Thursday' => true,
            'Friday' => true,
        ]);
        $variation_id = $this->variation_id;
        $GLOBALS['intersoccer_test_products'][$variation_id] = new class($variation_id) {
            private $id;
            public function __construct($id) { $this->id = (int) $id; }
            public function get_id() { return $this->id; }
            public function get_price() { return 55.0; }
            public function get_parent_id() { return 0; }
            public function get_name() { return 'Camp'; }
            public function get_slug() { return 'camp'; }
            public function get_attributes() { return []; }
            public function get_attribute($name) { return ''; }
            public function is_type($type) { return false; }
        };
        $_POST['player_assignment'] = '1';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $GLOBALS['intersoccer_test_product_type'] = null;
        unset($GLOBALS['intersoccer_test_products'][$this->variation_id]);
        parent::tearDown();
    }

    /**
     * Run both cart-item filters in the same order WordPress does.
     */
    private function capture_cart_item(): array
    {
        $cart_item_data = intersoccer_add_custom_cart_item_data([], $this->product_id, $this->variation_id);
        return intersoccer_add_late_pickup_data($cart_item_data, $this->product_id, $this->variation_id);
    }

    public function testCommaSeparatedSingleEntryIsNotPricedOrStoredAsFiveDays(): void
    {
        $raw = 'Monday, Tuesday, Wednesday, Thursday, Friday';
        $_POST['camp_days'] = [$raw];
        $_POST['variation_id'] = (string) $this->variation_id;
        $_POST['late_pickup_cost'] = '0';
        $_POST['base_price'] = '1';

        $passed = intersoccer_validate_cart_item(true, $this->product_id, 1, $this->variation_id, null, null);
        $this->assertFalse($passed, 'One posted string listing every weekday is not a camp day');
        $this->assertFalse(InterSoccer_Camp::validate_single_day(true, $this->product_id, 1));

        $cart_item_data = $this->capture_cart_item();
        $stored = $cart_item_data['camp_days'] ?? [];
        $this->assertNotContains($raw, $stored);
        $this->assertCount(0, $stored);
        $this->assertNotEquals(275.0, (float) $cart_item_data['base_price'], 'Must not charge five days for one invalid entry');
        $this->assertNotEquals(1.0, (float) $cart_item_data['base_price'], 'Posted base_price is ignored');

        $built = intersoccer_build_order_line_meta([
            'product_id' => $this->product_id,
            'variation_id' => $this->variation_id,
            'product_type' => 'camp',
            'cart_values' => [
                'camp_days' => [$raw],
            ],
        ]);
        $this->assertArrayNotHasKey('Days Selected', $built['updates']);
    }

    public function testDuplicateRealDaysAreChargedOnce(): void
    {
        $_POST['camp_days'] = ['Monday', 'Monday', 'lundi'];
        $_POST['variation_id'] = (string) $this->variation_id;
        $_POST['base_price'] = '1';

        $passed = intersoccer_validate_cart_item(true, $this->product_id, 1, $this->variation_id, null, null);
        $this->assertTrue($passed);

        $cart_item_data = $this->capture_cart_item();
        $this->assertSame(['Monday'], $cart_item_data['camp_days']);
        $this->assertEquals(55.0, (float) $cart_item_data['base_price']);
        $this->assertNotEquals(1.0, (float) $cart_item_data['base_price']);
    }

    public function testDaysOutsideTheVariationAreDropped(): void
    {
        update_post_meta($this->variation_id, '_intersoccer_camp_days_available', [
            'Monday' => false,
            'Wednesday' => true,
        ]);
        $_POST['camp_days'] = ['Monday', 'mercredi', 'Saturday'];
        $_POST['variation_id'] = (string) $this->variation_id;

        $cart_item_data = $this->capture_cart_item();

        $this->assertSame(['Wednesday'], $cart_item_data['camp_days']);
        $this->assertEquals(55.0, (float) $cart_item_data['base_price']);
    }

    public function testFiveSeparateDaysUseFullWeekLatePickupAndIgnorePostedPrices(): void
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $_POST['camp_days'] = $days;
        $_POST['variation_id'] = (string) $this->variation_id;
        $_POST['late_pickup_type'] = 'single-days';
        $_POST['late_pickup_days'] = $days;
        $_POST['late_pickup_cost'] = '0';
        $_POST['base_price'] = '1';

        $passed = intersoccer_validate_cart_item(true, $this->product_id, 1, $this->variation_id, null, null);
        $this->assertTrue($passed, 'Five separate real days still add');

        $cart_item_data = $this->capture_cart_item();

        $this->assertSame($days, $cart_item_data['camp_days']);
        $this->assertEquals(275.0, (float) $cart_item_data['base_price'], 'Five days are priced per day');
        $this->assertEquals(90.0, (float) $cart_item_data['late_pickup_cost'], 'Five single days use the full-week late pickup rate');
        $this->assertNotEquals(0.0, (float) $cart_item_data['late_pickup_cost']);
        $this->assertNotEquals(1.0, (float) $cart_item_data['base_price']);
        $this->assertNotEquals(125.0, (float) $cart_item_data['late_pickup_cost'], 'Must not charge five times the per-day late pickup rate');
    }
}
