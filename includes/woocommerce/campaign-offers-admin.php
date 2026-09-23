<?php
/**
 * Admin UI for Campaign Offers (WooCommerce → InterSoccer Discounts).
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', 'intersoccer_register_campaign_offer_settings');
function intersoccer_register_campaign_offer_settings() {
    register_setting(
        'intersoccer_campaign_offers_group',
        'intersoccer_campaign_offers_enabled',
        [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]
    );
}

add_action('admin_init', 'intersoccer_handle_campaign_offers_save');
function intersoccer_handle_campaign_offers_save() {
    if (!isset($_POST['intersoccer_campaign_offers_submit']) && !isset($_POST['intersoccer_campaign_offers_refresh'])) {
        return;
    }
    if (!isset($_POST['intersoccer_campaign_offers_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['intersoccer_campaign_offers_nonce'])), 'intersoccer_save_campaign_offers')) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $enabled = isset($_POST['intersoccer_campaign_offers_enabled']) ? 1 : 0;
    update_option('intersoccer_campaign_offers_enabled', $enabled);

    $posted = isset($_POST['intersoccer_campaign_offers']) && is_array($_POST['intersoccer_campaign_offers'])
        ? wp_unslash($_POST['intersoccer_campaign_offers'])
        : [];

    $refresh_index = isset($_POST['intersoccer_campaign_offers_refresh'])
        ? sanitize_text_field(wp_unslash($_POST['intersoccer_campaign_offers_refresh']))
        : '';

    $existing = intersoccer_get_campaign_offers();
    $offers = [];
    $index = 0;
    $rr_missing_notice = false;
    foreach ($posted as $row) {
        if (!is_array($row)) {
            $index++;
            continue;
        }
        $normalized = intersoccer_normalize_campaign_offer($row);
        if ($normalized === null) {
            $index++;
            continue;
        }

        $prior = $existing[$normalized['id']] ?? [];
        $should_refresh = ((string) $refresh_index === (string) $index)
            || (
                !empty($normalized['restrict_to_distressed'])
                && empty($normalized['distressed_variation_ids'])
                && empty($prior['distressed_variation_ids'])
            );

        if ($should_refresh && !empty($normalized['restrict_to_distressed'])) {
            $snap = intersoccer_campaign_snapshot_distressed_events(
                $normalized['distressed_season'],
                $normalized['distressed_program_year']
            );
            $normalized['distressed_variation_ids'] = $snap['variation_ids'];
            $normalized['distressed_product_ids'] = $snap['product_ids'];
            $normalized['distressed_refreshed_at'] = function_exists('current_time')
                ? current_time('mysql')
                : gmdate('Y-m-d H:i:s');
            if (empty($snap['available'])) {
                $rr_missing_notice = true;
            }
        } elseif (empty($normalized['restrict_to_distressed'])) {
            $normalized['distressed_variation_ids'] = [];
            $normalized['distressed_product_ids'] = [];
            $normalized['distressed_refreshed_at'] = '';
        } else {
            // Keep the posted snapshot — do not auto-mutate as counts rise.
            if ($normalized['distressed_variation_ids'] === [] && !empty($prior['distressed_variation_ids'])) {
                $normalized['distressed_variation_ids'] = $prior['distressed_variation_ids'];
                $normalized['distressed_product_ids'] = $prior['distressed_product_ids'] ?? [];
                $normalized['distressed_refreshed_at'] = $prior['distressed_refreshed_at'] ?? '';
            }
        }

        $normalized = intersoccer_campaign_sync_coupon($normalized);
        $offers[$normalized['id']] = $normalized;
        $index++;
    }

    update_option('intersoccer_campaign_offers', $offers);

    add_settings_error(
        'intersoccer_campaign_offers',
        'saved',
        __('Campaign offers saved.', 'intersoccer-product-variations'),
        'updated'
    );

    if ($rr_missing_notice || (
        array_filter($offers, static function ($offer) {
            return !empty($offer['restrict_to_distressed'])
                && empty($offer['distressed_variation_ids'])
                && !intersoccer_campaign_distressed_api_available();
        })
    )) {
        add_settings_error(
            'intersoccer_campaign_offers',
            'distressed_api_missing',
            __('Reports & Rosters distressed API is not available (intersoccer_reports_distressed_variation_ids). The distressed snapshot stays empty, so restricted offers will not apply until Reports & Rosters is updated and you refresh the list.', 'intersoccer-product-variations'),
            'error'
        );
    }
}

add_action('admin_enqueue_scripts', 'intersoccer_enqueue_campaign_offers_admin_assets');
function intersoccer_enqueue_campaign_offers_admin_assets($hook) {
    if (strpos((string) $hook, 'intersoccer-discounts') === false) {
        return;
    }
    wp_enqueue_script(
        'intersoccer-admin-campaign-offers',
        INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_URL . 'js/admin-campaign-offers.js',
        ['jquery'],
        '2.9.4',
        true
    );
}

/**
 * Render Campaign Offers section on the Discounts screen.
 */
