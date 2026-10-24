<?php
/**
 * Regression: year-only DOB must not parse as today's date (#113).
 *
 * Run: vendor/bin/phpunit tests/LooseDateYearOnlyTest.php
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
    define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        return $value;
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        if ($type === 'Y') {
            return '2026';
        }
        if ($type === 'Y-m-d') {
            return '2026-10-24';
        }
        return gmdate($type);
    }
}

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/age-group-verification.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

class LooseDateYearOnlyTest extends TestCase {
    /**
     * @param array<string,string> $meta
     * @return object
     */
    private function makeOrderItem(array $meta = []) {
        if (class_exists('WC_Order_Item_Product')) {
            return new WC_Order_Item_Product($meta);
        }

        return new class($meta) {
            /** @var array<string,string> */
            private $meta;

            public function __construct(array $meta) {
                $this->meta = $meta;
            }

            public function get_meta($key, $single = true) {
                return $this->meta[$key] ?? '';
            }

            public function get_product_id() {
                return 0;
            }

            public function get_variation_id() {
                return 0;
            }

            public function get_order() {
                return null;
            }
        };
    }
    private function currentCalendarYear(): string {
        return (string) (int) current_time('Y');
    }

    private function assertDisplayNeverShowsSpuriousCurrentYear(string $display, string $input): void {
        $current = $this->currentCalendarYear();
        if (strpos($input, $current) === false) {
            $this->assertStringNotContainsString($current, $display);
        }
    }

    public function test_parse_loose_date_rejects_year_only_and_junk(): void {
        $this->assertNull(intersoccer_parse_loose_date_to_ymd('2016'));
        $this->assertNull(intersoccer_parse_loose_date_to_ymd('1630'));
        $this->assertNull(intersoccer_parse_loose_date_to_ymd('05/2016'));
        $this->assertNull(intersoccer_parse_loose_date_to_ymd('05.2016'));
        $this->assertNull(intersoccer_parse_loose_date_to_ymd('2016-05'));
        $this->assertNull(intersoccer_parse_loose_date_to_ymd(''));
        $this->assertNull(intersoccer_parse_loose_date_to_ymd('   '));
        $this->assertNull(intersoccer_parse_loose_date_to_ymd('abc'));
        $this->assertNull(intersoccer_parse_loose_date_to_ymd('now'));
        $this->assertNull(intersoccer_parse_loose_date_to_ymd('tomorrow'));
        $this->assertNull(intersoccer_parse_loose_date_to_ymd('2016-02-30'));
        $this->assertNull(intersoccer_parse_loose_date_to_ymd('01.01.2999'));
    }

    public function test_parse_loose_date_accepts_full_and_event_style_dates(): void {
        $this->assertSame('2016-05-03', intersoccer_parse_loose_date_to_ymd('2016-05-03'));
        $this->assertSame('2016-05-03', intersoccer_parse_loose_date_to_ymd('03.05.2016'));
        $parsed_event = intersoccer_parse_loose_date_to_ymd('January 15, 2025');
        $this->assertSame('2025-01-15', $parsed_event);
    }

    public function test_normalize_attendee_dob_rejects_future_full_dates(): void {
        $this->assertNull(intersoccer_normalize_attendee_dob_order_meta_value('2031-01-01'));
        $future = ((int) current_time('Y') + 2) . '-06-01';
        $this->assertNull(intersoccer_normalize_attendee_dob_order_meta_value($future));
    }

    public function test_redacted_display_year_only_and_partial_month_year(): void {
        $item = $this->makeOrderItem();
        $display = intersoccer_format_attendee_dob_for_redacted_surface('2016', $item);
        $this->assertSame('Birth year: 2016', $display);
        $this->assertDisplayNeverShowsSpuriousCurrentYear($display, '2016');

        $display_my = intersoccer_format_attendee_dob_for_redacted_surface('05/2016', $item);
        $this->assertSame('Birth year: 2016', $display_my);
        $this->assertDisplayNeverShowsSpuriousCurrentYear($display_my, '05/2016');
    }

    public function test_redacted_display_full_dob_with_and_without_start_date(): void {
        $item_no_start = $this->makeOrderItem();
        $birth_year_only = intersoccer_format_attendee_dob_for_redacted_surface('2016-05-03', $item_no_start);
        $this->assertSame('Birth year: 2016', $birth_year_only);
        $this->assertDisplayNeverShowsSpuriousCurrentYear($birth_year_only, '2016-05-03');

        $item_with_start = $this->makeOrderItem(['Camp Start Date' => '2026-07-06']);
        $age_line = intersoccer_format_attendee_dob_for_redacted_surface('2016-05-03', $item_with_start);
        $this->assertSame('Age at camp start: 10', $age_line);
        $this->assertDisplayNeverShowsSpuriousCurrentYear($age_line, '2016-05-03');

        $eu = intersoccer_format_attendee_dob_for_redacted_surface('03.05.2016', $item_no_start);
        $this->assertSame('Birth year: 2016', $eu);
    }

    public function test_redacted_display_omits_junk_and_future_years(): void {
        $item = $this->makeOrderItem();
        $this->assertSame('', intersoccer_format_attendee_dob_for_redacted_surface('', $item));
        $this->assertSame('', intersoccer_format_attendee_dob_for_redacted_surface('abc', $item));
        $this->assertSame('', intersoccer_format_attendee_dob_for_redacted_surface('2031', $item));
        $this->assertSame('', intersoccer_format_attendee_dob_for_redacted_surface('2031-05-03', $item));
    }

    public function test_age_gate_dob_parse_null_for_year_only(): void {
        $this->assertNull(intersoccer_parse_loose_date_to_ymd('2016'));
        $this->assertNull(intersoccer_normalize_attendee_dob_order_meta_value('2016'));
    }
}
