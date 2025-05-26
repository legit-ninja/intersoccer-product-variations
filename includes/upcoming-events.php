<?php

/**
 * Upcoming Events
 * Changes:
 * - Added shortcode to display upcoming events with rich descriptions from CSV.
 * - Implemented pagination and caching for event queries.
 * - Added venue filter using CSV-derived venues.
 * - Integrated with event-tickets-integration.php for roster previews.
 * - Ensured queries run on init to avoid translation issues.
 * Testing:
 * - Add [intersoccer_upcoming_events] shortcode to a page, verify events list with pagination.
 * - Add [intersoccer_upcoming_events venue="Varembe"], confirm only Varembe events show.
 * - Click roster preview, verify player names load via AJAX.
 * - Check event descriptions match wc-product-export-3-5-2025.csv data.
 * - Verify no translation loading notices in server logs.
 */

defined('ABSPATH') or die('No script kiddies please!');

add_action('init', function () {
    add_shortcode('intersoccer_upcoming_events', 'intersoccer_upcoming_events_shortcode');
});

function intersoccer_upcoming_events_shortcode($atts)
{
    if (!function_exists('tribe_get_events')) {
        return '<p>' . esc_html__('The Events Calendar is required.', 'intersoccer-player-management') . '</p>';
    }

    $atts = shortcode_atts(['venue' => '', 'per_page' => 10], $atts, 'intersoccer_upcoming_events');
    $venue = sanitize_text_field($atts['venue']);
    $per_page = absint($atts['per_page']);
    $current_page = max(1, absint($_GET['event_page'] ?? 1));
    $offset = ($current_page - 1) * $per_page;

    $cache_key = 'intersoccer_events_' . md5($venue . $current_page . $per_page);
    $events = wp_cache_get($cache_key, 'intersoccer');
    if (false === $events) {
        $args = [
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'start_date' => current_time('Y-m-d'),
            'cache_results' => true,
        ];
        if ($venue) {
            $args['meta_query'] = [
                [
                    'key' => '_EventVenueID',
                    'value' => get_posts([
                        'post_type' => 'tribe_venue',
                        'post_status' => 'publish',
                        'title' => $venue,
                        'fields' => 'ids',
                    ]),
                    'compare' => 'IN',
                ],
            ];
        }
        $events = tribe_get_events($args);
        wp_cache_set($cache_key, $events, 'intersoccer', 3600);
    }

    $total_events = count(tribe_get_events(['posts_per_page' => -1, 'start_date' => current_time('Y-m-d')]));
    $total_pages = ceil($total_events / $per_page);

    ob_start();
?>
    <div class="intersoccer-upcoming-events">
        <h2><?php _e('Upcoming Events', 'intersoccer-player-management'); ?></h2>
        <ul>
            <?php foreach ($events as $event): ?>
                <?php
                $product_id = get_post_meta($event->ID, '_intersoccer_product_id', true);
                $product = $product_id ? wc_get_product($product_id) : null;
                $description = $product ? $product->get_short_description() : '';
                ?>
                <li>
                    <h3><?php echo esc_html($event->post_title); ?></h3>
                    <p><strong><?php _e('Date:', 'intersoccer-player-management'); ?></strong> <?php echo esc_html(tribe_get_start_date($event, false, 'Y-m-d')); ?></p>
                    <p><strong><?php _e('Venue:', 'intersoccer-player-management'); ?></strong> <?php echo esc_html(tribe_get_venue($event->ID) ?: 'TBD'); ?></p>
                    <p><?php echo wp_kses_post($description); ?></p>
                    <button class="button roster-preview" data-event-id="<?php echo esc_attr($event->ID); ?>"><?php _e('Preview Roster', 'intersoccer-player-management'); ?></button>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($total_pages > 1): ?>
            <div class="event-pagination">
                <?php if ($current_page > 1): ?>
                    <a href="<?php echo esc_url(add_query_arg('event_page', $current_page - 1)); ?>" class="button"><?php _e('Previous', 'intersoccer-player-management'); ?></a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="<?php echo esc_url(add_query_arg('event_page', $i)); ?>" class="button <?php echo $i === $current_page ? 'active' : ''; ?>"><?php echo esc_html($i); ?></a>
                <?php endfor; ?>
                <?php if ($current_page < $total_pages): ?>
                    <a href="<?php echo esc_url(add_query_arg('event_page', $current_page + 1)); ?>" class="button"><?php _e('Next', 'intersoccer-player-management'); ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <script>
            jQuery(document).ready(function($) {
                $('.roster-preview').on('click', function() {
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
                                var roster = response.data.map(item => item.player_name).join('\n');
                                alert('Roster:\n' + (roster || 'No attendees yet.'));
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
    return ob_get_clean();
}
?>
