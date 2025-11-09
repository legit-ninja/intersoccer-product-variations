<?php
/**
 * File: product-course.php
 * Description: Course-specific logic for InterSoccer WooCommerce products, including date/session calculations.
 * Dependencies: WooCommerce, product-types.php (for type detection)
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/course/class-course-context.php';
require_once __DIR__ . '/course/class-course-meta-repository.php';
require_once __DIR__ . '/course/class-course-schedule-calculator.php';
require_once __DIR__ . '/course/class-course-pricing-calculator.php';

/**
 * Get course metadata with WPML fallback to original language
 *
 * @param int $variation_id Variation ID
 * @param string $meta_key Metadata key
 * @param mixed $default Default value
 * @return mixed Metadata value
 */
function intersoccer_get_course_meta($variation_id, $meta_key, $default = null) {
    // First try to get from current variation
    $value = get_post_meta($variation_id, $meta_key, true);

    // If value exists and is not empty, return it
    if ($value !== '' && $value !== null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug('Found {meta_key} directly on variation {variation_id}: {value}', [
                'meta_key' => $meta_key,
                'variation_id' => $variation_id,
                'value' => $value,
            ]);
        }
        return $value;
    }

    // If WPML is active, try to get from original language variation
    if (defined('ICL_SITEPRESS_VERSION') || function_exists('icl_get_current_language')) {
        // Get the default language
        $default_lang = apply_filters('wpml_default_language', null) ?: 'en';

        $original_variation_id = apply_filters('wpml_object_id', $variation_id, 'product_variation', true, $default_lang);

        if ($original_variation_id && $original_variation_id !== $variation_id) {
            $original_value = get_post_meta($original_variation_id, $meta_key, true);
            if ($original_value !== '' && $original_value !== null) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    intersoccer_debug('Using metadata from original variation {original} for {meta_key} on translated variation {variation}: {value}', [
                        'original' => $original_variation_id,
                        'meta_key' => $meta_key,
                        'variation' => $variation_id,
                        'value' => $original_value,
                    ]);
                }
                return $original_value;
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    intersoccer_debug('Original variation {original} also has empty {meta_key}', [
                        'original' => $original_variation_id,
                        'meta_key' => $meta_key,
                    ]);
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_warning('Could not find original variation for {variation_id} (original_id: {original})', [
                    'variation_id' => $variation_id,
                    'original' => $original_variation_id ?: 'null',
                ]);
            }
        }
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug('WPML not detected for metadata fallback');
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        intersoccer_debug('Returning default for {meta_key} on variation {variation}: {value}', [
            'meta_key' => $meta_key,
            'variation' => $variation_id,
            'value' => $default,
        ]);
    }
    return $default;
}

/**
 * Class to handle course-specific calculations.
 */
