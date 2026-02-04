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

    console.log('[Avvance Widget] Script loaded');

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
     * Open modal
     */
    function openModal() {
        console.log('[Avvance Widget] Opening modal');
        var $modal = $('#avvance-preapproval-modal');

        if ($modal.length === 0) {
            console.error('[Avvance Widget] ERROR: Modal element not found in DOM!');
            alert('Modal not found. Please refresh the page.');
            return;
        }

        $modal.fadeIn(200);
        $('body').css('overflow', 'hidden');
    }

    /**
     * Close modal
     */
    function closeModal() {
        console.log('[Avvance Widget] Closing modal');
        $('#avvance-preapproval-modal').fadeOut(200);
        $('body').css('overflow', '');
    }

    /**
     * Start polling for pre-approval status updates
     */
    function startStatusPolling() {
        console.log('[Avvance Widget] Starting status polling');

        if (statusCheckInterval) {
            clearInterval(statusCheckInterval);
        }

        var pollCount = 0;
        var maxPolls = 200;

        statusCheckInterval = setInterval(function() {
            pollCount++;
            console.log('[Avvance Widget] Poll #' + pollCount);

            if (preapprovalWindow && preapprovalWindow.closed) {
                console.log('[Avvance Widget] Pre-approval window closed, stopping polling');
                clearInterval(statusCheckInterval);
                statusCheckInterval = null;
            }

            checkPreapprovalStatusWithCallback(function(data) {
                if (data && isPreApprovedStatus(data.status) && data.max_amount) {
                    console.log('[Avvance Widget] Pre-approval received! Status:', data.status, 'Amount:', data.max_amount);
                    updateCTAToPreapproved(data.max_amount);

                    clearInterval(statusCheckInterval);
                    statusCheckInterval = null;

                    if (preapprovalWindow && !preapprovalWindow.closed) {
                        preapprovalWindow.close();
                        console.log('[Avvance Widget] Closed pre-approval window');
                    }
                }
            });

            if (pollCount >= maxPolls) {
                console.log('[Avvance Widget] Max polling attempts reached, stopping');
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
            error: function(xhr, status, error) {
                console.error('[Avvance Widget] Error checking pre-approval status', error);
            }
        });
    }

    /**
     * Update CTA to show pre-approved message
     */
    function updateCTAToPreapproved(maxAmount) {
        console.log('[Avvance Widget] Updating CTA to preapproved with amount:', maxAmount);

        var formattedAmount = parseFloat(maxAmount).toLocaleString('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });

        var preapprovedMessage = '<span class="avvance-preapproved-message">' +
            'You\'re preapproved for up to $' + formattedAmount +
            '</span>';

        $('.avvance-prequal-cta').html(preapprovedMessage);

        console.log('[Avvance Widget] CTA updated to preapproved message');
    }

    /**
     * Check for pre-approval status via AJAX
     */
    function checkPreApprovalStatus($widget) {
        console.log('[Avvance Widget] Checking pre-approval status...');
        
        $.ajax({
            url: avvanceWidget.ajaxUrl,
            type: 'POST',
            data: {
                action: 'avvance_check_preapproval',
                nonce: avvanceWidget.nonce
            },
            success: function(response) {
                console.log('[Avvance Widget] Pre-approval check response:', response);
                
                if (response.success && response.data.has_preapproval) {
                    console.log('[Avvance Widget] ✅ Pre-approval found!');
                    
                    var $ctaContainer = $widget.find('.avvance-prequal-cta');
                    
                    if ($ctaContainer.length) {
                        $ctaContainer.html(
                            '<span class="avvance-preapproved-message" data-preapproved="true" style="color: #0073aa; font-weight: 600;">' +
                            response.data.message +
                            '</span>'
                        );
                        console.log('[Avvance Widget] ✅ Updated widget with pre-approval');
                    }
                } else {
                    console.log('[Avvance Widget] No pre-approval found');
                }
            },
            error: function(xhr, status, error) {
                console.error('[Avvance Widget] Pre-approval check failed:', error);
            }
        });
    }

    /**
     * Load price breakdown via AJAX
     */
    function loadPriceBreakdown($widget) {
        console.log('[Avvance Widget] Loading price breakdown...');
        
        var amount = parseFloat($widget.data('amount'));
        
        if (!amount || amount < avvanceWidget.minAmount || amount > avvanceWidget.maxAmount) {
            console.log('[Avvance Widget] Amount invalid or out of range:', amount);
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
                console.log('[Avvance Widget] Price breakdown response:', response);

                if (response.success) {
                    var paymentOptions = response.data;

                    if (Array.isArray(paymentOptions) && paymentOptions.length > 0) {
                        var firstOption = paymentOptions[0];
                        var monthlyPayment = firstOption.monthlyPaymentAmount || firstOption.paymentAmount;

                        if (monthlyPayment === undefined || monthlyPayment === null) {
                            console.error('[Avvance Widget] Could not find payment amount in response:', firstOption);
                            $widget.find('.avvance-price-message').html('Pay over time with <img src="' +
                                avvanceWidget.logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline">');
                            return;
                        }

                        var formattedPayment = parseFloat(monthlyPayment).toFixed(2);

                        var messageHtml = 'From $' + formattedPayment + '/mo with <img src="' + 
                            avvanceWidget.logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline">';
                        
                        $widget.find('.avvance-price-message').html(messageHtml);
                        console.log('[Avvance Widget] Price message updated');
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('[Avvance Widget] Price breakdown error:', error);
            }
        });
    }

    /**
     * Update widget with new amount
     */
    function updateWidget($widget, newAmount) {
        console.log('[Avvance Widget] Updating widget with amount:', newAmount);
        
        var minAmount = avvanceWidget.minAmount;
        var maxAmount = avvanceWidget.maxAmount;
        
        // Check if amount is in valid range
        if (newAmount < minAmount || newAmount > maxAmount) {
            console.log('[Avvance Widget] Amount out of range, hiding widget');
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
        console.log('[Avvance Widget] Found widgets:', $widgets.length);

        if ($widgets.length === 0 && isCartPage) {
            console.log('[Avvance Widget] No widgets found on cart page, will inject via JS');
            setTimeout(injectWidgetForBlocks, 2000);
            return;
        }

        $widgets.each(function() {
            var $widget = $(this);
            var context = $widget.data('context');
            
            console.log('[Avvance Widget] Initializing widget:', context);
            
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
        console.log('[Avvance Widget] Initializing variable product support');
        
        // Store original price
        var $widget = $('.avvance-product-widget');
        if ($widget.length && !$widget.data('original-price')) {
            var originalPrice = $widget.data('amount');
            $widget.data('original-price', originalPrice);
            console.log('[Avvance Widget] Original price stored:', originalPrice);
        }
        
        // Listen for variation found event
        $(document.body).on('found_variation', '.variations_form', function(event, variation) {
            console.log('[Avvance Widget] ✅ Variation found:', variation);
            
            var $productWidget = $('.avvance-product-widget');
            
            if ($productWidget.length) {
                var newPrice = variation.display_price;
                console.log('[Avvance Widget] New variation price:', newPrice);
                
                updateWidget($productWidget, newPrice);
            }
        });
        
        // Listen for variation reset
        $(document.body).on('reset_data', '.variations_form', function() {
            console.log('[Avvance Widget] Variation reset');
            
            var $productWidget = $('.avvance-product-widget');
            
            if ($productWidget.length) {
                var originalPrice = $productWidget.data('original-price') || $productWidget.data('amount');
                console.log('[Avvance Widget] Resetting to original price:', originalPrice);
                
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
                console.log('[Avvance Widget] Quantity changed to', qty, ', new amount:', newAmount);
                
                updateWidget($productWidget, newAmount);
            }
        });
    }

    /**
     * Handle cart updates
     */
    function initCartSupport() {
        console.log('[Avvance Widget] Initializing cart support');
        
        // Cart totals updated
        $(document.body).on('updated_cart_totals', function() {
            console.log('[Avvance Widget] Cart totals updated');
            
            setTimeout(function() {
                var $cartWidget = $('.avvance-cart-widget');
                
                if ($cartWidget.length) {
                    // Try to get new cart total from the page
                    var newTotal = getCartTotalFromPage();
                    
                    if (newTotal) {
                        console.log('[Avvance Widget] New cart total:', newTotal);
                        updateWidget($cartWidget, newTotal);
                    }
                } else {
                    console.log('[Avvance Widget] No cart widget found, reinitializing');
                    initWidgets();
                }
            }, 500);
        });
        
        // Shipping method updated
        $(document.body).on('updated_shipping_method', function() {
            console.log('[Avvance Widget] Shipping method updated');
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
                    console.log('[Avvance Widget] Found cart total:', amount, 'from selector:', selectors[i]);
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
        console.log('[Avvance Widget] Initializing checkout support');
        
        // Payment method change
        $('form.checkout').on('change', 'input[name="payment_method"]', function() {
            var selectedMethod = $(this).val();
            console.log('[Avvance Widget] Payment method changed to:', selectedMethod);
            
            if (selectedMethod === 'avvance') {
                $('#avvance-checkout-widget-container').slideDown(300);
            } else {
                $('#avvance-checkout-widget-container').slideUp(300);
            }
        });
        
        // Checkout updated (after coupon, shipping change, etc.)
        $(document.body).on('updated_checkout', function() {
            console.log('[Avvance Widget] Checkout updated');
            
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
        console.log('[Avvance Widget] Attempting widget injection for Blocks cart');
        
        // Try to find cart total
        var cartTotal = getCartTotalFromPage();
        
        if (!cartTotal) {
            console.log('[Avvance Widget] Could not find cart total');
            return;
        }
        
        var minAmount = avvanceWidget.minAmount;
        var maxAmount = avvanceWidget.maxAmount;
        
        if (cartTotal < minAmount || cartTotal > maxAmount) {
            console.log('[Avvance Widget] Cart total out of range:', cartTotal);
            return;
        }
        
        console.log('[Avvance Widget] Cart total valid:', cartTotal);
        
        // Create widget HTML
        var sessionId = 'avv_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        var widgetHtml = '<div class="avvance-cart-widget avvance-cart-widget-injected" data-amount="' + cartTotal + '" data-session-id="' + sessionId + '" data-context="cart" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px;">' +
            '<div class="avvance-widget-content">' +
            '<div class="avvance-price-message">' +
            '<span class="avvance-loading">Loading payment options...</span>' +
            '</div>' +
            '<div class="avvance-prequal-cta" style="margin-top: 8px;">' +
            '<a href="#" class="avvance-prequal-link" data-session-id="' + sessionId + '" style="color: #0073aa; text-decoration: underline;">Check your spending power</a>' +
            '</div>' +
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
                console.log('[Avvance Widget] Injecting after:', injectionSelectors[i]);
                $injectPoint.after(widgetHtml);
                injected = true;
                break;
            }
        }
        
        if (!injected) {
            console.log('[Avvance Widget] Could not find injection point');
            return;
        }
        
        console.log('[Avvance Widget] ✅ Widget injected');
        
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
        console.log('[Avvance Widget] DOM ready, initializing...');

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

        // Handle "Check your spending power" link clicks - open modal
        $(document).on('click', '.avvance-prequal-link', function(e) {
            e.preventDefault();
            console.log('[Avvance Widget] Pre-qual link clicked');
            openModal();
        });

        // Handle modal close
        $(document).on('click', '.avvance-modal-close, .avvance-modal-overlay', function() {
            console.log('[Avvance Widget] Modal close triggered');
            closeModal();
        });

        // Prevent modal content clicks from closing modal
        $(document).on('click', '.avvance-modal-content', function(e) {
            e.stopPropagation();
        });

        // Handle "See if you qualify" button
        $(document).on('click', '.avvance-qualify-button', function(e) {
            e.preventDefault();
            console.log('[Avvance Widget] Qualify button clicked');

            var $button = $(this);
            var $widget = $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget').first();
            var sessionId = $widget.data('session-id');

            console.log('[Avvance Widget] Session ID:', sessionId);

            if (!sessionId) {
                console.error('[Avvance Widget] Missing session ID');
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
                    console.log('[Avvance Widget] Pre-approval response:', response);
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
                            console.log('[Avvance Widget] Pre-approval window opened');
                            startStatusPolling();
                        } else {
                            console.log('[Avvance Widget] WARNING: Pop-up blocked');
                            alert('Please allow pop-ups to open your pre-approval application.');
                            window.open(response.data.url, '_blank');
                        }
                    } else {
                        console.error('[Avvance Widget] Invalid pre-approval response', response);
                        var errorMsg = (response.data && response.data.message) ? response.data.message : 'Unable to create pre-approval request. Please try again.';
                        alert(errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[Avvance Widget] Pre-approval AJAX failed', {xhr: xhr, status: status, error: error});
                    $button.removeClass('loading').prop('disabled', false);
                    alert('An error occurred. Please try again or contact support.');
                }
            });
        });

        // Check for existing pre-approval on page load (after delay)
        setTimeout(function() {
            console.log('[Avvance Widget] Checking for existing pre-approval');
            checkPreapprovalStatusWithCallback(function(data) {
                console.log('[Avvance Widget] Pre-approval check result:', data);
                if (data && isPreApprovedStatus(data.status) && data.max_amount) {
                    console.log('[Avvance Widget] Updating to pre-approved status with amount:', data.max_amount);
                    updateCTAToPreapproved(data.max_amount);
                }
            });
        }, 500);
    });

})(jQuery);