<?php
/**
 * Enhanced Discount System for InterSoccer
 * Handles combo offers and sibling discounts for camps and courses with precise allocation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('intersoccer_get_default_discount_rules')) {
    function intersoccer_get_default_discount_rules() {
        return [
            [
                'id' => 'camp-2nd-child',
                'name' => __('Camp Sibling Discount (2nd Child)', 'intersoccer-product-variations'),
                'type' => 'camp',
                'condition' => '2nd_child',
                'rate' => 20,
                'active' => true,
            ],
            [
                'id' => 'camp-3rd-plus-child',
                'name' => __('Camp Sibling Discount (3rd+ Child)', 'intersoccer-product-variations'),
                'type' => 'camp',
                'condition' => '3rd_plus_child',
                'rate' => 25,
                'active' => true,
            ],
            [
                'id' => 'course-2nd-child',
                'name' => __('Course Sibling Discount (2nd Child)', 'intersoccer-product-variations'),
                'type' => 'course',
                'condition' => '2nd_child',
                'rate' => 20,
                'active' => true,
            ],
            [
                'id' => 'course-3rd-plus-child',
                'name' => __('Course Sibling Discount (3rd+ Child)', 'intersoccer-product-variations'),
                'type' => 'course',
                'condition' => '3rd_plus_child',
                'rate' => 30,
                'active' => true,
            ],
            [
                'id' => 'course-same-season',
                'name' => __('Course Same Season Discount', 'intersoccer-product-variations'),
                'type' => 'course',
                'condition' => 'same_season_course',
                'rate' => 50,
                'active' => true,
            ],
            [
                'id' => 'tournament-2nd-child',
                'name' => __('Tournament Sibling Discount (2nd Child)', 'intersoccer-product-variations'),
                'type' => 'tournament',
                'condition' => '2nd_child',
                'rate' => 20,
                'active' => true,
            ],
            [
                'id' => 'tournament-3rd-plus-child',
                'name' => __('Tournament Sibling Discount (3rd+ Child)', 'intersoccer-product-variations'),
                'type' => 'tournament',
                'condition' => '3rd_plus_child',
                'rate' => 30,
                'active' => true,
            ],
            [
                'id' => 'camp-progressive-week-2',
                'name' => __('Camp Progressive Discount (Week 2)', 'intersoccer-product-variations'),
                'type' => 'camp',
                'condition' => 'progressive_week_2',
                'rate' => 10,
                'active' => true,
            ],
            [
                'id' => 'camp-progressive-week-3-plus',
                'name' => __('Camp Progressive Discount (Week 3+)', 'intersoccer-product-variations'),
                'type' => 'camp',
                'condition' => 'progressive_week_3_plus',
                'rate' => 20,
                'active' => true,
            ],
            [
                'id' => 'tournament-same-child-multiple-days',
                'name' => __('Tournament Same Child Multiple Days Discount', 'intersoccer-product-variations'),
                'type' => 'tournament',
                'condition' => 'same_child_multiple_days',
                'rate' => 33.33,
                'active' => true,
            ],
        ];
    }
}

if (!function_exists('intersoccer_normalize_discount_rules')) {
    function intersoccer_normalize_discount_rules($rules) {
        if (!is_array($rules)) {
            return [];
        }

        $allowed_types = ['general', 'camp', 'course', 'tournament', 'birthday'];
        $allowed_conditions = ['2nd_child', '3rd_plus_child', 'same_season_course', 'progressive_week_2', 'progressive_week_3_plus', 'same_child_multiple_days', 'none'];
        $normalized = [];

        foreach ($rules as $rule) {
            $id = isset($rule['id']) ? sanitize_key($rule['id']) : '';
            if (empty($id)) {
                $id = wp_generate_uuid4();
            }

            $name = isset($rule['name']) ? sanitize_text_field($rule['name']) : '';
            if ($name === '') {
                continue;
            }

            $type = isset($rule['type']) ? sanitize_key($rule['type']) : 'general';
            if (!in_array($type, $allowed_types, true)) {
                $type = 'general';
            }

            $condition = isset($rule['condition']) ? sanitize_key($rule['condition']) : 'none';
            if ($condition === '' || !in_array($condition, $allowed_conditions, true)) {
                $condition = 'none';
            }

            $rate = min(max(floatval($rule['rate'] ?? 0), 0), 100);
            $active = isset($rule['active']) ? (bool) $rule['active'] : true;

            $normalized[$id] = [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'condition' => $condition,
                'rate' => $rate,
                'active' => $active,
            ];
        }

        return $normalized;
    }
}

if (!function_exists('intersoccer_merge_default_discount_rules')) {
    function intersoccer_merge_default_discount_rules($rules) {
        $normalized = intersoccer_normalize_discount_rules($rules);
        $defaults = intersoccer_normalize_discount_rules(intersoccer_get_default_discount_rules());

        foreach ($defaults as $id => $default_rule) {
            if (!isset($normalized[$id])) {
                $normalized[$id] = $default_rule;
            }
        }

        return $normalized;
    }
}

/**
 * Get customer's previous orders with configurable lookback period
 * 
 * @param int $customer_id Customer user ID
 * @param string|null $customer_email Customer email (fallback for guest orders)
 * @param int $lookback_months Number of months to look back (default: 6)
 * @return array Array of WC_Order objects
 */
function intersoccer_get_customer_previous_orders($customer_id, $customer_email = null, $lookback_months = 6) {
    static $cache = [];
    $cache_key = $customer_id . '_' . ($customer_email ?? '') . '_' . $lookback_months;
    
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }
    
    $args = [
        'status' => ['wc-completed', 'completed', 'wc-processing', 'processing'],
        'limit' => -1,
        'return' => 'ids'
    ];
    
    // Add customer identification
    if ($customer_id > 0) {
        $args['customer_id'] = $customer_id;
    } elseif ($customer_email) {
        $args['billing_email'] = $customer_email;
    } else {
        $cache[$cache_key] = [];
        return [];
    }
    
    // Add date range filter
    if ($lookback_months > 0) {
        $date_after = date('Y-m-d', strtotime("-{$lookback_months} months"));
        $args['date_after'] = $date_after;
    }
    
    $order_ids = wc_get_orders($args);
    $orders = [];
    
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $orders[] = $order;
        }
    }
    
    $cache[$cache_key] = $orders;
    return $orders;
}

/**
 * Extract course items from an order
 * 
 * @param WC_Order $order Order object
 * @return array Array of course items with parent_product_id, assigned_player, course_day
 */
function intersoccer_extract_course_items_from_order($order) {
    $course_items = [];
    
    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        
        // Check if it's a course
        $product_type = intersoccer_get_product_type($variation_id ?: $product_id);
        if ($product_type !== 'course') {
            continue;
        }
        
        // Get parent product ID
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            continue;
        }
        
        $parent_product_id = $product->get_parent_id();
        if (!$parent_product_id) {
            $parent_product_id = $product_id; // If no parent, use product_id itself
        }
        
        // Get assigned player
        $assigned_player = $item->get_meta('assigned_attendee') ?: $item->get_meta('assigned_player');
        if (empty($assigned_player)) {
            continue;
        }
        
        // Get course day from variation attributes
        $course_day = '';
        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $course_day = $variation->get_attribute('pa_course-day') ?: '';
            }
        }
        
        // Fallback to parent product attributes
        if (empty($course_day)) {
            $parent_product = wc_get_product($parent_product_id);
            if ($parent_product) {
                $course_day = $parent_product->get_attribute('pa_course-day') ?: '';
            }
        }
        
        $course_items[] = [
            'order_id' => $order->get_id(),
            'item_id' => $item_id,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'parent_product_id' => $parent_product_id,
            'assigned_player' => $assigned_player,
            'course_day' => $course_day,
            'quantity' => $item->get_quantity(),
            'order_date' => $order->get_date_created()->format('Y-m-d H:i:s')
        ];
    }
    
    return $course_items;
}

/**
 * Extract camp items from an order
 * 
 * @param WC_Order $order Order object
 * @return array Array of camp items with parent_product_id, assigned_player, camp_terms, week_number
 */
