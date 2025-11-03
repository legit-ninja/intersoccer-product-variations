/**
 * InterSoccer Product Enhancer
 * Handles frontend product page enhancements for player selection and day selection
 */
(function($) {
    'use strict';

    // Main enhancer object
    window.InterSoccerProductEnhancer = {
        // Configuration
        config: {
            productId: 0,
            productType: '',
            userId: 0,
            isVariable: false,
            preloadedDays: [],
            initialPlayers: []
        },

        // State
        state: {
            selectedDays: [],
            lastVariation: null,
            formSelector: 'form.cart, form.variations_form, .woocommerce-product-details form, .single-product form'
        },

        // Initialize the enhancer
        init: function() {
            this.loadConfig();
            this.bindEvents();
            this.injectFields();
            console.log('InterSoccer: Product enhancer initialized for product', this.config.productId);
        },

        // Load configuration from data attributes
        loadConfig: function() {
            const $data = $('#intersoccer-enhancement-data');
            if ($data.length) {
                this.config = {
                    productId: parseInt($data.data('product-id')) || 0,
                    productType: $data.data('product-type') || '',
                    userId: parseInt($data.data('user-id')) || 0,
                    isVariable: $data.data('is-variable') === '1',
                    preloadedDays: JSON.parse($data.data('preloaded-days') || '[]'),
                    initialPlayers: JSON.parse($data.data('initial-players') || '[]')
                };
            }
        },

        // Bind event handlers
        bindEvents: function() {
            const self = this;
            const $form = $(this.state.formSelector);

            // Form submission validation is now handled by elementor-widgets.php
            // Only keeping quantity enforcement here
            $form.on('submit', function(e) {
                // Force quantity to 1 for InterSoccer products
                if (['camp', 'course'].includes(self.config.productType)) {
                    $form.find('input[name="quantity"], .quantity input[type="number"]').val(1);
                }
                console.log('InterSoccer Product Enhancer: Form submitting, quantity set to 1');
            });

            // Variation change handler
            $form.on('found_variation', function(event, variation) {
                self.handleVariationChange(variation);
            });

            // Reset handler
            $form.on('reset_data', function() {
                self.handleReset();
            });

            // Player selection change
            $form.on('change', '.intersoccer-player-select', function() {
                // For camps, delegate button state to elementor-widgets.php via custom event
                if (self.config.productType === 'camp') {
                    console.log('InterSoccer Product Enhancer: Player changed, triggering intersoccer_update_button_state event');
                    $form.trigger('intersoccer_update_button_state');
                } else {
                    self.updateButtonState();
                }
            });

            // Booking type change
            $form.on('change', 'select[name="attribute_pa_booking-type"]', function() {
                self.handleBookingTypeChange($(this).val());
            });
        },

        // Inject fields into the form
        injectFields: function() {
            const $form = $(this.state.formSelector);
            if (!$form.length) {
                console.error('InterSoccer: Product form not found');
                return;
            }

            // Don't inject if already present
            if ($form.find('.intersoccer-player-selection').length > 0) {
                return;
            }

            this.injectPlayerSelection($form);
            
            if (this.config.isVariable && this.config.productType === 'camp') {
                this.injectDaySelection($form);
            }

            this.populatePlayerSelect($form);
            // For camps, delegate to elementor-widgets.php
            if (this.config.productType === 'camp') {
                $form.trigger('intersoccer_update_button_state');
            } else {
                this.updateButtonState();
            }
        },

        // Inject player selection field
        injectPlayerSelection: function($form) {
            const template = $('#intersoccer-player-selection-template').html();
            if (!template) {
                console.error('InterSoccer: Player selection template not found');
                return;
            }

            const $target = this.findInjectionTarget($form);
            $target.append(template);
        },

        // Inject day selection field
        injectDaySelection: function($form) {
            const template = $('#intersoccer-day-selection-template').html();
            if (!template) {
                console.error('InterSoccer: Day selection template not found');
                return;
            }

            const $target = this.findInjectionTarget($form);
            $target.append(template);
        },

        // Find the best place to inject fields
        findInjectionTarget: function($form) {
            // Try variations table first
            let $target = $form.find('.variations tbody, .variations_table');
            if ($target.length) {
                return $target;
            }

            // Try variations container
            $target = $form.find('.variations, .woocommerce-variation');
            if ($target.length) {
                return $target;
            }

            // Fallback to form itself
            return $form;
        },

        // Populate player select dropdown
        populatePlayerSelect: function($form) {
            const $select = $form.find('.intersoccer-player-select');
            if (!$select.length || !this.config.userId) {
                return;
            }

            // Clear existing options except first
            $select.find('option:not(:first)').remove();

            // Add players
            if (this.config.initialPlayers.length > 0) {
                this.config.initialPlayers.forEach((player, index) => {
                    $select.append(`<option value="${index}">${player.first_name} ${player.last_name}</option>`);
                });
            } else {
                // Show link to add players
                const $content = $form.find('.intersoccer-player-content');
                $content.append('<p>No players registered. <a href="' + intersoccerCheckout.manage_players_url + '">Add a player</a>.</p>');
            }
        },

        // Handle variation change
        handleVariationChange: function(variation) {
            this.state.lastVariation = variation;
            
            if (variation && variation.intersoccer_camp_data) {
                this.handleCampVariation(variation.intersoccer_camp_data);
            } else if (variation && variation.intersoccer_course_data) {
                this.handleCourseVariation(variation.intersoccer_course_data);
            }

            // For camps, delegate to elementor-widgets.php
            if (this.config.productType === 'camp') {
                $(this.state.formSelector).trigger('intersoccer_update_button_state');
            } else {
                this.updateButtonState();
            }
        },

        // Handle camp-specific variation
        handleCampVariation: function(campData) {
            if (campData.is_single_day) {
                this.showDaySelection();
                this.renderDayCheckboxes();
            } else {
                this.hideDaySelection();
            }
        },

        // Handle course-specific variation
        handleCourseVariation: function(courseData) {
            this.displayCourseInfo(courseData);
        },

        // Handle booking type change
        handleBookingTypeChange: function(bookingType) {
            if (!bookingType) return;
            if (bookingType === 'single-days' || bookingType === 'à la journée' || bookingType === 'a-la-journee' || bookingType.toLowerCase().includes('single') || bookingType.toLowerCase().includes('journée') || bookingType.toLowerCase().includes('journee')) {
                this.showDaySelection();
                this.renderDayCheckboxes();
            } else {
                this.hideDaySelection();
            }
            // For camps, delegate to elementor-widgets.php
            if (this.config.productType === 'camp') {
                $(this.state.formSelector).trigger('intersoccer_update_button_state');
            } else {
                this.updateButtonState();
            }
        },

        // Show day selection
        showDaySelection: function() {
            $(this.state.formSelector).find('.intersoccer-day-selection').show();
        },

        // Hide day selection
        hideDaySelection: function() {
            const $form = $(this.state.formSelector);
            $form.find('.intersoccer-day-selection').hide();
            $form.find('input[name="camp_days[]"]').remove();
            this.state.selectedDays = [];
        },

        // Render day checkboxes
        renderDayCheckboxes: function() {
            const $form = $(this.state.formSelector);
            const $container = $form.find('.intersoccer-day-checkboxes');
            
            if (!$container.length || this.config.preloadedDays.length === 0) {
                return;
            }

            const self = this;
            $container.empty();

            this.config.preloadedDays.forEach(day => {
                const isChecked = this.state.selectedDays.includes(day);
                const $label = $(`
                    <label style="margin-right: 10px; display: inline-block;">
                        <input type="checkbox" name="camp_days_temp[]" value="${day}" 
                               class="intersoccer-day-checkbox" ${isChecked ? 'checked' : ''}> ${day}
                    </label>
                `);
                $container.append($label);
            });

            // Bind change events
            $container.find('.intersoccer-day-checkbox').on('change', function() {
                self.handleDayChange();
            });
        },

        // Handle day checkbox change
        handleDayChange: function() {
            const $form = $(this.state.formSelector);
            
            // Update selected days
            this.state.selectedDays = $form.find('.intersoccer-day-checkbox:checked')
                .map(function() { return $(this).val(); }).get();

            // Update hidden inputs
            $form.find('input[name="camp_days[]"]').remove();
            this.state.selectedDays.forEach(day => {
                $form.append(`<input type="hidden" name="camp_days[]" value="${day}">`);
            });

            // For camps, delegate to elementor-widgets.php
            if (this.config.productType === 'camp') {
                $form.trigger('intersoccer_update_button_state');
            } else {
                this.updateButtonState();
            }
        },

        // Display course information
        displayCourseInfo: function(courseData) {
            const $form = $(this.state.formSelector);
            let $display = $form.find('.intersoccer-course-info');

            if (!$display.length) {
                // Position after the variations table
                const $variationsTable = $form.find('.variations');
                if ($variationsTable.length) {
                    $variationsTable.after('<div class="intersoccer-course-info"></div>');
                } else {
                    // Fallback: before add to cart button
                    $form.find('.single_add_to_cart_button').before('<div class="intersoccer-course-info"></div>');
                }
                $display = $form.find('.intersoccer-course-info');
            }

            let html = '<div class="intersoccer-course-details">';
            if (courseData.start_date) {
                const startDate = new Date(courseData.start_date).toLocaleDateString('de-CH');
                html += `<p><strong>Start Date:</strong> ${startDate}</p>`;
            }
            if (courseData.end_date) {
                const endDate = new Date(courseData.end_date).toLocaleDateString('de-CH');
                html += `<p><strong>End Date:</strong> ${endDate}</p>`;
            }
            if (courseData.holidays && courseData.holidays.length > 0) {
                const holidays = courseData.holidays.map(date => new Date(date).toLocaleDateString('de-CH')).join(', ');
                html += `<p><strong>Holidays (No Session):</strong> ${holidays}</p>`;
            }
            if (courseData.remaining_sessions) {
                html += `<p><strong>Remaining Sessions:</strong> ${courseData.remaining_sessions}`;
                if (courseData.total_sessions) {
                    html += ` of ${courseData.total_sessions}`;
                }
                html += `</p>`;
            }
            html += '</div>';

            $display.html(html);
        },

        // Validate form before submission
        validateForm: function() {
            const $form = $(this.state.formSelector);
            
            // Check player selection
            const playerId = $form.find('.intersoccer-player-select').val();
            if (!playerId) {
                this.showError('player', 'Please select an attendee.');
                return false;
            }

            // Day validation is now handled by elementor-widgets.php
            // Commenting out to prevent conflicts with the new system
            /*
            // Check day selection for single-day camps
            const bookingType = $form.find('select[name="attribute_pa_booking-type"]').val();
            if (bookingType === 'single-days' && this.state.selectedDays.length === 0) {
                this.showError('days', 'Please select at least one day.');
                return false;
            }
            */

            // Force quantity to 1 for InterSoccer products
            if (['camp', 'course'].includes(this.config.productType)) {
                $form.find('input[name="quantity"], .quantity input[type="number"]').val(1);
            }

            return true;
        },

        // Show error message
        showError: function(type, message) {
            const $form = $(this.state.formSelector);
            let $notification;

            if (type === 'player') {
                $notification = $form.find('.intersoccer-attendee-notification');
            } else if (type === 'days') {
                $notification = $form.find('.intersoccer-day-notification');
            }

            if ($notification.length) {
                $notification.text(message).show();
                setTimeout(() => $notification.hide(), 5000);
            }
        },

        // Update add to cart button state
        updateButtonState: function() {
            // For camps, button state is handled by elementor-widgets.php
            if (this.config.productType === 'camp') {
                console.log('InterSoccer Product Enhancer: Skipping updateButtonState for camp (handled by elementor-widgets.php)');
                return;
            }
            
            const $form = $(this.state.formSelector);
            const $button = $form.find('button.single_add_to_cart_button');
            
            // Fix: Check for null/undefined/empty string, but allow 0 (first player index)
            const playerValue = $form.find('.intersoccer-player-select').val();
            const playerSelected = playerValue !== null && playerValue !== undefined && playerValue !== '';
            const bookingType = $form.find('select[name="attribute_pa_booking-type"]').val();
            
            // Check actual hidden inputs instead of this.state.selectedDays (which is managed by elementor-widgets.php)
            const actualSelectedDays = $form.find('input[name="camp_days[]"]').length;
            const daysSelected = (!bookingType) ? true : (bookingType === 'single-days' || bookingType === 'à la journée' || bookingType === 'a-la-journee' || bookingType.toLowerCase().includes('single') || bookingType.toLowerCase().includes('journée') || bookingType.toLowerCase().includes('journee')) ? actualSelectedDays > 0 : true;
            const isLoggedIn = this.config.userId > 0;

            console.log('InterSoccer Product Enhancer: Button state check - playerValue:', playerValue, 'player:', playerSelected, 'days:', daysSelected, 'actualDays:', actualSelectedDays, 'logged in:', isLoggedIn);

            const canAddToCart = playerSelected && daysSelected && isLoggedIn;

            $button.prop('disabled', !canAddToCart);
            
            // Handle express checkout buttons
            const $expressContainer = $('.wc-stripe-product-checkout-container');
            if (canAddToCart) {
                $expressContainer.show();
            } else {
                $expressContainer.hide();
            }

            // Update notification messages
            this.updateNotifications(playerSelected, daysSelected, isLoggedIn);
        },

        // Update notification messages
        updateNotifications: function(playerSelected, daysSelected, isLoggedIn) {
            // For camps, notifications are handled by elementor-widgets.php
            if (this.config.productType === 'camp') {
                console.log('InterSoccer Product Enhancer: Skipping notifications for camp (handled by elementor-widgets.php)');
                return;
            }
            
            const $form = $(this.state.formSelector);
            const $notification = $form.find('.intersoccer-attendee-notification');

            if (!isLoggedIn) {
                $notification.text('Please log in or register to select an attendee.').show();
            } else if (!playerSelected) {
                $notification.text('Please select an attendee.').show();
            } else {
                $notification.hide();
            }

            const $dayNotification = $form.find('.intersoccer-day-notification');
            if (playerSelected && !daysSelected) {
                $dayNotification.text('Please select at least one day.').show();
            } else {
                $dayNotification.hide();
            }
        },

        // Handle form reset
        handleReset: function() {
            this.state.selectedDays = [];
            this.state.lastVariation = null;
            $(this.state.formSelector).find('.intersoccer-course-info').empty();
        }
    };

    // Initialize when DOM is ready
    jQuery(document).ready(function() {
        if (typeof window.InterSoccerProductEnhancer !== 'undefined') {
            window.InterSoccerProductEnhancer.init();
        }
    });

})(jQuery);