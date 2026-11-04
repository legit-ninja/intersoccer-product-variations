<?php
/**
 * PV #152: campaign joining user must be linked at order creation, not on payment_complete.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';

if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
    define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!function_exists('intersoccer_campaign_joining_email_meta_key')) {
    function intersoccer_campaign_joining_email_meta_key() {
        return '_intersoccer_campaign_joining_email';
    }
}

if (!function_exists('intersoccer_campaign_joining_meta_key')) {
    function intersoccer_campaign_joining_meta_key() {
        return '_intersoccer_campaign_joining';
    }
}

if (!function_exists('has_action')) {
    function has_action($hook, $callback = false) {
        if ($callback === false) {
            return !empty($GLOBALS['intersoccer_test_actions'][$hook]);
        }
        if (empty($GLOBALS['intersoccer_test_actions'][$hook])) {
            return false;
        }
        foreach ($GLOBALS['intersoccer_test_actions'][$hook] as $callbacks) {
            foreach ($callbacks as $registered) {
                if ($registered === $callback) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        $key = strtolower((string) $value);
        return $GLOBALS['pv152_test_users_by_email'][$key] ?? false;
    }
}

if (!class_exists('Pv152PaymentCompleteTestOrder')) {
    class Pv152PaymentCompleteTestOrder {
        public $meta = [];
        public $save_count = 0;
        public $update_log = [];

        public function get_meta($key) {
            return $this->meta[$key] ?? '';
        }

        public function update_meta_data($key, $value) {
            $this->meta[$key] = $value;
            $this->update_log[] = $key;
        }

        public function save() {
            $this->save_count++;
        }
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/campaign-leads.php';
require_once dirname(__DIR__) . '/includes/woocommerce/campaign-checkout-field.php';

class Pv152PaymentCompleteTest extends TestCase
{
    private const ORDER_ID = 88015201;
    private const LINKED_USER = 88015202;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['intersoccer_test_actions'] = [];
        $GLOBALS['pv152_test_users_by_email'] = [
            'guardian.peer@example.com' => (object) ['ID' => self::LINKED_USER],
        ];
        unset($GLOBALS['intersoccer_test_orders']);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['intersoccer_test_actions'],
            $GLOBALS['pv152_test_users_by_email'],
            $GLOBALS['intersoccer_test_orders'],
            $_POST
        );
        parent::tearDown();
    }

    public function test_pv152_payment_complete_does_not_write_order(): void
    {
        $this->assertFalse(
            has_action('woocommerce_payment_complete', 'intersoccer_campaign_link_joining_user_on_payment'),
            'Campaign linking must not run on payment_complete'
        );

        $tracked = new Pv152PaymentCompleteTestOrder();
        $tracked->meta[intersoccer_campaign_joining_email_meta_key()] = 'guardian.peer@example.com';
        $GLOBALS['intersoccer_test_orders'][self::ORDER_ID] = $tracked;

        do_action('woocommerce_payment_complete', self::ORDER_ID);

        $this->assertSame(0, $tracked->save_count, 'Payment complete must not persist order meta');
        $this->assertSame([], $tracked->update_log, 'Payment complete must not update order meta');

        $_POST['intersoccer_campaign_joining'] = 'Peer Child';
        $_POST['intersoccer_campaign_joining_email'] = 'guardian.peer@example.com';

        intersoccer_campaign_save_joining_meta(self::ORDER_ID);

        $this->assertSame(1, $tracked->save_count, 'Order creation should persist joining meta once');
        $this->assertSame(
            self::LINKED_USER,
            (int) $tracked->meta[intersoccer_campaign_joining_user_meta_key()],
            'Joining user id should be stored at order creation'
        );
    }
}
