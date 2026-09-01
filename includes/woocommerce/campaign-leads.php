<?php
/**
 * TOGETHER20 campaign leads: order-meta capture, existing-user link, admin list/CSV.
 *
 * Never creates WP users or player profiles.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('intersoccer_campaign_joining_user_meta_key')) {
    /**
     * @return string
     */
    function intersoccer_campaign_joining_user_meta_key() {
        return '_intersoccer_campaign_joining_user_id';
    }
}

if (!function_exists('intersoccer_campaign_normalize_lead_email')) {
    /**
     * @param mixed $email
     * @return string
     */
    function intersoccer_campaign_normalize_lead_email($email) {
        $clean = function_exists('sanitize_email')
            ? sanitize_email((string) $email)
            : trim((string) $email);
        return strtolower($clean);
    }
}

if (!function_exists('intersoccer_campaign_lookup_user_id_by_email')) {
    /**
     * Existing WP user id for an email, or 0. Never creates a user.
     *
     * @param mixed $email
     * @return int
     */
    function intersoccer_campaign_lookup_user_id_by_email($email) {
        $clean = intersoccer_campaign_normalize_lead_email($email);
        if ($clean === '') {
            return 0;
        }
        if (function_exists('is_email') && !is_email($clean)) {
            return 0;
        }
        if (!function_exists('get_user_by')) {
            return 0;
        }
        $user = get_user_by('email', $clean);
        if (!is_object($user) || empty($user->ID)) {
            return 0;
        }
        return (int) $user->ID;
    }
}

if (!function_exists('intersoccer_campaign_lead_timestamp')) {
    /**
     * @param mixed $value
     * @return int
     */
    function intersoccer_campaign_lead_timestamp($value) {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0;
        }
        $ts = strtotime($raw);
        return $ts ? $ts : 0;
    }
}

if (!function_exists('intersoccer_campaign_lead_candidate_converts')) {
    /**
     * @param mixed $email
     * @param int   $source_order_id
     * @param mixed $source_created
     * @param array $candidate {id, billing_email, status, date_created}
     * @return bool
     */
    function intersoccer_campaign_lead_candidate_converts($email, $source_order_id, $source_created, array $candidate) {
        $want = intersoccer_campaign_normalize_lead_email($email);
        $got = intersoccer_campaign_normalize_lead_email($candidate['billing_email'] ?? '');
        if ($want === '' || $got !== $want) {
            return false;
        }
        $status = strtolower((string) ($candidate['status'] ?? ''));
        if (!in_array($status, ['processing', 'completed'], true)) {
            return false;
        }
        $other_id = (int) ($candidate['id'] ?? 0);
        $source_id = (int) $source_order_id;
        if ($other_id <= 0 || $other_id === $source_id) {
            return false;
        }
        $source_ts = intersoccer_campaign_lead_timestamp($source_created);
        $other_ts = intersoccer_campaign_lead_timestamp($candidate['date_created'] ?? 0);
        if ($other_ts > $source_ts) {
            return true;
        }
        return $other_ts === $source_ts && $other_id > $source_id;
    }
}

if (!function_exists('intersoccer_campaign_lead_is_converted')) {
    /**
     * @param mixed      $email
     * @param int        $source_order_id
     * @param mixed      $source_created
     * @param array|null $candidates Optional list of candidate arrays (tests).
     * @return bool
     */
    function intersoccer_campaign_lead_is_converted($email, $source_order_id, $source_created, $candidates = null) {
        return intersoccer_campaign_lead_converted_order_id($email, $source_order_id, $source_created, $candidates) > 0;
    }
}

if (!function_exists('intersoccer_campaign_lead_converted_order_id')) {
    /**
     * @param mixed      $email
     * @param int        $source_order_id
     * @param mixed      $source_created
     * @param array|null $candidates
     * @return int
     */
    function intersoccer_campaign_lead_converted_order_id($email, $source_order_id, $source_created, $candidates = null) {
        if ($candidates === null) {
            $candidates = intersoccer_campaign_paid_order_candidates_for_email($email);
        }
        if (!is_array($candidates)) {
            return 0;
        }
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if (intersoccer_campaign_lead_candidate_converts($email, $source_order_id, $source_created, $candidate)) {
                return (int) ($candidate['id'] ?? 0);
            }
        }
        return 0;
    }
}

