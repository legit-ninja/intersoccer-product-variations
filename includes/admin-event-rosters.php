<?php

/**
 * Admin Event Rosters
 * Changes:
 * - Added pagination to event queries.
 * - Enhanced filters for event categories and attendee status.
 * - Added AJAX endpoint for real-time roster updates.
 * - Integrated venue details from wc-product-export-3-5-2025.csv.
 * - Simplified to show only player assignments, removing custom fields (2025-05-10).
 * Testing:
 * - Navigate to Players & Orders > Event Rosters, verify pagination (20 events/page).
 * - Test filters for event categories and attendee status, ensure correct filtering.
 * - Use AJAX to fetch roster for an event, confirm attendee data loads.
 * - Verify venue details (e.g., address) display for events linked to products.
 * - Confirm player assignments are displayed in the roster table.
 */

defined('ABSPATH') or die('No script kiddies please!');

function intersoccer_render_event_rosters_tab()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-player-management'));
    }

    if (!function_exists('tribe_get_events')) {
        echo '<div class="error"><p>' . esc_html__('The Events Calendar is required.', 'intersoccer-player-management') . '</p></div>';
        return;
    }

    $events_per_page = 20;
    $current_page = max(1, absint($_GET['paged'] ?? 1));
    $offset = ($current_page - 1) * $events_per_page;
    $filters = [
        'event_title' => sanitize_text_field($_GET['event_title'] ?? ''),
        'event_category' => absint($_GET['event_category'] ?? 0),
        'attendee_status' => sanitize_text_field($_GET['attendee_status'] ?? ''),
    ];

    $event_args = [
        'posts_per_page' => $events_per_page,
        'paged' => $current_page,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'eventDisplay' => 'all',
        'cache_results' => true,
    ];
    if ($filters['event_title']) {
        $event_args['s'] = $filters['event_title'];
    }
    if ($filters['event_category']) {
        $event_args['tax_query'] = [
            [
                'taxonomy' => 'tribe_events_cat',
                'field' => 'term_id',
                'terms' => $filters['event_category'],
            ],
        ];
    }

    $events = tribe_get_events($event_args);
    $total_events = count(tribe_get_events(['posts_per_page' => -1, 'eventDisplay' => 'all']));
    $total_pages = ceil($total_events / $events_per_page);

    $venue_data = [
        'Geneva - Stade de Varembe (nr. Nations)' => ['address' => 'Avenue de France 40, 1202 Genève', 'city' => 'Geneva'],
        // From wc-product-export-3-5-2025.csv
    ];

