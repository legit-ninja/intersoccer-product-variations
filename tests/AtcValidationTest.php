<?php
/**
 * Test: Add-to-Cart Server Validation
 *
 * Verifies the server-side hard gate for attendee-required products:
 * - Guests cannot ATC on attendee-required products
 * - Logged-in users must select a player before ATC
 * - Non-attendee-required products allow ATC without player
 *
 * AC C8-C9: earlier-assign ATC harden
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';

if (!function_exists('intersoccer_product_requires_attendee')) {
    function intersoccer_product_requires_attendee($product_id) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return false;
        }

        $product_type = intersoccer_get_product_type($product_id);
        if (in_array($product_type, ['camp', 'course', 'birthday'], true)) {
            return true;
        }

        return (bool) apply_filters('intersoccer_product_requires_attendee', false, $product_id);
    }
}

if (!function_exists('intersoccer_has_posted_player_assignment')) {
    function intersoccer_has_posted_player_assignment() {
        foreach (['player_assignment', 'assigned_attendee', 'assigned_player_id'] as $field) {
            if (isset($_POST[$field])) {
                $val = trim((string) wp_unslash($_POST[$field]));
                if ($val !== '' && $val !== '0') {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('intersoccer_validate_cart_item')) {
    function intersoccer_validate_cart_item($passed, $product_id, $quantity, $variation_id = null, $variations = null, $cart_item_data = null) {
        $requires_attendee = intersoccer_product_requires_attendee($product_id);

        if ($requires_attendee) {
            $user_id = (int) get_current_user_id();

            if ($user_id <= 0) {
                $login_url = function_exists('wc_get_account_endpoint_url')
                    ? wc_get_account_endpoint_url('dashboard')
                    : wp_login_url();
                wc_add_notice(
                    sprintf(
                        'Please <a href="%s">log in or register</a> to book this product.',
                        esc_url($login_url)
                    ),
                    'error'
                );
                $passed = false;
                intersoccer_warning('Cart validation failed: guest attempted ATC on attendee-required product ' . $product_id);
            } elseif (!intersoccer_has_posted_player_assignment()) {
                wc_add_notice(
                    'Please select an attendee before adding to cart.',
                    'error'
                );
                $passed = false;
                intersoccer_warning('Cart validation failed: no player selected for attendee-required product ' . $product_id);
            }
        }

        return $passed;
    }
}

class AtcValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $GLOBALS['intersoccer_test_user_id'] = 0;
        $GLOBALS['wc_notices'] = [];
        $GLOBALS['intersoccer_test_product_type'] = null;
        $GLOBALS['intersoccer_test_product_categories'] = [];
        $GLOBALS['intersoccer_test_product_name'] = '';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($GLOBALS['intersoccer_test_user_id']);
        unset($GLOBALS['wc_notices']);
        unset($GLOBALS['intersoccer_test_product_type']);
        unset($GLOBALS['intersoccer_test_product_categories']);
        unset($GLOBALS['intersoccer_test_product_name']);
        parent::tearDown();
    }

    public function testProductRequiresAttendeeForCamp()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $this->assertTrue(intersoccer_product_requires_attendee(123));
    }

    public function testProductRequiresAttendeeForCourse()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'course';
        $this->assertTrue(intersoccer_product_requires_attendee(123));
    }

    public function testProductRequiresAttendeeForBirthday()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'birthday';
        $this->assertTrue(intersoccer_product_requires_attendee(123));
    }

    public function testProductDoesNotRequireAttendeeForTournament()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'tournament';
        $this->assertFalse(intersoccer_product_requires_attendee(123));
    }

    public function testProductDoesNotRequireAttendeeWhenNoType()
    {
        $GLOBALS['intersoccer_test_product_type'] = null;
        $this->assertFalse(intersoccer_product_requires_attendee(123));
    }

    public function testHasPostedPlayerAssignmentWithIndex()
    {
        $_POST['player_assignment'] = '2';
        $this->assertTrue(intersoccer_has_posted_player_assignment());
    }

    public function testHasPostedPlayerAssignmentWithAttendee()
    {
        $_POST['assigned_attendee'] = 'Jane Doe';
        $this->assertTrue(intersoccer_has_posted_player_assignment());
    }

    public function testHasPostedPlayerAssignmentWithUuid()
    {
        $_POST['assigned_player_id'] = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertTrue(intersoccer_has_posted_player_assignment());
    }

    public function testHasNoPostedPlayerAssignmentEmpty()
    {
        $_POST['player_assignment'] = '';
        $this->assertFalse(intersoccer_has_posted_player_assignment());
    }

    public function testHasNoPostedPlayerAssignmentZero()
    {
        $_POST['player_assignment'] = '0';
        $this->assertFalse(intersoccer_has_posted_player_assignment());
    }

    public function testHasNoPostedPlayerAssignmentMissing()
    {
        $this->assertFalse(intersoccer_has_posted_player_assignment());
    }

    public function testValidateRejectsGuestOnAttendeeRequiredProduct()
    {
        $GLOBALS['intersoccer_test_user_id'] = 0;
        $GLOBALS['intersoccer_test_product_type'] = 'camp';

        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);

        $this->assertFalse($passed, 'Guests should be rejected for attendee-required products');
        $this->assertNotEmpty($GLOBALS['wc_notices'], 'An error notice should be added');
    }

    public function testValidateRejectsLoggedInUserWithoutPlayer()
    {
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_product_type'] = 'course';

        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);

        $this->assertFalse($passed, 'Logged-in users without player selection should be rejected');
        $this->assertNotEmpty($GLOBALS['wc_notices'], 'An error notice should be added');
    }

    public function testValidateAcceptsLoggedInUserWithPlayer()
    {
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_product_type'] = 'course';
        $_POST['player_assignment'] = '1';

        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);

        $this->assertTrue($passed, 'Logged-in users with player selection should be accepted');
    }

    public function testValidateAcceptsNonAttendeeProduct()
    {
        $GLOBALS['intersoccer_test_user_id'] = 0;
        $GLOBALS['intersoccer_test_product_type'] = 'tournament';

        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);

        $this->assertTrue($passed, 'Non-attendee products should allow guest ATC');
    }

    public function testValidateAcceptsLoggedInUserWithUuidPlayer()
    {
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_product_type'] = 'birthday';
        $_POST['assigned_player_id'] = '550e8400-e29b-41d4-a716-446655440000';

        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);

        $this->assertTrue($passed, 'UUID player assignment should be accepted');
    }

    public function testValidatePreservesPriorFailure()
    {
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_product_type'] = 'course';
        $_POST['player_assignment'] = '1';

        $passed = intersoccer_validate_cart_item(false, 123, 1, null, null, null);

        $this->assertFalse($passed, 'Prior validation failure should be preserved');
    }
}