if (!class_exists('InterSoccer_Course')) {
class InterSoccer_Course {

    /**
     * Runtime cache for course prices keyed by canonical variation and meta signature.
     *
     * @var array<string,float>
     */
    protected static $price_cache = [];
    /**
     * Runtime stats for instrumentation during a single request.
     *
     * @var array<string,array<int,int>>
     */
    protected static $runtime_stats = [];
    /** @var InterSoccer_Course_Meta_Repository|null */
    protected static $meta_repository_instance = null;
    /** @var InterSoccer_Course_Schedule_Calculator|null */
    protected static $schedule_calculator_instance = null;
    /** @var InterSoccer_Course_Pricing_Calculator|null */
    protected static $pricing_calculator_instance = null;

    /**
     * Reset runtime cache (useful for tests and long-running processes).
     */
    public static function reset_runtime_cache() {
        self::$price_cache = [];
        self::$runtime_stats = [];
        self::$meta_repository_instance = null;
        self::$schedule_calculator_instance = null;
        self::$pricing_calculator_instance = null;
        if (class_exists('InterSoccer_Course_Meta_Repository')) {
            InterSoccer_Course_Meta_Repository::reset_runtime_state();
        }
    }

    /**
     * Retrieve runtime instrumentation stats (primarily for tests).
     *
     * @return array<string,array<int,int>>
     */
    public static function get_runtime_stats() {
        return self::$runtime_stats;
    }

    /**
     * Increment a runtime stat counter for the given canonical variation.
     *
     * @param string $bucket Stat name (e.g., price_computations, cache_hits).
     * @param int    $canonical_variation_id Canonical variation identifier.
     * @return void
     */
    protected static function record_runtime_stat($bucket, $canonical_variation_id) {
        if (!isset(self::$runtime_stats[$bucket])) {
            self::$runtime_stats[$bucket] = [];
        }
        if (!isset(self::$runtime_stats[$bucket][$canonical_variation_id])) {
            self::$runtime_stats[$bucket][$canonical_variation_id] = 0;
        }
        self::$runtime_stats[$bucket][$canonical_variation_id]++;
    }

    protected static function meta_repository() {
        if (!self::$meta_repository_instance) {
            self::$meta_repository_instance = new InterSoccer_Course_Meta_Repository();
        }
        return self::$meta_repository_instance;
    }

    protected static function schedule_calculator() {
        if (!self::$schedule_calculator_instance) {
            self::$schedule_calculator_instance = new InterSoccer_Course_Schedule_Calculator();
        }
        return self::$schedule_calculator_instance;
    }

    protected static function pricing_calculator() {
        if (!self::$pricing_calculator_instance) {
            self::$pricing_calculator_instance = new InterSoccer_Course_Pricing_Calculator(self::schedule_calculator());
        }
        return self::$pricing_calculator_instance;
    }

    protected static function resolve_product_id_for_context($product_id, $variation_id) {
        if ($product_id) {
            return (int) $product_id;
        }
        if ($variation_id) {
            $product = wc_get_product($variation_id);
            if ($product && method_exists($product, 'get_parent_id')) {
                $parent = $product->get_parent_id();
                if ($parent) {
                    return (int) $parent;
                }
            }
        }
        return 0;
    }

    protected static function build_context($product_id, $variation_id, array $options = []) {
        $resolved_product_id = self::resolve_product_id_for_context($product_id, $variation_id);
        return self::meta_repository()->build_context($resolved_product_id, $variation_id, $options);
    }

    /**
     * Resolve a canonical variation ID for WPML translations.
     *
     * @param int $variation_id Variation ID (may be translated).
     * @return int Canonical/original variation ID when available.
     */
    protected static function get_canonical_variation_id($variation_id) {
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
     * Get the course day number from variation attribute (1=Monday, 7=Sunday).
     *
     * @param int $variation_id Variation ID.
     * @return int Course day number or 0 if invalid.
     */
    public static function get_course_day($variation_id) {
        $context = self::build_context(0, $variation_id, ['include_base_price' => false]);
        $course_day_num = self::schedule_calculator()->get_course_day($context);

        if ($course_day_num === 0) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_warning('Invalid course day slug for variation {variation}', ['variation' => $variation_id]);
            }
        }

        return $course_day_num;
    }

    /**
     * Calculate the end date for a course, accounting for holidays.
     *
     * IMPORTANT: The "needed sessions" are the actual number of events the customer
     * will participate in. Holidays are ADDED TO (not subtracted from) the sessions needed
     * in order to determine the end date. This ensures the customer receives the full
     * number of paid sessions even when holidays occur.
     *
     * @param int $variation_id Variation ID.
     * @param int $total_weeks Total sessions the customer should receive.
     * @return string End date in 'Y-m-d' or empty.
     */
    public static function calculate_end_date($variation_id, $total_weeks) {
        $context = self::build_context(0, $variation_id, ['include_base_price' => false]);

        if ($total_weeks && $total_weeks !== $context->get_total_weeks()) {
            $context = new InterSoccer_Course_Context(
                $context->get_product_id(),
                $context->get_variation_id(),
                $context->get_canonical_variation_id(),
                $context->get_base_price(),
                max(0, (int) $total_weeks),
                $context->get_session_rate(),
                $context->get_start_date(),
                $context->get_holidays()
            );
        }

        $end_date = self::schedule_calculator()->calculate_end_date($context);

        if ($end_date) {
            update_post_meta($variation_id, '_end_date', $end_date);
            return $end_date;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_warning('Failed to calculate end_date for variation {variation}', ['variation' => $variation_id]);
        }

        return '';
    }

    /**
     * Calculate total sessions for a course.
     *
     * @param int $variation_id Variation ID.
     * @param int $total_weeks Total weeks.
     * @return int Total sessions.
     */
    public static function calculate_total_sessions($variation_id, $total_weeks) {
        $context = self::build_context(0, $variation_id, ['include_base_price' => false]);

        if ($total_weeks && $total_weeks !== $context->get_total_weeks()) {
            $context = new InterSoccer_Course_Context(
                $context->get_product_id(),
                $context->get_variation_id(),
                $context->get_canonical_variation_id(),
                $context->get_base_price(),
                max(0, (int) $total_weeks),
                $context->get_session_rate(),
                $context->get_start_date(),
                $context->get_holidays()
            );
        }

        return self::schedule_calculator()->calculate_total_sessions($context);
    }

    /**
     * Calculate remaining sessions from current date, skipping holidays.
     *
     * @param int $variation_id Variation ID.
     * @param int $total_weeks Total weeks.
     * @return int Remaining sessions.
     */
    public static function calculate_remaining_sessions($variation_id, $total_weeks) {
        intersoccer_debug('calculate_remaining_sessions called for variation {variation} with total_weeks {weeks}', [
            'variation' => $variation_id,
            'weeks' => $total_weeks,
        ]);

        $context = self::build_context(0, $variation_id, [
            'include_base_price' => false,
            'base_price' => 0,
        ]);

        if ($total_weeks && $total_weeks !== $context->get_total_weeks()) {
            // Preserve legacy behaviour where callers pass an explicit total weeks value.
            $context = new InterSoccer_Course_Context(
                $context->get_product_id(),
                $context->get_variation_id(),
                $context->get_canonical_variation_id(),
                $context->get_base_price(),
                max(0, (int) $total_weeks),
                $context->get_session_rate(),
                $context->get_start_date(),
                $context->get_holidays()
            );
        }

        $remaining = self::schedule_calculator()->calculate_remaining_sessions($context);

        intersoccer_debug('Final remaining sessions for variation {variation}: {remaining} (out of {total} total)', [
            'variation' => $variation_id,
            'remaining' => $remaining,
            'total' => $context->get_total_weeks(),
        ]);
        return $remaining;
    }

    /**
     * Calculate course price (based on session rate multiplied by remaining sessions).
     *
     * @param int $product_id Product ID.
     * @param int $variation_id Variation ID.
     * @param int $remaining_sessions Remaining sessions (calculated if null).
     * @return float Calculated price.
     */
    public static function calculate_price($product_id, $variation_id, $remaining_sessions = null) {
        $context = self::build_context($product_id, $variation_id);
        $current_date_for_key = function_exists('current_time') ? current_time('Y-m-d') : date('Y-m-d');
        $cache_key = $context->build_cache_key($current_date_for_key);
        $canonical_variation_id = $context->get_canonical_variation_id();

        intersoccer_debug('Course price calculation for variation {variation} - base_price: {base}, total_weeks: {weeks}, session_rate: {rate}', [
            'variation' => $variation_id ?: $product_id,
            'base' => $context->get_base_price(),
            'weeks' => $context->get_total_weeks(),
            'rate' => $context->get_session_rate(),
        ]);

        if (isset(self::$price_cache[$cache_key])) {
            intersoccer_debug('Returning cached course price for canonical variation {canonical} (requested variation {requested}) on {date}: {price}', [
                'canonical' => $canonical_variation_id,
                'requested' => $variation_id,
                'date' => $current_date_for_key,
                'price' => self::$price_cache[$cache_key],
            ]);
            self::record_runtime_stat('cache_hits', $canonical_variation_id);
            return self::$price_cache[$cache_key];
        }

        self::record_runtime_stat('price_computations', $canonical_variation_id);

        if (!is_null($remaining_sessions)) {
            $hinted_sessions = max(0, (int) $remaining_sessions);
            intersoccer_debug('Received remaining_sessions hint {hint} for variation {variation}', [
                'hint' => $hinted_sessions,
                'variation' => $variation_id,
            ]);
        } else {
            $hinted_sessions = null;
        }

        $result = self::pricing_calculator()->calculate_price($context, $hinted_sessions, $current_date_for_key);
        $authoritative_remaining = $result['remaining_sessions'];

        if (!is_null($hinted_sessions) && $hinted_sessions !== $authoritative_remaining) {
            intersoccer_warning('Remaining sessions hint mismatch for variation {variation} - hinted: {hint}, actual: {actual}', [
                'variation' => $variation_id,
                'hint' => $hinted_sessions,
                'actual' => $authoritative_remaining,
            ]);
        }

        intersoccer_debug('Authoritative remaining_sessions for variation {variation}: {remaining}', [
            'variation' => $variation_id,
            'remaining' => $authoritative_remaining,
        ]);

        if (!empty($result['guard'])) {
            $guard_bucket = $result['guard'] === 'future_start' ? 'guard_future_start' : 'guard_base_price';
            self::record_runtime_stat($guard_bucket, $canonical_variation_id);
            intersoccer_info('Guard rule triggered ({guard}) for variation {variation} - returning base price {price}', [
                'guard' => $result['guard'],
                'variation' => $variation_id,
                'price' => $context->get_base_price(),
            ]);
        } elseif ($context->get_session_rate() > 0 && $authoritative_remaining > 0) {
            intersoccer_debug('Course price calculated using session rate for variation {variation}: {price} (rate: {rate} × remaining sessions: {remaining})', [
                'variation' => $variation_id,
                'price' => $result['price'],
                'rate' => $context->get_session_rate(),
                'remaining' => $authoritative_remaining,
            ]);
        }

        $final_price = max(0, $result['price']);
        intersoccer_debug('Final calculated price for variation {variation}: {price}', [
            'variation' => $variation_id,
            'price' => $final_price,
        ]);
        self::$price_cache[$cache_key] = $final_price;
        intersoccer_debug('Cached course price for canonical variation {canonical} (requested variation {requested}) on {date}: {price}', [
            'canonical' => $canonical_variation_id,
            'requested' => $variation_id,
            'date' => $current_date_for_key,
            'price' => $final_price,
        ]);
        return $final_price;
    }

    /**
     * Calculate discount note for course.
     *
     * @param int $variation_id Variation ID.
     * @param int $remaining_sessions Remaining sessions.
     * @return string Discount note.
     */
    public static function calculate_discount_note($variation_id, $remaining_sessions) {
        $discount_note = '';
        if ($remaining_sessions > 0) {
            $discount_note = sprintf(__('%d Weeks Remaining', 'intersoccer-product-variations'), $remaining_sessions);
        }
        intersoccer_debug('Calculated discount_note for variation {variation}: {note}', [
            'variation' => $variation_id,
            'note' => $discount_note,
        ]);
        return $discount_note;
    }

    /**
     * Ensure WooCommerce variation price caches roll over daily for course products.
     *
     * @param array        $hash Existing hash fragment.
     * @param WC_Product   $product Parent product instance.
     * @param bool         $for_display Whether prices are for display.
     * @return array Modified hash including a daily salt.
     */
    public static function filter_variation_prices_hash($hash, $product, $for_display) {
        if (!is_array($hash)) {
            $hash = [];
        }

        if (!$product || !method_exists($product, 'get_id')) {
            return $hash;
        }

        $product_id = (int) $product->get_id();
        $product_type = function_exists('intersoccer_get_product_type') ? intersoccer_get_product_type($product_id) : null;

        if ($product_type !== 'course') {
            return $hash;
        }

        $current_day = function_exists('current_time') ? current_time('Y-m-d') : date('Y-m-d');

        $hash['intersoccer_course_pricing_day'] = $current_day;
        $hash['intersoccer_course_pricing_version'] = '2';

        return $hash;
    }
}
} // End class_exists check for InterSoccer_Course

