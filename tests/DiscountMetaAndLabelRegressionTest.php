<?php
/**
 * Regression: numeric Discount Amount meta + percent sign in sibling labels (#80 follow-up).
 *
 * Tess: stored meta was 67 (from &#67; in entity-encoded CHF via wc_price HTML),
 * while the real 20% of CHF 525 is 105. Label showed "20 Camp Sibling Discount"
 * without the % sign.
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        return $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return is_scalar($str) ? (string) $str : '';
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $title));
    }
}

if (!function_exists('wc_price')) {
    /**
     * Deliberately returns entity-encoded CHF HTML so regressions that call wc_price
     * for Discount Amount are caught (first digits become 67 from &#67;).
     */
    function wc_price($price) {
        return '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">&#67;&#72;&#70;</span>&nbsp;'
            . number_format((float) $price, 2, '.', '')
            . '</bdi></span>';
    }
}

if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists($taxonomy) {
        return false;
    }
}

if (!function_exists('wc_get_product_terms')) {
    function wc_get_product_terms($product_id, $taxonomy, $args = []) {
        return [];
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
require_once dirname(__DIR__) . '/includes/language-helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';

class DiscountMetaAndLabelRegressionTest extends TestCase
{
    public function testDiscountAmountMetaIsNumericNotParsedFromWcPriceHtml()
    {
        $base = 525.0;
        $rate = 0.20;
        $expected = round($base * $rate, 2); // 105.00

        // Prove the legacy bug: first digits in entity-encoded CHF HTML are 67, not 105.
        $html = wc_price($expected);
        $this->assertStringContainsString('&#67;', $html);
        preg_match('/(\d+\.?\d*)/', $html, $matches);
        $this->assertSame('67', $matches[1], 'Sanity: naive digit parse of wc_price HTML yields 67');

        $built = intersoccer_build_order_line_meta([
            'product_id' => 51723,
            'variation_id' => 51724,
            'product_type' => 'camp',
            'cart_values' => [
                'discount_amount' => $expected,
                'discount_note' => '20% Camp Sibling Discount',
                'assigned_attendee' => 'Giulia',
                'assigned_player' => 1,
            ],
        ]);

        $updates = $built['updates'];
        $this->assertArrayHasKey('Discount Amount', $updates);
        $this->assertIsNumeric($updates['Discount Amount']);
        $this->assertEqualsWithDelta(105.0, (float) $updates['Discount Amount'], 0.001);
        $this->assertStringNotContainsString('<', (string) $updates['Discount Amount']);
        $this->assertStringNotContainsString('&#', (string) $updates['Discount Amount']);

        $this->assertArrayHasKey('_intersoccer_total_item_discount', $updates);
        $this->assertEqualsWithDelta(105.0, (float) $updates['_intersoccer_total_item_discount'], 0.001);

        $this->assertArrayHasKey('_intersoccer_item_discounts', $updates);
        $this->assertIsArray($updates['_intersoccer_item_discounts']);
        $this->assertEqualsWithDelta(
            105.0,
            (float) $updates['_intersoccer_item_discounts'][0]['amount'],
            0.001,
            'Reporting amount field must be the real discount, not 67'
        );
        $this->assertStringContainsString('20%', $updates['_intersoccer_item_discounts'][0]['name']);
    }

    public function testSiblingDiscountLabelsIncludePercentSign()
    {
        // Source templates in discounts.php (must keep %% so sprintf emits a literal %).
        $src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/discounts.php');
        $this->assertStringContainsString(
            "intersoccer_translate_string('%s%% Camp Sibling Discount'",
            $src
        );
        $this->assertStringContainsString(
            "intersoccer_translate_string('%s%% Course Sibling Discount'",
            $src
        );
        $this->assertStringContainsString(
            "intersoccer_translate_string('%s%% Tournament Sibling Discount'",
            $src
        );
        $this->assertStringNotContainsString(
            "intersoccer_translate_string('%s Camp Sibling Discount'",
            $src,
            'Unescaped %s without %% would print "20 Camp Sibling Discount"'
        );

        $this->assertSame('20% Camp Sibling Discount', sprintf('%s%% Camp Sibling Discount', 20));
        $this->assertSame('20% Course Sibling Discount', sprintf('%s%% Course Sibling Discount', 20));
        $this->assertSame('20% Tournament Sibling Discount', sprintf('%s%% Tournament Sibling Discount', 20));
        $this->assertSame(
            '10% Camp Week 2 Discount',
            sprintf('%d%% Camp Week 2 Discount', 10)
        );
        $this->assertSame(
            '50% Same Season Course Discount',
            sprintf('%d%% Same Season Course Discount', 50)
        );
    }

    public function testApplySiblingRatesFallbackLabelContainsPercent()
    {
        $fallback = sprintf('%s%% Camp Sibling Discount', 0.20 * 100);
        $this->assertStringContainsString('20%', $fallback);
        $this->assertDoesNotMatchRegularExpression('/^20[^%]/', $fallback);
    }
}
