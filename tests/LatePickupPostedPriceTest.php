<?php
/**
 * Posted late-pickup cost and base price must not override the server price.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-camp.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';
require_once dirname(__DIR__) . '/includes/woocommerce/late-pickup.php';

class LatePickupPostedPriceTest extends TestCase
{
    private $product_id = 88000;
    private $variation_id = 88001;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_options']['intersoccer_late_pickup_per_day'] = 25.0;
        $GLOBALS['intersoccer_test_options']['intersoccer_late_pickup_full_week'] = 90.0;
        update_post_meta($this->variation_id, '_intersoccer_enable_late_pickup', 'yes');
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $GLOBALS['intersoccer_test_product_type'] = null;
        parent::tearDown();
    }

    /**
     * Run both cart-item filters in the same order WordPress does.
     */
    private function capture_cart_item(): array
    {
        $cart_item_data = [];
        $cart_item_data = intersoccer_add_custom_cart_item_data($cart_item_data, $this->product_id, $this->variation_id);
        return intersoccer_add_late_pickup_data($cart_item_data, $this->product_id, $this->variation_id);
    }

    public function testTamperedLatePickupCostAndBasePriceAreIgnored(): void
    {
        $_POST['late_pickup_type'] = 'single-days';
        $_POST['late_pickup_days'] = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $_POST['late_pickup_cost'] = '0';
        $_POST['base_price'] = '1';

        $cart_item_data = $this->capture_cart_item();

        $this->assertSame('single-days', $cart_item_data['late_pickup_type']);
        $this->assertCount(5, $cart_item_data['late_pickup_days']);
        $this->assertEquals(90.0, (float) $cart_item_data['late_pickup_cost'], 'Five single days use the full-week rate, not the posted cost');
        $this->assertEquals(100.0, (float) $cart_item_data['base_price'], 'Base price comes from the product, not the posted base_price');
        $this->assertNotEquals(0.0, (float) $cart_item_data['late_pickup_cost']);
        $this->assertNotEquals(1.0, (float) $cart_item_data['base_price']);
    }

    public function testFewerThanFiveSingleDaysUseThePerDayRate(): void
    {
        $_POST['late_pickup_type'] = 'single-days';
        $_POST['late_pickup_days'] = ['Monday', 'Wednesday', 'Friday'];
        $_POST['late_pickup_cost'] = '1';
        $_POST['base_price'] = '1';

        $cart_item_data = $this->capture_cart_item();

        $this->assertEquals(75.0, (float) $cart_item_data['late_pickup_cost']);
        $this->assertEquals(100.0, (float) $cart_item_data['base_price']);
    }
}
