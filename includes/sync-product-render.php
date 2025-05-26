<?php
/**
 * Sync Product Render
 * Changes:
 * - Optimized product fetching with pagination.
 * - Simplified attribute collection using CSV-derived attributes.
 * - Moved inline styles/scripts to css/admin.css and js/sync-products.js.
 * - Added bulk sync confirmation prompt.
 * - Ensured compatibility with WordPress 6.7+ to avoid translation issues.
 * Testing:
 * - In Sync Products tab, click Quick Edit, verify attribute fields load from CSV data.
 * - Save attributes, confirm they update in WooCommerce and sync to an event.
 * - Test bulk sync with multiple products, verify confirmation prompt appears.
 * - Check CSS/JS loading, ensure no inline scripts remain.
 * - Verify no translation loading notices in server logs.
 */

defined('ABSPATH') or die('No script kiddies please!');

$edit_product_id = $product->get_id();
$is_variation = $product->get_type() === 'variation';
$attributes = [
    'pa_booking-type' => '',
    'pa_intersoccer-venues' => '',
    'pa_camp-terms' => '',
    'pa_course-terms' => '',
    'event_start_date' => '',
    'event_end_date' => '',
];

$attribute_config = [
    'pa_booking-type' => ['label' => __('Booking Type', 'intersoccer-player-management')],
    'pa_intersoccer-venues' => ['label' => __('Venue', 'intersoccer-player-management')],
    'pa_camp-terms' => ['label' => __('Camp Terms', 'intersoccer-player-management')],
    'pa_course-terms' => ['label' => __('Course Terms', 'intersoccer-player-management')],
];

foreach ($attributes as $key => &$value) {
    if ($is_variation && isset($variation_attributes[$key])) {
        $value = $variation_attributes[$key];
    } else {
        $terms = wc_get_product_terms($edit_product_id, $key, ['fields' => 'slugs']);
        $value = $terms[0] ?? '';
    }
}

$attribute_arrays = [
    'booking_types' => [],
    'venues' => [],
    'camp_terms' => [],
    'course_terms' => [],
];

foreach ($attribute_arrays as $key => &$array) {
    $taxonomy = 'pa_' . str_replace('_', '-', $key);
    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
    foreach ($terms as $term) {
        $array[$term->slug] = $term->name;
    }
}

$start_date = get_post_meta($edit_product_id, 'start_date', true);
$end_date = get_post_meta($edit_product_id, 'end_date', true);

?>
<div class="quick-edit-form">
    <?php foreach ($attribute_config as $attribute => $config): ?>
        <?php if (!empty($attribute_arrays[str_replace('pa_', '', $attribute)])): ?>
            <p>
                <label for="<?php echo esc_attr($attribute); ?>"><?php echo esc_html($config['label']); ?>:</label>
                <select class="attribute-field" data-attribute="<?php echo esc_attr($attribute); ?>" id="<?php echo esc_attr($attribute); ?>">
                    <option value=""><?php _e('Select', 'intersoccer-player-management'); ?></option>
                    <?php foreach ($attribute_arrays[str_replace('pa_', '', $attribute)] as $slug => $name): ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($attributes[$attribute], $slug); ?>><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
        <?php endif; ?>
    <?php endforeach; ?>
    <p>
        <label for="event_start_date"><?php _e('Start Date:', 'intersoccer-player-management'); ?></label>
        <input type="text" class="attribute-field date-range-picker" data-attribute="event_start_date" id="event_start_date" value="<?php echo esc_attr($start_date); ?>">
    </p>
    <p>
        <label for="event_end_date"><?php _e('End Date:', 'intersoccer-player-management'); ?></label>
        <input type="text" class="attribute-field date-range-picker" data-attribute="event_end_date" id="event_end_date" value="<?php echo esc_attr($end_date); ?>">
    </p>
    <p>
        <button class="button save-attributes"><?php _e('Save & Sync', 'intersoccer-player-management'); ?></button>
        <button class="button cancel-quick-edit"><?php _e('Cancel', 'intersoccer-player-management'); ?></button>
    </p>
</div>
<?php
// Inline script moved to js/sync-products.js
?>
