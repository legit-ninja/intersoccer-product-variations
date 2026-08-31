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
    if (!isset($_POST['intersoccer_campaign_offers_submit'])) {
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

    $offers = [];
    foreach ($posted as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = intersoccer_normalize_campaign_offer($row);
        if ($normalized === null) {
            continue;
        }
        $normalized = intersoccer_campaign_sync_coupon($normalized);
        $offers[$normalized['id']] = $normalized;
    }

    update_option('intersoccer_campaign_offers', $offers);

    add_settings_error(
        'intersoccer_campaign_offers',
        'saved',
        __('Campaign offers saved.', 'intersoccer-product-variations'),
        'updated'
    );
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
        '2.8.31',
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
        ], '__INDEX__', $exclusive_keys, $exclusive_labels);
        ?>
    </script>
    <?php
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