if (function_exists('add_filter')) {
    add_filter('woocommerce_get_variation_prices_hash', ['InterSoccer_Course', 'filter_variation_prices_hash'], 10, 3);
}

// Procedural wrappers for backward compatibility
function calculate_end_date($variation_id, $total_weeks) {
    return InterSoccer_Course::calculate_end_date($variation_id, $total_weeks);
}

function calculate_total_sessions($variation_id, $total_weeks) {
    return InterSoccer_Course::calculate_total_sessions($variation_id, $total_weeks);
}

function calculate_remaining_sessions($variation_id, $total_weeks) {
    return InterSoccer_Course::calculate_remaining_sessions($variation_id, $total_weeks);
}

/**
 * Get pre-selected variation ID from URL parameters or session
 *
 * @param WC_Product $product Variable product
 * @return int Variation ID or 0 if none found
 */
function intersoccer_get_preselected_variation_id($product) {
    if (!$product || !$product->is_type('variable')) {
        return 0;
    }

    // Check URL parameters for variation attributes
    $attributes = $product->get_variation_attributes();
    $selected_attributes = [];

    foreach ($attributes as $attribute_name => $options) {
        $param_name = 'attribute_' . sanitize_title($attribute_name);
        if (isset($_GET[$param_name]) && !empty($_GET[$param_name])) {
            $selected_attributes[$attribute_name] = sanitize_text_field($_GET[$param_name]);
        }
    }

    // If we have selected attributes, find the matching variation
    if (!empty($selected_attributes)) {
        $variation_id = intersoccer_find_matching_variation($product, $selected_attributes);
        if ($variation_id) {
            return $variation_id;
        }
    }

    // Check session for pre-selected variation (could be set from cart links)
    $session_variation = WC()->session->get('intersoccer_preselected_variation');
    if ($session_variation && isset($session_variation[$product->get_id()])) {
        return absint($session_variation[$product->get_id()]);
    }

    return 0;
}

