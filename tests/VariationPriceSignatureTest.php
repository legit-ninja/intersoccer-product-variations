<?php
/**
 * Regression: #166 — woocommerce_get_variation_price callback signature must match WooCommerce.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';

class VariationPriceSignatureTest extends TestCase {
    private function setProductType(int $product_id, string $type): void {
        delete_transient('intersoccer_type_' . $product_id);
        set_transient('intersoccer_type_' . $product_id, $type, 3600);
    }

    /**
     * @return object{get_id: callable, get_variation_prices: callable}
     */
    private function makeVariableProduct(int $product_id, array $variation_prices) {
        return new class($product_id, $variation_prices) {
            private $id;
            private $variation_prices;

            public function __construct(int $id, array $variation_prices) {
                $this->id = $id;
                $this->variation_prices = $variation_prices;
            }

            public function get_id() {
                return $this->id;
            }

            public function get_variation_prices($for_display = false) {
                return $this->variation_prices;
            }
        };
    }

    public static function embedVariationPriceProvider(): array {
        return [
            'camp min' => ['camp', 5001, 'min', false, '120'],
            'camp max display' => ['camp', 5002, 'max', true, '200'],
            'course min' => ['course', 6001, 'min', false, '100'],
            'course max display' => ['course', 6002, 'max', true, '150'],
        ];
    }

    #[DataProvider('embedVariationPriceProvider')]
    public function testWoocommerceGetVariationPriceFilterMatchesWooSignature(
        string $product_type,
        int $product_id,
        string $min_or_max,
        bool $for_display,
        string $incoming_price
    ): void {
        $this->assertTrue(function_exists('intersoccer_modify_variation_price'));

        $this->setProductType($product_id, $product_type);

        $variation_prices = [
            'price' => [101 => '100', 102 => '150'],
            'regular_price' => [101 => '100', 102 => '150'],
            'sale_price' => [101 => '100', 102 => '150'],
        ];
        $product = $this->makeVariableProduct($product_id, $variation_prices);

        $result = intersoccer_modify_variation_price(
            $incoming_price,
            $product,
            $min_or_max,
            $for_display
        );

        $this->assertSame($incoming_price, $result);
    }

    /**
     * Shop/archive variable price ranges use woocommerce_variation_prices, not woocommerce_get_variation_price.
     */
    public function testCourseShopRangeStillUsesVariationPricesHook() {
        $src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php');
        $this->assertStringContainsString(
            "add_filter('woocommerce_variation_prices', 'intersoccer_modify_variation_prices'",
            $src
        );
        $this->assertStringContainsString(
            'InterSoccer_Course::calculate_price($product_id, $variation_id)',
            $src
        );
        $this->assertStringContainsString('function intersoccer_modify_variation_price($price, $product, $min_or_max, $for_display)', $src);
        $this->assertStringContainsString('return $price;', $src);
    }
}
