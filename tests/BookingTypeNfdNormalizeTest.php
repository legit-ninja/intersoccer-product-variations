<?php
/**
 * Regression: PV#164 — NFD decomposed FR full-week labels must classify as full-week.
 *
 * Run: php vendor/bin/phpunit tests/BookingTypeNfdNormalizeTest.php
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';

class BookingTypeNfdNormalizeTest extends TestCase
{
    private const DECOMPOSED_LOWER = "Semaine comple\u{0300}te";
    private const DECOMPOSED_TITLE = "Semaine Comple\u{0300}te";

    public function test_strip_combining_marks_decomposed_lower(): void
    {
        $out = intersoccer_booking_type_strip_combining_marks(self::DECOMPOSED_LOWER);
        $this->assertSame('Semaine complete', $out);
        $this->assertSame(0, preg_match('/\p{Mn}/u', $out));
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type($out));
    }

    public function test_strip_combining_marks_decomposed_title(): void
    {
        $out = intersoccer_booking_type_strip_combining_marks(self::DECOMPOSED_TITLE);
        $this->assertSame('Semaine Complete', $out);
        $this->assertSame(0, preg_match('/\p{Mn}/u', $out));
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type($out));
    }

    public function test_decomposed_french_full_week_with_normalizer_when_intl_available(): void
    {
        if (!class_exists('Normalizer')) {
            $this->markTestSkipped('intl Normalizer not available in this PHP build');
        }
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type(self::DECOMPOSED_LOWER));
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type(self::DECOMPOSED_TITLE));
    }

    public function test_no_intl_path_strip_output_normalizes_to_full_week(): void
    {
        $lower = intersoccer_booking_type_strip_combining_marks(self::DECOMPOSED_LOWER);
        $title = intersoccer_booking_type_strip_combining_marks(self::DECOMPOSED_TITLE);
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type($lower));
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type($title));
    }

    public function test_decomposed_raw_label_normalizes_without_intl(): void
    {
        if (class_exists('Normalizer')) {
            $this->markTestSkipped('Requires environment without intl Normalizer (e.g. Debian php:8.4-cli)');
        }
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type(self::DECOMPOSED_LOWER));
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type(self::DECOMPOSED_TITLE));
    }

    public function test_decomposed_label_counts_for_camp_sibling_discount(): void
    {
        $this->assertTrue(
            intersoccer_discount_camp_booking_counts_for_sibling(self::DECOMPOSED_LOWER),
            'Decomposed FR full-week label must qualify for camp sibling discounts'
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function precomposedFullWeekLabels(): array
    {
        return [
            'EN Full Week' => ['Full Week', 'full-week'],
            'FR Semaine complète' => ['Semaine complète', 'full-week'],
            'DE Ganze Woche' => ['Ganze Woche', 'full-week'],
            'slug full-week' => ['full-week', 'full-week'],
            'slug semaine-complete' => ['semaine-complete', 'full-week'],
            'slug ganze-woche' => ['ganze-woche', 'full-week'],
        ];
    }

    #[DataProvider('precomposedFullWeekLabels')]
    public function test_precomposed_labels_unchanged(string $raw, string $expected): void
    {
        $this->assertSame($expected, intersoccer_normalize_canonical_booking_type($raw));
    }
}
