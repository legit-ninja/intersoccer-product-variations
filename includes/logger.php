<?php
/**
 * File: includes/logger.php
 * Description: Provides a PSR-3 compatible logger wrapper for the InterSoccer plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('InterSoccer_Logger')) {
    class InterSoccer_Logger {
        /** @var \Psr\Log\LoggerInterface|null */
        protected static $logger = null;

        /**
         * Retrieve the underlying logger instance.
         *
         * @return \Psr\Log\LoggerInterface|null
         */
        protected static function get_logger() {
            if (self::$logger !== null) {
                return self::$logger;
            }

            if (function_exists('wc_get_logger')) {
                self::$logger = wc_get_logger();
            }

            return self::$logger;
        }

        /**
         * Log a message with a given level.
         *
         * @param string $level   Log level as defined by PSR-3.
         * @param string $message Message to log.
         * @param array  $context Optional context array.
         * @return void
         */
        public static function log($level, $message, array $context = []) {
            $logger = self::get_logger();
            $context = array_merge(['source' => 'intersoccer-product-variations'], $context);

            if ($logger) {
                $logger->log($level, $message, $context);
                return;
            }

            error_log('InterSoccer [' . strtoupper($level) . ']: ' . self::interpolate($message, $context));
        }

        public static function debug($message, array $context = []) {
            self::log('debug', $message, $context);
        }

        public static function info($message, array $context = []) {
            self::log('info', $message, $context);
        }

        public static function notice($message, array $context = []) {
            self::log('notice', $message, $context);
        }

        public static function warning($message, array $context = []) {
            self::log('warning', $message, $context);
        }

        public static function error($message, array $context = []) {
            self::log('error', $message, $context);
        }

        public static function critical($message, array $context = []) {
            self::log('critical', $message, $context);
        }

        protected static function interpolate($message, array $context) {
            if (false === strpos($message, '{')) {
                return $message;
            }

            $replace = [];
            foreach ($context as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $value = wp_json_encode($value);
                }
                $replace['{' . $key . '}'] = $value;
            }

            return strtr($message, $replace);
        }
    }
}

if (!function_exists('intersoccer_log')) {
    function intersoccer_log($level, $message, array $context = []) {
        InterSoccer_Logger::log($level, $message, $context);
    }
}

if (!function_exists('intersoccer_debug')) {
    function intersoccer_debug($message, array $context = []) {
        InterSoccer_Logger::debug($message, $context);
    }
}

if (!function_exists('intersoccer_info')) {
    function intersoccer_info($message, array $context = []) {
        InterSoccer_Logger::info($message, $context);
    }
}

if (!function_exists('intersoccer_warning')) {
    function intersoccer_warning($message, array $context = []) {
        InterSoccer_Logger::warning($message, $context);
    }
}

if (!function_exists('intersoccer_error')) {
    function intersoccer_error($message, array $context = []) {
        InterSoccer_Logger::error($message, $context);
    }
}
