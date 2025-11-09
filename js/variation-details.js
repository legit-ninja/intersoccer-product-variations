/**
 * File: variation-details.js
 * Description: Modernized variation handling with modular services, shared event bus,
 *              and typed data layer for InterSoccer WooCommerce products.
 */

(function (window, $, undefined) {
    'use strict';

    if (!window || !$) {
        return;
    }

    const MODULE_PREFIX = 'InterSoccer';

    const Logger = {
        enabled: true,
        prefix: `${MODULE_PREFIX}:`,
        debug: function () {
            if (!this.enabled || !console || !console.debug) return;
            console.debug(this.prefix, ...arguments);
        },
        info: function () {
            if (!this.enabled || !console || !console.info) return;
            console.info(this.prefix, ...arguments);
        },
        warn: function () {
            if (!console || !console.warn) return;
            console.warn(this.prefix, ...arguments);
        },
        error: function () {
            if (!console || !console.error) return;
            console.error(this.prefix, ...arguments);
        }
    };

    const EventBus = (function () {
        const listeners = {};
        return {
            on(event, handler) {
                if (!listeners[event]) {
                    listeners[event] = [];
                }
                listeners[event].push(handler);
            },
            off(event, handler) {
                if (!listeners[event]) return;
                listeners[event] = listeners[event].filter((fn) => fn !== handler);
            },
            emit(event, payload) {
                if (!listeners[event]) return;
                listeners[event].forEach((handler) => {
                    try {
                        handler(payload);
                    } catch (err) {
                        Logger.error(`EventBus handler error for ${event}`, err);
                    }
                });
            }
        };
    })();

    const CourseMeta = {
        create(data) {
            return {
                start_date: data.start_date || '',
                end_date: data.end_date || '',
                total_weeks: Number(data.total_weeks || data.total_sessions || 0) || 0,
                remaining_sessions: Number(data.remaining_sessions || data.remaining_weeks || 0) || 0,
                weekly_discount: Number(data.weekly_discount || 0) || 0,
                holidays: Array.isArray(data.holidays) ? data.holidays : [],
                is_course: Boolean(data.is_course)
            };
        }
    };

    class ApiClient {
        constructor(config) {
            this.ajaxUrl = config.ajax_url;
            this.nonce = config.nonce;
            this.nonceRefreshUrl = config.nonce_refresh_url;
            this.refreshingNonce = null;
        }

        request(options, allowRetry = true) {
            const payload = Object.assign({ nonce: this.nonce }, options.data || {});
            const ajaxOverrides = options.ajax || {};
            return new Promise((resolve, reject) => {
                $.ajax(Object.assign({
                    url: this.ajaxUrl,
                    type: 'POST',
                    data: payload
                }, ajaxOverrides)).done((response) => {
                    resolve(response);
                }).fail((xhr) => {
                    if (xhr.status === 403 && allowRetry) {
                        this.refreshNonce()
                            .then(() => this.request(options, false).then(resolve).catch(reject))
                            .catch(reject);
                    } else {
                        reject(xhr);
                    }
                });
            });
        }

        refreshNonce() {
            if (!this.nonceRefreshUrl) {
                return Promise.reject(new Error('Nonce refresh URL not configured'));
            }
            if (this.refreshingNonce) {
                return this.refreshingNonce;
            }
            this.refreshingNonce = new Promise((resolve, reject) => {
                $.ajax({
                    url: this.nonceRefreshUrl,
                    type: 'POST'
                }).done((response) => {
                    if (response.success && response.data && response.data.nonce) {
                        this.nonce = response.data.nonce;
                        Logger.info('Nonce refreshed', this.nonce);
                        resolve(this.nonce);
                    } else {
                        reject(new Error('Nonce refresh failed'));
                    }
                }).fail((xhr) => {
                    reject(new Error(`Nonce refresh error: ${xhr.statusText}`));
                }).always(() => {
                    this.refreshingNonce = null;
                });
            });
            return this.refreshingNonce;
        }

        fetchProductType(productId) {
            return this.request({
                data: {
                    action: 'intersoccer_get_product_type',
                    product_id: productId
                }
            }).then((response) => {
                if (response.success && response.data && response.data.product_type) {
                    return response.data.product_type;
                }
                throw new Error('Product type not found');
            });
        }

        fetchCourseMetadata(productId, variationId) {
            return this.request({
                data: {
                    action: 'intersoccer_get_course_metadata',
                    product_id: productId,
                    variation_id: variationId
                }
            }).then((response) => {
                if (response.success && response.data) {
                    return CourseMeta.create(response.data);
                }
                return CourseMeta.create({});
            });
        }

        fetchCourseInfo(productId, variationId) {
            return this.request({
                data: {
                    action: 'intersoccer_get_course_info',
                    product_id: productId,
                    variation_id: variationId
                }
            }).then((response) => {
                if (response.success && response.data) {
                    return CourseMeta.create(response.data);
                }
                return CourseMeta.create({});
            });
        }

        calculateDynamicPrice(productId, variationId, remainingWeeks) {
            return this.request({
                data: {
                    action: 'intersoccer_calculate_dynamic_price',
                    product_id: productId,
                    variation_id: variationId,
                    remaining_weeks: remainingWeeks
                }
            }).then((response) => {
                if (response.success && response.data) {
                    return response.data;
                }
                throw new Error(response.data?.message || 'Failed to calculate price');
            });
        }

        updateSessionData(productId, payload) {
            return this.request({
                data: Object.assign({
                    action: 'intersoccer_update_session_data',
                    product_id: productId
                }, payload)
            });
        }
    }

    class CourseInfoRenderer {
        constructor() {
            this.valueTemplate = (value) => `<span style="color: white; padding: 8px; display: inline-block;">${value}</span>`;
        }

        row(id) {
            return $(`#${id}`);
        }

        updateRow(id, html) {
            const $row = this.row(id);
            if (!$row.length) return;
            if (html) {
                $row.find('td.value').html(html);
                $row.show();
            } else {
                $row.hide();
            }
        }

        hideAll() {
            $('.intersoccer-course-info-row').hide();
        }

        renderFromCourseData(courseData) {
            if (!courseData || !courseData.is_course) {
                this.hideAll();
                return false;
            }

            this.updateRow('intersoccer-course-start-date', courseData.start_date ? this.valueTemplate(courseData.start_date) : null);
            this.updateRow('intersoccer-course-end-date', courseData.end_date ? this.valueTemplate(courseData.end_date) : null);
            this.updateRow('intersoccer-course-total-sessions', courseData.total_weeks ? this.valueTemplate(courseData.total_weeks) : null);

            if (courseData.remaining_sessions && courseData.remaining_sessions !== courseData.total_weeks) {
                this.updateRow('intersoccer-course-remaining-sessions', this.valueTemplate(courseData.remaining_sessions));
            } else {
                this.updateRow('intersoccer-course-remaining-sessions', null);
            }

            if (courseData.holidays && courseData.holidays.length) {
                const holidayHtml = [`<div style="color: white; padding: 8px;"><ul style="margin: 0; padding-left: 20px;">`]
                    .concat(courseData.holidays.map((holiday) => `<li>${holiday}</li>`))
                    .concat(['</ul></div>'])
                    .join('');
                this.updateRow('intersoccer-course-holidays', holidayHtml);
            } else {
                this.updateRow('intersoccer-course-holidays', null);
            }

            return true;
        }
    }

    class FormState {
        constructor($form) {
            this.$form = $form;
            this.productId = $form.data('product_id') || $form.find('input[name="product_id"]').val() || 'unknown';
            this.lastValidPlayerId = '';
        }

        getForm() {
            return this.$form;
        }

        getProductId() {
            return this.productId;
        }

        getSelectedPlayerId() {
            return this.$form.find('.player-select').val() || '';
        }

        setLastValidPlayerId(playerId) {
            this.lastValidPlayerId = playerId || '';
        }

        getLastValidPlayerId() {
            return this.lastValidPlayerId;
        }

        getBookingType() {
            return this.$form.find('select[name="attribute_pa_booking-type"]').val() ||
                this.$form.find('input[name="attribute_pa_booking-type"]').val() || '';
        }

        getVariationId() {
            return this.$form.find('input[name="variation_id"]').val();
        }

        updateHiddenFields({ playerId, remainingWeeks }) {
            this.$form.find('input[name="assigned_attendee"]').remove();
            this.$form.find('input[name="remaining_weeks"]').remove();
            if (playerId) {
                this.$form.append(`<input type="hidden" name="assigned_attendee" value="${playerId}">`);
            }
            if (remainingWeeks !== null && remainingWeeks !== undefined) {
                this.$form.append(`<input type="hidden" name="remaining_weeks" value="${remainingWeeks}">`);
            }
        }

        setAddToCartEnabled(enabled) {
            const $button = this.$form.find('button.single_add_to_cart_button');
            $button.prop('disabled', !enabled);
            if (enabled) {
                $button.removeClass('disabled');
            }
        }

        setQuantity(amount) {
            this.$form.find('input[name="quantity"]').val(amount);
        }

        triggerCheckVariations() {
            this.$form.trigger('check_variations');
        }

        disableCourseInfo() {
            $('.intersoccer-course-info-row').hide();
        }
    }

    class VariationWorkflow {
        constructor(formState, apiClient, courseRenderer) {
            this.formState = formState;
            this.apiClient = apiClient;
            this.courseRenderer = courseRenderer;

            this.productType = 'unknown';
            this.currentVariation = null;
            this.lastVariationId = null;
            this.lastBookingType = null;
            this.lastPriceUpdateTime = 0;
            this.isProcessingVariation = false;
            this.playerChangeTimeout = null;
            this.variationDebounce = null;
            this.variationEventLocked = false;
        }

        init() {
            this.bindCoreEvents();
            this.fetchProductType();
            this.formState.triggerCheckVariations();
        }

        fetchProductType() {
            const productId = this.formState.getProductId();
            if (!productId || productId === 'unknown') {
                Logger.warn('Unable to determine product ID for product type lookup');
                return;
            }

            this.apiClient.fetchProductType(productId)
                .then((type) => {
                    this.productType = type;
                    EventBus.emit('product:type', type);
                    Logger.info('Product type identified', type);
                    this.handlePreSelectedVariations();
                })
                .catch((error) => {
                    Logger.error('Failed to fetch product type', error);
                });
        }

        bindCoreEvents() {
            const $form = this.formState.getForm();

            $form.on('found_variation', (event, variation) => {
                if (this.variationEventLocked) return;
                this.lockVariationEvent();
                clearTimeout(this.variationDebounce);
                this.variationDebounce = setTimeout(() => {
                    this.handleVariation(variation);
                    this.unlockVariationEvent();
                }, 200);
            });

            $form.on('woocommerce_variation_has_changed reset_data', () => {
                if (this.variationEventLocked) return;
                this.lockVariationEvent();
                Logger.debug('Variation data reset');
                this.currentVariation = null;
                this.lastVariationId = null;
                this.lastBookingType = null;
                this.courseRenderer.hideAll();
                setTimeout(() => this.unlockVariationEvent(), 600);
            });

            $form.find('select[name="attribute_pa_booking-type"], input[name="attribute_pa_booking-type"]').on('change', () => {
                Logger.debug('Booking type updated');
                this.formState.triggerCheckVariations();
            });

            this.setupPlayerSelectionHandling();
            this.setupAddToCartHandlers();
        }

        lockVariationEvent() {
            this.variationEventLocked = true;
        }

        unlockVariationEvent() {
            this.variationEventLocked = false;
        }

        handlePreSelectedVariations() {
            const $form = this.formState.getForm();
            const urlParams = new URLSearchParams(window.location.search);
            const $variationSelects = $form.find('select[name^="attribute_"]');
            let hasPreSelectedAttributes = false;

            $variationSelects.each(function () {
                const $select = $(this);
                const paramValue = urlParams.get($select.attr('name'));
                if (paramValue) {
                    hasPreSelectedAttributes = true;
                    $select.val(paramValue);
                }
            });

            if (!hasPreSelectedAttributes) {
                return;
            }

            Logger.info('Pre-selected attributes detected from URL');
            $form.trigger('check_variations');

            if (this.productType !== 'course') {
                return;
            }

            setTimeout(() => {
                const variationId = parseInt($form.find('input[name="variation_id"]').val(), 10);
                if (!variationId) {
                    Logger.debug('No variation ID resolved for pre-selected attributes');
                    return;
                }

                this.courseRenderer.renderFromCourseData(CourseMeta.create({ is_course: false }));
                this.apiClient.fetchCourseInfo(this.formState.getProductId(), variationId)
                    .then((courseData) => {
                        if (!this.courseRenderer.renderFromCourseData(courseData)) {
                            this.courseRenderer.hideAll();
                        }
                    });
            }, 500);
        }

        handleVariation(variation) {
            if (!variation || this.isProcessingVariation) {
                return;
            }

            const variationId = variation.variation_id || 0;
            const bookingType = variation?.attributes?.attribute_pa_booking_type || this.formState.getBookingType();
            const bookingTypeChanged = this.lastBookingType !== bookingType;

            if (!variationId) {
                Logger.error('Variation missing ID', variation);
                return;
            }

            if (variationId === this.lastVariationId && !bookingTypeChanged) {
                Logger.debug('Variation unchanged; skipping handler');
                return;
            }

            this.isProcessingVariation = true;
            this.currentVariation = variation;
            this.lastVariationId = variationId;
            this.lastBookingType = bookingType;

            if (this.productType === 'course') {
                this.processCourseVariation(variationId, variation)
                    .finally(() => {
                        this.isProcessingVariation = false;
                    });
            } else if (this.productType === 'camp' && (bookingType || '').toLowerCase() === 'full-week') {
                this.processFullWeekCampVariation(variationId)
                    .finally(() => {
                        this.isProcessingVariation = false;
                    });
            } else {
                this.courseRenderer.hideAll();
                this.processGenericVariation(variationId)
                    .finally(() => {
                        this.isProcessingVariation = false;
                    });
            }
        }

        processCourseVariation(variationId, variation) {
            const productId = this.formState.getProductId();
            return this.apiClient.fetchCourseMetadata(productId, variationId)
                .then((courseMeta) => {
                    const playerId = this.formState.getSelectedPlayerId();
                    const remainingWeeks = courseMeta.remaining_sessions || null;
                    this.formState.updateHiddenFields({ playerId, remainingWeeks });
                    this.formState.setAddToCartEnabled(Boolean(playerId));
                    if (playerId) {
                        this.formState.setLastValidPlayerId(playerId);
                    }

                    if (!this.courseRenderer.renderFromCourseData(variation?.course_info ? CourseMeta.create(Object.assign({ is_course: true }, variation.course_info)) : null)) {
                        this.apiClient.fetchCourseInfo(productId, variationId).then((data) => {
                            if (!this.courseRenderer.renderFromCourseData(data)) {
                                this.courseRenderer.hideAll();
                            }
                        });
                    }

                    this.updatePrice(productId, variationId, remainingWeeks);
                })
                .catch((error) => {
                    Logger.error('Course metadata retrieval failed', error);
                    const playerId = this.formState.getSelectedPlayerId();
                    this.formState.updateHiddenFields({ playerId, remainingWeeks: null });
                    this.formState.setAddToCartEnabled(Boolean(playerId));
                });
        }

        processFullWeekCampVariation(variationId) {
            const productId = this.formState.getProductId();
            const playerId = this.formState.getSelectedPlayerId();
            this.formState.updateHiddenFields({ playerId, remainingWeeks: null });
            this.formState.setQuantity(1);
            this.formState.setAddToCartEnabled(Boolean(playerId));
            if (playerId) {
                this.formState.setLastValidPlayerId(playerId);
            }
            return this.updatePrice(productId, variationId, null);
        }

        processGenericVariation(variationId) {
            const productId = this.formState.getProductId();
            const playerId = this.formState.getSelectedPlayerId();
            this.formState.updateHiddenFields({ playerId, remainingWeeks: null });
            this.formState.setQuantity(1);
            this.formState.setAddToCartEnabled(Boolean(playerId));
            if (playerId) {
                this.formState.setLastValidPlayerId(playerId);
            }
            return this.updatePrice(productId, variationId, null);
        }

        updatePrice(productId, variationId, remainingWeeks) {
            const now = Date.now();
            if (now - this.lastPriceUpdateTime < 1000) {
                Logger.debug('Price update throttled');
                return Promise.resolve();
            }

            this.lastPriceUpdateTime = now;
            return this.apiClient.calculateDynamicPrice(productId, variationId, remainingWeeks)
                .then((priceData) => {
                    this.renderPrice(priceData.price);
                    EventBus.emit('price:update', priceData);
                })
                .catch((error) => {
                    Logger.error('Failed to update price', error);
                });
        }

        renderPrice(priceHtml) {
            const $container = $('.woocommerce-variation-price');
            if ($container.length) {
                $container.html(priceHtml);
                return;
            }

            const $priceElement = $('.single_variation .price, .woocommerce-variation-price .price').first();
            if ($priceElement.length) {
                $priceElement.html(priceHtml);
            } else {
                Logger.warn('No price container available to update');
            }
        }

        setupPlayerSelectionHandling() {
            const $form = this.formState.getForm();
            $form.find('.player-select').on('change', (event) => {
                const playerId = $(event.currentTarget).val() || '';
                const bookingType = this.formState.getBookingType();
                Logger.info('Player selection changed', playerId);

                const remainingWeeks = (this.productType === 'course') ? null : this.formState.getForm().find('input[name="remaining_weeks"]').val() || null;
                this.formState.updateHiddenFields({ playerId, remainingWeeks });

                clearTimeout(this.playerChangeTimeout);
                this.playerChangeTimeout = setTimeout(() => {
                    if (!this.currentVariation) {
                        this.formState.triggerCheckVariations();
                    }

                    this.formState.setAddToCartEnabled(Boolean(playerId));
                    if (playerId) {
                        this.formState.setLastValidPlayerId(playerId);
                    }

                    if (this.currentVariation) {
                        this.updatePrice(this.formState.getProductId(), this.currentVariation.variation_id, remainingWeeks)
                            .then(() => Logger.debug('Price updated due to player selection'));
                    }

                    this.apiClient.updateSessionData(this.formState.getProductId(), {
                        assigned_attendee: playerId,
                        camp_days: [],
                        remaining_weeks: remainingWeeks
                    });
                }, 600);
            });
        }

        setupAddToCartHandlers() {
            const $form = this.formState.getForm();

            $(document).on('click', 'button.single_add_to_cart_button, .elementor-add-to-cart button', (event) => {
                const $button = $(event.currentTarget);
                if ($button.hasClass('buy-now')) {
                    const playerId = this.formState.getSelectedPlayerId() || this.formState.getLastValidPlayerId();
                    const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
                    this.formState.updateHiddenFields({ playerId, remainingWeeks });
                    $form.append('<input type="hidden" name="buy_now" value="1">');
                }
            });

            $form.on('submit', () => {
                const playerId = this.formState.getSelectedPlayerId() || this.formState.getLastValidPlayerId();
                const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
                this.formState.updateHiddenFields({ playerId, remainingWeeks });
            });

            $form.on('adding_to_cart', (event, $button, data) => {
                const playerId = this.formState.getSelectedPlayerId() || this.formState.getLastValidPlayerId();
                const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
                if (playerId) data.assigned_attendee = playerId;
                if (remainingWeeks !== undefined && remainingWeeks !== null) data.remaining_weeks = remainingWeeks;
                data.quantity = 1;
            });
        }
    }

    class FormObserver {
        constructor() {
            this.observer = null;
            this.retryTimer = null;
            this.retryCount = 0;
            this.maxRetries = 10;
            this.retryInterval = 1000;
        }

        watch(callback) {
            const attemptLocate = () => {
                const $form = $('form.cart');
                if ($form.length) {
                    Logger.info('Product form detected');
                    this.disconnect();
                    callback($form);
                    return true;
                }
                return false;
            };

            if (attemptLocate()) {
                return;
            }

            this.observer = new MutationObserver(() => {
                if (attemptLocate()) {
                    this.disconnect();
                }
            });
            this.observer.observe(document.body, { childList: true, subtree: true });

            this.retryTimer = window.setInterval(() => {
                this.retryCount += 1;
                if (attemptLocate()) {
                    this.disconnect();
                } else if (this.retryCount >= this.maxRetries) {
                    Logger.error('Product form not found after retries');
                    this.disconnect();
                }
            }, this.retryInterval);
        }

        disconnect() {
            if (this.observer) {
                this.observer.disconnect();
                this.observer = null;
            }
            if (this.retryTimer) {
                clearInterval(this.retryTimer);
                this.retryTimer = null;
            }
        }
    }

    class VariationDetailsApp {
        constructor(config) {
            this.config = config;
            this.apiClient = new ApiClient(config);
            this.formObserver = new FormObserver();
        }

        boot() {
            if (!this.config || !this.config.ajax_url) {
                Logger.error('intersoccerCheckout configuration missing');
                return;
            }

            this.formObserver.watch(($form) => {
                const formState = new FormState($form);
                const courseRenderer = new CourseInfoRenderer();
                const workflow = new VariationWorkflow(formState, this.apiClient, courseRenderer);
                workflow.init();
            });
        }
    }

    $(document).ready(function () {
        Logger.info('Document ready, initializing variation details');
        const config = window.intersoccerCheckout || null;
        if (!config) {
            Logger.error('intersoccerCheckout not initialized');
            return;
        }
        const app = new VariationDetailsApp(config);
        app.boot();
    });

})(window, jQuery);