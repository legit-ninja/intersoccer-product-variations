# InterSoccer Product Variations Plugin

## Overview
This plugin enhances the WooCommerce booking system for InterSoccer Switzerland by managing complex product variations, dynamic pricing calculations, and sophisticated sibling discount systems. It supports three main product types: Camps (full-week and single-day), Courses (seasonal with prorated pricing), and Birthdays, with comprehensive admin interfaces and multilingual support.

## Version
- **Version: 1.12.5**
- Release Date: December 5, 2025

## Features

### Product Types & Variations
- **Camps**: Full-week bookings (e.g., CHF 500/week) and single-day selections with per-day pricing (e.g., CHF 55/day)
- **Courses**: Seasonal programs with automatic end-date calculation, holiday exclusions, and remaining session-based pricing
- **Birthdays**: Special event bookings with custom attributes
- **Dynamic Attributes**: Supports `pa_activity-type`, `pa_intersoccer-venues`, `pa_program-season`, `pa_age-group`, `pa_canton-region`, `pa_city`, `pa_booking-type`, `pa_course-day`, `pa_camp-terms`, `pa_days-of-week`

### Advanced Pricing System
- **Camp Pricing**: Full-week fixed pricing vs. single-day cumulative pricing based on selected days
- **Course Pricing**: Pro-rated pricing based on remaining sessions in the season, accounting for holidays and course schedules
- **Real-time Calculations**: AJAX-powered price updates in cart and checkout
- **Currency Support**: CHF denomination with WooCommerce integration

### Comprehensive Discount System
- **Camp Discounts**:
  - Sibling: 20% off 2nd child, 25% off 3rd+ children
  - Progressive: Week 2 gets 10% off, Week 3+ gets 20% off (based on total weeks purchased)
  - Full-week bookings only (single-day excluded)
- **Course Discounts**:
  - Sibling: 20% off 2nd child, 30% off 3rd+ children
  - Same-season: 50% off 2nd course for same child (different days)
  - Retroactive: Applies even if first course was in previous order
- **Tournament Discounts**:
  - Sibling: 20% off 2nd child, 30% off 3rd+ children
  - Multiple days: Day 1 = 30 CHF, Day 2+ = 20 CHF (2 days for 50 CHF)
  - Retroactive: Applies even if first day was in previous order
- **Precise Allocation**: Context-aware discount application with cart validation
- **Order Metadata**: Discount details stored in order item metadata
- **Configurable**: All rates and features configurable via Admin UI

### Late Pickup System
- **Camp Add-on**: Optional late pickup service (18:00) with configurable pricing
- **Flexible Options**: Per-day selection or full-week pricing
- **Admin Configuration**: Customizable costs via WordPress options
- **Multilingual**: WPML integration for interface strings

### Admin Interface
- **Product Type Detection**: Automatic detection via attributes or categories with caching
- **Course Management**: Start dates, total weeks, holiday exclusions, end-date calculation
- **Variation Fields**: Custom meta fields for course parameters
- **Discount Management**: Configure all discount rules, rates, and retroactive settings
- **Order Lookback**: Configurable period (1-24 months) for checking previous orders
- **Debug Logging**: Comprehensive error logging for troubleshooting

### User Experience
- **Variation Details**: AJAX-powered variation information display
- **Player Assignment**: Integration with player management system
- **Cart Updates**: Real-time discount notifications and price adjustments
- **Multilingual Support**: WPML compatible (English, German, French)

## Installation
1. Upload the plugin files to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure WooCommerce product attributes and variations.
4. Set up InterSoccer-specific taxonomies (automatically registered).
5. Configure late pickup pricing in WordPress options.

## Development Workflow
- Code locally, deploy via SCP to `dev.intersoccer.legit.ninja`.
- Build Cypress E2E tests and PHPUnit unit tests.
- Commit to `github.com/legit-ninja/intersoccer-product-variations`.
- Tag releases (e.g., `v1.10.8`) with descriptive messages.
- Upload zip to `updates.underdogunlimited.com`.
- Test on `staging.intersoccer.ch`, then deploy to production.

## Testing
- **PHPUnit**: Unit tests in `/tests/` directory
- **Task Runner**: Use `task test` to run PHPUnit tests
- **Pre-commit Hooks**: Automatic test execution on commits

## Debugging
- Enable `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php` on Dev.
- Check `wp-content/debug.log` for detailed error logs.
- Use SSH/SCP for file access if activation fails.
- Review browser console logs and server logs on Dev.

## Key Dependencies
- **WooCommerce**: Core e-commerce functionality
- **WPML**: Multilingual support (optional)
- **PHPUnit**: Testing framework
- **Composer**: Dependency management

## Future Enhancements
- Weekly discount integration for multiple Summer Camps
- Google Sheets/Office365 export integration
- Advanced reporting and analytics
- API integrations for external booking systems

## Key Metrics
- Booking completion rate (>90%)
- Price calculation accuracy in cart and orders
- Discount application success rate
- Multilingual content coverage

## License
GPL-2.0 or later.

## Contributors
- Jeremy Lee
