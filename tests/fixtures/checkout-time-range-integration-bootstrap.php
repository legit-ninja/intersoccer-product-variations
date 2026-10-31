<?php
/**
 * Minimal bootstrap for checkout time-range integration tests (separate PHPUnit process).
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

if (!defined('ICL_LANGUAGE_CODE')) {
    define('ICL_LANGUAGE_CODE', 'en');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__, 2) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__, 2) . '/includes/woocommerce/time-format.php';
require_once dirname(__DIR__, 2) . '/includes/woocommerce/checkout-calculations.php';
require_once dirname(__DIR__, 2) . '/includes/woocommerce/order-meta-contract.php';

if (!function_exists('wc_attribute_label')) {
    function wc_attribute_label($name, $product = null) {
        unset($product);
        $label = (string) $name;
        if (function_exists('intersoccer_attr_order_meta_label_map')) {
            $map = intersoccer_attr_order_meta_label_map();
            if (isset($map[$name])) {
                $label = (string) $map[$name];
            }
        }

        return $label;
    }
}

MockFilters::reset();
MockFilters::$filters['wpml_current_language'] = static fn () => 'en';

class CheckoutTimeRangeMockOrder
{
    /** @var string */
    private $lang;

    public function __construct(string $lang)
    {
        $this->lang = $lang;
    }

    public function get_meta($key, $single = true)
    {
        unset($single);

        return $key === 'wpml_language' ? $this->lang : '';
    }

    public function get_customer_id()
    {
        return 0;
    }
}

class CheckoutTimeRangeMockTaxonomyAttribute
{
    public function is_taxonomy()
    {
        return true;
    }

    public function get_options()
    {
        return [];
    }
}

class CheckoutTimeRangeMockParentProduct
{
    /** @var int */
    private $id;

    /** @var string[] */
    private $taxonomies;

    /**
     * @param int      $id
     * @param string[] $taxonomies
     */
    public function __construct($id, array $taxonomies)
    {
        $this->id = (int) $id;
        $this->taxonomies = $taxonomies;
    }

    public function get_attributes()
    {
        $out = [];
        foreach ($this->taxonomies as $taxonomy) {
            $out[$taxonomy] = new CheckoutTimeRangeMockTaxonomyAttribute();
        }

        return $out;
    }
}

class CheckoutTimeRangeMockVariationProduct
{
    /** @var int */
    private $id;

    /** @var array<string,string> */
    private $variation_attributes;

    /**
     * @param int                  $id
     * @param array<string,string> $variation_attributes
     */
    public function __construct($id, array $variation_attributes)
    {
        $this->id = (int) $id;
        $this->variation_attributes = $variation_attributes;
    }

    public function get_variation_attributes()
    {
        return $this->variation_attributes;
    }

    public function get_attribute($name)
    {
        unset($name);

        return '';
    }
}

/**
 * @param string $slug
 * @param string $term_name
 */
function seed_checkout_time_range_camp_product($slug, $term_name): void
{
    $parent = new CheckoutTimeRangeMockParentProduct(5001, ['pa_camp-times']);
    $variation = new CheckoutTimeRangeMockVariationProduct(5002, [
        'attribute_pa_camp-times' => $slug,
    ]);
    $GLOBALS['intersoccer_test_products'] = [5001 => $parent, 5002 => $variation];
    $GLOBALS['intersoccer_test_terms'] = [
        8001 => ['name' => $term_name, 'slug' => $slug, 'taxonomy' => 'pa_camp-times'],
    ];
}