if (!function_exists('intersoccer_campaign_order_to_candidate')) {
    /**
     * @param object $order
     * @return array
     */
    function intersoccer_campaign_order_to_candidate($order) {
        $id = 0;
        $email = '';
        $status = '';
        $created = 0;
        if (is_object($order)) {
            if (method_exists($order, 'get_id')) {
                $id = (int) $order->get_id();
            }
            if (method_exists($order, 'get_billing_email')) {
                $email = (string) $order->get_billing_email();
            }
            if (method_exists($order, 'get_status')) {
                $status = (string) $order->get_status();
            }
            if (method_exists($order, 'get_date_created')) {
                $created = $order->get_date_created();
            }
        }
        return [
            'id' => $id,
            'billing_email' => $email,
            'status' => $status,
            'date_created' => $created,
        ];
    }
}

if (!function_exists('intersoccer_campaign_paid_order_candidates_for_email')) {
    /**
     * @param string $email
     * @return array<int,array>
     */
    function intersoccer_campaign_paid_order_candidates_for_email($email) {
        $clean = intersoccer_campaign_normalize_lead_email($email);
        if ($clean === '' || !function_exists('wc_get_orders')) {
            return [];
        }
        $orders = wc_get_orders([
            'limit' => 50,
            'status' => ['processing', 'completed'],
            'billing_email' => $clean,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);
        if (!is_array($orders)) {
            return [];
        }
        $rows = [];
        foreach ($orders as $order) {
            $rows[] = intersoccer_campaign_order_to_candidate($order);
        }
        return $rows;
    }
}

if (!function_exists('intersoccer_campaign_link_joining_user')) {
    /**
     * Store existing user id for the joining email, or 0. Never creates a user.
     *
     * @param object $order
     * @return void
     */
    function intersoccer_campaign_link_joining_user($order) {
        if (!is_object($order) || !method_exists($order, 'get_meta') || !method_exists($order, 'update_meta_data')) {
            return;
        }
        $email_key = function_exists('intersoccer_campaign_joining_email_meta_key')
            ? intersoccer_campaign_joining_email_meta_key()
            : '_intersoccer_campaign_joining_email';
        $email = $order->get_meta($email_key);
        if ($email === '' || $email === null) {
            return;
        }
        $user_id = intersoccer_campaign_lookup_user_id_by_email($email);
        $order->update_meta_data(intersoccer_campaign_joining_user_meta_key(), $user_id);
        if (method_exists($order, 'save')) {
            $order->save();
        }
    }
}

if (!function_exists('intersoccer_campaign_link_joining_user_on_payment')) {
    /**
     * @param int $order_id
     */
    function intersoccer_campaign_link_joining_user_on_payment($order_id) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order((int) $order_id);
        if ($order) {
            intersoccer_campaign_link_joining_user($order);
        }
    }
}

