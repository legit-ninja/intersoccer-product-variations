# Changelog

All notable changes to the InterSoccer Product Variations plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.10.16] - 2025-10-16

### Added
- Comprehensive logging to diagnose French single-days booking issue
- Validation to prevent single-day camp registration without any days selected
- Late Pick Up checkbox enabled by default for camp variations with improved styling

### Fixed
- AJAX handler for camp pricing in Elementor widgets
- Camp variation hook firing issues with detailed debug logging
- Camp detection simplified to use `intersoccer_is_camp()` on parent product

### Changed
- Replaced `woocommerce_wp_checkbox` with manual HTML for better control
- Updated camp detection to check variation attributes directly
- Moved Late Pick Up enable/disable to variation level for finer control

## [1.10.15] - 2025-10-14

### Added
- Per-product Late Pick Up enable/disable option
- Detailed debug logging for camp variation hooks investigation
- Debug output for checkbox rendering

### Changed
- Late Pick Up feature now configurable at variation level instead of globally

## [1.10.14] - 2025-10-09

### Fixed
- Weekly rate calculation for courses and remaining sessions
- Parent product attributes now properly added to order item metadata

### Changed
- Updated README.md with accurate plugin description based on current codebase review

## [1.10.13] - 2025-10-08

### Added
- Add-to-cart button now disabled until price calculations are complete for courses
- Add-to-cart button disabled until single-day price is updated for camps

### Improved
- User experience by preventing cart additions with incomplete pricing

## [1.10.12] - 2025-10-06

### Changed
- Version numbering format updated

## [1.10.11] - 2025-10-05

### Added
- Late pick up options for camps with configurable pricing

### Changed
- Version bump for late pickup feature release

## [1.10.10] - 2025-09-08

### Added
- Auto-expire products feature to automatically remove outdated bookings

## [1.10.9] - 2025-08-25

### Added
- Course end-date recalculation to variation health page for admin monitoring

## [1.10.8] - 2025-08-23

### Changed
- Admin UI overhaul with improved interface and order updates
- Removed duplicate metadata in order items for cleaner data storage

### Improved
- Order item metadata and meta display during checkout

## [1.10.7] - 2025-08-17

### Fixed
- Cart and checkout discount reliability improvements
- WooCommerce modifications refactored for better reliability

### Changed
- Refactored WooCommerce modifications for modular maintenance

## [1.10.6] - 2025-08-08

### Fixed
- Course end-date calculation corrected
- Order item metadata restored and reinforced

### Changed
- Refactored WooCommerce modifications for modular maintenance
- Cart items metadata formatting corrected

## [1.10.5] - 2025-08-05

### Fixed
- End-date calculation for courses corrected
- All metadata now properly saved to order items

## [1.10.4] - 2025-07-30

### Added
- Logging for rendering discount checkout
- Improved logging and validation throughout

### Fixed
- Course duration now includes holidays correctly
- Multi-day select visibility restored
- Color of meta attributes in single-product template
- Extra text removed from holiday display

### Changed
- Updated camp select CSS for better appearance
- Updated AJAX handling for more reliable operations
- Reinforced item metadata handling

## [1.10.3] - 2025-07-27

### Fixed
- Course start and end dates forced to correct values
- Start and end dates for courses fixed

## [1.10.2] - 2025-07-22

### Added
- Holiday field for courses with proper scheduling
- Remaining weeks message fixed for courses

### Fixed
- Family and combo discounts calculation
- Multi-holidays support for variations

---

## Version History Summary

- **1.10.16** (Current) - French booking diagnostics, camp validation, Late Pick Up improvements
- **1.10.15** - Per-product Late Pick Up controls
- **1.10.14** - Course pricing calculations and metadata fixes
- **1.10.13** - Cart button UX improvements
- **1.10.12-11** - Late pick up feature introduction
- **1.10.10** - Auto-expire products
- **1.10.9** - Course health monitoring
- **1.10.8** - Admin UI overhaul
- **1.10.7** - Discount reliability improvements
- **1.10.6** - Course calculations and metadata fixes
- **1.10.5** - Order metadata improvements
- **1.10.4** - Holiday support and UI fixes
- **1.10.3** - Course date calculations
- **1.10.2** - Holiday fields and discount fixes

## Development Guidelines

### Creating a Release
1. Update version in `intersoccer-product-variations.php`
2. Update this CHANGELOG.md with new version section
3. Update README.md version if needed
4. Commit changes: `git commit -m "Release version X.Y.Z"`
5. Create tag: `git tag -a vX.Y.Z -m "Version X.Y.Z"`
6. Push with tags: `git push origin --tags`
7. Create release package for distribution

### Commit Message Guidelines
- Use clear, descriptive commit messages
- Prefix with category when appropriate (fix:, feat:, refactor:, debug:)
- Reference issue numbers when applicable
- Keep commits focused on single concerns

### Testing Before Release
- Run PHPUnit tests: `task test`
- Test on staging environment
- Verify all discount calculations
- Check multilingual support (EN, DE, FR)
- Validate pricing for camps and courses

## Contributors
- Jeremy Lee (Primary Developer)

## License
GPL-2.0 or later
