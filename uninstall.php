<?php
/**
 * Remove the repair capability and repair options. Product and order data stay.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

$contract = __DIR__ . '/includes/woocommerce/repair-contract.php';
if (is_readable($contract)) {
	require_once $contract;
}

if (function_exists('intersoccer_pv_repair_uninstall')) {
	intersoccer_pv_repair_uninstall();
}
