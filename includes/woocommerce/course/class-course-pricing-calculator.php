<?php
/**
 * Course pricing calculator.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('InterSoccer_Course_Pricing_Calculator')) {
    class InterSoccer_Course_Pricing_Calculator {
        /** @var InterSoccer_Course_Schedule_Calculator */
        protected $schedule_calculator;

        public function __construct(InterSoccer_Course_Schedule_Calculator $schedule_calculator) {
            $this->schedule_calculator = $schedule_calculator;
        }

        /**
         * Compute a course price given context and optional remaining session hint.
         *
         * @param InterSoccer_Course_Context $context
         * @param int|null                   $remaining_sessions_hint
         * @param string                     $current_date
         * @return array{price:float,remaining_sessions:int}
         */
        public function calculate_price(InterSoccer_Course_Context $context, $remaining_sessions_hint, $current_date) {
            $base_price = $context->get_base_price();
            $total_weeks = $context->get_total_weeks();

            if ($total_weeks <= 0) {
                return [
                    'price' => $base_price,
                    'remaining_sessions' => 0,
                    'guard' => 'missing_total_weeks',
                ];
            }

            $start_date = $context->get_start_date();
            if ($start_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
                $start = new DateTime($start_date);
                $current = new DateTime($current_date);
                if ($current < $start) {
                    return [
                        'price' => $base_price,
                        'remaining_sessions' => $total_weeks,
                        'guard' => 'future_start',
                    ];
                }
            }

            $remaining_sessions = $this->schedule_calculator->calculate_remaining_sessions($context, $current_date);

            if (!is_null($remaining_sessions_hint) && $remaining_sessions_hint !== $remaining_sessions) {
                // favour calculated value; hint is ignored intentionally
            }

            $session_rate = $context->get_session_rate();
            $price = $base_price;

            if ($session_rate > 0 && $remaining_sessions > 0) {
                $price = round($session_rate * $remaining_sessions, 2);
                return [
                    'price' => $price,
                    'remaining_sessions' => $remaining_sessions,
                    'guard' => null,
                ];
            }

            $total_sessions = $this->schedule_calculator->calculate_total_sessions($context);
            if ($total_sessions > 0 && $remaining_sessions > 0 && $remaining_sessions <= $total_sessions) {
                if ($remaining_sessions < $total_sessions) {
                    $price = round($base_price * ($remaining_sessions / $total_sessions), 2);
                }
            }

            return [
                'price' => max(0, $price),
                'remaining_sessions' => $remaining_sessions,
                'guard' => null,
            ];
        }
    }
}