/**
 * Find variation that matches selected attributes
 *
 * @param WC_Product $product Variable product
 * @param array $selected_attributes Selected attribute values
 * @return int Variation ID or 0 if not found
 */
function intersoccer_find_matching_variation($product, $selected_attributes) {
    $variations = $product->get_available_variations();

    foreach ($variations as $variation) {
        $variation_attributes = $variation['attributes'];
        $matches = true;

        foreach ($selected_attributes as $attribute_name => $selected_value) {
            $variation_attr_key = 'attribute_' . sanitize_title($attribute_name);
            $variation_value = isset($variation_attributes[$variation_attr_key]) ? $variation_attributes[$variation_attr_key] : '';

            if ($variation_value !== $selected_value) {
                $matches = false;
                break;
            }
        }

        if ($matches) {
            return $variation['variation_id'];
        }
    }

    return 0;
}



/**
 * Automatically display course information on course product pages (fallback for non-variable products)
 */
add_filter('the_content', 'intersoccer_add_course_info_to_content', 20);
function intersoccer_add_course_info_to_content($content) {
    // Only modify single product pages
    if (!is_singular('product')) {
        return $content;
    }

    global $product;
    if (!$product || !is_a($product, 'WC_Product')) {
        return $content;
    }

    $product_id = $product->get_id();
    $product_type = intersoccer_get_product_type($product_id);

    if ($product_type !== 'course') {
        return $content;
    }

    // For variable products, course info is handled by woocommerce_after_variations_table hook
    if ($product->is_type('variable')) {
        return $content;
    }

    // Get variation ID if this is a variation product
    $variation_id = 0;
    if ($product->is_type('variation')) {
        $variation_id = $product_id;
        $product_id = $product->get_parent_id();
    }

    // For variation and simple products, show the info directly
    ob_start();
    intersoccer_render_course_info($product_id, $variation_id, false, true); // plain = true
    $course_info_html = ob_get_clean();

    // Append to content
    return $content . $course_info_html;
}

