# Security Audit Report

**Plugin:** InterSoccer Product Variations
**Version Audited:** 1.12.8
**Audit Date:** 2026-02-20

---

## Summary

Ten vulnerabilities were identified across the PHP backend and JavaScript frontend. Two are high severity (XSS), four are medium severity (CSRF, information disclosure, nonce exposure, unauthenticated writes), and four are low severity.

---

## HIGH Severity

### H1 — Stored XSS in Admin Order Preview Table

**File:** `includes/woocommerce/admin-ui.php`
**Lines:** ~890–912 (method `column_default` of `InterSoccer_Order_Preview_Table`)

The `missing_summary` and `risk_level` columns output data without `esc_html()`:

```php
// missing_summary column — no escaping:
$summary_html .= '<small>' . implode(', ', array_slice($unique_missing, 0, 5));

// risk_level column — no escaping:
$risk_reasons = implode('<br>', array_slice($item['risk_reasons'], 0, 2));
return sprintf('<span style="color: %s;">%s</span><br><small>%s</small>',
    $color,
    strtoupper($item['risk_level']),
    $risk_reasons   // unescaped
);
```

The `$unique_missing` array is built at line ~1055:

```php
$missing[] = $key . ' (incorrect: "' . $existing_value . '" should be "' . $corrected_value . '")';
```

where `$existing_value = $item->get_meta($key, true)` is raw order item meta. If any order has a poisoned `Activity Type` meta value containing `<script>alert(1)</script>`, it will execute in the WP admin panel when an admin views the Order Metadata Update Tool.

**Fix:** Wrap all dynamic values in `esc_html()` before concatenation.

---

### H2 — XSS via Player Name in Frontend Dropdown

**File:** `js/product-enhancer.js`
**Line:** 183

```javascript
$select.append(`<option value="${index}">${player.first_name} ${player.last_name}</option>`);
```

Player data comes from the `intersoccer_get_user_players` AJAX handler, which applies `sanitize_text_field()` server-side. `sanitize_text_field()` strips HTML tags but does **not** HTML-encode characters like `"`, `<`, `>`. A player name containing a double-quote (`"`) could break out of the option attribute and inject HTML. A name like `"><img src=x onerror=alert(1)>` would bypass the server-side sanitisation.

**Fix:** Use jQuery's `.text()` setter or explicitly encode before inserting into HTML:

```javascript
const $option = $('<option>').val(index).text(`${player.first_name} ${player.last_name}`);
$select.append($option);
```

---

## MEDIUM Severity

### M1 — Missing CSRF Protection on Order Preview Action

**File:** `includes/woocommerce/admin-ui.php`
**Function:** `intersoccer_render_update_orders_page()` (~line 1107)

The nonce (`intersoccer_update_orders_nonce`) is verified only when `update_selected_orders` is submitted. The `preview_updates` action executes without any nonce check, allowing a CSRF attack to force an admin's browser to trigger large, expensive order scans (e.g., `preview_limit=2000` or `preview_limit=-1`).

```php
// Nonce is checked here:
if (isset($_POST['update_selected_orders'])) {
    check_admin_referer('intersoccer_update_orders', 'intersoccer_update_orders_nonce');
    // ...
}
// But NOT here:
$show_preview = isset($_POST['preview_updates']) || isset($_POST['detailed_preview']);
```

**Fix:** Add `check_admin_referer('intersoccer_update_orders', 'intersoccer_update_orders_nonce')` at the top of the function when any POST action is detected.

---

### M2 — Active Nonce Exposed in Browser Console

**File:** `includes/elementor-widgets.php`
**Line:** ~1967

```php
debug('InterSoccer: Nonce:', '<?php echo wp_create_nonce('intersoccer_nonce'); ?>');
```

This PHP is rendered unconditionally (not gated by `WP_DEBUG`) and prints a fresh, valid nonce to the browser's developer console on every product page load. Anyone with browser console access (users, browser extensions, injected scripts) can read this nonce and use it to call any `intersoccer_nonce`-protected AJAX endpoint without proper authorisation.

**Fix:** Remove this debug statement or gate it behind a `WP_DEBUG` PHP conditional before the closing `?>`.

---

### M3 — Sensitive Customer Data in Debug Logs

**File:** `includes/woocommerce/cart-calculations.php`
**Lines:** 21–26

```php
intersoccer_debug('InterSoccer Cart Data: Full POST: ' . print_r($_POST, true));
intersoccer_debug('InterSoccer Cart Data: Full GET: ' . print_r($_GET, true));
```

Similar patterns exist in `ajax-handlers.php` (lines 41, 86, 147, 208). When `WP_DEBUG=true`, the complete request payload (which may include payment tokens, personal details, and session data) is written to the WooCommerce log file. On staging or development environments with log file access, this constitutes a data exposure risk.

