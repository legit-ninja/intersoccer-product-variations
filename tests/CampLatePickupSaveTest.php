<?php

use PHPUnit\Framework\TestCase;

class CampLatePickupSaveTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wordpress/');
        }
        require_once dirname(__DIR__) . '/includes/helpers.php';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    public function testLatePickupAdminFieldsSubmittedWhenSentinelPresent(): void
    {
        $_POST['_intersoccer_late_pickup_fields_present'] = [
            2 => '1',
        ];

        $this->assertTrue(intersoccer_late_pickup_admin_fields_submitted(12345, 2));
        $this->assertFalse(intersoccer_late_pickup_admin_fields_submitted(12345, 5));
    }

    public function testLatePickupAdminFieldsSubmittedForIndividualSave(): void
    {
        $_POST['_intersoccer_enable_late_pickup'] = 'yes';

        $this->assertTrue(intersoccer_late_pickup_admin_fields_submitted(12345, -1));
    }

    public function testLatePickupAdminFieldsNotSubmittedWhenAbsent(): void
    {
        $this->assertFalse(intersoccer_late_pickup_admin_fields_submitted(12345, 3));
    }
}
