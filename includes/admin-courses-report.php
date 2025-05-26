<?php
/**
 * Admin Courses Report
 * Changes (Updated):
 * - Removed duplicate intersoccer_render_historical_comparison() to avoid redeclaration.
 * - References intersoccer_render_historical_comparison() from admin-camps-report.php.
 * - Refactored to share logic with admin-camps-report.php via intersoccer_render_report_tab.
 * - Added pagination, Flatpickr, and CSV streaming.
 * - Integrated historical comparison with 2024 data (assumed similar structure to camps).
 * - Added AJAX roster endpoint for courses.
 * Testing:
 * - Navigate to Players & Orders > Courses Report, verify pagination (20 orders/page).
 * - Test date range picker with Flatpickr, ensure valid ranges filter orders.
 * - Use AJAX to fetch roster for a course product, confirm player data loads.
 * - Export CSV, verify file downloads without memory errors.
 * - Check Historical Comparison tab, ensure 2024 vs. 2025 data displays correctly.
 */

defined('ABSPATH') or die('No script kiddies please!');

function intersoccer_render_courses_report_tab() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-player-management'));
    }

    wp_enqueue_script('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', [], '4.6.13', true);
    wp_enqueue_style('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr/dist

/flatpickr.min.css', [], '4.6.13');

    intersoccer_render_report_tab('courses', 'full_term');
}