function intersoccer_extract_camp_items_from_order($order) {
    $camp_items = [];
    
    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        
        // Check if it's a camp
        $product_type = intersoccer_get_product_type($variation_id ?: $product_id);
        if ($product_type !== 'camp') {
            continue;
        }
        
        // Get parent product ID
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            continue;
        }
        
        $parent_product_id = $product->get_parent_id();
        if (!$parent_product_id) {
            $parent_product_id = $product_id; // If no parent, use product_id itself
        }
        
        // Get assigned player
        $assigned_player = $item->get_meta('assigned_attendee') ?: $item->get_meta('assigned_player');
        if (empty($assigned_player)) {
            continue;
        }
        
        // Get camp-terms from variation attributes
        $camp_terms = '';
        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $camp_terms = $variation->get_attribute('pa_camp-terms') ?: '';
            }
        }
        
        // Fallback to parent product attributes
        if (empty($camp_terms)) {
            $parent_product = wc_get_product($parent_product_id);
            if ($parent_product) {
                $camp_terms = $parent_product->get_attribute('pa_camp-terms') ?: '';
            }
        }
        
        // Parse week number from camp-terms
        $week_number = intersoccer_parse_camp_week_from_terms($camp_terms);
        
        $camp_items[] = [
            'order_id' => $order->get_id(),
            'item_id' => $item_id,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'parent_product_id' => $parent_product_id,
            'assigned_player' => $assigned_player,
            'camp_terms' => $camp_terms,
            'week_number' => $week_number,
            'quantity' => $item->get_quantity(),
            'order_date' => $order->get_date_created()->format('Y-m-d H:i:s')
        ];
    }
    
    return $camp_items;
}

/**
 * Parse week number from camp-terms attribute
 * 
 * @param string $camp_terms Camp terms string (e.g., "summer-week-2-june-24-june-28-5-days")
 * @return int|null Week number or null if not found
 */
function intersoccer_parse_camp_week_from_terms($camp_terms) {
    if (empty($camp_terms)) {
        return null;
    }
    
    // Pattern: season-week-X-...
    if (preg_match('/week-(\d+)/i', $camp_terms, $matches)) {
        return intval($matches[1]);
    }
    
    return null;
}

/**
 * Get previous courses by parent product and assigned player
 * 
 * @param int $customer_id Customer user ID
 * @param int $parent_product_id Parent product ID
 * @param int $assigned_player Assigned player/attendee ID
 * @param int $lookback_months Number of months to look back (default: 6)
 * @return array Array of course items
 */
function intersoccer_get_previous_courses_by_parent($customer_id, $parent_product_id, $assigned_player, $lookback_months = 6) {
    static $cache = [];
    $cache_key = 'courses_' . $customer_id . '_' . $parent_product_id . '_' . $assigned_player . '_' . $lookback_months;
    
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }
    
    $customer_email = null;
    if ($customer_id > 0) {
        $user = get_user_by('id', $customer_id);
        if ($user) {
            $customer_email = $user->user_email;
        }
    }
    
    $orders = intersoccer_get_customer_previous_orders($customer_id, $customer_email, $lookback_months);
    $matching_courses = [];
    
    foreach ($orders as $order) {
        $course_items = intersoccer_extract_course_items_from_order($order);
        foreach ($course_items as $course_item) {
            if ($course_item['parent_product_id'] == $parent_product_id && 
                $course_item['assigned_player'] == $assigned_player) {
                $matching_courses[] = $course_item;
            }
        }
    }
    
    $cache[$cache_key] = $matching_courses;
    return $matching_courses;
}

/**
 * Extract tournament items from an order
 * 
 * @param WC_Order $order Order object
 * @return array Array of tournament items with parent_product_id, assigned_player, tournament_day
 */
function intersoccer_extract_tournament_items_from_order($order) {
    $tournament_items = [];
    
    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        
        // Check if it's a tournament
        $product_type = intersoccer_get_product_type($variation_id ?: $product_id);
        if ($product_type !== 'tournament') {
            continue;
        }
        
        // Get parent product ID
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            continue;
        }
        
        $parent_product_id = $product->get_parent_id();
        if (!$parent_product_id) {
            $parent_product_id = $product_id; // If no parent, use product_id itself
        }
        
        // Get assigned player
        $assigned_player = $item->get_meta('assigned_attendee') ?: $item->get_meta('assigned_player');
        if (empty($assigned_player)) {
            continue;
        }
        
        $tournament_items[] = [
            'order_id' => $order->get_id(),
            'item_id' => $item_id,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'parent_product_id' => $parent_product_id,
            'assigned_player' => $assigned_player,
            'quantity' => $item->get_quantity(),
            'order_date' => $order->get_date_created()->format('Y-m-d H:i:s')
        ];
    }
    
    return $tournament_items;
}

/**
 * Get previous tournaments by parent product and assigned player
 * 
 * @param int $customer_id Customer user ID
 * @param int $parent_product_id Parent product ID
 * @param int $assigned_player Assigned player/attendee ID
 * @param int $lookback_months Number of months to look back (default: 6)
 * @return array Array of tournament items
 */
function intersoccer_get_previous_tournaments_by_parent($customer_id, $parent_product_id, $assigned_player, $lookback_months = 6) {
    static $cache = [];
    $cache_key = 'tournaments_' . $customer_id . '_' . $parent_product_id . '_' . $assigned_player . '_' . $lookback_months;
    
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }
    
    $customer_email = null;
    if ($customer_id > 0) {
        $user = get_user_by('id', $customer_id);
        if ($user) {
            $customer_email = $user->user_email;
        }
    }
    
    $orders = intersoccer_get_customer_previous_orders($customer_id, $customer_email, $lookback_months);
    $matching_tournaments = [];
    
    foreach ($orders as $order) {
        $tournament_items = intersoccer_extract_tournament_items_from_order($order);
        foreach ($tournament_items as $tournament_item) {
            if ($tournament_item['parent_product_id'] == $parent_product_id && 
                $tournament_item['assigned_player'] == $assigned_player) {
                $matching_tournaments[] = $tournament_item;
            }
        }
    }
    
    $cache[$cache_key] = $matching_tournaments;
    return $matching_tournaments;
}

/**
 * Get previous camps by parent product and assigned player
 * 
 * @param int $customer_id Customer user ID
 * @param int $parent_product_id Parent product ID
 * @param int $assigned_player Assigned player/attendee ID
 * @param int $lookback_months Number of months to look back (default: 6)
 * @return array Array of camp items
 */
function intersoccer_get_previous_camps_by_parent($customer_id, $parent_product_id, $assigned_player, $lookback_months = 6) {
    static $cache = [];
    $cache_key = 'camps_' . $customer_id . '_' . $parent_product_id . '_' . $assigned_player . '_' . $lookback_months;
    
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }
    
    $customer_email = null;
    if ($customer_id > 0) {
        $user = get_user_by('id', $customer_id);
        if ($user) {
            $customer_email = $user->user_email;
        }
    }
    
    $orders = intersoccer_get_customer_previous_orders($customer_id, $customer_email, $lookback_months);
    $matching_camps = [];
    
    foreach ($orders as $order) {
        $camp_items = intersoccer_extract_camp_items_from_order($order);
        foreach ($camp_items as $camp_item) {
            if ($camp_item['parent_product_id'] == $parent_product_id && 
                $camp_item['assigned_player'] == $assigned_player) {
                $matching_camps[] = $camp_item;
            }
        }
    }
    
    $cache[$cache_key] = $matching_camps;
    return $matching_camps;
}

/**
 * Build cart context for precise discount allocation
 */
