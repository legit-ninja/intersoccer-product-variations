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

    protected function setUp(): void {
        parent::setUp();
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        if (class_exists('MockMetaData')) {
            MockMetaData::reset();
        }

        $this->saved_terms = $GLOBALS['intersoccer_test_terms'] ?? [];
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

    public function testSlugMapFallbackWhenWpmlDoesNotResolveTerm() {
        MockFilters::reset();

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
}