function intersoccer_render_report_tab($type = 'camps', $booking_type = 'week') {
    $items_per_page = 20;
    $current_page = max(1, absint($_GET['paged'] ?? 1));
    $offset = ($current_page - 1) * $items_per_page;

    $filters = [
        'name' => sanitize_text_field($_GET["{$type}_name"] ?? ''),
        'date_range' => sanitize_text_field($_GET['date_range'] ?? ''),
        'venue' => sanitize_text_field($_GET['venue'] ?? ''),
    ];

    $args = [
        'limit' => $items_per_page,
        'offset' => $offset,
        'status' => ['completed', 'processing'],
    ];
    if (!empty($filters['date_range'])) {
        $dates = explode(' to ', $filters['date_range']);
        if (count($dates) === 2) {
            $args['date_created'] = sanitize_text_field($dates[0]) . '...' . sanitize_text_field($dates[1]);
        }
    }

    $orders = wc_get_orders($args);
    $all_items = [];
    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $players = [];
            foreach ($item->get_meta_data() as $meta) {
                if (preg_match('/^Player (\d+) Name$/', $meta->key, $matches)) {
                    $index = $matches[1];
                    $players[] = [
                        'name' => $meta->value,
                        'dob' => $item->get_meta("Player {$index} DOB", true),
                        'gender' => $item->get_meta("Player {$index} Gender", true),
                    ];
                }
            }
            $all_items[] = [
                'order' => $order,
                'product' => $product,
                'players' => $players,
                'venue' => $item->get_meta('Venue', true),
            ];
        }
    }

    $total_orders = count(wc_get_orders(['limit' => -1, 'status' => ['completed', 'processing']]));
    $total_pages = ceil($total_orders / $items_per_page);

    if (isset($_GET['export_csv']) && wp_verify_nonce($_GET['export_nonce'], "intersoccer_export_{$type}")) {
        intersoccer_export_report($all_items, $type);
    }

    ?>
    <div class="wrap">
        <h1><?php echo $type === 'camps' ? __('Camps Report', 'intersoccer-player-management') : __('Courses Report', 'intersoccer-player-management'); ?></h1>
        <ul class="subsubsub">
            <li><a href="#current" class="current"><?php _e('Current Report', 'intersoccer-player-management'); ?></a> |</li>
            <li><a href="#historical"><?php _e('Historical Comparison', 'intersoccer-player-management'); ?></a></li>
        </ul>
        <div id="current">
            <form method="get">
                <input type="hidden" name="page" value="intersoccer-player-management">
                <input type="hidden" name="tab" value="<?php echo esc_attr($type); ?>-report">
                <p>
                    <label for="<?php echo esc_attr($type); ?>_name"><?php _e('Name:', 'intersoccer-player-management'); ?></label>
                    <input type="text" id="<?php echo esc_attr($type); ?>_name" name="<?php echo esc_attr($type); ?>_name" value="<?php echo esc_attr($filters['name']); ?>">
                </p>
                <p>
                    <label for="date_range"><?php _e('Date Range:', 'intersoccer-player-management'); ?></label>
                    <input type="text" id="date_range" name="date_range" class="date-range-picker" value="<?php echo esc_attr($filters['date_range']); ?>">
                </p>
                <p>
                    <label for="venue"><?php _e('Venue:', 'intersoccer-player-management'); ?></label>
                    <select id="venue" name="venue">
                        <option value=""><?php _e('All Venues', 'intersoccer-player-management'); ?></option>
                        <?php foreach (get_terms(['taxonomy' => 'pa_intersoccer-venues', 'hide_empty' => false]) as $venue): ?>
                            <option value="<?php echo esc_attr($venue->slug); ?>" <?php selected($filters['venue'], $venue->slug); ?>><?php echo esc_html($venue->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <input type="submit" class="button" value="<?php _e('Filter', 'intersoccer-player-management'); ?>">
                    <a href="<?php echo esc_url(add_query_arg(['export_csv' => 1, 'export_nonce' => wp_create_nonce("intersoccer_export_{$type}")])); ?>" class="button"><?php _e('Export CSV', 'intersoccer-player-management'); ?></a>
                </p>
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Order ID', 'intersoccer-player-management'); ?></th>
                        <th><?php echo $type === 'camps' ? __('Camp Name', 'intersoccer-player-management') : __('Course Name', 'intersoccer-player-management'); ?></th>
                        <th><?php _e('Players', 'intersoccer-player-management'); ?></th>
                        <th><?php _e('Venue', 'intersoccer-player-management'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_items as $item): ?>
                        <tr>
                            <td><?php echo esc_html($item['order']->get_id()); ?></td>
                            <td><?php echo esc_html($item['product']->get_name()); ?></td>
                            <td>
                                <?php foreach ($item['players'] as $player): ?>
                                    <?php echo esc_html($player['name']) . ' (' . esc_html($player['dob']) . ', ' . esc_html($player['gender']) . ')<br>'; ?>
                                <?php endforeach; ?>
                            </td>
                            <td><?php echo esc_html($item['venue'] ?: 'Not Set'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    printf(
                        __('Showing %d-%d of %d orders', 'intersoccer-player-management'),
                        $offset + 1,
                        min($offset + $items_per_page, $total_orders),
                        $total_orders
                    );
                    if ($total_pages > 1):
                        ?>
                        <span class="pagination-links">
                            <?php if ($current_page > 1): ?>
                                <a class="prev-page" href="<?php echo esc_url(add_query_arg('paged', $current_page - 1)); ?>">«</a>
                            <?php else: ?>
                                <span class="prev-page disabled">«</span>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a class="page-number" href="<?php echo esc_url(add_query_arg('paged', $i)); ?>" <?php echo $i === $current_page ? 'class="current"' : ''; ?>><?php echo esc_html($i); ?></a>
                            <?php endfor; ?>
                            <?php if ($current_page < $total_pages): ?>
                                <a class="next-page" href="<?php echo esc_url(add_query_arg('paged', $current_page + 1)); ?>">»</a>
                            <?php else: ?>
                                <span class="next-page disabled">»</span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div id="historical" style="display: none;">
            <?php intersoccer_render_historical_comparison('courses'); ?>
        </div>
        <script>
            jQuery(document).ready(function($) {
                flatpickr('.date-range-picker', {
                    mode: 'range',
                    dateFormat: 'Y-m-d',
                });
                $('.subsubsub a').on('click', function(e) {
                    e.preventDefault();
                    $('.subsubsub a').removeClass('current');
                    $(this).addClass('current');
                    $('#current, #historical').hide();
                    $($(this).attr('href')).show();
                });
            });
        </script>
    </div>
    <?php
}

function intersoccer_export_report($items, $type) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $type . '-report-' . date('Y-m-d-H-i-s') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Order ID', $type === 'camps' ? 'Camp Name' : 'Course Name', 'Player Name', 'DOB', 'Gender', 'Venue']);
    foreach ($items as $item_data) {
        foreach ($item_data['players'] as $player) {
            fputcsv($output, [
                $item_data['order']->get_id(),
                $item_data['product']->get_name(),
                $player['name'],
                $player['dob'],
                $player['gender'],
                $item_data['venue'] ?: 'Not Set',
            ]);
        }
    }
    fclose($output);
    exit;
}

add_action('wp_ajax_intersoccer_get_course_roster', 'intersoccer_get_course_roster');
function intersoccer_get_course_roster() {
    check_ajax_referer('intersoccer_roster_nonce', 'nonce');
    $product_id = absint($_POST['product_id']);
    $courses = [];
    $orders = wc_get_orders(['meta_key' => '_product_id', 'meta_value' => $product_id]);
    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            $players = [];
            foreach ($item->get_meta_data() as $meta) {
                if (preg_match('/^Player (\d+) Name$/', $meta->key, $matches)) {
                    $index = $matches[1];
                    $players[] = [
                        'name' => $meta->value,
                        'dob' => $item->get_meta("Player {$index} DOB", true),
                    ];
                }
            }
            $courses[] = ['order_id' => $order->get_id(), 'players' => $players];
        }
    }
    wp_send_json_success($courses);
}
?>
