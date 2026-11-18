<?php
/**
 * Evergreen course/camp seasons on the locked pa_program-season registry.
 */

use PHPUnit\Framework\TestCase;

class EvergreenSeasonRegistryTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
        require_once dirname(__DIR__) . '/includes/woocommerce/attribute-enforcement.php';
        require_once dirname(__DIR__) . '/includes/helpers.php';
        require_once dirname(__DIR__) . '/includes/woocommerce/age-group-verification.php';
        require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
    }

    public function test_registry_keeps_original_four_then_adds_specialized_seasons(): void {
        $terms = intersoccer_attr_definition('program-season')['default_terms'];
        $names = array_column($terms, 'name');
        $slugs = array_column($terms, 'slug');

        $this->assertSame(
            ['Summer', 'Autumn', 'Spring', 'Winter'],
            array_slice($names, 0, 4)
        );
        $this->assertSame(
            ['summer', 'autumn', 'spring', 'winter'],
            array_slice($slugs, 0, 4)
        );
        $this->assertSame(
            ['Winter Courses', 'Spring/Summer Courses', 'Easter Camps'],
            array_slice($names, 4)
        );
        $this->assertSame(
            ['winter-courses', 'spring-summer-courses', 'easter-camps'],
            array_slice($slugs, 4)
        );
    }

    public function test_english_names_slug_to_the_expected_registry_slugs(): void {
        $this->assertSame('winter-courses', intersoccer_attr_english_term_slug('Winter Courses'));
        $this->assertSame('spring-summer-courses', intersoccer_attr_english_term_slug('Spring/Summer Courses'));
        $this->assertSame('easter-camps', intersoccer_attr_english_term_slug('Easter Camps'));
        $this->assertSame('winter', intersoccer_attr_english_term_slug('Winter'));
        $this->assertSame('spring', intersoccer_attr_english_term_slug('Spring'));
        $this->assertSame('summer', intersoccer_attr_english_term_slug('Summer'));
        $this->assertSame('autumn', intersoccer_attr_english_term_slug('Autumn'));
    }

    public function test_translated_names_do_not_sanitize_to_the_english_slug(): void {
        $this->assertNotSame('winter-courses', intersoccer_attr_english_term_slug("Cours d'hiver"));
        $this->assertNotSame('winter-courses', intersoccer_attr_english_term_slug('Winterkurse'));
        $this->assertNotSame('spring-summer-courses', intersoccer_attr_english_term_slug('Cours printemps-été'));
        $this->assertNotSame('spring-summer-courses', intersoccer_attr_english_term_slug('Frühlings-/Sommerkurse'));
        $this->assertNotSame('easter-camps', intersoccer_attr_english_term_slug('Camps de Pâques'));
        $this->assertNotSame('easter-camps', intersoccer_attr_english_term_slug('Osterferienlager'));
    }

    public function test_english_names_are_allowed(): void {
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', 'Winter Courses'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', 'Spring/Summer Courses'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', 'Easter Camps'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', 'Winter Courses', 'winter-courses'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', 'Spring/Summer Courses', 'spring-summer-courses'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', 'Easter Camps', 'easter-camps'));
    }

    public function test_original_seasons_stay_allowed_and_unknown_names_stay_blocked(): void {
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', 'Autumn', 'autumn'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', 'Summer'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', 'Spring'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', 'Winter'));

        $unknown = intersoccer_attr_validate_new_term('pa_program-season', 'Monsoon');
        $this->assertInstanceOf(WP_Error::class, $unknown);
        $this->assertSame('term_not_in_registry', $unknown->get_error_code());

        $legacy_fr = intersoccer_attr_validate_new_term('pa_program-season', 'Automne');
        $this->assertInstanceOf(WP_Error::class, $legacy_fr);
        $this->assertSame('term_not_in_registry', $legacy_fr->get_error_code());
    }

    /**
     * @dataProvider translatedNameProvider
     */
    public function test_translated_names_are_accepted_and_map_to_the_english_slug(string $name, string $slug): void {
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', $name));
        $this->assertSame($slug, intersoccer_attr_program_season_slug_for_label($name));
        $this->assertSame($slug, intersoccer_attr_program_season_slug_for_label($slug));

        $inserted = intersoccer_attr_insert_term_english_slug(
            ['name' => $name, 'slug' => 'ignored-local-slug'],
            'pa_program-season',
            []
        );
        $this->assertSame($slug, $inserted['slug']);
    }

    public static function translatedNameProvider(): array {
        return [
            'fr winter straight apostrophe' => ["Cours d'hiver", 'winter-courses'],
            'fr winter typographic apostrophe' => ["Cours d\u{2019}hiver", 'winter-courses'],
            'fr winter entity apostrophe' => ['Cours d&#039;hiver', 'winter-courses'],
            'de winter' => ['Winterkurse', 'winter-courses'],
            'fr spring summer' => ['Cours printemps-été', 'spring-summer-courses'],
            'fr spring summer nfd' => ["Cours printemps-e\u{0301}te\u{0301}", 'spring-summer-courses'],
            'de spring summer' => ['Frühlings-/Sommerkurse', 'spring-summer-courses'],
            'de spring summer nfd' => ["Fru\u{0308}hlings-/Sommerkurse", 'spring-summer-courses'],
            'fr easter' => ['Camps de Pâques', 'easter-camps'],
            'fr easter nfd' => ["Camps de Pa\u{0302}ques", 'easter-camps'],
            'de easter' => ['Osterferienlager', 'easter-camps'],
        ];
    }

    /**
     * @dataProvider yearQualifiedProvider
     */
    public function test_year_qualified_forms_stay_blocked(string $name): void {
        $result = intersoccer_attr_validate_new_term('pa_program-season', $name);
        $this->assertInstanceOf(WP_Error::class, $result, $name);
        $this->assertSame('year_qualified_season', $result->get_error_code(), $name);
        $this->assertTrue(intersoccer_pm_is_year_qualified_season_label($name), $name);
    }

    public static function yearQualifiedProvider(): array {
        return [
            ['Winter Courses 2027'],
            ['Easter Camps 2026'],
            ["Cours d'hiver 2027"],
            ["Cours d\u{2019}hiver 2027"],
            ['Winterkurse 2026/27'],
            ['Spring/Summer Courses 2027'],
            ['Frühlings-/Sommerkurse 2026'],
            ['Camps de Pâques 2026'],
            ['Osterferienlager 2027'],
            ['Autumn 2027'],
            ['autumn-2027'],
        ];
    }

    public function test_calendar_order_places_new_seasons_beside_winter_and_spring(): void {
        $order = [
            'Winter',
            'Winter Courses',
            'Spring',
            'Easter Camps',
            'Spring/Summer Courses',
            'Summer',
            'Autumn',
        ];
        $previous = null;
        foreach ($order as $label) {
            $rank = intersoccer_attr_program_season_sort_rank($label);
            $this->assertNotNull($rank, $label);
            if ($previous !== null) {
                $this->assertGreaterThan($previous, $rank, $label);
            }
            $previous = $rank;
        }
        $this->assertSame(
            intersoccer_attr_program_season_sort_rank('Easter Camps'),
            intersoccer_attr_program_season_sort_rank('Camps de Pâques')
        );

        $this->assertSame(
            intersoccer_attr_program_season_sort_rank('Winter Courses'),
            intersoccer_attr_program_season_sort_rank("Cours d'hiver")
        );
        $this->assertSame(11, intersoccer_attr_program_season_sort_rank('Winter Courses'));
        $this->assertSame(21, intersoccer_attr_program_season_sort_rank('Easter Camps'));
        $this->assertSame(25, intersoccer_attr_program_season_sort_rank('Spring/Summer Courses'));
        $this->assertNull(intersoccer_attr_program_season_sort_rank('Automne'));
    }

    public function test_activity_and_calendar_anchor(): void {
        $this->assertSame('course', intersoccer_attr_program_season_activity_type('Winter Courses'));
        $this->assertSame('course', intersoccer_attr_program_season_activity_type('Winterkurse'));
        $this->assertSame('course', intersoccer_attr_program_season_activity_type("Cours d'hiver"));
        $this->assertSame('course', intersoccer_attr_program_season_activity_type('Spring/Summer Courses'));
        $this->assertSame('course', intersoccer_attr_program_season_activity_type('Frühlings-/Sommerkurse'));
        $this->assertSame('camp', intersoccer_attr_program_season_activity_type('Easter Camps'));
        $this->assertSame('camp', intersoccer_attr_program_season_activity_type('Osterferienlager'));
        $this->assertSame('camp', intersoccer_attr_program_season_activity_type('Camps de Pâques'));

        foreach (['Summer', 'Autumn', 'Spring', 'Winter'] as $bare) {
            $this->assertSame('', intersoccer_attr_program_season_activity_type($bare), $bare);
        }

        $this->assertSame('winter', intersoccer_attr_program_season_calendar_anchor('Winter'));
        $this->assertSame('winter', intersoccer_attr_program_season_calendar_anchor('Winter Courses'));
        $this->assertSame('winter', intersoccer_attr_program_season_calendar_anchor('Winterkurse'));
        $this->assertSame('spring', intersoccer_attr_program_season_calendar_anchor('Spring'));
        $this->assertSame('spring', intersoccer_attr_program_season_calendar_anchor('Easter Camps'));
        $this->assertSame('spring-summer', intersoccer_attr_program_season_calendar_anchor('Spring/Summer Courses'));
        $this->assertSame('spring-summer', intersoccer_attr_program_season_calendar_anchor('Cours printemps-été'));
        $this->assertSame('summer', intersoccer_attr_program_season_calendar_anchor('Summer'));
        $this->assertSame('autumn', intersoccer_attr_program_season_calendar_anchor('Autumn'));
    }

    public function test_year_extraction_matches_the_bare_season_for_evergreen_names(): void {
        $this->assertSame(
            intersoccer_pv_year_from_program_season('Winter'),
            intersoccer_pv_year_from_program_season('Winter Courses')
        );
        $this->assertSame(
            intersoccer_pv_year_from_program_season('Spring'),
            intersoccer_pv_year_from_program_season('Easter Camps')
        );
        $this->assertSame(
            intersoccer_pv_year_from_program_season('Spring'),
            intersoccer_pv_year_from_program_season('Spring/Summer Courses')
        );
        $this->assertFalse(intersoccer_pm_is_year_qualified_season_label('Winter Courses'));
        $this->assertFalse(intersoccer_pm_is_year_qualified_season_label('Easter Camps'));
        $this->assertFalse(intersoccer_pm_is_year_qualified_season_label('Spring/Summer Courses'));
    }

    public function test_discount_keys_stay_separate_from_bare_seasons_and_share_translations(): void {
        $this->assertSame('autumn|2026', intersoccer_discount_compose_season_key('Autumn', '2026'));
        $this->assertSame('winter|2026', intersoccer_discount_compose_season_key('Winter', '2026'));
        $this->assertSame('spring|2026', intersoccer_discount_compose_season_key('Spring', '2026'));
        $this->assertSame('summer|2026', intersoccer_discount_compose_season_key('Summer', '2026'));

        $this->assertSame('winter courses|2026', intersoccer_discount_compose_season_key('Winter Courses', '2026'));
        $this->assertSame('easter camps|2026', intersoccer_discount_compose_season_key('Easter Camps', '2026'));
        $this->assertSame(
            'spring/summer courses|2026',
            intersoccer_discount_compose_season_key('Spring/Summer Courses', '2026')
        );

        $this->assertNotSame(
            intersoccer_discount_compose_season_key('Winter', '2026'),
            intersoccer_discount_compose_season_key('Winter Courses', '2026')
        );
        $this->assertNotSame(
            intersoccer_discount_compose_season_key('Spring', '2026'),
            intersoccer_discount_compose_season_key('Easter Camps', '2026')
        );
        $this->assertNotSame(
            intersoccer_discount_compose_season_key('Spring', '2026'),
            intersoccer_discount_compose_season_key('Spring/Summer Courses', '2026')
        );
        $this->assertNotSame(
            intersoccer_discount_compose_season_key('Summer', '2026'),
            intersoccer_discount_compose_season_key('Spring/Summer Courses', '2026')
        );
        $this->assertNotSame(
            intersoccer_discount_compose_season_key('Winter Courses', '2026'),
            intersoccer_discount_compose_season_key('Winter Courses', '2027')
        );

        $this->assertSame('Winter Courses', intersoccer_discount_canonicalize_season_label("Cours d'hiver"));
        $this->assertSame('Winter Courses', intersoccer_discount_canonicalize_season_label('Winterkurse'));
        $this->assertSame('Easter Camps', intersoccer_discount_canonicalize_season_label('Osterferienlager'));
        $this->assertSame(
            'Spring/Summer Courses',
            intersoccer_discount_canonicalize_season_label('Frühlings-/Sommerkurse')
        );
        $this->assertSame(
            intersoccer_discount_compose_season_key('Winter Courses', '2027'),
            intersoccer_discount_compose_season_key(
                intersoccer_discount_canonicalize_season_label("Cours d'hiver"),
                '2027'
            )
        );
        $this->assertSame(
            'winter courses|2026',
            intersoccer_discount_normalize_season_key("cours d'hiver|2026")
        );

        $this->assertSame('Autumn', intersoccer_discount_canonicalize_season_label('Autumn'));
        $this->assertSame('Automne', intersoccer_discount_canonicalize_season_label('Automne'));
        $this->assertSame('Herbst', intersoccer_discount_canonicalize_season_label('Herbst'));
    }

    public function test_original_season_slug_is_not_rewritten_for_unmapped_translations(): void {
        $autumn = intersoccer_attr_insert_term_english_slug(
            ['name' => 'Autumn', 'slug' => 'custom'],
            'pa_program-season',
            []
        );
        $this->assertSame('autumn', $autumn['slug']);

        $automne = intersoccer_attr_insert_term_english_slug(
            ['name' => 'Automne', 'slug' => 'automne'],
            'pa_program-season',
            []
        );
        $this->assertSame(intersoccer_attr_english_term_slug('Automne'), $automne['slug']);
        $this->assertNotSame('autumn', $automne['slug']);
    }

    public function test_customer_order_meta_translates_new_english_season_names(): void {
        $labels = intersoccer_attr_program_season_customer_labels();
        foreach (['Summer', 'Autumn', 'Spring', 'Winter', 'Winter Courses', 'Spring/Summer Courses', 'Easter Camps'] as $name) {
            $this->assertContains($name, $labels);
            $this->assertSame($name, intersoccer_pv_translate_order_meta_token_for_display($name));
        }

        $fr = (string) file_get_contents(dirname(__DIR__) . '/lang/intersoccer-product-variations-fr_FR.po');
        $de = (string) file_get_contents(dirname(__DIR__) . '/lang/intersoccer-product-variations-de_DE.po');
        $this->assertStringContainsString("msgstr \"Cours d'hiver\"", $fr);
        $this->assertStringContainsString('msgstr "Cours printemps-été"', $fr);
        $this->assertStringContainsString('msgstr "Camps de Pâques"', $fr);
        $this->assertStringContainsString('msgstr "Winterkurse"', $de);
        $this->assertStringContainsString('msgstr "Frühlings-/Sommerkurse"', $de);
        $this->assertStringContainsString('msgstr "Osterferienlager"', $de);
    }
}