function intersoccer_build_cart_context($cart_items) {
    $context = array(
        'camps_by_child' => array(),
        'courses_by_child' => array(),
        'tournaments_by_child' => array(),
        'courses_by_season_child' => array(),
        'all_items' => array(),
        'previous_courses' => array(),     // Previous course purchases by parent_product_id and assigned_player
        'previous_camps' => array(),       // Previous camp purchases by parent_product_id and assigned_player
        'previous_tournaments' => array()  // Previous tournament purchases by parent_product_id and assigned_player
    );
    
    // Get customer information for previous order queries
    $customer_id = get_current_user_id();
    $customer_email = null;
    if ($customer_id > 0) {
        $user = get_user_by('id', $customer_id);
        if ($user) {
            $customer_email = $user->user_email;
        }
    } else {
        // Try to get email from cart/session for guest checkout
        if (WC()->customer && WC()->customer->get_billing_email()) {
            $customer_email = WC()->customer->get_billing_email();
        }
    }
    
    // Get lookback period from settings (default: 6 months)
    $lookback_months = intval(get_option('intersoccer_retroactive_discount_lookback_months', 6));
    if ($lookback_months < 1) {
        $lookback_months = 6; // Minimum 1 month
    }
    if ($lookback_months > 24) {
        $lookback_months = 24; // Maximum 24 months
    }
    
    // Build cart items context
    foreach ($cart_items as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $variation_id = $cart_item['variation_id'] ?? 0;
        $assigned_player = $cart_item['assigned_attendee'] ?? $cart_item['assigned_player'] ?? null;
        $product_type = intersoccer_get_product_type($product_id);
        $price = floatval($cart_item['data']->get_price());
        
        // Get parent product ID
        $product = wc_get_product($variation_id ?: $product_id);
        $parent_product_id = null;
        if ($product) {
            $parent_product_id = $product->get_parent_id();
            if (!$parent_product_id) {
                $parent_product_id = $product_id; // If no parent, use product_id itself
            }
        }
        
        $item_data = array(
            'cart_key' => $cart_item_key,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'parent_product_id' => $parent_product_id,
            'assigned_player' => $assigned_player,
            'product_type' => $product_type,
            'price' => $price,
            'quantity' => $cart_item['quantity']
        );
        
        $context['all_items'][] = $item_data;
        
        if ($assigned_player !== null) {
            if ($product_type === 'camp') {
                // Check if it's full-week (combo discounts only apply to full-week)
                $booking_type = get_post_meta($variation_id ?: $product_id, 'attribute_pa_booking-type', true);
                if ($booking_type === 'full-week' || empty($booking_type)) {
                    if (!isset($context['camps_by_child'][$assigned_player])) {
                        $context['camps_by_child'][$assigned_player] = array();
                    }
                    $context['camps_by_child'][$assigned_player][] = $item_data;
                }
            } elseif ($product_type === 'course') {
                $season = intersoccer_get_product_season($product_id);
                
                if (!isset($context['courses_by_child'][$assigned_player])) {
                    $context['courses_by_child'][$assigned_player] = array();
                }
                $context['courses_by_child'][$assigned_player][] = $item_data;
                
                // Group by season for same-season discounts
                if (!isset($context['courses_by_season_child'][$season][$assigned_player])) {
                    $context['courses_by_season_child'][$season][$assigned_player] = array();
                }
                $context['courses_by_season_child'][$season][$assigned_player][] = $item_data;
            } elseif ($product_type === 'tournament') {
                if (!isset($context['tournaments_by_child'][$assigned_player])) {
                    $context['tournaments_by_child'][$assigned_player] = array();
                }
                $context['tournaments_by_child'][$assigned_player][] = $item_data;
            }
        }
    }
    
    // Load previous order data if customer is identified and retroactive discounts are enabled
    $enable_retroactive_courses = get_option('intersoccer_enable_retroactive_course_discounts', true);
    $enable_retroactive_camps = get_option('intersoccer_enable_retroactive_camp_discounts', true);
    $enable_retroactive_tournaments = true; // Always enabled for tournaments
    
    if (($enable_retroactive_courses || $enable_retroactive_camps || $enable_retroactive_tournaments) && ($customer_id > 0 || $customer_email)) {
        // Collect unique parent_product_id and assigned_player combinations from cart
        $course_combinations = array();
        $camp_combinations = array();
        $tournament_combinations = array();
        
        foreach ($context['all_items'] as $item) {
            if ($item['assigned_player'] && $item['parent_product_id']) {
                if ($item['product_type'] === 'course' && $enable_retroactive_courses) {
                    $key = $item['parent_product_id'] . '_' . $item['assigned_player'];
                    if (!isset($course_combinations[$key])) {
                        $course_combinations[$key] = array(
                            'parent_product_id' => $item['parent_product_id'],
                            'assigned_player' => $item['assigned_player']
                        );
                    }
                } elseif ($item['product_type'] === 'camp' && $enable_retroactive_camps) {
                    $key = $item['parent_product_id'] . '_' . $item['assigned_player'];
                    if (!isset($camp_combinations[$key])) {
                        $camp_combinations[$key] = array(
                            'parent_product_id' => $item['parent_product_id'],
                            'assigned_player' => $item['assigned_player']
                        );
                    }
                } elseif ($item['product_type'] === 'tournament' && $enable_retroactive_tournaments) {
                    $key = $item['parent_product_id'] . '_' . $item['assigned_player'];
                    if (!isset($tournament_combinations[$key])) {
                        $tournament_combinations[$key] = array(
                            'parent_product_id' => $item['parent_product_id'],
                            'assigned_player' => $item['assigned_player']
                        );
                    }
                }
            }
        }
        
        // Query previous courses
        if (!empty($course_combinations) && $enable_retroactive_courses) {
            foreach ($course_combinations as $key => $combo) {
                $previous_courses = intersoccer_get_previous_courses_by_parent(
                    $customer_id,
                    $combo['parent_product_id'],
                    $combo['assigned_player'],
                    $lookback_months
                );
                if (!empty($previous_courses)) {
                    $context['previous_courses'][$key] = $previous_courses;
                }
            }
        }
        
        // Query previous camps
        if (!empty($camp_combinations) && $enable_retroactive_camps) {
            foreach ($camp_combinations as $key => $combo) {
                $previous_camps = intersoccer_get_previous_camps_by_parent(
                    $customer_id,
                    $combo['parent_product_id'],
                    $combo['assigned_player'],
                    $lookback_months
                );
                if (!empty($previous_camps)) {
                    $context['previous_camps'][$key] = $previous_camps;
                }
            }
        }
        
        // Query previous tournaments
        if (!empty($tournament_combinations) && $enable_retroactive_tournaments) {
            foreach ($tournament_combinations as $key => $combo) {
                $previous_tournaments = intersoccer_get_previous_tournaments_by_parent(
                    $customer_id,
                    $combo['parent_product_id'],
                    $combo['assigned_player'],
                    $lookback_months
                );
                if (!empty($previous_tournaments)) {
                    $context['previous_tournaments'][$key] = $previous_tournaments;
                }
            }
        }
    }
    
    intersoccer_debug("InterSoccer Precise: Built cart context with " . count($context['all_items']) . " items, " . 
              count($context['camps_by_child']) . " children with camps, " . 
              count($context['courses_by_child']) . " children with courses, " . 
              count($context['tournaments_by_child']) . " children with tournaments, " .
              count($context['previous_courses']) . " previous course groups, " .
              count($context['previous_camps']) . " previous camp groups, " .
              count($context['previous_tournaments']) . " previous tournament groups");
    
    return $context;
}

/**
 * Determine precise discount type with better logic
 */
function intersoccer_determine_precise_discount_type($fee_name) {
    $fee_name_lower = strtolower($fee_name);
    
    // Camp discounts
    if (strpos($fee_name_lower, 'camp') !== false) {
        if (strpos($fee_name_lower, 'sibling') !== false || strpos($fee_name_lower, 'multi-child') !== false) {
            return 'camp_sibling';
        }
        return 'camp_other';
    }
    
    // Course discounts
    if (strpos($fee_name_lower, 'course') !== false) {
        if (strpos($fee_name_lower, 'same season') !== false) {
            return 'course_same_season';
        }
        if (strpos($fee_name_lower, 'sibling') !== false || strpos($fee_name_lower, 'multi-child') !== false) {
            return 'course_multi_child';
        }
        return 'course_other';
    }
    
    // Tournament discounts
    if (strpos($fee_name_lower, 'tournament') !== false) {
        if (strpos($fee_name_lower, 'sibling') !== false || strpos($fee_name_lower, 'multi-child') !== false) {
            return 'tournament_multi_child';
        }
        return 'tournament_other';
    }
    
    // Percentage-based discounts
    if (preg_match('/(\d+)%/', $fee_name)) {
        return 'percentage_discount';
    }
    
    return 'other';
}

/**
 * Allocate combo discount to specific items based on discount rules
 */
function intersoccer_allocate_combo_discount($combo_discount, $cart_context, $order) {
    $allocations = array();
    
    switch ($combo_discount['type']) {
        case 'camp_sibling':
            $allocations = intersoccer_allocate_camp_sibling_discount($combo_discount, $cart_context, $order);
            break;
            
        case 'course_multi_child':
            $allocations = intersoccer_allocate_course_multi_child_discount($combo_discount, $cart_context, $order);
            break;
            
        case 'course_same_season':
            $allocations = intersoccer_allocate_course_same_season_discount($combo_discount, $cart_context, $order);
            break;
            
        case 'tournament_multi_child':
            $allocations = intersoccer_allocate_tournament_multi_child_discount($combo_discount, $cart_context, $order);
            break;
            
        default:
            // For other discount types, try to distribute proportionally
            $allocations = intersoccer_allocate_proportional_discount($combo_discount, $order);
            break;
    }
    
    return $allocations;
}

/**
 * Fetch and map discount rules from database.
 *
 * @return array Mapped rates by type and condition.
 */