if (!function_exists('intersoccer_campaign_collect_leads')) {
    /**
     * One row per source order that stored a joining email.
     *
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_campaign_collect_leads() {
        if (!function_exists('wc_get_orders') || !function_exists('intersoccer_campaign_joining_email_meta_key')) {
            return [];
        }
        $orders = wc_get_orders([
            'limit' => 200,
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => ['pending', 'on-hold', 'processing', 'completed'],
            'meta_key' => intersoccer_campaign_joining_email_meta_key(),
            'meta_compare' => '!=',
            'meta_value' => '',
        ]);
        if (!is_array($orders)) {
            return [];
        }

        $name_key = function_exists('intersoccer_campaign_joining_meta_key')
            ? intersoccer_campaign_joining_meta_key()
            : '_intersoccer_campaign_joining';
        $user_key = intersoccer_campaign_joining_user_meta_key();
        $email_key = intersoccer_campaign_joining_email_meta_key();
        $rows = [];

        foreach ($orders as $order) {
            if (!is_object($order) || !method_exists($order, 'get_meta')) {
                continue;
            }
            $email = intersoccer_campaign_normalize_lead_email($order->get_meta($email_key));
            if ($email === '') {
                continue;
            }
            $order_id = method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
            $created = method_exists($order, 'get_date_created') ? $order->get_date_created() : 0;
            $linked = (int) $order->get_meta($user_key);
            if ($linked <= 0) {
                $linked = intersoccer_campaign_lookup_user_id_by_email($email);
            }
            $converted_id = intersoccer_campaign_lead_converted_order_id($email, $order_id, $created);
            $edit_url = '';
            if (method_exists($order, 'get_edit_order_url')) {
                $edit_url = (string) $order->get_edit_order_url();
            } elseif (function_exists('admin_url') && $order_id > 0) {
                $edit_url = admin_url('post.php?post=' . $order_id . '&action=edit');
            }
            $rows[] = [
                'email' => $email,
                'child_name' => (string) $order->get_meta($name_key),
                'source_order' => $order_id,
                'existing_user_id' => $linked,
                'converted' => $converted_id > 0,
                'converted_order' => $converted_id,
                'edit_url' => $edit_url,
            ];
        }

        return $rows;
    }
}

add_action('woocommerce_payment_complete', 'intersoccer_campaign_link_joining_user_on_payment', 10, 1);

add_action('admin_init', 'intersoccer_campaign_maybe_export_leads', 5);
function intersoccer_campaign_maybe_export_leads() {
    if (empty($_GET['intersoccer_campaign_leads_csv'])) {
        return;
    }
    if (!function_exists('current_user_can') || !current_user_can('manage_woocommerce')) {
        return;
    }
    if (function_exists('wp_verify_nonce')) {
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'intersoccer_campaign_leads_csv')) {
            return;
        }
    }

    $leads = intersoccer_campaign_collect_leads();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="campaign-leads.csv"');
    $out = fopen('php://output', 'w');
    if ($out) {
        fputcsv($out, ['email', 'child_name', 'source_order', 'existing_user_id', 'converted', 'converted_order']);
        foreach ($leads as $row) {
            fputcsv($out, [
                $row['email'] ?? '',
                $row['child_name'] ?? '',
                $row['source_order'] ?? '',
                $row['existing_user_id'] ?? 0,
                !empty($row['converted']) ? 'yes' : 'no',
                $row['converted_order'] ?? '',
            ]);
        }
        fclose($out);
    }
    exit;
}

if (!function_exists('intersoccer_render_campaign_leads_section')) {
    /**
     * Admin table on Campaign Offers.
     */
    function intersoccer_render_campaign_leads_section() {
        if (!function_exists('current_user_can') || !current_user_can('manage_woocommerce')) {
            return;
        }
        $leads = intersoccer_campaign_collect_leads();
        $csv_url = function_exists('wp_nonce_url')
            ? wp_nonce_url(admin_url('admin.php?page=intersoccer-discounts&intersoccer_campaign_leads_csv=1'), 'intersoccer_campaign_leads_csv')
            : '';
        ?>
        <h2><?php echo esc_html(function_exists('intersoccer_campaign_translate') ? intersoccer_campaign_translate('Campaign leads', __('Campaign leads', 'intersoccer-product-variations')) : __('Campaign leads', 'intersoccer-product-variations')); ?></h2>
        <p><?php esc_html_e('Families named on group-offer checkouts. Existing customer is a matching WordPress user (never created here). Converted means a later paid order used the same billing email.', 'intersoccer-product-variations'); ?></p>
        <?php if ($csv_url) : ?>
            <p>
                <a class="button" href="<?php echo esc_url($csv_url); ?>"><?php esc_html_e('Download CSV', 'intersoccer-product-variations'); ?></a>
            </p>
        <?php endif; ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Guardian email', 'intersoccer-product-variations'); ?></th>
                    <th><?php esc_html_e('Child name', 'intersoccer-product-variations'); ?></th>
                    <th><?php esc_html_e('Source order', 'intersoccer-product-variations'); ?></th>
                    <th><?php esc_html_e('Existing customer', 'intersoccer-product-variations'); ?></th>
                    <th><?php esc_html_e('Converted', 'intersoccer-product-variations'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$leads) : ?>
                    <tr>
                        <td colspan="5"><?php esc_html_e('No campaign leads yet.', 'intersoccer-product-variations'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($leads as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['email']); ?></td>
                            <td><?php echo esc_html($row['child_name']); ?></td>
                            <td>
                                <?php if (!empty($row['edit_url'])) : ?>
                                    <a href="<?php echo esc_url($row['edit_url']); ?>"><?php echo esc_html((string) $row['source_order']); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html((string) $row['source_order']); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                echo !empty($row['existing_user_id'])
                                    ? esc_html((string) (int) $row['existing_user_id'])
                                    : esc_html__('No', 'intersoccer-product-variations');
                                ?>
                            </td>
                            <td>
                                <?php
                                if (!empty($row['converted'])) {
                                    echo esc_html__('Yes', 'intersoccer-product-variations');
                                    if (!empty($row['converted_order'])) {
                                        echo ' (' . esc_html((string) (int) $row['converted_order']) . ')';
                                    }
                                } else {
                                    echo esc_html__('No', 'intersoccer-product-variations');
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}