/**
 * Shortcode to display course information (for manual use if needed)
 */
add_shortcode('intersoccer_course_info', 'intersoccer_course_info_shortcode');
function intersoccer_course_info_shortcode($atts) {
    global $product;

    if (!$product || !is_a($product, 'WC_Product')) {
        return '';
    }

    $product_id = $product->get_id();
    $product_type = intersoccer_get_product_type($product_id);

    if ($product_type !== 'course') {
        return '';
    }

    // Get variation ID if this is a variable product
    $variation_id = 0;
    if ($product->is_type('variable')) {
        // Always show the course info container - JavaScript will populate it
        ob_start();
        echo '<div id="intersoccer-course-info" class="intersoccer-course-info" style="display: none;">';
        echo '<h4>' . __('Course Information', 'intersoccer-product-variations') . '</h4>';
        echo '<div id="intersoccer-course-details"></div>';
        echo '</div>';
        return ob_get_clean();
    } elseif ($product->is_type('variation')) {
        $variation_id = $product_id;
        $product_id = $product->get_parent_id();
    }

    // Display course info for simple products or when variation is selected
    ob_start();
    intersoccer_render_course_info($product_id, $variation_id);
    return ob_get_clean();
}

/**
 * Render course information HTML
 *
 * @param int $product_id Product ID
 * @param int $variation_id Variation ID
 * @param bool $inner_only Return inner HTML only (for AJAX/pre-selected variations)
 * @param bool $plain Remove styling for plain text display
 */
