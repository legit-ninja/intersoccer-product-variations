<?php
/**
 * File: discounts.php
 * Description: Handles discount application for InterSoccer WooCommerce products based on rules in wp_options.
 * Dependencies: WooCommerce, product-types.php, product-camps.php, product-course.php
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class to handle discount calculations and application.
 */
class InterSoccer_Discounts {

    /**
     * Apply discounts to cart items.
     *
     * @param WC_Cart $cart Cart object.
     */
    public static function apply_discounts($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        error_log('InterSoccer: Applying discounts on cart calculate totals');

        $cart_items = $cart->get_cart();
        $rules = get_option('intersoccer_discount_rules', []);
        error_log('InterSoccer: Loaded discount rules from wp_options: ' . print_r($rules, true));
        $grouped_items = [];
        $player_seasons = [];

        // Group items by season and player
        foreach ($cart_items as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $variation_id = $cart_item['variation_id'] ?: $product_id;
            $product_type = InterSoccer_Product_Types::get_product_type($product_id);
            $player_id = isset($cart_item['player_assignment']) ? $cart_item['player_assignment'] : null;
            $season = get_post_meta($variation_id, 'attribute_pa_program-season', true) ?: 'unknown';

            if (in_array($product_type, ['camp', 'course', 'general']) && $player_id) {
                $grouped_items[$season][$player_id][] = [
                    'key' => $cart_item_key,
                    'item' => $cart_item
                ];
                if ($product_type === 'course') {
                    $player_seasons[$season][$player_id][] = $cart_item_key;
                }
            }
        }

        // Apply discounts per season group
        foreach ($grouped_items as $season => $players) {
            // Camp and General discounts
            $unique_players = array_keys($players);
            $player_count = count($unique_players);
            if ($player_count > 1) {
                foreach ($players as $player_id => $items) {
                    $player_index = array_search($player_id, $unique_players);
                    foreach ($items as $item_data) {
                        $cart_item_key = $item_data['key'];
                        $cart_item = $item_data['item'];
                        $product_id = $cart_item['product_id'];
                        $variation_id = $cart_item['variation_id'] ?: $product_id;
                        $product_type = InterSoccer_Product_Types::get_product_type($product_id);

                        // Skip if not applicable
                        if (!in_array($product_type, ['camp', 'general'])) {
                            continue;
                        }

                        $base_price = floatval($cart_item['data']->get_regular_price());
                        $calculated_price = self::get_base_price($product_id, $variation_id, $cart_item, $product_type);
                        $booking_type = get_post_meta($variation_id, 'attribute_pa_booking-type', true) ?: 'full-week';
                        $age_group = get_post_meta($variation_id, 'attribute_pa_age-group', true) ?: 'unknown';

                        $discount = 0;
                        $discount_note = '';
                        foreach ($rules as $rule) {
                            if (!$rule['active'] || ($rule['type'] !== $product_type && $rule['type'] !== 'general')) {
                                continue;
                            }

                            if ($product_type === 'camp' && $player_index > 0) {
                                if ($rule['condition'] === '2nd_child' && $player_index == 1) {
                                    $discount_rate = $rule['rate'] / 100;
                                    $is_half_day = strpos($age_group, 'Half-Day') !== false;
                                    $reference_item = self::get_reference_item($players, $unique_players[0]);
                                    $reference_age_group = $reference_item ? get_post_meta($reference_item['variation_id'] ?: $reference_item['product_id'], 'attribute_pa_age-group', true) : 'unknown';
                                    $is_reference_half_day = strpos($reference_age_group, 'Half-Day') !== false;

                                    if ($is_half_day && !$is_reference_half_day) {
                                        $discount = $base_price * $discount_rate;
                                    } elseif (!$is_half_day && $is_reference_half_day) {
                                        $reference_base_price = $reference_item ? self::get_base_price($reference_item['product_id'], $reference_item['variation_id'], $reference_item, $product_type) : $base_price;
                                        $discount = min($base_price, $reference_base_price) * $discount_rate;
                                    } else {
                                        $discount = $base_price * $discount_rate;
                                    }
                                    $discount_note = sprintf(__('%d%% Family Discount (%s Child)', 'intersoccer-player-management'), $rule['rate'], '2nd');
                                } elseif ($rule['condition'] === '3rd_plus_child' && $player_index >= 2) {
                                    $discount_rate = $rule['rate'] / 100;
                                    $discount = $base_price * $discount_rate;
                                    $discount_note = sprintf(__('%d%% Family Discount (%s Child)', 'intersoccer-player-management'), $rule['rate'], ($player_index == 2 ? '3rd' : 'Additional'));
                                }
                            } elseif ($rule['condition'] === 'none' && $rule['type'] === 'general') {
                                $discount_rate = $rule['rate'] / 100;
                                $discount = $base_price * $discount_rate;
                                $discount_note = sprintf(__('%d%% %s', 'intersoccer-player-management'), $rule['rate'], $rule['name']);
                            }
                        }

                        if ($discount > 0) {
                            $final_price = $calculated_price - $discount;
                            $cart->cart_contents[$cart_item_key]['data']->set_price($final_price);
                            $cart->cart_contents[$cart_item_key]['combo_discount_note'] = $discount_note;
                            $cart->cart_contents[$cart_item_key]['discount_applied'] = $discount_note;
                            error_log("InterSoccer: Applied $discount_note for $product_type $cart_item_key (Season: $season, Player: $player_id, Index: $player_index) at " . current_time('Y-m-d H:i:s'));
                        } else {
                            $cart->cart_contents[$cart_item_key]['data']->set_price($calculated_price);
                            unset($cart->cart_contents[$cart_item_key]['combo_discount_note']);
                            unset($cart->cart_contents[$cart_item_key]['discount_applied']);
                            error_log("InterSoccer: No discount applied for $cart_item_key (Type: $product_type, Season: $season, Player: $player_id, Index: $player_index)");
                        }
                    }
                }
            }

            // Course discounts
            if (!empty($player_seasons[$season])) {
                $unique_players = array_keys($player_seasons[$season]);
                $player_count = count($unique_players);
                if ($player_count > 1) {
                    foreach ($player_seasons[$season] as $player_id => $item_keys) {
                        $player_index = array_search($player_id, $unique_players);
                        foreach ($item_keys as $cart_item_key) {
                            $cart_item = $cart_items[$cart_item_key];
                            $product_id = $cart_item['product_id'];
                            $variation_id = $cart_item['variation_id'] ?: $product_id;
                            $product_type = InterSoccer_Product_Types::get_product_type($product_id);

                            if ($product_type !== 'course') {
                                continue;
                            }

                            $base_price = floatval($cart_item['data']->get_regular_price());
                            $calculated_price = InterSoccer_Course::calculate_price($product_id, $variation_id);
                            $discount = 0;
                            $discount_note = '';

                            foreach ($rules as $rule) {
                                if (!$rule['active'] || ($rule['type'] !== 'course' && $rule['type'] !== 'general')) {
                                    continue;
                                }

                                if ($rule['condition'] === '2nd_child' && $player_index == 1) {
                                    $discount_rate = $rule['rate'] / 100;
                                    $discount = $base_price * $discount_rate;
                                    $discount_note = sprintf(__('%d%% Family Discount (2nd Child)', 'intersoccer-player-management'), $rule['rate']);
                                } elseif ($rule['condition'] === '3rd_plus_child' && $player_index >= 2) {
                                    $discount_rate = $rule['rate'] / 100;
                                    $discount = $base_price * $discount_rate;
                                    $discount_note = sprintf(__('%d%% Family Discount (%s Child)', 'intersoccer-player-management'), $rule['rate'], ($player_index == 2 ? '3rd' : 'Additional'));
                                } elseif ($rule['condition'] === 'none' && $rule['type'] === 'general') {
                                    $discount_rate = $rule['rate'] / 100;
                                    $discount = $base_price * $discount_rate;
                                    $discount_note = sprintf(__('%d%% %s', 'intersoccer-player-management'), $rule['rate'], $rule['name']);
                                }
                            }

                            if ($discount > 0) {
                                $final_price = $calculated_price - $discount;
                                $cart->cart_contents[$cart_item_key]['data']->set_price($final_price);
                                $cart->cart_contents[$cart_item_key]['combo_discount_note'] = $discount_note;
                                $cart->cart_contents[$cart_item_key]['discount_applied'] = $discount_note;
                                error_log("InterSoccer: Applied $discount_note for course $cart_item_key (Season: $season, Player: $player_id, Index: $player_index)");
                            } else {
                                $cart->cart_contents[$cart_item_key]['data']->set_price($calculated_price);
                                unset($cart->cart_contents[$cart_item_key]['combo_discount_note']);
                                unset($cart->cart_contents[$cart_item_key]['discount_applied']);
                                error_log("InterSoccer: No discount applied for course $cart_item_key (Season: $season, Player: $player_id, Index: $player_index)");
                            }
                        }
                    }
                }

                // Same-season course discount for same child
                foreach ($player_seasons[$season] as $player_id => $item_keys) {
                    if (count($item_keys) > 1) {
                        $course_index = 0;
                        foreach ($item_keys as $cart_item_key) {
                            $course_index++;
                            if ($course_index == 1) {
                                continue; // Skip first course
                            }
                            $cart_item = $cart_items[$cart_item_key];
                            $product_id = $cart_item['product_id'];
                            $variation_id = $cart_item['variation_id'] ?: $product_id;
                            $product_type = InterSoccer_Product_Types::get_product_type($product_id);

                            if ($product_type !== 'course') {
                                continue;
                            }

                            $base_price = floatval($cart_item['data']->get_regular_price());
                            $calculated_price = InterSoccer_Course::calculate_price($product_id, $variation_id);
                            $discount = 0;
                            $discount_note = '';

                            foreach ($rules as $rule) {
                                if (!$rule['active'] || ($rule['type'] !== 'course' && $rule['type'] !== 'general')) {
                                    continue;
                                }

                                if ($rule['condition'] === 'same_season_course' && $course_index > 1) {
                                    $discount_rate = $rule['rate'] / 100;
                                    $discount = $base_price * $discount_rate;
                                    $discount_note = sprintf(__('%d%% Course Combo Discount', 'intersoccer-player-management'), $rule['rate']);
                                } elseif ($rule['condition'] === 'none' && $rule['type'] === 'general') {
                                    $discount_rate = $rule['rate'] / 100;
                                    $discount = $base_price * $discount_rate;
                                    $discount_note = sprintf(__('%d%% %s', 'intersoccer-player-management'), $rule['rate'], $rule['name']);
                                }
                            }

                            if ($discount > 0) {
                                $final_price = $calculated_price - $discount;
                                $cart->cart_contents[$cart_item_key]['data']->set_price($final_price);
                                $cart->cart_contents[$cart_item_key]['combo_discount_note'] = $discount_note;
                                $cart->cart_contents[$cart_item_key]['discount_applied'] = $discount_note;
                                error_log("InterSoccer: Applied $discount_note for course $cart_item_key (Season: $season, Player: $player_id, Course Index: $course_index)");
                            } else {
                                $cart->cart_contents[$cart_item_key]['data']->set_price($calculated_price);
                                unset($cart->cart_contents[$cart_item_key]['combo_discount_note']);
                                unset($cart->cart_contents[$cart_item_key]['discount_applied']);
                                error_log("InterSoccer: No discount applied for course $cart_item_key (Season: $season, Player: $player_id, Course Index: $course_index)");
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Get base price for a product based on type.
     *
     * @param int $product_id Product ID.
     * @param int $variation_id Variation ID.
     * @param array $cart_item Cart item data.
     * @param string $product_type Product type.
     * @return float Base price.
     */
    private static function get_base_price($product_id, $variation_id, $cart_item, $product_type) {
        if ($product_type === 'camp') {
            return InterSoccer_Camp::calculate_price($product_id, $variation_id, $cart_item['camp_days'] ?? []);
        } elseif ($product_type === 'course') {
            return InterSoccer_Course::calculate_price($product_id, $variation_id);
        } else {
            $product = wc_get_product($variation_id ?: $product_id);
            return $product ? floatval($product->get_price()) : 0;
        }
    }

    /**
     * Get reference item for camp discount comparison.
     *
     * @param array $players Players grouped by season.
     * @param string $first_player_id First player ID.
     * @return array|null Reference item.
     */
    private static function get_reference_item($players, $first_player_id) {
        foreach ($players[$first_player_id] as $ref_item) {
            if (get_post_meta($ref_item['item']['variation_id'] ?: $ref_item['item']['product_id'], 'attribute_pa_booking-type', true) === 'full-week') {
                return $ref_item['item'];
            }
        }
        return null;
    }

    /**
     * Modify variation prices for frontend display.
     *
     * @param array $prices Variation prices.
     * @param WC_Product $product Product object.
     * @param bool $for_display For display purposes.
     * @return array Modified prices.
     */
    public static function modify_variation_prices($prices, $product, $for_display) {
        $product_id = $product->get_id();
        error_log('InterSoccer: Modifying variation prices for product ' . $product_id . ' during rendering');

        foreach ($prices['price'] as $variation_id => $price) {
            $product_type = InterSoccer_Product_Types::get_product_type($product_id);
            $calculated_price = 0;

            if ($product_type === 'camp') {
                $calculated_price = InterSoccer_Camp::calculate_price($product_id, $variation_id, []);
            } elseif ($product_type === 'course') {
                $calculated_price = InterSoccer_Course::calculate_price($product_id, $variation_id);
            } else {
                $calculated_price = floatval($price);
            }

            $prices['price'][$variation_id] = $calculated_price;
            $prices['regular_price'][$variation_id] = $calculated_price;
            $prices['sale_price'][$variation_id] = $calculated_price;
            error_log('InterSoccer: Set price for variation ' . $variation_id . ': ' . $calculated_price);
        }

        return $prices;
    }

    /**
     * Calculate discount note for a variation.
     *
     * @param int $variation_id Variation ID.
     * @param array $cart_item Cart item data.
     * @return string Discount note.
     */
    public static function calculate_discount_note($variation_id, $cart_item = []) {
        $product_id = $cart_item['product_id'] ?? $variation_id;
        $product_type = InterSoccer_Product_Types::get_product_type($product_id);
        $discount_note = '';

        if ($product_type === 'camp') {
            $discount_note = InterSoccer_Camp::calculate_discount_note($variation_id, $cart_item['camp_days'] ?? []);
        } elseif ($product_type === 'course') {
            $total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
            $remaining_sessions = InterSoccer_Course::calculate_remaining_sessions($variation_id, $total_weeks);
            $discount_note = InterSoccer_Course::calculate_discount_note($variation_id, $remaining_sessions);
        }

        // Check for general discounts
        $rules = get_option('intersoccer_discount_rules', []);
        foreach ($rules as $rule) {
            if ($rule['active'] && $rule['type'] === 'general' && $rule['condition'] === 'none') {
                $discount_note = sprintf(__('%d%% %s', 'intersoccer-player-management'), $rule['rate'], $rule['name']);
                break;
            }
        }

        error_log('InterSoccer: Calculated discount note for variation ' . $variation_id . ': ' . $discount_note);
        return $discount_note;
    }
}

// Hooks
add_action('woocommerce_before_calculate_totals', ['InterSoccer_Discounts', 'apply_discounts'], 10, 1);
add_filter('woocommerce_variation_prices', ['InterSoccer_Discounts', 'modify_variation_prices'], 10, 3);

error_log('InterSoccer: Defined discount functions in discounts.php');
?>