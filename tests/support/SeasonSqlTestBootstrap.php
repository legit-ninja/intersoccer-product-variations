<?php
/**
 * Bootstrap $wpdb season SQL fixture and reset season resolution caches between tests.
 */

require_once __DIR__ . '/WpdbSeasonSqlMock.php';

/**
 * @param array{terms?:array,term_taxonomy?:array,icl?:array}|null $fixture
 */
function intersoccer_pv_test_bootstrap_season_sql_wpdb(?array $fixture = null): WpdbSeasonSqlMock
{
    $wpdb = new WpdbSeasonSqlMock();
    $wpdb->load_fixture($fixture ?? WpdbSeasonSqlMock::autumn_2026_wpml_fixture());
    $GLOBALS['wpdb'] = $wpdb;
    $GLOBALS['intersoccer_test_transients'] = [];
    $GLOBALS['intersoccer_test_object_cache'] = [];
    if (function_exists('intersoccer_pv_discount_season_term_id_clear_request_cache')) {
        intersoccer_pv_discount_season_term_id_clear_request_cache();
    }
    return $wpdb;
}

function intersoccer_pv_test_clear_season_resolution_persistent_caches(): void
{
    $GLOBALS['intersoccer_test_transients'] = [];
    $GLOBALS['intersoccer_test_object_cache'] = [];
    if (function_exists('intersoccer_pv_discount_season_term_id_clear_request_cache')) {
        intersoccer_pv_discount_season_term_id_clear_request_cache();
    }
}
