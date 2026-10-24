<?php

require_once dirname(__DIR__) . '/includes/language-helpers.php';

use PHPUnit\Framework\TestCase;

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT, $depth);
    }
}

/**
 * PV strings embedded in inline JS must survive wp_json_encode (apostrophes, quotes, newlines).
 */
class InlineJsI18nEncodeTest extends TestCase {
    protected function tearDown(): void {
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        parent::tearDown();
    }

    public function test_late_pickup_elementor_strings_json_encodable_with_fr_de_apostrophes(): void {
        if (!class_exists('MockFilters')) {
            $this->markTestSkipped('MockFilters not available');
        }

        MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
            $map = [
                'No Late Pickup' => "Pas de récupération tardive l'autre jour",
                'Invalid selection' => "Sélection d'invalidité",
                'Full Week' => 'Ganze Woche "Test"',
                'Single Days' => "Einzelne Tage\nZeile zwei",
            ];
            return $map[$text] ?? $translation;
        };

        $strings = intersoccer_get_late_pickup_elementor_strings();
        $json = wp_json_encode($strings);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame("Pas de récupération tardive l'autre jour", $decoded['noLatePickup'] ?? '');
    }
}
