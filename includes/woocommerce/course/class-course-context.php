<?php
/**
 * Course context DTO.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('InterSoccer_Course_Context')) {
    class InterSoccer_Course_Context {
        /** @var int */
        protected $product_id;
        /** @var int */
        protected $variation_id;
        /** @var int */
        protected $canonical_variation_id;
        /** @var float */
        protected $base_price;
        /** @var int */
        protected $total_weeks;
        /** @var float */
        protected $session_rate;
        /** @var string|null */
        protected $start_date;
        /** @var array */
        protected $holidays;

        /**
         * @param int         $product_id
         * @param int         $variation_id
         * @param int         $canonical_variation_id
         * @param float       $base_price
         * @param int         $total_weeks
         * @param float       $session_rate
         * @param string|null $start_date
         * @param array       $holidays
         */
        public function __construct($product_id, $variation_id, $canonical_variation_id, $base_price, $total_weeks, $session_rate, $start_date, array $holidays) {
            $this->product_id = (int) $product_id;
            $this->variation_id = (int) $variation_id;
            $this->canonical_variation_id = (int) $canonical_variation_id;
            $this->base_price = (float) $base_price;
            $this->total_weeks = max(0, (int) $total_weeks);
            $this->session_rate = (float) $session_rate;
            $this->start_date = $start_date ?: null;
            $this->holidays = array_values($holidays);
        }

        public function get_product_id() {
            return $this->product_id;
        }

        public function get_variation_id() {
            return $this->variation_id;
        }

        public function get_canonical_variation_id() {
            return $this->canonical_variation_id ?: $this->variation_id ?: $this->product_id;
        }

        public function get_base_price() {
            return $this->base_price;
        }

        public function get_total_weeks() {
            return $this->total_weeks;
        }

        public function get_session_rate() {
            return $this->session_rate;
        }

        public function get_start_date() {
            return $this->start_date;
        }

        public function get_holidays() {
            return $this->holidays;
        }

        /**
         * Build a deterministic signature based on pricing meta.
         *
         * @return string
         */
        public function get_meta_signature() {
            return implode('|', [
                $this->base_price,
                $this->total_weeks,
                $this->session_rate,
                $this->start_date ?: 'no-start',
                md5(json_encode($this->holidays))
            ]);
        }

        /**
         * Build the cache key for storing computed prices.
         *
         * @param string $current_date
         * @return string
         */
        public function build_cache_key($current_date) {
            return implode('|', [
                $this->get_canonical_variation_id(),
                $this->get_meta_signature(),
                $current_date
            ]);
        }
    }
}