**Fix:** Replace full `$_POST`/`$_GET` dumps with logging only specific, non-sensitive keys.

---

### M4 — Unauthenticated Guest Session Data Storage (DoS vector)

**File:** `includes/ajax-handlers.php`
**Lines:** 201–242

The `intersoccer_update_session_data` AJAX action is registered for `wp_ajax_nopriv_`, meaning unauthenticated users can call it. It writes to WordPress transients using a key derived from `session_id()`:

```php
$user_id = get_current_user_id() ?: 'guest_' . session_id();
set_transient('intersoccer_session_' . $user_id, $session_data, HOUR_IN_SECONDS);
```

An attacker could flood this endpoint with requests (each spawning a new PHP session) to create large numbers of transient records in the database, exhausting storage or causing performance degradation.

**Fix:** Either restrict this endpoint to authenticated users only (remove the `nopriv` hook), or implement rate limiting (e.g., per-IP transient count check).

---

## LOW Severity

### L1 — Silent Failure on Nonce Verification in `intersoccer_get_days_of_week`

**File:** `includes/ajax-handlers.php`
**Lines:** 325–332

```php
if (!wp_verify_nonce($nonce, 'intersoccer_nonce')) {
    return;  // No wp_die(), no error response
}
```

When nonce verification fails, the function returns silently, sending a 200 HTTP response with an empty body. Standard WordPress AJAX practice is to call `wp_send_json_error()` followed by `wp_die()`. The silent return can mask security issues from automated monitoring tools that inspect HTTP response codes and bodies.

**Fix:**
```php
if (!wp_verify_nonce($nonce, 'intersoccer_nonce')) {
    wp_send_json_error(['message' => __('Invalid nonce.', 'intersoccer-product-variations')], 403);
    wp_die();
}
```

---

### L2 — `_e()` Used with Raw HTML (Translation Injection)

**File:** `includes/woocommerce/admin-ui.php`
**Line:** ~273

```php
_e('Add, edit, or delete...use <a href="' . admin_url('edit.php?post_type=shop_coupon') . '">WooCommerce > Coupons</a>.', 'intersoccer-product-variations');
```

Passing HTML-containing strings through `_e()` allows a malicious translator (via a compromised .po/.mo file) to inject arbitrary HTML into the admin page. While this requires file-system access, it violates the principle of least privilege.

**Fix:** Separate the HTML structure from the translatable string, or use `wp_kses_post()` on the translated value.

---

### L3 — Loose Type Comparison for Player ID in Discount Logic

**File:** `includes/woocommerce/discounts.php`
**Lines:** 406–410

```php
if ($course_item['parent_product_id'] == $parent_product_id &&
    $course_item['assigned_player'] == $assigned_player) {
```

Loose `==` comparisons are used instead of strict `===`. In PHP, `0 == "any_string"` evaluates to `true` in older PHP versions (< 8.0), and string-to-number coercion can cause unexpected matches. If `$assigned_player` is `0` (no player assigned), it could match items with string player names on PHP < 8.

**Fix:** Use strict `===` comparisons throughout.

---

### L4 — Hardcoded Production Order and Variation IDs in Dead Debug Code

**File:** `includes/woocommerce/cart-calculations.php`
**Lines:** 1128–1158

```php
function debug_specific_order_38734() {
    $variation_id = 35319;
    // ...
}
// debug_specific_order_38734(); // Uncommented to run
```

Real production order ID (`38734`) and variation ID (`35319`) are hardcoded in the source. While this function is commented out, the IDs are visible in the public plugin source and could be used to enumerate objects if the plugin is ever publicly distributed.

**Fix:** Remove the function entirely or replace the IDs with placeholder values.

---

## Checklist of Recommended Fixes

| ID | Severity | File | Action |
|----|----------|------|--------|
| H1 | High | `admin-ui.php` | Add `esc_html()` around `$unique_missing` and `$risk_reasons` output |
| H2 | High | `product-enhancer.js` | Use jQuery DOM methods instead of template literals for player name |
| M1 | Medium | `admin-ui.php` | Add nonce check for `preview_updates` POST action |
| M2 | Medium | `elementor-widgets.php` | Remove or gate nonce debug `console.log` |
| M3 | Medium | `cart-calculations.php`, `ajax-handlers.php` | Log specific keys only, never full `$_POST`/`$_GET` |
| M4 | Medium | `ajax-handlers.php` | Restrict `update_session_data` to authenticated users or add rate limiting |
| L1 | Low | `ajax-handlers.php` | Add `wp_send_json_error()` + `wp_die()` on nonce failure |
| L2 | Low | `admin-ui.php` | Separate HTML from translatable strings |
| L3 | Low | `discounts.php` | Replace `==` with `===` for player ID comparisons |
| L4 | Low | `cart-calculations.php` | Remove dead debug function with hardcoded production IDs |
