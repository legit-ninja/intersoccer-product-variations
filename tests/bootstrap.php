<?php
// Load Composer autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Load WordPress testing environment (adjust path to your local WP tests).
define('WP_TESTS_DIR', '/path/to/wordpress/tests/phpunit');  // Update this.
require_once WP_TESTS_DIR . '/includes/functions.php';

// Activate the plugin/theme in tests.
function _manually_load_plugin() {
    require dirname(__DIR__) . '/plugin.php';  // Adjust for theme if needed.
}
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

require_once WP_TESTS_DIR . '/includes/bootstrap.php';