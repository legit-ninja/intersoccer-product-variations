# InterSoccer Product Variations Plugin

## Overview
This plugin enhances the WooCommerce booking system for InterSoccer’s Camps, Courses, and Tickets by managing product variations, pricing calculations, and sibling discounts. It targets Swiss parents, coaches, and admins, integrating with WooCommerce to handle dynamic pricing and booking variations.

## Version
- **Version: 1.2.54**  
- Release Date: June 20, 2025

## Features
- **Product Variations**: Supports Camps (full week or single days), Courses (seasonal, prorated), and Birthdays with configurable attributes (e.g., `pa_activity-type`, `pa_intersoccer-venues`).
- **Pricing Calculation**: Dynamically calculates prices based on days (Camps) or remaining weeks (Courses), including currency denotation (e.g., CHF).
- **Sibling Discounts**:
  - Camps: 20% off 2nd child, 25% off 3rd+ children for different children.
  - Courses: 20% off 2nd child, 30% off 3rd+ children in the same Season; 50% off 2nd Course for the same child in the same Season.
- **Cart Updates**: Real-time price updates in the cart with applied discounts.
- **Order Metadata**: Includes discount details in order item metadata.
- **Multilingual**: Compatible with WPML (English, German, French).

## Installation
1. Upload the plugin files to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure WooCommerce product attributes and variations.

## Development Workflow
- Code locally, deploy via SCP to `dev.intersoccer.legit.ninja`.
- Build Cypress E2E tests and PHPUnit unit tests.
- Commit to `github.com/legit-ninja/intersoccer-product-variations`.
- Tag releases (e.g., `v1.2.53`) with descriptive messages.
- Upload zip to `updates.underdogunlimited.com`.
- Test on `staging.intersoccer.ch`, then deploy to production.

## Debugging
- Enable `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php` on Dev.
- Check `wp-content/debug.log` for errors.
- Use SSH/SCP for file access if activation fails.
- Review browser console logs and server logs on Dev.

## Future Enhancements
- Add weekly discount for multiple Summer Camps.
- Integrate with Google Sheets and Office365 for exports.

## Key Metrics
- Booking completion rate (>90%).
- Price calculation accuracy in cart and orders.

## License
GPL-2.0 or later.

## Contributors
- Jeremy Lee
