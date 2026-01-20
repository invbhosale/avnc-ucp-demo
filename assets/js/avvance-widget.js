/**
 * COMPLETE UPDATED avvance-widget.js
 * 
 * This version checks for pre-approval after injecting widget on Blocks cart
 * Replace your current avvance-widget.js with this version
 */

(function($) {
    'use strict';

    console.log('[Avvance Widget Debug] Widget script loaded');
    console.log('[Avvance Widget Debug] Current page URL:', window.location.href);
    console.log('[Avvance Widget Debug] Body classes:', document.body.className);

    var isCartPage = document.body.classList.contains('woocommerce-cart');
    console.log('[Avvance Widget Debug] Is cart page:', isCartPage);

    /**
     * Check for pre-approval status via AJAX
     */
    function checkPreApprovalStatus($widget) {
        console.log('[Avvance Widget] === CHECKING PRE-APPROVAL STATUS ===');
        
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
                    console.log('[Avvance Widget] ✅ PRE-APPROVAL FOUND!');
                    console.log('[Avvance Widget] Message:', response.data.message);
                    console.log('[Avvance Widget] Max amount: $' + response.data.max_amount_formatted);
                    
                    // Update the CTA to show pre-approval
                    var $ctaContainer = $widget.find('.avvance-prequal-cta');
                    console.log('[Avvance Widget] CTA container found:', $ctaContainer.length);
                    
                    if ($ctaContainer.length) {
                        $ctaContainer.html(
                            '<span class="avvance-preapproved-message" data-preapproved="true" style="color: #0073aa; font-weight: 600;">' +
                            response.data.message +
                            '</span>'
                        );
                        console.log('[Avvance Widget] ✅ Updated widget with pre-approval message');
                    } else {
                        console.error('[Avvance Widget] CTA container not found!');
                    }
                } else {
                    console.log('[Avvance Widget] No pre-approval found, keeping default CTA');
                }
            },
            error: function(xhr, status, error) {
                console.error('[Avvance Widget] Pre-approval check failed:', error);
                console.error('[Avvance Widget] XHR:', xhr);
            }
        });
    }

    /**
     * Load price breakdown via AJAX
     */
    function loadPriceBreakdown($widget) {
        console.log('[Avvance Widget Debug] === STARTING PRICE BREAKDOWN ===');
        console.log('[Avvance Widget Debug] Widget:', $widget);
        
        var amount = parseFloat($widget.data('amount'));
        console.log('[Avvance Widget Debug] Amount:', amount);
        
        if (!amount || amount < 300 || amount > 25000) {
            console.log('[Avvance Widget Debug] Amount invalid or out of range');
            return;
        }

        console.log('[Avvance Widget Debug] Making AJAX call to get_price_breakdown');
        console.log('[Avvance Widget Debug] AJAX request sending...');

        $.ajax({
            url: avvanceWidget.ajaxUrl,
            type: 'POST',
            data: {
                action: 'avvance_get_price_breakdown',
                amount: amount,
                nonce: avvanceWidget.nonce
            },
            success: function(response) {
                console.log('[Avvance Widget Debug] === PRICE BREAKDOWN RESPONSE ===');
                console.log('[Avvance Widget Debug] Full response:', response);
                console.log('[Avvance Widget Debug] Response.success:', response.success);
                console.log('[Avvance Widget Debug] Response.data:', response.data);

                if (response.success) {
                    var paymentOptions = response.data;
                    
                    if (Array.isArray(paymentOptions)) {
                        console.log('[Avvance Widget Debug] Response.data is direct array');
                    } else if (paymentOptions && paymentOptions.paymentOptions) {
                        console.log('[Avvance Widget Debug] Response.data has nested paymentOptions');
                        paymentOptions = paymentOptions.paymentOptions;
                    }

                    console.log('[Avvance Widget Debug] Found payment options with length:', paymentOptions ? paymentOptions.length : 0);

                    if (paymentOptions && paymentOptions.length > 0) {
                        var firstOption = paymentOptions[0];
                        console.log('[Avvance Widget Debug] First option:', firstOption);
                        
                        var monthlyPayment = firstOption.paymentAmount;
                        console.log('[Avvance Widget Debug] Monthly payment amount:', monthlyPayment);
                        
                        var formattedPayment = monthlyPayment.toFixed(2);
                        console.log('[Avvance Widget Debug] Formatted payment:', formattedPayment);

                        var messageHtml = 'From $' + formattedPayment + '/mo with <img src="' + 
                            avvanceWidget.logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline">';
                        
                        console.log('[Avvance Widget Debug] Setting message HTML:', messageHtml);
                        
                        $widget.find('.avvance-price-message').html(messageHtml);
                        console.log('[Avvance Widget Debug] Message HTML updated successfully');
                    } else {
                        console.log('[Avvance Widget Debug] No payment options in response');
                    }
                } else {
                    console.log('[Avvance Widget Debug] Response not successful');
                }
            },
            error: function(xhr, status, error) {
                console.error('[Avvance Widget Debug] Price breakdown AJAX error:', error);
                console.error('[Avvance Widget Debug] Status:', status);
                console.error('[Avvance Widget Debug] XHR:', xhr);
            }
        });
    }

    /**
     * Initialize widgets
     */
    function initWidgets() {
        var $widgets = $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget');
        console.log('[Avvance Widget Debug] Found widgets:', $widgets.length);

        if ($widgets.length === 0) {
            console.log('[Avvance Widget Debug] WARNING: No widget containers found on page!');
            
            if (isCartPage) {
                console.log('[Avvance Widget Debug] On cart page but widget missing - likely WooCommerce Blocks cart');
                
                // Check what type of cart we have
                console.log('[Avvance Widget Debug] Checking for cart table:', $('.woocommerce-cart-form').length);
                console.log('[Avvance Widget Debug] Checking for cart totals:', $('.cart_totals').length);
                console.log('[Avvance Widget Debug] Checking for blocks cart:', $('.wp-block-woocommerce-cart').length);
                
                // Try to inject widget after a delay to let blocks render
                setTimeout(function() {
                    if ($('.avvance-cart-widget').length === 0) {
                        console.log('[Avvance Widget Debug] Still no widget after delay, injecting via JavaScript');
                        injectWidgetForBlocks();
                    }
                }, 2000);
            }
            return;
        }

        $widgets.each(function() {
            var $widget = $(this);
            loadPriceBreakdown($widget);
            
            // For cart widgets, also check pre-approval
            if ($widget.hasClass('avvance-cart-widget')) {
                checkPreApprovalStatus($widget);
            }
        });
    }

    /**
     * Inject widget for WooCommerce Blocks cart
     */
    function injectWidgetForBlocks() {
        console.log('[Avvance Widget Debug] === ATTEMPTING MANUAL WIDGET INJECTION ===');
        
        // Try to find cart total
        var cartTotal = null;
        var $cartItems = $('.wp-block-woocommerce-cart .wc-block-cart__main, .wc-block-cart-items, .woocommerce-cart');
        
        console.log('[Avvance Widget Debug] Cart items containers found:', $cartItems.length);
        console.log('[Avvance Widget Debug] Block cart items found:', $('.wp-block-woocommerce-cart-items-block').length);
        console.log('[Avvance Widget Debug] Classic cart items found:', $('.woocommerce-cart-form').length);
        
        // Try multiple selectors to find cart total
        var totalSelectors = [
            '.order-total .woocommerce-Price-amount bdi',
            '.order-total .woocommerce-Price-amount',
            '.cart-subtotal .woocommerce-Price-amount bdi',
            '.cart-subtotal .woocommerce-Price-amount',
            '.wp-block-woocommerce-cart-order-summary-subtotal-block .wc-block-formatted-money-amount',
            '.wp-block-woocommerce-cart-order-summary-block .wc-block-formatted-money-amount',
            '.wc-block-components-totals-footer-item .wc-block-formatted-money-amount',
            '.wc-block-components-totals-item__value',
            '.wc-block-cart__totals .wc-block-formatted-money-amount',
            '.wc-block-components-totals-item .wc-block-formatted-money-amount',
            '.wc-block-components-product-price .wc-block-formatted-money-amount',
            '.wp-block-woocommerce-cart .wc-block-formatted-money-amount',
            '[class*="totals"] .wc-block-formatted-money-amount',
            '[class*="total"] [class*="money-amount"]'
        ];
        
        console.log('[Avvance Widget Debug] Trying to find cart total with', totalSelectors.length, 'selectors');
        
        for (var i = 0; i < totalSelectors.length; i++) {
            var selector = totalSelectors[i];
            console.log('[Avvance Widget Debug] Selector #' + (i+1) + ':', selector);
            var $element = $(selector).first();
            console.log('[Avvance Widget Debug]   Found', $element.length);
            
            if ($element.length) {
                var text = $element.text().trim();
                var amount = parseFloat(text.replace(/[^0-9.]/g, ''));
                
                if (!isNaN(amount) && amount > 0) {
                    cartTotal = amount;
                    console.log('[Avvance Widget Debug]   Found total:', cartTotal);
                    break;
                }
            }
        }
        
        // Fallback: search all elements for currency pattern
        if (!cartTotal) {
            console.log('[Avvance Widget Debug] === FALLBACK: Searching all elements with currency patterns ===');
            var $allElements = $('*:contains("$")').filter(function() {
                var text = $(this).text().trim();
                return /^\$[\d,]+\.?\d*$/.test(text);
            });
            
            console.log('[Avvance Widget Debug] Found', $allElements.length, 'elements with currency');
            console.log('[Avvance Widget Debug] Searching all', $allElements.length);
            
            $allElements.each(function(idx) {
                var $el = $(this);
                var text = $el.text().trim();
                var amount = parseFloat(text.replace(/[^0-9.]/g, ''));
                
                console.log('[Avvance Widget Debug]   Element #' + (idx+1) + ':');
                console.log('[Avvance Widget Debug]     Tag:', this.tagName);
                console.log('[Avvance Widget Debug]     Classes:', this.className);
                console.log('[Avvance Widget Debug]     Text:', text);
                console.log('[Avvance Widget Debug]     Parsed amount:', amount);
                
                if (!isNaN(amount) && amount >= 300 && amount <= 25000) {
                    cartTotal = amount;
                    console.log('[Avvance Widget Debug]   ^^^ THIS LOOKS LIKE THE CART TOTAL! Using it.');
                    return false; // break
                }
            });
        }
        
        if (!cartTotal || cartTotal < 300 || cartTotal > 25000) {
            console.log('[Avvance Widget Debug] Cart total not found or out of range:', cartTotal);
            return;
        }
        
        console.log('[Avvance Widget Debug] Cart total valid for widget:', cartTotal);
        
        // Create widget HTML
        var sessionId = 'avv_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        var widgetHtml = '<div class="avvance-cart-widget avvance-cart-widget-injected" data-amount="' + cartTotal + '" data-session-id="' + sessionId + '" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px;">' +
            '<div class="avvance-widget-content">' +
            '<div class="avvance-price-message" style="margin-bottom: 10px;">' +
            '<span class="avvance-loading">Loading payment options...</span>' +
            '</div>' +
            '<div class="avvance-prequal-cta">' +
            '<a href="#" class="avvance-prequal-link" data-session-id="' + sessionId + '" style="color: #0073aa; text-decoration: underline;">Check your spending power</a>' +
            '</div>' +
            '</div>' +
            '</div>';
        
        // Find where to inject (after cart totals)
        console.log('[Avvance Widget Debug] Looking for cart totals section to insert widget after...');
        
        var injectionSelectors = [
            '.wp-block-woocommerce-cart-order-summary-totals-block .wc-block-components-totals-wrapper',
            '.wp-block-woocommerce-cart-order-summary-totals-block',
            '.wp-block-woocommerce-cart-order-summary-block',
            '.wc-block-components-totals-footer-item',
            '.cart_totals',
            '.woocommerce-cart-form'
        ];
        
        var injected = false;
        for (var j = 0; j < injectionSelectors.length; j++) {
            var injectSelector = injectionSelectors[j];
            console.log('[Avvance Widget Debug] Trying selector:', injectSelector);
            var $injectPoint = $(injectSelector).last();
            
            if ($injectPoint.length) {
                console.log('[Avvance Widget Debug] Injection point: After cart totals section using selector:', injectSelector);
                $injectPoint.after(widgetHtml);
                console.log('[Avvance Widget Debug] Widget injected AFTER totals section');
                injected = true;
                break;
            }
        }
        
        if (!injected) {
            console.log('[Avvance Widget Debug] Could not find injection point, appending to cart');
            $('.wp-block-woocommerce-cart, .woocommerce-cart').first().append(widgetHtml);
            console.log('[Avvance Widget Debug] Widget appended to cart container');
        }
        
        console.log('[Avvance Widget Debug] Widget HTML injected successfully');
        
        // Initialize the injected widget
        var $injectedWidget = $('.avvance-cart-widget-injected').first();
        if ($injectedWidget.length) {
            console.log('[Avvance Widget Debug] Found injected widget, loading price breakdown');
            loadPriceBreakdown($injectedWidget);
            
            // ✅ CHECK FOR PRE-APPROVAL AFTER INJECTION
            console.log('[Avvance Widget Debug] Checking for pre-approval...');
            checkPreApprovalStatus($injectedWidget);
            
            // Check if modal exists
            if (!$('#avvance-preapproval-modal').length) {
                console.log('[Avvance Widget Debug] Modal not found, would need to inject it via AJAX');
            }
        }
    }

    /**
     * Initialize on DOM ready
     */
    $(document).ready(function() {
        initWidgets();
    });

    /**
     * Re-initialize on AJAX cart updates (for WooCommerce blocks)
     */
    $(document.body).on('updated_cart_totals updated_checkout', function() {
        console.log('[Avvance Widget Debug] Cart/checkout updated, re-initializing widgets');
        initWidgets();
    });

})(jQuery);