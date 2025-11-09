<?php
/**
 * Course schedule calculator utilities.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('InterSoccer_Course_Schedule_Calculator')) {
    class InterSoccer_Course_Schedule_Calculator {
        /**
         * Determine the numeric course day from the variation attributes.
         *
         * @param InterSoccer_Course_Context $context
         * @return int
         */
        public function get_course_day(InterSoccer_Course_Context $context) {
            $variation_id = $context->get_variation_id() ?: $context->get_product_id();
            $attribute_slug = get_post_meta($variation_id, 'attribute_pa_course-day', true);
            if (!$attribute_slug) {
                return 0;
            }

            $day_map = [
                'monday' => 1,
                'tuesday' => 2,
                'wednesday' => 3,
                'thursday' => 4,
                'friday' => 5,
                'saturday' => 6,
                'sunday' => 7,
                'lundi' => 1,
                'mardi' => 2,
                'mercredi' => 3,
                'jeudi' => 4,
                'vendredi' => 5,
                'samedi' => 6,
                'dimanche' => 7,
                'montag' => 1,
                'dienstag' => 2,
                'mittwoch' => 3,
                'donnerstag' => 4,
                'freitag' => 5,
                'samstag' => 6,
                'sonntag' => 7,
            ];

            return $day_map[strtolower($attribute_slug)] ?? 0;
        }

        /**
         * Calculate remaining sessions based on current date.
         *
         * @param InterSoccer_Course_Context $context
         * @param string|null                $current_date
         * @return int
         */
        public function calculate_remaining_sessions(InterSoccer_Course_Context $context, $current_date = null) {
            $total_weeks = $context->get_total_weeks();
            if ($total_weeks <= 0) {
                return 0;
            }

            $start_date = $context->get_start_date();
            if (!$start_date) {
                return $total_weeks;
            }

            $course_day = $this->get_course_day($context);
            if (!$course_day) {
                return $total_weeks;
            }

            $current_date = $current_date ?: $this->get_current_date();
            $current = new DateTime($current_date);
            $start = new DateTime($start_date);

            if ($current < $start) {
                return $total_weeks;
            }

            $end_date = $this->calculate_end_date($context);
            if (!$end_date) {
                return $total_weeks;
            }

            $end = new DateTime($end_date);
            if ($current > $end) {
                return 0;
            }

            $remaining = 0;
            $date = clone $current;
            $holiday_set = array_flip($context->get_holidays());

            while ($date <= $end) {
                $day = $date->format('Y-m-d');
                $day_of_week = (int) $date->format('N');

                if ($day_of_week === $course_day && !isset($holiday_set[$day])) {
                    $remaining++;
                }
                $date->add(new DateInterval('P1D'));
            }

            return max(0, min($remaining, $total_weeks));
        }

        /**
         * Calculate the total sessions for the course (total weeks adjusted for holidays).
         *
         * @param InterSoccer_Course_Context $context
         * @return int
         */
        public function calculate_total_sessions(InterSoccer_Course_Context $context) {
            $total_weeks = $context->get_total_weeks();
            if ($total_weeks <= 0) {
                return 0;
            }

            $start_date = $context->get_start_date();
            if (!$start_date) {
                return $total_weeks;
            }

            $course_day = $this->get_course_day($context);
            if (!$course_day) {
                return $total_weeks;
            }

            $current = new DateTime($start_date);
            $holiday_set = array_flip($context->get_holidays());
            $sessions = 0;
            $days_checked = 0;

            while ($sessions < $total_weeks && $days_checked < ($total_weeks * 7 * 2)) {
                $day = $current->format('Y-m-d');
                $day_of_week = (int) $current->format('N');

                if ($day_of_week === $course_day && !isset($holiday_set[$day])) {
                    $sessions++;
                }

                $current->add(new DateInterval('P1D'));
                $days_checked++;
            }

            return $sessions;
        }

        /**
         * Calculate the projected end date for the course.
         *
         * @param InterSoccer_Course_Context $context
         * @return string|null Y-m-d formatted date or null on failure.
         */
        public function calculate_end_date(InterSoccer_Course_Context $context) {
            $total_weeks = $context->get_total_weeks();
            if ($total_weeks <= 0) {
                return null;
            }

            $start_date = $context->get_start_date();
            if (!$start_date) {
                return null;
            }

            $course_day = $this->get_course_day($context);
            if (!$course_day) {
                return null;
            }

            $holiday_set = array_flip($context->get_holidays());
            $sessions_needed = $total_weeks;
            $current_date = new DateTime($start_date);
            $weeks_counted = 0;
            $days_checked = 0;

            while ($weeks_counted < $sessions_needed && $days_checked < ($total_weeks * 7 * 2)) {
                $day_of_week = (int) $current_date->format('N');
                $current_day = $current_date->format('Y-m-d');

                if ($day_of_week === $course_day && !isset($holiday_set[$current_day])) {
                    $weeks_counted++;
                }

                $current_date->add(new DateInterval('P1D'));
                $days_checked++;
            }

            if ($weeks_counted === 0) {
                return null;
            }

            $current_date->sub(new DateInterval('P1D'));
            return $current_date->format('Y-m-d');
        }

        /**
         * Helper to get current date in site timezone.
         *
         * @return string
         */
        protected function get_current_date() {
            return function_exists('current_time') ? current_time('Y-m-d') : date('Y-m-d');
        }
    }
}
