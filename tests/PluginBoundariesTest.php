<?php
/**
 * Ecosystem boundary tests for intersoccer-product-variations.
 */

use PHPUnit\Framework\TestCase;

class PluginBoundariesTest extends TestCase
{
    // Regression: ECO-001 — Product-variations duplicates coach/organizer role registration owned by player-management
    public function test_only_player_management_registers_coach_role()
    {
        $main_plugin = file_get_contents(dirname(__DIR__) . '/intersoccer-product-variations.php');

        $this->assertIsString($main_plugin);
        $this->assertDoesNotMatchRegularExpression(
            "/add_role\s*\(\s*['\"]coach['\"]/",
            $main_plugin,
            'Player Management owns coach role registration; PV must not call add_role for coach'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/add_role\s*\(\s*['\"]organizer['\"]/",
            $main_plugin,
            'Player Management owns organizer role registration; PV must not call add_role for organizer'
        );
    }

    public function test_release_does_not_include_the_one_time_course_holiday_fixer()
    {
        $main_plugin = file_get_contents(dirname(__DIR__) . '/intersoccer-product-variations.php');
        $this->assertStringNotContainsString('utils/fix-course-holidays.php', $main_plugin);

        $admin_ui = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/admin-ui.php');
        $this->assertStringContainsString("function_exists('intersoccer_course_holiday_fix_has_run')", $admin_ui);
    }
}
