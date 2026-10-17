<?php
/**
 * Privacy redaction for attendee DOB and medical conditions on emails and thank-you page.
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
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

if (!function_exists('is_admin')) {
    function is_admin() {
        return !empty($GLOBALS['intersoccer_test_is_admin']);
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() {
        return !empty($GLOBALS['intersoccer_test_doing_ajax']);
    }
}

if (!function_exists('is_order_received_page')) {
    function is_order_received_page() {
        return !empty($GLOBALS['intersoccer_test_is_order_received_page']);
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/age-group-verification.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        private $meta = [];

        public function __construct(array $meta = []) {
            foreach ($meta as $key => $value) {
                $this->meta[] = ['key' => $key, 'value' => $value];
            }
        }

        public function get_meta($key, $single = true) {
            $values = [];
            foreach ($this->meta as $row) {
                if ($row['key'] === $key) {
                    $values[] = $row['value'];
                }
            }
            return $values[0] ?? '';
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
    }
}

class OrderMetaPrivacyTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        unset(
            $GLOBALS['intersoccer_test_is_admin'],
            $GLOBALS['intersoccer_test_doing_ajax'],
            $GLOBALS['intersoccer_test_is_order_received_page'],
            $GLOBALS['intersoccer_sensitive_order_meta_email']
        );
    }

    /**
     * @param array<int,object> $formatted_meta
     * @return array<string,string>
     */
    private function meta_map(array $formatted_meta): array {
        $map = [];
        foreach ($formatted_meta as $meta) {
            $map[(string) $meta->key] = (string) ($meta->display_value ?? $meta->value ?? '');
        }
        return $map;
    }

    private function sample_formatted_meta(bool $plain_text = false): array {
        $medical = (object) [
            'key' => 'Medical Conditions',
            'display_key' => 'Medical Conditions',
            'value' => 'Peanut allergy',
            'display_value' => 'Peanut allergy',
        ];
        $dob = (object) [
            'key' => 'Attendee DOB',
            'display_key' => 'Attendee DOB',
            'value' => '2015-06-20',
            'display_value' => '2015-06-20',
        ];
        if ($plain_text) {
            $medical->display_value = $medical->value;
            $dob->display_value = $dob->value;
        }
        return [$medical, $dob];
    }

    public function test_admin_order_screen_keeps_full_medical_and_dob(): void {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $item = new WC_Order_Item_Product([
            'Camp Start Date' => '2026-07-06',
            'Attendee DOB' => '2015-06-20',
            'Medical Conditions' => 'Peanut allergy',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->sample_formatted_meta(), $item);
        $map = $this->meta_map($filtered);
        $this->assertSame('Peanut allergy', $map['Medical Conditions']);
        $this->assertSame('2015-06-20', $map['Attendee DOB']);
    }

    public function test_my_account_order_view_keeps_full_values(): void {
        $item = new WC_Order_Item_Product([
            'Attendee DOB' => '2015-06-20',
            'Medical Conditions' => 'Peanut allergy',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->sample_formatted_meta(), $item);
        $map = $this->meta_map($filtered);
        $this->assertSame('Peanut allergy', $map['Medical Conditions']);
        $this->assertSame('2015-06-20', $map['Attendee DOB']);
    }

    public function test_thank_you_page_masks_medical_and_dob(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        $item = new WC_Order_Item_Product([
            'Camp Start Date' => '2026-07-06',
            'Attendee DOB' => '2015-06-20',
            'Medical Conditions' => 'Peanut allergy',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->sample_formatted_meta(), $item);
        $map = $this->meta_map($filtered);
        $this->assertStringContainsString('Medical information: provided', $map['Medical Conditions']);
        $this->assertStringNotContainsString('Peanut', $map['Medical Conditions']);
        $this->assertSame('Age at camp start: 11', $map['Attendee DOB']);
        $this->assertStringNotContainsString('2015-06-20', $map['Attendee DOB']);
    }

    public function test_customer_email_html_masks_sensitive_meta(): void {
        intersoccer_sensitive_order_meta_begin_email_context();
        $item = new WC_Order_Item_Product([
            'Camp Start Date' => '2026-07-06',
            'Attendee DOB' => '2015-06-20',
            'Medical Conditions' => 'Peanut allergy',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->sample_formatted_meta(false), $item);
        intersoccer_sensitive_order_meta_end_email_context();
        $map = $this->meta_map($filtered);
        $this->assertStringContainsString('Medical information: provided', $map['Medical Conditions']);
        $this->assertSame('Age at camp start: 11', $map['Attendee DOB']);
    }

    public function test_customer_email_plain_text_masks_sensitive_meta(): void {
        intersoccer_sensitive_order_meta_begin_email_context();
        $item = new WC_Order_Item_Product([
            'Camp Start Date' => '2026-07-06',
            'Attendee DOB' => '2015-06-20',
            'Medical Conditions' => 'Peanut allergy',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->sample_formatted_meta(true), $item);
        intersoccer_sensitive_order_meta_end_email_context();
        foreach ($filtered as $meta) {
            if ($meta->key === 'Medical Conditions') {
                $this->assertSame($meta->value, $meta->display_value);
                $this->assertStringNotContainsString('Peanut', $meta->display_value);
            }
            if ($meta->key === 'Attendee DOB') {
                $this->assertSame($meta->value, $meta->display_value);
                $this->assertStringNotContainsString('2015-06-20', $meta->display_value);
            }
        }
    }

    public function test_admin_new_order_email_masks_even_in_admin_context(): void {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        intersoccer_sensitive_order_meta_begin_email_context();
        $item = new WC_Order_Item_Product([
            'Camp Start Date' => '2026-07-06',
            'Attendee DOB' => '2014-01-15',
            'Medical Conditions' => 'Asthma inhaler',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->sample_formatted_meta(), $item);
        intersoccer_sensitive_order_meta_end_email_context();
        $map = $this->meta_map($filtered);
        $this->assertStringContainsString('Medical information: provided', $map['Medical Conditions']);
        $this->assertStringNotContainsString('Asthma', $map['Medical Conditions']);
        $this->assertStringNotContainsString('2014-01-15', $map['Attendee DOB']);
    }

    public function test_birth_year_when_camp_start_unknown(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        $item = new WC_Order_Item_Product([
            'Attendee DOB' => '2012-09-01',
        ]);
        $meta = [
            (object) [
                'key' => 'Attendee DOB',
                'value' => '2012-09-01',
                'display_value' => '2012-09-01',
            ],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $map = $this->meta_map($filtered);
        $this->assertSame('Birth year: 2012', $map['Attendee DOB']);
    }

    public function test_medical_row_omitted_when_placeholder_none(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        $item = new WC_Order_Item_Product([
            'Medical Conditions' => 'None',
            'Attendee DOB' => '2015-01-01',
            'Camp Start Date' => '2026-07-06',
        ]);
        $meta = [
            (object) ['key' => 'Medical Conditions', 'value' => 'None', 'display_value' => 'None'],
            (object) ['key' => 'Attendee DOB', 'value' => '2015-01-01', 'display_value' => '2015-01-01'],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $keys = array_map(static fn($m) => $m->key, $filtered);
        $this->assertNotContains('Medical Conditions', $keys);
    }
}