function intersoccer_get_discount_rates() {
    $rules_option = get_option('intersoccer_discount_rules', []);

    $merged = intersoccer_merge_default_discount_rules($rules_option);
    if ($merged !== $rules_option) {
        update_option('intersoccer_discount_rules', $merged);
    }
    $rules_option = $merged;
 
    $rates = [
        'camp' => [],
        'course' => [],
        'tournament' => []
    ];
    
    // Track which types have rules in the database (regardless of active status)
    $has_rules = [
        'camp' => false,
        'course' => false,
        'tournament' => false
    ];

    foreach ($rules_option as $rule) {
        $type = $rule['type'] ?? 'general';
        $condition = $rule['condition'] ?? 'none';
        
        // Track if rules exist for this type
        if (in_array($type, ['camp', 'course', 'tournament']) && $condition !== 'none') {
            $has_rules[$type] = true;
        }
        
        // Only add to rates if rule is active
        if (!isset($rule['active']) || !$rule['active']) {
            continue;
        }
        
        $rate = floatval($rule['rate'] ?? 0) / 100;

        if (in_array($type, ['camp', 'course', 'tournament']) && $condition !== 'none') {
            $rates[$type][$condition] = $rate;
        }
    }

    // Add default rates ONLY if no rules exist in database for that type
    // If rules exist but are disabled, don't use defaults
    if (!$has_rules['camp'] && empty($rates['camp'])) {
        $rates['camp'] = [
            '2nd_child' => 0.20,
            '3rd_plus_child' => 0.25
        ];
    }
    
    if (!$has_rules['course'] && empty($rates['course'])) {
        $rates['course'] = [
            '2nd_child' => 0.20,
            '3rd_plus_child' => 0.30,
            'same_season_course' => 0.50
        ];
    }
    
    if (!$has_rules['tournament'] && empty($rates['tournament'])) {
        $rates['tournament'] = [
            '2nd_child' => 0.20,
            '3rd_plus_child' => 0.30
        ];
    }

    return $rates;
}

/**
 * Clear cart fees before recalculation to prevent duplication
 */
add_action('woocommerce_before_calculate_totals', function($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }
    
    static $fees_cleared = false;
    if (!$fees_cleared) {
        $fees_cleared = true;
    }
}, 5);

/**
 * Apply InterSoccer discounts to cart
 */
add_action('woocommerce_before_calculate_totals', 'intersoccer_apply_combo_discounts_to_items', 20);

