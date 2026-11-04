<?php
/**
 * Bootstrap for order-create language parity tests (#136).
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
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__, 2) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__, 2) . '/includes/woocommerce/time-format.php';
require_once dirname(__DIR__, 2) . '/includes/woocommerce/checkout-calculations.php';
require_once dirname(__DIR__, 2) . '/includes/woocommerce/order-meta-contract.php';

if (!class_exists('OrderCreateLanguageMockOrder')) {
    class OrderCreateLanguageMockOrder
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
}

if (!class_exists('OrderCreateLanguageMockTaxonomyAttribute')) {
    class OrderCreateLanguageMockTaxonomyAttribute
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
}

if (!class_exists('OrderCreateLanguageMockParentProduct')) {
    class OrderCreateLanguageMockParentProduct
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
                $out[$taxonomy] = new OrderCreateLanguageMockTaxonomyAttribute();
            }

            return $out;
        }
    }
}

if (!class_exists('OrderCreateLanguageMockVariationProduct')) {
    class OrderCreateLanguageMockVariationProduct
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
            $key = strpos((string) $name, 'pa_') === 0 ? 'attribute_' . $name : (string) $name;

            return $this->variation_attributes[$key] ?? '';
        }
    }
}

if (!function_exists('order_create_language_register_wpml_term_twins')) {
    /**
     * @param array<int,array<string,mixed>> $terms
     * @param array<int,array<string,int>>     $en_map term_id => [ lang => twin_id ]
     */
    function order_create_language_register_wpml_term_twins(array $terms, array $en_map): void
    {
        $GLOBALS['intersoccer_test_terms'] = $terms;
        $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'] = true;
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'all';

        MockFilters::reset();
        MockFilters::$filters['wpml_default_language'] = static fn () => 'en';
        MockFilters::$filters['wpml_current_language'] = static fn () => 'en';
        MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) use ($en_map) {
            unset($type, $return_original);
            $id = (int) $id;
            if ($lang === null || $lang === '' || $lang === 'en') {
                foreach ($en_map as $canonical_en => $twins) {
                    if ($id === (int) $canonical_en) {
                        return (int) $canonical_en;
                    }
                    foreach ($twins as $twin_id) {
                        if ($id === (int) $twin_id) {
                            return (int) $canonical_en;
                        }
                    }
                }
            }

            return $id;
        };

        $icl = [];
        foreach ($en_map as $en_id => $twins) {
            $icl[(int) $en_id] = array_merge(['en' => (int) $en_id], $twins);
            foreach ($twins as $lang => $twin_id) {
                $icl[(int) $twin_id][$lang] = (int) $twin_id;
                $icl[(int) $twin_id]['en'] = (int) $en_id;
            }
        }
        $GLOBALS['intersoccer_test_icl_translations'] = $icl;
    }
}

if (!function_exists('icl_object_id')) {
    function icl_object_id($element_id, $element_type = 'post', $return_original_if_missing = false, $language_code = null)
    {
        $element_id = (int) $element_id;
        $lang = $language_code !== null && $language_code !== ''
            ? (string) $language_code
            : (defined('ICL_LANGUAGE_CODE') ? (string) ICL_LANGUAGE_CODE : 'en');

        $map = $GLOBALS['intersoccer_test_icl_translations'] ?? [];
        if (isset($map[$element_id][$lang])) {
            return (int) $map[$element_id][$lang];
        }

        return $return_original_if_missing ? $element_id : null;
    }
}

if (!class_exists('TestProductTypeRegistry')) {
    class TestProductTypeRegistry
    {
        /** @var array<int,string|null> */
        private static $types = [];

        public static function set($product_id, $type): void
        {
            self::$types[(int) $product_id] = $type;
        }

        public static function get($product_id)
        {
            $product_id = (int) $product_id;
            return array_key_exists($product_id, self::$types) ? self::$types[$product_id] : null;
        }

        public static function reset(): void
        {
            self::$types = [];
        }
    }
}

if (!function_exists('intersoccer_get_product_type')) {
    function intersoccer_get_product_type($product_id)
    {
        if (class_exists('TestProductTypeRegistry') && TestProductTypeRegistry::get($product_id) !== null) {
            return TestProductTypeRegistry::get($product_id);
        }

        return 'course';
    }
}

if (!function_exists('wc_attribute_label')) {
    function wc_attribute_label($name, $product = null)
    {
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
