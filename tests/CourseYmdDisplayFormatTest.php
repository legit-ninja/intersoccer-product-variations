<?php

require_once dirname(__DIR__) . '/includes/language-helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

use PHPUnit\Framework\TestCase;

if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp) {
        return date($format, $timestamp);
    }
}

class CourseYmdDisplayFormatTest extends TestCase {
    public function test_format_ymd_uses_customer_display_format_msgid(): void {
        $formatted = intersoccer_pv_format_ymd_for_customer_display('2026-10-19');
        $this->assertStringContainsString('2026', $formatted);
        $this->assertStringNotContainsString('October 19, 2026', $formatted);
    }
}