function intersoccer_apply_combo_discounts_to_items($cart) {
    intersoccer_debug('InterSoccer: ===== DISCOUNT FUNCTION CALLED =====');
    intersoccer_debug('InterSoccer: is_admin=' . (is_admin() ? 'yes' : 'no') . ', DOING_AJAX=' . (defined('DOING_AJAX') && DOING_AJAX ? 'yes' : 'no'));
    intersoccer_debug('InterSoccer: did_action count=' . did_action('woocommerce_before_calculate_totals'));
    
    if (is_admin() && !defined('DOING_AJAX')) {
        intersoccer_debug('InterSoccer: EXITING - is_admin and not AJAX');
        return;
    }
    if (did_action('woocommerce_before_calculate_totals') >= 2) {
        intersoccer_debug('InterSoccer: EXITING - prevent loops (did_action >= 2)');
        return;  // Prevent loops
    }
    
    intersoccer_debug('InterSoccer: Starting discount calculations, cart has ' . count($cart->get_cart()) . ' items');

    // Reset all items to base price
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (isset($cart_item['base_price'])) {
            $cart_item['data']->set_price((float) $cart_item['base_price']);
            $cart_item['discount_note'] = '';  // Reset note for reapply
            $cart_item['discount_amount'] = 0;
        }
    }

    // Check if sibling discounts should be disabled when coupons are applied
    $disable_with_coupons = get_option('intersoccer_disable_sibling_discount_with_coupons', false);
    $has_coupons = false;
    
    if ($disable_with_coupons && $cart instanceof WC_Cart) {
        $applied_coupons = $cart->get_applied_coupons();
        $has_coupons = !empty($applied_coupons);
        
        if ($has_coupons && defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug('InterSoccer: Sibling discounts disabled - coupons detected: ' . implode(', ', $applied_coupons));
        }
    }

    $context = intersoccer_build_cart_context($cart->get_cart());
    
    // Get active discount rates (only includes active rules)
    $discount_rates = intersoccer_get_discount_rates();

    // Apply Camp Multi-Child Discounts (skip if coupons are applied and option is enabled)
    if (!$has_coupons || !$disable_with_coupons) {
        // Check if camp sibling discounts are enabled
        $camp_2nd_rate = $discount_rates['camp']['2nd_child'] ?? null;
        $camp_3rd_rate = $discount_rates['camp']['3rd_plus_child'] ?? null;
        
        if ($camp_2nd_rate !== null || $camp_3rd_rate !== null) {
            $camp_children = $context['camps_by_child'];
            if (count($camp_children) >= 2) {
                // Calculate per-child totals
                $child_totals = [];
                foreach ($camp_children as $child => $items) {
                    $total = array_sum(array_map(function($item) { return $item['price'] * $item['quantity']; }, $items));
                    $child_totals[$child] = $total;
                }
                // Sort children by total DESC (highest first gets 0%)
                arsort($child_totals);
                $sorted_children = array_keys($child_totals);
                
                foreach ($sorted_children as $index => $child) {
                    $percent = 0;
                    if ($index == 1 && $camp_2nd_rate !== null) {
                        $percent = $camp_2nd_rate;  // Use rate from active rule
                    } elseif ($index >= 2 && $camp_3rd_rate !== null) {
                        $percent = $camp_3rd_rate;  // Use rate from active rule
                    }
                    
                    if ($percent > 0) {
                        $template = intersoccer_translate_string('%s Camp Sibling Discount', 'intersoccer-product-variations', '%s Camp Sibling Discount');
                        $fallback = sprintf($template, $percent * 100);
                        $message = intersoccer_get_discount_message('camp_multi_child_' . ($index + 1), 'cart_message', $fallback);
                        foreach ($camp_children[$child] as $item) {
                            $cart_key = $item['cart_key'];
                            $base_price = $cart->cart_contents[$cart_key]['base_price'];
                            $discounted_price = $base_price * (1 - $percent);
                            $cart->cart_contents[$cart_key]['data']->set_price($discounted_price);
                            $cart->cart_contents[$cart_key]['discount_amount'] = $base_price - $discounted_price;
                            $cart->cart_contents[$cart_key]['discount_note'] = $message;
                            intersoccer_debug('InterSoccer: Applied ' . ($percent * 100) . '% camp discount to item ' . $item['product_id'] . ' for child ' . $child);
                        }
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug('InterSoccer: Camp sibling discounts are disabled in settings');
            }
        }
        
        // Apply Retroactive Progressive Camp Discounts
        $enable_retroactive_camps = get_option('intersoccer_enable_retroactive_camp_discounts', true);
        $camp_week_2_rate = $discount_rates['camp']['progressive_week_2'] ?? null;
        $camp_week_3_plus_rate = $discount_rates['camp']['progressive_week_3_plus'] ?? null;
        
        if ($enable_retroactive_camps && ($camp_week_2_rate !== null || $camp_week_3_plus_rate !== null)) {
            $customer_id = get_current_user_id();
            $lookback_months = intval(get_option('intersoccer_retroactive_discount_lookback_months', 6));
            
            // Process each camp in cart
            foreach ($context['camps_by_child'] as $assigned_player => $camp_items) {
                foreach ($camp_items as $item) {
                    $parent_product_id = $item['parent_product_id'] ?? null;
                    if (!$parent_product_id) {
                        continue;
                    }
                    
                    // Get camp-terms and week number for current cart item
                    $variation_id = $item['variation_id'] ?? 0;
                    $product = wc_get_product($variation_id ?: $item['product_id']);
                    $camp_terms = '';
                    if ($product) {
                        $camp_terms = $product->get_attribute('pa_camp-terms') ?: '';
                        if (empty($camp_terms) && $product->get_parent_id()) {
                            $parent = wc_get_product($product->get_parent_id());
                            if ($parent) {
                                $camp_terms = $parent->get_attribute('pa_camp-terms') ?: '';
                            }
                        }
                    }
                    
                    $current_week = intersoccer_parse_camp_week_from_terms($camp_terms);
                    if (!$current_week) {
                        continue; // Skip if we can't determine week number
                    }
                    
                    // Get previous camps for same parent product and assigned player
                    $previous_camps = intersoccer_get_previous_camps_by_parent(
                        $customer_id,
                        $parent_product_id,
                        $assigned_player,
                        $lookback_months
                    );
                    
                    // Collect all week numbers (previous + current cart)
                    $all_weeks = [];
                    foreach ($previous_camps as $prev_camp) {
                        if ($prev_camp['week_number']) {
                            $all_weeks[] = $prev_camp['week_number'];
                        }
                    }
                    
                    // Add current cart week
                    $all_weeks[] = $current_week;
                    
                    // Remove duplicates and sort
                    $all_weeks = array_unique($all_weeks);
                    sort($all_weeks);
                    
                    // Determine which week this is (1st, 2nd, 3rd+, etc.)
                    $week_position = array_search($current_week, $all_weeks) + 1; // 1-based index
                    
                    // Apply progressive discount based on week position
                    $percent = 0;
                    if ($week_position === 2 && $camp_week_2_rate !== null) {
                        $percent = $camp_week_2_rate;
                    } elseif ($week_position >= 3 && $camp_week_3_plus_rate !== null) {
                        $percent = $camp_week_3_plus_rate;
                    }
                    
                    if ($percent > 0) {
                        $cart_key = $item['cart_key'];
                        $base_price = $cart->cart_contents[$cart_key]['base_price'];
                        $discounted_price = $base_price * (1 - $percent);
                        $cart->cart_contents[$cart_key]['data']->set_price($discounted_price);
                        $cart->cart_contents[$cart_key]['discount_amount'] = $base_price - $discounted_price;
                        
                        // Create discount label
                        if ($week_position === 2) {
                            $discount_label = sprintf(__('%d%% Camp Week 2 Discount', 'intersoccer-product-variations'), $percent * 100);
                            $message = intersoccer_get_discount_message('camp_progressive_week_2', 'cart_message', $discount_label);
                        } else {
                            $discount_label = sprintf(__('%d%% Camp Week %d+ Discount', 'intersoccer-product-variations'), $percent * 100, $week_position);
                            $message = intersoccer_get_discount_message('camp_progressive_week_3_plus', 'cart_message', $discount_label);
                        }
                        
                        $cart->cart_contents[$cart_key]['discount_note'] = $message;
                        intersoccer_debug('InterSoccer: Applied progressive camp discount ' . ($percent * 100) . '% to week ' . $current_week . ' (position ' . $week_position . ') for item ' . $item['product_id'] . ' for attendee ' . $assigned_player);
                    }
                }
            }
        }
    } // End camp multi-child discount check

    // Similar logic for Course Multi-Child
    // Skip if coupons are applied and option is enabled
    if (!$has_coupons || !$disable_with_coupons) {
        // Check if course sibling discounts are enabled
        $course_2nd_rate = $discount_rates['course']['2nd_child'] ?? null;
        $course_3rd_rate = $discount_rates['course']['3rd_plus_child'] ?? null;
        
        if ($course_2nd_rate !== null || $course_3rd_rate !== null) {
            $course_children = $context['courses_by_child'];
            if (count($course_children) >= 2) {
                $child_totals = [];
                foreach ($course_children as $child => $items) {
                    $total = array_sum(array_map(function($item) { return $item['price'] * $item['quantity']; }, $items));
                    $child_totals[$child] = $total;
                }
                // Sort children by total DESC (highest first gets 0%)
                arsort($child_totals);
                $sorted_children = array_keys($child_totals);
                
                foreach ($sorted_children as $index => $child) {
                    $percent = 0;
                    if ($index == 1 && $course_2nd_rate !== null) {
                        $percent = $course_2nd_rate;  // Use rate from active rule
                    } elseif ($index >= 2 && $course_3rd_rate !== null) {
                        $percent = $course_3rd_rate;  // Use rate from active rule
                    }
                    
                    if ($percent > 0) {
                        $template = intersoccer_translate_string('%s Course Sibling Discount', 'intersoccer-product-variations', '%s Course Sibling Discount');
                        $fallback = sprintf($template, $percent * 100);
                        $message = intersoccer_get_discount_message('course_multi_child_' . ($index + 1), 'cart_message', $fallback);
                        foreach ($course_children[$child] as $item) {
                            $cart_key = $item['cart_key'];
                            $base_price = $cart->cart_contents[$cart_key]['base_price'];
                            $discounted_price = $base_price * (1 - $percent);
                            $cart->cart_contents[$cart_key]['data']->set_price($discounted_price);
                            $cart->cart_contents[$cart_key]['discount_amount'] = $base_price - $discounted_price;
                            $cart->cart_contents[$cart_key]['discount_note'] = $message;
                            intersoccer_debug('InterSoccer: Applied ' . ($percent * 100) . '% course sibling discount to item ' . $item['product_id'] . ' for child ' . $child);
                        }
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug('InterSoccer: Course sibling discounts are disabled in settings');
            }
        }
    } // End course multi-child discount check

    // Course Same-Season Same-Child (skip if coupons are applied and option is enabled)
    if (!$has_coupons || !$disable_with_coupons) {
        // Check if same-season discount is enabled
        $same_season_rate = $discount_rates['course']['same_season_course'] ?? null;
        
        if ($same_season_rate !== null) {
            $enable_retroactive = get_option('intersoccer_enable_retroactive_course_discounts', true);
            
            foreach ($context['courses_by_season_child'] as $season => $children) {
                foreach ($children as $child => $items) {
                    // Verify all items are for the same attendee
                    $assigned_players = array_unique(array_filter(array_column($items, 'assigned_player')));
                    if (count($assigned_players) !== 1) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            intersoccer_debug('InterSoccer: Skipping same-season discount - items have different assigned players: ' . implode(', ', $assigned_players));
                        }
                        continue;
                    }
                    
                    // Verify the assigned_player is not null/empty
                    $assigned_player = reset($assigned_players);
                    if (empty($assigned_player)) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            intersoccer_debug('InterSoccer: Skipping same-season discount - assigned_player is empty');
                        }
                        continue;
                    }
                    
                    // Case 1: Multiple courses in cart for same child/season - apply discount to 2nd+ items
                    if (count($items) >= 2) {
                        // Sort items by base price ASC (cheap first full, expensive discount)
                        usort($items, function($a, $b) { return $a['price'] <=> $b['price']; });
                        // Only discount the second (index 1, which is higher price)
                        $percent = $same_season_rate;  // Use rate from active rule
                        $item = $items[1];  // Second item
                        $cart_key = $item['cart_key'];
                        $base_price = $cart->cart_contents[$cart_key]['base_price'];
                        $discounted_price = $base_price * (1 - $percent);
                        $cart->cart_contents[$cart_key]['data']->set_price($discounted_price);
                        $cart->cart_contents[$cart_key]['discount_amount'] = $base_price - $discounted_price;
                        $discount_label = sprintf(__('%d%% Same Season Course Discount', 'intersoccer-product-variations'), $percent * 100);
                        $cart->cart_contents[$cart_key]['discount_note'] = intersoccer_get_discount_message('course_same_season', 'cart_message', $discount_label);
                        intersoccer_debug('InterSoccer: Applied ' . ($percent * 100) . '% same-season discount to item ' . $item['product_id'] . ' for attendee ' . $assigned_player);
                    }
                    // Case 2: Only 1 course in cart, but check previous orders for retroactive discount
                    elseif (count($items) === 1 && $enable_retroactive) {
                        $item = $items[0];
                        $parent_product_id = $item['parent_product_id'] ?? null;
                        
                        if (!$parent_product_id) {
                            continue;
                        }
                        
                        // Get current course day
                        $variation_id = $item['variation_id'] ?? 0;
                        $product = wc_get_product($variation_id ?: $item['product_id']);
                        $current_course_day = '';
                        if ($product) {
                            $current_course_day = $product->get_attribute('pa_course-day') ?: '';
                            if (empty($current_course_day) && $product->get_parent_id()) {
                                $parent = wc_get_product($product->get_parent_id());
                                if ($parent) {
                                    $current_course_day = $parent->get_attribute('pa_course-day') ?: '';
                                }
                            }
                        }
                        
                        // Check previous orders for same parent product and same assigned player
                        $customer_id = get_current_user_id();
                        $lookback_months = intval(get_option('intersoccer_retroactive_discount_lookback_months', 6));
                        $previous_courses = intersoccer_get_previous_courses_by_parent(
                            $customer_id,
                            $parent_product_id,
                            $assigned_player,
                            $lookback_months
                        );
                        
                        // Check if there's a previous course with a different day
                        $has_different_day_course = false;
                        foreach ($previous_courses as $prev_course) {
                            if (!empty($prev_course['course_day']) && 
                                !empty($current_course_day) && 
                                strtolower($prev_course['course_day']) !== strtolower($current_course_day)) {
                                $has_different_day_course = true;
                                break;
                            }
                        }
                        
                        // Apply discount if previous course with different day found
                        if ($has_different_day_course) {
                            $percent = $same_season_rate;
                            $cart_key = $item['cart_key'];
                            $base_price = $cart->cart_contents[$cart_key]['base_price'];
                            $discounted_price = $base_price * (1 - $percent);
                            $cart->cart_contents[$cart_key]['data']->set_price($discounted_price);
                            $cart->cart_contents[$cart_key]['discount_amount'] = $base_price - $discounted_price;
                            $discount_label = sprintf(__('%d%% Same Season Course Discount', 'intersoccer-product-variations'), $percent * 100);
                            $cart->cart_contents[$cart_key]['discount_note'] = intersoccer_get_discount_message('course_same_season', 'cart_message', $discount_label);
                            intersoccer_debug('InterSoccer: Applied retroactive ' . ($percent * 100) . '% same-season discount to item ' . $item['product_id'] . ' for attendee ' . $assigned_player . ' (previous order found)');
                        }
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug('InterSoccer: Same-season course discount is disabled in settings');
            }
        }
    } // End same-season discount check

    // Apply Tournament Multi-Child Discounts
    // Skip if coupons are applied and option is enabled
    if (!$has_coupons || !$disable_with_coupons) {
        // Check if tournament sibling discounts are enabled
        $tournament_2nd_rate = $discount_rates['tournament']['2nd_child'] ?? null;
        $tournament_3rd_rate = $discount_rates['tournament']['3rd_plus_child'] ?? null;
        
        if ($tournament_2nd_rate !== null || $tournament_3rd_rate !== null) {
            $tournament_children = $context['tournaments_by_child'];
            if (count($tournament_children) >= 2) {
                // Calculate per-child totals
                $child_totals = [];
                foreach ($tournament_children as $child => $items) {
                    $total = array_sum(array_map(function($item) { return $item['price'] * $item['quantity']; }, $items));
                    $child_totals[$child] = $total;
                }
                // Sort children by total DESC (highest first gets 0%)
                arsort($child_totals);
                $sorted_children = array_keys($child_totals);
                
                foreach ($sorted_children as $index => $child) {
                    $percent = 0;
                    if ($index == 1 && $tournament_2nd_rate !== null) {
                        $percent = $tournament_2nd_rate;  // Use rate from active rule
                    } elseif ($index >= 2 && $tournament_3rd_rate !== null) {
                        $percent = $tournament_3rd_rate;  // Use rate from active rule
                    }
                    
                    if ($percent > 0) {
                        $template = intersoccer_translate_string('%s Tournament Sibling Discount', 'intersoccer-product-variations', '%s Tournament Sibling Discount');
                        $fallback = sprintf($template, $percent * 100);
                        $message = intersoccer_get_discount_message('tournament_multi_child_' . ($index + 1), 'cart_message', $fallback);
                        foreach ($tournament_children[$child] as $item) {
                            $cart_key = $item['cart_key'];
                            $base_price = $cart->cart_contents[$cart_key]['base_price'];
                            $discounted_price = $base_price * (1 - $percent);
                            $cart->cart_contents[$cart_key]['data']->set_price($discounted_price);
                            $cart->cart_contents[$cart_key]['discount_amount'] = $base_price - $discounted_price;
                            $cart->cart_contents[$cart_key]['discount_note'] = $message;
                            intersoccer_debug('InterSoccer: Applied ' . ($percent * 100) . '% tournament discount to item ' . $item['product_id'] . ' for child ' . $child);
                        }
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug('InterSoccer: Tournament sibling discounts are disabled in settings');
            }
        }
        
        // Apply Tournament Same-Child Multiple Days Discount
        $tournament_same_child_rate = $discount_rates['tournament']['same_child_multiple_days'] ?? null;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug('InterSoccer Tournament: Checking same-child multiple days discount');
            intersoccer_debug('InterSoccer Tournament: Rate from settings: ' . ($tournament_same_child_rate !== null ? ($tournament_same_child_rate * 100) . '%' : 'NULL/DISABLED'));
            intersoccer_debug('InterSoccer Tournament: Available rates: ' . print_r($discount_rates['tournament'] ?? [], true));
        }
        
        if ($tournament_same_child_rate !== null) {
            $customer_id = get_current_user_id();
            $lookback_months = intval(get_option('intersoccer_retroactive_discount_lookback_months', 6));
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug('InterSoccer Tournament: Customer ID: ' . $customer_id);
                intersoccer_debug('InterSoccer Tournament: Lookback months: ' . $lookback_months);
                intersoccer_debug('InterSoccer Tournament: Tournaments in context: ' . count($context['tournaments_by_child']));
            }
            
            // Group tournaments by assigned player and parent product
            $tournaments_by_player_parent = array();
            foreach ($context['tournaments_by_child'] as $assigned_player => $tournament_items) {
                foreach ($tournament_items as $item) {
                    $parent_product_id = $item['parent_product_id'] ?? null;
                    if (!$parent_product_id) {
                        continue;
                    }
                    
                    $key = $parent_product_id . '_' . $assigned_player;
                    if (!isset($tournaments_by_player_parent[$key])) {
                        $tournaments_by_player_parent[$key] = array(
                            'parent_product_id' => $parent_product_id,
                            'assigned_player' => $assigned_player,
                            'cart_items' => array()
                        );
                    }
                    $tournaments_by_player_parent[$key]['cart_items'][] = $item;
                }
            }
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug('InterSoccer Tournament: Grouped into ' . count($tournaments_by_player_parent) . ' groups by player/parent');
            }
            
            // Process each group
            foreach ($tournaments_by_player_parent as $key => $group) {
                $parent_product_id = $group['parent_product_id'];
                $assigned_player = $group['assigned_player'];
                $cart_items = $group['cart_items'];
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    intersoccer_debug('InterSoccer Tournament: Processing group - Parent: ' . $parent_product_id . ', Player: ' . $assigned_player . ', Cart items: ' . count($cart_items));
                }
                
                // Get previous tournaments for this combination
                $previous_tournaments = intersoccer_get_previous_tournaments_by_parent(
                    $customer_id,
                    $parent_product_id,
                    $assigned_player,
                    $lookback_months
                );
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    intersoccer_debug('InterSoccer Tournament: Found ' . count($previous_tournaments) . ' previous tournaments');
                }
                
                // Count total tournament days (previous + current cart)
                $total_days = count($previous_tournaments) + count($cart_items);
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    intersoccer_debug('InterSoccer Tournament: Total days (previous + cart): ' . $total_days);
                }
                
                // Apply discount to 2nd+ days in current cart
                if ($total_days >= 2) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        intersoccer_debug('InterSoccer Tournament: Total days >= 2, applying discount logic');
                    }
                    
                    // Sort cart items by price (ascending) to discount cheaper items
                    usort($cart_items, function($a, $b) { return $a['price'] <=> $b['price']; });
                    
                    // Determine how many items in cart should get discount
                    $previous_count = count($previous_tournaments);
                    
                    foreach ($cart_items as $index => $item) {
                        $day_position = $previous_count + $index + 1; // 1-based position
                        
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            intersoccer_debug('InterSoccer Tournament: Item ' . $index . ' - Position: ' . $day_position . ', Price: ' . $item['price']);
                        }
                        
                        // Apply discount to 2nd+ days
                        if ($day_position >= 2) {
                            $percent = $tournament_same_child_rate;
                            $cart_key = $item['cart_key'];
                            $base_price = $cart->cart_contents[$cart_key]['base_price'];
                            $discounted_price = $base_price * (1 - $percent);
                            
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                intersoccer_debug('InterSoccer Tournament: Applying ' . ($percent * 100) . '% discount - Base: ' . $base_price . ', Discounted: ' . $discounted_price);
                            }
                            
                            $cart->cart_contents[$cart_key]['data']->set_price($discounted_price);
                            $cart->cart_contents[$cart_key]['discount_amount'] = $base_price - $discounted_price;
                            
                            $discount_label = sprintf(__('%d%% Tournament Multiple Days Discount', 'intersoccer-product-variations'), $percent * 100);
                            $message = intersoccer_get_discount_message('tournament_same_child_multiple_days', 'cart_message', $discount_label);
                            $cart->cart_contents[$cart_key]['discount_note'] = $message;
                            
                            intersoccer_debug('InterSoccer: Applied tournament same-child discount ' . ($percent * 100) . '% to day ' . $day_position . ' for item ' . $item['product_id'] . ' for attendee ' . $assigned_player);
                        } else {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                intersoccer_debug('InterSoccer Tournament: Day position ' . $day_position . ' < 2, no discount applied');
                            }
                        }
                    }
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        intersoccer_debug('InterSoccer Tournament: Total days < 2 (' . $total_days . '), no discount applied');
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug('InterSoccer: Tournament same-child multiple days discount is disabled in settings');
            }
        }
    } // End tournament multi-child discount check
}