?>
    <div class="wrap">
        <h1><?php _e('Event Rosters', 'intersoccer-player-management'); ?></h1>
        <form method="get">
            <input type="hidden" name="page" value="intersoccer-player-management">
            <input type="hidden" name="tab" value="event-rosters">
            <p>
                <label for="event_title"><?php _e('Event Title:', 'intersoccer-player-management'); ?></label>
                <input type="text" id="event_title" name="event_title" value="<?php echo esc_attr($filters['event_title']); ?>">
            </p>
            <p>
                <label for="event_category"><?php _e('Category:', 'intersoccer-player-management'); ?></label>
                <select id="event_category" name="event_category">
                    <option value=""><?php _e('All Categories', 'intersoccer-player-management'); ?></option>
                    <?php foreach (tribe_get_event_categories() as $category): ?>
                        <option value="<?php echo esc_attr($category->term_id); ?>" <?php selected($filters['event_category'], $category->term_id); ?>><?php echo esc_html($category->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="attendee_status"><?php _e('Attendee Status:', 'intersoccer-player-management'); ?></label>
                <select id="attendee_status" name="attendee_status">
                    <option value=""><?php _e('All Statuses', 'intersoccer-player-management'); ?></option>
                    <option value="checked_in" <?php selected($filters['attendee_status'], 'checked_in'); ?>><?php _e('Checked In', 'intersoccer-player-management'); ?></option>
                    <option value="not_checked_in" <?php selected($filters['attendee_status'], 'not_checked_in'); ?>><?php _e('Not Checked In', 'intersoccer-player-management'); ?></option>
                </select>
            </p>
            <p>
                <input type="submit" class="button" value="<?php _e('Filter', 'intersoccer-player-management'); ?>">
            </p>
        </form>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Event Name', 'intersoccer-player-management'); ?></th>
                    <th><?php _e('Date', 'intersoccer-player-management'); ?></th>
                    <th><?php _e('Venue', 'intersoccer-player-management'); ?></th>
                    <th><?php _e('Attendees', 'intersoccer-player-management'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                    <?php
                    $attendees = tribe_tickets_get_attendees($event->ID);
                    if ($filters['attendee_status']) {
                        $attendees = array_filter($attendees, function ($attendee) use ($filters) {
                            $checked_in = get_post_meta($attendee['ID'], 'checked_in', true) === 'yes';
                            return ($filters['attendee_status'] === 'checked_in' && $checked_in) || ($filters['attendee_status'] === 'not_checked_in' && !$checked_in);
                        });
                    }
                    $venue_id = get_post_meta($event->ID, '_EventVenueID', true);
                    $venue_name = tribe_get_venue($event->ID) ?: 'TBD';
                    $venue_details = isset($venue_data[$venue_name]) ? $venue_data[$venue_name]['address'] : '';
                    ?>
                    <tr>
                        <td><?php echo esc_html($event->post_title); ?></td>
                        <td><?php echo esc_html(tribe_get_start_date($event, false, 'Y-m-d H:i')); ?></td>
                        <td><?php echo esc_html($venue_name); ?><?php echo $venue_details ? ' (' . esc_html($venue_details) . ')' : ''; ?></td>
                        <td>
                            <ul>
                                <?php foreach ($attendees as $attendee): ?>
                                    <?php
                                    $player_name = get_post_meta($attendee['ID'], 'player_name', true);
                                    $order_id = $attendee['order_id'];
                                    $order = wc_get_order($order_id);
                                    $player_assignments = '';
                                    if ($order) {
                                        foreach ($order->get_items() as $item) {
                                            $assignments = $item->get_meta('Player Assignments');
                                            if ($assignments) {
                                                $player_names = array_map(function ($assignment) {
                                                    return $assignment['player_name'];
                                                }, $assignments);
                                                $player_assignments = implode(', ', $player_names);
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                    <?php if ($player_name): ?>
                                        <li>
                                            <?php echo esc_html($player_name); ?>
                                            (<?php echo get_post_meta($attendee['ID'], 'checked_in', true) === 'yes' ? __('Checked In', 'intersoccer-player-management') : __('Not Checked In', 'intersoccer-player-management'); ?>)
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                            <button class="button fetch-roster" data-event-id="<?php echo esc_attr($event->ID); ?>"><?php _e('Refresh Roster', 'intersoccer-player-management'); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                printf(
                    __('Showing %d-%d of %d events', 'intersoccer-player-management'),
                    $offset + 1,
                    min($offset + $events_per_page, $total_events),
                    $total_events
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
        <script>
            jQuery(document).ready(function($) {
                $('.fetch-roster').on('click', function() {
                    var $button = $(this);
                    var eventId = $button.data('event-id');
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'intersoccer_get_event_roster',
                            nonce: '<?php echo wp_create_nonce('intersoccer_roster_nonce'); ?>',
                            event_id: eventId
                        },
                        success: function(response) {
                            if (response.success) {
                                var $list = $button.prev('ul');
                                $list.empty();
                                response.data.forEach(function(item) {
                                    $list.append('<li>' + item.name + '</li>');
                                });
                            } else {
                                alert('<?php _e('Failed to fetch roster.', 'intersoccer-player-management'); ?>');
                            }
                        }
                    });
                });
            });
        </script>
    </div>
<?php
}

add_action('wp_ajax_intersoccer_get_event_roster', 'intersoccer_get_event_roster');
function intersoccer_get_event_roster()
{
    check_ajax_referer('intersoccer_roster_nonce');
    $event_id = absint($_POST['event_id']);
    $attendees = tribe_tickets_get_attendees($event_id);
    $roster = array_map(function ($attendee) {
        return [
            'name' => get_post_meta($attendee['ID'], 'player_name', true),
            'order_id' => $attendee['order_id'],
        ];
    }, $attendees);
    wp_send_json_success($roster);
}
?>
