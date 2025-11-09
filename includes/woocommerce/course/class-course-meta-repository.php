<?php
/**
 * Course meta repository responsible for building context objects.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('InterSoccer_Course_Meta_Repository')) {
    class InterSoccer_Course_Meta_Repository {
        /** @var array<int,bool> */
        protected static $primed_products = [];
        /** @var array<int,bool> */
        protected static $primed_post_ids = [];

        /**
         * Reset runtime state (useful for tests).
         */
        public static function reset_runtime_state() {
            self::$primed_products = [];
            self::$primed_post_ids = [];
        }

        /**
         * Inspect primed post IDs (testing/debug only).
         *
         * @return int[]
         */
        public static function get_primed_post_ids() {
            return array_keys(self::$primed_post_ids);
        }

        /**
         * Build a course context for the given product/variation combination.
         *
         * @param int $product_id
         * @param int $variation_id
         * @return InterSoccer_Course_Context
         */
        public function build_context($product_id, $variation_id, array $options = []) {
            $product_id = $product_id ? (int) $product_id : 0;
            $variation_id = $variation_id ? (int) $variation_id : 0;
            $pricing_subject_id = $variation_id ?: $product_id;

            $canonical_variation_id = $this->resolve_canonical_variation_id($variation_id);

            $this->prime_meta_for_product($product_id);
            $this->prime_meta_cache([$product_id, $variation_id, $canonical_variation_id]);

            $include_base_price = $options['include_base_price'] ?? true;
            if ($include_base_price) {
                $base_price = $this->resolve_base_price($product_id, $variation_id);
            } else {
                $base_price = isset($options['base_price']) ? (float) $options['base_price'] : 0.0;
            }
            $total_weeks = intersoccer_get_course_meta($pricing_subject_id, '_course_total_weeks', 0);
            $session_rate = intersoccer_get_course_meta($pricing_subject_id, '_course_weekly_discount', 0);
            $start_date = intersoccer_get_course_meta($pricing_subject_id, '_course_start_date', null);
            $holidays = intersoccer_get_course_meta($pricing_subject_id, '_course_holiday_dates', []);
            if (!is_array($holidays)) {
                $holidays = [];
            }

            if ($canonical_variation_id && $canonical_variation_id !== $variation_id) {
                $this->prime_meta_cache([$canonical_variation_id]);
            }

            return new InterSoccer_Course_Context(
                $product_id,
                $variation_id,
                $canonical_variation_id,
                $base_price,
                $total_weeks,
                $session_rate,
                $start_date,
                $holidays
            );
        }

        /**
         * Resolve the base price for the current context without triggering recursion.
         *
         * @param int $product_id
         * @param int $variation_id
         * @return float
         */
        protected function resolve_base_price($product_id, $variation_id) {
            $lookup_id = $variation_id ?: $product_id;
            if (!$lookup_id) {
                return 0.0;
            }

            $product = wc_get_product($lookup_id);
            if ($product) {
                return (float) $product->get_price();
            }

            return 0.0;
        }

        /**
         * Resolve canonical variation ID for WPML translations.
         *
         * @param int $variation_id
         * @return int
         */
        protected function resolve_canonical_variation_id($variation_id) {
            if (!$variation_id) {
                return 0;
            }

            if (!function_exists('apply_filters') || (!defined('ICL_SITEPRESS_VERSION') && !function_exists('icl_get_current_language'))) {
                return (int) $variation_id;
            }

            $default_lang = apply_filters('wpml_default_language', null);
            if (empty($default_lang)) {
                return (int) $variation_id;
            }

            $original_id = apply_filters('wpml_object_id', $variation_id, 'product_variation', true, $default_lang);
            if (!empty($original_id)) {
                return (int) $original_id;
            }

            return (int) $variation_id;
        }

        /**
         * Prime metadata cache for a product and its variations.
         *
         * @param int $product_id
         * @return void
         */
        protected function prime_meta_for_product($product_id) {
            $product_id = (int) $product_id;
            if (!$product_id || isset(self::$primed_products[$product_id])) {
                return;
            }

            self::$primed_products[$product_id] = true;

            if (!function_exists('update_postmeta_cache')) {
                return;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                return;
            }

            $post_ids = [$product_id];
            if (method_exists($product, 'get_children')) {
                $children = $product->get_children();
                if (is_array($children)) {
                    $post_ids = array_merge($post_ids, $children);
                }
            }

            $this->prime_meta_cache($post_ids);
        }

        /**
         * Prime the meta cache for specific post IDs.
         *
         * @param array<int> $post_ids
         * @return void
         */
        protected function prime_meta_cache(array $post_ids) {
            if (!function_exists('update_postmeta_cache')) {
                return;
            }

            $post_ids = array_unique(array_filter(array_map('intval', $post_ids)));
            if (empty($post_ids)) {
                return;
            }

            $uncached = array_filter($post_ids, function ($post_id) {
                return !isset(self::$primed_post_ids[$post_id]);
            });

            if (empty($uncached)) {
                return;
            }

            update_postmeta_cache($uncached);

            foreach ($uncached as $post_id) {
                self::$primed_post_ids[$post_id] = true;
            }
        }
    }
}
