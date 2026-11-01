<?php
/**
 * Localized date_i18n for PHPUnit via $GLOBALS['intersoccer_test_date_i18n_override'].
 */

class IntersoccerPvTestDateI18n {
    /** @var callable|null */
    private static $previous_override = null;

    /** @var string en|fr|de */
    private static $locale = 'en';

    /** @var array<string,string> */
    private const EN_TO_FR = [
        'January' => 'janvier',
        'February' => 'février',
        'March' => 'mars',
        'April' => 'avril',
        'May' => 'mai',
        'June' => 'juin',
        'July' => 'juillet',
        'August' => 'août',
        'September' => 'septembre',
        'October' => 'octobre',
        'November' => 'novembre',
        'December' => 'décembre',
    ];

    /** @var array<string,string> */
    private const EN_TO_DE = [
        'January' => 'Januar',
        'February' => 'Februar',
        'March' => 'März',
        'April' => 'April',
        'May' => 'Mai',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'August',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Dezember',
    ];

    public static function set_locale(string $locale): void {
        self::$locale = $locale;
    }

    public static function enable(): void {
        if (self::$previous_override === null) {
            self::$previous_override = $GLOBALS['intersoccer_test_date_i18n_override'] ?? null;
        }
        $GLOBALS['intersoccer_test_date_i18n_override'] = [self::class, 'format'];
    }

    public static function enable_de_customer_date_format_gettext(): void {
        if (!class_exists('MockFilters')) {
            return;
        }
        MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
            unset($domain);
            if ($text === 'j F Y') {
                return 'j. F Y';
            }
            return $translation;
        };
    }

    public static function reset(): void {
        if (self::$previous_override === null) {
            unset($GLOBALS['intersoccer_test_date_i18n_override']);
        } else {
            $GLOBALS['intersoccer_test_date_i18n_override'] = self::$previous_override;
            self::$previous_override = null;
        }
        self::$locale = 'en';
    }

    /**
     * @param mixed $timestamp
     * @param mixed $gmt
     */
    public static function format($format, $timestamp = false, $gmt = false): string {
        unset($gmt);
        $ts = (int) $timestamp;
        $day = (int) date('j', $ts);
        $year = date('Y', $ts);
        $en_month = date('F', $ts);
        $format = (string) $format;

        if (self::$locale === 'fr') {
            $month = self::EN_TO_FR[$en_month] ?? strtolower($en_month);
            return $day . ' ' . $month . ' ' . $year;
        }

        if (self::$locale === 'de') {
            $month = self::EN_TO_DE[$en_month] ?? $en_month;
            if (str_contains($format, 'j. F')) {
                return $day . '. ' . $month . ' ' . $year;
            }
            return $day . ' ' . $month . ' ' . $year;
        }

        return date($format, $ts);
    }
}
