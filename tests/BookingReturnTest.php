<?php
/**
 * Same-site return to the product the parent was booking.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('home_url')) {
    function home_url($path = '') {
        return 'https://example.test' . $path;
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($post = 0) {
        return 'https://example.test/product/camp/';
    }
}

if (!function_exists('wp_validate_redirect')) {
    function wp_validate_redirect($location, $fallback_url = '') {
        $GLOBALS['intersoccer_pv_wp_validate_redirect_calls'][] = $location;
        $parts = parse_url((string) $location);
        if (!is_array($parts)) {
            return $fallback_url;
        }
        if (isset($parts['scheme']) && !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return $fallback_url;
        }
        if (isset($parts['host'])) {
            $home = parse_url(home_url('/'));
            $home_host = is_array($home) && isset($home['host']) ? strtolower((string) $home['host']) : '';
            if (strtolower((string) $parts['host']) !== $home_host) {
                return $fallback_url;
            }
        }
        return $location;
    }
}

if (!function_exists('wc_format_coupon_code')) {
    function wc_format_coupon_code($value) {
        $value = sanitize_text_field((string) $value);
        return strtolower($value);
    }
}

if (!function_exists('WC')) {
    function WC() {
        if (!isset($GLOBALS['intersoccer_days_gate_wc']) || !is_object($GLOBALS['intersoccer_days_gate_wc'])) {
            $GLOBALS['intersoccer_days_gate_wc'] = (object) ['session' => null, 'cart' => null];
        }
        return $GLOBALS['intersoccer_days_gate_wc'];
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []) {
        if (!is_array($args)) {
            return $defaults;
        }
        return array_merge($defaults, $args);
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses($string, $allowed_html, $allowed_protocols = []) {
        return (string) $string;
    }
}

if (!function_exists('intersoccer_get_user_players')) {
    function intersoccer_get_user_players($user_id) {
        return isset($GLOBALS['intersoccer_test_user_players']) && is_array($GLOBALS['intersoccer_test_user_players'])
            ? $GLOBALS['intersoccer_test_user_players']
            : [];
    }
}

class BookingReturnSession {
    public $data = [];

    public function get($key, $default = null) {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set($key, $value) {
        $this->data[$key] = $value;
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/booking-return.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/language-helpers.php';

class BookingReturnTest extends TestCase {
    /** @var array<string,mixed> */
    private $previousGet = [];

    /** @var array<string,mixed> */
    private $previousPost = [];

    /** @var BookingReturnSession */
    private $session;

    protected function setUp(): void {
        parent::setUp();
        $this->previousGet = $_GET;
        $this->previousPost = $_POST;
        $_GET = [];
        $_POST = [];
        MockFilters::reset();
        $GLOBALS['intersoccer_pv_wp_validate_redirect_calls'] = [];
        $GLOBALS['intersoccer_test_user_players'] = [];
        $GLOBALS['intersoccer_test_user_id'] = 5;
        $GLOBALS['intersoccer_pv_test_textdomain_strings'] = [];
        if (!isset($GLOBALS['intersoccer_days_gate_wc']) || !is_object($GLOBALS['intersoccer_days_gate_wc'])) {
            $GLOBALS['intersoccer_days_gate_wc'] = (object) ['session' => null, 'cart' => null];
        }
        $this->session = new BookingReturnSession();
        $GLOBALS['intersoccer_days_gate_wc']->session = $this->session;
        $GLOBALS['product'] = new class {
            public function get_id() {
                return 42;
            }
        };
    }

    protected function tearDown(): void {
        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
        unset($GLOBALS['product']);
        unset($GLOBALS['intersoccer_test_products'], $GLOBALS['intersoccer_pv_test_matching_variation']);
        unset(
            $GLOBALS['intersoccer_test_terms'],
            $GLOBALS['intersoccer_test_wpml_current_language'],
            $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'],
            $GLOBALS['intersoccer_test_wpml_switch_language_count']
        );
        MockFilters::reset();
        parent::tearDown();
    }

    public function test_validate_return_url_accepts_same_site_and_rejects_open_redirects(): void {
        $this->assertSame(
            'https://example.test/product/camp/',
            intersoccer_pv_validate_return_url('https://example.test/product/camp/')
        );
        $this->assertSame(
            'http://example.test/product/camp/',
            intersoccer_pv_validate_return_url('http://example.test/product/camp/')
        );
        $this->assertSame(
            '/product/camp/?bf_return=1',
            intersoccer_pv_validate_return_url('/product/camp/?bf_return=1')
        );
        $this->assertNotEmpty($GLOBALS['intersoccer_pv_wp_validate_redirect_calls']);

        $this->assertSame('', intersoccer_pv_validate_return_url('https://evil.example/product/'));
        $this->assertSame('', intersoccer_pv_validate_return_url('//evil.example/product/'));
        $this->assertSame('', intersoccer_pv_validate_return_url('javascript:alert(1)'));
        $this->assertSame('', intersoccer_pv_validate_return_url('data:text/html,hi'));
        $this->assertSame('', intersoccer_pv_validate_return_url("https://example.test/product/\r\nSet-Cookie:x"));
        $this->assertSame('', intersoccer_pv_validate_return_url("https://example.test\\\x5cevil.example/"));
        $this->assertSame('', intersoccer_pv_validate_return_url(''));
        $this->assertSame('', intersoccer_pv_validate_return_url(['https://example.test/']));
    }

    public function test_booking_return_url_includes_attributes_days_quantity_and_session_coupon(): void {
        $this->session->set('intersoccer_url_coupon_code', 'SAMPLE10');
        $url = intersoccer_pv_booking_return_url(42, [
            'attributes' => ['attribute_pa_camp-terms' => 'week-2'],
            'camp_days' => ['Monday', 'Tuesday'],
            'quantity' => 2,
            'coupon' => 'from-caller',
        ]);

        $this->assertStringStartsWith('https://example.test/product/camp/?', $url);
        $this->assertStringContainsString('attribute_pa_camp-terms=' . rawurlencode('week-2'), $url);
        $this->assertStringContainsString('camp_days%5B%5D=' . rawurlencode('Monday'), $url);
        $this->assertStringContainsString('camp_days%5B%5D=' . rawurlencode('Tuesday'), $url);
        $this->assertStringContainsString('quantity=2', $url);
        $this->assertStringContainsString('bf_return=1', $url);
        $this->assertStringContainsString('coupon=' . rawurlencode('sample10'), $url);
        $this->assertStringNotContainsString('from-caller', $url);
        $this->assertStringContainsString('rawurlencode', (string) file_get_contents(dirname(__DIR__) . '/includes/woocommerce/booking-return.php'));
    }

    public function test_booking_return_url_omits_coupon_when_the_session_has_none(): void {
        $url = intersoccer_pv_booking_return_url(42, [
            'attributes' => ['attribute_pa_venue' => 'hall'],
            'camp_days' => ['Monday'],
        ]);
        $this->assertStringNotContainsString('coupon=', $url);
        $this->assertStringContainsString('attribute_pa_venue=hall', $url);
        $this->assertStringContainsString('camp_days%5B%5D=Monday', $url);
    }

    public function test_coupon_comes_from_the_session_not_the_query_string(): void {
        $_GET['coupon'] = 'othervalue';
        $this->session->set('intersoccer_url_coupon_code', 'SAMPLE10');
        $url = intersoccer_pv_booking_return_url(42, []);
        $this->assertStringContainsString('coupon=' . rawurlencode('sample10'), $url);
        $this->assertStringNotContainsString('othervalue', $url);
    }

    public function test_permalink_uses_the_current_language_filter(): void {
        MockFilters::$filters['wpml_current_language'] = static function () {
            return 'fr';
        };
        MockFilters::$filters['wpml_permalink'] = static function ($url, $lang = null) {
            $GLOBALS['intersoccer_test_wpml_permalink_lang'] = $lang;
            return 'https://example.test/fr/produit/camp/';
        };

        $url = intersoccer_pv_booking_return_url(42, []);
        $this->assertStringStartsWith('https://example.test/fr/produit/camp/', $url);
        $this->assertSame('fr', $GLOBALS['intersoccer_test_wpml_permalink_lang']);
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/woocommerce/booking-return.php');
        $this->assertStringNotContainsString('gerer-participants', $src);
        $this->assertStringNotContainsString('teilnehmer-verwalten', $src);
    }

    public function test_account_redirect_filter_keeps_the_default_when_redirect_is_missing_or_invalid(): void {
        $default = 'https://example.test/my-account/';
        $_GET['redirect'] = 'https://example.test/product/camp/?bf_return=1';
        $this->assertSame(
            'https://example.test/product/camp/?bf_return=1',
            intersoccer_pv_filter_account_redirect($default)
        );

        $_GET = [];
        $_POST['redirect'] = 'https://evil.example/phish';
        $this->assertSame($default, intersoccer_pv_filter_account_redirect($default));

        $_POST = [];
        $this->assertSame($default, intersoccer_pv_filter_account_redirect($default));
    }

    public function test_login_and_register_forms_carry_a_validated_redirect_field(): void {
        $_GET['redirect'] = 'https://example.test/product/camp/';
        ob_start();
        intersoccer_pv_print_return_redirect_field();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('name="redirect"', $html);
        $this->assertStringContainsString('value="https://example.test/product/camp/"', $html);

        $_GET['redirect'] = 'javascript:alert(1)';
        ob_start();
        intersoccer_pv_print_return_redirect_field();
        $this->assertSame('', (string) ob_get_clean());

        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/woocommerce/booking-return.php');
        $this->assertStringContainsString("add_filter('woocommerce_login_redirect'", $src);
        $this->assertStringContainsString("add_filter('woocommerce_registration_redirect'", $src);
        $this->assertStringContainsString("add_filter('login_redirect'", $src);
        $this->assertStringContainsString("add_action('woocommerce_login_form_end'", $src);
        $this->assertStringContainsString("add_action('woocommerce_register_form_end'", $src);
    }

    public function test_login_register_and_add_player_links_include_the_encoded_redirect(): void {
        $return = intersoccer_pv_booking_return_url(42, []);
        $encoded = rawurlencode($return);
        $strings = intersoccer_get_player_assignment_strings([
            'dashboard_url' => 'https://example.test/my-account/',
            'manage_players_url' => 'https://example.test/my-account/manage-players/',
        ]);

        $this->assertStringContainsString('redirect=' . $encoded, $strings['loginPromptHtml']);
        $this->assertStringContainsString('Log in or create an account to choose your child.', $strings['loginPromptHtml']);
        $this->assertStringContainsString('Create account', $strings['loginPromptHtml']);
        $this->assertStringContainsString('>Log in<', $strings['loginPromptHtml']);
        $this->assertStringContainsString('redirect=' . $encoded, $strings['noPlayersRegisteredHtml']);
        $this->assertStringContainsString('Please <a href=', $strings['loginPromptHtmlLegacy']);
        $this->assertSame('Please log in or register to select an attendee.', $strings['loginPromptText']);
        $helpers = (string) file_get_contents(dirname(__DIR__) . '/includes/language-helpers.php');
        $this->assertStringContainsString('intersoccer-booking-return-link', $helpers);
        $this->assertStringContainsString("'class' => []", $helpers);
    }

    public function test_add_another_child_link_is_only_for_parents_with_players(): void {
        $return = intersoccer_pv_booking_return_url(42, []);
        $encoded = rawurlencode($return);
        $GLOBALS['intersoccer_test_user_id'] = 5;
        $GLOBALS['intersoccer_test_user_players'] = [
            0 => ['first_name' => 'Ada', 'last_name' => 'Example', 'player_id' => 'player-uuid-1'],
        ];

        $html = intersoccer_pv_add_another_child_html();
        $this->assertStringContainsString('Add another child', $html);
        $this->assertStringContainsString('redirect=' . $encoded, $html);
        $this->assertStringContainsString('manage-players/', $html);

        $strings = intersoccer_get_player_assignment_strings();
        $this->assertStringContainsString('redirect=' . $encoded, $strings['addAnotherChildHtml']);

        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/woocommerce/booking-return.php');
        $this->assertStringContainsString('intersoccer-booking-return-link', $src);
        $elementor = (string) file_get_contents(dirname(__DIR__) . '/includes/elementor-widgets.php');
        $this->assertStringContainsString('addAnotherChildHtml', $elementor);

        $GLOBALS['intersoccer_test_user_id'] = 0;
        $this->assertSame('', intersoccer_pv_add_another_child_html());

        $GLOBALS['intersoccer_test_user_id'] = 5;
        $GLOBALS['intersoccer_test_user_players'] = [];
        $this->assertSame('', intersoccer_pv_add_another_child_html());
        $empty = intersoccer_get_player_assignment_strings();
        $this->assertSame('', $empty['addAnotherChildHtml']);
        $this->assertStringContainsString('Add a player', $empty['noPlayersRegisteredHtml']);
    }

    public function test_return_notice_and_single_eligible_player_preselect(): void {
        $_GET['bf_return'] = '1';
        $this->assertSame('', intersoccer_pv_booking_return_notice_html(42));

        $_GET['camp_days'] = 'monday';
        $saved = intersoccer_pv_booking_return_notice_html(42);
        $this->assertStringContainsString('Your choices are saved. Check them and add to cart.', $saved);

        unset($_GET['camp_days']);
        $_GET['attribute_pa_venue'] = 'hall';
        $lost = intersoccer_pv_booking_return_notice_html(42);
        $this->assertStringContainsString('Please pick them again.', $lost);
        $this->assertSame('', intersoccer_pv_return_preselect_value(42));

        $_GET = ['bf_return' => '1'];
        $GLOBALS['intersoccer_test_user_players'] = [
            3 => ['first_name' => 'Ada', 'last_name' => 'Example', 'player_id' => 'player-uuid-1'],
        ];
        $this->assertSame('player-uuid-1', intersoccer_pv_return_preselect_value(42));

        $GLOBALS['intersoccer_test_user_players'][4] = [
            'first_name' => 'Bea',
            'last_name' => 'Example',
            'player_id' => 'player-uuid-2',
        ];
        $this->assertSame('', intersoccer_pv_return_preselect_value(42));

        $GLOBALS['intersoccer_test_user_players'] = [
            3 => ['first_name' => 'Ada', 'last_name' => 'Example', 'player_id' => 'player-uuid-1'],
        ];
        MockFilters::$filters['intersoccer_pv_return_player_eligible'] = static function (...$args) {
            return false;
        };
        $this->assertSame('', intersoccer_pv_return_preselect_value(42));

        $_GET = [];
        $this->assertSame('', intersoccer_pv_return_preselect_value(42));
    }

    public function test_return_notice_requires_a_product_option_or_day(): void {
        $_GET = ['bf_return' => '1', 'quantity' => '1'];
        $this->assertSame('', intersoccer_pv_booking_return_notice_html(42));

        $_GET = ['bf_return' => '1'];
        $this->session->set('intersoccer_url_coupon_code', 'SAMPLE10');
        $this->assertSame('', intersoccer_pv_booking_return_notice_html(42));

        $_GET['quantity'] = '1';
        $this->assertSame('', intersoccer_pv_booking_return_notice_html(42));

        $_GET = ['bf_return' => '1', 'camp_days' => 'monday'];
        $saved_days = intersoccer_pv_booking_return_notice_html(42);
        $this->assertStringContainsString('Your choices are saved. Check them and add to cart.', $saved_days);

        $_GET = ['bf_return' => '1', 'attribute_pa_venue' => 'hall', 'quantity' => '1'];
        $this->session->set('intersoccer_url_coupon_code', 'SAMPLE10');
        $this->assertTrue(intersoccer_pv_booking_return_has_saved_state());
        $this->assertStringContainsString('Please pick them again.', intersoccer_pv_booking_return_notice_html(42));
    }

    public function test_login_prompt_buttons_stay_in_flow_above_the_attendee_notice(): void {
        $css = (string) file_get_contents(dirname(__DIR__) . '/css/styles.css');
        $this->assertMatchesRegularExpression(
            '/\.intersoccer-login-prompt\s*\{[^}]*display:\s*flow-root/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.woocommerce\s+div\.product\s+form\.cart\s+\.intersoccer-login-prompt\s+\.button[^{]*\{[^}]*float:\s*none/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.intersoccer-attendee-notification[^{]*\{[^}]*position:\s*static/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.intersoccer-attendee-notification[^{]*\{[^}]*float:\s*none/',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.intersoccer-login-prompt[^{]*\{[^}]*margin-top:\s*-/',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.intersoccer-attendee-notification[^{]*\{[^}]*margin(?:-top|-bottom)?:\s*-/',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.intersoccer-attendee-notification[^{]*\{[^}]*position:\s*absolute/',
            $css
        );
    }

    public function test_return_notice_sits_in_normal_flow_above_the_form(): void {
        $php = (string) file_get_contents(dirname(__DIR__) . '/includes/woocommerce/booking-return.php');
        $this->assertStringContainsString("add_action('woocommerce_before_add_to_cart_form', 'intersoccer_pv_echo_booking_return_notice'", $php);
        $widgets = (string) file_get_contents(dirname(__DIR__) . '/includes/elementor-widgets.php');
        $this->assertStringNotContainsString('intersoccer_pv_echo_booking_return_notice', $widgets);
        $css = (string) file_get_contents(dirname(__DIR__) . '/css/styles.css');
        $this->assertStringContainsString('position: static;', $css);
        $this->assertStringContainsString('.intersoccer-booking-return-note', $css);
    }

    public function test_return_notice_distinguishes_partial_matched_and_invalid_options(): void {
        $GLOBALS['intersoccer_test_products'][42] = new class {
            public function get_id() {
                return 42;
            }
            public function is_type($type) {
                return $type === 'variable';
            }
            public function get_variation_attributes() {
                return [
                    'pa_venue' => ['hall', 'park'],
                    'pa_age' => ['3-5', '6-8'],
                ];
            }
        };
        $GLOBALS['intersoccer_pv_test_matching_variation'] = static function ($product, $attributes) {
            $venue = isset($attributes['attribute_pa_venue']) ? (string) $attributes['attribute_pa_venue'] : '';
            $age = isset($attributes['attribute_pa_age']) ? (string) $attributes['attribute_pa_age'] : '';
            if ($venue === 'hall' && $age === '3-5') {
                return 99;
            }
            return 0;
        };

        $_GET = ['bf_return' => '1', 'attribute_pa_venue' => 'hall'];
        $partial = intersoccer_pv_booking_return_notice_html(42);
        $this->assertSame('partial', intersoccer_pv_return_attribute_state(42));
        $this->assertStringContainsString('Please finish choosing your options to continue.', $partial);
        $this->assertStringNotContainsString('Please pick them again.', $partial);
        $this->assertStringNotContainsString('Your choices are saved.', $partial);

        $_GET = ['bf_return' => '1', 'attribute_pa_venue' => 'hall', 'attribute_pa_age' => '3-5'];
        $matched = intersoccer_pv_booking_return_notice_html(42);
        $this->assertSame('matched', intersoccer_pv_return_attribute_state(42));
        $this->assertStringContainsString('Your choices are saved. Check them and add to cart.', $matched);

        $_GET = ['bf_return' => '1', 'attribute_pa_venue' => 'unknown'];
        $invalid_value = intersoccer_pv_booking_return_notice_html(42);
        $this->assertSame('invalid', intersoccer_pv_return_attribute_state(42));
        $this->assertStringContainsString('Please pick them again.', $invalid_value);

        $_GET = ['bf_return' => '1', 'attribute_pa_venue' => 'hall', 'attribute_pa_age' => '6-8'];
        $unmatched = intersoccer_pv_booking_return_notice_html(42);
        $this->assertSame('invalid', intersoccer_pv_return_attribute_state(42));
        $this->assertStringContainsString('Please pick them again.', $unmatched);
    }

    public function test_french_form_slugs_match_english_variation_slugs_and_restore_the_form(): void {
        $this->installReturnTermMap();
        $stored = $this->englishVariationAttributes();
        $this->bindReturnProduct($this->optionsFromAttributes($stored), [77 => $stored]);
        $this->useExactVariationMatch(77, $stored);
        $this->setReturnLanguage('fr');
        $GLOBALS['intersoccer_test_user_players'] = [
            3 => ['first_name' => 'Ada', 'last_name' => 'Example', 'player_id' => 'player-uuid-1'],
        ];

        $_GET = $this->frenchReturnQuery();
        $this->assertSame('matched', intersoccer_pv_return_attribute_state(42));
        $this->assertSame(77, intersoccer_pv_matching_variation_id(42));
        $saved = intersoccer_pv_booking_return_notice_html(42);
        $this->assertStringContainsString('Your choices are saved. Check them and add to cart.', $saved);
        $this->assertStringNotContainsString('Please pick them again.', $saved);
        $this->assertSame('player-uuid-1', intersoccer_pv_return_preselect_value(42));
        $this->assertSame($this->frenchFormAttributes(), intersoccer_pv_return_form_attribute_values());
        $this->assertSame(['Monday', 'Wednesday'], intersoccer_pv_request_camp_days());

        $_GET = $this->englishReturnQuery();
        $this->assertSame('matched', intersoccer_pv_return_attribute_state(42));
        $this->assertSame(77, intersoccer_pv_matching_variation_id(42));
        $this->assertStringContainsString('Your choices are saved. Check them and add to cart.', intersoccer_pv_booking_return_notice_html(42));
        $this->assertSame($this->frenchFormAttributes(), intersoccer_pv_return_form_attribute_values());
        $this->assertSame(['Monday', 'Wednesday'], intersoccer_pv_request_camp_days());

        $_GET = $this->frenchReturnQuery();
        unset($_GET['attribute_pa_age-group'], $_GET['attribute_pa_camp-times'], $_GET['attribute_pa_booking-type']);
        $this->assertSame('partial', intersoccer_pv_return_attribute_state(42));
        $this->assertStringNotContainsString('Please pick them again.', intersoccer_pv_booking_return_notice_html(42));

        $_GET = $this->frenchReturnQuery();
        $_GET['attribute_pa_booking-type'] = 'not-a-real-slug';
        $this->assertSame('invalid', intersoccer_pv_return_attribute_state(42));
        $this->assertStringContainsString('Please pick them again.', intersoccer_pv_booking_return_notice_html(42));
        $this->assertSame('', intersoccer_pv_return_preselect_value(42));

        $js = (string) file_get_contents(dirname(__DIR__) . '/js/booking-return.js');
        $this->assertStringContainsString('returnAttributes', $js);
        $this->assertStringContainsString('returnCampDays', $js);
        $this->assertMatchesRegularExpression(
            '/restoreAttributes\(\$form\);\s*seedCampDays\(\$form\);/',
            $js
        );
    }

    public function test_mixed_english_and_french_variation_slugs_still_match(): void {
        $this->installReturnTermMap();
        $stored = [
            'attribute_pa_intersoccer-venues' => '-pully',
            'attribute_pa_age-group' => '5-13-ans-journee-complete',
            'attribute_pa_camp-times' => '1000-1700',
            'attribute_pa_booking-type' => 'a-la-journee',
        ];
        $this->bindReturnProduct($this->optionsFromAttributes($stored), [77 => $stored]);
        $this->useExactVariationMatch(77, $stored);
        $this->setReturnLanguage('fr');

        $_GET = $this->frenchReturnQuery();
        $this->assertSame(0, $this->exactVariationId($stored, intersoccer_pv_request_attribute_selections()));
        $this->assertSame('matched', intersoccer_pv_return_attribute_state(42));
        $this->assertSame(77, intersoccer_pv_matching_variation_id(42));
        $this->assertSame($this->frenchFormAttributes(), intersoccer_pv_return_form_attribute_values());
        $this->assertSame(['Monday', 'Wednesday'], intersoccer_pv_request_camp_days());

        $this->setReturnLanguage('en');
        $_GET = $this->englishReturnQuery();
        $this->assertSame(0, $this->exactVariationId($stored, intersoccer_pv_request_attribute_selections()));
        $this->assertSame('matched', intersoccer_pv_return_attribute_state(42));
        $this->assertSame(77, intersoccer_pv_matching_variation_id(42));
        $this->assertSame($this->englishFormAttributes(), intersoccer_pv_return_form_attribute_values());
        $this->assertSame(['Monday', 'Wednesday'], intersoccer_pv_request_camp_days());
    }

    public function test_english_and_german_returns_stay_on_direct_slug_matches(): void {
        $this->installReturnTermMap();
        $english = $this->englishVariationAttributes();
        $this->bindReturnProduct($this->optionsFromAttributes($english), [88 => $english]);
        $seen = [];
        $GLOBALS['intersoccer_pv_test_matching_variation'] = static function ($product, $attributes) use (&$seen) {
            $seen = $attributes;
            return 88;
        };
        $this->setReturnLanguage('en');
        $_GET = $this->englishReturnQuery();
        $this->assertSame('matched', intersoccer_pv_return_attribute_state(42));
        $this->assertSame($english, $seen);
        $this->assertSame($this->englishFormAttributes(), intersoccer_pv_return_form_attribute_values());
        $this->assertSame(['Monday', 'Wednesday'], intersoccer_pv_request_camp_days());
        $this->assertStringContainsString('Your choices are saved. Check them and add to cart.', intersoccer_pv_booking_return_notice_html(42));

        $german = $this->germanVariationAttributes();
        $this->bindReturnProduct($this->optionsFromAttributes($german), [89 => $german]);
        $seen = [];
        $GLOBALS['intersoccer_pv_test_matching_variation'] = static function ($product, $attributes) use (&$seen) {
            $seen = $attributes;
            return 89;
        };
        $this->setReturnLanguage('de');
        $_GET = $this->germanReturnQuery();
        $this->assertSame('matched', intersoccer_pv_return_attribute_state(42));
        $this->assertSame($german, $seen);
        $this->assertSame($this->germanFormAttributes(), intersoccer_pv_return_form_attribute_values());
        $this->assertSame(['Monday', 'Wednesday'], intersoccer_pv_request_camp_days());
        $this->assertStringNotContainsString('Please pick them again.', intersoccer_pv_booking_return_notice_html(42));
    }

    public function test_without_wpml_translated_slugs_do_not_match_and_direct_slugs_still_do(): void {
        $stored = $this->englishVariationAttributes();
        $this->bindReturnProduct($this->optionsFromAttributes($stored), [77 => $stored]);
        $this->useExactVariationMatch(77, $stored);
        $GLOBALS['intersoccer_test_terms'] = [
            31 => ['taxonomy' => 'pa_intersoccer-venues', 'slug' => '-pully', 'name' => 'Venue', 'wpml_lang' => 'en'],
            32 => ['taxonomy' => 'pa_intersoccer-venues', 'slug' => '-pully-fr', 'name' => 'Venue FR', 'wpml_lang' => 'fr'],
        ];

        $_GET = $this->frenchReturnQuery();
        $this->assertFalse(intersoccer_pv_return_wpml_active());
        $this->assertFalse(intersoccer_pv_attribute_slugs_equivalent('-pully-fr', '-pully', 'pa_intersoccer-venues'));
        $this->assertSame('invalid', intersoccer_pv_return_attribute_state(42));
        $this->assertSame(0, intersoccer_pv_matching_variation_id(42));
        $this->assertSame($this->frenchFormAttributes(), intersoccer_pv_return_form_attribute_values());
        $this->assertStringContainsString('Please pick them again.', intersoccer_pv_booking_return_notice_html(42));

        $_GET = $this->englishReturnQuery();
        $this->assertSame('matched', intersoccer_pv_return_attribute_state(42));
        $this->assertSame(77, intersoccer_pv_matching_variation_id(42));
        $this->assertSame($this->englishFormAttributes(), intersoccer_pv_return_form_attribute_values());
        $this->assertSame(['Monday', 'Wednesday'], intersoccer_pv_request_camp_days());
        $this->assertStringContainsString('Your choices are saved. Check them and add to cart.', intersoccer_pv_booking_return_notice_html(42));
    }

    /**
     * @param array<string,array<int,string>> $options
     * @param array<int,array<string,string>> $variations
     */
    private function bindReturnProduct(array $options, array $variations): void {
        $child_ids = array_map('intval', array_keys($variations));
        $GLOBALS['intersoccer_test_products'][42] = new class($options, $child_ids) {
            /** @var array<string,array<int,string>> */
            private $options;
            /** @var array<int,int> */
            private $children;

            public function __construct(array $options, array $children) {
                $this->options = $options;
                $this->children = $children;
            }

            public function get_id() {
                return 42;
            }

            public function is_type($type) {
                return $type === 'variable';
            }

            public function get_variation_attributes() {
                return $this->options;
            }

            public function get_children() {
                return $this->children;
            }

            public function get_attribute($name) {
                return '';
            }
        };
        foreach ($variations as $id => $attributes) {
            $GLOBALS['intersoccer_test_products'][(int) $id] = new class($attributes) {
                /** @var array<string,string> */
                private $attributes;

                public function __construct(array $attributes) {
                    $this->attributes = $attributes;
                }

                public function get_status() {
                    return 'publish';
                }

                public function get_variation_attributes() {
                    return $this->attributes;
                }

                public function get_attribute($name) {
                    return '';
                }
            };
        }
    }

    /**
     * @param array<string,string> $stored
     */
    private function useExactVariationMatch(int $variation_id, array $stored): void {
        $GLOBALS['intersoccer_pv_test_matching_variation'] = static function ($product, $attributes) use ($variation_id, $stored) {
            foreach ($stored as $key => $value) {
                if (!isset($attributes[$key]) || (string) $attributes[$key] !== (string) $value) {
                    return 0;
                }
            }
            return $variation_id;
        };
    }

    /**
     * @param array<string,string> $stored
     * @param array<string,string> $requested
     */
    private function exactVariationId(array $stored, array $requested): int {
        foreach ($stored as $key => $value) {
            if (!isset($requested[$key]) || (string) $requested[$key] !== (string) $value) {
                return 0;
            }
        }
        return 77;
    }

    /**
     * @param array<string,string> $attributes
     * @return array<string,array<int,string>>
     */
    private function optionsFromAttributes(array $attributes): array {
        $options = [];
        foreach ($attributes as $key => $value) {
            $name = strpos($key, 'attribute_') === 0 ? substr($key, strlen('attribute_')) : $key;
            $options[$name] = [(string) $value];
        }
        return $options;
    }

    private function setReturnLanguage(string $language): void {
        $GLOBALS['intersoccer_test_wpml_current_language'] = $language;
        $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'] = true;
        MockFilters::$filters['wpml_current_language'] = static function () {
            return $GLOBALS['intersoccer_test_wpml_current_language'] ?? null;
        };
    }

    private function installReturnTermMap(): void {
        $rows = [
            'pa_booking-type' => [
                'en' => ['id' => 11, 'slug' => 'single-days'],
                'fr' => ['id' => 12, 'slug' => 'a-la-journee'],
                'de' => ['id' => 13, 'slug' => 'einzelner-tage'],
            ],
            'pa_age-group' => [
                'en' => ['id' => 21, 'slug' => '5-13y-full-day'],
                'fr' => ['id' => 22, 'slug' => '5-13-ans-journee-complete'],
                'de' => ['id' => 23, 'slug' => '5-13j-ganzer-tag'],
            ],
            'pa_intersoccer-venues' => [
                'en' => ['id' => 31, 'slug' => '-pully'],
                'fr' => ['id' => 32, 'slug' => '-pully-fr'],
                'de' => ['id' => 33, 'slug' => '-ort-de'],
            ],
            'pa_camp-times' => [
                'en' => ['id' => 41, 'slug' => '1000-1700'],
                'fr' => ['id' => 42, 'slug' => '1000-1700-fr'],
                'de' => ['id' => 43, 'slug' => '1000-1700-de'],
            ],
        ];
        $terms = [];
        $groups = [];
        foreach ($rows as $taxonomy => $langs) {
            $by_lang = [];
            foreach ($langs as $lang => $term) {
                $terms[$term['id']] = [
                    'taxonomy' => $taxonomy,
                    'slug' => $term['slug'],
                    'name' => $term['slug'],
                    'wpml_lang' => $lang,
                ];
                $by_lang[$lang] = $term['id'];
            }
            foreach ($langs as $term) {
                $groups[$term['id']] = $by_lang;
            }
        }
        $GLOBALS['intersoccer_test_terms'] = $terms;
        MockFilters::$filters['wpml_default_language'] = static function () {
            return 'en';
        };
        MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) use ($groups) {
            $id = (int) $id;
            if (!isset($groups[$id])) {
                return $return_original ? $id : 0;
            }
            $lang = is_string($lang) && $lang !== '' ? $lang : 'en';
            if (isset($groups[$id][$lang])) {
                return $groups[$id][$lang];
            }
            return $return_original ? $id : 0;
        };
        $this->setReturnLanguage('en');
    }

    /**
     * @return array<string,string>
     */
    private function englishVariationAttributes(): array {
        return [
            'attribute_pa_intersoccer-venues' => '-pully',
            'attribute_pa_age-group' => '5-13y-full-day',
            'attribute_pa_camp-times' => '1000-1700',
            'attribute_pa_booking-type' => 'single-days',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function frenchFormAttributes(): array {
        return [
            'attribute_pa_intersoccer-venues' => '-pully-fr',
            'attribute_pa_age-group' => '5-13-ans-journee-complete',
            'attribute_pa_camp-times' => '1000-1700-fr',
            'attribute_pa_booking-type' => 'a-la-journee',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function englishFormAttributes(): array {
        return $this->englishVariationAttributes();
    }

    /**
     * @return array<string,string>
     */
    private function germanVariationAttributes(): array {
        return [
            'attribute_pa_intersoccer-venues' => '-ort-de',
            'attribute_pa_age-group' => '5-13j-ganzer-tag',
            'attribute_pa_camp-times' => '1000-1700-de',
            'attribute_pa_booking-type' => 'einzelner-tage',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function germanFormAttributes(): array {
        return $this->germanVariationAttributes();
    }

    /**
     * @return array<string,mixed>
     */
    private function frenchReturnQuery(): array {
        return $this->frenchFormAttributes() + [
            'bf_return' => '1',
            'camp_days' => ['Monday', 'Wednesday'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function englishReturnQuery(): array {
        return $this->englishFormAttributes() + [
            'bf_return' => '1',
            'camp_days' => ['Monday', 'Wednesday'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function germanReturnQuery(): array {
        return $this->germanFormAttributes() + [
            'bf_return' => '1',
            'camp_days' => ['Monday', 'Wednesday'],
        ];
    }

    public function test_return_script_takes_the_coupon_from_the_server_value_only(): void {
        $js = (string) file_get_contents(dirname(__DIR__) . '/js/booking-return.js');
        $this->assertStringContainsString('returnCoupon', $js);
        $this->assertStringContainsString('intersoccer-booking-return-link', $js);
        $this->assertStringNotContainsString("get('coupon')", $js);
        $this->assertStringNotContainsString('get("coupon")', $js);
        $this->assertStringContainsString('bf_return', $js);
    }
}

if (!class_exists('WC_Product_Data_Store_CPT')) {
    class WC_Product_Data_Store_CPT {
        public function find_matching_product_variation($product, $attributes) {
            if (isset($GLOBALS['intersoccer_pv_test_matching_variation']) && is_callable($GLOBALS['intersoccer_pv_test_matching_variation'])) {
                return (int) call_user_func($GLOBALS['intersoccer_pv_test_matching_variation'], $product, $attributes);
            }
            return 0;
        }
    }
}