function intersoccer_render_course_info($product_id, $variation_id, $inner_only = false, $plain = false) {
    if (!$variation_id) {
        return;
    }

    $start_date = intersoccer_get_course_meta($variation_id, '_course_start_date', '');
    $total_weeks = (int) intersoccer_get_course_meta($variation_id, '_course_total_weeks', 0);
    $holidays = intersoccer_get_course_meta($variation_id, '_course_holiday_dates', []);

    if (empty($start_date) || $total_weeks <= 0) {
        return;
    }

    // Calculate end date
    $end_date = '';
    if (class_exists('InterSoccer_Course')) {
        $end_date = InterSoccer_Course::calculate_end_date($variation_id, $total_weeks);
    }

    // Calculate remaining sessions
    $remaining_sessions = 0;
    if (class_exists('InterSoccer_Course')) {
        $remaining_sessions = InterSoccer_Course::calculate_remaining_sessions($variation_id, $total_weeks);
    }

    if (!$inner_only) {
        if ($plain) {
            echo '<div class="intersoccer-course-info">';
            echo '<strong>' . __('Course Information:', 'intersoccer-product-variations') . '</strong><br>';
        } else {
            echo '<div class="intersoccer-course-info">';
            echo '<h4>' . __('Course Information', 'intersoccer-product-variations') . '</h4>';
        }
        echo '<div class="intersoccer-course-details">';
    }

    // Start Date
    if ($start_date) {
        $formatted_start = date_i18n(get_option('date_format'), strtotime($start_date));
        echo '<p><strong>' . __('Start Date:', 'intersoccer-product-variations') . '</strong> ' . esc_html($formatted_start) . '</p>';
    }

    // End Date
    if ($end_date) {
        $formatted_end = date_i18n(get_option('date_format'), strtotime($end_date));
        echo '<p><strong>' . __('End Date:', 'intersoccer-product-variations') . '</strong> ' . esc_html($formatted_end) . '</p>';
    }

    // Total Weeks
    echo '<p><strong>' . __('Total Sessions:', 'intersoccer-product-variations') . '</strong> ' . esc_html($total_weeks) . '</p>';

    // Remaining Sessions
    if ($remaining_sessions !== $total_weeks) {
        echo '<p><strong>' . __('Remaining Sessions:', 'intersoccer-product-variations') . '</strong> ' . esc_html($remaining_sessions) . '</p>';
    }

    // Holidays
    if (!empty($holidays) && is_array($holidays)) {
        echo '<p><strong>' . __('Holidays:', 'intersoccer-product-variations') . '</strong></p>';
        echo '<ul style="margin-left: 20px;">';
        foreach ($holidays as $holiday) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday)) {
                $formatted_holiday = date_i18n(get_option('date_format'), strtotime($holiday));
                echo '<li>' . esc_html($formatted_holiday) . '</li>';
            }
        }
        echo '</ul>';
    }

    if (!$inner_only) {
        echo '</div>';
        if (!$plain) {
            echo '</div>';
        }
    }
}