function intersoccer_render_campaign_offers_section() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    settings_errors('intersoccer_campaign_offers');

    $globally_enabled = intersoccer_campaign_offers_globally_enabled();
    $offers = intersoccer_get_campaign_offers();
    $exclusive_keys = intersoccer_campaign_exclusive_keys();
    $exclusive_labels = [
        'camp_sibling' => __('Camp sibling', 'intersoccer-product-variations'),
        'camp_progressive' => __('Camp progressive', 'intersoccer-product-variations'),
        'course_sibling' => __('Course sibling', 'intersoccer-product-variations'),
        'course_same_season' => __('Course same-season', 'intersoccer-product-variations'),
        'tournament_sibling' => __('Tournament sibling', 'intersoccer-product-variations'),
        'tournament_multi_day' => __('Tournament multi-day', 'intersoccer-product-variations'),
        'first_order_referral' => __('First-order referral (CRS)', 'intersoccer-product-variations'),
    ];
    ?>
    <h2><?php esc_html_e('Campaign Offers', 'intersoccer-product-variations'); ?></h2>
    <p><?php esc_html_e('Time-boxed promotional discounts. The coupon code is the customer-facing entry; the percent is applied in the InterSoccer discount pipeline (higher percent wins, then the cap). Native WooCommerce coupon amount is always 0% so it cannot stack. Seeded Autumn codes are disabled until you enable them.', 'intersoccer-product-variations'); ?></p>

    <form method="post" id="intersoccer-campaign-offers-form">
        <?php wp_nonce_field('intersoccer_save_campaign_offers', 'intersoccer_campaign_offers_nonce'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="intersoccer_campaign_offers_enabled"><?php esc_html_e('Enable campaign offers', 'intersoccer-product-variations'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" name="intersoccer_campaign_offers_enabled" id="intersoccer_campaign_offers_enabled" value="1" <?php checked($globally_enabled); ?> />
                        <?php esc_html_e('Kill switch: uncheck to invalidate all campaign coupons immediately.', 'intersoccer-product-variations'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <div id="intersoccer-campaign-offers-list">
            <?php
            $index = 0;
            foreach ($offers as $offer) {
                intersoccer_render_campaign_offer_card($offer, $index, $exclusive_keys, $exclusive_labels);
                $index++;
            }
            ?>
        </div>

        <p>
            <button type="button" class="button" id="intersoccer-add-campaign-offer"><?php esc_html_e('Add campaign offer', 'intersoccer-product-variations'); ?></button>
            <button type="submit" class="button button-primary" name="intersoccer_campaign_offers_submit" value="1"><?php esc_html_e('Save campaign offers', 'intersoccer-product-variations'); ?></button>
        </p>
    </form>

    <script type="text/html" id="intersoccer-campaign-offer-template">
        <?php
        intersoccer_render_campaign_offer_card([
            'id' => '',
            'enabled' => false,
            'name' => '',
            'code' => '',
            'percent' => 15,
            'max_cap_percent' => 20,
            'product_ids' => [],
            'excluded_product_ids' => [],
            'product_categories' => [],
            'excluded_product_categories' => [],
            'product_tags' => [],
            'starts_at' => '',
            'ends_at' => '',
            'requires_group_field' => false,
            'group_field_label' => __('Who is your child joining?', 'intersoccer-product-variations'),
            'group_field_placeholder' => __('Friend or sibling name', 'intersoccer-product-variations'),
            'group_field_error' => __('Please enter who your child is joining.', 'intersoccer-product-variations'),
            'exclusive_with' => [],
            'coupon_id' => 0,
            'restrict_to_distressed' => false,
            'distressed_season' => 'autumn',
            'distressed_program_year' => '2026',
            'distressed_variation_ids' => [],
            'distressed_product_ids' => [],
            'distressed_refreshed_at' => '',
        ], '__INDEX__', $exclusive_keys, $exclusive_labels);
        ?>
    </script>
    <?php
    if (function_exists('intersoccer_render_campaign_leads_section')) {
        intersoccer_render_campaign_leads_section();
    }
}

/**
 * @param array $offer
 * @param int|string $index
 * @param array<int,string> $exclusive_keys
 * @param array<string,string> $exclusive_labels
 */
function intersoccer_render_campaign_offer_card(array $offer, $index, array $exclusive_keys, array $exclusive_labels) {
    $prefix = 'intersoccer_campaign_offers[' . $index . ']';
    $starts = !empty($offer['starts_at']) ? str_replace(' ', 'T', substr($offer['starts_at'], 0, 16)) : '';
    $ends = !empty($offer['ends_at']) ? str_replace(' ', 'T', substr($offer['ends_at'], 0, 16)) : '';
    ?>
    <div class="intersoccer-campaign-offer-card" style="background:#fff;border:1px solid #ccd0d4;padding:16px;margin-bottom:16px;">
        <input type="hidden" name="<?php echo esc_attr($prefix); ?>[id]" value="<?php echo esc_attr($offer['id']); ?>" />
        <input type="hidden" name="<?php echo esc_attr($prefix); ?>[coupon_id]" value="<?php echo esc_attr((string) ($offer['coupon_id'] ?? 0)); ?>" />
        <p>
            <label>
                <input type="checkbox" name="<?php echo esc_attr($prefix); ?>[enabled]" value="1" <?php checked(!empty($offer['enabled'])); ?> />
                <?php esc_html_e('Enabled', 'intersoccer-product-variations'); ?>
            </label>
            <button type="button" class="button-link-delete intersoccer-remove-campaign-offer" style="float:right;"><?php esc_html_e('Remove', 'intersoccer-product-variations'); ?></button>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th><?php esc_html_e('Internal name', 'intersoccer-product-variations'); ?></th>
                <td><input type="text" class="regular-text" name="<?php echo esc_attr($prefix); ?>[name]" value="<?php echo esc_attr($offer['name']); ?>" /></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Coupon code', 'intersoccer-product-variations'); ?></th>
                <td><input type="text" class="regular-text" name="<?php echo esc_attr($prefix); ?>[code]" value="<?php echo esc_attr($offer['code']); ?>" pattern="[A-Za-z0-9_\-]+" /></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Discount %', 'intersoccer-product-variations'); ?></th>
                <td>
                    <input type="number" min="0" max="100" step="0.1" name="<?php echo esc_attr($prefix); ?>[percent]" value="<?php echo esc_attr((string) $offer['percent']); ?>" class="small-text" />
                    <label style="margin-left:12px;"><?php esc_html_e('Max cap %', 'intersoccer-product-variations'); ?>
                        <input type="number" min="0" max="100" step="0.1" name="<?php echo esc_attr($prefix); ?>[max_cap_percent]" value="<?php echo esc_attr((string) $offer['max_cap_percent']); ?>" class="small-text" />
                    </label>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Start / end (site timezone)', 'intersoccer-product-variations'); ?></th>
                <td>
                    <input type="datetime-local" name="<?php echo esc_attr($prefix); ?>[starts_at]" value="<?php echo esc_attr($starts); ?>" />
                    <input type="datetime-local" name="<?php echo esc_attr($prefix); ?>[ends_at]" value="<?php echo esc_attr($ends); ?>" />
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Eligible product IDs', 'intersoccer-product-variations'); ?></th>
                <td>
                    <input type="text" class="large-text" name="<?php echo esc_attr($prefix); ?>[product_ids]" value="<?php echo esc_attr(implode(',', $offer['product_ids'])); ?>" placeholder="<?php esc_attr_e('Comma-separated; blank = all', 'intersoccer-product-variations'); ?>" />
                    <p class="description"><?php esc_html_e('Blank still means all products unless Restrict to distressed events is on.', 'intersoccer-product-variations'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Distressed events', 'intersoccer-product-variations'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($prefix); ?>[restrict_to_distressed]" value="1" <?php checked(!empty($offer['restrict_to_distressed'])); ?> />
                        <?php esc_html_e('Restrict to distressed events', 'intersoccer-product-variations'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Uses Final Numbers Critical/Low variation IDs from Reports & Rosters. Snapshot is frozen until you refresh — counts rising later do not yank the discount.', 'intersoccer-product-variations'); ?></p>
                    <p>
                        <label><?php esc_html_e('Season', 'intersoccer-product-variations'); ?>
                            <input type="text" name="<?php echo esc_attr($prefix); ?>[distressed_season]" value="<?php echo esc_attr((string) ($offer['distressed_season'] ?? '')); ?>" placeholder="autumn" class="regular-text" />
                        </label>
                        <label style="margin-left:12px;"><?php esc_html_e('Program year', 'intersoccer-product-variations'); ?>
                            <input type="text" name="<?php echo esc_attr($prefix); ?>[distressed_program_year]" value="<?php echo esc_attr((string) ($offer['distressed_program_year'] ?? '')); ?>" placeholder="2026" class="small-text" />
                        </label>
                    </p>
                    <input type="hidden" name="<?php echo esc_attr($prefix); ?>[distressed_variation_ids]" value="<?php echo esc_attr(implode(',', $offer['distressed_variation_ids'] ?? [])); ?>" />
                    <input type="hidden" name="<?php echo esc_attr($prefix); ?>[distressed_product_ids]" value="<?php echo esc_attr(implode(',', $offer['distressed_product_ids'] ?? [])); ?>" />
                    <input type="hidden" name="<?php echo esc_attr($prefix); ?>[distressed_refreshed_at]" value="<?php echo esc_attr((string) ($offer['distressed_refreshed_at'] ?? '')); ?>" />
                    <p>
                        <button type="submit" class="button" name="intersoccer_campaign_offers_refresh" value="<?php echo esc_attr((string) $index); ?>">
                            <?php esc_html_e('Refresh list', 'intersoccer-product-variations'); ?>
                        </button>
                        <?php
                        $snap_count = count($offer['distressed_variation_ids'] ?? []);
                        $refreshed = (string) ($offer['distressed_refreshed_at'] ?? '');
                        if ($snap_count > 0) {
                            printf(
                                /* translators: 1: variation count, 2: timestamp */
                                esc_html__('Snapshot: %1$d variation IDs%2$s.', 'intersoccer-product-variations'),
                                (int) $snap_count,
                                $refreshed !== '' ? ' (' . esc_html($refreshed) . ')' : ''
                            );
                        } else {
                            esc_html_e('Snapshot is empty.', 'intersoccer-product-variations');
                        }
                        ?>
                    </p>
                    <?php if (!empty($offer['restrict_to_distressed']) && !intersoccer_campaign_distressed_api_available()) : ?>
                        <div class="notice notice-error inline"><p>
                            <?php esc_html_e('Reports & Rosters distressed API is not available. Snapshot stays empty until intersoccer_reports_distressed_variation_ids() is present, then refresh.', 'intersoccer-product-variations'); ?>
                        </p></div>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Excluded product IDs', 'intersoccer-product-variations'); ?></th>
                <td>
                    <input type="text" class="large-text" name="<?php echo esc_attr($prefix); ?>[excluded_product_ids]" value="<?php echo esc_attr(implode(',', $offer['excluded_product_ids'])); ?>" />
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Category / tag term IDs', 'intersoccer-product-variations'); ?></th>
                <td>
                    <label><?php esc_html_e('Categories', 'intersoccer-product-variations'); ?>
                        <input type="text" name="<?php echo esc_attr($prefix); ?>[product_categories]" value="<?php echo esc_attr(implode(',', $offer['product_categories'])); ?>" />
                    </label>
                    <label><?php esc_html_e('Excluded categories', 'intersoccer-product-variations'); ?>
                        <input type="text" name="<?php echo esc_attr($prefix); ?>[excluded_product_categories]" value="<?php echo esc_attr(implode(',', $offer['excluded_product_categories'])); ?>" />
                    </label>
                    <label><?php esc_html_e('Tags', 'intersoccer-product-variations'); ?>
                        <input type="text" name="<?php echo esc_attr($prefix); ?>[product_tags]" value="<?php echo esc_attr(implode(',', $offer['product_tags'])); ?>" />
                    </label>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Group field', 'intersoccer-product-variations'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($prefix); ?>[requires_group_field]" value="1" <?php checked(!empty($offer['requires_group_field'])); ?> />
                        <?php esc_html_e('Require “who is your child joining?” when this code is applied', 'intersoccer-product-variations'); ?>
                    </label>
                    <p>
                        <input type="text" class="regular-text" name="<?php echo esc_attr($prefix); ?>[group_field_label]" value="<?php echo esc_attr($offer['group_field_label']); ?>" placeholder="<?php esc_attr_e('Label', 'intersoccer-product-variations'); ?>" />
                    </p>
                    <p>
                        <input type="text" class="regular-text" name="<?php echo esc_attr($prefix); ?>[group_field_placeholder]" value="<?php echo esc_attr($offer['group_field_placeholder']); ?>" placeholder="<?php esc_attr_e('Placeholder', 'intersoccer-product-variations'); ?>" />
                    </p>
                    <p>
                        <input type="text" class="large-text" name="<?php echo esc_attr($prefix); ?>[group_field_error]" value="<?php echo esc_attr($offer['group_field_error']); ?>" placeholder="<?php esc_attr_e('Error message', 'intersoccer-product-variations'); ?>" />
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Cannot stack with', 'intersoccer-product-variations'); ?></th>
                <td>
                    <?php foreach ($exclusive_keys as $key) : ?>
                        <label style="display:inline-block;margin-right:12px;">
                            <input type="checkbox" name="<?php echo esc_attr($prefix); ?>[exclusive_with][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $offer['exclusive_with'], true)); ?> />
                            <?php echo esc_html($exclusive_labels[$key] ?? $key); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>
    </div>
    <?php
}
