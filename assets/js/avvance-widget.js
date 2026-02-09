/**
 * Avvance Widget JavaScript - COMPLETE WITH CRITICAL FEATURES
 * 
 * NEW FEATURES:
 * 1. Variable product variation change detection
 * 2. Checkout widget show/hide logic
 * 3. Enhanced cart updates
 * 4. Category page widget support
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
     * Check if status indicates pre-approval was successful
     *
     * Only 2 valid lead statuses from Avvance:
     * - PRE_APPROVED: Customer is pre-approved (eligible)
     * - NOT_APPROVED: Customer is declined (not eligible)
     */
    function isPreApprovedStatus(status) {
        if (!status) return false;
        // Only PRE_APPROVED is considered approved
        return status === 'PRE_APPROVED';
    }

    /**
     * Parse API response into normalized offers array.
     * Handles both old format (flat array with monthlyPaymentAmount)
     * and new format ({ offers: [...] } with paymentAmount + offerType).
     */
    function parseOffers(data) {
        var offers = [];

        // New format: { offers: [...] }
        if (data && data.offers && Array.isArray(data.offers)) {
            offers = data.offers;
        }
        // Old format: flat array with monthlyPaymentAmount
        else if (Array.isArray(data)) {
            for (var i = 0; i < data.length; i++) {
                var item = data[i];
                offers.push({
                    apr: item.apr || 0,
                    paymentAmount: item.monthlyPaymentAmount || item.paymentAmount || 0,
                    termInMonths: item.termInMonths || null,
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
     * Render loan option cards into a container
     */
    function renderLoanCards(offers, $container) {
        if (!offers || offers.length === 0) {
            $container.html('<p style="color: #666; text-align: center;">No loan options available for this amount.</p>');
            return;
        }

        var html = '';
        for (var i = 0; i < offers.length; i++) {
            var offer = offers[i];
            var badge = '';
            var priceHtml = '';
            var detailsHtml = '';

            if (offer.offerType === 'PROMO') {
                var promoMonths = offer.promotionTermInMonths || '—';
                badge = 'Promo: 0% interest for the first ' + promoMonths + ' months';
                var promoPayment = offer.promotionPaymentAmount ? parseFloat(offer.promotionPaymentAmount).toFixed(2) : '—';
                priceHtml = '$' + promoPayment + ' <span class="avvance-price-suffix">/ month</span>';
                if (offer.termInMonths) {
                    detailsHtml = 'Then $' + parseFloat(offer.paymentAmount).toFixed(2) + '/month for ' + offer.termInMonths + ' months';
                }
            } else if (offer.offerType === 'ZERO') {
                var zeroMonths = offer.termInMonths || '—';
                badge = '0% APR for ' + zeroMonths + ' months';
                priceHtml = '$' + parseFloat(offer.paymentAmount).toFixed(2) + ' <span class="avvance-price-suffix">/ month</span>';
            } else {
                // APR offer
                var aprVal = offer.apr ? parseFloat(offer.apr).toFixed(2) : '0.00';
                var aprMonths = offer.termInMonths || '—';
                badge = aprVal + '% APR for ' + aprMonths + ' months';
                priceHtml = '$' + parseFloat(offer.paymentAmount).toFixed(2) + ' <span class="avvance-price-suffix">/ month</span>';
            }

            html += '<div class="avvance-loan-card">';
            html += '  <div class="avvance-card-badge">' + badge + '</div>';
            html += '  <div class="avvance-card-row">';
            html += '    <div>';
            html += '      <div class="avvance-monthly-price">' + priceHtml + '</div>';
            if (detailsHtml) {
                html += '      <div class="avvance-card-details">' + detailsHtml + '</div>';
            }
            html += '    </div>';
            html += '  </div>';
            html += '</div>';
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
                    renderLoanCards(offers, $container);
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
     * Open pre-approval modal
     */
    function openModal() {
        var $modal = $('#avvance-preapproval-modal');

        if ($modal.length === 0) {
            alert('Modal not found. Please refresh the page.');
            return;
        }

        // Get amount from first visible widget
        var $widget = $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget').first();
        var amount = $widget.length ? parseFloat($widget.data('amount')) : 0;

        if (amount > 0) {
            $('#avvance-modal-amount').val(formatCurrency(amount));
            loadModalPriceBreakdown(amount, $('#avvance-modal-loan-cards'));
        }

        $modal.fadeIn(200);
        $('body').css('overflow', 'hidden');
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
     * Update widget to show pre-approved state
     * State 3: "You're pre-approved! As low as $XXX.XX/month with <logo> See your details"
     */
    function updateCTAToPreapproved(maxAmount) {
        // Update each widget that has a cached monthly payment
        $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget').each(function() {
            var $widget = $(this);
            var monthlyPayment = $widget.data('monthly-payment');
            var formattedPayment = monthlyPayment ? parseFloat(monthlyPayment).toFixed(2) : null;

            var messageHtml = '<span class="avvance-preapproved-badge">You\'re pre-approved!</span> ';
            if (formattedPayment) {
                messageHtml += 'As low as <strong>$' + formattedPayment + '/month</strong> with ';
            } else {
                messageHtml += 'Pay over time with ';
            }
            messageHtml += '<img src="' + avvanceWidget.logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline"> ';
            messageHtml += '<a href="#" class="avvance-see-details-link">See your details</a>';

            $widget.find('.avvance-widget-content').html(
                '<div class="avvance-price-message avvance-preapproved-state">' + messageHtml + '</div>'
            );
        });

        // Update the preapproved details modal with the new max amount
        var $detailsModal = $('#avvance-preapproved-details-modal');
        if ($detailsModal.length && maxAmount) {
            var formattedMax = parseFloat(maxAmount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            // Update data attribute for loan card loading
            $detailsModal.data('max-amount', maxAmount);
            $detailsModal.attr('data-max-amount', maxAmount);

            // Update the success banner text
            $detailsModal.find('.avvance-success-title').html(
                '<span class="avvance-success-check">&#10003;</span> Your spending power is $' + formattedMax + '!'
            );

            // Update the success text
            var minAmount = avvanceWidget.minAmount || 300;
            $detailsModal.find('.avvance-success-text').html(
                'You\'ve been pre-approved for U.S. Bank Avvance for $' + formattedMax + '. ' +
                'To use your spending power, your purchase must be between $' + minAmount + ' and $' + formattedMax + '.'
            );

            // Update the input field
            $('#avvance-preapproved-modal-amount').val('$' + formattedMax);
        }
    }

    /**
     * Check for pre-approval status via AJAX
     */
    function checkPreApprovalStatus($widget) {
        $.ajax({
            url: avvanceWidget.ajaxUrl,
            type: 'POST',
            data: {
                action: 'avvance_check_preapproval',
                nonce: avvanceWidget.nonce
            },
            success: function(response) {
                if (response.success && response.data.has_preapproval) {
                    updateCTAToPreapproved(response.data.max_amount);
                }
            },
            error: function() {
            }
        });
    }

    /**
     * Load price breakdown via AJAX
     *
     * API returns array of payment options, e.g.:
     * [{ apr: 0, monthlyPaymentAmount: 183.89 }, { apr: 8.99, monthlyPaymentAmount: 105.24 }]
     *
     * Widget states:
     * 1. No 0% APR: "As low as $XXX.XX/month with <logo> Check your spending power"
     * 2. 0% APR available: "0% APR or as low as $XXX.XX/month with <logo> Check your spending power"
     * 3. Pre-approved (handled separately by updateCTAToPreapproved)
     */
    function loadPriceBreakdown($widget) {
        var amount = parseFloat($widget.data('amount'));

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
                if (response.success) {
                    var offers = parseOffers(response.data);

                    if (offers.length > 0) {
                        // Store all offers on the widget for modal use
                        $widget.data('offers', offers);

                        // Get best offer based on priority: ZERO > PROMO > APR
                        var bestOffer = getBestOffer(offers);

                        if (!bestOffer || !bestOffer.paymentAmount) {
                            $widget.find('.avvance-widget-content').html(
                                '<div class="avvance-price-message">' +
                                'Pay over time with <img src="' + avvanceWidget.logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline">' +
                                '</div>'
                            );
                            return;
                        }

                        // Determine display payment and whether to show 0% APR
                        var displayPayment, hasZeroApr = false;

                        if (bestOffer.offerType === 'ZERO') {
                            hasZeroApr = true;
                            displayPayment = bestOffer.paymentAmount;
                        } else if (bestOffer.offerType === 'PROMO') {
                            hasZeroApr = true;
                            displayPayment = bestOffer.promotionPaymentAmount || bestOffer.paymentAmount;
                        } else {
                            displayPayment = bestOffer.paymentAmount;
                        }

                        var formattedPayment = parseFloat(displayPayment).toFixed(2);

                        // Cache the monthly payment on the widget for pre-approved state
                        $widget.data('monthly-payment', displayPayment);

                        // Build the widget message
                        var messageHtml = '';
                        if (hasZeroApr) {
                            messageHtml += '<strong class="avvance-zero-apr">0% APR</strong> or as low as ';
                        } else {
                            messageHtml += 'As low as ';
                        }
                        messageHtml += '<strong>$' + formattedPayment + '/month</strong> with ';
                        messageHtml += '<img src="' + avvanceWidget.logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline"> ';

                        var sessionId = $widget.data('session-id') || '';
                        messageHtml += '<a href="#" class="avvance-prequal-link" data-session-id="' + sessionId + '">Check your spending power</a>';

                        $widget.find('.avvance-widget-content').html(
                            '<div class="avvance-price-message">' + messageHtml + '</div>'
                        );
                    }
                }
            },
            error: function() {
            }
        });
    }

    /**
     * Update widget with new amount
     */
    function updateWidget($widget, newAmount) {
        var minAmount = avvanceWidget.minAmount;
        var maxAmount = avvanceWidget.maxAmount;

        // Check if amount is in valid range
        if (newAmount < minAmount || newAmount > maxAmount) {
            $widget.fadeOut(300);
            return;
        }
        
        // Show widget if hidden
        if (!$widget.is(':visible')) {
            $widget.fadeIn(300);
        }
        
        // Update data attribute
        $widget.attr('data-amount', newAmount);
        
        // Reload price breakdown
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
            var $widget = $(this);
            var context = $widget.data('context');

            loadPriceBreakdown($widget);
            
            // Check pre-approval for cart and product widgets
            if (context === 'cart' || context === 'product') {
                checkPreApprovalStatus($widget);
            }
        });
    }

    /**
     * Handle variable product variation changes
     */
    function initVariableProductSupport() {
        // Store original price
        var $widget = $('.avvance-product-widget');
        if ($widget.length && !$widget.data('original-price')) {
            var originalPrice = $widget.data('amount');
            $widget.data('original-price', originalPrice);
        }

        // Listen for variation found event
        $(document.body).on('found_variation', '.variations_form', function(event, variation) {
            var $productWidget = $('.avvance-product-widget');

            if ($productWidget.length) {
                var newPrice = variation.display_price;
                updateWidget($productWidget, newPrice);
            }
        });

        // Listen for variation reset
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
        
        // Listen for quantity changes on product page
        $('.qty').on('change', function() {
            var $productWidget = $('.avvance-product-widget');
            
            if ($productWidget.length) {
                var qty = parseInt($(this).val()) || 1;
                var basePrice = $productWidget.data('amount');
                
                // Check if it's a variation product with selected variation
                var $variationForm = $(this).closest('.variations_form');
                if ($variationForm.length) {
                    var variationId = $variationForm.find('input[name="variation_id"]').val();
                    if (variationId) {
                        // Let the variation change handler deal with it
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
        // Cart totals updated
        $(document.body).on('updated_cart_totals', function() {
            setTimeout(function() {
                var $cartWidget = $('.avvance-cart-widget');

                if ($cartWidget.length) {
                    // Try to get new cart total from the page
                    var newTotal = getCartTotalFromPage();

                    if (newTotal) {
                        updateWidget($cartWidget, newTotal);
                    }
                } else {
                    initWidgets();
                }
            }, 500);
        });

        // Shipping method updated
        $(document.body).on('updated_shipping_method', function() {
            $(document.body).trigger('updated_cart_totals');
        });
    }

    /**
     * Get cart total from page DOM
     */
    function getCartTotalFromPage() {
        // Try multiple selectors
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
     * Handle checkout page
     */
    function initCheckoutSupport() {
        // Payment method change
        $('form.checkout').on('change', 'input[name="payment_method"]', function() {
            var selectedMethod = $(this).val();

            if (selectedMethod === 'avvance') {
                $('#avvance-checkout-widget-container').slideDown(300);
            } else {
                $('#avvance-checkout-widget-container').slideUp(300);
            }
        });
        
        // Checkout updated (after coupon, shipping change, etc.)
        $(document.body).on('updated_checkout', function() {
            // Recheck if Avvance is selected
            if ($('input[name="payment_method"]:checked').val() === 'avvance') {
                $('#avvance-checkout-widget-container').slideDown(300);
            }
        });
        
        // Initial check on page load
        if ($('input[name="payment_method"]:checked').val() === 'avvance') {
            $('#avvance-checkout-widget-container').show();
        }
    }

    /**
     * Inject widget for WooCommerce Blocks cart
     */
    function injectWidgetForBlocks() {
        // Try to find cart total
        var cartTotal = getCartTotalFromPage();

        if (!cartTotal) {
            return;
        }

        var minAmount = avvanceWidget.minAmount;
        var maxAmount = avvanceWidget.maxAmount;

        if (cartTotal < minAmount || cartTotal > maxAmount) {
            return;
        }
        
        // Create widget HTML
        var sessionId = 'avv_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        var widgetHtml = '<div class="avvance-cart-widget avvance-cart-widget-injected" data-amount="' + cartTotal + '" data-session-id="' + sessionId + '" data-context="cart" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px;">' +
            '<div class="avvance-widget-content">' +
            '<div class="avvance-price-message"></div>' +
            '</div>' +
            '</div>';
        
        // Find injection point
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
        
        // Initialize the injected widget
        var $injectedWidget = $('.avvance-cart-widget-injected');
        if ($injectedWidget.length) {
            loadPriceBreakdown($injectedWidget);
            checkPreApprovalStatus($injectedWidget);
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
            initCheckoutSupport();
        }

        // Handle "Check your spending power" link clicks - open pre-approval modal
        $(document).on('click', '.avvance-prequal-link', function(e) {
            e.preventDefault();
            openModal();
        });

        // Handle info icon clicks on category widgets - check pre-approval status and open appropriate modal
        $(document).on('click', '.avvance-info-icon', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var $icon = $(this);
            var amount = parseFloat($icon.data('amount')) || 0;

            // Check pre-approval status and open appropriate modal
            $.ajax({
                url: avvanceWidget.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'avvance_check_preapproval',
                    nonce: avvanceWidget.nonce
                },
                success: function(response) {
                    if (response.success && response.data.has_preapproval) {
                        // User is pre-approved - open preapproved details modal
                        var $detailsModal = $('#avvance-preapproved-details-modal');
                        if ($detailsModal.length) {
                            var maxAmount = parseFloat($detailsModal.attr('data-max-amount')) || parseFloat($detailsModal.data('max-amount')) || response.data.max_amount || 0;

                            if (maxAmount > 0) {
                                loadModalPriceBreakdown(maxAmount, $('#avvance-preapproved-modal-loan-cards'));
                            }

                            $detailsModal.fadeIn(200);
                            $('body').css('overflow', 'hidden');
                        }
                    } else {
                        // User is not pre-approved - open pre-approval modal
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
                },
                error: function() {
                    // On error, default to pre-approval modal
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
            });
        });

        // Handle "See your details" link clicks - open preapproved details modal
        $(document).on('click', '.avvance-see-details-link', function(e) {
            e.preventDefault();
            var $detailsModal = $('#avvance-preapproved-details-modal');
            if ($detailsModal.length) {
                // Get max amount - use attr() as fallback since data() might be cached
                var maxAmount = parseFloat($detailsModal.attr('data-max-amount')) || parseFloat($detailsModal.data('max-amount')) || 0;

                // Always try to load loan cards
                if (maxAmount > 0) {
                    loadModalPriceBreakdown(maxAmount, $('#avvance-preapproved-modal-loan-cards'));
                }

                $detailsModal.fadeIn(200);
                $('body').css('overflow', 'hidden');
            }
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
            var $widget = $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget').first();
            var sessionId = $widget.data('session-id');

            if (!sessionId) {
                alert('Unable to start pre-approval. Please refresh the page and try again.');
                return;
            }

            // Show loading state
            $button.addClass('loading').prop('disabled', true);

            // Create pre-approval request
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
                        // Close modal
                        closeModal();

                        // Open pre-approval application in new window
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

        // Check for existing pre-approval on page load (after delay)
        setTimeout(function() {
            checkPreapprovalStatusWithCallback(function(data) {
                if (data && isPreApprovedStatus(data.status) && data.max_amount) {
                    updateCTAToPreapproved(data.max_amount);
                }
            });
        }, 500);
    });

})(jQuery);