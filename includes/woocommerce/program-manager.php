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
		add_action('wp_ajax_intersoccer_pm_save_variation_venue', [__CLASS__, 'ajax_save_variation_venue']);
		add_action('wp_ajax_intersoccer_pm_save_variation_course_time', [__CLASS__, 'ajax_save_variation_course_time']);
		add_action('wp_ajax_intersoccer_pm_save_camp_schedule', [__CLASS__, 'ajax_save_camp_schedule']);
		add_action('wp_ajax_intersoccer_pm_prefill_camp_schedules', [__CLASS__, 'ajax_prefill_camp_schedules']);
		add_action('wp_ajax_intersoccer_pm_apply_parsed_camp_dates', [__CLASS__, 'ajax_apply_parsed_camp_dates']);
		add_action('wp_ajax_intersoccer_pm_propose_camp_times', [__CLASS__, 'ajax_propose_camp_times']);
		add_action('wp_ajax_intersoccer_pm_repair_camp_facets', [__CLASS__, 'ajax_repair_camp_facets']);
		add_action('wp_ajax_intersoccer_pm_sync_wpml_languages', [__CLASS__, 'ajax_sync_wpml_languages']);
		add_action('wp_ajax_intersoccer_pm_bulk_process_one', [__CLASS__, 'ajax_bulk_process_one']);
		add_action('wp_ajax_intersoccer_pm_quick_edit', [__CLASS__, 'ajax_quick_edit']);
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

		wp_enqueue_style(
			'intersoccer-program-manager',
			INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_URL . 'css/program-manager.css',
			[],
			'2.7.31.3'
		);

		wp_enqueue_script(
			'intersoccer-program-manager',
			INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_URL . 'js/program-manager.js',
			['jquery'],
			'2.7.31.3',
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
				'confirm_sync_wpml' => __('Sync all languages (WPML)? This creates or refreshes FR/DE product and variation translations from English, copying shared attribute slugs, schedule, prices, and course meta.', 'intersoccer-product-variations'),
				'saving'            => __('Saving…', 'intersoccer-product-variations'),
				'saved'             => __('Saved', 'intersoccer-product-variations'),
				'error'             => __('Error', 'intersoccer-product-variations'),
				'creating'          => __('Creating program…', 'intersoccer-product-variations'),
				'refreshing'        => __('Refreshing…', 'intersoccer-product-variations'),
				'syncing_wpml'      => __('Syncing WPML languages…', 'intersoccer-product-variations'),
				'select_type'       => __('Please select a program type.', 'intersoccer-product-variations'),
				'enter_name'        => __('Please enter a program name.', 'intersoccer-product-variations'),
				'select_target_year'=> __('Select or enter a target program year before applying Duplicate to year.', 'intersoccer-product-variations'),
				'select_programs'   => __('Select one or more programs in the list before applying Duplicate to year.', 'intersoccer-product-variations'),
				'bulk_progress_of'  => __('Processing %1$d of %2$d: %3$s', 'intersoccer-product-variations'),
				'bulk_tallies'      => __('Processed: %1$d · Skipped: %2$d · Failed: %3$d', 'intersoccer-product-variations'),
				'bulk_cancel'       => __('Cancel', 'intersoccer-product-variations'),
				'bulk_stopping'     => __('Stopping after current…', 'intersoccer-product-variations'),
				'bulk_complete'     => __('Bulk action complete.', 'intersoccer-product-variations'),
				'bulk_cancelled'    => __('Bulk action stopped.', 'intersoccer-product-variations'),
				'bulk_reloading'    => __('Reloading…', 'intersoccer-product-variations'),
				'bulk_select_items' => __('Select one or more programs before applying a bulk action.', 'intersoccer-product-variations'),
				'bulk_title_refresh'=> __('Refresh Variation Attributes', 'intersoccer-product-variations'),
				'bulk_title_scaffold'=> __('Auto-scaffold Missing Variations', 'intersoccer-product-variations'),
				'bulk_title_duplicate'=> __('Duplicate to year…', 'intersoccer-product-variations'),
				'bulk_title_wpml'   => __('Sync all languages (WPML)', 'intersoccer-product-variations'),
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
		// Birthday packages are city/length-of-party priced — never score InterSoccer Venues (or camp season/year).
		if ($type === 'birthday') {
			$required_parent = array_values(array_filter(
				$required_parent,
				static function ($taxonomy) {
					return !in_array($taxonomy, [
						'pa_intersoccer-venues',
						'pa_program-season',
						'pa_program-year',
					], true);
				}
			));
		}
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
			if (strpos($key, '_course_') === 0 || strpos($key, '_camp_') === 0 || strpos($key, '_') === 0) {
				// Camp week index only required when the variation has camp-terms.
				if ($key === '_camp_week_index' && $product_type === 'camp') {
					$terms = get_post_meta($variation_id, 'attribute_pa_camp-terms', true);
					if (($terms === '' || $terms === null || $terms === false)
						&& empty($variation->get_attribute('pa_camp-terms'))) {
						continue;
					}
				}
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
		$bulk_nonce_raw = '';
		if (!empty($_POST['intersoccer_pm_bulk_nonce'])) {
			$bulk_nonce_raw = (string) wp_unslash($_POST['intersoccer_pm_bulk_nonce']);
		} elseif (!empty($_POST['_wpnonce'])) {
			$bulk_nonce_raw = (string) wp_unslash($_POST['_wpnonce']);
		}
		if (!empty($_POST['product_ids']) && $bulk_nonce_raw !== '') {
			$nonce_ok = (bool) wp_verify_nonce($bulk_nonce_raw, 'intersoccer_pm_bulk_nonce');
			$cap_ok   = current_user_can(self::CAPABILITY);
			if ($nonce_ok && $cap_ok) {
				$action     = !empty($_POST['action']) && $_POST['action'] !== '-1' ? sanitize_text_field(wp_unslash($_POST['action'])) : '';
				if (!$action) {
					$action = !empty($_POST['action2']) && $_POST['action2'] !== '-1' ? sanitize_text_field(wp_unslash($_POST['action2'])) : '';
				}
				$product_ids = array_map('absint', (array) $_POST['product_ids']);
				$processed   = 0;

				if ($action === 'refresh_attrs') {
					foreach ($product_ids as $pid) {
						$result = self::process_bulk_item('refresh_attrs', $pid);
						if (($result['outcome'] ?? '') === 'processed') {
							$processed++;
						}
					}
					echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('%d products processed — variation attributes refreshed.', 'intersoccer-product-variations'), $processed) . '</p></div>';
				} elseif ($action === 'scaffold_variations') {
					foreach ($product_ids as $pid) {
						$result = self::process_bulk_item('scaffold_variations', $pid);
						if (($result['outcome'] ?? '') === 'processed') {
							$processed++;
						}
					}
					echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('%d products processed — variations scaffolded.', 'intersoccer-product-variations'), $processed) . '</p></div>';
				} elseif ($action === 'duplicate_to_year') {
					@set_time_limit(0);
					$target_year   = isset($_POST['pm_target_year']) ? sanitize_text_field(wp_unslash($_POST['pm_target_year'])) : '';
					$target_custom = isset($_POST['pm_target_year_custom']) ? sanitize_text_field(wp_unslash($_POST['pm_target_year_custom'])) : '';
					$target_season = isset($_POST['pm_target_season']) ? sanitize_text_field(wp_unslash($_POST['pm_target_season'])) : '';
					if (self::normalize_program_year($target_year) === '' && $target_custom !== '') {
						$target_year = $target_custom;
					}
					$target_year = self::normalize_program_year($target_year);

					if ($target_year === '') {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Bulk Duplicate requires a target program year (e.g. 2027).', 'intersoccer-product-variations') . '</p></div>';
					} else {
						$year_term = self::ensure_program_year_term($target_year);
						if (is_wp_error($year_term)) {
							echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($year_term->get_error_message()) . '</p></div>';
						} else {
							$created = [];
							$errors  = [];
							foreach ($product_ids as $pid) {
								$result = self::process_bulk_item('duplicate_to_year', $pid, [
									'year'   => $target_year,
									'season' => $target_season,
								]);
								if (($result['outcome'] ?? '') === 'processed' && !empty($result['new_product_id'])) {
									$created[] = (int) $result['new_product_id'];
									$processed++;
								} elseif (($result['outcome'] ?? '') === 'skipped' && !empty($result['message'])) {
									$errors[] = $result['message'];
								} elseif (($result['outcome'] ?? '') === 'failed' && !empty($result['message'])) {
									$errors[] = $result['message'];
								}
							}

							if ($processed > 0) {
								$links = [];
								foreach ($created as $new_id) {
									$product = wc_get_product($new_id);
									$label   = $product ? $product->get_name() : ('#' . $new_id);
									$url     = add_query_arg([
										'post_type'  => 'product',
										'page'       => self::PAGE_SLUG,
										'product_id' => $new_id,
									], admin_url('edit.php'));
									$links[] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
								}
								echo '<div class="notice notice-success is-dismissible"><p>'
									. sprintf(
										/* translators: 1: count, 2: year */
										esc_html__('Duplicated %1$d programs to Draft (year %2$s). Update dates/terms/prices, then Sync WPML before publishing.', 'intersoccer-product-variations'),
										$processed,
										esc_html($target_year)
									)
									. '</p>';
								if (!empty($links)) {
									echo '<p>' . implode(' · ', $links) . '</p>';
								}
								echo '</div>';
							}
							if (!empty($errors)) {
								echo '<div class="notice notice-warning is-dismissible"><p>'
									. esc_html__('Some programs could not be duplicated:', 'intersoccer-product-variations')
									. '</p><ul><li>' . implode('</li><li>', array_map('esc_html', $errors)) . '</li></ul></div>';
							}
							if ($processed === 0 && empty($errors)) {
								echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('No programs were duplicated.', 'intersoccer-product-variations') . '</p></div>';
							}
						}
					}
				} elseif ($action === 'sync_wpml_languages') {
					@set_time_limit(0);
					$created  = 0;
					$synced   = 0;
					$failed   = 0;
					$messages = [];
					foreach ($product_ids as $pid) {
						$result = self::process_bulk_item('sync_wpml_languages', $pid);
						if (($result['outcome'] ?? '') === 'failed' && empty($result['wpml'])) {
							$failed++;
							continue;
						}
						$processed++;
						$wpml = is_array($result['wpml'] ?? null) ? $result['wpml'] : [];
						$created += count($wpml['parents_created'] ?? []);
						$synced  += count($wpml['parents_synced'] ?? []);
						if (($result['outcome'] ?? '') === 'failed') {
							$failed++;
						}
						if (!empty($result['message'])) {
							$messages[] = sprintf('#%d: %s', (int) ($result['product_id'] ?? $pid), $result['message']);
						}
					}
					$summary = sprintf(
						/* translators: 1: products processed, 2: languages created, 3: languages refreshed, 4: failures */
						__('%1$d products processed — WPML: %2$d language(s) created, %3$d refreshed, %4$d with errors.', 'intersoccer-product-variations'),
						$processed,
						$created,
						$synced,
						$failed
					);
					$class = $failed > 0 ? 'notice-warning' : 'notice-success';
					echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($summary) . '</p>';
					if ($messages !== []) {
						echo '<ul style="margin-left:1.5em;list-style:disc;">';
						foreach (array_slice($messages, 0, 20) as $msg) {
							echo '<li>' . esc_html($msg) . '</li>';
						}
						echo '</ul>';
					}
					echo '</div>';
				}
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Bulk action could not be verified (security check failed). Please reload the Program Manager page and try again.', 'intersoccer-product-variations') . '</p></div>';
			}
		}

		$table = new InterSoccer_Program_List_Table();
		$table->prepare_items();
		$create_url = add_query_arg(['post_type' => 'product', 'page' => self::PAGE_SLUG, 'action' => 'create'], admin_url('edit.php'));
		$show_issues = isset($_GET['show_issues_only']) && $_GET['show_issues_only'] === '1';
		$pm_status   = isset($_GET['pm_status']) ? sanitize_key(wp_unslash($_GET['pm_status'])) : 'publish';
		if (!in_array($pm_status, ['publish', 'draft', 'private', 'all'], true)) {
			$pm_status = 'publish';
		}
		$include_drafts = in_array($pm_status, ['all', 'draft'], true);
		$drafts_only    = ($pm_status === 'draft');
		$private_only   = ($pm_status === 'private');
		$filter_action  = menu_page_url(self::PAGE_SLUG, false);
		$base_args      = [
			'post_type' => 'product',
			'page'      => self::PAGE_SLUG,
		];
		if ($show_issues) {
			$base_args['show_issues_only'] = '1';
		}
		if (!empty($_REQUEST['s'])) {
			$base_args['s'] = sanitize_text_field(wp_unslash($_REQUEST['s']));
		}
		$view_urls = [
			'all'     => add_query_arg(array_merge($base_args, ['pm_status' => 'all']), admin_url('edit.php')),
			'publish' => add_query_arg(array_merge($base_args, ['pm_status' => 'publish']), admin_url('edit.php')),
			'draft'   => add_query_arg(array_merge($base_args, ['pm_status' => 'draft']), admin_url('edit.php')),
			'private' => add_query_arg(array_merge($base_args, ['pm_status' => 'private']), admin_url('edit.php')),
		];
		if ($drafts_only) {
			$filter_pm_status = 'draft';
		} elseif ($private_only) {
			$filter_pm_status = 'private';
		} elseif ($include_drafts) {
			$filter_pm_status = 'all';
		} else {
			$filter_pm_status = 'publish';
		}

		$year_terms   = get_terms(['taxonomy' => 'pa_program-year', 'hide_empty' => false]);
		$season_terms = get_terms(['taxonomy' => 'pa_program-season', 'hide_empty' => false]);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Program Manager', 'intersoccer-product-variations'); ?></h1>
			<a href="<?php echo esc_url($create_url); ?>" class="page-title-action"><?php esc_html_e('Create New Program', 'intersoccer-product-variations'); ?></a>
			<hr class="wp-header-end">
			<p class="description"><?php esc_html_e('Manage InterSoccer programs. Green = complete, yellow = partially complete, red = missing required attributes.', 'intersoccer-product-variations'); ?></p>

			<ul class="subsubsub" style="margin: 8px 0 12px;">
				<li class="all"><a href="<?php echo esc_url($view_urls['all']); ?>" class="<?php echo $pm_status === 'all' ? 'current' : ''; ?>"><?php esc_html_e('All', 'intersoccer-product-variations'); ?></a> |</li>
				<li class="publish"><a href="<?php echo esc_url($view_urls['publish']); ?>" class="<?php echo $pm_status === 'publish' ? 'current' : ''; ?>"><?php esc_html_e('Published', 'intersoccer-product-variations'); ?></a> |</li>
				<li class="draft"><a href="<?php echo esc_url($view_urls['draft']); ?>" class="<?php echo $pm_status === 'draft' ? 'current' : ''; ?>"><?php esc_html_e('Drafts', 'intersoccer-product-variations'); ?></a> |</li>
				<li class="private"><a href="<?php echo esc_url($view_urls['private']); ?>" class="<?php echo $pm_status === 'private' ? 'current' : ''; ?>"><?php esc_html_e('Private', 'intersoccer-product-variations'); ?></a></li>
			</ul>

			<form method="get" action="<?php echo esc_url($filter_action); ?>" class="intersoccer-pm-list-filters" style="margin: 12px 0; clear: both;">
				<input type="hidden" name="post_type" value="product" />
				<input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
				<input type="hidden" name="pm_status" value="<?php echo esc_attr($filter_pm_status); ?>" class="intersoccer-pm-status-field" />
				<?php if (!empty($_REQUEST['s'])) : ?>
					<input type="hidden" name="s" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_REQUEST['s']))); ?>" />
				<?php endif; ?>
				<label style="margin-right: 16px;">
					<input type="checkbox"
						name="include_drafts"
						value="1"
						class="intersoccer-pm-include-drafts"
						<?php checked($include_drafts && !$private_only); ?>
						<?php disabled($drafts_only || $private_only); ?>
						onchange="var f=this.form,h=f.querySelector('.intersoccer-pm-status-field'); if(this.disabled){return;} h.value=this.checked?'all':'publish'; f.submit();" />
					<?php esc_html_e('Include drafts', 'intersoccer-product-variations'); ?>
				</label>
				<label>
					<input type="checkbox" name="show_issues_only" value="1" <?php checked($show_issues); ?> onchange="this.form.submit();" />
					<?php esc_html_e('Show only programs with issues', 'intersoccer-product-variations'); ?>
				</label>
				<?php if ($drafts_only) : ?>
					<span class="description" style="margin-left:8px;"><?php esc_html_e('Drafts-only view — use Published or All to change.', 'intersoccer-product-variations'); ?></span>
				<?php elseif ($private_only) : ?>
					<span class="description" style="margin-left:8px;"><?php esc_html_e('Private (retired) catalogue — sold years kept for order history.', 'intersoccer-product-variations'); ?></span>
				<?php endif; ?>
			</form>

			<form method="get" action="<?php echo esc_url($filter_action); ?>" class="search-box" style="margin: 0 0 12px;">
				<input type="hidden" name="post_type" value="product" />
				<input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
				<input type="hidden" name="pm_status" value="<?php echo esc_attr($pm_status); ?>" />
				<?php if ($show_issues) : ?>
					<input type="hidden" name="show_issues_only" value="1" />
				<?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr(isset($_REQUEST['s']) ? $_REQUEST['s'] : ''); ?>" placeholder="<?php esc_attr_e('Search by name…', 'intersoccer-product-variations'); ?>" />
				<input type="submit" class="button" value="<?php esc_attr_e('Search Programs', 'intersoccer-product-variations'); ?>" />
				<?php if (!empty($_REQUEST['s'])) : ?>
					<a href="<?php echo esc_url(add_query_arg(array_merge(['post_type' => 'product', 'page' => self::PAGE_SLUG, 'pm_status' => $pm_status], $show_issues ? ['show_issues_only' => '1'] : []), admin_url('edit.php'))); ?>" class="button"><?php esc_html_e('Clear', 'intersoccer-product-variations'); ?></a>
				<?php endif; ?>
			</form>

			<form method="post" action="<?php echo esc_url($filter_action); ?>" id="intersoccer-pm-bulk-form">
				<input type="hidden" name="post_type" value="product" />
				<input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
				<?php wp_nonce_field('intersoccer_pm_bulk_nonce', 'intersoccer_pm_bulk_nonce'); ?>
				<?php if (!empty($_REQUEST['s'])) : ?>
					<input type="hidden" name="s" value="<?php echo esc_attr($_REQUEST['s']); ?>" />
				<?php endif; ?>
				<input type="hidden" name="pm_status" value="<?php echo esc_attr($pm_status); ?>" />
				<?php if ($show_issues) : ?>
					<input type="hidden" name="show_issues_only" value="1" />
				<?php endif; ?>

				<?php /* Relocated beside Bulk Actions via JS when “Duplicate to year…” is selected. */ ?>
				<div id="intersoccer-pm-bulk-year-roll" class="intersoccer-pm-bulk-year-roll" hidden>
					<label for="pm_target_year" style="margin-right: 8px;">
						<?php esc_html_e('Year', 'intersoccer-product-variations'); ?>
						<select name="pm_target_year" id="pm_target_year">
							<option value=""><?php esc_html_e('— Select —', 'intersoccer-product-variations'); ?></option>
							<?php if (!is_wp_error($year_terms)) : foreach ($year_terms as $term) : ?>
								<option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></option>
							<?php endforeach; endif; ?>
						</select>
					</label>
					<label for="pm_target_year_custom" style="margin-right: 8px;">
						<input type="text" name="pm_target_year_custom" id="pm_target_year_custom" class="small-text" placeholder="<?php esc_attr_e('or 2027', 'intersoccer-product-variations'); ?>" pattern="20\d{2}" title="<?php esc_attr_e('New program year', 'intersoccer-product-variations'); ?>" />
					</label>
					<label for="pm_target_season" style="margin-right: 8px;">
						<?php esc_html_e('Season', 'intersoccer-product-variations'); ?>
						<select name="pm_target_season" id="pm_target_season">
							<option value=""><?php esc_html_e('— Keep source —', 'intersoccer-product-variations'); ?></option>
							<?php if (!is_wp_error($season_terms)) : foreach ($season_terms as $term) : ?>
								<option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></option>
							<?php endforeach; endif; ?>
						</select>
					</label>
				</div>

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
		$post_status   = $product->get_status();
		$status_labels = [
			'draft'   => __('Draft', 'intersoccer-product-variations'),
			'publish' => __('Published', 'intersoccer-product-variations'),
			'private' => __('Private', 'intersoccer-product-variations'),
		];
		$status_badge  = $status_labels[$post_status] ?? ucfirst((string) $post_status);

		?>
		<div class="wrap intersoccer-pm-detail">
			<h1>
				<?php echo esc_html($product->get_name()); ?>
				<span class="intersoccer-pm-type-badge"><?php echo esc_html(ucfirst($type ?: 'unknown')); ?></span>
				<span class="intersoccer-pm-post-status-badge post-state" id="intersoccer-pm-status-badge"><?php echo esc_html($status_badge); ?></span>
			</h1>
			<p>
				<a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('Back to Program List', 'intersoccer-product-variations'); ?></a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit in WooCommerce', 'intersoccer-product-variations'); ?></a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url($duplicate_url); ?>"><?php esc_html_e('Duplicate Program', 'intersoccer-product-variations'); ?></a>
			</p>
			<?php if (function_exists('intersoccer_pm_wpml_available') && intersoccer_pm_wpml_available()) : ?>
			<p class="intersoccer-pm-wpml-sync-row" style="margin:8px 0 16px;">
				<button type="button" class="button button-secondary" id="intersoccer-pm-sync-wpml-btn" data-product-id="<?php echo esc_attr((string) $product_id); ?>">
					<?php esc_html_e('Sync all languages (WPML)', 'intersoccer-product-variations'); ?>
				</button>
				<span id="intersoccer-pm-sync-wpml-status" style="margin-left:8px;"></span>
				<br />
				<span class="description"><?php esc_html_e('Creates or refreshes FR/DE translations from the default language (EN), copying shared attribute slugs, camp schedule, prices, and course meta. Edit the catalogue in English first.', 'intersoccer-product-variations'); ?></span>
			</p>
			<?php endif; ?>
			<p class="intersoccer-pm-detail-status-row" style="margin:12px 0 20px;">
				<label for="intersoccer-pm-detail-status" style="font-weight:600;margin-right:8px;">
					<?php esc_html_e('Status', 'intersoccer-product-variations'); ?>
				</label>
				<select id="intersoccer-pm-detail-status" style="min-width:140px;">
					<option value="draft" <?php selected($post_status, 'draft'); ?>><?php esc_html_e('Draft', 'intersoccer-product-variations'); ?></option>
					<option value="publish" <?php selected($post_status, 'publish'); ?>><?php esc_html_e('Publish', 'intersoccer-product-variations'); ?></option>
					<option value="private" <?php selected($post_status, 'private'); ?>><?php esc_html_e('Private', 'intersoccer-product-variations'); ?></option>
				</select>
				<button type="button" class="button button-primary" id="intersoccer-pm-save-status-btn" data-product-id="<?php echo esc_attr((string) $product_id); ?>">
					<?php esc_html_e('Update status', 'intersoccer-product-variations'); ?>
				</button>
				<span id="intersoccer-pm-status-save-msg" style="margin-left:8px;"></span>
			</p>

			<h2><?php esc_html_e('Parent Attributes', 'intersoccer-product-variations'); ?></h2>
			<?php
			$multi_select_slugs = ['days-of-week', 'camp-terms', 'camp-times', 'course-day', 'course-times', 'intersoccer-venues', 'age-group'];
			$required_parent = intersoccer_attr_required($type, 'parent');
			if ($type === 'birthday') {
				$required_parent = array_values(array_filter(
					$required_parent,
					static function ($taxonomy) {
						return !in_array($taxonomy, [
							'pa_intersoccer-venues',
							'pa_program-season',
							'pa_program-year',
						], true);
					}
				));
			}
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
			<?php if ($type === 'course') : ?>
			<p class="description" style="max-width:700px;">
				<?php esc_html_e('Select Course Day(s) here, then Save Attributes. Assign Course Times on each variation below (times differ per SKU). Use Auto-generate Variations to create missing day × age × venue SKUs.', 'intersoccer-product-variations'); ?>
			</p>
			<?php endif; ?>
			<p style="margin-top: 12px;">
				<button type="button" class="button button-primary" id="intersoccer-pm-save-attrs-btn" data-product-id="<?php echo esc_attr($product_id); ?>">
					<?php esc_html_e('Save Attributes', 'intersoccer-product-variations'); ?>
				</button>
				<span id="intersoccer-pm-attrs-save-status" style="margin-left: 8px;"></span>
			</p>

			<h2><?php esc_html_e('Variations', 'intersoccer-product-variations'); ?>
				<span class="intersoccer-pm-variation-count">(<?php echo esc_html($completeness['variations_healthy'] . '/' . $completeness['variations_total'] . ' ' . __('healthy', 'intersoccer-product-variations')); ?>)</span>
			</h2>

			<?php
			$parent_venue_slugs = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'slugs']);
			if (is_wp_error($parent_venue_slugs)) {
				$parent_venue_slugs = [];
			}
			$parent_venue_slugs = array_values(array_filter(array_map('strval', (array) $parent_venue_slugs)));
			$venue_missing_count = 0;
			foreach ($completeness['variations_issues'] as $issue_list) {
				if (in_array('pa_intersoccer-venues', (array) $issue_list, true)) {
					$venue_missing_count++;
				}
			}
			if (in_array($type, ['camp', 'course'], true) && $parent_venue_slugs !== [] && $venue_missing_count > 0) :
				?>
			<div class="notice notice-warning inline" style="margin: 12px 0; padding: 8px 12px;">
				<p style="margin:0;">
					<?php
					printf(
						/* translators: 1: variations missing venue, 2: parent venue count */
						esc_html__('Parent has %2$d venue(s), but %1$d variation(s) still need a venue assigned below. Parent venue terms alone do not fill variation SKUs on multi-venue programs.', 'intersoccer-product-variations'),
						(int) $venue_missing_count,
						(int) count($parent_venue_slugs)
					);
					?>
				</p>
			</div>
			<?php endif; ?>

			<?php if ($type && ($completeness['variations_total'] === 0 || $type === 'course')) : ?>
				<p>
					<button type="button" class="button button-primary" id="intersoccer-pm-scaffold-btn" data-product-id="<?php echo esc_attr($product_id); ?>" data-product-type="<?php echo esc_attr($type); ?>">
						<?php esc_html_e('Auto-generate Variations', 'intersoccer-product-variations'); ?>
					</button>
				</p>
			<?php endif; ?>

			<?php if ($type === 'camp') : ?>
			<div class="intersoccer-pm-camp-schedule-tools" style="margin: 12px 0; padding: 12px; background: #f6f7f7; border: 1px solid #ddd; border-radius: 4px; max-width: 900px;">
				<h3 style="margin-top:0;"><?php esc_html_e('Camp Schedule', 'intersoccer-product-variations'); ?></h3>
				<p class="description"><?php esc_html_e('Store start/end dates and week index on each variation. Labels in Camp Terms stay for storefront display.', 'intersoccer-product-variations'); ?></p>
				<p>
					<label>
						<?php esc_html_e('Week 1 start', 'intersoccer-product-variations'); ?>
						<input type="date" id="intersoccer-pm-week1-start" />
					</label>
					&nbsp;
					<label>
						<?php esc_html_e('Duration (days)', 'intersoccer-product-variations'); ?>
						<input type="number" id="intersoccer-pm-week-duration" min="1" max="7" value="5" style="width:60px;" />
					</label>
					&nbsp;
					<button type="button" class="button" id="intersoccer-pm-propose-weeks-btn" data-product-id="<?php echo esc_attr($product_id); ?>">
						<?php esc_html_e('Propose remaining weeks', 'intersoccer-product-variations'); ?>
					</button>
					<button type="button" class="button" id="intersoccer-pm-apply-parsed-dates-btn" data-product-id="<?php echo esc_attr($product_id); ?>">
						<?php esc_html_e('Apply parsed dates from camp-terms', 'intersoccer-product-variations'); ?>
					</button>
					<button type="button" class="button" id="intersoccer-pm-propose-times-btn" data-product-id="<?php echo esc_attr($product_id); ?>">
						<?php esc_html_e('Propose times from age', 'intersoccer-product-variations'); ?>
					</button>
					<button type="button" class="button button-primary" id="intersoccer-pm-repair-facets-btn" data-product-id="<?php echo esc_attr($product_id); ?>">
						<?php esc_html_e('Repair Venue / Camp Term on variations', 'intersoccer-product-variations'); ?>
					</button>
				</p>
				<p class="description"><?php esc_html_e('Propose fills empty rows only (+7 days per week index). Apply parsed uses the deprecated camp-terms parser once to seed meta. Propose times fills empty pa_camp-times from age (Half Day → 1000-1230, Full Day → 1000-1700). Repair promotes Venue and Camp Term to variation attributes and backfills Camp Term from week meta (single-venue products also get Venue).', 'intersoccer-product-variations'); ?></p>
				<span id="intersoccer-pm-schedule-tools-status"></span>
			</div>
			<?php endif; ?>

			<table class="widefat striped intersoccer-pm-variations-table">
				<thead>
					<tr>
						<th><?php esc_html_e('ID', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Attributes', 'intersoccer-product-variations'); ?></th>
						<?php if (in_array($type, ['camp', 'course'], true)) : ?>
							<th><?php esc_html_e('Venue', 'intersoccer-product-variations'); ?></th>
						<?php endif; ?>
						<?php if ($type === 'course') : ?>
							<th><?php esc_html_e('Course Time', 'intersoccer-product-variations'); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e('Price (CHF)', 'intersoccer-product-variations'); ?></th>
						<?php if ($type === 'camp') : ?>
							<th><?php esc_html_e('Week', 'intersoccer-product-variations'); ?></th>
							<th><?php esc_html_e('Start', 'intersoccer-product-variations'); ?></th>
							<th><?php esc_html_e('End', 'intersoccer-product-variations'); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e('Status', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Issues', 'intersoccer-product-variations'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$children = $product->get_children();
					$parent_venue_terms = [];
					if (in_array($type, ['camp', 'course'], true) && $parent_venue_slugs !== []) {
						foreach ($parent_venue_slugs as $vslug) {
							$vterm = get_term_by('slug', $vslug, 'pa_intersoccer-venues');
							if ($vterm && !is_wp_error($vterm)) {
								$parent_venue_terms[] = $vterm;
							}
						}
					}
					$course_time_terms = [];
					if ($type === 'course' && taxonomy_exists('pa_course-times')) {
						$ct_terms = get_terms(['taxonomy' => 'pa_course-times', 'hide_empty' => false]);
						if (!is_wp_error($ct_terms)) {
							$course_time_terms = $ct_terms;
						}
					}
					foreach ($children as $var_id) :
						$variation    = wc_get_product($var_id);
						if (!$variation) continue;
						$var_result   = self::get_variation_completeness($var_id, $type);
						$var_attrs    = $variation->get_attributes();
						$attr_display = [];
						foreach ($var_attrs as $tax => $val) {
							if ($tax === 'pa_intersoccer-venues' || ($type === 'course' && $tax === 'pa_course-times')) {
								continue;
							}
							$slug  = str_replace('pa_', '', $tax);
							$label = intersoccer_attr_wc_label($slug) ?: $slug;
							if ($val) {
								$term = get_term_by('slug', $val, $tax);
								$attr_display[] = $label . ': ' . ($term ? $term->name : $val);
							}
						}
						$price = $variation->get_regular_price();
						$sched = ($type === 'camp' && function_exists('intersoccer_get_camp_schedule_meta'))
							? intersoccer_get_camp_schedule_meta($var_id)
							: ['start' => '', 'end' => '', 'week' => null];
						$current_venue = isset($var_attrs['pa_intersoccer-venues']) ? (string) $var_attrs['pa_intersoccer-venues'] : '';
						if ($current_venue === '') {
							$current_venue = (string) get_post_meta($var_id, 'attribute_pa_intersoccer-venues', true);
						}
						$current_course_time = isset($var_attrs['pa_course-times']) ? (string) $var_attrs['pa_course-times'] : '';
						if ($current_course_time === '') {
							$current_course_time = (string) get_post_meta($var_id, 'attribute_pa_course-times', true);
						}
						$issue_labels = array_map([__CLASS__, 'format_missing_key_label'], $var_result['missing']);
					?>
					<tr data-variation-id="<?php echo esc_attr($var_id); ?>">
						<td><?php echo esc_html($var_id); ?></td>
						<td><?php echo esc_html(implode(' | ', $attr_display) ?: '—'); ?></td>
						<?php if (in_array($type, ['camp', 'course'], true)) : ?>
							<td>
								<select class="intersoccer-pm-venue-select" data-variation-id="<?php echo esc_attr($var_id); ?>" style="min-width: 180px; max-width: 260px;">
									<option value=""><?php esc_html_e('— Select venue —', 'intersoccer-product-variations'); ?></option>
									<?php foreach ($parent_venue_terms as $vterm) : ?>
										<option value="<?php echo esc_attr($vterm->slug); ?>" <?php selected($current_venue, $vterm->slug); ?>>
											<?php echo esc_html($vterm->name); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<span class="intersoccer-pm-venue-status"></span>
							</td>
						<?php endif; ?>
						<?php if ($type === 'course') : ?>
							<td>
								<select class="intersoccer-pm-course-time-select" data-variation-id="<?php echo esc_attr($var_id); ?>" style="min-width: 140px; max-width: 220px;">
									<option value=""><?php esc_html_e('— Select time —', 'intersoccer-product-variations'); ?></option>
									<?php foreach ($course_time_terms as $tterm) : ?>
										<option value="<?php echo esc_attr($tterm->slug); ?>" <?php selected($current_course_time, $tterm->slug); ?>>
											<?php echo esc_html($tterm->name); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<span class="intersoccer-pm-course-time-status"></span>
							</td>
						<?php endif; ?>
						<td>
							<input type="number" step="0.01" min="0" class="intersoccer-pm-price-input" data-variation-id="<?php echo esc_attr($var_id); ?>" value="<?php echo esc_attr($price); ?>" style="width: 100px;" />
							<span class="intersoccer-pm-price-status"></span>
						</td>
						<?php if ($type === 'camp') : ?>
							<td>
								<input type="number" min="1" max="52" class="intersoccer-pm-camp-week" data-variation-id="<?php echo esc_attr($var_id); ?>" value="<?php echo esc_attr($sched['week'] !== null ? $sched['week'] : ''); ?>" style="width: 60px;" />
							</td>
							<td>
								<input type="date" class="intersoccer-pm-camp-start" data-variation-id="<?php echo esc_attr($var_id); ?>" value="<?php echo esc_attr($sched['start']); ?>" />
							</td>
							<td>
								<input type="date" class="intersoccer-pm-camp-end" data-variation-id="<?php echo esc_attr($var_id); ?>" value="<?php echo esc_attr($sched['end']); ?>" />
								<span class="intersoccer-pm-schedule-status"></span>
							</td>
						<?php endif; ?>
						<td>
							<?php if ($var_result['is_healthy']) : ?>
								<span style="color:green;">&#10003; <?php esc_html_e('Healthy', 'intersoccer-product-variations'); ?></span>
							<?php else : ?>
								<span style="color:red;">&#10007; <?php esc_html_e('Issues', 'intersoccer-product-variations'); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html(implode(', ', $issue_labels)); ?></td>
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
			.intersoccer-pm-post-status-badge { font-size: 13px; font-weight: 600; margin-left: 10px; color: #646970; vertical-align: middle; }
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
								$is_multi = in_array($slug, ['days-of-week', 'camp-terms', 'camp-times', 'course-day', 'course-times', 'intersoccer-venues', 'age-group'], true);
						?>
						<tr class="intersoccer-pm-attr-row" data-types="<?php echo esc_attr($t); ?>" style="display:none;">
							<th><label><?php echo esc_html($label); ?> *</label></th>
							<td>
								<select name="parent_attrs[<?php echo esc_attr($t); ?>][<?php echo esc_attr($taxonomy); ?>][]" class="intersoccer-pm-attr-select" data-taxonomy="<?php echo esc_attr($taxonomy); ?>" data-program-type="<?php echo esc_attr($t); ?>" <?php echo $is_multi ? 'multiple size="5"' : ''; ?>>
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
						<tr class="intersoccer-pm-attr-row intersoccer-pm-course-meta-row" data-types="course" style="display:none;">
							<th><label for="pm-course-start-date"><?php esc_html_e('Course start date', 'intersoccer-product-variations'); ?></label></th>
							<td><input type="date" id="pm-course-start-date" /></td>
						</tr>
						<tr class="intersoccer-pm-attr-row intersoccer-pm-course-meta-row" data-types="course" style="display:none;">
							<th><label for="pm-course-total-weeks"><?php esc_html_e('Total weeks / sessions', 'intersoccer-product-variations'); ?></label></th>
							<td><input type="number" id="pm-course-total-weeks" min="1" max="52" value="16" style="width:80px;" /></td>
						</tr>
						<tr class="intersoccer-pm-attr-row intersoccer-pm-course-meta-row" data-types="course" style="display:none;">
							<th><label for="pm-course-holiday-dates"><?php esc_html_e('Holiday dates', 'intersoccer-product-variations'); ?></label></th>
							<td>
								<textarea id="pm-course-holiday-dates" rows="3" class="large-text" placeholder="<?php esc_attr_e('One Y-m-d per line, e.g. 2026-12-25', 'intersoccer-product-variations'); ?>"></textarea>
								<p class="description"><?php esc_html_e('Applied to each variation as _course_holiday_dates before generation.', 'intersoccer-product-variations'); ?></p>
							</td>
						</tr>
						<tr class="intersoccer-pm-attr-row" data-types="camp,course,birthday,tournament" style="display:none;">
							<th><label for="pm-regular-price"><?php esc_html_e('Regular price (CHF)', 'intersoccer-product-variations'); ?></label></th>
							<td>
								<input type="number" id="pm-regular-price" min="0" step="0.01" style="width:120px;" />
								<p class="description"><?php esc_html_e('Optional. Applied to all generated variations.', 'intersoccer-product-variations'); ?></p>
							</td>
						</tr>
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
				camp: <?php echo wp_json_encode(self::build_camp_matrix_rows()); ?>,
				// Course matrix is rebuilt in JS from Step 2 multi-selects (days/ages/times/venues).
				course: [],
				birthday: <?php echo wp_json_encode(self::get_birthday_matrix_options($term_options)); ?>,
				tournament: <?php echo wp_json_encode(self::get_tournament_matrix_options($term_options)); ?>
			};
			var intersoccerPMCampDefaults = {
				ages: ['5-13y-full-day', '3-5y-half-day'],
				bookings: ['full-week', 'single-days'],
				times: ['1000-1700', '1000-1230']
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
	 * Build course variation matrix from selected day / age / time / venue slugs.
	 *
	 * @param string[] $day_slugs
	 * @param string[] $age_slugs
	 * @param string[] $time_slugs
	 * @param string[] $venue_slugs
	 * @return array<int,array<string,string>>
	 */
	public static function build_course_matrix_rows($day_slugs = [], $age_slugs = [], $time_slugs = [], $venue_slugs = []) {
		if (!function_exists('intersoccer_pm_build_course_matrix_rows')) {
			$helpers = INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . 'includes/helpers.php';
			if (is_readable($helpers)) {
				require_once $helpers;
			}
		}
		return function_exists('intersoccer_pm_build_course_matrix_rows')
			? intersoccer_pm_build_course_matrix_rows($day_slugs, $age_slugs, $time_slugs, $venue_slugs)
			: [];
	}

	/**
	 * @param array<string,array<int,object>> $term_options
	 * @return array<int,array<string,string>>
	 */
	private static function get_course_matrix_options($term_options) {
		$day_slugs   = [];
		$age_slugs   = [];
		$venue_slugs = [];
		foreach ($term_options['pa_course-day'] ?? [] as $term) {
			if (is_object($term) && isset($term->slug)) {
				$day_slugs[] = (string) $term->slug;
			}
		}
		foreach ($term_options['pa_age-group'] ?? [] as $term) {
			if (is_object($term) && isset($term->slug)) {
				$age_slugs[] = (string) $term->slug;
			}
		}
		foreach ($term_options['pa_intersoccer-venues'] ?? [] as $term) {
			if (is_object($term) && isset($term->slug)) {
				$venue_slugs[] = (string) $term->slug;
			}
		}
		// Omit course-times: assigned per variation, not at program create.
		return self::build_course_matrix_rows($day_slugs, $age_slugs, [], $venue_slugs);
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
		$years      = get_terms(['taxonomy' => 'pa_program-year', 'hide_empty' => false]);
		$source_name = $source->get_name();
		?>
		<div class="wrap intersoccer-pm-duplicate">
			<h1><?php esc_html_e('Duplicate Program', 'intersoccer-product-variations'); ?></h1>
			<p><a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('Back to Program List', 'intersoccer-product-variations'); ?></a></p>
			<p class="description"><?php esc_html_e('Creates a Draft clone. Set program year for catalogue roll-over; seasons stay evergreen (Autumn/Winter/Spring/Summer). Update dates, terms, and prices after duplicating, then Sync WPML.', 'intersoccer-product-variations'); ?></p>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e('Source Program', 'intersoccer-product-variations'); ?></th>
					<td><strong><?php echo esc_html($source_name); ?></strong> (<?php echo esc_html(ucfirst($type)); ?>)</td>
				</tr>
				<tr>
					<th><label for="pm-dup-name"><?php esc_html_e('New Program Name', 'intersoccer-product-variations'); ?></label></th>
					<td><input type="text" id="pm-dup-name" class="regular-text" value="<?php echo esc_attr($source_name . ' (Copy)'); ?>" data-source-name="<?php echo esc_attr($source_name); ?>" /></td>
				</tr>
				<tr>
					<th><label for="pm-dup-year"><?php esc_html_e('Program Year', 'intersoccer-product-variations'); ?></label></th>
					<td>
						<select id="pm-dup-year">
							<option value=""><?php esc_html_e('— Keep same year —', 'intersoccer-product-variations'); ?></option>
							<?php if (!is_wp_error($years)) : foreach ($years as $term) : ?>
								<option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></option>
							<?php endforeach; endif; ?>
						</select>
						<input type="text" id="pm-dup-year-custom" class="small-text" style="margin-left:8px;" placeholder="<?php esc_attr_e('or type e.g. 2027', 'intersoccer-product-variations'); ?>" />
						<p class="description"><?php esc_html_e('Required attribute for camps/courses. Titles should carry the year; do not create year-qualified season terms.', 'intersoccer-product-variations'); ?></p>
					</td>
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

		$parent_attrs_raw = [];
		if (!empty($_POST['parent_attrs_json'])) {
			$decoded = json_decode(wp_unslash((string) $_POST['parent_attrs_json']), true);
			if (is_array($decoded)) {
				$parent_attrs_raw = $decoded;
			}
		} elseif (isset($_POST['parent_attrs']) && is_array($_POST['parent_attrs'])) {
			$parent_attrs_raw = $_POST['parent_attrs'];
		}
		$wc_attributes    = [];

		$matrix = [];
		if (!empty($_POST['matrix_json'])) {
			$decoded_matrix = json_decode(wp_unslash((string) $_POST['matrix_json']), true);
			if (is_array($decoded_matrix)) {
				$matrix = $decoded_matrix;
			}
		} elseif (isset($_POST['matrix']) && is_array($_POST['matrix'])) {
			$matrix = $_POST['matrix'];
		}

		$course_meta = [];
		if (!empty($_POST['course_meta_json'])) {
			$decoded_meta = json_decode(wp_unslash((string) $_POST['course_meta_json']), true);
			if (is_array($decoded_meta)) {
				$course_meta = $decoded_meta;
			}
		}
		$regular_price = isset($_POST['regular_price']) ? wc_format_decimal(wp_unslash((string) $_POST['regular_price'])) : '';


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
		// Collect variation option slugs from matrix so we never dump the full taxonomy.
		$matrix_slugs_by_tax = [];
		foreach ($matrix as $row) {
			if (!is_array($row)) {
				continue;
			}
			foreach ($row as $tax => $slug) {
				if ($tax === 'label' || $slug === '' || $slug === null) {
					continue;
				}
				$tax = (strpos((string) $tax, 'pa_') === 0) ? (string) $tax : 'pa_' . ltrim((string) $tax, '_');
				if (!isset($matrix_slugs_by_tax[$tax])) {
					$matrix_slugs_by_tax[$tax] = [];
				}
				$matrix_slugs_by_tax[$tax][] = sanitize_text_field((string) $slug);
			}
		}
		foreach ($variation_taxonomies as $taxonomy) {
			$already_set = false;
			foreach ($wc_attributes as $attr) {
				if ($attr->get_name() === $taxonomy) {
					$attr->set_variation(true);
					$already_set = true;
					break;
				}
			}
			if ($already_set || !taxonomy_exists($taxonomy)) {
				continue;
			}
			$slugs = array_values(array_unique($matrix_slugs_by_tax[$taxonomy] ?? []));
			if ($slugs === []) {
				continue;
			}
			$term_ids = [];
			foreach ($slugs as $slug) {
				$term = get_term_by('slug', $slug, $taxonomy);
				if ($term && !is_wp_error($term)) {
					$term_ids[] = (int) $term->term_id;
				}
			}
			if ($term_ids === []) {
				continue;
			}
			$attribute = new WC_Product_Attribute();
			$attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
			$attribute->set_name($taxonomy);
			$attribute->set_options($term_ids);
			$attribute->set_visible(true);
			$attribute->set_variation(true);
			$wc_attributes[] = $attribute;
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
		$times_from_matrix = [];
		$course_days_from_matrix = [];
		$course_times_from_matrix = [];
		$venues_from_matrix = [];
		foreach ($matrix as $row) {
			if (!is_array($row)) {
				continue;
			}
			if (!empty($row['pa_camp-times'])) {
				$times_from_matrix[] = sanitize_text_field((string) $row['pa_camp-times']);
			}
			if (!empty($row['pa_course-day'])) {
				$course_days_from_matrix[] = sanitize_text_field((string) $row['pa_course-day']);
			}
			if (!empty($row['pa_course-times'])) {
				$course_times_from_matrix[] = sanitize_text_field((string) $row['pa_course-times']);
			}
			if (!empty($row['pa_intersoccer-venues'])) {
				$venues_from_matrix[] = sanitize_text_field((string) $row['pa_intersoccer-venues']);
			}
			$var_id = self::create_single_variation($product_id, $type, $row, $course_meta, $regular_price);
			if ($var_id) {
				$variations_created++;
			}
		}

		if ($type === 'camp' && !empty($times_from_matrix)) {
			self::ensure_parent_camp_times_variation_attribute($product_id, $times_from_matrix);
		}
		if ($type === 'course') {
			self::ensure_parent_course_variation_attributes(
				$product_id,
				$course_days_from_matrix,
				$course_times_from_matrix,
				$venues_from_matrix
			);
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

		$matrix = self::get_default_matrix($type, $product_id);
		$created = 0;
		$skipped = 0;
		$times_from_matrix = [];
		$course_days_from_matrix = [];
		$course_times_from_matrix = [];
		$venues_from_matrix = [];

		$existing_keys = [];
		foreach ($product->get_children() as $child_id) {
			$child = wc_get_product($child_id);
			if (!$child) {
				continue;
			}
			$existing_keys[self::variation_attr_signature($child->get_attributes())] = true;
		}

		foreach ($matrix as $row) {
			$sig = self::variation_attr_signature($row);
			if (isset($existing_keys[$sig])) {
				$skipped++;
				continue;
			}
			$var_id = self::create_single_variation($product_id, $type, $row);
			if ($var_id) {
				$created++;
				$existing_keys[$sig] = true;
				if (!empty($row['pa_camp-times'])) {
					$times_from_matrix[] = (string) $row['pa_camp-times'];
				}
				if (!empty($row['pa_course-day'])) {
					$course_days_from_matrix[] = (string) $row['pa_course-day'];
				}
				if (!empty($row['pa_course-times'])) {
					$course_times_from_matrix[] = (string) $row['pa_course-times'];
				}
				if (!empty($row['pa_intersoccer-venues'])) {
					$venues_from_matrix[] = (string) $row['pa_intersoccer-venues'];
				}
			}
		}

		if ($type === 'camp') {
			self::ensure_parent_camp_times_variation_attribute($product_id, $times_from_matrix);
		}
		if ($type === 'course') {
			self::ensure_parent_course_variation_attributes(
				$product_id,
				$course_days_from_matrix,
				$course_times_from_matrix,
				$venues_from_matrix
			);
		}

		wc_delete_product_transients($product_id);

		wp_send_json_success([
			'created' => $created,
			'skipped' => $skipped,
			'message' => sprintf(
				/* translators: 1: created count, 2: skipped count */
				__('%1$d variations created, %2$d already present.', 'intersoccer-product-variations'),
				$created,
				$skipped
			),
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

		if (function_exists('intersoccer_sync_variation_prices_to_translations')) {
			intersoccer_sync_variation_prices_to_translations($variation_id, $price, $price);
		}

		wp_send_json_success(['variation_id' => $variation_id, 'price' => $price]);
	}

	public static function ajax_save_variation_venue() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
		$venue_slug   = isset($_POST['venue']) ? sanitize_text_field(wp_unslash($_POST['venue'])) : '';

		if (!$variation_id) {
			wp_send_json_error(['message' => __('Missing variation ID.', 'intersoccer-product-variations')]);
		}

		$variation = wc_get_product($variation_id);
		if (!$variation || !($variation instanceof WC_Product_Variation)) {
			wp_send_json_error(['message' => __('Invalid variation.', 'intersoccer-product-variations')]);
		}

		$parent_id = (int) $variation->get_parent_id();
		$type      = class_exists('InterSoccer_Product_Types')
			? InterSoccer_Product_Types::get_product_type($parent_id)
			: '';
		if (!in_array($type, ['camp', 'course'], true)) {
			wp_send_json_error(['message' => __('Venue assignment is only supported for camps and courses.', 'intersoccer-product-variations')]);
		}

		$allowed = wc_get_product_terms($parent_id, 'pa_intersoccer-venues', ['fields' => 'slugs']);
		if (is_wp_error($allowed)) {
			$allowed = [];
		}
		$allowed = array_map('strval', (array) $allowed);

		if ($venue_slug !== '' && !in_array($venue_slug, $allowed, true)) {
			wp_send_json_error(['message' => __('Venue is not assigned on the parent product.', 'intersoccer-product-variations')]);
		}

		self::ensure_parent_taxonomy_variation_attribute($parent_id, 'pa_intersoccer-venues', $allowed);

		$attrs = $variation->get_attributes();
		$attrs['pa_intersoccer-venues'] = $venue_slug;
		$variation->set_attributes($attrs);
		$variation->save();
		update_post_meta($variation_id, 'attribute_pa_intersoccer-venues', $venue_slug);
		if ($venue_slug !== '') {
			wp_set_object_terms($variation_id, $venue_slug, 'pa_intersoccer-venues');
		} else {
			wp_set_object_terms($variation_id, [], 'pa_intersoccer-venues');
		}

		if (function_exists('intersoccer_sync_variation_taxonomy_attribute_to_translations')) {
			intersoccer_sync_variation_taxonomy_attribute_to_translations($variation_id, 'pa_intersoccer-venues', $venue_slug);
		}

		wc_delete_product_transients($parent_id);

		wp_send_json_success([
			'variation_id' => $variation_id,
			'venue'        => $venue_slug,
			'completeness' => self::get_variation_completeness($variation_id, $type),
		]);
	}


	public static function ajax_save_variation_course_time() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
		$time_slug    = isset($_POST['course_time']) ? sanitize_text_field(wp_unslash($_POST['course_time'])) : '';

		if (!$variation_id) {
			wp_send_json_error(['message' => __('Missing variation ID.', 'intersoccer-product-variations')]);
		}

		$variation = wc_get_product($variation_id);
		if (!$variation || !($variation instanceof WC_Product_Variation)) {
			wp_send_json_error(['message' => __('Invalid variation.', 'intersoccer-product-variations')]);
		}

		$parent_id = (int) $variation->get_parent_id();
		$type      = class_exists('InterSoccer_Product_Types')
			? InterSoccer_Product_Types::get_product_type($parent_id)
			: '';
		if ($type !== 'course') {
			wp_send_json_error(['message' => __('Course time assignment is only supported for courses.', 'intersoccer-product-variations')]);
		}

		if ($time_slug !== '') {
			$term = get_term_by('slug', $time_slug, 'pa_course-times');
			if (!$term || is_wp_error($term)) {
				wp_send_json_error(['message' => __('Invalid course time.', 'intersoccer-product-variations')]);
			}
			self::ensure_parent_taxonomy_variation_attribute($parent_id, 'pa_course-times', [$time_slug]);
		}

		$attrs = $variation->get_attributes();
		$attrs['pa_course-times'] = $time_slug;
		$variation->set_attributes($attrs);
		$variation->save();
		update_post_meta($variation_id, 'attribute_pa_course-times', $time_slug);
		if ($time_slug !== '') {
			wp_set_object_terms($variation_id, $time_slug, 'pa_course-times');
		} else {
			wp_set_object_terms($variation_id, [], 'pa_course-times');
		}

		if (function_exists('intersoccer_sync_variation_taxonomy_attribute_to_translations')) {
			intersoccer_sync_variation_taxonomy_attribute_to_translations($variation_id, 'pa_course-times', $time_slug);
		}

		wc_delete_product_transients($parent_id);


		wp_send_json_success([
			'variation_id' => $variation_id,
			'course_time'  => $time_slug,
			'completeness' => self::get_variation_completeness($variation_id, $type),
		]);
	}

	public static function ajax_save_camp_schedule() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
		$start        = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : '';
		$end          = isset($_POST['end']) ? sanitize_text_field(wp_unslash($_POST['end'])) : '';
		$week         = isset($_POST['week']) ? absint($_POST['week']) : 0;

		if (!$variation_id || !function_exists('intersoccer_update_camp_schedule')) {
			wp_send_json_error(['message' => __('Invalid request.', 'intersoccer-product-variations')]);
		}

		$variation = wc_get_product($variation_id);
		if (!$variation || !($variation instanceof WC_Product_Variation)) {
			wp_send_json_error(['message' => __('Invalid variation.', 'intersoccer-product-variations')]);
		}

		intersoccer_update_camp_schedule($variation_id, $start, $end, $week > 0 ? $week : null, true);
		wc_delete_product_transients($variation->get_parent_id());

		wp_send_json_success([
			'variation_id' => $variation_id,
			'schedule'     => intersoccer_get_camp_schedule_meta($variation_id),
		]);
	}

	public static function ajax_prefill_camp_schedules() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$product_id     = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$week1_start    = isset($_POST['week1_start']) ? sanitize_text_field(wp_unslash($_POST['week1_start'])) : '';
		$duration_days  = isset($_POST['duration_days']) ? absint($_POST['duration_days']) : 5;
		$overwrite      = !empty($_POST['overwrite']);

		$product = wc_get_product($product_id);
		if (!$product || !$product->is_type('variable') || !function_exists('intersoccer_camp_schedule_propose_from_week1')) {
			wp_send_json_error(['message' => __('Invalid product.', 'intersoccer-product-variations')]);
		}

		$updated = 0;
		$rows    = [];
		foreach ($product->get_children() as $var_id) {
			$meta = intersoccer_get_camp_schedule_meta($var_id);
			$week = $meta['week'];
			if ($week === null && function_exists('intersoccer_get_camp_schedule')) {
				$resolved = intersoccer_get_camp_schedule($var_id, true);
				$week     = $resolved['week'];
			}
			if ($week === null || $week < 1) {
				continue;
			}

			$has_dates = ($meta['start'] !== '' || $meta['end'] !== '');
			if ($has_dates && !$overwrite) {
				continue;
			}

			$proposed = intersoccer_camp_schedule_propose_from_week1($week1_start, (int) $week, $duration_days);
			if ($proposed['start'] === '') {
				continue;
			}

			intersoccer_update_camp_schedule($var_id, $proposed['start'], $proposed['end'], (int) $week, true);
			$updated++;
			$rows[] = [
				'variation_id' => $var_id,
				'schedule'     => intersoccer_get_camp_schedule_meta($var_id),
			];
		}

		wc_delete_product_transients($product_id);
		wp_send_json_success(['updated' => $updated, 'rows' => $rows]);
	}

	public static function ajax_apply_parsed_camp_dates() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$force      = !empty($_POST['force']);

		if (!function_exists('intersoccer_migrate_camp_dates_for_product')) {
			require_once INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . 'includes/woocommerce/camp-schedule-migrate.php';
		}

		$result = intersoccer_migrate_camp_dates_for_product($product_id, [
			'force'   => $force,
			'dry_run' => false,
		]);

		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		wp_send_json_success($result);
	}

	/**
	 * Fill empty variation pa_camp-times from age slug (non-destructive by default).
	 */
	public static function ajax_propose_camp_times() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$overwrite  = !empty($_POST['overwrite']);

		$product = wc_get_product($product_id);
		if (!$product || !$product->is_type('variable')) {
			wp_send_json_error(['message' => __('Invalid product.', 'intersoccer-product-variations')]);
		}

		if (!function_exists('intersoccer_pm_default_camp_time_slug_for_age')) {
			require_once INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . 'includes/helpers.php';
		}

		$allowed_times = wc_get_product_terms($product_id, 'pa_camp-times', ['fields' => 'slugs']);
		if (is_wp_error($allowed_times)) {
			$allowed_times = [];
		}

		$updated = 0;
		$skipped = 0;
		$rows    = [];

		foreach ($product->get_children() as $var_id) {
			$variation = wc_get_product($var_id);
			if (!$variation || !($variation instanceof WC_Product_Variation)) {
				continue;
			}

			$attrs = $variation->get_attributes();
			$age   = isset($attrs['pa_age-group']) ? (string) $attrs['pa_age-group'] : '';
			$time  = isset($attrs['pa_camp-times']) ? (string) $attrs['pa_camp-times'] : '';

			if ($time !== '' && !$overwrite) {
				$skipped++;
				continue;
			}

			$proposed = intersoccer_pm_default_camp_time_slug_for_age($age, $allowed_times);
			if ($proposed === '') {
				$skipped++;
				continue;
			}

			if ($time === $proposed) {
				$skipped++;
				continue;
			}

			$attrs['pa_camp-times'] = $proposed;
			$variation->set_attributes($attrs);
			$variation->save();
			if (function_exists('intersoccer_sync_variation_taxonomy_attribute_to_translations')) {
				intersoccer_sync_variation_taxonomy_attribute_to_translations((int) $var_id, 'pa_camp-times', $proposed);
			}
			$updated++;
			$rows[] = [
				'variation_id' => $var_id,
				'pa_camp-times' => $proposed,
				'pa_age-group'  => $age,
			];
		}

		// Ensure parent attribute includes proposed times and is used for variations.
		self::ensure_parent_camp_times_variation_attribute($product_id, array_column($rows, 'pa_camp-times'));
		self::repair_camp_variation_facets($product_id);

		wc_delete_product_transients($product_id);
		wp_send_json_success([
			'updated' => $updated,
			'skipped' => $skipped,
			'rows'    => $rows,
			'message' => sprintf(
				/* translators: 1: updated count, 2: skipped count */
				__('Updated %1$d, skipped %2$d.', 'intersoccer-product-variations'),
				$updated,
				$skipped
			),
		]);
	}

	/**
	 * AJAX: promote Venue/Camp Term to variation attrs and backfill empty values.
	 */
	public static function ajax_repair_camp_facets() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$result = self::repair_camp_variation_facets($product_id);
		if (empty($result['promoted'])) {
			wp_send_json_error(['message' => __('Not a camp variable product.', 'intersoccer-product-variations')]);
		}

		wp_send_json_success([
			'result' => $result,
			'completeness' => self::get_product_completeness($product_id),
			'message' => sprintf(
				/* translators: 1: updated variations, 2: venue rows still needing manual venue */
				__('Camp facets repaired: %1$d variations updated. %2$d still need a venue (multi-venue products).', 'intersoccer-product-variations'),
				(int) $result['updated'],
				(int) $result['venue_needs_manual']
			),
		]);
	}

	/**
	 * AJAX: create/link FR/DE translations via WPML/WCML and fan out EN catalogue data.
	 */
	public static function ajax_sync_wpml_languages() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		if (!function_exists('intersoccer_pm_sync_product_translations')) {
			wp_send_json_error(['message' => __('WPML sync is not available.', 'intersoccer-product-variations')]);
		}

		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$result     = intersoccer_pm_sync_product_translations($product_id);

		if (empty($result['ok']) && in_array('invalid_product', $result['errors'] ?? [], true)) {
			wp_send_json_error(['message' => $result['message'] ?: __('Invalid product.', 'intersoccer-product-variations'), 'result' => $result]);
		}
		if (empty($result['ok']) && in_array('wpml_unavailable', $result['errors'] ?? [], true)) {
			wp_send_json_error(['message' => $result['message'] ?: __('WPML is not available.', 'intersoccer-product-variations'), 'result' => $result]);
		}
		if (empty($result['ok']) && in_array('not_variable', $result['errors'] ?? [], true)) {
			wp_send_json_error(['message' => $result['message'] ?: __('Invalid product.', 'intersoccer-product-variations'), 'result' => $result]);
		}

		$source_id = (int) ($result['source_product_id'] ?? $product_id);
		wp_send_json_success([
			'result'       => $result,
			'completeness' => self::get_product_completeness($source_id),
			'message'      => $result['message'] ?? __('WPML sync complete.', 'intersoccer-product-variations'),
		]);
	}

	/**
	 * AJAX: process one list-bulk item (progress UI chunk).
	 */
	public static function ajax_bulk_process_one() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		@set_time_limit(0);

		$action     = isset($_POST['bulk_action']) ? sanitize_key(wp_unslash($_POST['bulk_action'])) : '';
		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$opts       = [];

		if ($action === 'duplicate_to_year') {
			$target_year   = isset($_POST['pm_target_year']) ? sanitize_text_field(wp_unslash($_POST['pm_target_year'])) : '';
			$target_custom = isset($_POST['pm_target_year_custom']) ? sanitize_text_field(wp_unslash($_POST['pm_target_year_custom'])) : '';
			$target_season = isset($_POST['pm_target_season']) ? sanitize_text_field(wp_unslash($_POST['pm_target_season'])) : '';
			if (self::normalize_program_year($target_year) === '' && $target_custom !== '') {
				$target_year = $target_custom;
			}
			$target_year = self::normalize_program_year($target_year);
			if ($target_year === '') {
				wp_send_json_error([
					'message' => __('Bulk Duplicate requires a target program year (e.g. 2027).', 'intersoccer-product-variations'),
				]);
			}
			$year_term = self::ensure_program_year_term($target_year);
			if (is_wp_error($year_term)) {
				wp_send_json_error(['message' => $year_term->get_error_message()]);
			}
			$opts['year']   = $target_year;
			$opts['season'] = $target_season;
		}

		$result = self::process_bulk_item($action, $product_id, $opts);
		if (empty($result['ok']) && ($result['outcome'] ?? '') === 'failed' && empty($result['wpml']) && $action !== 'sync_wpml_languages') {
			wp_send_json_error($result);
		}

		wp_send_json_success($result);
	}

	/**
	 * Process a single Program Manager list bulk action against one product.
	 *
	 * @param string $action     refresh_attrs|scaffold_variations|duplicate_to_year|sync_wpml_languages
	 * @param int    $product_id Product ID.
	 * @param array  $opts       Optional. year/season for duplicate_to_year.
	 * @return array{ok:bool,outcome:string,product_id:int,product_name:string,message:string,new_product_id:?int,wpml:?array}
	 */
	public static function process_bulk_item($action, $product_id, $opts = []) {
		$product_id = absint($product_id);
		$action     = sanitize_key((string) $action);
		$product    = apply_filters('intersoccer_pm_bulk_get_product', null, $product_id);
		if ($product === null) {
			$product = $product_id ? wc_get_product($product_id) : false;
		}
		$name = ($product && method_exists($product, 'get_name')) ? (string) $product->get_name() : '';

		$base = [
			'ok'             => true,
			'outcome'        => 'processed',
			'product_id'     => $product_id,
			'product_name'   => $name,
			'message'        => '',
			'new_product_id' => null,
			'wpml'           => null,
		];

		$allowed = ['refresh_attrs', 'scaffold_variations', 'duplicate_to_year', 'sync_wpml_languages'];
		if (!in_array($action, $allowed, true)) {
			return array_merge($base, [
				'ok'      => false,
				'outcome' => 'failed',
				'message' => __('Unknown bulk action.', 'intersoccer-product-variations'),
			]);
		}

		if (!$product || !method_exists($product, 'is_type') || !$product->is_type('variable')) {
			return array_merge($base, [
				'ok'      => true,
				'outcome' => 'skipped',
				'message' => sprintf(
					/* translators: %d: product ID */
					__('Skipped #%d (not a variable product).', 'intersoccer-product-variations'),
					$product_id
				),
			]);
		}

		if ($action === 'refresh_attrs') {
			$type = class_exists('InterSoccer_Product_Types')
				? InterSoccer_Product_Types::get_product_type($product_id)
				: '';
			if ($type === 'camp') {
				self::repair_camp_variation_facets($product_id);
			} else {
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
			}
			return array_merge($base, [
				'message' => __('Variation attributes refreshed.', 'intersoccer-product-variations'),
			]);
		}

		if ($action === 'scaffold_variations') {
			if (count($product->get_children()) > 0) {
				return array_merge($base, [
					'outcome' => 'skipped',
					'message' => sprintf(
						/* translators: %d: product ID */
						__('Skipped #%d (variations already exist).', 'intersoccer-product-variations'),
						$product_id
					),
				]);
			}
			$type = class_exists('InterSoccer_Product_Types')
				? InterSoccer_Product_Types::get_product_type($product_id)
				: '';
			if (!$type) {
				return array_merge($base, [
					'outcome' => 'skipped',
					'message' => sprintf(
						/* translators: %d: product ID */
						__('Skipped #%d (unknown program type).', 'intersoccer-product-variations'),
						$product_id
					),
				]);
			}
			$matrix = self::get_default_matrix($type, $product_id);
			foreach ($matrix as $row) {
				self::create_single_variation($product_id, $type, $row);
			}
			return array_merge($base, [
				'message' => __('Variations scaffolded.', 'intersoccer-product-variations'),
			]);
		}

		if ($action === 'duplicate_to_year') {
			$target_year   = self::normalize_program_year($opts['year'] ?? '');
			$target_season = isset($opts['season']) ? sanitize_text_field((string) $opts['season']) : '';
			if ($target_year === '') {
				return array_merge($base, [
					'ok'      => false,
					'outcome' => 'failed',
					'message' => __('Bulk Duplicate requires a target program year (e.g. 2027).', 'intersoccer-product-variations'),
				]);
			}
			$type = class_exists('InterSoccer_Product_Types')
				? InterSoccer_Product_Types::get_product_type($product_id)
				: '';
			if (!$type) {
				return array_merge($base, [
					'outcome' => 'skipped',
					'message' => sprintf(
						/* translators: %d: product ID */
						__('Skipped #%d (unknown program type).', 'intersoccer-product-variations'),
						$product_id
					),
				]);
			}

			$new_id = self::duplicate_program($product_id, $product->get_name(), [
				'year'          => $target_year,
				'season'        => $target_season,
				'rewrite_title' => true,
			]);
			if (is_wp_error($new_id)) {
				return array_merge($base, [
					'ok'      => false,
					'outcome' => 'failed',
					'message' => sprintf(
						/* translators: 1: product ID, 2: error message */
						__('#%1$d: %2$s', 'intersoccer-product-variations'),
						$product_id,
						$new_id->get_error_message()
					),
				]);
			}

			return array_merge($base, [
				'new_product_id' => (int) $new_id,
				'message'        => sprintf(
					/* translators: 1: source product ID, 2: new product ID, 3: year */
					__('Duplicated #%1$d → #%2$d (year %3$s).', 'intersoccer-product-variations'),
					$product_id,
					(int) $new_id,
					$target_year
				),
			]);
		}

		// sync_wpml_languages
		if (!function_exists('intersoccer_pm_sync_product_translations')) {
			return array_merge($base, [
				'ok'      => false,
				'outcome' => 'failed',
				'message' => __('WPML sync is not available.', 'intersoccer-product-variations'),
			]);
		}

		$wpml = intersoccer_pm_sync_product_translations($product_id);
		$ok   = !empty($wpml['ok']);
		$failed = !$ok && !empty($wpml['errors']);
		$source_id = (int) ($wpml['source_product_id'] ?? $product_id);
		$source    = wc_get_product($source_id);
		$source_name = ($source && method_exists($source, 'get_name')) ? (string) $source->get_name() : $name;

		return [
			'ok'             => $ok || !$failed,
			'outcome'        => $failed ? 'failed' : 'processed',
			'product_id'     => $source_id,
			'product_name'   => $source_name,
			'message'        => (string) ($wpml['message'] ?? ''),
			'new_product_id' => null,
			'wpml'           => $wpml,
		];
	}

	public static function ajax_quick_edit() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$name       = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
		$status     = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
		$attrs_raw  = isset($_POST['attrs']) && is_array($_POST['attrs']) ? $_POST['attrs'] : [];

		$product = wc_get_product($product_id);
		if (!$product || !$product->is_type('variable')) {
			wp_send_json_error(['message' => __('Invalid product.', 'intersoccer-product-variations')]);
		}

		if ($name !== '') {
			$product->set_name($name);
		}
		$allowed_status = function_exists('intersoccer_pm_is_allowed_product_status')
			? intersoccer_pm_is_allowed_product_status($status)
			: in_array((string) $status, ['draft', 'publish', 'private'], true);
		if ($allowed_status) {
			$product->set_status($status);
		}

		$existing_attributes = $product->get_attributes();
		foreach ($attrs_raw as $taxonomy => $slugs) {
			$taxonomy = sanitize_text_field($taxonomy);
			if (strpos($taxonomy, 'pa_') !== 0) {
				continue;
			}
			if (!is_array($slugs)) {
				$slugs = [$slugs];
			}
			$slugs = array_values(array_filter(array_map('sanitize_text_field', $slugs)));
			$term_ids = [];
			foreach ($slugs as $slug) {
				$term = get_term_by('slug', $slug, $taxonomy);
				if ($term && !is_wp_error($term)) {
					$term_ids[] = (int) $term->term_id;
				}
			}

			if (isset($existing_attributes[$taxonomy]) && $existing_attributes[$taxonomy] instanceof WC_Product_Attribute) {
				$attr = clone $existing_attributes[$taxonomy];
			} else {
				$attr = new WC_Product_Attribute();
				$attr->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
				$attr->set_name($taxonomy);
				$attr->set_visible(true);
				$attr->set_variation(false);
			}
			$attr->set_options($term_ids);
			$existing_attributes[$taxonomy] = $attr;
		}

		$product->set_attributes($existing_attributes);
		$product->save();
		wc_delete_product_transients($product_id);

		if ($allowed_status && function_exists('intersoccer_sync_product_status_to_translations')) {
			intersoccer_sync_product_status_to_translations($product_id, $status);
		}

		wp_send_json_success([
			'product_id' => $product_id,
			'name'       => $product->get_name(),
			'status'     => $product->get_status(),
		]);
	}

	/**
	 * Normalize a program-year value to a bare calendar year (e.g. 2027).
	 *
	 * @param string $year Raw year (name or slug).
	 * @return string Empty string if invalid.
	 */
	public static function normalize_program_year($year) {
		return intersoccer_pm_normalize_program_year($year);
	}

	/**
	 * Rewrite a program title for a target year (strip "(Copy)", replace or append year).
	 *
	 * @param string $name Source or draft title.
	 * @param string $year Target year (normalized internally).
	 * @return string
	 */
	public static function rewrite_program_title_for_year($name, $year) {
		return intersoccer_pm_rewrite_program_title_for_year($name, $year);
	}

	/**
	 * Ensure a pa_program-year term exists (creates bare year terms only — never season labels).
	 *
	 * @param string $year Year name/slug.
	 * @return WP_Term|WP_Error
	 */
	public static function ensure_program_year_term($year) {
		$year = self::normalize_program_year($year);
		if ($year === '') {
			return new WP_Error(
				'invalid_year',
				__('A valid program year (e.g. 2027) is required.', 'intersoccer-product-variations')
			);
		}

		$term = get_term_by('slug', $year, 'pa_program-year');
		if (!$term || is_wp_error($term)) {
			$term = get_term_by('name', $year, 'pa_program-year');
		}
		if ($term && !is_wp_error($term)) {
			return $term;
		}

		$result = wp_insert_term($year, 'pa_program-year', ['slug' => $year]);
		if (is_wp_error($result)) {
			// Race: term may have been created concurrently.
			$existing = get_term_by('slug', $year, 'pa_program-year');
			if ($existing && !is_wp_error($existing)) {
				return $existing;
			}
			return $result;
		}

		$created = get_term((int) $result['term_id'], 'pa_program-year');
		if (!$created || is_wp_error($created)) {
			return new WP_Error(
				'year_term_missing',
				__('Could not create program year term.', 'intersoccer-product-variations')
			);
		}
		return $created;
	}

	/**
	 * Duplicate a variable program as a Draft (structure + late-pickup flags; optional year/season).
	 *
	 * Does not copy prices or camp/course schedule dates. Does not create WPML translations.
	 * Never creates year-qualified season terms (e.g. "Autumn 2027").
	 *
	 * @param int    $source_id Source product ID.
	 * @param string $new_name  New product name (may be rewritten when year + rewrite_title).
	 * @param array  $opts {
	 *     @type string $season        Optional season slug (existing term only).
	 *     @type string $year          Optional/required program year (creates term if needed).
	 *     @type bool   $rewrite_title When true and year set, rewrite title for target year.
	 * }
	 * @return int|WP_Error New product ID or error.
	 */
	public static function duplicate_program($source_id, $new_name, $opts = []) {
		$opts = wp_parse_args($opts, [
			'season'        => '',
			'year'          => '',
			'rewrite_title' => false,
		]);

		$source_id  = absint($source_id);
		$new_name   = sanitize_text_field((string) $new_name);
		$new_season = sanitize_text_field((string) $opts['season']);
		$new_year   = self::normalize_program_year($opts['year']);

		if (!$source_id || $new_name === '') {
			return new WP_Error(
				'missing_args',
				__('Source ID and name are required.', 'intersoccer-product-variations')
			);
		}

		$source = wc_get_product($source_id);
		if (!$source || !$source->is_type('variable')) {
			return new WP_Error(
				'invalid_source',
				__('Invalid source product.', 'intersoccer-product-variations')
			);
		}

		$type = class_exists('InterSoccer_Product_Types')
			? InterSoccer_Product_Types::get_product_type($source_id)
			: '';
		if (!$type) {
			return new WP_Error(
				'unknown_type',
				__('Source product has no InterSoccer program type.', 'intersoccer-product-variations')
			);
		}

		$year_term = null;
		if ($new_year !== '') {
			$year_term = self::ensure_program_year_term($new_year);
			if (is_wp_error($year_term)) {
				return $year_term;
			}
			if (!empty($opts['rewrite_title'])) {
				$new_name = self::rewrite_program_title_for_year($new_name, $new_year);
			}
		}

		$season_term = null;
		if ($new_season !== '') {
			if (function_exists('intersoccer_pm_is_year_qualified_season_label')
				&& intersoccer_pm_is_year_qualified_season_label($new_season)) {
				return new WP_Error(
					'year_qualified_season',
					__('Do not use year-qualified season terms (e.g. Autumn 2027). Use evergreen seasons and pa_program-year.', 'intersoccer-product-variations')
				);
			}
			// Existing evergreen season terms only — never invent "Autumn 2027".
			$season_term = get_term_by('slug', $new_season, 'pa_program-season');
			if (!$season_term || is_wp_error($season_term)) {
				$season_term = get_term_by('name', $new_season, 'pa_program-season');
			}
			if (!$season_term || is_wp_error($season_term)) {
				return new WP_Error(
					'invalid_season',
					__('Season term not found. Use an evergreen season (Autumn, Winter, Spring, Summer).', 'intersoccer-product-variations')
				);
			}
		}

		$new_product = new WC_Product_Variable();
		$new_product->set_name($new_name);
		$new_product->set_status('draft');
		$new_product->set_catalog_visibility('visible');

		$source_attributes = $source->get_attributes();
		$new_attributes    = [];

		foreach ($source_attributes as $attribute) {
			$clone = clone $attribute;
			$attr_name = $clone->get_name();
			if ($season_term && $attr_name === 'pa_program-season') {
				$clone->set_options([(int) $season_term->term_id]);
			}
			if ($year_term && $attr_name === 'pa_program-year') {
				$clone->set_options([(int) $year_term->term_id]);
			}
			$new_attributes[] = $clone;
		}

		$new_product->set_attributes($new_attributes);
		$new_id = $new_product->save();

		if (!$new_id) {
			return new WP_Error(
				'duplicate_failed',
				__('Failed to create duplicate.', 'intersoccer-product-variations')
			);
		}

		update_post_meta($new_id, '_intersoccer_product_type', $type);

		if ($season_term) {
			wp_set_object_terms($new_id, [(int) $season_term->term_id], 'pa_program-season');
		}
		if ($year_term) {
			wp_set_object_terms($new_id, [(int) $year_term->term_id], 'pa_program-year');
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

		return (int) $new_id;
	}

	public static function ajax_duplicate_program() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-product-variations')]);
		}

		$source_id  = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
		$new_name   = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
		$new_season = isset($_POST['season']) ? sanitize_text_field(wp_unslash($_POST['season'])) : '';
		$new_year   = isset($_POST['year']) ? sanitize_text_field(wp_unslash($_POST['year'])) : '';

		if (!$source_id || $new_name === '') {
			wp_send_json_error(['message' => __('Source ID and name are required.', 'intersoccer-product-variations')]);
		}

		$new_id = self::duplicate_program($source_id, $new_name, [
			'season'        => $new_season,
			'year'          => $new_year,
			'rewrite_title' => ($new_year !== ''),
		]);

		if (is_wp_error($new_id)) {
			wp_send_json_error(['message' => $new_id->get_error_message()]);
		}

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

		if ($type === 'camp') {
			self::repair_camp_variation_facets($product_id);
		} elseif ($type === 'course') {
			// Keep venues as a variation attribute so storefront shows InterSoccer Venues.
			$course_venues = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'slugs']);
			if (!is_wp_error($course_venues) && !empty($course_venues)) {
				self::ensure_parent_taxonomy_variation_attribute($product_id, 'pa_intersoccer-venues', $course_venues);
			}
		}

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

	/**
	 * Human-readable label for a missing completeness key.
	 *
	 * @param string $key
	 * @return string
	 */
	private static function format_missing_key_label($key) {
		$key = (string) $key;
		if (strpos($key, 'pa_') === 0) {
			$slug  = substr($key, 3);
			$label = function_exists('intersoccer_attr_wc_label') ? intersoccer_attr_wc_label($slug) : '';
			return $label !== '' ? $label : $key;
		}
		$meta_labels = [
			'_regular_price'   => __('Regular price', 'intersoccer-product-variations'),
			'_camp_start_date' => __('Camp start date', 'intersoccer-product-variations'),
			'_camp_end_date'   => __('Camp end date', 'intersoccer-product-variations'),
			'_camp_week_index' => __('Camp week', 'intersoccer-product-variations'),
		];
		return $meta_labels[$key] ?? $key;
	}

	private static function get_default_matrix($type, $product_id = 0) {
		switch ($type) {
			case 'camp':
				$ages  = [];
				$times = [];
				if ($product_id) {
					$ages = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'slugs']);
					$times = wc_get_product_terms($product_id, 'pa_camp-times', ['fields' => 'slugs']);
					if (is_wp_error($ages)) {
						$ages = [];
					}
					if (is_wp_error($times)) {
						$times = [];
					}
				}
				return self::build_camp_matrix_rows($ages, $times);
			case 'course':
				$days   = [];
				$ages   = [];
				$venues = [];
				if ($product_id) {
					$days   = wc_get_product_terms($product_id, 'pa_course-day', ['fields' => 'slugs']);
					$ages   = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'slugs']);
					$venues = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'slugs']);
					if (is_wp_error($days)) {
						$days = [];
					}
					if (is_wp_error($ages)) {
						$ages = [];
					}
					if (is_wp_error($venues)) {
						$venues = [];
					}
				}
				// Course times are variation-only — do not expand matrix from parent times.
				return self::build_course_matrix_rows($days, $ages, [], $venues);
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
	 * Build camp variation matrix: booking-type × age-group × paired camp-times.
	 *
	 * @param string[] $age_slugs
	 * @param string[] $time_slugs Allowed parent times (optional).
	 * @param string[] $booking_slugs
	 * @return array<int,array<string,string>>
	 */
	public static function build_camp_matrix_rows($age_slugs = [], $time_slugs = [], $booking_slugs = []) {
		if (!function_exists('intersoccer_pm_default_camp_time_slug_for_age')) {
			$helpers = INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . 'includes/helpers.php';
			if (is_readable($helpers)) {
				require_once $helpers;
			}
		}

		$ages = array_values(array_filter(array_map('strval', (array) $age_slugs)));
		if (empty($ages)) {
			$ages = ['5-13y-full-day', '3-5y-half-day'];
		}

		$bookings = array_values(array_filter(array_map('strval', (array) $booking_slugs)));
		if (empty($bookings)) {
			$bookings = ['full-week', 'single-days'];
		}

		$times = array_values(array_filter(array_map('strval', (array) $time_slugs)));

		$booking_labels = [
			'full-week'   => __('Full Week', 'intersoccer-product-variations'),
			'single-days' => __('Single Day(s)', 'intersoccer-product-variations'),
		];

		$matrix = [];
		foreach ($ages as $age) {
			$time = function_exists('intersoccer_pm_default_camp_time_slug_for_age')
				? intersoccer_pm_default_camp_time_slug_for_age($age, $times)
				: '';
			foreach ($bookings as $booking) {
				$row = [
					'pa_booking-type' => $booking,
					'pa_age-group'    => $age,
				];
				if ($time !== '') {
					$row['pa_camp-times'] = $time;
				}
				$parts = [
					$booking_labels[$booking] ?? $booking,
					$age,
				];
				if ($time !== '') {
					$parts[] = $time;
				}
				$row['label'] = implode(' / ', $parts);
				$matrix[] = $row;
			}
		}

		return $matrix;
	}

	/**
	 * Stable signature for variation attribute uniqueness checks.
	 *
	 * @param array<string,mixed> $attributes
	 * @return string
	 */
	private static function variation_attr_signature($attributes) {
		$attrs = [];
		foreach ((array) $attributes as $key => $value) {
			if ($key === 'label' || $value === '' || $value === null) {
				continue;
			}
			$tax = (strpos((string) $key, 'pa_') === 0) ? (string) $key : 'pa_' . ltrim((string) $key, '_');
			$attrs[$tax] = is_array($value) ? implode(',', $value) : (string) $value;
		}
		ksort($attrs);
		return wp_json_encode($attrs);
	}

	/**
	 * Ensure pa_camp-times exists on the parent and is used for variations.
	 *
	 * @param int      $product_id
	 * @param string[] $extra_slugs
	 */
	private static function ensure_parent_camp_times_variation_attribute($product_id, $extra_slugs = []) {
		self::ensure_parent_taxonomy_variation_attribute($product_id, 'pa_camp-times', $extra_slugs);
	}

	/**
	 * Ensure course variation taxonomies on the parent are limited to matrix values.
	 *
	 * @param int      $product_id
	 * @param string[] $day_slugs
	 * @param string[] $time_slugs
	 * @param string[] $venue_slugs
	 */
	private static function ensure_parent_course_variation_attributes($product_id, $day_slugs = [], $time_slugs = [], $venue_slugs = []) {
		if (!empty($day_slugs)) {
			self::ensure_parent_taxonomy_variation_attribute($product_id, 'pa_course-day', $day_slugs);
		}
		if (!empty($time_slugs)) {
			self::ensure_parent_taxonomy_variation_attribute($product_id, 'pa_course-times', $time_slugs);
		}
		if (!empty($venue_slugs)) {
			self::ensure_parent_taxonomy_variation_attribute($product_id, 'pa_intersoccer-venues', $venue_slugs);
		}
	}

	/**
	 * Mark a parent taxonomy as used for variations and merge option slugs.
	 *
	 * @param int      $product_id
	 * @param string   $taxonomy
	 * @param string[] $extra_slugs
	 * @return bool True when parent attribute is marked is_variation after save.
	 */
	private static function ensure_parent_taxonomy_variation_attribute($product_id, $taxonomy, $extra_slugs = []) {
		$product = wc_get_product($product_id);
		if (!$product || !taxonomy_exists($taxonomy)) {
			return false;
		}

		$extra_slugs = array_values(array_unique(array_filter(array_map('strval', (array) $extra_slugs))));
		$current     = wc_get_product_terms($product_id, $taxonomy, ['fields' => 'slugs']);
		if (is_wp_error($current)) {
			$current = [];
		}
		$merged = array_values(array_unique(array_merge($current, $extra_slugs)));
		if (empty($merged)) {
			return false;
		}

		wp_set_object_terms($product_id, $merged, $taxonomy);

		$term_ids = [];
		foreach ($merged as $slug) {
			$term = get_term_by('slug', $slug, $taxonomy);
			if ($term && !is_wp_error($term)) {
				$term_ids[] = (int) $term->term_id;
			}
		}
		if ($term_ids === []) {
			return false;
		}

		// Fresh load — avoid stale attribute objects after wp_set_object_terms.
		$product = wc_get_product($product_id);
		if (!$product) {
			return false;
		}

		$attributes = $product->get_attributes();
		if (isset($attributes[$taxonomy]) && is_object($attributes[$taxonomy])) {
			$attributes[$taxonomy]->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
			$attributes[$taxonomy]->set_name($taxonomy);
			$attributes[$taxonomy]->set_options($term_ids);
			$attributes[$taxonomy]->set_variation(true);
			$attributes[$taxonomy]->set_visible(true);
		} else {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
			$attribute->set_name($taxonomy);
			$attribute->set_options($term_ids);
			$attribute->set_visible(true);
			$attribute->set_variation(true);
			$attributes[$taxonomy] = $attribute;
		}
		$product->set_attributes($attributes);
		$product->save();

		// Hard-confirm is_variation in _product_attributes (WC can drop the flag when options were slugs).
		$raw = get_post_meta($product_id, '_product_attributes', true);
		if (!is_array($raw)) {
			$raw = [];
		}
		if (!isset($raw[$taxonomy]) || !is_array($raw[$taxonomy])) {
			$raw[$taxonomy] = [
				'name' => $taxonomy,
				'value' => '',
				'position' => count($raw),
				'is_visible' => 1,
				'is_variation' => 1,
				'is_taxonomy' => 1,
			];
		} else {
			$raw[$taxonomy]['is_variation'] = 1;
			$raw[$taxonomy]['is_visible'] = 1;
			$raw[$taxonomy]['is_taxonomy'] = 1;
			$raw[$taxonomy]['name'] = $taxonomy;
		}
		update_post_meta($product_id, '_product_attributes', $raw);
		wc_delete_product_transients($product_id);
		clean_post_cache($product_id);

		$product = wc_get_product($product_id);
		$attrs = $product ? $product->get_attributes() : [];
		$ok = isset($attrs[$taxonomy]) && is_object($attrs[$taxonomy]) && $attrs[$taxonomy]->get_variation();

		return (bool) $ok;
	}

	/**
	 * Promote Venue/Camp Term to variation attrs and backfill empty variation values.
	 *
	 * Camp Times are left to propose/refresh helpers. Venue is only auto-filled when
	 * the parent has a single venue (multi-venue still needs staff assignment).
	 *
	 * @param int $product_id
	 * @return array{promoted:bool,updated:int,skipped:int,venue_needs_manual:int}
	 */
	public static function repair_camp_variation_facets($product_id) {
		$product_id = absint($product_id);
		$result = [
			'promoted' => false,
			'updated' => 0,
			'skipped' => 0,
			'venue_needs_manual' => 0,
		];

		$product = wc_get_product($product_id);
		if (!$product || !$product->is_type('variable')) {
			return $result;
		}

		$type = class_exists('InterSoccer_Product_Types')
			? InterSoccer_Product_Types::get_product_type($product_id)
			: '';
		if ($type !== 'camp') {
			return $result;
		}

		if (!function_exists('intersoccer_pm_infer_camp_term_slug_for_variation')) {
			require_once INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . 'includes/helpers.php';
		}

		$venues = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'slugs']);
		$terms  = wc_get_product_terms($product_id, 'pa_camp-terms', ['fields' => 'slugs']);
		if (is_wp_error($venues)) {
			$venues = [];
		}
		if (is_wp_error($terms)) {
			$terms = [];
		}

		$venue_ok = self::ensure_parent_taxonomy_variation_attribute($product_id, 'pa_intersoccer-venues', $venues);
		$terms_ok = self::ensure_parent_taxonomy_variation_attribute($product_id, 'pa_camp-terms', $terms);
		$times_ok = self::ensure_parent_taxonomy_variation_attribute($product_id, 'pa_camp-times', []);
		$result['promoted'] = ($venue_ok || $venues === []) && ($terms_ok || $terms === []) && $times_ok !== false;
		$result['parent_flags'] = [
			'pa_intersoccer-venues' => $venue_ok,
			'pa_camp-terms' => $terms_ok,
			'pa_camp-times' => $times_ok,
		];

		$product = wc_get_product($product_id);
		if (!$product) {
			return $result;
		}

		foreach ($product->get_children() as $var_id) {
			$variation = wc_get_product($var_id);
			if (!$variation || !($variation instanceof WC_Product_Variation)) {
				continue;
			}

			$attrs = $variation->get_attributes();
			$changed = false;

			$venue = isset($attrs['pa_intersoccer-venues']) ? (string) $attrs['pa_intersoccer-venues'] : '';
			$term  = isset($attrs['pa_camp-terms']) ? (string) $attrs['pa_camp-terms'] : '';

			// Prefer stored meta when WC attribute object is empty (avoids wiping on save).
			if ($venue === '') {
				$meta_venue = get_post_meta($var_id, 'attribute_pa_intersoccer-venues', true);
				if (is_string($meta_venue) && $meta_venue !== '') {
					$attrs['pa_intersoccer-venues'] = $meta_venue;
					$venue = $meta_venue;
				}
			}
			if ($term === '') {
				$meta_term = get_post_meta($var_id, 'attribute_pa_camp-terms', true);
				if (is_string($meta_term) && $meta_term !== '') {
					$attrs['pa_camp-terms'] = $meta_term;
					$term = $meta_term;
				}
			}

			if ($term === '' && $terms_ok) {
				$proposed_term = intersoccer_pm_infer_camp_term_slug_for_variation($var_id, $terms);
				if ($proposed_term !== '') {
					$attrs['pa_camp-terms'] = $proposed_term;
					$changed = true;
				}
			}

			if ($venue === '') {
				$proposed_venue = intersoccer_pm_infer_venue_slug_for_variation($venues);
				if ($proposed_venue !== '' && $venue_ok) {
					$attrs['pa_intersoccer-venues'] = $proposed_venue;
					$changed = true;
				} elseif ($venue === '' && count($venues) > 1) {
					$result['venue_needs_manual']++;
				}
			}

			if ($changed) {
				$variation->set_attributes($attrs);
				$variation->save();
				// Persist taxonomy attrs as post meta — WC may ignore keys not yet marked for variations.
				if (!empty($attrs['pa_camp-terms'])) {
					update_post_meta($var_id, 'attribute_pa_camp-terms', $attrs['pa_camp-terms']);
					wp_set_object_terms($var_id, $attrs['pa_camp-terms'], 'pa_camp-terms');
					if (function_exists('intersoccer_sync_variation_taxonomy_attribute_to_translations')) {
						intersoccer_sync_variation_taxonomy_attribute_to_translations((int) $var_id, 'pa_camp-terms', (string) $attrs['pa_camp-terms']);
					}
				}
				if (!empty($attrs['pa_intersoccer-venues'])) {
					update_post_meta($var_id, 'attribute_pa_intersoccer-venues', $attrs['pa_intersoccer-venues']);
					wp_set_object_terms($var_id, $attrs['pa_intersoccer-venues'], 'pa_intersoccer-venues');
					if (function_exists('intersoccer_sync_variation_taxonomy_attribute_to_translations')) {
						intersoccer_sync_variation_taxonomy_attribute_to_translations((int) $var_id, 'pa_intersoccer-venues', (string) $attrs['pa_intersoccer-venues']);
					}
				}
				$persisted = wc_get_product($var_id);
				$pa = $persisted ? $persisted->get_attributes() : [];
				$term_ok = !empty($attrs['pa_camp-terms']) && (($pa['pa_camp-terms'] ?? '') === $attrs['pa_camp-terms'] || get_post_meta($var_id, 'attribute_pa_camp-terms', true) === $attrs['pa_camp-terms']);
				if ($term_ok || (!empty($attrs['pa_intersoccer-venues']) && (($pa['pa_intersoccer-venues'] ?? '') !== '' || get_post_meta($var_id, 'attribute_pa_intersoccer-venues', true) !== ''))) {
					$result['updated']++;
				} else {
					$result['skipped']++;
				}
			} else {
				$result['skipped']++;
			}
		}

		wc_delete_product_transients($product_id);

		return $result;
	}

	/**
	 * @param int    $product_id
	 * @param string $type
	 * @param array  $attributes Key-value pairs of taxonomy => slug
	 * @param array  $course_meta Optional course meta overrides (_course_*).
	 * @param string $regular_price Optional CHF price applied to the variation.
	 * @return int|false Variation ID or false on failure.
	 */
	private static function create_single_variation($product_id, $type, $attributes, $course_meta = [], $regular_price = '') {
		$attributes = array_map('sanitize_text_field', (array) $attributes);
		unset($attributes['label']);

		$variation = new WC_Product_Variation();
		$variation->set_parent_id($product_id);
		$variation->set_status('publish');
		$variation->set_attributes($attributes);

		if ($regular_price !== '' && $regular_price !== null && is_numeric($regular_price)) {
			$variation->set_regular_price((string) $regular_price);
			$variation->set_price((string) $regular_price);
		}

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
				if (strpos($meta_key, '_') !== 0) {
					continue;
				}
				$value = $default_value;
				if (isset($course_meta[$meta_key]) && $course_meta[$meta_key] !== '' && $course_meta[$meta_key] !== null) {
					$value = $course_meta[$meta_key];
				}
				if ($meta_key === '_course_holiday_dates') {
					$value = self::normalize_course_holiday_dates($value);
				}
				if ($meta_key === '_course_total_weeks') {
					$value = (string) max(0, (int) $value);
				}
				update_post_meta($var_id, $meta_key, $value);
			}
			if (function_exists('intersoccer_sync_course_metadata_to_translations')) {
				$start = (string) get_post_meta($var_id, '_course_start_date', true);
				$weeks = (int) get_post_meta($var_id, '_course_total_weeks', true);
				$holidays = get_post_meta($var_id, '_course_holiday_dates', true);
				$discount = get_post_meta($var_id, '_course_weekly_discount', true);
				$end = (string) get_post_meta($var_id, '_end_date', true);
				intersoccer_sync_course_metadata_to_translations(
					$var_id,
					$start,
					$weeks,
					is_array($holidays) ? $holidays : [],
					is_numeric($discount) ? (float) $discount : 0.0,
					$end
				);
			}
		}

		return $var_id;
	}

	/**
	 * @param mixed $raw
	 * @return array<int,string>
	 */
	private static function normalize_course_holiday_dates($raw) {
		if (is_array($raw)) {
			$lines = $raw;
		} else {
			$lines = preg_split('/[\r\n,]+/', (string) $raw) ?: [];
		}
		$out = [];
		foreach ($lines as $line) {
			$line = trim((string) $line);
			if ($line !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) {
				$out[] = $line;
			}
		}
		return array_values(array_unique($out));
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
		$actions = [
			'refresh_attrs'       => __('Refresh Variation Attributes', 'intersoccer-product-variations'),
			'scaffold_variations' => __('Auto-scaffold Missing Variations', 'intersoccer-product-variations'),
			'duplicate_to_year'   => __('Duplicate to year…', 'intersoccer-product-variations'),
		];
		if (function_exists('intersoccer_pm_wpml_available') && intersoccer_pm_wpml_available()) {
			$actions['sync_wpml_languages'] = __('Sync all languages (WPML)', 'intersoccer-product-variations');
		}
		return $actions;
	}

	public function prepare_items() {
		$this->_column_headers = [$this->get_columns(), [], []];

		$search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

		$pm_status = isset($_REQUEST['pm_status']) ? sanitize_key(wp_unslash($_REQUEST['pm_status'])) : 'publish';
		if (!in_array($pm_status, ['publish', 'draft', 'private', 'all'], true)) {
			$pm_status = 'publish';
		}
		if ($pm_status === 'publish') {
			$status = ['publish'];
		} elseif ($pm_status === 'draft') {
			$status = ['draft'];
		} elseif ($pm_status === 'private') {
			$status = ['private'];
		} else {
			$status = ['publish', 'draft', 'private'];
		}

		$products = wc_get_products([
			'type'   => 'variable',
			'status' => $status,
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
			// Birthday packages use city (not InterSoccer Venues) — surface that in the Venue column.
			if ($type === 'birthday' && empty($venue_terms)) {
				$city_terms = wc_get_product_terms($product_id, 'pa_city', ['fields' => 'names']);
				if (!empty($city_terms) && !is_wp_error($city_terms)) {
					$venue_terms = $city_terms;
				}
			}

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
				$badge = '';
				if ($item['status'] === 'draft') {
					$badge = ' <span class="post-state">' . esc_html__('Draft', 'intersoccer-product-variations') . '</span>';
				} elseif ($item['status'] === 'private') {
					$badge = ' <span class="post-state">' . esc_html__('Private', 'intersoccer-product-variations') . '</span>';
				}
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

				$render_multi = static function ($taxonomy, $class, $label) use ($item) {
					$current = wc_get_product_terms($item['product_id'], $taxonomy, ['fields' => 'slugs']);
					if (!is_array($current)) {
						$current = [];
					}
					$all = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
					$html = '<p><label>' . esc_html($label) . '<br /><select class="' . esc_attr($class) . '" multiple size="4" style="width:100%;min-width:220px;">';
					if (!is_wp_error($all)) {
						foreach ($all as $term) {
							$html .= '<option value="' . esc_attr($term->slug) . '"' . selected(in_array($term->slug, $current, true), true, false) . '>'
								. esc_html($term->name) . '</option>';
						}
					}
					$html .= '</select></label></p>';
					return $html;
				};

				$qe = '<button type="button" class="button button-small intersoccer-pm-quick-edit-toggle" data-product-id="' . esc_attr($item['product_id']) . '">'
					. esc_html__('Quick Edit', 'intersoccer-product-variations') . '</button>';

				$qe_panel = '<div class="intersoccer-pm-quick-edit" data-product-id="' . esc_attr($item['product_id']) . '" data-type="' . esc_attr($item['type']) . '" style="display:none;margin-top:8px;padding:10px;background:#f6f7f7;border:1px solid #ccd0d4;max-width:560px;">'
					. '<p><label>' . esc_html__('Name', 'intersoccer-product-variations') . ' <input type="text" class="pm-qe-name widefat" value="' . esc_attr($item['name']) . '" /></label></p>'
					. '<p><label>' . esc_html__('Status', 'intersoccer-product-variations') . ' <select class="pm-qe-status">'
					. '<option value="draft"' . selected($item['status'], 'draft', false) . '>Draft</option>'
					. '<option value="publish"' . selected($item['status'], 'publish', false) . '>Publish</option>'
					. '<option value="private"' . selected($item['status'], 'private', false) . '>Private</option>'
					. '</select></label></p>';
				if ($item['type'] !== 'birthday') {
					$qe_panel .= $render_multi('pa_program-season', 'pm-qe-season', __('Season', 'intersoccer-product-variations'))
						. $render_multi('pa_intersoccer-venues', 'pm-qe-venues', __('Venues', 'intersoccer-product-variations'));
				} else {
					$qe_panel .= $render_multi('pa_city', 'pm-qe-city', __('Cities', 'intersoccer-product-variations'));
				}
				if ($item['type'] === 'camp') {
					$qe_panel .= $render_multi('pa_camp-terms', 'pm-qe-camp-terms', __('Camp Terms', 'intersoccer-product-variations'))
						. $render_multi('pa_camp-times', 'pm-qe-camp-times', __('Camp Times', 'intersoccer-product-variations'));
				}
				$qe_panel .= '<p><button type="button" class="button button-primary intersoccer-pm-quick-edit-save" data-product-id="' . esc_attr($item['product_id']) . '">'
					. esc_html__('Update', 'intersoccer-product-variations') . '</button> '
					. '<button type="button" class="button intersoccer-pm-quick-edit-cancel">' . esc_html__('Cancel', 'intersoccer-product-variations') . '</button> '
					. '<span class="pm-qe-status-msg"></span></p></div>';

				return $qe . ' '
					. '<a href="' . esc_url($detail_url) . '" class="button button-small">' . esc_html__('Manage', 'intersoccer-product-variations') . '</a> '
					. '<a href="' . esc_url($dup_url) . '" class="button button-small">' . esc_html__('Duplicate', 'intersoccer-product-variations') . '</a> '
					. '<a href="' . esc_url($edit_url) . '" class="button button-small button-link">' . esc_html__('WC Edit', 'intersoccer-product-variations') . '</a>'
					. $qe_panel;

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