/**
 * Attach same-season discount notes after cart items have been priced.
 * This runs only in the front-end cart/checkout flow because WooCommerce
 * triggers woocommerce_before_calculate_totals while building the cart.
 */
add_action('woocommerce_before_calculate_totals', 'intersoccer_attach_same_season_discount_note', 25);

function intersoccer_attach_same_season_discount_note($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    if (!$cart instanceof WC_Cart || $cart->is_empty()) {
        return;
    }

    // Check if same-season discount is enabled in settings
    $discount_rates = intersoccer_get_discount_rates();
    $same_season_rate = $discount_rates['course']['same_season_course'] ?? null;
    if ($same_season_rate === null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug('InterSoccer: Same-season discount note not attached - discount is disabled in settings');
        }
        return;
    }

    // Check if same-season discount should be disabled when coupons are applied
    $disable_with_coupons = get_option('intersoccer_disable_sibling_discount_with_coupons', false);
    if ($disable_with_coupons) {
        $applied_coupons = $cart->get_applied_coupons();
        if (!empty($applied_coupons)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug('InterSoccer: Same-season discount note disabled - coupons detected: ' . implode(', ', $applied_coupons));
            }
            return;
        }
    }

    // Group by season AND assigned attendee (must be same attendee for same season)
    $season_attendee_groups = [];
    foreach ($cart->get_cart() as $cart_key => $cart_item) {
        $product_type = intersoccer_get_product_type($cart_item['product_id']);
        if ($product_type !== 'course') {
            continue;
        }

        $assigned_player = $cart_item['assigned_attendee'] ?? $cart_item['assigned_player'] ?? null;
        if (empty($assigned_player)) {
            // Skip items without assigned attendee
            continue;
        }

        $variation_id = $cart_item['variation_id'] ?: $cart_item['product_id'];
        $season = get_post_meta($variation_id, 'attribute_pa_program-season', true) ?: 'unknown';
        
        // Group by season AND attendee
        $group_key = $season . '_' . $assigned_player;
        if (!isset($season_attendee_groups[$group_key])) {
            $season_attendee_groups[$group_key] = [
                'season' => $season,
                'attendee' => $assigned_player,
                'items' => []
            ];
        }
        
        $season_attendee_groups[$group_key]['items'][] = [
            'cart_key' => $cart_key,
            'variation_id' => $variation_id,
        ];
    }

    foreach ($season_attendee_groups as $group) {
        $items = $group['items'];
        if (count($items) < 2) {
            continue;
        }

        // Verify all items have the same assigned attendee (double-check)
        $attendees = array_unique(array_filter(array_map(function($item) use ($cart) {
            $cart_item = $cart->cart_contents[$item['cart_key']] ?? null;
            if (!$cart_item) return null;
            return $cart_item['assigned_attendee'] ?? $cart_item['assigned_player'] ?? null;
        }, $items)));
        
        if (count($attendees) !== 1) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug('InterSoccer: Skipping same-season discount note - items have different assigned attendees: ' . implode(', ', $attendees));
            }
            continue;
        }

        // Apply note to second item (index 1)
        $target = $items[1];
        $discount_label = sprintf(__('%d%% Same Season Course Discount', 'intersoccer-product-variations'), $same_season_rate * 100);
        $message = intersoccer_get_discount_message(
            'course_same_season',
            'cart_message',
            $discount_label
        );

        if (!empty($target['cart_key']) && isset($cart->cart_contents[$target['cart_key']])) {
            $cart->cart_contents[$target['cart_key']]['discount_note'] = $message;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug('InterSoccer: Attached same-season discount note to cart item ' . $target['variation_id'] . ' for attendee ' . $group['attendee']);
            }
        }
    }
}
/**
 * Apply camp combo discounts for multiple children
 * 20% discount on 2nd camp, 25% on 3rd and additional camps
 * ONLY applies to full-week bookings, not single-day camps
 */
