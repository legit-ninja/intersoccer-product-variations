<?php
/**
 * Regression: localized pa_course-day slugs resolve via WPML EN twin for proration.
 */

use PHPUnit\Framework\TestCase;

class CourseScheduleCalculatorWpmlTest extends TestCase {
    private const TAXONOMY = 'pa_course-day';
    private const EN_TERM_ID = 16701;
    private const FR_TERM_ID = 16702;
    private const DE_TERM_ID = 16703;

    /** @var array<string,mixed> */
    private $saved_terms;

    /** @var mixed */
    private $saved_wpml_filter_flag;

    /** @var mixed */
    private $saved_wpml_current_language;

    protected function setUp(): void {
        parent::setUp();
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        if (class_exists('MockMetaData')) {
            MockMetaData::reset();
        }

        $this->saved_terms = $GLOBALS['intersoccer_test_terms'] ?? [];
        $this->saved_wpml_filter_flag = $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'] ?? null;
        $this->saved_wpml_current_language = $GLOBALS['intersoccer_test_wpml_current_language'] ?? null;

        $GLOBALS['intersoccer_test_terms'] = [
            self::EN_TERM_ID => [
                'slug' => 'wednesday',
                'name' => 'Wednesday',
                'taxonomy' => self::TAXONOMY,
                'wpml_lang' => 'en',
            ],
            self::FR_TERM_ID => [
                'slug' => 'mercredi',
                'name' => 'Mercredi',
                'taxonomy' => self::TAXONOMY,
                'wpml_lang' => 'fr',
            ],
            self::DE_TERM_ID => [
                'slug' => 'mittwou',
                'name' => 'Mittwoch',
                'taxonomy' => self::TAXONOMY,
                'wpml_lang' => 'de',
            ],
        ];

        MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
            if ($type !== self::TAXONOMY || $lang !== 'en') {
                return $id;
            }
            $map = [
                self::FR_TERM_ID => self::EN_TERM_ID,
                self::DE_TERM_ID => self::EN_TERM_ID,
                self::EN_TERM_ID => self::EN_TERM_ID,
            ];
            $id = (int) $id;
            return $map[$id] ?? $id;
        };
    }

    protected function tearDown(): void {
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        $GLOBALS['intersoccer_test_terms'] = $this->saved_terms;
        if ($this->saved_wpml_filter_flag === null) {
            unset($GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language']);
        } else {
            $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'] = $this->saved_wpml_filter_flag;
        }
        if ($this->saved_wpml_current_language === null) {
            unset($GLOBALS['intersoccer_test_wpml_current_language']);
        } else {
            $GLOBALS['intersoccer_test_wpml_current_language'] = $this->saved_wpml_current_language;
        }
        unset($GLOBALS['intersoccer_test_wpml_switch_language_count']);
        parent::tearDown();
    }

    /**
     * @return array<string,array{0:string,1:int}>
     */
    public static function localizedWednesdaySlugsProvider(): array {
        return [
            'english wednesday' => ['wednesday', self::EN_TERM_ID],
            'french mercredi' => ['mercredi', self::FR_TERM_ID],
            'german mittwou' => ['mittwou', self::DE_TERM_ID],
        ];
    }

    /**
     * @dataProvider localizedWednesdaySlugsProvider
     */
    public function testLocalizedCourseDaySlugProratesLikeEnglish($attribute_slug, $term_id) {
        $variation_id = 16710 + (int) $term_id;
        $start_date = '2025-09-03';
        $total_weeks = 15;
        $session_rate = 21.6;
        $base_price = 283.50;
        $holiday = ['2025-10-22'];
        $current_date = '2025-11-10';

        update_post_meta($variation_id, 'attribute_pa_course-day', $attribute_slug);

        $context = new InterSoccer_Course_Context(
            16700,
            $variation_id,
            $variation_id,
            $base_price,
            $total_weeks,
            $session_rate,
            $start_date,
            $holiday
        );

        $schedule = new InterSoccer_Course_Schedule_Calculator();
        $this->assertSame(3, $schedule->get_course_day($context));

        $remaining = $schedule->calculate_remaining_sessions($context, $current_date);
        $this->assertSame(6, $remaining);

        $pricing = new InterSoccer_Course_Pricing_Calculator($schedule);
        $result = $pricing->calculate_price($context, null, $current_date);
        $this->assertSame(6, $result['remaining_sessions']);
        $this->assertEqualsWithDelta(129.6, $result['price'], 0.01);
    }

    public function testGermanSlugProratesWhenCurrentLanguageIsEnglish() {
        $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'] = true;
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'en';
        $GLOBALS['intersoccer_test_wpml_switch_language_count'] = 0;
        MockFilters::$filters['wpml_current_language'] = static function () {
            return $GLOBALS['intersoccer_test_wpml_current_language'] ?? 'en';
        };

        $this->assertFalse(get_term_by('slug', 'mittwou', self::TAXONOMY));

        $variation_id = 16720;
        $start_date = '2025-09-03';
        $total_weeks = 15;
        $session_rate = 21.6;
        $base_price = 283.50;
        $holiday = ['2025-10-22'];
        $current_date = '2025-11-10';

        update_post_meta($variation_id, 'attribute_pa_course-day', 'mittwou');

        $context = new InterSoccer_Course_Context(
            16700,
            $variation_id,
            $variation_id,
            $base_price,
            $total_weeks,
            $session_rate,
            $start_date,
            $holiday
        );

        $schedule = new InterSoccer_Course_Schedule_Calculator();
        $this->assertSame(3, $schedule->get_course_day($context));
        $this->assertSame(2, (int) ($GLOBALS['intersoccer_test_wpml_switch_language_count'] ?? 0));
        $this->assertSame('en', (string) $GLOBALS['intersoccer_test_wpml_current_language']);

        $remaining = $schedule->calculate_remaining_sessions($context, $current_date);
        $this->assertSame(6, $remaining);

        $pricing = new InterSoccer_Course_Pricing_Calculator($schedule);
        $result = $pricing->calculate_price($context, null, $current_date);
        $this->assertSame(6, $result['remaining_sessions']);
        $this->assertEqualsWithDelta(129.6, $result['price'], 0.01);
    }

    public function testSlugMapFallbackWhenWpmlDoesNotResolveTerm() {
        MockFilters::reset();
        $GLOBALS['intersoccer_test_terms'] = [];

        $variation_id = 16799;
        update_post_meta($variation_id, 'attribute_pa_course-day', 'wednesday');

        $context = new InterSoccer_Course_Context(
            16700,
            $variation_id,
            $variation_id,
            100.0,
            10,
            0.0,
            '2026-01-07',
            []
        );

        $schedule = new InterSoccer_Course_Schedule_Calculator();
        $this->assertSame(3, $schedule->get_course_day($context));
    }

    public function testSlugMapFallbackGermanShortSlugsWhenWpmlAbsent() {
        MockFilters::reset();
        $GLOBALS['intersoccer_test_terms'] = [];

        $schedule = new InterSoccer_Course_Schedule_Calculator();
        $map = intersoccer_weekday_slug_map();
        $this->assertSame(3, $map['mittwou']);
        $this->assertSame(1, $map['mantig']);
        $this->assertSame(5, $map['fritig']);

        $cases = [
            ['mittwou', 3],
            ['mantig', 1],
            ['fritig', 5],
        ];

        foreach ($cases as [$slug, $expected_day]) {
            $variation_id = 16800 + $expected_day;
            update_post_meta($variation_id, 'attribute_pa_course-day', $slug);
            $context = new InterSoccer_Course_Context(
                16700,
                $variation_id,
                $variation_id,
                100.0,
                10,
                0.0,
                '2026-01-07',
                []
            );
            $this->assertSame($expected_day, $schedule->get_course_day($context), $slug);
        }
    }
}
