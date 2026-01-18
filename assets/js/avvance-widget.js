/**
 * Avvance Widget JavaScript with Debug Logging
 * Handles price breakdown display, modal, and pre-approval flow
 */
(function($) {
    'use strict';
    
    var preapprovalWindow = null;
    var statusCheckInterval = null;
    
    // Debug logging helper
    function debugLog(message, data) {
        console.log('[Avvance Widget Debug] ' + message, data || '');
    }

    // Check if status indicates pre-approval was successful
    function isPreApprovedStatus(status) {
        if (!status) return false;
        var approvedStatuses = ['PRE_APPROVED', 'Qualified lead', 'APPROVED', 'qualified'];
        return approvedStatuses.indexOf(status) !== -1 || status.toLowerCase().indexOf('approved') !== -1;
    }
    
    var cartTotalObserver = null;
    var lastKnownTotal = 0;
    var updateDebounceTimer = null;

    $(document).ready(function() {
        debugLog('Widget script loaded');
        debugLog('Current page URL:', window.location.href);
        debugLog('Body classes:', $('body').attr('class'));
        debugLog('Is cart page:', $('body').hasClass('woocommerce-cart'));

        // Find all widget containers
        var $widgets = $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget');
        debugLog('Found widgets:', $widgets.length);

        // If multiple cart widgets exist, keep only the last one (most likely in correct position)
        if ($('.avvance-cart-widget').length > 1) {
            debugLog('WARNING: Multiple cart widgets found! Removing duplicates...');
            $('.avvance-cart-widget').slice(0, -1).each(function(index) {
                debugLog('Removing duplicate widget #' + (index + 1));
                $(this).closest('.avvance-cart-widget-container').remove();
            });
            // Re-select widgets after cleanup
            $widgets = $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget');
            debugLog('Widgets after cleanup:', $widgets.length);
        }

        if ($widgets.length === 0) {
            debugLog('WARNING: No widget containers found on page!');

            // If on cart page and no widget, check if hooks aren't working
            if ($('body').hasClass('woocommerce-cart')) {
                debugLog('On cart page but widget missing - likely WooCommerce Blocks cart');
                debugLog('Checking for cart table:', $('.woocommerce-cart-form').length);
                debugLog('Checking for cart totals:', $('.cart_totals').length);
                debugLog('Checking for blocks cart:', $('.wp-block-woocommerce-cart').length);

                // Try to inject widget manually for Blocks cart (only if PHP didn't render it)
                setTimeout(function() {
                    // Double-check widget wasn't rendered by PHP
                    if ($('.avvance-cart-widget').length === 0) {
                        debugLog('Still no widget after delay, injecting via JavaScript');
                        injectCartWidgetFallback();
                    } else {
                        debugLog('Widget was rendered by PHP, skipping JavaScript injection');
                    }
                }, 500);
            }

            return;
        }
        
        // Log widget details
        $widgets.each(function(index) {
            var $widget = $(this);
            debugLog('Widget #' + index + ' details:', {
                'class': $widget.attr('class'),
                'amount': $widget.data('amount'),
                'session-id': $widget.data('session-id'),
                'visible': $widget.is(':visible'),
                'display': $widget.css('display')
            });
        });
        
        // Load price breakdown for each widget (async, non-blocking)
        setTimeout(function() {
            debugLog('Starting price breakdown loading (after 100ms delay)');

            $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget').each(function() {
                var $widget = $(this);
                var amount = $widget.data('amount');

                debugLog('Processing widget with amount:', amount);

                if (amount) {
                    loadPriceBreakdown($widget, amount);
                } else {
                    debugLog('WARNING: Widget has no amount data!');
                }
            });
        }, 100);
        
        // Handle "Check your spending power" link clicks - open modal
        $(document).on('click', '.avvance-prequal-link', function(e) {
            e.preventDefault();
            debugLog('Pre-qual link clicked');
            debugLog('Modal element exists:', $('#avvance-preapproval-modal').length);
            debugLog('Modal current display:', $('#avvance-preapproval-modal').css('display'));
            openModal();
        });
        
        // Handle modal close
        $(document).on('click', '.avvance-modal-close, .avvance-modal-overlay', function() {
            debugLog('Modal close triggered');
            closeModal();
        });
        
        // Prevent modal content clicks from closing modal
        $(document).on('click', '.avvance-modal-content', function(e) {
            e.stopPropagation();
        });
        
        // Handle "See if you qualify" button
        $(document).on('click', '.avvance-qualify-button', function(e) {
            e.preventDefault();
            debugLog('Qualify button clicked');
            
            var $button = $(this);
            var $widget = $('.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget').first();
            var sessionId = $widget.data('session-id');
            
            debugLog('Session ID:', sessionId);
            
            if (!sessionId) {
                console.error('Avvance: Missing session ID');
                alert('Unable to start pre-approval. Please refresh the page and try again.');
                return;
            }
            
            // Show loading state
            $button.addClass('loading').prop('disabled', true);
            debugLog('Button loading state enabled');
            
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
                    debugLog('Pre-approval response:', response);
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
                            debugLog('Pre-approval window opened');
                            startStatusPolling();
                        } else {
                            debugLog('WARNING: Pop-up blocked');
                            alert('Please allow pop-ups to open your pre-approval application.');
                            window.open(response.data.url, '_blank');
                        }
                    } else {
                        debugLog('ERROR: Invalid pre-approval response', response);
                        var errorMsg = (response.data && response.data.message) ? response.data.message : 'Unable to create pre-approval request. Please try again.';
                        alert(errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    debugLog('ERROR: Pre-approval AJAX failed', {xhr: xhr, status: status, error: error});
                    $button.removeClass('loading').prop('disabled', false);
                    console.error('Avvance: Pre-approval creation error', error);
                    alert('An error occurred. Please try again or contact support.');
                }
            });
        });
        
        // Check for existing pre-approval on page load (after delay)
        setTimeout(function() {
            debugLog('Checking for existing pre-approval');
            checkPreapprovalStatus(function(data) {
                debugLog('Pre-approval check result:', data);
                if (data && isPreApprovedStatus(data.status) && data.max_amount) {
                    debugLog('Updating to pre-approved status with amount:', data.max_amount);
                    updateCTAToPreapproved(data.max_amount);
                }
            });
        }, 500);

        // Start monitoring cart total changes (for dynamic updates)
        if ($('body').hasClass('woocommerce-cart')) {
            setTimeout(function() {
                startCartTotalMonitoring();
            }, 1000);
        }
    });
    
    /**
     * Load price breakdown from API
     */
    function loadPriceBreakdown($widget, amount) {
        debugLog('=== STARTING PRICE BREAKDOWN ===');
        debugLog('Widget:', $widget);
        debugLog('Amount:', amount);
        
        debugLog('Making AJAX call to get_price_breakdown');
        
        $.ajax({
            url: avvanceWidget.ajaxUrl,
            type: 'POST',
            data: {
                action: 'avvance_get_price_breakdown',
                amount: amount
            },
            beforeSend: function() {
                debugLog('AJAX request sending...');
            },
            success: function(response) {
                debugLog('=== PRICE BREAKDOWN RESPONSE ===');
                debugLog('Full response:', response);
                debugLog('Response.success:', response.success);
                debugLog('Response.data:', response.data);

                if (!response.success || !response.data) {
                    debugLog('ERROR: Invalid response - missing success or data');
                    $widget.find('.avvance-price-message').html('<em>Pricing unavailable</em>');
                    return;
                }

                // Handle both array and object with paymentOptions array
                var paymentOptions = null;

                if (Array.isArray(response.data)) {
                    debugLog('Response.data is direct array');
                    paymentOptions = response.data;
                } else if (response.data.paymentOptions && Array.isArray(response.data.paymentOptions)) {
                    debugLog('Response.data has paymentOptions array');
                    paymentOptions = response.data.paymentOptions;
                } else if (response.data.priceBreakdown && Array.isArray(response.data.priceBreakdown)) {
                    debugLog('Response.data has priceBreakdown array');
                    paymentOptions = response.data.priceBreakdown;
                } else {
                    debugLog('ERROR: Could not find payment options array');
                    debugLog('Response.data keys:', Object.keys(response.data));
                    $widget.find('.avvance-price-message').html('<em>Pricing unavailable</em>');
                    return;
                }

                if (!paymentOptions || paymentOptions.length === 0) {
                    debugLog('ERROR: Payment options array is empty');
                    $widget.find('.avvance-price-message').html('<em>Pricing unavailable</em>');
                    return;
                }

                debugLog('Found payment options with length:', paymentOptions.length);

                // Get first option (lowest monthly payment)
                var firstOption = paymentOptions[0];
                debugLog('First option:', firstOption);

                // Try different possible field names for monthly payment
                var monthlyPayment = firstOption.monthlyPaymentAmount ||
                                   firstOption.monthlyPayment ||
                                   firstOption.paymentAmount ||
                                   firstOption.payment;

                debugLog('Monthly payment amount:', monthlyPayment);

                if (monthlyPayment && monthlyPayment > 0) {
                    var formattedPayment = parseFloat(monthlyPayment).toFixed(2);
                    debugLog('Formatted payment:', formattedPayment);

                    var message = 'From $' + formattedPayment + '/mo with ' +
                        '<img src="' + avvanceWidget.logoUrl + '" ' +
                        'alt="U.S. Bank Avvance" class="avvance-logo-inline">';

                    debugLog('Setting message HTML:', message);
                    $widget.find('.avvance-price-message').html(message);
                    debugLog('Message HTML updated successfully');
                } else {
                    debugLog('ERROR: Invalid or missing payment amount');
                    debugLog('Available fields in firstOption:', Object.keys(firstOption));
                    $widget.find('.avvance-price-message').html('<em>Pricing unavailable</em>');
                }
            },
            error: function(xhr, status, error) {
                debugLog('=== PRICE BREAKDOWN ERROR ===');
                debugLog('XHR:', xhr);
                debugLog('Status:', status);
                debugLog('Error:', error);
                debugLog('Response text:', xhr.responseText);
                
                console.error('Avvance: Price breakdown error', error);
                $widget.find('.avvance-price-message').html('<em>Pricing unavailable</em>');
            }
        });
    }
    
    /**
     * Open modal
     */
    function openModal() {
        debugLog('Opening modal');
        var $modal = $('#avvance-preapproval-modal');
        debugLog('Modal element found:', $modal.length > 0);
        
        if ($modal.length === 0) {
            debugLog('ERROR: Modal element not found in DOM!');
            alert('Modal not found. Please refresh the page.');
            return;
        }
        
        debugLog('Modal before fadeIn display:', $modal.css('display'));
        $modal.fadeIn(200);
        $('body').css('overflow', 'hidden');
        
        setTimeout(function() {
            debugLog('Modal after fadeIn display:', $modal.css('display'));
            debugLog('Modal visible:', $modal.is(':visible'));
        }, 250);
    }
    
    /**
     * Close modal
     */
    function closeModal() {
        debugLog('Closing modal');
        $('#avvance-preapproval-modal').fadeOut(200);
        $('body').css('overflow', '');
    }
    
    /**
     * Start polling for pre-approval status updates
     */
    function startStatusPolling() {
        debugLog('Starting status polling');
        
        if (statusCheckInterval) {
            clearInterval(statusCheckInterval);
        }
        
        var pollCount = 0;
        var maxPolls = 200;
        
        statusCheckInterval = setInterval(function() {
            pollCount++;
            debugLog('Poll #' + pollCount);
            
            if (preapprovalWindow && preapprovalWindow.closed) {
                debugLog('Pre-approval window closed, stopping polling');
                clearInterval(statusCheckInterval);
                statusCheckInterval = null;
            }
            
            checkPreapprovalStatus(function(data) {
                debugLog('Poll response:', data);
                if (data && isPreApprovedStatus(data.status) && data.max_amount) {
                    debugLog('Pre-approval received! Status:', data.status, 'Amount:', data.max_amount);
                    updateCTAToPreapproved(data.max_amount);

                    clearInterval(statusCheckInterval);
                    statusCheckInterval = null;

                    if (preapprovalWindow && !preapprovalWindow.closed) {
                        preapprovalWindow.close();
                        debugLog('Closed pre-approval window');
                    }
                }
            });
            
            if (pollCount >= maxPolls) {
                debugLog('Max polling attempts reached, stopping');
                clearInterval(statusCheckInterval);
                statusCheckInterval = null;
            }
        }, avvanceWidget.checkInterval);
    }
    
    /**
     * Check pre-approval status via AJAX
     */
    function checkPreapprovalStatus(callback) {
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
                console.error('Avvance: Error checking pre-approval status', error);
            }
        });
    }
    
    /**
     * Update CTA to show pre-approved message
     */
    function updateCTAToPreapproved(maxAmount) {
        debugLog('Updating CTA to preapproved with amount:', maxAmount);

        var formattedAmount = parseFloat(maxAmount).toLocaleString('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });

        var preapprovedMessage = '<span class="avvance-preapproved-message">' +
            'You\'re preapproved for up to $' + formattedAmount +
            '</span>';

        $('.avvance-prequal-cta').html(preapprovedMessage);

        debugLog('CTA updated to preapproved message');
    }

    /**
     * Inject cart widget when PHP hooks don't work (for Blocks cart)
     */
    function injectCartWidgetFallback() {
        debugLog('=== ATTEMPTING MANUAL WIDGET INJECTION ===');

        // Check if widget already exists (prevent duplicates)
        if ($('.avvance-cart-widget').length > 0) {
            debugLog('Widget already exists, aborting injection');
            return;
        }

        // Check if cart is empty (look for empty cart message)
        var $emptyCart = $('.wc-block-cart__empty-cart, .cart-empty, .woocommerce-info');
        if ($emptyCart.length > 0) {
            var emptyText = $emptyCart.text().toLowerCase();
            if (emptyText.indexOf('empty') !== -1 || emptyText.indexOf('no items') !== -1) {
                debugLog('Cart appears to be empty based on empty cart message, aborting injection');
                return;
            }
        }

        // Check if cart has actual items (look for cart items container)
        var $cartItems = $('.wc-block-cart-items, .woocommerce-cart-form__contents tbody tr');
        debugLog('Cart items containers found:', $cartItems.length);

        if ($cartItems.length > 0) {
            // For Blocks cart, check for individual items
            var $blockItems = $('.wc-block-cart-items .wc-block-cart-items__row, .wc-block-cart-items [class*="cart-item"]');
            debugLog('Block cart items found:', $blockItems.length);

            // For classic cart, check for table rows
            var $classicItems = $('.woocommerce-cart-form__contents tbody tr.cart_item, .woocommerce-cart-form__contents tbody tr[class*="cart"]');
            debugLog('Classic cart items found:', $classicItems.length);

            if ($blockItems.length === 0 && $classicItems.length === 0) {
                debugLog('No individual cart items found, cart appears to be empty, aborting injection');
                return;
            }
        } else {
            // No cart items container found at all - try broader search
            var $anyItems = $('[class*="cart-item"], [class*="cart_item"]');
            debugLog('Broader cart items search found:', $anyItems.length);

            if ($anyItems.length === 0) {
                debugLog('No cart items found anywhere, cart appears to be empty, aborting injection');
                return;
            }
        }

        // Get cart total from page (try multiple selectors)
        var cartTotal = 0;
        var $totalElement = null;

        // Try different selectors for cart total
        var selectors = [
            // Classic WooCommerce
            '.order-total .woocommerce-Price-amount bdi',
            '.order-total .woocommerce-Price-amount',
            '.cart-subtotal .woocommerce-Price-amount bdi',
            '.cart-subtotal .woocommerce-Price-amount',
            // WooCommerce Blocks - specific components
            '.wp-block-woocommerce-cart-order-summary-subtotal-block .wc-block-formatted-money-amount',
            '.wp-block-woocommerce-cart-order-summary-block .wc-block-formatted-money-amount',
            '.wc-block-components-totals-footer-item .wc-block-formatted-money-amount',
            '.wc-block-components-totals-item__value',
            '.wc-block-cart__totals .wc-block-formatted-money-amount',
            // More generic Blocks selectors
            '.wc-block-components-totals-item .wc-block-formatted-money-amount',
            '.wc-block-components-product-price .wc-block-formatted-money-amount',
            '.wp-block-woocommerce-cart .wc-block-formatted-money-amount',
            // Broader search
            '[class*="totals"] .wc-block-formatted-money-amount',
            '[class*="total"] [class*="money-amount"]'
        ];

        debugLog('Trying to find cart total with', selectors.length, 'selectors...');

        for (var i = 0; i < selectors.length; i++) {
            var $elements = $(selectors[i]);
            debugLog('Selector #' + (i+1) + ':', selectors[i]);
            debugLog('  Found', $elements.length, 'element(s)');

            if ($elements.length > 0) {
                // Try each found element
                $elements.each(function(index) {
                    if (cartTotal > 0) return; // Already found valid total

                    var totalText = $(this).text();
                    debugLog('  Element #' + (index + 1) + ' text:', totalText);

                    var cleanedText = totalText.replace(/[^0-9.]/g, '');
                    var parsedTotal = parseFloat(cleanedText);

                    debugLog('    Cleaned:', cleanedText, '- Parsed:', parsedTotal);

                    if (parsedTotal > 0) {
                        cartTotal = parsedTotal;
                        $totalElement = $(this);
                        debugLog('SUCCESS! Found cart total using selector:', selectors[i]);
                        debugLog('Cart total:', cartTotal);
                    }
                });

                if (cartTotal > 0) break;
            }
        }

        // If still not found, log all elements with money/currency patterns
        if (cartTotal === 0) {
            debugLog('=== FALLBACK: Searching all elements with currency patterns ===');

            // First, try to find elements with "total" in the class (more likely to be cart total)
            var $totalElements = $('[class*="total"][class*="price"], [class*="total"][class*="amount"], [class*="total"][class*="money"]');
            debugLog('Found', $totalElements.length, 'elements with "total" in class name');

            if ($totalElements.length > 0) {
                $totalElements.slice(0, 10).each(function(i) {
                    if (cartTotal > 0) return; // Already found

                    var text = $(this).text().trim();
                    var classes = $(this).attr('class');
                    var tagName = $(this).prop('tagName');
                    var cleanedText = text.replace(/[^0-9.]/g, '');
                    var parsedAmount = parseFloat(cleanedText);

                    debugLog('  Total element #' + (i+1) + ':');
                    debugLog('    Tag:', tagName);
                    debugLog('    Classes:', classes);
                    debugLog('    Text:', text.substring(0, 50));
                    debugLog('    Parsed amount:', parsedAmount);

                    if (parsedAmount >= 300 && parsedAmount <= 25000 && text.indexOf('$') !== -1) {
                        cartTotal = parsedAmount;
                        $totalElement = $(this);
                        debugLog('  ^^^ THIS LOOKS LIKE THE CART TOTAL! Using it.');
                    }
                });
            }

            // If still not found, search all currency elements
            if (cartTotal === 0) {
                var $allCurrency = $('[class*="price"], [class*="money"], [class*="amount"]');
                debugLog('Searching all', $allCurrency.length, 'currency elements');

                $allCurrency.slice(0, 15).each(function(i) {
                    if (cartTotal > 0) return; // Already found

                    var text = $(this).text().trim();
                    var classes = $(this).attr('class');
                    var tagName = $(this).prop('tagName');
                    var cleanedText = text.replace(/[^0-9.]/g, '');
                    var parsedAmount = parseFloat(cleanedText);

                    debugLog('  Element #' + (i+1) + ':');
                    debugLog('    Tag:', tagName);
                    debugLog('    Classes:', classes);
                    debugLog('    Text:', text.substring(0, 50));
                    debugLog('    Parsed amount:', parsedAmount);

                    // Skip if this looks like an item price or product grid price (not cart total)
                    if (classes && (classes.indexOf('item') !== -1 ||
                                   classes.indexOf('product-price') !== -1 ||
                                   classes.indexOf('grid') !== -1)) {
                        debugLog('    Skipping - looks like item/product price');
                        return;
                    }

                    if (parsedAmount >= 300 && parsedAmount <= 25000 && text.indexOf('$') !== -1) {
                        cartTotal = parsedAmount;
                        $totalElement = $(this);
                        debugLog('  ^^^ THIS LOOKS LIKE THE CART TOTAL! Using it.');
                    }
                });
            }
        }

        if (cartTotal < 300 || cartTotal > 25000) {
            debugLog('Cart total out of range for Avvance ($300-$25,000):', cartTotal);
            return;
        }

        debugLog('Cart total valid for widget:', cartTotal);

        // Find injection point - insert AFTER cart totals section
        var $injectionPoint = null;
        var injectionMethod = '';

        // Try to find cart totals section to insert after it
        // Use more specific selectors to avoid duplicates
        var totalsSectionSelectors = [
            '.wp-block-woocommerce-cart-order-summary-totals-block .wc-block-components-totals-wrapper', // Most specific
            '.wp-block-woocommerce-cart-order-summary-totals-block', // Container of totals
            '.wc-block-cart__sidebar', // Sidebar containing all cart totals
            '.wp-block-woocommerce-cart-order-summary-block', // Broader order summary
            '.wc-block-components-totals-wrapper:last' // Last totals wrapper as fallback
        ];

        debugLog('Looking for cart totals section to insert widget after...');

        for (var j = 0; j < totalsSectionSelectors.length; j++) {
            var $totalsSection = $(totalsSectionSelectors[j]);
            debugLog('Trying selector:', totalsSectionSelectors[j], '- Found:', $totalsSection.length);

            if ($totalsSection.length > 0) {
                $injectionPoint = $totalsSection;
                injectionMethod = 'after';
                debugLog('Injection point: After cart totals section using selector:', totalsSectionSelectors[j]);
                break;
            }
        }

        // Fallback: if we can't find totals section, prepend to cart container
        if (!$injectionPoint || $injectionPoint.length === 0) {
            debugLog('Could not find totals section, using fallback placement');
            if ($('.wp-block-woocommerce-cart').length > 0) {
                $injectionPoint = $('.wp-block-woocommerce-cart');
                injectionMethod = 'append';
                debugLog('Injection point: Blocks cart container (append)');
            } else if ($('.entry-content').length > 0) {
                $injectionPoint = $('.entry-content');
                injectionMethod = 'append';
                debugLog('Injection point: Entry content (append)');
            } else if ($('main').length > 0) {
                $injectionPoint = $('main');
                injectionMethod = 'append';
                debugLog('Injection point: Main element (append)');
            }
        }

        if (!$injectionPoint || $injectionPoint.length === 0) {
            debugLog('ERROR: Could not find injection point for widget');
            return;
        }

        // Generate session ID
        var sessionId = 'avv_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

        // Create widget HTML
        var widgetHtml = '<div class="avvance-cart-widget-container" style="margin: 20px 0;">' +
            '<div class="avvance-cart-widget" data-amount="' + cartTotal + '" data-session-id="' + sessionId + '">' +
            '<div class="avvance-widget-content">' +
            '<div class="avvance-price-message">' +
            '<span class="avvance-loading">Loading payment options...</span>' +
            '</div>' +
            '<div class="avvance-prequal-cta" style="margin-top: 8px;">' +
            '<a href="#" class="avvance-prequal-link" data-session-id="' + sessionId + '">Check your spending power</a>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '</div>';

        // Inject widget
        if (injectionMethod === 'after') {
            $injectionPoint.after(widgetHtml);
            debugLog('Widget injected AFTER totals section');
        } else if (injectionMethod === 'prepend') {
            $injectionPoint.prepend(widgetHtml);
            debugLog('Widget injected at START of container');
        } else {
            $injectionPoint.append(widgetHtml);
            debugLog('Widget injected at END of container');
        }

        debugLog('Widget HTML injected successfully');

        // Find the injected widget and load price breakdown
        var $injectedWidget = $('.avvance-cart-widget').first();
        if ($injectedWidget.length > 0) {
            debugLog('Found injected widget, loading price breakdown');
            setTimeout(function() {
                loadPriceBreakdown($injectedWidget, cartTotal);
            }, 200);
        }

        // Inject modal if not exists
        if ($('#avvance-preapproval-modal').length === 0) {
            debugLog('Modal not found, would need to inject it via AJAX');
            // For now, the modal should be rendered by PHP on all cart pages
            // If it's missing, we'd need to fetch it via AJAX
        }
    }

    /**
     * Start monitoring cart total for changes (quantity updates, item removal, etc.)
     */
    function startCartTotalMonitoring() {
        debugLog('=== STARTING CART TOTAL MONITORING ===');

        // Get initial cart total
        lastKnownTotal = getCurrentCartTotal();
        debugLog('Initial cart total:', lastKnownTotal);

        // Set up MutationObserver to watch for DOM changes in cart area
        if (typeof MutationObserver !== 'undefined') {
            var cartContainer = document.querySelector('.wp-block-woocommerce-cart, .woocommerce-cart-form, .entry-content');

            if (cartContainer) {
                debugLog('Setting up MutationObserver on cart container');

                cartTotalObserver = new MutationObserver(function(mutations) {
                    // Debounce: wait 500ms after last change before checking
                    clearTimeout(updateDebounceTimer);
                    updateDebounceTimer = setTimeout(function() {
                        var newTotal = getCurrentCartTotal();
                        debugLog('Checking cart total after mutation:', newTotal);

                        if (newTotal !== lastKnownTotal) {
                            if (newTotal > 0) {
                                debugLog('=== CART TOTAL CHANGED ===');
                                debugLog('Old total:', lastKnownTotal);
                                debugLog('New total:', newTotal);

                                lastKnownTotal = newTotal;
                                handleCartTotalChange(newTotal);
                            } else if (newTotal === 0 && lastKnownTotal > 0) {
                                // Cart became empty
                                debugLog('=== CART BECAME EMPTY ===');
                                debugLog('Old total:', lastKnownTotal);
                                lastKnownTotal = 0;

                                // Remove widget
                                var $existingWidget = $('.avvance-cart-widget').first();
                                if ($existingWidget.length > 0) {
                                    debugLog('Removing widget (cart empty)');
                                    $existingWidget.closest('.avvance-cart-widget-container').fadeOut(300, function() {
                                        $(this).remove();
                                    });
                                }
                            }
                        }
                    }, 500);
                });

                cartTotalObserver.observe(cartContainer, {
                    childList: true,
                    subtree: true,
                    characterData: true
                });

                debugLog('MutationObserver active');
            } else {
                debugLog('WARNING: Cart container not found for monitoring');
            }
        } else {
            debugLog('WARNING: MutationObserver not supported, using polling fallback');
            // Fallback: Poll every 2 seconds
            setInterval(function() {
                var newTotal = getCurrentCartTotal();
                if (newTotal !== lastKnownTotal && newTotal > 0) {
                    debugLog('Cart total changed (polling):', lastKnownTotal, '->', newTotal);
                    lastKnownTotal = newTotal;
                    handleCartTotalChange(newTotal);
                }
            }, 2000);
        }
    }

    /**
     * Get current cart total from page
     */
    function getCurrentCartTotal() {
        var total = 0;

        // Try multiple selectors for cart total (same as injection function)
        var selectors = [
            // WooCommerce Blocks - specific components
            '.wc-block-components-totals-footer-item .wc-block-formatted-money-amount',
            '.wp-block-woocommerce-cart-order-summary-totals-block .wc-block-components-totals-footer-item .wc-block-formatted-money-amount',
            '.wc-block-cart__totals .wc-block-formatted-money-amount',
            // Classic WooCommerce
            '.order-total .woocommerce-Price-amount',
            '.cart-subtotal .woocommerce-Price-amount'
        ];

        for (var i = 0; i < selectors.length; i++) {
            var $element = $(selectors[i]).first();
            if ($element.length > 0) {
                var text = $element.text();
                var cleaned = text.replace(/[^0-9.]/g, '');
                total = parseFloat(cleaned);
                if (total > 0) {
                    break;
                }
            }
        }

        // Fallback: search all currency elements if still not found
        if (total === 0) {
            var $allCurrency = $('[class*="price"], [class*="money"], [class*="amount"]');
            $allCurrency.slice(0, 10).each(function() {
                if (total > 0) return;

                var text = $(this).text().trim();
                var classes = $(this).attr('class') || '';

                // Skip item prices
                if (classes.indexOf('item') !== -1 ||
                    classes.indexOf('product-price') !== -1 ||
                    classes.indexOf('grid') !== -1) {
                    return;
                }

                var cleaned = text.replace(/[^0-9.]/g, '');
                var parsed = parseFloat(cleaned);

                if (parsed >= 300 && parsed <= 25000 && text.indexOf('$') !== -1) {
                    total = parsed;
                }
            });
        }

        return total;
    }

    /**
     * Handle cart total change - update or hide widget
     */
    function handleCartTotalChange(newTotal) {
        debugLog('Handling cart total change:', newTotal);

        var $existingWidget = $('.avvance-cart-widget').first();

        // Check if total is in valid range ($300-$25,000)
        if (newTotal >= 300 && newTotal <= 25000) {
            debugLog('Total in valid range, showing/updating widget');

            if ($existingWidget.length > 0) {
                // Widget exists, update it
                debugLog('Updating existing widget with new total');
                $existingWidget.attr('data-amount', newTotal);
                $existingWidget.find('.avvance-price-message').html('<span class="avvance-loading">Loading payment options...</span>');

                // Reload price breakdown
                setTimeout(function() {
                    loadPriceBreakdown($existingWidget, newTotal);
                }, 300);
            } else {
                // Widget doesn't exist, inject it
                debugLog('Widget not found, injecting new widget');
                injectCartWidgetFallback();
            }
        } else {
            debugLog('Total out of range ($300-$25,000), hiding widget');

            if ($existingWidget.length > 0) {
                debugLog('Removing widget from page');
                $existingWidget.closest('.avvance-cart-widget-container').fadeOut(300, function() {
                    $(this).remove();
                });
            }
        }
    }

})(jQuery);