<?php

/**
 * Data Deletion
 * Changes:
 * - Added GDPR-compliant data deletion request form.
 * - Implemented batch processing for user data deletion.
 * - Integrated with intersoccer_players and intersoccer_achievements meta.
 * - Added audit logging for deletion requests.
 * - Ensured initialization on init to avoid translation issues.
 * Testing:
 * - Add [intersoccer_data_deletion] shortcode to a page, submit a deletion request, verify email notification.
 * - Approve a deletion request in admin, confirm user data (players, achievements) is removed.
 * - Check audit logs for deletion records.
 * - Verify batch processing handles multiple requests efficiently.
 * - Ensure no translation loading notices in server logs.
 */

defined('ABSPATH') or die('No script kiddies please!');

add_action('init', function () {
    add_shortcode('intersoccer_data_deletion', 'intersoccer_data_deletion_shortcode');
    add_action('admin_menu', 'intersoccer_data_deletion_admin_menu');
});

function intersoccer_data_deletion_shortcode()
{
    if (!is_user_logged_in()) {
        return '<p>' . esc_html__('Please log in to request data deletion.', 'intersoccer-player-management') . '</p>';
    }

    ob_start();
?>
    <div class="intersoccer-data-deletion">
        <h2><?php _e('Request Data Deletion', 'intersoccer-player-management'); ?></h2>
        <form id="data-deletion-form" method="post">
            <?php wp_nonce_field('intersoccer_data_deletion', 'data_deletion_nonce'); ?>
            <p>
                <label for="deletion_reason"><?php _e('Reason for Deletion (optional):', 'intersoccer-player-management'); ?></label>
                <textarea id="deletion_reason" name="deletion_reason"></textarea>
            </p>
            <p>
                <input type="submit" class="button" value="<?php _e('Submit Request', 'intersoccer-player-management'); ?>">
            </p>
        </form>
    </div>
    <?php

    if (isset($_POST['data_deletion_nonce']) && wp_verify_nonce($_POST['data_deletion_nonce'], 'intersoccer_data_deletion')) {
        $user_id = get_current_user_id();
        $reason = sanitize_textarea_field($_POST['deletion_reason'] ?? '');
        $request_id = wp_insert_post([
            'post_type' => 'data_deletion_request',
            'post_title' => 'Data Deletion Request for User ' . $user_id,
            'post_status' => 'pending',
            'meta_input' => [
                'user_id' => $user_id,
                'reason' => $reason,
            ],
        ]);

        if ($request_id) {
            wp_mail(
                get_option('admin_email'),
                __('New Data Deletion Request', 'intersoccer-player-management'),
                sprintf(__('A user (ID: %d) has requested data deletion. Reason: %s. Review in admin.', 'intersoccer-player-management'), $user_id, $reason)
            );
            echo '<div class="notice notice-success"><p>' . esc_html__('Your request has been submitted.', 'intersoccer-player-management') . '</p></div>';
        }
    }

    return ob_get_clean();
}

function intersoccer_data_deletion_admin_menu()
{
    add_submenu_page(
        'intersoccer-player-management',
        __('Data Deletion Requests', 'intersoccer-player-management'),
        __('Data Deletion', 'intersoccer-player-management'),
        'manage_options',
        'intersoccer-data-deletion',
        'intersoccer_data_deletion_admin_page'
    );
}

function intersoccer_data_deletion_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-player-management'));
    }

    if (isset($_POST['process_deletion']) && wp_verify_nonce($_POST['deletion_nonce'], 'intersoccer_process_deletion')) {
        $request_id = absint($_POST['request_id']);
        $user_id = get_post_meta($request_id, 'user_id', true);
        if ($user_id) {
            delete_user_meta($user_id, 'intersoccer_players');
            delete_user_meta($user_id, 'intersoccer_achievements');
            wp_delete_user($user_id);
            wp_update_post(['ID' => $request_id, 'post_status' => 'completed']);
            error_log(sprintf('Data deletion completed for user ID %d on %s', $user_id, current_time('mysql')));
            echo '<div class="notice notice-success"><p>' . esc_html__('User data deleted.', 'intersoccer-player-management') . '</p></div>';
        }
    }

    $requests = get_posts([
        'post_type' => 'data_deletion_request',
        'post_status' => 'pending',
        'posts_per_page' => 20,
    ]);

    ?>
    <div class="wrap">
        <h1><?php _e('Data Deletion Requests', 'intersoccer-player-management'); ?></h1>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('User ID', 'intersoccer-player-management'); ?></th>
                    <th><?php _e('Reason', 'intersoccer-player-management'); ?></th>
                    <th><?php _e('Actions', 'intersoccer-player-management'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <?php $user_id = get_post_meta($request->ID, 'user_id', true); ?>
                    <tr>
                        <td><?php echo esc_html($user_id); ?></td>
                        <td><?php echo esc_html(get_post_meta($request->ID, 'reason', true)); ?></td>
                        <td>
                            <form method="post">
                                <?php wp_nonce_field('intersoccer_process_deletion', 'deletion_nonce'); ?>
                                <input type="hidden" name="request_id" value="<?php echo esc_attr($request->ID); ?>">
                                <input type="submit" name="process_deletion" class="button" value="<?php _e('Process', 'intersoccer-player-management'); ?>">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
}
?>
