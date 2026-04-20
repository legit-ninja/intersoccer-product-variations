<?php
/**
 * Unit tests for intersoccer_resolve_intersoccer_players_meta_key (sparse keys vs posted ordinals).
 *
 * Run: php vendor/phpunit/phpunit/phpunit --no-configuration tests/PlayersMetaKeyResolutionTest.php
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

require_once dirname(__DIR__) . '/includes/helpers.php';

class PlayersMetaKeyResolutionTest extends TestCase {

    public function test_exact_meta_key_wins() {
        $players = [
            5 => ['first_name' => 'A', 'gender' => 'female'],
        ];
        $this->assertSame(5, intersoccer_resolve_intersoccer_players_meta_key($players, 5));
        $r = intersoccer_resolve_intersoccer_players_meta_key($players, '5');
        $this->assertTrue($r === 5 || $r === '5', 'string "5" should resolve to the same slot as int 5');
    }

    public function test_ordinal_maps_to_first_sparse_key() {
        $players = [
            5 => ['first_name' => 'A', 'gender' => 'female'],
            7 => ['first_name' => 'B', 'gender' => 'male'],
        ];
        $this->assertSame(5, intersoccer_resolve_intersoccer_players_meta_key($players, 0));
        $this->assertSame(7, intersoccer_resolve_intersoccer_players_meta_key($players, 1));
    }

    public function test_empty_and_invalid() {
        $players = [0 => ['gender' => 'female']];
        $this->assertNull(intersoccer_resolve_intersoccer_players_meta_key([], 0));
        $this->assertNull(intersoccer_resolve_intersoccer_players_meta_key($players, ''));
        $this->assertNull(intersoccer_resolve_intersoccer_players_meta_key($players, 99));
        $this->assertNull(intersoccer_resolve_intersoccer_players_meta_key($players, 'x'));
    }
}