function intersoccer_apply_camp_combo_discounts($camps_by_child) {
    $rates = intersoccer_get_discount_rates()['camp'];
    $second_child_rate = $rates['2nd_child'] ?? 0.20;
    $third_plus_rate = $rates['3rd_plus_child'] ?? 0.25;
    
    $all_camps = [];
    
    // Flatten all camps across children
    foreach ($camps_by_child as $child_id => $camps) {
        foreach ($camps as $camp) {
            $camp['child_id'] = $child_id;
            $all_camps[] = $camp;
        }
    }
    
    if (count($all_camps) < 2) {
        return;
    }
    
    // Sort by price (descending) to apply discounts to cheaper items
    usort($all_camps, function($a, $b) {
        return $b['price'] <=> $a['price'];
    });
    
    for ($i = 1; $i < count($all_camps); $i++) {
        $camp = $all_camps[$i];
        $discount_percentage = ($i === 1) ? ($second_child_rate * 100) : ($third_plus_rate * 100);
        $discount_rate = ($i === 1) ? $second_child_rate : $third_plus_rate;
        $discount_amount = $camp['price'] * $discount_rate;
        
        // Enhanced discount label with discount type info
        if ($i === 1) {
            $discount_label = sprintf(__('%d%% Sibling Camp Discount', 'intersoccer-product-variations'), $discount_percentage);
        } else {
            $discount_label = sprintf(__('%d%% Multi-Child Camp Discount', 'intersoccer-product-variations'), $discount_percentage);
        }
        
        WC()->cart->add_fee($discount_label, -$discount_amount);
        
        intersoccer_debug(sprintf(
            'InterSoccer: Applied %s: -CHF %.2f (Child %d)',
            $discount_label,
            $discount_amount,
            $camp['child_id'] + 1
        ));
    }
}

/**
 * Apply course combo discounts for multiple children
 * 20% discount for 2nd child, 30% for 3rd and additional children
 */
function intersoccer_apply_course_combo_discounts($courses_by_child) {
    $rates = intersoccer_get_discount_rates()['course'];
    $second_child_rate = $rates['2nd_child'] ?? 0.20;
    $third_plus_rate = $rates['3rd_plus_child'] ?? 0.30;
    
    $children_with_courses = array_keys($courses_by_child);
    
    if (count($children_with_courses) < 2) {
        return;
    }
    
    // Sort children by total course value (descending)
    $children_totals = [];
    foreach ($courses_by_child as $child_id => $courses) {
        $total = array_sum(array_column($courses, 'price'));
        $children_totals[$child_id] = $total;
    }
    arsort($children_totals);
    
    $sorted_children = array_keys($children_totals);
    
    for ($i = 1; $i < count($sorted_children); $i++) {
        $child_id = $sorted_children[$i];
        $discount_percentage = ($i === 1) ? ($second_child_rate * 100) : ($third_plus_rate * 100);
        $discount_rate = ($i === 1) ? $second_child_rate : $third_plus_rate;
        
        foreach ($courses_by_child[$child_id] as $course) {
            $discount_amount = $course['price'] * $discount_rate;
            
            // Enhanced discount label
            if ($i === 1) {
                $discount_label = sprintf(__('%d%% Sibling Course Discount', 'intersoccer-product-variations'), $discount_percentage);
            } else {
                $discount_label = sprintf(__('%d%% Multi-Child Course Discount', 'intersoccer-product-variations'), $discount_percentage);
            }
            
            WC()->cart->add_fee($discount_label, -$discount_amount);
            
            intersoccer_debug(sprintf(
                'InterSoccer: Applied %s: -CHF %.2f (Child %d)',
                $discount_label,
                $discount_amount,
                $child_id + 1
            ));
        }
    }
}

/**
 * Apply tournament combo discounts for multiple children
 * 20% discount for 2nd child, 30% for 3rd and additional children
 */
function intersoccer_apply_tournament_combo_discounts($tournaments_by_child) {
    $rates = intersoccer_get_discount_rates()['tournament'];
    $second_child_rate = $rates['2nd_child'] ?? 0.20;
    $third_plus_rate = $rates['3rd_plus_child'] ?? 0.30;
    
    $children_with_tournaments = array_keys($tournaments_by_child);
    
    if (count($children_with_tournaments) < 2) {
        return;
    }
    
    // Sort children by total tournament value (descending)
    $children_totals = [];
    foreach ($tournaments_by_child as $child_id => $tournaments) {
        $total = array_sum(array_column($tournaments, 'price'));
        $children_totals[$child_id] = $total;
    }
    arsort($children_totals);
    
    $sorted_children = array_keys($children_totals);
    
    for ($i = 1; $i < count($sorted_children); $i++) {
        $child_id = $sorted_children[$i];
        $discount_percentage = ($i === 1) ? ($second_child_rate * 100) : ($third_plus_rate * 100);
        $discount_rate = ($i === 1) ? $second_child_rate : $third_plus_rate;
        
        foreach ($tournaments_by_child[$child_id] as $tournament) {
            $discount_amount = $tournament['price'] * $discount_rate;
            
            // Enhanced discount label
            if ($i === 1) {
                $discount_label = sprintf(__('%d%% Sibling Tournament Discount', 'intersoccer-product-variations'), $discount_percentage);
            } else {
                $discount_label = sprintf(__('%d%% Multi-Child Tournament Discount', 'intersoccer-product-variations'), $discount_percentage);
            }
            
            WC()->cart->add_fee($discount_label, -$discount_amount);
            
            intersoccer_debug(sprintf(
                'InterSoccer: Applied %s: -CHF %.2f (Child %d)',
                $discount_label,
                $discount_amount,
                $child_id + 1
            ));
        }
    }
}