/**
 * Display course info placeholder rows in variations table
 * These will be populated by JavaScript when a variation is selected
 */
add_action('woocommerce_after_variations_table', 'intersoccer_display_course_info_rows');
function intersoccer_display_course_info_rows() {
    global $product;
    
    if (!$product || !is_a($product, 'WC_Product')) {
        return;
    }
    
    $product_id = $product->get_id();
    $product_type = intersoccer_get_product_type($product_id);
    
    if ($product_type !== 'course') {
        return;
    }
    
    // Only for variable products
    if (!$product->is_type('variable')) {
        return;
    }
    
    // JavaScript will populate these rows and control visibility
    // Using inline script to append to tbody for proper table structure
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Add course info rows to variations table tbody
        var $variationsTable = $('.variations tbody');
        if ($variationsTable.length) {
            // Add hidden placeholder rows that JavaScript will populate
            // Match exact WooCommerce structure: <th class="label"><label>...</label></th><td class="value">...</td>
            $variationsTable.append('<tr class="intersoccer-course-info-row" id="intersoccer-course-start-date" style="display: none;"><th class="label"><label>Start Date</label></th><td class="value"></td></tr>');
            $variationsTable.append('<tr class="intersoccer-course-info-row" id="intersoccer-course-end-date" style="display: none;"><th class="label"><label>End Date</label></th><td class="value"></td></tr>');
            $variationsTable.append('<tr class="intersoccer-course-info-row" id="intersoccer-course-total-sessions" style="display: none;"><th class="label"><label>Total Sessions</label></th><td class="value"></td></tr>');
            $variationsTable.append('<tr class="intersoccer-course-info-row" id="intersoccer-course-remaining-sessions" style="display: none;"><th class="label"><label>Remaining Sessions</label></th><td class="value"></td></tr>');
            $variationsTable.append('<tr class="intersoccer-course-info-row" id="intersoccer-course-holidays" style="display: none;"><th class="label"><label>Holidays</label></th><td class="value"></td></tr>');
        }
    });
    </script>
    <?php
}

/**
 * AJAX handler to get course info for selected variation
 */
add_action('wp_ajax_intersoccer_get_course_info_display', 'intersoccer_get_course_info_display');
add_action('wp_ajax_nopriv_intersoccer_get_course_info_display', 'intersoccer_get_course_info_display');
function intersoccer_get_course_info_display() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = absint($_POST['product_id'] ?? 0);
    $variation_id = absint($_POST['variation_id'] ?? 0);

    if (!$product_id || !$variation_id) {
        wp_send_json_error(['message' => 'Missing product or variation ID']);
        return;
    }

    ob_start();
    intersoccer_render_course_info($product_id, $variation_id, true, true); // inner_only = true, plain = true for AJAX
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
}

intersoccer_debug('Defined course functions in product-course.php');

/**
 * One-time recalc of course end dates.
 */
add_action('init', 'intersoccer_recalculate_course_end_dates');
function intersoccer_recalculate_course_end_dates() {
    $flag = 'intersoccer_end_date_recalc_20250825'; // Updated flag to force recalc
    if (get_option($flag, false)) {
        return;
    }

    wp_schedule_single_event(time(), 'intersoccer_run_course_end_date_update');
    update_option($flag, true);
    intersoccer_info('Scheduled one-time course end date recalc');
}

/**
 * Action to run course end date update.
 */
