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
 *
 * NOTE: This test exercises the PRODUCTION code in cart-calculations.php,
 * not stub re-implementations. The bootstrap provides WP/WC mocks that allow
 * the production functions to run in a test environment.
 */

use PHPUnit\Framework\TestCase;


if (!class_exists('TestProductTypeRegistry')) {
    class TestProductTypeRegistry
    {
        /** @var array<int,string|null> */
        private static $types = [];

        public static function set($product_id, $type)
        {
            self::$types[(int) $product_id] = $type;
        }

        public static function get($product_id)
        {
            $id = (int) $product_id;
            return array_key_exists($id, self::$types) ? self::$types[$id] : null;
        }

        public static function reset()
        {
            self::$types = [];
        }
    }
}

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';

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
        $GLOBALS['intersoccer_test_products'] = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($GLOBALS['intersoccer_test_user_id']);
        unset($GLOBALS['wc_notices']);
        unset($GLOBALS['intersoccer_test_product_type']);
        unset($GLOBALS['intersoccer_test_product_categories']);
        unset($GLOBALS['intersoccer_test_product_name']);
        unset($GLOBALS['intersoccer_test_products']);
        if (class_exists('TestProductTypeRegistry')) {
            TestProductTypeRegistry::reset();
        }
        parent::tearDown();
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testProductRequiresAttendeeForCamp()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $this->assertTrue(
            intersoccer_product_requires_attendee(123),
            'Camp products should require attendee'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testProductRequiresAttendeeForCourse()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'course';
        $this->assertTrue(
            intersoccer_product_requires_attendee(123),
            'Course products should require attendee'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testProductRequiresAttendeeForBirthday()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'birthday';
        $this->assertTrue(
            intersoccer_product_requires_attendee(123),
            'Birthday products should require attendee'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testProductDoesNotRequireAttendeeForTournament()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'tournament';
        $this->assertFalse(
            intersoccer_product_requires_attendee(123),
            'Tournament products should not require attendee'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testProductDoesNotRequireAttendeeWhenNoType()
    {
        $GLOBALS['intersoccer_test_product_type'] = null;
        $this->assertFalse(
            intersoccer_product_requires_attendee(123),
            'Products without type should not require attendee'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testHasPostedPlayerAssignmentWithIndex()
    {
        $_POST['player_assignment'] = '2';
        $this->assertTrue(
            intersoccer_has_posted_player_assignment(),
            'Numeric player_assignment should be detected'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testHasPostedPlayerAssignmentWithAttendee()
    {
        $_POST['assigned_attendee'] = 'Jane Doe';
        $this->assertTrue(
            intersoccer_has_posted_player_assignment(),
            'assigned_attendee name should be detected'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testHasPostedPlayerAssignmentWithUuid()
    {
        $_POST['assigned_player_id'] = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertTrue(
            intersoccer_has_posted_player_assignment(),
            'UUID assigned_player_id should be detected'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testHasNoPostedPlayerAssignmentEmpty()
    {
        $_POST['player_assignment'] = '';
        $this->assertFalse(
            intersoccer_has_posted_player_assignment(),
            'Empty player_assignment should not count'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testHasNoPostedPlayerAssignmentZero()
    {
        $_POST['player_assignment'] = '0';
        $this->assertFalse(
            intersoccer_has_posted_player_assignment(),
            'Zero player_assignment should not count'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testHasNoPostedPlayerAssignmentMissing()
    {
        $this->assertFalse(
            intersoccer_has_posted_player_assignment(),
            'Missing POST fields should return false'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     * @group ac-c8-c9
     */
    public function testValidateRejectsGuestOnAttendeeRequiredProduct()
    {
        $GLOBALS['intersoccer_test_user_id'] = 0;
        $GLOBALS['intersoccer_test_product_type'] = 'camp';

        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);

        $this->assertFalse($passed, 'Guests should be rejected for attendee-required products');
        $this->assertNotEmpty($GLOBALS['wc_notices'], 'An error notice should be added');
        $this->assertStringContainsString(
            'log in',
            $GLOBALS['wc_notices'][0]['message'],
            'Notice should prompt login'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     * @group ac-c8-c9
     */
    public function testValidateRejectsLoggedInUserWithoutPlayer()
    {
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_product_type'] = 'course';

        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);

        $this->assertFalse($passed, 'Logged-in users without player selection should be rejected');
        $this->assertNotEmpty($GLOBALS['wc_notices'], 'An error notice should be added');
        $this->assertStringContainsString(
            'select an attendee',
            strtolower($GLOBALS['wc_notices'][0]['message']),
            'Notice should prompt attendee selection'
        );
    }

    /**
     * @group atc-validation
     * @group production-code
     * @group ac-c8-c9
     */
    public function testValidateAcceptsLoggedInUserWithPlayer()
    {
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_product_type'] = 'course';
        $_POST['player_assignment'] = '1';

        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);

        $this->assertTrue($passed, 'Logged-in users with player selection should be accepted');
        $this->assertEmpty($GLOBALS['wc_notices'], 'No error notices should be added');
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testValidateAcceptsNonAttendeeProduct()
    {
        $GLOBALS['intersoccer_test_user_id'] = 0;
        $GLOBALS['intersoccer_test_product_type'] = 'tournament';

        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);

        $this->assertTrue($passed, 'Non-attendee products should allow guest ATC');
    }

    /**
     * @group atc-validation
     * @group production-code
     * @group ac-c8-c9
     */
    public function testValidateAcceptsLoggedInUserWithUuidPlayer()
    {
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_product_type'] = 'birthday';
        $_POST['assigned_player_id'] = '550e8400-e29b-41d4-a716-446655440000';

        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);

        $this->assertTrue($passed, 'UUID player assignment should be accepted');
    }

    /**
     * @group atc-validation
     * @group production-code
     */
    public function testValidatePreservesPriorFailure()
    {
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_product_type'] = 'course';
        $_POST['player_assignment'] = '1';

        $passed = intersoccer_validate_cart_item(false, 123, 1, null, null, null);

        $this->assertFalse($passed, 'Prior validation failure should be preserved');
    }

    /**
     * Verify cart item data capture writes assigned_player index (not just assigned_attendee string)
     * so PM safety net can read it downstream.
     *
     * @group atc-validation
     * @group production-code
     * @group cart-data
     */
    public function testCartItemDataCapturesAssignedPlayerIndex()
    {
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $_POST['player_assignment'] = '2';

        $cart_item_data = intersoccer_add_custom_cart_item_data([], 123, 0);

        $this->assertArrayHasKey('assigned_player', $cart_item_data, 'assigned_player index must be captured');
        $this->assertEquals(2, $cart_item_data['assigned_player'], 'assigned_player should match posted index');
    }

    /**
     * Verify cart item data capture works with assigned_attendee field too
     *
     * @group atc-validation
     * @group production-code
     * @group cart-data
     */
    public function testCartItemDataCapturesFromAssignedAttendeeField()
    {
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $_POST['assigned_attendee'] = '1';

        $cart_item_data = intersoccer_add_custom_cart_item_data([], 123, 0);

        $this->assertArrayHasKey('assigned_player', $cart_item_data, 'assigned_player index must be captured from assigned_attendee');
    }

    /**
     * #77: int IDs must keep working (no object cast path).
     *
     * @group atc-validation
     * @group production-code
     * @group issue-77
     */
    public function testRequiresAttendeeAcceptsIntProductId()
    {
        unset($GLOBALS['intersoccer_test_product_type']);
        TestProductTypeRegistry::set(123, 'camp');

        $this->assertTrue(
            intersoccer_product_requires_attendee(123),
            'Integer parent product ID should require attendee for camps'
        );
    }

    /**
     * #77: simple WC_Product-like object must use get_id(), not (int) cast.
     *
     * @group atc-validation
     * @group production-code
     * @group issue-77
     */
    public function testRequiresAttendeeAcceptsSimpleProductObject()
    {
        unset($GLOBALS['intersoccer_test_product_type']);
        TestProductTypeRegistry::set(123, 'course');

        $product = new class {
            public function get_id() { return 123; }
            public function get_parent_id() { return 0; }
            public function is_type($type) { return $type === 'simple'; }
        };

        $this->assertTrue(
            intersoccer_product_requires_attendee($product),
            'Simple product object should resolve via get_id() and require attendee for courses'
        );
    }

    /**
     * #77: WC_Product_Variation must not warn, and must use parent for type/attendee.
     *
     * PHPUnit converts warnings to exceptions (phpunit.xml), so a blind (int) cast fails this test.
     *
     * @group atc-validation
     * @group production-code
     * @group issue-77
     */
    public function testRequiresAttendeeAcceptsVariationObjectUsesParentWithoutWarning()
    {
        if (!class_exists('WC_Product_Variation', false)) {
            eval('class WC_Product_Variation {}');
        }

        unset($GLOBALS['intersoccer_test_product_type']);
        // Parent is a camp; variation ID alone must not be typed as camp.
        TestProductTypeRegistry::set(100, 'camp');
        TestProductTypeRegistry::set(999, null);

        $variation = new class(999, 100) extends WC_Product_Variation {
            private $id;
            private $parent_id;
            public function __construct($id, $parent_id)
            {
                $this->id = (int) $id;
                $this->parent_id = (int) $parent_id;
            }
            public function get_id() { return $this->id; }
            public function get_parent_id() { return $this->parent_id; }
            public function is_type($type) { return $type === 'variation'; }
        };

        // Also assert no PHP warning/notice via an explicit handler (belt and suspenders).
        $warnings = [];
        set_error_handler(function ($errno, $errstr) use (&$warnings) {
            $warnings[] = [$errno, $errstr];
            return true;
        });
        try {
            $requires = intersoccer_product_requires_attendee($variation);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'Passing WC_Product_Variation must not emit warnings/notices');
        $this->assertTrue($requires, 'Variation should require attendee based on parent product type');

        // Opposite types: parent is tournament (no attendee); variation id alone is camp.
        // Using the variation ID by mistake would incorrectly return true.
        unset($GLOBALS['intersoccer_test_product_type']);
        TestProductTypeRegistry::reset();
        TestProductTypeRegistry::set(100, 'tournament');
        TestProductTypeRegistry::set(999, 'camp');
        $this->assertFalse(
            intersoccer_product_requires_attendee($variation),
            'Must use parent ID (100 / tournament), not variation ID (999 / camp)'
        );
    }

    /**
     * #77: resolve helper keeps ints and maps variation objects to parent.
     *
     * @group atc-validation
     * @group production-code
     * @group issue-77
     */
    public function testResolveAttendeeProductIdFromVariationAndInt()
    {
        $this->assertSame(55, intersoccer_resolve_attendee_product_id(55));

        $simple = new class {
            public function get_id() { return 77; }
            public function get_parent_id() { return 0; }
            public function is_type($type) { return $type === 'simple'; }
        };
        $this->assertSame(77, intersoccer_resolve_attendee_product_id($simple));

        $variation = new class {
            public function get_id() { return 999; }
            public function get_parent_id() { return 100; }
            public function is_type($type) { return $type === 'variation'; }
        };
        $this->assertSame(100, intersoccer_resolve_attendee_product_id($variation));
    }
}
