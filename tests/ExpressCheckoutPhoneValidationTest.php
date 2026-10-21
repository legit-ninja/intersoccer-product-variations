<?php
/**
 * Express Store API billing phone completeness (classic checkout uses the phone validator plugin).
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/express-checkout-player-gate.php';

if (!class_exists('IntersoccerExpressPhoneTestRequest')) {
    class IntersoccerExpressPhoneTestRequest {
        private $params;

        public function __construct(array $params)
        {
            $this->params = $params;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }
    }
}

class ExpressCheckoutPhoneValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['intersoccer_test_options']['woocommerce_checkout_phone_field'] = 'required';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['intersoccer_test_options']['woocommerce_checkout_phone_field']);
        parent::tearDown();
    }

    private function phoneOrder($phone, $country = '')
    {
        return new class($phone, $country) {
            private $phone;
            private $country;

            public function __construct($phone, $country)
            {
                $this->phone = $phone;
                $this->country = $country;
            }

            public function get_billing_phone()
            {
                return $this->phone;
            }

            public function get_billing_country()
            {
                return $this->country;
            }
        };
    }

    private function expressPhoneRequest(array $billing)
    {
        return new IntersoccerExpressPhoneTestRequest([
            'billing_address' => $billing,
            'payment_data' => [['key' => 'express_payment_type', 'value' => 'apple_pay']],
        ]);
    }

    public static function completePhoneProvider(): array
    {
        return [
            ['+41 79 123 45 67'],
            ['079 123 45 67'],
            ['0041791234567'],
            ['+33 6 12 34 56 78'],
            ['+49 151 23456789'],
        ];
    }

    public static function incompletePhoneProvider(): array
    {
        return [
            ['079 123'],
            ['+41 79 12'],
            ['12345'],
            ['+41 79 123 45 678'],
            ['abc'],
        ];
    }

    #[DataProvider('completePhoneProvider')]
    public function testPhoneIsComplete($phone)
    {
        $this->assertTrue(intersoccer_phone_is_complete($phone, 'CH'));
    }

    #[DataProvider('incompletePhoneProvider')]
    public function testPhoneIsIncomplete($phone)
    {
        $this->assertFalse(intersoccer_phone_is_complete($phone, 'CH'));
    }

    public function testExpressStoreApiIncompletePhoneIsRefused()
    {
        try {
            intersoccer_store_api_require_billing_phone(
                $this->phoneOrder(''),
                $this->expressPhoneRequest(['phone' => '079 123'])
            );
            $this->fail('Expected the checkout to be refused');
        } catch (Exception $e) {
            $this->assertSame(intersoccer_billing_phone_incomplete_message(), $e->getMessage());
            $this->assertSame(400, $e->getCode());
        }
    }

    #[DataProvider('completePhoneProvider')]
    public function testExpressStoreApiCompletePhonePasses($phone)
    {
        intersoccer_store_api_require_billing_phone(
            $this->phoneOrder(''),
            $this->expressPhoneRequest(['phone' => $phone])
        );
        $this->assertTrue(true);
    }

    public function testExpressStoreApiEmptyPhoneUsesRequiredError()
    {
        try {
            intersoccer_store_api_require_billing_phone(
                $this->phoneOrder(''),
                $this->expressPhoneRequest(['phone' => ''])
            );
            $this->fail('Expected the checkout to be refused');
        } catch (Exception $e) {
            $this->assertSame(intersoccer_billing_phone_required_message(), $e->getMessage());
        }
    }

    public function testNonExpressStoreApiIncompletePhonePasses()
    {
        $thrown = null;
        try {
            intersoccer_store_api_require_billing_phone(
                $this->phoneOrder('079 123'),
                new IntersoccerExpressPhoneTestRequest([
                    'billing_address' => ['phone' => '079 123'],
                    'payment_data' => [],
                ])
            );
        } catch (Exception $e) {
            $thrown = $e;
        }
        $this->assertNull($thrown);
    }
}
