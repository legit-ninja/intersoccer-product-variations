# InterSoccer Product Variations Plugin

## Overview
The `intersoccer-product-variations` plugin extends WooCommerce (WooComm) functionality for InterSoccer Switzerland's event booking system. It manages product variations for soccer camps, courses, and birthdays, enabling dynamic assignment of attributes like "Days-of-week" for camps and prorated discounts for courses based on remaining weeks. The plugin ensures a seamless user experience for parents assigning children (players) to events during the purchase process.

This plugin is part of the InterSoccer Website Redesign project, built on WordPress (WP) and WooComm, aligning with InterSoccer's business model of offering soccer camps (full week or single days), courses (season-long, prorated), and birthday events.

**Author**: Jeremy Lee

## Features
- **Dynamic Product Variations**:
  - Camps: Supports "Full Week" or "Single Day(s)" variations with a multi-select (select2) interface for choosing days (e.g., Monday, Tuesday) based on the `pa_days-of-week` attribute.
  - Courses: Applies prorated pricing based on `Start-Date` and `End-Date` attributes, discounting based on remaining weeks (e.g., 16-week course with 10 weeks left reduces price by 6 weeks).
  - Birthdays: Manages variations like additional t-shirts or medals.
- **Player Assignment**: Integrates with the player-management-plugin to assign children to events during checkout.
- **Attribute Handling**: Adds all product attributes (e.g., "Assignee", "Days-of-week", "Discount") to the cart and order details, visible to customers.
- **Buy Now/Add to Cart**: Enables buttons only after a player is assigned to the event.

## Installation
1. **Clone the Repository**:
   ```bash
   git clone https://github.com/legit-ninja/intersoccer-product-variations.git
   ```
2. **Install Dependencies**:
   Ensure WordPress and WooCommerce are installed. Copy the plugin folder to `wp-content/plugins/`.
3. **Activate Plugin**:
   In the WordPress admin panel, navigate to Plugins > Installed Plugins and activate "InterSoccer Product Variations".
4. **Configure WooCommerce**:
   Ensure product attributes (`pa_days-of-week`, `pa_start-date`, `pa_end-date`, etc.) are set up in WooCommerce for camps and courses.

## Usage
1. **Create Products in WooCommerce**:
   - Camps: Set as Variable Products with attributes for "Days-of-week" and variations for "Full Week" or "Single Day(s)".
   - Courses: Set as Variable Products with `Start-Date` and `End-Date` attributes for proration logic.
   - Birthdays: Set as Simple Products with static attributes or variations for add-ons.
2. **Parent Workflow**:
   - Parents select a product, configure variations (e.g., select days for camps), and assign a player.
   - The plugin dynamically displays a select2 multi-select for single-day camp variations and calculates prorated course prices.
   - Attributes are added to the cart and visible in order confirmation emails.
3. **Admin Workflow**:
   - Shop managers can view assigned players and event details in WooCommerce orders.

## Development
- **Dependencies**: Requires WordPress, WooCommerce, and the `player-management-plugin` for player assignment.
- **Testing**: Use Cypress tests (planned) to validate variation logic and checkout flows. Refer to the project’s primary objectives for bug fixes and security enhancements.
- **Code Structure**:
  - `includes/`: Core logic for variation handling and attribute assignment.
  - `assets/`: JS (select2 integration) and CSS for frontend forms.
  - `templates/`: Custom WooCommerce templates for variation displays.

## Contribution
1. Fork the repository.
2. Create a feature branch (`git checkout -b feature/YourFeature`).
3. Commit changes (`git commit -m 'Add YourFeature'`).
4. Push to the branch (`git push origin feature/YourFeature`).
5. Open a Pull Request.

Please adhere to WordPress coding standards and include unit tests for new features.

## Issues
Report bugs or suggest features via the [GitHub Issues](https://github.com/legit-ninja/intersoccer-product-variations/issues) page.

## License
GPLv2 or later, compatible with WordPress.