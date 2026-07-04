<?php
/**
 * Admin UI on WooCommerce Products → Attributes for InterSoccer attribute sync and audit.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_notices', 'intersoccer_attr_render_attributes_admin_panel');
function intersoccer_attr_render_attributes_admin_panel() {
    if (!function_exists('get_current_screen')) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'product_page_product_attributes') {
        return;
    }

    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $audit = intersoccer_attr_audit();
    $templates = intersoccer_attr_product_type_templates();
    $nonce = wp_create_nonce('intersoccer_attr_admin');
    $health_url = admin_url('edit.php?post_type=product&page=intersoccer-variation-health');
    ?>
    <div class="notice notice-info intersoccer-attr-panel" style="padding: 12px 16px; margin-top: 12px;">
        <h2 style="margin: 0 0 8px;"><?php esc_html_e('InterSoccer Attribute Setup', 'intersoccer-product-variations'); ?></h2>
        <p style="margin: 0 0 10px;">
            <?php
            printf(
                esc_html__(
                    'Contract v%d — %1$d of %2$d attributes registered in WooCommerce. Legacy meta hits: %3$d.',
                    'intersoccer-product-variations'
                ),
                (int) INTERSOCCER_ATTRIBUTE_CONTRACT_VERSION,
                (int) $audit['summary']['registered'],
                (int) $audit['summary']['total_registry'],
                (int) $audit['summary']['legacy_hits']
            );
            ?>
        </p>

        <?php if (!empty($audit['missing_wc_attributes'])) : ?>
            <p style="color: #856404; margin: 0 0 10px;">
                <strong><?php esc_html_e('Missing:', 'intersoccer-product-variations'); ?></strong>
                <?php echo esc_html(implode(', ', $audit['missing_wc_attributes'])); ?>
            </p>
        <?php endif; ?>

        <p style="margin: 0 0 12px;">
            <button type="button" class="button button-primary" id="intersoccer-attr-sync">
                <?php esc_html_e('Sync InterSoccer Attributes', 'intersoccer-product-variations'); ?>
            </button>
            <button type="button" class="button" id="intersoccer-attr-audit">
                <?php esc_html_e('Run Audit', 'intersoccer-product-variations'); ?>
            </button>
            <a class="button button-link" href="<?php echo esc_url($health_url); ?>">
                <?php esc_html_e('Variation Health Dashboard', 'intersoccer-product-variations'); ?>
            </a>
        </p>

        <details style="margin-bottom: 10px;">
            <summary><?php esc_html_e('Product type attribute templates', 'intersoccer-product-variations'); ?></summary>
            <table class="widefat striped" style="margin-top: 8px; max-width: 900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Type', 'intersoccer-product-variations'); ?></th>
                        <th><?php esc_html_e('Parent', 'intersoccer-product-variations'); ?></th>
                        <th><?php esc_html_e('Variation', 'intersoccer-product-variations'); ?></th>
                        <th><?php esc_html_e('Meta', 'intersoccer-product-variations'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $type => $template) : ?>
                        <tr>
                            <td><strong><?php echo esc_html(ucfirst($type)); ?></strong></td>
                            <td><?php echo esc_html(implode(', ', $template['parent'])); ?></td>
                            <td><?php echo esc_html(implode(', ', $template['variation'])); ?></td>
                            <td><?php echo esc_html(implode(', ', $template['meta'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>

        <div id="intersoccer-attr-results" style="display: none; margin-top: 10px;"></div>
    </div>
    <script>
    jQuery(function($) {
        var nonce = <?php echo wp_json_encode($nonce); ?>;

        function showResult(html, isError) {
            var $box = $('#intersoccer-attr-results');
            $box.show().html(
                '<div class="notice ' + (isError ? 'notice-error' : 'notice-success') + ' inline" style="padding: 8px 12px;"><p style="margin:0;">' + html + '</p></div>'
            );
        }

        $('#intersoccer-attr-sync').on('click', function() {
            var $btn = $(this).prop('disabled', true).text(<?php echo wp_json_encode(__('Syncing…', 'intersoccer-product-variations')); ?>);
            $.post(ajaxurl, {
                action: 'intersoccer_attr_sync',
                nonce: nonce
            }).done(function(response) {
                if (response.success) {
                    showResult(response.data.message);
                    setTimeout(function() { window.location.reload(); }, 1500);
                } else {
                    showResult(response.data && response.data.message ? response.data.message : 'Error', true);
                }
            }).fail(function() {
                showResult(<?php echo wp_json_encode(__('Sync request failed.', 'intersoccer-product-variations')); ?>, true);
            }).always(function() {
                $btn.prop('disabled', false).text(<?php echo wp_json_encode(__('Sync InterSoccer Attributes', 'intersoccer-product-variations')); ?>);
            });
        });

        $('#intersoccer-attr-audit').on('click', function() {
            var $btn = $(this).prop('disabled', true);
            $.post(ajaxurl, {
                action: 'intersoccer_attr_audit',
                nonce: nonce
            }).done(function(response) {
                if (response.success && response.data.html) {
                    showResult(response.data.html);
                } else {
                    showResult(response.data && response.data.message ? response.data.message : 'Error', true);
                }
            }).fail(function() {
                showResult(<?php echo wp_json_encode(__('Audit request failed.', 'intersoccer-product-variations')); ?>, true);
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    });
    </script>
    <?php
}

add_action('wp_ajax_intersoccer_attr_sync', 'intersoccer_attr_ajax_sync');
function intersoccer_attr_ajax_sync() {
    check_ajax_referer('intersoccer_attr_admin', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
    }

    $result = intersoccer_attr_sync_to_woocommerce(true);
    $parts = [];

    if (!empty($result['created'])) {
        $parts[] = sprintf(
            __('Created attributes: %s', 'intersoccer-product-variations'),
            implode(', ', $result['created'])
        );
    }
    if (!empty($result['existing'])) {
        $parts[] = sprintf(
            __('Already registered: %d', 'intersoccer-product-variations'),
            count($result['existing'])
        );
    }
    if (!empty($result['existing_via_alias'])) {
        $parts[] = sprintf(
            __('Resolved via legacy slug: %s', 'intersoccer-product-variations'),
            implode(', ', $result['existing_via_alias'])
        );
    }
    if (!empty($result['reconciled'])) {
        $parts[] = sprintf(
            __('Reconciled orphaned taxonomies: %s', 'intersoccer-product-variations'),
            implode(', ', $result['reconciled'])
        );
    }
    if (!empty($result['terms_created'])) {
        $parts[] = sprintf(
            __('Terms seeded: %s', 'intersoccer-product-variations'),
            implode(', ', $result['terms_created'])
        );
    }
    if (!empty($result['errors'])) {
        $parts[] = __('Errors: ', 'intersoccer-product-variations') . implode('; ', $result['errors']);
    }

    if (empty($parts)) {
        $parts[] = __('Sync completed with no changes.', 'intersoccer-product-variations');
    }

    wp_send_json_success([
        'message' => implode(' · ', $parts),
        'result' => $result,
    ]);
}

add_action('wp_ajax_intersoccer_attr_audit', 'intersoccer_attr_ajax_audit');
function intersoccer_attr_ajax_audit() {
    check_ajax_referer('intersoccer_attr_admin', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
    }

    $audit = intersoccer_attr_audit();
    $lines = [];

    $lines[] = sprintf(
        '<strong>%s</strong> %d / %d registered, %d missing, %d legacy hits.',
        esc_html__('Audit:', 'intersoccer-product-variations'),
        (int) $audit['summary']['registered'],
        (int) $audit['summary']['total_registry'],
        (int) $audit['summary']['missing'],
        (int) $audit['summary']['legacy_hits']
    );

    if (!empty($audit['alias_resolved'])) {
        $aliases = [];
        foreach ($audit['alias_resolved'] as $registry_slug => $wc_slug) {
            $aliases[] = $registry_slug . '→' . $wc_slug;
        }
        $lines[] = esc_html__('Registry aliases in WooCommerce:', 'intersoccer-product-variations') . ' '
            . esc_html(implode(', ', $aliases));
    }

    if (!empty($audit['orphaned_taxonomies'])) {
        $orphans = [];
        foreach ($audit['orphaned_taxonomies'] as $registry_slug => $taxonomy) {
            $orphans[] = $registry_slug . '→' . $taxonomy;
        }
        $lines[] = esc_html__('Orphaned taxonomies (run Sync to reconcile):', 'intersoccer-product-variations') . ' '
            . esc_html(implode(', ', $orphans));
    }

    if (!empty($audit['missing_wc_attributes'])) {
        $lines[] = esc_html__('Missing WC attributes:', 'intersoccer-product-variations') . ' '
            . esc_html(implode(', ', $audit['missing_wc_attributes']));
    }

    if (!empty($audit['missing_default_terms'])) {
        $lines[] = esc_html__('Missing default terms:', 'intersoccer-product-variations') . ' '
            . esc_html(implode(', ', array_slice($audit['missing_default_terms'], 0, 20)));
    }

    if (!empty($audit['legacy_taxonomy_in_use'])) {
        $legacy = [];
        foreach ($audit['legacy_taxonomy_in_use'] as $tax => $count) {
            $legacy[] = $tax . ' (' . $count . ')';
        }
        $lines[] = esc_html__('Legacy taxonomy meta in use:', 'intersoccer-product-variations') . ' '
            . esc_html(implode(', ', $legacy));
    }

    if (!empty($audit['legacy_meta_key_counts'])) {
        $legacy = [];
        foreach ($audit['legacy_meta_key_counts'] as $key => $count) {
            $legacy[] = $key . ' (' . $count . ')';
        }
        $lines[] = esc_html__('Legacy meta keys in use:', 'intersoccer-product-variations') . ' '
            . esc_html(implode(', ', $legacy));
    }

    wp_send_json_success(['html' => implode('<br>', $lines)]);
}
