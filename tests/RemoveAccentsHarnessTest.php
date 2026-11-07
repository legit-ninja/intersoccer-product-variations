<?php
/**
 * The test double for remove_accents() must fold French and German letters
 * the way WordPress 6.7.1 does for those letters, without iconv.
 * This is not a complete WordPress remove_accents() implementation.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';

class RemoveAccentsHarnessTest extends TestCase
{
    public function test_french_and_german_letters_use_the_default_wordpress_map(): void
    {
        $letters = [
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a',
            'Æ' => 'AE', 'æ' => 'ae',
            'Ç' => 'C', 'ç' => 'c',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Î' => 'I', 'Ï' => 'I', 'î' => 'i', 'ï' => 'i',
            'Ô' => 'O', 'Ö' => 'O', 'ô' => 'o', 'ö' => 'o',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ÿ' => 'y',
            'Œ' => 'OE', 'œ' => 'oe',
            'ß' => 's',
        ];

        foreach ($letters as $letter => $expected) {
            $this->assertSame($expected, remove_accents($letter), $letter);
        }

        $this->assertSame('semaine-complete', remove_accents('semaine-complete'));
        $this->assertSame('Semaine complete', remove_accents('Semaine complète'));
        $this->assertStringNotContainsString('`', remove_accents('Semaine complète'));
    }

    public function test_german_locale_uses_wordpress_digraphs(): void
    {
        $this->assertSame('ae', remove_accents('ä', 'de_DE'));
        $this->assertSame('Ae', remove_accents('Ä', 'de_CH'));
        $this->assertSame('oe', remove_accents('ö', 'de_AT'));
        $this->assertSame('Oe', remove_accents('Ö', 'de_DE_formal'));
        $this->assertSame('ue', remove_accents('ü', 'de'));
        $this->assertSame('Ue', remove_accents('Ü', 'de_DE'));
        $this->assertSame('ss', remove_accents('ß', 'de_DE'));
        $this->assertSame('a', remove_accents('ä', 'fr_FR'));
        $this->assertSame('s', remove_accents('ß', 'en_US'));
    }

    public function test_accented_full_week_labels_fold_in_lower_and_title_case(): void
    {
        foreach (['semaine complète', 'Semaine Complète', 'für ganze woche', 'Für Ganze Woche'] as $label) {
            $this->assertSame(
                'full-week',
                intersoccer_normalize_canonical_booking_type($label),
                $label
            );
            $this->assertTrue(
                intersoccer_discount_camp_booking_counts_for_sibling($label),
                $label
            );
        }
    }
}
