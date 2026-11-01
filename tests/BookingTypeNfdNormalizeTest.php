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

    protected function tearDown(): void
    {
        MockFilters::reset();
        parent::tearDown();
    }

    public function test_decomposed_french_full_week_with_normalizer_when_intl_available(): void
    {
        if (!class_exists('Normalizer') && !function_exists('normalizer_normalize')) {
            $this->markTestSkipped('intl Normalizer not available in this PHP build');
        }
        MockFilters::reset();
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type(self::DECOMPOSED_LOWER));
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type(self::DECOMPOSED_TITLE));
    }

    public function test_decomposed_french_full_week_with_combining_mark_fallback(): void
    {
        MockFilters::$filters['intersoccer_booking_type_unicode_compose_force_fallback'] = static function () {
            return true;
        };
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type(self::DECOMPOSED_LOWER));
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type(self::DECOMPOSED_TITLE));
    }

    public function test_unicode_compose_fallback_strips_combining_acute_directly(): void
    {
        MockFilters::$filters['intersoccer_booking_type_unicode_compose_force_fallback'] = static function () {
            return true;
        };
        $composed = intersoccer_booking_type_unicode_compose(self::DECOMPOSED_LOWER);
        $this->assertSame('Semaine complete', $composed);
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
