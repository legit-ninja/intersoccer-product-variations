<?php
/**
 * Order Meta Repair admin helpers (slug + legacy redirects).
 * Loaded from admin-ui.php; kept separate so PHPUnit can smoke-test without WP_List_Table.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical admin page slug for the unified Order Meta Repair tool.
 *
 * @return string
 */
function intersoccer_order_meta_repair_page_slug() {
    return 'intersoccer-order-meta-repair';
}

/**
 * Redirect legacy Find Order Issues / Bulk Repair slugs to the unified page.
 */
function intersoccer_redirect_legacy_order_meta_repair_pages() {
    if (!is_admin() || !isset($_GET['page'])) {
        return;
    }
    $page = sanitize_key(wp_unslash((string) $_GET['page']));
    $map = [
        'intersoccer-update-orders' => 'scan',
        'intersoccer-automated-updates' => 'batch',
    ];
    if (!isset($map[$page])) {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $args = [
        'page' => intersoccer_order_meta_repair_page_slug(),
        'tab' => $map[$page],
    ];
    foreach (['order_statuses', 'preview_limit', 'fix_activity_type_only', 'strip_deprecated_meta', 'prune_legacy_twins', 'preview_updates', 'detailed_preview'] as $key) {
        if (isset($_GET[$key])) {
            $args[$key] = wp_unslash($_GET[$key]);
        }
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    // Allow PHPUnit to observe the redirect without terminating the process.
    if (defined('INTERSOCCER_ORDER_META_REPAIR_TEST') && INTERSOCCER_ORDER_META_REPAIR_TEST) {
        return;
    }
    exit;
}
