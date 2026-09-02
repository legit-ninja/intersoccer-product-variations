<?php
/**
 * Campaign Offers: cap helpers, window, eligibility, joining email, lead match-by-email.
 */

use PHPUnit\Framework\TestCase;

class CampaignOfferTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['intersoccer_test_options'] = [];
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        if (!function_exists('wp_timezone')) {
            function wp_timezone() {
                return new DateTimeZone('UTC');
            }
        }
        if (!function_exists('wp_unslash')) {
            function wp_unslash($value) {
                return $value;
            }
        }
        if (!function_exists('sanitize_email')) {
            function sanitize_email($email) {
                return trim((string) $email);
            }
        }
        if (!function_exists('is_email')) {
            function is_email($email) {
                return filter_var((string) $email, FILTER_VALIDATE_EMAIL) !== false;
            }
        }
        $GLOBALS['intersoccer_test_users_by_email'] = [];
        require_once dirname(__DIR__) . '/includes/woocommerce/campaign-offers.php';
        require_once dirname(__DIR__) . '/includes/woocommerce/campaign-checkout-field.php';
        require_once dirname(__DIR__) . '/includes/woocommerce/campaign-leads.php';
    }

    /**
     * @return array
     */
    private function sampleOffer(array $overrides = []) {
        $base = [
            'id' => 'together20',
            'enabled' => true,
            'name' => 'Together',
            'code' => 'TOGETHER20',
            'percent' => 20,
            'max_cap_percent' => 20,
            'product_ids' => [],
            'excluded_product_ids' => [],
            'product_categories' => [],
            'excluded_product_categories' => [],
            'product_tags' => [],
            'starts_at' => '2026-08-01 00:00:00',
            'ends_at' => '2026-11-30 23:59:59',
            'requires_group_field' => true,
            'group_field_label' => 'Who is your child joining?',
            'group_field_placeholder' => 'Friend or sibling name',
            'group_field_error' => 'Please enter who your child is joining.',
            'exclusive_with' => [],
            'coupon_id' => 0,
        ];
        return array_merge($base, $overrides);
    }

    public function test_defaults_include_autumn_codes(): void {
        $defaults = intersoccer_get_default_campaign_offers();
        $this->assertArrayHasKey('autumn15', $defaults);
        $this->assertArrayHasKey('together20', $defaults);
        $this->assertSame('AUTUMN15', $defaults['autumn15']['code']);
        $this->assertSame('TOGETHER20', $defaults['together20']['code']);
        $this->assertFalse($defaults['autumn15']['requires_group_field']);
        $this->assertTrue($defaults['together20']['requires_group_field']);
        $this->assertSame(20.0, (float) $defaults['together20']['max_cap_percent']);
    }

    public function test_sanitize_normalizes_code_and_ids(): void {
        $row = intersoccer_normalize_campaign_offer([
            'id' => 'My Offer!',
            'code' => 'autumn-15 extra',
            'name' => 'Autumn',
            'percent' => 150,
            'max_cap_percent' => -5,
            'product_ids' => '10, 20, abc',
            'exclusive_with' => ['camp_sibling', 'not_a_source'],
            'enabled' => '1',
        ]);
        $this->assertNotNull($row);
        $this->assertSame('AUTUMN-15EXTRA', $row['code']);
        $this->assertEquals(100, $row['percent']);
        $this->assertEquals(0, $row['max_cap_percent']);
        $this->assertSame([10, 20], $row['product_ids']);
        $this->assertSame(['camp_sibling'], $row['exclusive_with']);
    }

    public function test_cap_campaign_plus_sibling_never_exceeds_max(): void {
        $effective = intersoccer_resolve_capped_percent([
            'camp_sibling' => 0.20,
            'campaign' => 0.20,
        ], 0.20);
        $this->assertEqualsWithDelta(0.20, $effective, 0.0001);
    }

    public function test_cap_reduces_third_child_when_campaign_applied(): void {
        $effective = intersoccer_resolve_capped_percent([
            'camp_sibling' => 0.25,
            'campaign' => 0.20,
        ], 0.20);
        $this->assertEqualsWithDelta(0.20, $effective, 0.0001);
    }

    public function test_higher_percent_wins_then_cap(): void {
        $uncapped = intersoccer_resolve_capped_percent([
            'camp_sibling' => 0.15,
            'campaign' => 0.20,
        ], 0.20);
        $this->assertEqualsWithDelta(0.20, $uncapped, 0.0001);
    }

    public function test_exclusivity_drops_sibling_candidate(): void {
        $sources = intersoccer_campaign_filter_exclusive_sources([
            'camp_sibling' => 0.25,
            'campaign' => 0.15,
        ], ['camp_sibling']);
        $this->assertArrayNotHasKey('camp_sibling', $sources);
        $effective = intersoccer_resolve_capped_percent($sources, 0.20);
        $this->assertEqualsWithDelta(0.15, $effective, 0.0001);
    }

    public function test_date_window_on_and_off(): void {
        $offer = $this->sampleOffer();
        $inside = new DateTimeImmutable('2026-10-15 12:00:00', new DateTimeZone('UTC'));
        $before = new DateTimeImmutable('2026-07-01 00:00:00', new DateTimeZone('UTC'));
        $after = new DateTimeImmutable('2026-12-01 00:00:00', new DateTimeZone('UTC'));
        $this->assertTrue(intersoccer_campaign_offer_in_window($offer, $inside));
        $this->assertFalse(intersoccer_campaign_offer_in_window($offer, $before));
        $this->assertFalse(intersoccer_campaign_offer_in_window($offer, $after));
    }

    public function test_eligible_line_only_in_mixed_basket(): void {
        $offer = $this->sampleOffer([
            'product_ids' => [100],
            'excluded_product_ids' => [200],
        ]);
        $this->assertTrue(intersoccer_campaign_product_is_eligible($offer, 100));
        $this->assertFalse(intersoccer_campaign_product_is_eligible($offer, 200));
        $this->assertFalse(intersoccer_campaign_product_is_eligible($offer, 300));
    }

    public function test_empty_allowlist_applies_except_exclusions(): void {
        $offer = $this->sampleOffer(['excluded_product_ids' => [9]]);
        $this->assertTrue(intersoccer_campaign_product_is_eligible($offer, 1));
        $this->assertFalse(intersoccer_campaign_product_is_eligible($offer, 9));
    }

    public function test_joining_validation_required_when_empty(): void {
        $offer = $this->sampleOffer(['requires_group_field' => true]);
        $this->assertTrue(intersoccer_campaign_joining_fails_validation($offer, ''));
        $this->assertTrue(intersoccer_campaign_joining_fails_validation($offer, '   '));
        $this->assertFalse(intersoccer_campaign_joining_fails_validation($offer, 'Alex'));
    }

    public function test_joining_not_required_for_solo_offer(): void {
        $offer = $this->sampleOffer(['requires_group_field' => false]);
        $this->assertFalse(intersoccer_campaign_joining_fails_validation($offer, ''));
    }

    public function test_coupon_invalid_when_disabled_or_kill_switch(): void {
        $offer = $this->sampleOffer(['enabled' => false, 'starts_at' => '', 'ends_at' => '']);
        update_option('intersoccer_campaign_offers', ['together20' => $offer]);
        update_option('intersoccer_campaign_offers_enabled', true);

        $coupon = new class {
            public function get_code() {
                return 'TOGETHER20';
            }
        };
        $this->assertFalse(intersoccer_campaign_coupon_is_valid(true, $coupon));

        $offer['enabled'] = true;
        update_option('intersoccer_campaign_offers', ['together20' => $offer]);
        update_option('intersoccer_campaign_offers_enabled', false);
        $this->assertFalse(intersoccer_campaign_coupon_is_valid(true, $coupon));

        update_option('intersoccer_campaign_offers_enabled', true);
        $this->assertTrue(intersoccer_campaign_coupon_is_valid(true, $coupon));
    }

    public function test_coupon_invalid_after_deadline(): void {
        $offer = $this->sampleOffer([
            'enabled' => true,
            'starts_at' => '2020-01-01 00:00:00',
            'ends_at' => '2020-01-02 00:00:00',
        ]);
        update_option('intersoccer_campaign_offers', ['together20' => $offer]);
        update_option('intersoccer_campaign_offers_enabled', true);
        $coupon = new class {
            public function get_code() {
                return 'together20';
            }
        };
        $this->assertFalse(intersoccer_campaign_coupon_is_valid(true, $coupon));
    }

    public function test_non_campaign_coupons_detected(): void {
        update_option('intersoccer_campaign_offers', [
            'together20' => $this->sampleOffer(),
        ]);
        $cart = new class {
            public function get_applied_coupons() {
                return ['TOGETHER20', 'SAVE10'];
            }
        };
        $this->assertTrue(intersoccer_cart_has_non_campaign_coupons($cart));

        $campaignOnly = new class {
            public function get_applied_coupons() {
                return ['together20'];
            }
        };
        $this->assertFalse(intersoccer_cart_has_non_campaign_coupons($campaignOnly));
    }

    public function test_first_order_remaining_cap(): void {
        $offer = $this->sampleOffer(['max_cap_percent' => 20]);
        $this->assertEqualsWithDelta(5.0, intersoccer_campaign_remaining_cap_percent($offer, 0.15), 0.01);
        $this->assertEqualsWithDelta(0.0, intersoccer_campaign_remaining_cap_percent($offer, 0.20), 0.01);
    }

    public function test_first_order_exclusive_returns_zero(): void {
        $offer = $this->sampleOffer([
            'exclusive_with' => ['first_order_referral'],
            'starts_at' => '',
            'ends_at' => '',
        ]);
        update_option('intersoccer_campaign_offers', ['together20' => $offer]);
        update_option('intersoccer_campaign_offers_enabled', true);
        $cart = new class {
            public function get_applied_coupons() {
                return ['TOGETHER20'];
            }
            public function get_cart() {
                return [];
            }
        };
        $this->assertSame(0.0, intersoccer_campaign_filter_first_order_percent(10, $cart));
    }

    public function test_export_meta_key(): void {
        $this->assertSame('_intersoccer_campaign_joining', intersoccer_campaign_joining_meta_key());
        $this->assertSame('_intersoccer_campaign_joining_email', intersoccer_campaign_joining_email_meta_key());
        $this->assertSame('_intersoccer_campaign_joining_user_id', intersoccer_campaign_joining_user_meta_key());
    }

    public function test_lookup_user_id_by_email_existing_or_zero(): void {
        $GLOBALS['intersoccer_test_users_by_email'] = [
            'alex.parent@example.com' => (object) ['ID' => 42],
        ];
        $this->assertSame(42, intersoccer_campaign_lookup_user_id_by_email('alex.parent@example.com'));
        $this->assertSame(42, intersoccer_campaign_lookup_user_id_by_email('  Alex.Parent@example.com  '));
        $this->assertSame(0, intersoccer_campaign_lookup_user_id_by_email('unknown@example.com'));
        $this->assertSame(0, intersoccer_campaign_lookup_user_id_by_email(''));
        $this->assertSame(0, intersoccer_campaign_lookup_user_id_by_email('not-an-email'));
    }

    public function test_lead_converted_only_for_later_paid_same_email(): void {
        $email = 'friend.parent@example.com';
        $later = [
            'id' => 200,
            'billing_email' => $email,
            'status' => 'completed',
            'date_created' => '2026-10-02 12:00:00',
        ];
        $this->assertTrue(intersoccer_campaign_lead_is_converted($email, 100, '2026-10-01 12:00:00', [$later]));
        $this->assertFalse(intersoccer_campaign_lead_is_converted($email, 200, '2026-10-02 12:00:00', [$later]));
        $this->assertFalse(intersoccer_campaign_lead_is_converted($email, 100, '2026-10-03 12:00:00', [$later]));
        $this->assertFalse(intersoccer_campaign_lead_is_converted('other@example.com', 100, '2026-10-01 12:00:00', [$later]));
        $pending = $later;
        $pending['status'] = 'pending';
        $this->assertFalse(intersoccer_campaign_lead_is_converted($email, 100, '2026-10-01 12:00:00', [$pending]));
        $same_time_higher_id = [
            'id' => 101,
            'billing_email' => $email,
            'status' => 'processing',
            'date_created' => '2026-10-01 12:00:00',
        ];
        $this->assertTrue(intersoccer_campaign_lead_is_converted($email, 100, '2026-10-01 12:00:00', [$same_time_higher_id]));
    }

    public function test_joining_email_validation_required_when_empty_or_invalid(): void {
        $offer = $this->sampleOffer(['requires_group_field' => true]);
        $this->assertTrue(intersoccer_campaign_joining_email_fails_validation($offer, ''));
        $this->assertTrue(intersoccer_campaign_joining_email_fails_validation($offer, '   '));
        $this->assertTrue(intersoccer_campaign_joining_email_fails_validation($offer, 'not-an-email'));
        $this->assertFalse(intersoccer_campaign_joining_email_fails_validation($offer, 'alex.parent@example.com'));
    }

    public function test_joining_email_not_required_for_solo_offer(): void {
        $offer = $this->sampleOffer(['requires_group_field' => false]);
        $this->assertFalse(intersoccer_campaign_joining_email_fails_validation($offer, ''));
        $this->assertFalse(intersoccer_campaign_joining_email_fails_validation($offer, 'not-an-email'));
    }

    public function test_strip_optional_label_only_on_campaign_fields(): void {
        $html = '<label>Guardian\'s email&nbsp;<span class="optional">(optional)</span></label>';
        $stripped = intersoccer_campaign_strip_optional_label($html, 'intersoccer_campaign_joining_email');
        $this->assertStringNotContainsString('optional', $stripped);
        $this->assertStringContainsString('Guardian', $stripped);
        $name_html = '<label>Who is your child joining? <span class="optional">(optional)</span></label>';
        $this->assertStringNotContainsString(
            'class="optional"',
            intersoccer_campaign_strip_optional_label($name_html, 'intersoccer_campaign_joining')
        );
        $billing = '<label>Email&nbsp;<span class="optional">(optional)</span></label>';
        $this->assertSame($billing, intersoccer_campaign_strip_optional_label($billing, 'billing_email'));
    }

    public function test_sanitize_joining_pair_trims_name_and_email(): void {
        $pair = intersoccer_campaign_sanitize_joining_pair('  Alex Friend  ', '  alex.parent@example.com  ');
        $this->assertSame('Alex Friend', $pair['joining']);
        $this->assertSame('alex.parent@example.com', $pair['email']);
    }

    public function test_session_get_set_with_fake_session(): void {
        $session = $this->fakeCampaignSession();
        intersoccer_campaign_set_joining_session('Alex Friend', 'alex.parent@example.com', $session);
        $got = intersoccer_campaign_get_joining_session($session);
        $this->assertSame('Alex Friend', $got['joining']);
        $this->assertSame('alex.parent@example.com', $got['email']);
    }

    public function test_persist_joining_from_request_writes_session(): void {
        $session = $this->fakeCampaignSession();
        $written = intersoccer_campaign_persist_joining_from_request([
            'intersoccer_campaign_joining' => 'Alex Friend',
            'intersoccer_campaign_joining_email' => 'alex.parent@example.com',
        ], $session);
        $this->assertSame('Alex Friend', $written['joining']);
        $this->assertSame('alex.parent@example.com', intersoccer_campaign_get_joining_session($session)['email']);
        $this->assertNull(intersoccer_campaign_persist_joining_from_request(['billing_email' => 'x@y.com'], $session));
    }

    public function test_checkout_get_value_falls_back_to_session_when_empty(): void {
        $session = $this->fakeCampaignSession([
            'intersoccer_campaign_joining' => 'Alex Friend',
            'intersoccer_campaign_joining_email' => 'alex.parent@example.com',
        ]);
        $this->assertSame(
            'Alex Friend',
            intersoccer_campaign_checkout_get_value('', 'intersoccer_campaign_joining', $session)
        );
        $this->assertSame(
            'keep',
            intersoccer_campaign_checkout_get_value('keep', 'intersoccer_campaign_joining', $session)
        );
        $this->assertSame(
            'other',
            intersoccer_campaign_checkout_get_value('other', 'billing_email', $session)
        );
        $this->assertSame(
            'alex.parent@example.com',
            intersoccer_campaign_checkout_get_value(null, 'intersoccer_campaign_joining_email', $session)
        );
    }

    public function test_joining_field_args_required_false(): void {
        $args = intersoccer_campaign_joining_field_args();
        $this->assertFalse($args['intersoccer_campaign_joining']['required']);
        $this->assertFalse($args['intersoccer_campaign_joining_email']['required']);
        $this->assertContains('intersoccer-campaign-joining', $args['intersoccer_campaign_joining']['class']);
        $this->assertContains('intersoccer-campaign-joining', $args['intersoccer_campaign_joining_email']['class']);
    }

    /**
     * @param array<string,string> $data
     * @return object
     */
    private function fakeCampaignSession(array $data = []) {
        return new class($data) {
            /** @var array<string,string> */
            public $data;

            public function __construct(array $data) {
                $this->data = $data;
            }

            public function set($key, $value) {
                $this->data[$key] = $value;
            }

            public function get($key, $default = '') {
                return $this->data[$key] ?? $default;
            }
        };
    }
}
