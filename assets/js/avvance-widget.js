/**
 * Avvance Widget JavaScript - COMPLETE WITH CRITICAL FEATURES
 *
 * FEATURES:
 * 1. Variable product variation change detection
 * 2. Always-visible checkout banner above payment methods
 * 3. Enhanced cart updates
 * 4. Category page widget support
 * 5. Unified [data-modal] click handler
 * 6. Two-AJAX loadPriceBreakdown (price breakdown + pre-approval in sequence)
 */

(function($) {
    'use strict';

    var isCartPage = avvanceWidget.isCartPage || false;
    var isProductPage = avvanceWidget.isProductPage || false;
    var isCheckoutPage = avvanceWidget.isCheckoutPage || false;

    // Variables for pre-approval flow
    var preapprovalWindow = null;
    var statusCheckInterval = null;

    /**
     * Check if status indicates pre-approval was successful.
     *
     * Only 2 valid lead statuses from Avvance:
     * - PRE_APPROVED: Customer is pre-approved (eligible)
     * - NOT_APPROVED: Customer is declined (not eligible)
     */
    function isPreApprovedStatus(status) {
        if (!status) return false;
        return status === 'PRE_APPROVED';
    }

    /**
     * Parse API response into normalized offers array.
     * Handles both old format (flat array with monthlyPaymentAmount)
     * and new format ({ offers: [...] } with paymentAmount + offerType).
     */
    function parseOffers(data) {
        var offers = [];

        if (data && data.offers && Array.isArray(data.offers)) {
            offers = data.offers;
        } else if (Array.isArray(data)) {
            for (var i = 0; i < data.length; i++) {
                var item = data[i];
                offers.push({
                    apr: item.apr || 0,
                    paymentAmount: item.monthlyPaymentAmount || item.paymentAmount || 0,
                    termCount: item.termCount || null,
                    totalLoanWithInterest: item.totalLoanWithInterest || null,
                    offerType: item.offerType || (item.apr === 0 ? 'ZERO' : 'APR'),
                    promotionApr: item.promotionApr || null,
                    promotionTermInMonths: item.promotionTermInMonths || null,
                    promotionPaymentAmount: item.promotionPaymentAmount || null
                });
            }
        }

        return offers;
    }

    /**
     * Get the best offer for widget inline display.
     * Priority: ZERO > PROMO > APR
     */
    function getBestOffer(offers) {
        var zero = null, promo = null, apr = null;

        for (var i = 0; i < offers.length; i++) {
            var offer = offers[i];
            if (offer.offerType === 'ZERO' && !zero) zero = offer;
            else if (offer.offerType === 'PROMO' && !promo) promo = offer;
            else if (offer.offerType === 'APR' && !apr) apr = offer;
        }

        return zero || promo || apr || null;
    }

    /**
     * Build inline widget HTML for a given state.
     *
     * States:
     * 1. Not pre-approved, no 0% APR: "As low as $XX/month with [logo] Check your spending power"
     * 2. Not pre-approved, 0% APR: "0% APR or as low as $XX/month with [logo] Check your spending power"
     * 3. Pre-approved: "You're pre-approved! As low as $XX/month with [logo] See your details"
     */
    function buildWidgetHtml(offers, hasPreapproval, maxAmount, sessionId, context) {
        var bestOffer = getBestOffer(offers);
        var hasZeroApr = false;
        var displayPayment = null;

        if (bestOffer && bestOffer.paymentAmount) {
            if (bestOffer.offerType === 'ZERO') {
                hasZeroApr = true;
                displayPayment = bestOffer.paymentAmount;
            } else if (bestOffer.offerType === 'PROMO') {
                hasZeroApr = true;
                displayPayment = bestOffer.promotionPaymentAmount || bestOffer.paymentAmount;
            } else {
                displayPayment = bestOffer.paymentAmount;
            }
        }

        var formattedPayment = displayPayment ? '$' + parseFloat(displayPayment).toFixed(2) : null;
        var showLogo = avvanceWidget.showLogo !== false;
        var logoHtml = showLogo
            ? '<img src="' + avvanceWidget.logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline" style="display:inline-block;vertical-align:middle;">'
            : '<span class="avvance-brand">U.S. Bank Avvance</span>';

        var rateHtml, ctaHtml;

        if (hasPreapproval && maxAmount) {
            rateHtml = '<span class="avvance-preapproved-badge">You\'re pre-approved!</span> ' +
                (formattedPayment ? 'As low as <strong>' + formattedPayment + '/month</strong> with ' : 'Pay over time with ');
            ctaHtml = '<a href="#" class="avvance-cta-link" data-modal="preapproved-details">See your details</a>';
        } else {
            rateHtml = (hasZeroApr ? '<strong class="avvance-zero-apr">0% APR</strong> or as low as ' : 'As low as ') +
                (formattedPayment ? '<strong>' + formattedPayment + '/month</strong> with ' : 'with ');
            if (context === 'checkout') {
                ctaHtml = '<a href="#" class="avvance-learn-more-link" data-modal="modal-a" data-session-id="' + sessionId + '">Learn more</a>';
            } else {
                ctaHtml = '<a href="#" class="avvance-check-spending-link" data-modal="modal-b" data-session-id="' + sessionId + '">Check your spending power</a>';
            }
        }

        //return '<div class="avvance-price-message">' + rateHtml + '<span style="white-space:nowrap;">' + logoHtml + ctaHtml + '</span>' + '</div>';
        return rateHtml + '<span style="white-space:nowrap;">' + logoHtml + ctaHtml + '</span>';
    }

    /**
     * Render loan option cards into a container
     */
    function renderLoanCards(offers, $container, originalAmount) {
        if (!offers || offers.length === 0) {
            $container.html('<p style="color: #484861; text-align: center; font-size: 14px;">No loan options available for this amount.</p>');
            return;
        }

        var html = '';
        for (var i = 0; i < offers.length; i++) {
            var offer = offers[i];
            if (offer.offerType === 'PROMO') continue;

            var monthlyHtml = '$' + parseFloat(offer.paymentAmount).toFixed(2) +
                '<span class="avvance-price-suffix">/month</span>';

            var termCount = offer.termCount || '—';
            var aprVal = offer.apr !== null && offer.apr !== undefined
                ? parseFloat(offer.apr).toFixed(2) + '%'
                : '0%';
            var aprBadge = aprVal + ' APR for ' + termCount + ' months';

            var detailsHtml = '';
            if (offer.offerType === 'ZERO') {
                if (offer.totalLoanWithInterest) {
                    detailsHtml = 'Total $' + parseFloat(offer.totalLoanWithInterest)
                        .toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
            } else {
                var interest = (offer.totalLoanWithInterest && originalAmount)
                    ? parseFloat(offer.totalLoanWithInterest) - parseFloat(originalAmount)
                    : null;
                if (interest !== null && interest > 0) {
                    detailsHtml += '<span>Interest $' + interest.toLocaleString('en-US',
                        {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span>';
                }
                if (offer.totalLoanWithInterest) {
                    detailsHtml += '<span>Total $' + parseFloat(offer.totalLoanWithInterest)
                        .toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span>';
                }
            }

            html += '<div class="avvance-loan-card">';
            html += '  <div class="avvance-loan-card-header">';
            html += '    <span class="avvance-loan-monthly">' + monthlyHtml + '</span>';
            html += '    <span class="avvance-loan-apr-badge">' + aprBadge + '</span>';
            html += '  </div>';
            if (detailsHtml) {
                html += '  <div class="avvance-loan-details">' + detailsHtml + '</div>';
            }
            html += '</div>';
        }

        if (!html) {
            $container.html('<p style="color: #484861; text-align: center; font-size: 14px;">No loan options available for this amount.</p>');
            return;
        }

        $container.html(html);
    }

    /**
     * Load price breakdown for a modal and render loan cards
     */
    function loadModalPriceBreakdown(amount, $container) {
        if (!amount || amount < avvanceWidget.minAmount || amount > avvanceWidget.maxAmount) {
            $container.html('<p style="color: #666; text-align: center;">Amount must be between $' + avvanceWidget.minAmount + ' and $' + avvanceWidget.maxAmount + '.</p>');
            return;
        }

        $container.empty();

        $.ajax({
            url: avvanceWidget.ajaxUrl,
            type: 'POST',
            data: {
                action: 'avvance_get_price_breakdown',
                amount: amount,
                nonce: avvanceWidget.nonce
            },
            success: function(response) {
                if (response.success) {
                    var offers = parseOffers(response.data);
                    renderLoanCards(offers, $container, amount);
                } else {
                    $container.html('<p style="color: #666; text-align: center;">Unable to load loan options.</p>');
                }
            },
            error: function() {
                $container.html('<p style="color: #666; text-align: center;">Unable to load loan options.</p>');
            }
        });
    }

    /**
     * Parse currency string to number (removes $, commas)
     */
    function parseCurrencyInput(val) {
        return parseFloat(val.replace(/[^0-9.]/g, '')) || 0;
    }

    /**
     * Format number as currency string
     */
    function formatCurrency(amount) {
        return '$' + parseFloat(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /**
     * Slider navigation
     */
    function moveSlide(sliderId, direction) {
        var $slider = $('#' + sliderId);
        var $slides = $slider.find('.avvance-slide');
        var $dotsContainer = $('#' + sliderId.replace('slider', 'dots'));
        var $dots = $dotsContainer.find('.avvance-dot');

        var activeIndex = 0;
        $slides.each(function(i) {
            if ($(this).hasClass('active')) {
                activeIndex = i;
            }
        });

        var newIndex = activeIndex + direction;
        if (newIndex >= $slides.length) newIndex = 0;
        if (newIndex < 0) newIndex = $slides.length - 1;

        $slides.removeClass('active');
        $dots.removeClass('active');
        $slides.eq(newIndex).addClass('active');
        $dots.eq(newIndex).addClass('active');
    }

    function setSlide(sliderId, index) {
        var $slider = $('#' + sliderId);
        var $slides = $slider.find('.avvance-slide');
        var $dotsContainer = $('#' + sliderId.replace('slider', 'dots'));
        var $dots = $dotsContainer.find('.avvance-dot');

        $slides.removeClass('active');
        $dots.removeClass('active');
        $slides.eq(index).addClass('active');
        $dots.eq(index).addClass('active');
    }

    /**
     * Open modal by type ('preapproval' or 'preapproved-details')
     */
    function openModalByType(type, amount) {
        if (type === 'preapproved-details') {
            var $detailsModal = $('#avvance-preapproved-details-modal');
            if ($detailsModal.length) {
                var maxAmount = parseFloat($detailsModal.attr('data-max-amount')) || 0;
                if (maxAmount > 0) {
                    loadModalPriceBreakdown(maxAmount, $('#avvance-preapproved-modal-loan-cards'));
                }
                $detailsModal.fadeIn(200);
                $('body').css('overflow', 'hidden');
            }
        } else {
            var $modal = $('#avvance-preapproval-modal');
            if ($modal.length) {
                if (amount > 0) {
                    $('#avvance-modal-amount').val(formatCurrency(amount));
                    loadModalPriceBreakdown(amount, $('#avvance-modal-loan-cards'));
                }
                $modal.fadeIn(200);
                $('body').css('overflow', 'hidden');
            }
        }
    }

    /**
     * Close modal (any avvance modal)
     */
    function closeModal() {
        $('.avvance-modal').fadeOut(200);
        $('body').css('overflow', '');
    }

    /**
     * Start polling for pre-approval status updates
     */
    function startStatusPolling() {
        if (statusCheckInterval) {
            clearInterval(statusCheckInterval);
        }

        var pollCount = 0;
        var maxPolls = 200;

        statusCheckInterval = setInterval(function() {
            pollCount++;

            if (preapprovalWindow && preapprovalWindow.closed) {
                clearInterval(statusCheckInterval);
                statusCheckInterval = null;
            }

            checkPreapprovalStatusWithCallback(function(data) {
                if (data && isPreApprovedStatus(data.status) && data.max_amount) {
                    updateCTAToPreapproved(data.max_amount);

                    clearInterval(statusCheckInterval);
                    statusCheckInterval = null;

                    if (preapprovalWindow && !preapprovalWindow.closed) {
                        preapprovalWindow.close();
                    }
                }
            });

            if (pollCount >= maxPolls) {
                clearInterval(statusCheckInterval);
                statusCheckInterval = null;
            }
        }, avvanceWidget.checkInterval || 3000);
    }

    /**
     * Check pre-approval status via AJAX (with callback for polling)
     */
    function checkPreapprovalStatusWithCallback(callback) {
        $.ajax({
            url: avvanceWidget.ajaxUrl,
            type: 'POST',
            data: {
                action: 'avvance_check_preapproval_status',
                nonce: avvanceWidget.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    if (typeof callback === 'function') {
                        callback(response.data);
                    }
                }
            },
            error: function() {
            }
        });
    }

    /**
     * Update all widgets to show pre-approved state.
     * State 3: "You're pre-approved! As low as $XXX.XX/month with <logo> See your details"
     */
    function updateCTAToPreapproved(maxAmount) {
        // Update inline widgets
        $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget, .avvance-category-widget').each(function() {
            var $widget = $(this);
            var offers = $widget.data('offers') || [];
            var sessionId = $widget.data('session-id') || '';
            $widget.find('.avvance-widget-content').html(
                buildWidgetHtml(offers, true, maxAmount, sessionId)
            );
        });

        // Update checkout banner if present
        var $checkoutBanner = $('#avvance-checkout-banner');
        if ($checkoutBanner.length) {
            var formattedMax = parseFloat(maxAmount).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
            var showLogo = avvanceWidget.showLogo !== false;
            var logoHtml = showLogo
                ? '<img src="' + avvanceWidget.logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline" style="display:inline-block;vertical-align:middle;">'
                : '<span class="avvance-brand">U.S. Bank Avvance</span>';
            $checkoutBanner.html(
                '<div class="avvance-checkout-preapproved">' +
                '<div class="avvance-checkout-banner-check">&#10003;</div>' +
                '<div class="avvance-checkout-banner-text">' +
                '<strong>You\'re pre-approved for $' + formattedMax + '!</strong> ' +
                'Pay over time with <span style="white-space:nowrap;">' + logoHtml +
                '<a href="#" class="avvance-cta-link" data-modal="preapproved-details"> See your details</a></span>' +
                '</div>' +
                '</div>'
            );
        }

        // Update preapproved details modal data
        var $detailsModal = $('#avvance-preapproved-details-modal');
        if ($detailsModal.length && maxAmount) {
            var formattedMax2 = parseFloat(maxAmount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            $detailsModal.data('max-amount', maxAmount);
            $detailsModal.attr('data-max-amount', maxAmount);
            $detailsModal.find('.avvance-success-title').html(
                '<span class="avvance-success-check">&#10003;</span> Your spending power is $' + formattedMax2 + '!'
            );
            var minAmount = avvanceWidget.minAmount || 300;
            $detailsModal.find('.avvance-success-text').html(
                'You\'ve been pre-approved for U.S. Bank Avvance for $' + formattedMax2 + '. ' +
                'To use your spending power, your purchase must be between $' + minAmount + ' and $' + formattedMax2 + '.'
            );
            $('#avvance-preapproved-modal-amount').val('$' + formattedMax2);
        }
    }

    /**
     * Load price breakdown via AJAX, then check pre-approval, then build widget HTML.
     *
     * Widget states:
     * 1. No 0% APR: "As low as $XXX.XX/month with <logo> Check your spending power"
     * 2. 0% APR available: "0% APR or as low as $XXX.XX/month with <logo> Check your spending power"
     * 3. Pre-approved (second AJAX): "You're pre-approved! As low as $XXX.XX/month with <logo> See your details"
     */
    function loadPriceBreakdown($widget) {
        var amount = parseFloat($widget.data('amount'));
        var sessionId = $widget.data('session-id') || '';

        if (!amount || amount < avvanceWidget.minAmount || amount > avvanceWidget.maxAmount) {
            return;
        }

        $.ajax({
            url: avvanceWidget.ajaxUrl,
            type: 'POST',
            data: {
                action: 'avvance_get_price_breakdown',
                amount: amount,
                nonce: avvanceWidget.nonce
            },
            success: function(response) {
                var offers = [];
                if (response.success) {
                    offers = parseOffers(response.data);
                }

                if (offers.length > 0) {
                    $widget.data('offers', offers);
                    var bestOffer = getBestOffer(offers);
                    if (bestOffer && bestOffer.paymentAmount) {
                        $widget.data('monthly-payment', bestOffer.paymentAmount);
                    }
                    var hasZeroApr = false;
                    for (var i = 0; i < offers.length; i++) {
                        if (offers[i].offerType === 'ZERO' || offers[i].offerType === 'PROMO') {
                            hasZeroApr = true;
                            break;
                        }
                    }
                    $widget.attr('data-has-zero-apr', hasZeroApr ? '1' : '0');
                }

                $.ajax({
                    url: avvanceWidget.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'avvance_check_preapproval',
                        nonce: avvanceWidget.nonce
                    },
                    success: function(paResponse) {
                        var hasPreapproval = false;
                        var maxAmount = 0;
                        if (paResponse.success && paResponse.data && paResponse.data.has_preapproval) {
                            hasPreapproval = true;
                            maxAmount = paResponse.data.max_amount;
                        }
                        var context = $widget.data('context') || 'product';
                        $widget.find('.avvance-widget-content').html(
                            buildWidgetHtml(offers, hasPreapproval, maxAmount, sessionId, context)
                        );
                    },
                    error: function() {
                        var context = $widget.data('context') || 'product';
                        $widget.find('.avvance-widget-content').html(
                            buildWidgetHtml(offers, false, 0, sessionId, context)
                        );
                    }
                });
            },
            error: function() {
                var showLogo = avvanceWidget.showLogo !== false;
                $widget.find('.avvance-widget-content').html(
                    '<div class="avvance-price-message">Pay over time with ' +
                    (showLogo
                        ? '<img src="' + avvanceWidget.logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline">'
                        : '<span class="avvance-brand">U.S. Bank Avvance</span>') +
                    '</div>'
                );
            }
        });
    }

    /**
     * Update widget with new amount
     */
    function updateWidget($widget, newAmount) {
        var minAmount = avvanceWidget.minAmount;
        var maxAmount = avvanceWidget.maxAmount;

        if (newAmount < minAmount || newAmount > maxAmount) {
            $widget.fadeOut(300);
            return;
        }

        if (!$widget.is(':visible')) {
            $widget.fadeIn(300);
        }

        $widget.attr('data-amount', newAmount);
        loadPriceBreakdown($widget);
    }

    /**
     * Initialize widgets on page
     */
    function initWidgets() {
        var $widgets = $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget, .avvance-category-widget');

        if ($widgets.length === 0 && isCartPage) {
            setTimeout(injectWidgetForBlocks, 2000);
            return;
        }

        $widgets.each(function() {
            loadPriceBreakdown($(this));
        });
    }

    /**
     * Handle variable product variation changes
     */
    function initVariableProductSupport() {
        var $widget = $('.avvance-product-widget');
        if ($widget.length && !$widget.data('original-price')) {
            var originalPrice = $widget.data('amount');
            $widget.data('original-price', originalPrice);
        }

        $(document.body).on('found_variation', '.variations_form', function(event, variation) {
            var $productWidget = $('.avvance-product-widget');

            if ($productWidget.length) {
                var newPrice = variation.display_price;
                updateWidget($productWidget, newPrice);
            }
        });

        $(document.body).on('reset_data', '.variations_form', function() {
            var $productWidget = $('.avvance-product-widget');

            if ($productWidget.length) {
                var originalPrice = $productWidget.data('original-price') || $productWidget.data('amount');

                if (originalPrice > 0) {
                    updateWidget($productWidget, originalPrice);
                } else {
                    $productWidget.fadeOut(300);
                }
            }
        });

        $('.qty').on('change', function() {
            var $productWidget = $('.avvance-product-widget');

            if ($productWidget.length) {
                var qty = parseInt($(this).val()) || 1;
                var basePrice = $productWidget.data('amount');

                var $variationForm = $(this).closest('.variations_form');
                if ($variationForm.length) {
                    var variationId = $variationForm.find('input[name="variation_id"]').val();
                    if (variationId) {
                        return;
                    }
                }

                var newAmount = basePrice * qty;
                updateWidget($productWidget, newAmount);
            }
        });
    }

    /**
     * Handle cart updates
     */
    function initCartSupport() {
        $(document.body).on('updated_cart_totals', function() {
            setTimeout(function() {
                var $cartWidget = $('.avvance-cart-widget');

                if ($cartWidget.length) {
                    var newTotal = getCartTotalFromPage();

                    if (newTotal) {
                        updateWidget($cartWidget, newTotal);
                    }
                } else {
                    initWidgets();
                }
            }, 500);
        });

        $(document.body).on('updated_shipping_method', function() {
            $(document.body).trigger('updated_cart_totals');
        });
    }

    /**
     * Get cart total from page DOM
     */
    function getCartTotalFromPage() {
        var selectors = [
            '.order-total .woocommerce-Price-amount bdi',
            '.order-total .woocommerce-Price-amount',
            '.cart_totals .order-total .amount',
            '.wc-block-components-totals-footer-item .wc-block-formatted-money-amount'
        ];

        for (var i = 0; i < selectors.length; i++) {
            var $element = $(selectors[i]).first();

            if ($element.length) {
                var text = $element.text().trim();
                var amount = parseFloat(text.replace(/[^0-9.]/g, ''));

                if (!isNaN(amount) && amount > 0) {
                    return amount;
                }
            }
        }

        return null;
    }

    /**
     * Handle checkout page — reload widget after AJAX checkout updates.
     */
    function initCheckoutSupport() {
        $(document.body).on('updated_checkout', function() {
            var $checkoutWidget = $('.avvance-checkout-widget[data-context="checkout"]');
            if ($checkoutWidget.length) {
                loadPriceBreakdown($checkoutWidget);
            }
        });
    }

    /**
     * Inject widget for WooCommerce Blocks cart
     */
    function injectWidgetForBlocks() {
        var cartTotal = getCartTotalFromPage();

        if (!cartTotal) {
            return;
        }

        var minAmount = avvanceWidget.minAmount;
        var maxAmount = avvanceWidget.maxAmount;

        if (cartTotal < minAmount || cartTotal > maxAmount) {
            return;
        }

        var sessionId = 'avv_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        var widgetHtml = '<div class="avvance-cart-widget avvance-cart-widget-injected" data-amount="' + cartTotal + '" data-session-id="' + sessionId + '" data-context="cart" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px;">' +
            '<div class="avvance-widget-content">' +
            '<div class="avvance-price-message"></div>' +
            '</div>' +
            '</div>';

        var injectionSelectors = [
            '.wp-block-woocommerce-cart-order-summary-totals-block',
            '.cart_totals',
            '.woocommerce-cart-form'
        ];

        var injected = false;
        for (var i = 0; i < injectionSelectors.length; i++) {
            var $injectPoint = $(injectionSelectors[i]).last();

            if ($injectPoint.length) {
                $injectPoint.after(widgetHtml);
                injected = true;
                break;
            }
        }

        if (!injected) {
            return;
        }

        var $injectedWidget = $('.avvance-cart-widget-injected');
        if ($injectedWidget.length) {
            loadPriceBreakdown($injectedWidget);
        }
    }

    /**
     * Inject checkout banner for WooCommerce Blocks checkout
     */
    function injectCheckoutBanner() {
        if (!isCheckoutPage) return;
        if ($('#avvance-checkout-banner').length) return;

        var total = getCartTotalFromPage();
        if (!total) {
            setTimeout(injectCheckoutBanner, 1500);
            return;
        }

        var min = avvanceWidget.minAmount || 300;
        var max = avvanceWidget.maxAmount || 25000;
        if (total < min || total > max) return;

        var sessionId = 'avv_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        var showLogo = avvanceWidget.showLogo !== false;
        var logoUrl = avvanceWidget.logoUrl;

        var logoHtml = showLogo
            ? '<img src="' + logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline" style="display:inline-block;vertical-align:middle;">'
            : '<strong>U.S. Bank Avvance</strong>';

        var bannerHtml = '<div id="avvance-checkout-banner" class="avvance-checkout-banner"' +
            ' data-amount="' + total + '"' +
            ' data-session-id="' + sessionId + '"' +
            ' data-context="checkout"' +
            ' data-min-amount="' + min + '"' +
            ' data-max-amount="' + max + '">' +
            '<div class="avvance-widget-content"><div class="avvance-price-message"></div></div>' +
            '</div>';

        var injectionSelectors = [
            '.wc-block-checkout__payment-method',
            '.wp-block-woocommerce-checkout-payment-block',
            '.wc-block-components-checkout-step--payment-method',
            '.wc-block-checkout__payment',
            '.wc-block-checkout__order-note'
        ];

        var injected = false;
        for (var i = 0; i < injectionSelectors.length; i++) {
            var $target = $(injectionSelectors[i]).first();
            if ($target.length) {
                $target.before(bannerHtml);
                injected = true;
                break;
            }
        }

        if (!injected) {
            var $placeOrder = $('.wc-block-components-checkout-place-order-button, .wc-block-checkout__actions').first();
            if ($placeOrder.length) {
                $placeOrder.before(bannerHtml);
                injected = true;
            }
        }

        if (!injected) {
            setTimeout(injectCheckoutBanner, 2000);
            return;
        }

        var $banner = $('#avvance-checkout-banner');
        if ($banner.length) {
            loadPriceBreakdown($banner);
        }
    }

    /**
     * Initialize on DOM ready
     */
    $(document).ready(function() {
        initWidgets();

        if (isProductPage) {
            initVariableProductSupport();
        }

        if (isCartPage) {
            initCartSupport();
        }

        if (isCheckoutPage) {
            setTimeout(injectCheckoutBanner, 1000);
            initCheckoutSupport();
        }

        // Unified [data-modal] click handler — routes to the correct modal.
        $(document).on('click', '[data-modal]', function(e) {
            e.preventDefault();
            var type = $(this).data('modal');
            var $closest = $(this).closest(
                '.avvance-product-widget, .avvance-cart-widget, .avvance-category-widget, .avvance-checkout-widget, #avvance-checkout-banner'
            );
            var amount = $closest.length ? parseFloat($closest.data('amount')) : 0;
            if (!amount) {
                var $widget = $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget, .avvance-category-widget').first();
                amount = $widget.length ? parseFloat($widget.data('amount')) : 0;
            }
            openModalByType(type, amount);
        });

        // Handle modal close (all avvance modals)
        $(document).on('click', '.avvance-modal-close, .avvance-modal-overlay', function() {
            closeModal();
        });

        // Prevent modal dialog clicks from closing modal
        $(document).on('click', '.avvance-modal-dialog', function(e) {
            e.stopPropagation();
        });

        // Handle "Calculate monthly payments" button in pre-approval modal
        $(document).on('click', '#avvance-calc-btn', function(e) {
            e.preventDefault();
            var amount = parseCurrencyInput($('#avvance-modal-amount').val());
            loadModalPriceBreakdown(amount, $('#avvance-modal-loan-cards'));
        });

        // Handle "Calculate monthly payments" button in preapproved modal
        $(document).on('click', '#avvance-preapproved-calc-btn', function(e) {
            e.preventDefault();
            var amount = parseCurrencyInput($('#avvance-preapproved-modal-amount').val());
            loadModalPriceBreakdown(amount, $('#avvance-preapproved-modal-loan-cards'));
        });

        // Handle "Continue shopping" button
        $(document).on('click', '.avvance-continue-shopping-btn', function(e) {
            e.preventDefault();
            closeModal();
        });

        // Slider arrow navigation
        $(document).on('click', '.avvance-arrow-nav', function() {
            var sliderId = $(this).data('slider');
            var dir = parseInt($(this).data('dir'));
            moveSlide(sliderId, dir);
        });

        // Slider dot navigation
        $(document).on('click', '.avvance-dot', function() {
            var sliderId = $(this).data('slider');
            var index = parseInt($(this).data('index'));
            setSlide(sliderId, index);
        });

        // Handle "See if you qualify" button
        $(document).on('click', '.avvance-qualify-button', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $widget = $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget, .avvance-category-widget').first();
            var sessionId = $widget.data('session-id');

            if (!sessionId) {
                alert('Unable to start pre-approval. Please refresh the page and try again.');
                return;
            }

            $button.addClass('loading').prop('disabled', true);

            $.ajax({
                url: avvanceWidget.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'avvance_create_preapproval',
                    nonce: avvanceWidget.nonce,
                    session_id: sessionId
                },
                success: function(response) {
                    $button.removeClass('loading').prop('disabled', false);

                    if (response.success && response.data && response.data.url) {
                        closeModal();

                        preapprovalWindow = window.open(
                            response.data.url,
                            'avvance_preapproval',
                            'toolbar=no,location=no,directories=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=600,height=700'
                        );

                        if (preapprovalWindow) {
                            preapprovalWindow.focus();
                            startStatusPolling();
                        } else {
                            alert('Please allow pop-ups to open your pre-approval application.');
                            window.open(response.data.url, '_blank');
                        }
                    } else {
                        var errorMsg = (response.data && response.data.message) ? response.data.message : 'Unable to create pre-approval request. Please try again.';
                        alert(errorMsg);
                    }
                },
                error: function() {
                    $button.removeClass('loading').prop('disabled', false);
                    alert('An error occurred. Please try again or contact support.');
                }
            });
        });
    });

})(jQuery);