add_action('intersoccer_run_course_end_date_update', 'intersoccer_run_course_end_date_update_callback');
function intersoccer_run_course_end_date_update_callback() {
    $products = wc_get_products(['type' => 'variable', 'limit' => -1]);
    foreach ($products as $product) {
        if (InterSoccer_Product_Types::get_product_type($product->get_id()) === 'course') {
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                $total_weeks = (int) intersoccer_get_course_meta($variation_id, '_course_total_weeks', 0);
                InterSoccer_Course::calculate_end_date($variation_id, $total_weeks);
            }
        }
    }
    intersoccer_info('Completed one-time recalc of course end dates');
}

/**
 * AJAX handler to get course price for selected variation
 */
add_action('wp_ajax_intersoccer_get_course_price', 'intersoccer_get_course_price');
add_action('wp_ajax_nopriv_intersoccer_get_course_price', 'intersoccer_get_course_price');
function intersoccer_get_course_price() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = absint($_POST['product_id'] ?? 0);
    $variation_id = absint($_POST['variation_id'] ?? 0);

    if (!$product_id || !$variation_id) {
        wp_send_json_error(['message' => 'Missing product or variation ID']);
        return;
    }

    if (!class_exists('InterSoccer_Course')) {
        wp_send_json_error(['message' => 'Course calculation class not available']);
        return;
    }

    $price = InterSoccer_Course::calculate_price($product_id, $variation_id);

    // v2.0 - Return both price and formatted HTML
    // Wrap in <span class="price"> to match WooCommerce standard structure
    wp_send_json_success([
        'price' => $price,
        'price_html' => '<span class="price">' . wc_price($price) . '</span>'
    ]);
}

/**
 * Filter variation prices for courses to show prorated price automatically
 * This ensures the correct price displays immediately without needing AJAX
 * 
 * TEMPORARILY DISABLED - CAUSING INFINITE LOOP
 */
// add_filter('woocommerce_product_variation_get_price', 'intersoccer_filter_course_variation_price', 10, 2);
// add_filter('woocommerce_product_variation_get_regular_price', 'intersoccer_filter_course_variation_price', 10, 2);
// add_filter('woocommerce_product_variation_get_sale_price', 'intersoccer_filter_course_variation_price', 10, 2);

function intersoccer_filter_course_variation_price_DISABLED($price, $variation) {
    // Prevent infinite recursion - check if we're already filtering
    static $filtering = [];
    $variation_id = $variation->get_id();
    
    if (isset($filtering[$variation_id])) {
        return $price; // Already filtering this variation, return original price
    }
    
    // Only apply to course products
    $parent_id = $variation->get_parent_id();
    if (intersoccer_get_product_type($parent_id) !== 'course') {
        return $price;
    }
    
    // Mark as filtering to prevent recursion
    $filtering[$variation_id] = true;
    
    // Get the raw base price from post meta to avoid triggering the filter again
    $base_price = floatval(get_post_meta($variation_id, '_price', true));
    if (empty($base_price)) {
        $base_price = floatval(get_post_meta($parent_id, '_price', true));
    }
    
    // Calculate prorated price manually without calling get_price()
    $total_weeks = (int) intersoccer_get_course_meta($variation_id, '_course_total_weeks', 0);
    $session_rate = floatval(intersoccer_get_course_meta($variation_id, '_course_weekly_discount', 0));
    $remaining_sessions = InterSoccer_Course::calculate_remaining_sessions($variation_id, $total_weeks);
    
    $prorated_price = $base_price; // Default to full price
    
    // Use session rate calculation if available
    if ($session_rate > 0 && $remaining_sessions > 0) {
        $prorated_price = $session_rate * $remaining_sessions;
        $prorated_price = round($prorated_price, 2);
    }
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        intersoccer_debug('Filtered course variation price for {variation} from {original} to {prorated}', [
            'variation' => $variation_id,
            'original' => $price,
            'prorated' => $prorated_price,
        ]);
    }
    
    // Remove filtering flag
    unset($filtering[$variation_id]);
    
    return $prorated_price;
}

?>