/**
 * Apply same-season course discounts (same child, multiple courses)
 * 50% discount for 2nd course in the same season
 */
function intersoccer_apply_same_season_course_discounts($courses_by_season_child) {
    $rates = intersoccer_get_discount_rates()['course'];
    $combo_rate = $rates['same_season_course'] ?? 0.50;
    
    foreach ($courses_by_season_child as $season => $children_courses) {
        foreach ($children_courses as $child_id => $courses) {
            if (count($courses) < 2) {
                continue;
            }
            
            // Sort by price (descending) to apply discount to cheaper course
            usort($courses, function($a, $b) {
                return $b['price'] <=> $a['price'];
            });
            
            // Apply 50% discount to 2nd course (and any additional courses)
            for ($i = 1; $i < count($courses); $i++) {
                $course = $courses[$i];
                $discount_amount = $course['price'] * $combo_rate;
                
                // Enhanced discount label
                $discount_label = sprintf(
                    __('50%% Same Season Course Discount (%s)', 'intersoccer-product-variations'),
                    $season
                );
                
                WC()->cart->add_fee($discount_label, -$discount_amount);
                
                intersoccer_debug(sprintf(
                    'InterSoccer: Applied %s: -CHF %.2f (Child %d)',
                    $discount_label,
                    $discount_amount,
                    $child_id + 1
                ));
            }
        }
    }
}

/**
 * Determine discount type from fee name
 */
function intersoccer_determine_discount_type($fee_name) {
    $fee_name_lower = strtolower($fee_name);
    
    if (strpos($fee_name_lower, 'camp') !== false && (strpos($fee_name_lower, 'sibling') !== false || strpos($fee_name_lower, 'multi-child') !== false)) {
        return 'camp_sibling';
    } elseif (strpos($fee_name_lower, 'course') !== false && (strpos($fee_name_lower, 'sibling') !== false || strpos($fee_name_lower, 'multi-child') !== false)) {
        return 'course_multi_child';
    } elseif (strpos($fee_name_lower, 'same season') !== false) {
        return 'course_same_season';
    } elseif (strpos($fee_name_lower, 'coupon') !== false) {
        return 'coupon';
    }
    
    return 'other';
}

/**
 * Allocate discounts to order items for precise reporting
 */
function intersoccer_allocate_discounts_to_order_items($order_id, $order, $all_discounts) {
    if (empty($all_discounts)) {
        return;
    }
    
    $order_items = $order->get_items();
    $total_subtotal = 0;
    
    // Calculate total subtotal for proportional allocation
    foreach ($order_items as $item) {
        $total_subtotal += floatval($item->get_subtotal());
    }
    
    if ($total_subtotal <= 0) {
        return;
    }
    
    // Allocate discounts to items
    foreach ($order_items as $item_id => $item) {
        $item_subtotal = floatval($item->get_subtotal());
        $item_discounts = [];
        $total_item_discount = 0;
        
        foreach ($all_discounts as $discount) {
            // For reporting purposes, allocate proportionally
            // This will be enhanced for precise allocation in future updates
            $allocated_amount = ($item_subtotal / $total_subtotal) * $discount['amount'];
            
            if ($allocated_amount > 0.01) { // Only allocate if > 1 cent
                $item_discounts[] = [
                    'name' => $discount['name'],
                    'type' => $discount['type'],
                    'amount' => round($allocated_amount, 2),
                    'allocation_method' => 'proportional_reporting'
                ];
                $total_item_discount += $allocated_amount;
            }
        }
        
        // Store item-level discount data for reporting
        if (!empty($item_discounts)) {
            wc_update_order_item_meta($item_id, '_intersoccer_item_discounts', $item_discounts);
            wc_update_order_item_meta($item_id, '_intersoccer_total_item_discount', round($total_item_discount, 2));
        }
    }
}

/**
 * Add discount information to cart item data for display
 */
add_filter('woocommerce_get_item_data', function($item_data, $cart_item) {
    // Get applicable discounts for this item
    $product_id = $cart_item['product_id'];
    $product_type = intersoccer_get_product_type($product_id);
    $assigned_player = isset($cart_item['assigned_attendee']) ? $cart_item['assigned_attendee'] : null;
    
    if ($assigned_player === null || !$product_type) {
        return $item_data;
    }
    
    // Check what discounts might apply to this item
    $potential_discounts = [];
    $cart_items = WC()->cart->get_cart();
    
    if ($product_type === 'camp') {
        $camps_for_analysis = [];
        foreach ($cart_items as $item) {
            if (intersoccer_get_product_type($item['product_id']) === 'camp' && isset($item['assigned_attendee'])) {
                $variation_id = $item['variation_id'] ?? 0;
                $booking_type = get_post_meta($variation_id ?: $item['product_id'], 'attribute_pa_booking-type', true);
                
                if ($booking_type === 'full-week' || empty($booking_type)) {
                    $camps_for_analysis[] = $item;
                }
            }
        }
        
        if (count($camps_for_analysis) >= 2) {
            $potential_discounts[] = 'Sibling Camp Discount';
        }
    } elseif ($product_type === 'course') {
        $courses_for_analysis = [];
        $same_child_courses = [];
        
        foreach ($cart_items as $item) {
            if (intersoccer_get_product_type($item['product_id']) === 'course' && isset($item['assigned_attendee'])) {
                $courses_for_analysis[] = $item;
                
                if ($item['assigned_attendee'] === $assigned_player) {
                    $same_child_courses[] = $item;
                }
            }
        }
        
        // Check for multi-child discount
        $unique_children = array_unique(array_column($courses_for_analysis, 'assigned_attendee'));
        if (count($unique_children) >= 2) {
            $potential_discounts[] = 'Multi-Child Course Discount';
        }
        
        // Check for same-season discount
        if (count($same_child_courses) >= 2) {
            $potential_discounts[] = 'Same Season Course Discount';
        }
    }
    
    // if (!empty($potential_discounts)) {
    //     $item_data[] = [
    //         'key' => __('Potential Discounts', 'intersoccer-product-variations'),
    //         'value' => implode(', ', $potential_discounts),
    //         'display' => '<small style="color: #0073aa; font-style: italic;">' . implode(', ', $potential_discounts) . '</small>'
    //     ];
    // }
    
    return $item_data;
}, 10, 2);

/**
 * Clear discount session when cart contents change
 */
add_action('woocommerce_cart_item_removed', function() {
    intersoccer_clear_discount_session();
});

add_action('woocommerce_add_to_cart', function() {
    intersoccer_clear_discount_session();
});

add_action('woocommerce_cart_item_restored', function() {
    intersoccer_clear_discount_session();
});

/**
 * Helper function to clear discount session data
 */
function intersoccer_clear_discount_session() {
    if (WC()->session) {
        // Clear all intersoccer fee session data
        $session_data = WC()->session->get_session_data();
        foreach ($session_data as $key => $value) {
            if (strpos($key, 'intersoccer_fees_applied_') === 0) {
                WC()->session->__unset($key);
                intersoccer_debug('InterSoccer: Cleared discount session: ' . $key);
            }
        }
    }
}

/**
 * Debug function to log cart state for discount troubleshooting
 */
function intersoccer_debug_discount_cart() {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    
    intersoccer_debug('=== InterSoccer Discount Debug ===');
    
    if (WC()->cart && !WC()->cart->is_empty()) {
        $cart_items = WC()->cart->get_cart();
        foreach ($cart_items as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $product_type = intersoccer_get_product_type($product_id);
            $assigned_player = $cart_item['assigned_player'] ?? $cart_item['assigned_attendee'] ?? 'not set';
            $price = $cart_item['data']->get_price();
            
            intersoccer_debug("Cart Item: {$cart_item_key}");
            intersoccer_debug("  Product ID: {$product_id}");
            intersoccer_debug("  Product Type: {$product_type}");
            intersoccer_debug("  Assigned Player: {$assigned_player}");
            intersoccer_debug("  Price: {$price}");
        }
        
        $fees = WC()->cart->get_fees();
        intersoccer_debug('Current fees: ' . print_r($fees, true));
    }
    
    intersoccer_debug('=== End Discount Debug ===');
}

// Add debug hook when WP_DEBUG is enabled
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('woocommerce_cart_calculate_fees', 'intersoccer_debug_discount_cart', 999);
}

intersoccer_debug('InterSoccer: Loaded ENHANCED discounts.php with precise allocation and reporting integration');
?>