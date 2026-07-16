<?php
/**
 * InterSoccer Program Manager — guided product creation and management for staff.
 *
 * Provides a Products → Program Manager admin page with:
 * - List view of all programs with completeness indicators
 * - Detail view per product with parent/variation checklists
 * - Create wizard (4-step) for new programs
 * - Duplicate program for seasonal rollover
 * - Inline variation price editing
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
	exit;
}

class InterSoccer_Program_Manager {

	const NONCE_ACTION = 'intersoccer_pm_nonce';
	const PAGE_SLUG    = 'intersoccer-program-manager';
	const CAPABILITY   = 'manage_woocommerce';

	public static function init() {
		if (!is_admin()) {
			return;
		}

		add_action('admin_menu', [__CLASS__, 'register_page']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

		add_action('wp_ajax_intersoccer_pm_create_product', [__CLASS__, 'ajax_create_product']);
		add_action('wp_ajax_intersoccer_pm_scaffold_variations', [__CLASS__, 'ajax_scaffold_variations']);
		add_action('wp_ajax_intersoccer_pm_check_completeness', [__CLASS__, 'ajax_check_completeness']);
		add_action('wp_ajax_intersoccer_pm_save_variation_price', [__CLASS__, 'ajax_save_variation_price']);
		add_action('wp_ajax_intersoccer_pm_duplicate_program', [__CLASS__, 'ajax_duplicate_program']);
		add_action('wp_ajax_intersoccer_pm_save_parent_attrs', [__CLASS__, 'ajax_save_parent_attrs']);
		add_action('wp_ajax_intersoccer_pm_create_term', [__CLASS__, 'ajax_create_term']);
	}

	public static function register_page() {
		add_submenu_page(
			'edit.php?post_type=product',
			__('Program Manager', 'intersoccer-product-variations'),
			__('Program Manager', 'intersoccer-product-variations'),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[__CLASS__, 'render_page'],
			2
		);
	}

	public static function enqueue_assets($hook) {
		if (strpos($hook, self::PAGE_SLUG) === false) {
			return;
		}

		wp_enqueue_style('wp-components');

		wp_enqueue_script(
			'intersoccer-program-manager',
			INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_URL . 'js/program-manager.js',
			['jquery'],
			'1.0.0',
			true
		);

		wp_localize_script('intersoccer-program-manager', 'intersoccerPM', [
			'ajax_url'                 => admin_url('admin-ajax.php'),
			'nonce'                    => wp_create_nonce(self::NONCE_ACTION),
			'variation_health_nonce'   => wp_create_nonce('intersoccer_variation_health_nonce'),
			'course_holiday_fix_nonce' => wp_create_nonce('intersoccer_course_holiday_fix_nonce'),
			'page_url'                 => menu_page_url(self::PAGE_SLUG, false),
			'i18n'     => [
				'confirm_create'    => __('Create this program as a Draft product?', 'intersoccer-product-variations'),
				'confirm_duplicate' => __('Duplicate this program? A new Draft product will be created.', 'intersoccer-product-variations'),
				'confirm_refresh'   => __('Refresh attributes on all unhealthy variations? This applies default values for missing fields.', 'intersoccer-product-variations'),
				'saving'            => __('Saving…', 'intersoccer-product-variations'),
				'saved'             => __('Saved', 'intersoccer-product-variations'),
				'error'             => __('Error', 'intersoccer-product-variations'),
				'creating'          => __('Creating program…', 'intersoccer-product-variations'),
				'refreshing'        => __('Refreshing…', 'intersoccer-product-variations'),
				'select_type'       => __('Please select a program type.', 'intersoccer-product-variations'),
				'enter_name'        => __('Please enter a program name.', 'intersoccer-product-variations'),
			],
		]);
	}

	// =========================================================================
	// Page router
	// =========================================================================

	public static function render_page() {
		if (!current_user_can(self::CAPABILITY)) {
			wp_die(__('You do not have permission to access this page.', 'intersoccer-product-variations'));
		}

		$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

		if ($action === 'create') {
			self::render_create_wizard();
			return;
		}

		if ($action === 'duplicate' && !empty($_GET['source_id'])) {
			self::render_duplicate_wizard(absint($_GET['source_id']));
			return;
		}

		if (!empty($_GET['product_id'])) {
			self::render_detail_view(absint($_GET['product_id']));
			return;
		}

		self::render_list_view();
	}

	// =========================================================================
	// Completeness engine
	// =========================================================================

	/**
	 * @param int $product_id
	 * @return array{type:string|null,parent_total:int,parent_complete:int,parent_missing:array,variations_total:int,variations_healthy:int,variations_issues:array,percentage:int}
	 */
	public static function get_product_completeness($product_id) {
		$product = wc_get_product($product_id);
		if (!$product || !$product->is_type('variable')) {
			return self::empty_completeness();
		}

		$type = InterSoccer_Product_Types::get_product_type($product_id);
		if (!$type) {
			return self::empty_completeness();
		}

		$required_parent = intersoccer_attr_required($type, 'parent');
		$parent_attrs    = $product->get_attributes();
		$parent_complete = 0;
		$parent_missing  = [];

		foreach ($required_parent as $taxonomy) {
			$has_terms = false;
			if (isset($parent_attrs[$taxonomy])) {
				$attr = $parent_attrs[$taxonomy];
				if ($attr instanceof WC_Product_Attribute) {
					$has_terms = !empty($attr->get_options());
				} else {
					$has_terms = !empty($attr);
				}
			}
			if (!$has_terms) {
				$terms = wc_get_product_terms($product_id, $taxonomy, ['fields' => 'ids']);
				$has_terms = !empty($terms);
			}
			if ($has_terms) {
				$parent_complete++;
			} else {
				$parent_missing[] = $taxonomy;
			}
		}

		$children           = $product->get_children();
		$variations_total   = count($children);
		$variations_healthy = 0;
		$variations_issues  = [];

		foreach ($children as $var_id) {
			$var_result = self::get_variation_completeness($var_id, $type);
			if ($var_result['is_healthy']) {
				$variations_healthy++;
			} else {
				$variations_issues[$var_id] = $var_result['missing'];
			}
		}

		$parent_total = count($required_parent);
		$total_checks = $parent_total + $variations_total;
		$passed       = $parent_complete + $variations_healthy;
		$percentage   = $total_checks > 0 ? (int) round(($passed / $total_checks) * 100) : 0;

		return [
			'type'               => $type,
			'parent_total'       => $parent_total,
			'parent_complete'    => $parent_complete,
			'parent_missing'     => $parent_missing,
			'variations_total'   => $variations_total,
			'variations_healthy' => $variations_healthy,
			'variations_issues'  => $variations_issues,
			'percentage'         => $percentage,
		];
	}

	/**
	 * @param int    $variation_id
	 * @param string $product_type
	 * @return array{is_healthy:bool,missing:array}
	 */
	public static function get_variation_completeness($variation_id, $product_type) {
		$required = intersoccer_attr_health_required_keys($product_type);
		$missing  = [];

		$variation = wc_get_product($variation_id);
		if (!$variation || !($variation instanceof WC_Product_Variation)) {
			return ['is_healthy' => false, 'missing' => ['invalid_variation']];
		}

		$attributes = $variation->get_attributes();

		foreach ($required as $key) {
			if (strpos($key, '_course_') === 0 || strpos($key, '_') === 0) {
				$value = get_post_meta($variation_id, $key, true);
				if ($value === '' || $value === null || $value === false) {
					$missing[] = $key;
				}
			} else {
				$slug     = str_replace('pa_', '', $key);
				$meta_key = 'attribute_' . $key;
				$value    = get_post_meta($variation_id, $meta_key, true);
				if (!$value) {
					$value = isset($attributes[$key]) ? $attributes[$key] : '';
				}
				if (!$value) {
					$value = function_exists('intersoccer_attr_get_variation_value')
						? intersoccer_attr_get_variation_value($variation_id, $slug)
						: '';
				}
				if (empty($value)) {
					$missing[] = $key;
				}
			}
		}

		$price = $variation->get_regular_price();
		if ($price === '' || $price === null) {
			$missing[] = '_regular_price';
		}

		return [
			'is_healthy' => empty($missing),
			'missing'    => $missing,
		];
	}

	private static function empty_completeness() {
		return [
			'type'               => null,
			'parent_total'       => 0,
			'parent_complete'    => 0,
			'parent_missing'     => [],
			'variations_total'   => 0,
			'variations_healthy' => 0,
			'variations_issues'  => [],
			'percentage'         => 0,
		];
	}

	// =========================================================================
	// List view
	// =========================================================================

	private static function render_list_view() {
		// Handle bulk action submission
		if (!empty($_POST['product_ids']) && !empty($_POST['_wpnonce'])) {
			if (wp_verify_nonce($_POST['_wpnonce'], 'intersoccer_pm_bulk_nonce') && current_user_can(self::CAPABILITY)) {
				$action     = !empty($_POST['action']) && $_POST['action'] !== '-1' ? sanitize_text_field($_POST['action']) : '';
				if (!$action) {
					$action = !empty($_POST['action2']) && $_POST['action2'] !== '-1' ? sanitize_text_field($_POST['action2']) : '';
				}
				$product_ids = array_map('absint', (array) $_POST['product_ids']);
				$processed   = 0;

				if ($action === 'refresh_attrs') {
					foreach ($product_ids as $pid) {
						$product = wc_get_product($pid);
						if (!$product || !$product->is_type('variable')) {
							continue;
						}
						foreach ($product->get_children() as $var_id) {
							$variation = wc_get_product($var_id);
							if (!$variation) {
								continue;
							}
							$parent_attrs = $product->get_attributes();
							$var_attrs    = [];
							foreach ($parent_attrs as $attr) {
								if ($attr->get_variation()) {
									$var_attrs[$attr->get_name()] = '';
								}
							}
							$variation->set_attributes($var_attrs);
							$variation->save();
						}
						$processed++;
					}
					echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('%d products processed — variation attributes refreshed.', 'intersoccer-product-variations'), $processed) . '</p></div>';
				} elseif ($action === 'scaffold_variations') {
					foreach ($product_ids as $pid) {
						$product = wc_get_product($pid);
						if (!$product || !$product->is_type('variable')) {
							continue;
						}
						if (count($product->get_children()) > 0) {
							continue;
						}
						$type   = InterSoccer_Product_Types::get_product_type($pid);
						$matrix = self::get_default_matrix($type);
						foreach ($matrix as $row) {
							self::create_single_variation($pid, $row, $type);
						}
						$processed++;
					}
					echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('%d products processed — variations scaffolded.', 'intersoccer-product-variations'), $processed) . '</p></div>';
				}
			}
		}

		$table = new InterSoccer_Program_List_Table();
		$table->prepare_items();
		$create_url = add_query_arg(['post_type' => 'product', 'page' => self::PAGE_SLUG, 'action' => 'create'], admin_url('edit.php'));
		$show_issues = isset($_GET['show_issues_only']) && $_GET['show_issues_only'] === '1';
		$filter_action = menu_page_url(self::PAGE_SLUG, false);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Program Manager', 'intersoccer-product-variations'); ?></h1>
			<a href="<?php echo esc_url($create_url); ?>" class="page-title-action"><?php esc_html_e('Create New Program', 'intersoccer-product-variations'); ?></a>
			<hr class="wp-header-end">
			<p class="description"><?php esc_html_e('Manage InterSoccer programs. Green = complete, yellow = partially complete, red = missing required attributes.', 'intersoccer-product-variations'); ?></p>

			<form method="get" action="<?php echo esc_url($filter_action); ?>" style="margin: 12px 0;">
				<input type="hidden" name="post_type" value="product" />
				<input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
				<label>
					<input type="checkbox" name="show_issues_only" value="1" <?php checked($show_issues); ?> onchange="this.form.submit();" />
					<?php esc_html_e('Show only programs with issues', 'intersoccer-product-variations'); ?>
				</label>
			</form>

			<form method="get" action="<?php echo esc_url($filter_action); ?>" class="search-box" style="margin: 0 0 12px;">
				<input type="hidden" name="post_type" value="product" />
				<input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
				<?php if ($show_issues) : ?>
					<input type="hidden" name="show_issues_only" value="1" />
				<?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr(isset($_REQUEST['s']) ? $_REQUEST['s'] : ''); ?>" placeholder="<?php esc_attr_e('Search by name…', 'intersoccer-product-variations'); ?>" />
				<input type="submit" class="button" value="<?php esc_attr_e('Search Programs', 'intersoccer-product-variations'); ?>" />
				<?php if (!empty($_REQUEST['s'])) : ?>
					<a href="<?php echo esc_url($filter_action . '&post_type=product&page=' . self::PAGE_SLUG); ?>" class="button"><?php esc_html_e('Clear', 'intersoccer-product-variations'); ?></a>
				<?php endif; ?>
			</form>

			<form method="post">
				<?php wp_nonce_field('intersoccer_pm_bulk_nonce'); ?>
				<?php if (!empty($_REQUEST['s'])) : ?>
					<input type="hidden" name="s" value="<?php echo esc_attr($_REQUEST['s']); ?>" />
				<?php endif; ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	// =========================================================================
	// Detail view
	// =========================================================================

	private static function render_detail_view($product_id) {
		// Handle recalc form submission before rendering
		if (isset($_POST['intersoccer_recalc_end_dates']) && check_admin_referer('intersoccer_recalc_nonce')) {
			intersoccer_run_course_end_date_update_callback();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Course end dates recalculated successfully.', 'intersoccer-product-variations') . '</p></div>';
		}

		$product = wc_get_product($product_id);
		if (!$product || !$product->is_type('variable')) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Invalid or non-variable product.', 'intersoccer-product-variations') . '</p></div></div>';
			return;
		}

		$completeness = self::get_product_completeness($product_id);
		$type         = $completeness['type'];
		$templates    = intersoccer_attr_product_type_templates();
		$list_url     = menu_page_url(self::PAGE_SLUG, false);
		$edit_url     = get_edit_post_link($product_id, 'raw');
		$duplicate_url = add_query_arg([
			'post_type' => 'product',
			'page'      => self::PAGE_SLUG,
			'action'    => 'duplicate',
			'source_id' => $product_id,
		], admin_url('edit.php'));

		?>
		<div class="wrap intersoccer-pm-detail">
			<h1>
				<?php echo esc_html($product->get_name()); ?>
				<span class="intersoccer-pm-type-badge"><?php echo esc_html(ucfirst($type ?: 'unknown')); ?></span>
			</h1>
			<p>
				<a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('Back to Program List', 'intersoccer-product-variations'); ?></a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit in WooCommerce', 'intersoccer-product-variations'); ?></a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url($duplicate_url); ?>"><?php esc_html_e('Duplicate Program', 'intersoccer-product-variations'); ?></a>
			</p>

			<h2><?php esc_html_e('Parent Attributes', 'intersoccer-product-variations'); ?></h2>
			<?php
			$multi_select_slugs = ['days-of-week', 'camp-terms', 'camp-times', 'course-times', 'intersoccer-venues'];
			$required_parent = intersoccer_attr_required($type, 'parent');
			?>
			<table class="widefat striped" style="max-width: 700px;">
				<thead>
					<tr>
						<th><?php esc_html_e('Attribute', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Value', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Status', 'intersoccer-product-variations'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ($required_parent as $taxonomy) :
						$slug        = str_replace('pa_', '', $taxonomy);
						$label       = intersoccer_attr_wc_label($slug) ?: $slug;
						$current     = wc_get_product_terms($product_id, $taxonomy, ['fields' => 'slugs']);
						$all_terms   = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
						$is_multi    = in_array($slug, $multi_select_slugs, true);
						$ok          = !empty($current);
					?>
					<tr>
						<td><?php echo esc_html($label); ?></td>
						<td>
							<select class="intersoccer-pm-attr-edit" data-taxonomy="<?php echo esc_attr($taxonomy); ?>" <?php echo $is_multi ? 'multiple size="5"' : ''; ?> style="min-width: 200px;">
								<?php if (!$is_multi) : ?>
									<option value=""><?php esc_html_e('— Select —', 'intersoccer-product-variations'); ?></option>
								<?php endif; ?>
								<?php if (!is_wp_error($all_terms)) : foreach ($all_terms as $term) : ?>
									<option value="<?php echo esc_attr($term->slug); ?>" <?php echo in_array($term->slug, $current, true) ? 'selected' : ''; ?>>
										<?php echo esc_html($term->name); ?>
									</option>
								<?php endforeach; endif; ?>
							</select>
							<span class="intersoccer-pm-add-term-wrap" style="display:block;margin-top:6px;">
								<a href="#" class="intersoccer-pm-add-term-toggle" data-taxonomy="<?php echo esc_attr($taxonomy); ?>"><?php esc_html_e('+ Add new', 'intersoccer-product-variations'); ?></a>
								<span class="intersoccer-pm-add-term-form" style="display:none;">
									<input type="text" class="intersoccer-pm-new-term-input" placeholder="<?php esc_attr_e('Term name', 'intersoccer-product-variations'); ?>" style="min-width:140px;" />
									<button type="button" class="button button-small intersoccer-pm-add-term-btn" data-taxonomy="<?php echo esc_attr($taxonomy); ?>"><?php esc_html_e('Add', 'intersoccer-product-variations'); ?></button>
									<a href="#" class="intersoccer-pm-add-term-cancel"><?php esc_html_e('Cancel', 'intersoccer-product-variations'); ?></a>
									<span class="intersoccer-pm-add-term-status" style="margin-left:6px;font-size:12px;"></span>
								</span>
							</span>
						</td>
						<td class="intersoccer-pm-attr-status"><?php echo $ok ? '<span style="color:green;">&#10003;</span>' : '<span style="color:red;">&#10007;</span>'; ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin-top: 12px;">
				<button type="button" class="button button-primary" id="intersoccer-pm-save-attrs-btn" data-product-id="<?php echo esc_attr($product_id); ?>">
					<?php esc_html_e('Save Attributes', 'intersoccer-product-variations'); ?>
				</button>
				<span id="intersoccer-pm-attrs-save-status" style="margin-left: 8px;"></span>
			</p>

			<h2><?php esc_html_e('Variations', 'intersoccer-product-variations'); ?>
				<span class="intersoccer-pm-variation-count">(<?php echo esc_html($completeness['variations_healthy'] . '/' . $completeness['variations_total'] . ' ' . __('healthy', 'intersoccer-product-variations')); ?>)</span>
			</h2>

			<?php if ($type && $completeness['variations_total'] === 0) : ?>
				<p>
					<button type="button" class="button button-primary" id="intersoccer-pm-scaffold-btn" data-product-id="<?php echo esc_attr($product_id); ?>" data-product-type="<?php echo esc_attr($type); ?>">
						<?php esc_html_e('Auto-generate Variations', 'intersoccer-product-variations'); ?>
					</button>
				</p>
			<?php endif; ?>

			<table class="widefat striped intersoccer-pm-variations-table">
				<thead>
					<tr>
						<th><?php esc_html_e('ID', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Attributes', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Price (CHF)', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Status', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Issues', 'intersoccer-product-variations'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$children = $product->get_children();
					foreach ($children as $var_id) :
						$variation    = wc_get_product($var_id);
						if (!$variation) continue;
						$var_result   = self::get_variation_completeness($var_id, $type);
						$var_attrs    = $variation->get_attributes();
						$attr_display = [];
						foreach ($var_attrs as $tax => $val) {
							$slug  = str_replace('pa_', '', $tax);
							$label = intersoccer_attr_wc_label($slug) ?: $slug;
							if ($val) {
								$term = get_term_by('slug', $val, $tax);
								$attr_display[] = $label . ': ' . ($term ? $term->name : $val);
							}
						}
						$price = $variation->get_regular_price();
					?>
					<tr>
						<td><?php echo esc_html($var_id); ?></td>
						<td><?php echo esc_html(implode(' | ', $attr_display) ?: '—'); ?></td>
						<td>
							<input type="number" step="0.01" min="0" class="intersoccer-pm-price-input" data-variation-id="<?php echo esc_attr($var_id); ?>" value="<?php echo esc_attr($price); ?>" style="width: 100px;" />
							<span class="intersoccer-pm-price-status"></span>
						</td>
						<td>
							<?php if ($var_result['is_healthy']) : ?>
								<span style="color:green;">&#10003; <?php esc_html_e('Healthy', 'intersoccer-product-variations'); ?></span>
							<?php else : ?>
								<span style="color:red;">&#10007; <?php esc_html_e('Issues', 'intersoccer-product-variations'); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html(implode(', ', $var_result['missing'])); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php // --- Variation Health Tools (merged from standalone Variation Health page) --- ?>
			<?php
			$unhealthy_ids = [];
			foreach ($completeness['variations_issues'] as $vid => $issues) {
				$unhealthy_ids[] = (int) $vid;
			}
			?>

			<?php if (!empty($unhealthy_ids)) : ?>
			<div style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
				<h3><?php esc_html_e('Variation Health Tools', 'intersoccer-product-variations'); ?></h3>
				<p class="description"><?php esc_html_e('Refresh attributes on unhealthy variations to apply default values for missing fields.', 'intersoccer-product-variations'); ?></p>
				<p>
					<button type="button" class="button button-primary" id="intersoccer-pm-refresh-attrs-btn"
						data-variation-ids="<?php echo esc_attr(wp_json_encode($unhealthy_ids)); ?>">
						<?php printf(esc_html__('Refresh Attributes (%d unhealthy)', 'intersoccer-product-variations'), count($unhealthy_ids)); ?>
					</button>
				</p>
			</div>
			<?php endif; ?>

			<?php if ($type === 'course') : ?>
			<div style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
				<h3><?php esc_html_e('Course Tools', 'intersoccer-product-variations'); ?></h3>

				<form method="post" action="" style="margin-bottom: 12px;">
					<?php wp_nonce_field('intersoccer_recalc_nonce'); ?>
					<p class="description"><?php esc_html_e('Recalculate end dates for all course variations based on start date, sessions, and holidays.', 'intersoccer-product-variations'); ?></p>
					<input type="submit" name="intersoccer_recalc_end_dates" class="button" value="<?php esc_attr_e('Recalculate Course End Dates', 'intersoccer-product-variations'); ?>" />
				</form>

				<?php if (function_exists('intersoccer_course_holiday_fix_has_run') && !intersoccer_course_holiday_fix_has_run()) : ?>
				<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee;">
					<p><strong><?php esc_html_e('Course Holiday Fix (One-time)', 'intersoccer-product-variations'); ?></strong></p>
					<p class="description"><?php esc_html_e('Fix existing courses that were created with inflated session counts to work around the old holiday calculation bug.', 'intersoccer-product-variations'); ?></p>
					<button type="button" class="button button-secondary" id="intersoccer-pm-course-holiday-fix-btn">
						<?php esc_html_e('Run Course Holiday Fix', 'intersoccer-product-variations'); ?>
					</button>
					<div id="intersoccer-pm-holiday-fix-result" style="margin-top: 10px; display: none;"></div>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

		</div>
		<style>
			.intersoccer-pm-type-badge { background: #2271b1; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 12px; vertical-align: middle; margin-left: 8px; }
			.intersoccer-pm-variation-count { font-size: 14px; font-weight: normal; color: #666; }
			.intersoccer-pm-price-status { font-size: 11px; margin-left: 4px; }
		</style>
		<?php
	}

	// =========================================================================
	// Create wizard
	// =========================================================================

	private static function render_create_wizard() {
		$templates = intersoccer_attr_product_type_templates();
		$types     = array_keys($templates);

		$term_options = [];
		foreach (intersoccer_attr_registry() as $slug => $def) {
			$taxonomy = intersoccer_attr_taxonomy($slug);
			$terms    = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
			if (!is_wp_error($terms)) {
				$term_options[$taxonomy] = $terms;
			}
		}
		?>
		<div class="wrap intersoccer-pm-wizard">
			<h1><?php esc_html_e('Create New Program', 'intersoccer-product-variations'); ?></h1>
			<p><a href="<?php echo esc_url(menu_page_url(self::PAGE_SLUG, false)); ?>">&larr; <?php esc_html_e('Back to Program List', 'intersoccer-product-variations'); ?></a></p>

			<div class="intersoccer-pm-steps">
				<div class="intersoccer-pm-step-indicator">
					<span class="step-dot active" data-step="1">1</span>
					<span class="step-dot" data-step="2">2</span>
					<span class="step-dot" data-step="3">3</span>
					<span class="step-dot" data-step="4">4</span>
				</div>

				<!-- Step 1: Type selector -->
				<div class="intersoccer-pm-step" data-step="1">
					<h2><?php esc_html_e('Step 1: Select Program Type', 'intersoccer-product-variations'); ?></h2>
					<div class="intersoccer-pm-type-cards">
						<?php foreach ($types as $t) : ?>
							<label class="intersoccer-pm-type-card">
								<input type="radio" name="program_type" value="<?php echo esc_attr($t); ?>" />
								<span class="card-label"><?php echo esc_html(ucfirst($t)); ?></span>
								<span class="card-desc">
									<?php
									$parent_count = count($templates[$t]['parent']);
									$var_count    = count($templates[$t]['variation']);
									printf(
										esc_html__('%d parent attrs, %d variation attrs', 'intersoccer-product-variations'),
										$parent_count,
										$var_count
									);
									?>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
					<p><button type="button" class="button button-primary intersoccer-pm-next" data-next="2"><?php esc_html_e('Next', 'intersoccer-product-variations'); ?></button></p>
				</div>

				<!-- Step 2: Parent attributes -->
				<div class="intersoccer-pm-step" data-step="2" style="display:none;">
					<h2><?php esc_html_e('Step 2: Program Details', 'intersoccer-product-variations'); ?></h2>
					<table class="form-table intersoccer-pm-parent-attrs">
						<tr>
							<th><label for="pm-product-name"><?php esc_html_e('Program Name *', 'intersoccer-product-variations'); ?></label></th>
							<td><input type="text" id="pm-product-name" class="regular-text" required /></td>
						</tr>
						<?php foreach ($types as $t) :
							$parent_slugs = $templates[$t]['parent'];
							foreach ($parent_slugs as $slug) :
								if ($slug === 'activity-type') :
									continue;
								endif;
								$taxonomy = intersoccer_attr_taxonomy($slug);
								$label    = intersoccer_attr_wc_label($slug) ?: $slug;
								$terms    = $term_options[$taxonomy] ?? [];
								$is_multi = in_array($slug, ['days-of-week', 'camp-terms', 'camp-times', 'course-times', 'intersoccer-venues'], true);
						?>
						<tr class="intersoccer-pm-attr-row" data-types="<?php echo esc_attr($t); ?>" style="display:none;">
							<th><label><?php echo esc_html($label); ?> *</label></th>
							<td>
								<select name="parent_attrs[<?php echo esc_attr($taxonomy); ?>][]" class="intersoccer-pm-attr-select" data-taxonomy="<?php echo esc_attr($taxonomy); ?>" <?php echo $is_multi ? 'multiple size="5"' : ''; ?>>
									<?php if (!$is_multi) : ?>
										<option value=""><?php esc_html_e('— Select —', 'intersoccer-product-variations'); ?></option>
									<?php endif; ?>
									<?php foreach ($terms as $term) : ?>
										<option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<?php endforeach; endforeach; ?>
					</table>
					<p>
						<button type="button" class="button intersoccer-pm-prev" data-prev="1"><?php esc_html_e('Back', 'intersoccer-product-variations'); ?></button>
						<button type="button" class="button button-primary intersoccer-pm-next" data-next="3"><?php esc_html_e('Next', 'intersoccer-product-variations'); ?></button>
					</p>
				</div>

				<!-- Step 3: Variation matrix -->
				<div class="intersoccer-pm-step" data-step="3" style="display:none;">
					<h2><?php esc_html_e('Step 3: Variation Matrix', 'intersoccer-product-variations'); ?></h2>
					<p class="description"><?php esc_html_e('The following variations will be created. Uncheck any you do not need.', 'intersoccer-product-variations'); ?></p>
					<div id="intersoccer-pm-matrix-container">
						<!-- Populated by JS based on type -->
					</div>
					<p>
						<button type="button" class="button intersoccer-pm-prev" data-prev="2"><?php esc_html_e('Back', 'intersoccer-product-variations'); ?></button>
						<button type="button" class="button button-primary intersoccer-pm-next" data-next="4"><?php esc_html_e('Next', 'intersoccer-product-variations'); ?></button>
					</p>
				</div>

				<!-- Step 4: Review and create -->
				<div class="intersoccer-pm-step" data-step="4" style="display:none;">
					<h2><?php esc_html_e('Step 4: Review & Create', 'intersoccer-product-variations'); ?></h2>
					<div id="intersoccer-pm-review-summary"></div>
					<p>
						<button type="button" class="button intersoccer-pm-prev" data-prev="3"><?php esc_html_e('Back', 'intersoccer-product-variations'); ?></button>
						<button type="button" class="button button-primary" id="intersoccer-pm-create-btn"><?php esc_html_e('Create as Draft', 'intersoccer-product-variations'); ?></button>
					</p>
					<div id="intersoccer-pm-create-result" style="display:none;"></div>
				</div>
			</div>
		</div>

		<!-- Pass variation matrix data to JS -->
		<script type="text/javascript">
			var intersoccerPMMatrix = {
				camp: [
					{ 'pa_booking-type': 'full-week', 'pa_age-group': '5-13y-full-day', label: '<?php echo esc_js(__('Full Week / Full Day (5-13y)', 'intersoccer-product-variations')); ?>' },
					{ 'pa_booking-type': 'full-week', 'pa_age-group': '3-5y-half-day', label: '<?php echo esc_js(__('Full Week / Half Day (3-5y)', 'intersoccer-product-variations')); ?>' },
					{ 'pa_booking-type': 'single-days', 'pa_age-group': '5-13y-full-day', label: '<?php echo esc_js(__('Single Day(s) / Full Day (5-13y)', 'intersoccer-product-variations')); ?>' },
					{ 'pa_booking-type': 'single-days', 'pa_age-group': '3-5y-half-day', label: '<?php echo esc_js(__('Single Day(s) / Half Day (3-5y)', 'intersoccer-product-variations')); ?>' }
				],
				course: <?php echo wp_json_encode(self::get_course_matrix_options($term_options)); ?>,
				birthday: <?php echo wp_json_encode(self::get_birthday_matrix_options($term_options)); ?>,
				tournament: <?php echo wp_json_encode(self::get_tournament_matrix_options($term_options)); ?>
			};
		</script>

		<style>
			.intersoccer-pm-steps { max-width: 800px; }
			.intersoccer-pm-step-indicator { display: flex; gap: 12px; margin-bottom: 20px; }
			.step-dot { width: 32px; height: 32px; border-radius: 50%; background: #ddd; display: flex; align-items: center; justify-content: center; font-weight: bold; }
			.step-dot.active { background: #2271b1; color: #fff; }
			.step-dot.completed { background: #00a32a; color: #fff; }
			.intersoccer-pm-type-cards { display: flex; gap: 16px; flex-wrap: wrap; margin: 16px 0; }
			.intersoccer-pm-type-card { display: flex; flex-direction: column; align-items: center; padding: 20px 30px; border: 2px solid #ddd; border-radius: 8px; cursor: pointer; transition: border-color 0.2s; }
			.intersoccer-pm-type-card:hover { border-color: #2271b1; }
			.intersoccer-pm-type-card input:checked + .card-label { color: #2271b1; font-weight: bold; }
			.intersoccer-pm-type-card input { margin-bottom: 8px; }
			.card-label { font-size: 16px; font-weight: 600; }
			.card-desc { font-size: 12px; color: #666; margin-top: 4px; }
			.intersoccer-pm-matrix-row { padding: 6px 0; }
			.intersoccer-pm-matrix-row label { cursor: pointer; }
		</style>
		<?php
	}

	/**
	 * Build course variation matrix from available terms.
	 */
	private static function get_course_matrix_options($term_options) {
		$days  = $term_options['pa_course-day'] ?? [];
		$times = $term_options['pa_course-times'] ?? [];
		$ages  = $term_options['pa_age-group'] ?? [];

		$matrix = [];
		foreach ($days as $day) {
			foreach ($ages as $age) {
				$matrix[] = [
					'pa_course-day' => $day->slug,
					'pa_age-group'  => $age->slug,
					'label'         => $day->name . ' / ' . $age->name,
				];
			}
		}
		return $matrix;
	}

	private static function get_birthday_matrix_options($term_options) {
		$ages   = $term_options['pa_age-group'] ?? [];
		$matrix = [];
		foreach ($ages as $age) {
			$matrix[] = [
				'pa_age-group' => $age->slug,
				'label'        => $age->name,
			];
		}
		return $matrix;
	}

	private static function get_tournament_matrix_options($term_options) {
		$days  = $term_options['pa_tournament-day'] ?? [];
		$times = $term_options['pa_tournament-time'] ?? [];
		$ages  = $term_options['pa_age-group'] ?? [];

		$matrix = [];
		foreach ($days as $day) {
			foreach ($ages as $age) {
				$matrix[] = [
					'pa_tournament-day'  => $day->slug,
					'pa_age-group'       => $age->slug,
					'label'              => $day->name . ' / ' . $age->name,
				];
			}
		}
		return $matrix;
	}

	// =========================================================================
	// Duplicate wizard
	// =========================================================================

	private static function render_duplicate_wizard($source_id) {
		$source = wc_get_product($source_id);
		if (!$source || !$source->is_type('variable')) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Invalid source product.', 'intersoccer-product-variations') . '</p></div></div>';
			return;
		}

		$type       = InterSoccer_Product_Types::get_product_type($source_id);
		$list_url   = menu_page_url(self::PAGE_SLUG, false);
		$seasons    = get_terms(['taxonomy' => 'pa_program-season', 'hide_empty' => false]);
		?>
		<div class="wrap intersoccer-pm-duplicate">
			<h1><?php esc_html_e('Duplicate Program', 'intersoccer-product-variations'); ?></h1>
			<p><a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('Back to Program List', 'intersoccer-product-variations'); ?></a></p>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e('Source Program', 'intersoccer-product-variations'); ?></th>
					<td><strong><?php echo esc_html($source->get_name()); ?></strong> (<?php echo esc_html(ucfirst($type)); ?>)</td>
				</tr>
				<tr>
					<th><label for="pm-dup-name"><?php esc_html_e('New Program Name', 'intersoccer-product-variations'); ?></label></th>
					<td><input type="text" id="pm-dup-name" class="regular-text" value="<?php echo esc_attr($source->get_name() . ' (Copy)'); ?>" /></td>
				</tr>
				<tr>
					<th><label for="pm-dup-season"><?php esc_html_e('New Season', 'intersoccer-product-variations'); ?></label></th>
					<td>
						<select id="pm-dup-season">
							<option value=""><?php esc_html_e('— Keep same season —', 'intersoccer-product-variations'); ?></option>
							<?php if (!is_wp_error($seasons)) : foreach ($seasons as $term) : ?>
								<option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></option>
							<?php endforeach; endif; ?>
						</select>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button button-primary" id="intersoccer-pm-duplicate-btn" data-source-id="<?php echo esc_attr($source_id); ?>">
					<?php esc_html_e('Duplicate as Draft', 'intersoccer-product-variations'); ?>
				</button>
			</p>
			<div id="intersoccer-pm-duplicate-result" style="display:none;"></div>
		</div>
		<?php
	}

	// =========================================================================
	// AJAX handlers
	// =========================================================================

	public static function ajax_create_product() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
		$type = isset($_POST['program_type']) ? sanitize_text_field($_POST['program_type']) : '';

		if (empty($name) || empty($type)) {
			wp_send_json_error(['message' => __('Name and type are required.', 'intersoccer-product-variations')]);
		}

		$valid_types = array_keys(intersoccer_attr_product_type_templates());
		if (!in_array($type, $valid_types, true)) {
			wp_send_json_error(['message' => __('Invalid program type.', 'intersoccer-product-variations')]);
		}

		$product = new WC_Product_Variable();
		$product->set_name($name);
		$product->set_status('draft');
		$product->set_catalog_visibility('visible');

		$parent_attrs_raw = isset($_POST['parent_attrs']) && is_array($_POST['parent_attrs']) ? $_POST['parent_attrs'] : [];
		$wc_attributes    = [];

		foreach ($parent_attrs_raw as $taxonomy => $term_slugs) {
			$taxonomy = sanitize_text_field($taxonomy);
			if (!taxonomy_exists($taxonomy)) {
				continue;
			}

			$term_slugs = array_map('sanitize_text_field', (array) $term_slugs);
			$term_slugs = array_filter($term_slugs);
			if (empty($term_slugs)) {
				continue;
			}

			$term_ids = [];
			foreach ($term_slugs as $slug) {
				$term = get_term_by('slug', $slug, $taxonomy);
				if ($term && !is_wp_error($term)) {
					$term_ids[] = $term->term_id;
				}
			}

			if (!empty($term_ids)) {
				$attribute = new WC_Product_Attribute();
				$attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
				$attribute->set_name($taxonomy);
				$attribute->set_options($term_ids);
				$attribute->set_visible(true);
				$attribute->set_variation(self::is_variation_attribute($taxonomy, $type));
				$wc_attributes[] = $attribute;
			}
		}

		$variation_taxonomies = intersoccer_attr_required($type, 'variation');
		foreach ($variation_taxonomies as $taxonomy) {
			$already_set = false;
			foreach ($wc_attributes as $attr) {
				if ($attr->get_name() === $taxonomy) {
					$attr->set_variation(true);
					$already_set = true;
					break;
				}
			}
			if (!$already_set && taxonomy_exists($taxonomy)) {
				$all_terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids']);
				if (!is_wp_error($all_terms) && !empty($all_terms)) {
					$attribute = new WC_Product_Attribute();
					$attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
					$attribute->set_name($taxonomy);
					$attribute->set_options($all_terms);
					$attribute->set_visible(true);
					$attribute->set_variation(true);
					$wc_attributes[] = $attribute;
				}
			}
		}

		$product->set_attributes($wc_attributes);
		$product_id = $product->save();

		if (!$product_id) {
			wp_send_json_error(['message' => __('Failed to create product.', 'intersoccer-product-variations')]);
		}

		update_post_meta($product_id, '_intersoccer_product_type', $type);

		$activity_type_map = [
			'camp'       => 'camp',
			'course'     => 'course',
			'birthday'   => 'birthday-party',
			'tournament' => 'tournament',
		];
		if (isset($activity_type_map[$type])) {
			$at_slug = $activity_type_map[$type];
			wp_set_object_terms($product_id, $at_slug, 'pa_activity-type');

			$has_activity_attr = false;
			foreach ($wc_attributes as $attr) {
				if ($attr->get_name() === 'pa_activity-type') {
					$has_activity_attr = true;
					break;
				}
			}
			if (!$has_activity_attr) {
				$at_term = get_term_by('slug', $at_slug, 'pa_activity-type');
				if ($at_term && !is_wp_error($at_term)) {
					$at_attr = new WC_Product_Attribute();
					$at_attr->set_id(wc_attribute_taxonomy_id_by_name('pa_activity-type'));
					$at_attr->set_name('pa_activity-type');
					$at_attr->set_options([$at_term->term_id]);
					$at_attr->set_visible(true);
					$at_attr->set_variation(false);
					$wc_attributes[] = $at_attr;
					$product = wc_get_product($product_id);
					$product->set_attributes($wc_attributes);
					$product->save();
				}
			}
		}

		$variations_created = 0;
		$matrix = isset($_POST['matrix']) && is_array($_POST['matrix']) ? $_POST['matrix'] : [];
		foreach ($matrix as $row) {
			if (!is_array($row)) {
				continue;
			}
			$var_id = self::create_single_variation($product_id, $type, $row);
			if ($var_id) {
				$variations_created++;
			}
		}

		wc_delete_product_transients($product_id);

		$detail_url = add_query_arg([
			'post_type'  => 'product',
			'page'       => self::PAGE_SLUG,
			'product_id' => $product_id,
		], admin_url('edit.php'));

		wp_send_json_success([
			'product_id'         => $product_id,
			'variations_created' => $variations_created,
			'redirect'           => $detail_url,
		]);
	}

	public static function ajax_scaffold_variations() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$type       = isset($_POST['product_type']) ? sanitize_text_field($_POST['product_type']) : '';

		if (!$product_id || !$type) {
			wp_send_json_error(['message' => __('Missing product ID or type.', 'intersoccer-product-variations')]);
		}

		$product = wc_get_product($product_id);
		if (!$product || !$product->is_type('variable')) {
			wp_send_json_error(['message' => __('Invalid variable product.', 'intersoccer-product-variations')]);
		}

		$matrix = self::get_default_matrix($type);
		$created = 0;

		foreach ($matrix as $row) {
			$var_id = self::create_single_variation($product_id, $type, $row);
			if ($var_id) {
				$created++;
			}
		}

		wc_delete_product_transients($product_id);

		wp_send_json_success([
			'created' => $created,
			'message' => sprintf(__('%d variations created.', 'intersoccer-product-variations'), $created),
		]);
	}

	public static function ajax_check_completeness() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		if (!$product_id) {
			wp_send_json_error(['message' => __('Missing product ID.', 'intersoccer-product-variations')]);
		}

		wp_send_json_success(self::get_product_completeness($product_id));
	}

	public static function ajax_save_variation_price() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
		$price        = isset($_POST['price']) ? wc_format_decimal($_POST['price']) : '';

		if (!$variation_id) {
			wp_send_json_error(['message' => __('Missing variation ID.', 'intersoccer-product-variations')]);
		}

		$variation = wc_get_product($variation_id);
		if (!$variation || !($variation instanceof WC_Product_Variation)) {
			wp_send_json_error(['message' => __('Invalid variation.', 'intersoccer-product-variations')]);
		}

		$variation->set_regular_price($price);
		$variation->set_price($price);
		$variation->save();

		wc_delete_product_transients($variation->get_parent_id());

		wp_send_json_success(['variation_id' => $variation_id, 'price' => $price]);
	}

	public static function ajax_duplicate_program() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$source_id  = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
		$new_name   = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
		$new_season = isset($_POST['season']) ? sanitize_text_field($_POST['season']) : '';

		if (!$source_id || empty($new_name)) {
			wp_send_json_error(['message' => __('Source ID and name are required.', 'intersoccer-product-variations')]);
		}

		$source = wc_get_product($source_id);
		if (!$source || !$source->is_type('variable')) {
			wp_send_json_error(['message' => __('Invalid source product.', 'intersoccer-product-variations')]);
		}

		$type = InterSoccer_Product_Types::get_product_type($source_id);

		$new_product = new WC_Product_Variable();
		$new_product->set_name($new_name);
		$new_product->set_status('draft');
		$new_product->set_catalog_visibility('visible');

		$source_attributes = $source->get_attributes();
		$new_attributes    = [];

		foreach ($source_attributes as $attribute) {
			$clone = clone $attribute;
			if ($new_season && $clone->get_name() === 'pa_program-season') {
				$term = get_term_by('slug', $new_season, 'pa_program-season');
				if ($term && !is_wp_error($term)) {
					$clone->set_options([$term->term_id]);
				}
			}
			$new_attributes[] = $clone;
		}

		$new_product->set_attributes($new_attributes);
		$new_id = $new_product->save();

		if (!$new_id) {
			wp_send_json_error(['message' => __('Failed to create duplicate.', 'intersoccer-product-variations')]);
		}

		update_post_meta($new_id, '_intersoccer_product_type', $type);

		if ($new_season) {
			wp_set_object_terms($new_id, $new_season, 'pa_program-season');
		}

		foreach ($source->get_children() as $source_var_id) {
			$source_var = wc_get_product($source_var_id);
			if (!$source_var || !($source_var instanceof WC_Product_Variation)) {
				continue;
			}

			$new_var = new WC_Product_Variation();
			$new_var->set_parent_id($new_id);
			$new_var->set_attributes($source_var->get_attributes());
			$new_var->set_status('publish');
			$new_var_id = $new_var->save();

			if ($new_var_id) {
				$late_pickup = get_post_meta($source_var_id, '_intersoccer_enable_late_pickup', true);
				if ($late_pickup) {
					update_post_meta($new_var_id, '_intersoccer_enable_late_pickup', $late_pickup);
				}
				$camp_days = get_post_meta($source_var_id, '_intersoccer_camp_days_available', true);
				if ($camp_days) {
					update_post_meta($new_var_id, '_intersoccer_camp_days_available', $camp_days);
				}
				$lp_days = get_post_meta($source_var_id, '_intersoccer_late_pickup_days_available', true);
				if ($lp_days) {
					update_post_meta($new_var_id, '_intersoccer_late_pickup_days_available', $lp_days);
				}
			}
		}

		wc_delete_product_transients($new_id);

		$detail_url = add_query_arg([
			'post_type'  => 'product',
			'page'       => self::PAGE_SLUG,
			'product_id' => $new_id,
		], admin_url('edit.php'));

		wp_send_json_success([
			'product_id' => $new_id,
			'redirect'   => $detail_url,
		]);
	}

	public static function ajax_save_parent_attrs() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$attrs_raw  = isset($_POST['attrs']) ? $_POST['attrs'] : [];

		if (!$product_id || !is_array($attrs_raw)) {
			wp_send_json_error(['message' => __('Invalid request.', 'intersoccer-product-variations')]);
		}

		$product = wc_get_product($product_id);
		if (!$product || !$product->is_type('variable')) {
			wp_send_json_error(['message' => __('Invalid product.', 'intersoccer-product-variations')]);
		}

		$type = InterSoccer_Product_Types::get_product_type($product_id);
		$existing_attributes = $product->get_attributes();

		foreach ($attrs_raw as $taxonomy => $slugs) {
			$taxonomy = sanitize_text_field($taxonomy);
			if (strpos($taxonomy, 'pa_') !== 0) {
				continue;
			}

			if (!is_array($slugs)) {
				$slugs = [$slugs];
			}
			$slugs = array_map('sanitize_text_field', $slugs);
			$slugs = array_filter($slugs);

			wp_set_object_terms($product_id, $slugs, $taxonomy);

			$term_ids = [];
			foreach ($slugs as $slug) {
				$term = get_term_by('slug', $slug, $taxonomy);
				if ($term && !is_wp_error($term)) {
					$term_ids[] = $term->term_id;
				}
			}

			$attribute = isset($existing_attributes[$taxonomy])
				? $existing_attributes[$taxonomy]
				: new WC_Product_Attribute();

			$attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
			$attribute->set_name($taxonomy);
			$attribute->set_options($term_ids);
			$attribute->set_visible(true);
			$attribute->set_variation(self::is_variation_attribute($taxonomy, $type));

			$existing_attributes[$taxonomy] = $attribute;
		}

		$product->set_attributes($existing_attributes);
		$product->save();
		wc_delete_product_transients($product_id);

		$completeness = self::get_product_completeness($product_id);

		wp_send_json_success([
			'message'      => __('Attributes saved.', 'intersoccer-product-variations'),
			'completeness' => $completeness,
		]);
	}

	public static function ajax_create_term() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field(wp_unslash($_POST['taxonomy'])) : '';
		if (strpos($taxonomy, 'pa_') !== 0) {
			wp_send_json_error(['message' => __('Invalid taxonomy.', 'intersoccer-product-variations')]);
		}

		if (!function_exists('intersoccer_attr_slug_from_taxonomy') || !intersoccer_attr_slug_from_taxonomy($taxonomy)) {
			wp_send_json_error(['message' => __('Taxonomy is not registered.', 'intersoccer-product-variations')]);
		}

		$term_name = isset($_POST['term_name']) ? sanitize_text_field(wp_unslash($_POST['term_name'])) : '';
		if ($term_name === '') {
			wp_send_json_error(['message' => __('Term name is required.', 'intersoccer-product-variations')]);
		}

		$result = wp_insert_term($term_name, $taxonomy);
		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		$term = get_term($result['term_id'], $taxonomy);
		if (!$term || is_wp_error($term)) {
			wp_send_json_error(['message' => __('Term created but could not be loaded.', 'intersoccer-product-variations')]);
		}

		wp_send_json_success([
			'term_id' => (int) $result['term_id'],
			'slug'    => $term->slug,
			'name'    => $term_name,
		]);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private static function is_variation_attribute($taxonomy, $product_type) {
		$variation_taxonomies = intersoccer_attr_required($product_type, 'variation');
		return in_array($taxonomy, $variation_taxonomies, true);
	}

	private static function get_default_matrix($type) {
		switch ($type) {
			case 'camp':
				return [
					['pa_booking-type' => 'full-week', 'pa_age-group' => '5-13y-full-day'],
					['pa_booking-type' => 'full-week', 'pa_age-group' => '3-5y-half-day'],
					['pa_booking-type' => 'single-days', 'pa_age-group' => '5-13y-full-day'],
					['pa_booking-type' => 'single-days', 'pa_age-group' => '3-5y-half-day'],
				];
			case 'course':
				$days = get_terms(['taxonomy' => 'pa_course-day', 'hide_empty' => false]);
				$ages = get_terms(['taxonomy' => 'pa_age-group', 'hide_empty' => false]);
				$matrix = [];
				if (!is_wp_error($days) && !is_wp_error($ages)) {
					foreach ($days as $day) {
						foreach ($ages as $age) {
							$matrix[] = ['pa_course-day' => $day->slug, 'pa_age-group' => $age->slug];
						}
					}
				}
				return $matrix;
			case 'birthday':
				$ages = get_terms(['taxonomy' => 'pa_age-group', 'hide_empty' => false]);
				$matrix = [];
				if (!is_wp_error($ages)) {
					foreach ($ages as $age) {
						$matrix[] = ['pa_age-group' => $age->slug];
					}
				}
				return $matrix;
			case 'tournament':
				$days = get_terms(['taxonomy' => 'pa_tournament-day', 'hide_empty' => false]);
				$ages = get_terms(['taxonomy' => 'pa_age-group', 'hide_empty' => false]);
				$matrix = [];
				if (!is_wp_error($days) && !is_wp_error($ages)) {
					foreach ($days as $day) {
						foreach ($ages as $age) {
							$matrix[] = ['pa_tournament-day' => $day->slug, 'pa_age-group' => $age->slug];
						}
					}
				}
				return $matrix;
			default:
				return [];
		}
	}

	/**
	 * @param int    $product_id
	 * @param string $type
	 * @param array  $attributes Key-value pairs of taxonomy => slug
	 * @return int|false Variation ID or false on failure.
	 */
	private static function create_single_variation($product_id, $type, $attributes) {
		$attributes = array_map('sanitize_text_field', (array) $attributes);
		unset($attributes['label']);

		$variation = new WC_Product_Variation();
		$variation->set_parent_id($product_id);
		$variation->set_status('publish');
		$variation->set_attributes($attributes);

		$var_id = $variation->save();
		if (!$var_id) {
			return false;
		}

		if ($type === 'camp') {
			$age_slug = $attributes['pa_age-group'] ?? '';
			$is_half_day = (strpos($age_slug, 'half-day') !== false || strpos($age_slug, 'half day') !== false);
			update_post_meta($var_id, '_intersoccer_enable_late_pickup', $is_half_day ? 'no' : 'yes');
		}

		if ($type === 'course') {
			$defaults = intersoccer_attr_refresh_defaults('course');
			foreach ($defaults as $meta_key => $default_value) {
				if (strpos($meta_key, '_') === 0) {
					update_post_meta($var_id, $meta_key, $default_value);
				}
			}
		}

		return $var_id;
	}
}

// =========================================================================
// WP_List_Table subclass
// =========================================================================

if (!class_exists('WP_List_Table')) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class InterSoccer_Program_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct([
			'singular' => 'program',
			'plural'   => 'programs',
			'ajax'     => false,
		]);
	}

	public function get_columns() {
		return [
			'cb'           => '<input type="checkbox" />',
			'name'         => __('Program Name', 'intersoccer-product-variations'),
			'type'         => __('Type', 'intersoccer-product-variations'),
			'season'       => __('Season', 'intersoccer-product-variations'),
			'year'         => __('Year', 'intersoccer-product-variations'),
			'venue'        => __('Venue', 'intersoccer-product-variations'),
			'completeness' => __('Completeness', 'intersoccer-product-variations'),
			'variations'   => __('Variations', 'intersoccer-product-variations'),
			'actions'      => __('Actions', 'intersoccer-product-variations'),
		];
	}

	public function column_cb($item) {
		return sprintf('<input type="checkbox" name="product_ids[]" value="%s" />', esc_attr($item['product_id']));
	}

	public function get_bulk_actions() {
		return [
			'refresh_attrs'       => __('Refresh Variation Attributes', 'intersoccer-product-variations'),
			'scaffold_variations' => __('Auto-scaffold Missing Variations', 'intersoccer-product-variations'),
		];
	}

	public function prepare_items() {
		$this->_column_headers = [$this->get_columns(), [], []];

		$search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

		$products = wc_get_products([
			'type'   => 'variable',
			'status' => ['publish', 'draft'],
			'limit'  => -1,
			's'      => $search,
		]);

		$show_issues_only = isset($_GET['show_issues_only']) && $_GET['show_issues_only'] === '1';

		$data = [];
		foreach ($products as $product) {
			$product_id   = $product->get_id();
			$type         = InterSoccer_Product_Types::get_product_type($product_id);
			if (!$type) {
				continue;
			}

			$completeness = InterSoccer_Program_Manager::get_product_completeness($product_id);

			if ($show_issues_only && $completeness['percentage'] >= 100) {
				continue;
			}

			$season_terms = wc_get_product_terms($product_id, 'pa_program-season', ['fields' => 'names']);
			$year_terms   = wc_get_product_terms($product_id, 'pa_program-year', ['fields' => 'names']);
			$venue_terms  = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names']);

			$data[] = [
				'product_id'   => $product_id,
				'name'         => $product->get_name(),
				'status'       => $product->get_status(),
				'type'         => $type,
				'season'       => !empty($season_terms) ? implode(', ', $season_terms) : '—',
				'year'         => !empty($year_terms) ? implode(', ', $year_terms) : '—',
				'venue'        => !empty($venue_terms) ? implode(', ', $venue_terms) : '—',
				'completeness' => $completeness,
			];
		}

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total_items  = count($data);

		$this->set_pagination_args([
			'total_items' => $total_items,
			'per_page'    => $per_page,
		]);

		$this->items = array_slice($data, ($current_page - 1) * $per_page, $per_page);
	}

	public function column_default($item, $column_name) {
		switch ($column_name) {
			case 'name':
				$detail_url = add_query_arg([
					'post_type'  => 'product',
					'page'       => InterSoccer_Program_Manager::PAGE_SLUG,
					'product_id' => $item['product_id'],
				], admin_url('edit.php'));
				$badge = $item['status'] === 'draft' ? ' <span class="post-state">' . esc_html__('Draft', 'intersoccer-product-variations') . '</span>' : '';
				return '<a href="' . esc_url($detail_url) . '"><strong>' . esc_html($item['name']) . '</strong></a>' . $badge;

			case 'type':
				return esc_html(ucfirst($item['type']));

			case 'season':
				return esc_html($item['season']);

			case 'year':
				return esc_html($item['year']);

			case 'venue':
				return esc_html($item['venue']);

			case 'completeness':
				$pct   = $item['completeness']['percentage'];
				$color = $pct >= 100 ? '#00a32a' : ($pct >= 50 ? '#dba617' : '#d63638');
				return '<div style="display:flex;align-items:center;gap:8px;">'
					. '<div style="flex:1;max-width:120px;height:8px;background:#ddd;border-radius:4px;overflow:hidden;">'
					. '<div style="width:' . esc_attr($pct) . '%;height:100%;background:' . esc_attr($color) . ';"></div>'
					. '</div>'
					. '<span style="font-size:12px;color:' . esc_attr($color) . ';">' . esc_html($pct . '%') . '</span>'
					. '</div>';

			case 'variations':
				$c = $item['completeness'];
				$color = $c['variations_healthy'] === $c['variations_total'] ? 'green' : 'orange';
				return '<span style="color:' . esc_attr($color) . ';">' . esc_html($c['variations_healthy'] . '/' . $c['variations_total']) . '</span>';

			case 'actions':
				$detail_url = add_query_arg([
					'post_type'  => 'product',
					'page'       => InterSoccer_Program_Manager::PAGE_SLUG,
					'product_id' => $item['product_id'],
				], admin_url('edit.php'));
				$dup_url = add_query_arg([
					'post_type'  => 'product',
					'page'       => InterSoccer_Program_Manager::PAGE_SLUG,
					'action'     => 'duplicate',
					'source_id'  => $item['product_id'],
				], admin_url('edit.php'));
				$edit_url = get_edit_post_link($item['product_id'], 'raw');

				return '<a href="' . esc_url($detail_url) . '" class="button button-small">' . esc_html__('Manage', 'intersoccer-product-variations') . '</a> '
					. '<a href="' . esc_url($dup_url) . '" class="button button-small">' . esc_html__('Duplicate', 'intersoccer-product-variations') . '</a> '
					. '<a href="' . esc_url($edit_url) . '" class="button button-small button-link">' . esc_html__('WC Edit', 'intersoccer-product-variations') . '</a>';

			default:
				return '';
		}
	}

	public function no_items() {
		esc_html_e('No programs found. Create one to get started!', 'intersoccer-product-variations');
	}
}

// Initialize
InterSoccer_Program_Manager::init();
