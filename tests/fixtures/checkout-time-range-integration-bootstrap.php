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

$GLOBALS['intersoccer_test_icl_object_id_calls'] = [];
$GLOBALS['intersoccer_test_icl_translations'] = [];

if (!function_exists('icl_object_id')) {
    /**
     * @param int|string $element_id
     * @param string     $element_type
     * @param bool       $return_original_if_missing
     * @param string|null $language_code
     * @return int|null
     */
    function icl_object_id($element_id, $element_type = 'post', $return_original_if_missing = false, $language_code = null)
    {
        $element_id = (int) $element_id;
        $lang = $language_code !== null && $language_code !== ''
            ? (string) $language_code
            : (defined('ICL_LANGUAGE_CODE') ? (string) ICL_LANGUAGE_CODE : 'en');

        $GLOBALS['intersoccer_test_icl_object_id_calls'][] = [
            'element_id' => $element_id,
            'element_type' => (string) $element_type,
            'language_code' => $lang,
        ];

        $map = $GLOBALS['intersoccer_test_icl_translations'] ?? [];
        if (isset($map[$element_id][$lang])) {
            return (int) $map[$element_id][$lang];
        }

        return $return_original_if_missing ? $element_id : null;
    }
}

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

/**
 * @param string $slug
 * @param string $term_name
 */
function seed_checkout_time_range_course_product($slug, $term_name): void
{
    $parent = new CheckoutTimeRangeMockParentProduct(5001, [
        'pa_course-times',
        'pa_intersoccer-venues',
        'pa_age-group',
    ]);
    $variation = new CheckoutTimeRangeMockVariationProduct(5002, [
        'attribute_pa_course-times' => $slug,
        'attribute_pa_intersoccer-venues' => 'fixture-venue-a',
        'attribute_pa_age-group' => 'fixture-age-full-day',
    ]);
    $GLOBALS['intersoccer_test_products'] = [5001 => $parent, 5002 => $variation];
    $GLOBALS['intersoccer_test_terms'] = [
        8001 => ['name' => $term_name, 'slug' => $slug, 'taxonomy' => 'pa_course-times'],
        8101 => ['name' => 'Fixture Venue EN', 'slug' => 'fixture-venue-a', 'taxonomy' => 'pa_intersoccer-venues'],
        8102 => ['name' => 'Fixture Venue FR', 'slug' => 'fixture-venue-a-fr', 'taxonomy' => 'pa_intersoccer-venues'],
        8201 => ['name' => 'Fixture Age EN', 'slug' => 'fixture-age-full-day', 'taxonomy' => 'pa_age-group'],
        8202 => ['name' => 'Fixture Age FR', 'slug' => 'fixture-age-full-day-fr', 'taxonomy' => 'pa_age-group'],
    ];
    $GLOBALS['intersoccer_test_icl_translations'] = [
        8101 => ['en' => 8101, 'fr' => 8102],
        8201 => ['en' => 8201, 'fr' => 8202],
    ];
    $GLOBALS['intersoccer_test_icl_object_id_calls'] = [];
}
