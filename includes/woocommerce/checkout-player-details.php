<?php
/**
 * Checkout player lookup from guardian usermeta (Player Management SoT).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('intersoccer_get_player_details')) {
    /**
     * Helper function to get player details from user metadata
     *
     * @param int $user_id
     * @param int|string $player_index
     * @return array<string, string>
     */
    function intersoccer_get_player_details($user_id, $player_index) {
        $players = function_exists('intersoccer_get_user_players')
            ? intersoccer_get_user_players($user_id)
            : (get_user_meta($user_id, 'intersoccer_players', true) ?: []);

        if (!is_array($players)) {
            $players = [];
        }
        $slot = function_exists('intersoccer_resolve_intersoccer_players_meta_key')
            ? intersoccer_resolve_intersoccer_players_meta_key($players, $player_index)
            : (array_key_exists($player_index, $players) ? $player_index : null);

        if ($slot !== null && isset($players[$slot])) {
            $player = $players[$slot];
            $gender_val = isset($player['gender']) ? trim((string) ($player['gender'])) : '';
            if ($gender_val === '' && isset($player['player_gender'])) {
                $gender_val = trim((string) $player['player_gender']);
            }

            return [
                'first_name' => $player['first_name'] ?? '',
                'last_name' => $player['last_name'] ?? '',
                'name' => trim(($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? '')),
                'dob' => $player['dob'] ?? '',
                'gender' => $gender_val,
                'medical_conditions' => $player['medical_conditions'] ?? '',
                'player_id' => (string) ($player['player_id'] ?? ''),
            ];
        }

        return [
            'first_name' => 'Unknown',
            'last_name' => 'Player',
            'name' => 'Unknown Player',
            'dob' => '',
            'gender' => '',
            'medical_conditions' => '',
            'player_id' => '',
        ];
    }
}
