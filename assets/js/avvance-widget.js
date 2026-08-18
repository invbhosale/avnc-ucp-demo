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

    // Shared selector matching every inline widget instance on the page.
    var AVVANCE_WIDGET_SELECTOR = '.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget, .avvance-category-widget, .avvance-new-in-store-widget';

    // Variables for pre-approval flow
    var preapprovalWindow = null;
    var statusCheckInterval = null;
    var PREAPPROVAL_OFFERS_PAGE_SIZE = 3;

    // Memoized pre-approval status response, shared across all widget instances
    // on a page load so multiple widgets don't each fire an identical AJAX call.
    var preapprovalStatusPromise = null;

    /**
     * Open a popup window centered over the current browser window (not just
     * the screen), matching the convention used by PayPal/Klarna-style
     * financing popups instead of the browser's default top-left placement.
     */
    function openCenteredPopup(url, name, width, height) {
        var screenLeft = window.screenLeft !== undefined ? window.screenLeft : window.screenX;
        var screenTop = window.screenTop !== undefined ? window.screenTop : window.screenY;
        var outerWidth = window.outerWidth || document.documentElement.clientWidth || screen.width;
        var outerHeight = window.outerHeight || document.documentElement.clientHeight || screen.height;

        var left = screenLeft + Math.max(0, (outerWidth - width) / 2);
        var top = screenTop + Math.max(0, (outerHeight - height) / 2);

        return window.open(
            url,
            name,
            'toolbar=no,location=no,directories=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=' + width + ',height=' + height + ',left=' + left + ',top=' + top
        );
    }

    function getLightLogoUrl() {
        return avvanceWidget.logoUrlLight || avvanceWidget.logoUrl || '';
    }

    function getDarkLogoUrl() {
        return avvanceWidget.logoUrlDark || getLightLogoUrl();
    }

    function getLogoUrlForElement($element) {
        var isDarkTheme = false;

        if ($element && $element.length) {
            isDarkTheme = $element.hasClass('avvance-widget-dark') || $element.closest('.avvance-widget-dark').length > 0;
        }

        return isDarkTheme ? getDarkLogoUrl() : getLightLogoUrl();
    }

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
                offers.push($.extend({}, item, {
                    apr: item.apr || 0,
                    paymentAmount: item.monthlyPaymentAmount || item.paymentAmount || 0,
                    termCount: item.termCount || null,
                    totalLoanWithInterest: item.totalLoanWithInterest || null,
                    offerType: item.offerType || (item.apr === 0 ? 'ZERO' : 'APR'),
                    promotionApr: item.promotionApr || null,
                    promotionTermInMonths: item.promotionTerm || item.promotionTermInMonths || null,
                    promotionPaymentAmount: item.promotionPaymentAmount || null
                }));
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
    function buildWidgetHtml(offers, hasPreapproval, maxAmount, sessionId, context, $widget) {
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
        var logoUrl = getLogoUrlForElement($widget);
        var badgeHtml = '';
        var rateHtml, ctaHtml;
        var logoHtml = '<span class="avvance-widget-logo"><img src="' + logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline"></span>';

        if (hasPreapproval && maxAmount) {
            badgeHtml = '<span class="avvance-preapproved-badge">You\'re pre-approved!</span>';
            rateHtml = formattedPayment
                ? 'As low as <strong>' + formattedPayment + '/month</strong> with'
                : 'Pay over time with';
            ctaHtml = '<a href="#" class="avvance-cta-link" data-modal="modal-c">See your details</a>';
        } else {
            var amountWithHtml = formattedPayment
                ? '<span class="avvance-rate-amount-with"><strong>' + formattedPayment + '/month</strong> with</span>'
                : 'with';
            rateHtml = (hasZeroApr ? '<strong class="avvance-zero-apr">0% APR</strong> or as low as ' : 'As low as ') +
                amountWithHtml;
            if (context === 'checkout') {
                ctaHtml = '<a href="#" class="avvance-learn-more-link" data-modal="modal-a" data-session-id="' + sessionId + '">Learn more</a>';
            } else {
                ctaHtml = '<a href="#" class="avvance-check-spending-link" data-modal="modal-b" data-session-id="' + sessionId + '">Check your spending power</a>';
            }
        }

        return badgeHtml + '<span class="avvance-rate-text">' + rateHtml + '</span>' + logoHtml + ctaHtml;
    }

    /**
     * Render loan option cards into a container
     */
    function renderLoanCards(offers, $container, originalAmount, initialLimit) {
        if (!offers || offers.length === 0) {
            $container.html('<p style="color: #484861; text-align: center; font-size: 14px;">No loan options available for this amount.</p>');
            return;
        }

        var tipIconUrl = (typeof avvanceWidget !== 'undefined' && getLightLogoUrl())
            ? getLightLogoUrl().replace('avvance-logo.svg', 'toggletip-icon.svg')
            : '';

        var html = '';
        var $modal = $container.closest('.avvance-modal');
        var isPreapprovalModal = $modal.length && $modal.attr('id') === 'avvance-modal-c';
        var limit = parseInt(initialLimit, 10);

        // Modal-c should always start paginated; if a call path omits the limit,
        // fall back to the configured preapproval page size instead of rendering all.
        if (isNaN(limit) || limit < 0) {
            limit = isPreapprovalModal ? PREAPPROVAL_OFFERS_PAGE_SIZE : 0;
        }

        var displayCount = (limit > 0) ? Math.min(limit, offers.length) : offers.length;
        for (var i = 0; i < displayCount; i++) {
            var offer = offers[i];

            if (offer.offerType === 'PROMO') {
                var promoApr   = parseFloat(offer.promotionApr || 0);
                var postApr    = parseFloat(offer.apr || 0).toFixed(2);
                var promoTerm  = parseInt(offer.promotionTermInMonths || 0);
                var totalTerm  = parseInt(offer.termCount || 0);
                var remainTerm = totalTerm - promoTerm;

                var bannerHtml   = '<strong>Promo: ' + promoApr + '% interest for the first ' + promoTerm +
                    ' months</strong> then <strong>' + postApr + '%</strong> applies for remaining ' + remainTerm + ' months';
                var promoMonthly = '$' + parseFloat(offer.paymentAmount).toFixed(2) +
                    '<span class="avvance-price-suffix">/month</span>';
                var chipText     = postApr + '% APR for ' + totalTerm + ' months';
                var tipText      = 'This APR combines the ' + promoApr + '% promotion period and the' +
                    ' interest after the promotion ' + postApr + '%, estimating total loan cost if only' +
                    ' minimum payments are made.';
                var tipHtml      = tipIconUrl
                    ? ' <button class="avvance-toggletip-btn" type="button" aria-label="More information about APR">' +
                      '<img src="' + tipIconUrl + '" class="avvance-toggletip-icon" alt="">' +
                      '<span class="avvance-toggletip" role="tooltip">' + tipText + '</span>' +
                      '</button>'
                    : '';

                var promoInterest = (offer.totalLoanWithInterest && originalAmount)
                    ? parseFloat(offer.totalLoanWithInterest) - parseFloat(originalAmount) : null;
                var promoDetails  = '';
                if (promoInterest !== null && promoInterest > 0) {
                    promoDetails += '<p><span class="avvance-loan-interest-total-label">Interest</span> <span class="avvance-loan-interest-total-value">$' + promoInterest.toLocaleString('en-US',
                        {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span></p>';
                }
                if (offer.totalLoanWithInterest) {
                    promoDetails += '<p><span class="avvance-loan-interest-total-label">Total</span> <span class="avvance-loan-interest-total-value">$' + parseFloat(offer.totalLoanWithInterest)
                        .toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span></p>';
                }
                var avoidHtml = offer.promotionPaymentAmount
                    ? '<p class="avvance-promo-avoid-interest">Or pay' +
                      ' <strong class="avvance-promo-avoid-amount">$' +
                      parseFloat(offer.promotionPaymentAmount).toFixed(2) + '/month to avoid interest</strong>' +
                      ' </p>'
                    : '';

                html += '<div class="avvance-loan-card avvance-loan-card--promo">';
                html += '<div class="avvance-promo-banner">' + bannerHtml + '</div>';
                html += '<div class="avvance-promo-card-body">';
                html += '<p class="avvance-loan-card-label">Minimum required payment</p>';
                html += '<div class="avvance-loan-card-header">';
                html += '<span class="avvance-loan-monthly">' + promoMonthly + '</span>';
                html += '<span class="avvance-loan-apr-badge avvance-loan-apr-badge--chip">' + chipText + tipHtml + '</span>';
                html += '</div>';
                if (promoDetails) {
                    html += '<div class="avvance-loan-details">' + promoDetails + '</div>';
                }
                html += '</div>';
                html += avoidHtml;
                html += '</div>';
                continue;
            }

            var monthlyHtml = '$' + parseFloat(offer.paymentAmount).toFixed(2) +
                '<span class="avvance-price-suffix">/month</span>';

            var termCount = (offer.termCount && parseInt(offer.termCount) > 0) ? offer.termCount : null;
            var aprVal = offer.apr !== null && offer.apr !== undefined
                ? parseFloat(offer.apr).toFixed(2) + '%'
                : '0%';
            var aprBadge = aprVal + ' APR' + (termCount ? ' for ' + termCount + ' months' : '');

            var detailsHtml = '';
            
            var interest = (offer.totalLoanWithInterest && originalAmount)
                ? parseFloat(offer.totalLoanWithInterest) - parseFloat(originalAmount)
                : null;
            if (interest !== null) {
                detailsHtml += '<p><span class="avvance-loan-interest-total-label">Interest</span> <span class="avvance-loan-interest-total-value">$' + interest.toLocaleString('en-US',
                    {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span></p>';
            }
            if (offer.totalLoanWithInterest) {
                detailsHtml += '<p><span class="avvance-loan-interest-total-label">Total</span> <span class="avvance-loan-interest-total-value">$' + parseFloat(offer.totalLoanWithInterest)
                    .toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span></p>';
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

        if ($modal.length) {
            $modal.data('all-offers', offers);
            $modal.data('original-amount', originalAmount);
            $modal.data('display-count', displayCount);

            var hasMoreOffers = offers.length > displayCount;
            if (hasMoreOffers) {
                $modal.find('.avvance-see-more-btn').show();
            } else {
                $modal.find('.avvance-see-more-btn').hide();
            }
        }
    }

    /**
     * standardized error message HTML for loan options container
     */
    function getLoansErrorMessage() {
        return '<div class="avvance-loan-card avvance-loan-card-error">' +
               '<p class="avvance-error-message-text">We were unable to load your loan options.</p>' +
               '</div>';
    }

    /**
     * Page-level error HTML for loan options loading failures.
     */
    function getPageLevelErrorHtml() {
        var dangerIconUrl = avvanceWidget.imagesUrl + 'danger-icon.svg';
        return '<div class="avvance-page-level-error">' +
               '<img src="' + dangerIconUrl + '" alt="Error" class="avvance-danger-icon" loading="eager">' +
               '<span>An error occurred while loading your loan options. Please try again.</span>' +
               '</div>';
    }

    function formatWholeDollarAmount(amount) {
        return parseFloat(amount).toLocaleString('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });
    }

    function getFieldLevelErrorMessage(maxPreapprovedAmount) {
        var minAmount = avvanceWidget.minAmount || 300;
        var maxAmount = avvanceWidget.maxAmount || 25000;
        var effectiveMax = maxPreapprovedAmount && maxPreapprovedAmount > 0
            ? Math.min(maxAmount, maxPreapprovedAmount)
            : maxAmount;

        return 'Enter a Spending amount between $' + formatWholeDollarAmount(minAmount) + ' and $' + formatWholeDollarAmount(effectiveMax) + '.';
    }

    function clearFieldLevelError($calculatorRow) {
        if (!$calculatorRow || !$calculatorRow.length) {
            return;
        }

        var $input = $calculatorRow.find('.avvance-currency-input').first();
        $calculatorRow.find('.avvance-field-error-message').remove();
        $input.removeClass('avvance-currency-input-error').removeAttr('aria-invalid');
    }

    function showFieldLevelError($calculatorRow, errorMessage) {
        if (!$calculatorRow || !$calculatorRow.length) {
            return;
        }

        var $input = $calculatorRow.find('.avvance-currency-input').first();
        var $inputGroup = $input.parent();
        clearFieldLevelError($calculatorRow);

        if (!errorMessage) {
            return;
        }

        var dangerIconUrl = avvanceWidget.imagesUrl + 'danger-icon.svg';
        $input.addClass('avvance-currency-input-error').attr('aria-invalid', 'true');
        $inputGroup.append(
            '<p class="avvance-field-error-message" role="alert">' +
            '<img src="' + dangerIconUrl + '" alt="Error" class="avvance-field-error-icon" loading="eager">' +
            '<span>' + errorMessage + '</span>' +
            '</p>'
        );
    }

    /**
     * Validate amount and return error message if invalid
     * 
     * Validation rules:
     * 1. Missing/empty amount → Field Error
     * 2. Amount < minAmount → Field Error
     * 3. Amount > maxAmount → Field Error
     * 4. Amount > preApprovalMax (for pre-approved users) → Field Error
     */
    function validateLoanAmount(amount, maxPreapprovedAmount) {
        var fieldErrorMessage = getFieldLevelErrorMessage(maxPreapprovedAmount);

        // Check for missing or empty amount
        if (!amount || amount <= 0) {
            return {
                isValid: false,
                errorType: 'MISSING_AMOUNT',
                displayMessage: getLoansErrorMessage(),
                fieldErrorMessage: fieldErrorMessage
            };
        }

        var minAmount = avvanceWidget.minAmount || 300;
        var maxAmount = avvanceWidget.maxAmount || 25000;

        // Check if amount is below minimum
        if (amount < minAmount) {
            return {
                isValid: false,
                errorType: 'BELOW_MINIMUM',
                displayMessage: getLoansErrorMessage(),
                fieldErrorMessage: fieldErrorMessage
            };
        }

        // Check if amount exceeds pre-approved max (if user is pre-approved)
        if (maxPreapprovedAmount && amount > maxPreapprovedAmount) {
            return {
                isValid: false,
                errorType: 'EXCEEDS_PREAPPROVAL_MAX',
                displayMessage: getLoansErrorMessage(),
                fieldErrorMessage: fieldErrorMessage
            };
        }

        // Check if amount exceeds maximum allowed
        if (amount > maxAmount) {
            return {
                isValid: false,
                errorType: 'EXCEEDS_MAXIMUM',
                displayMessage: getLoansErrorMessage(),
                fieldErrorMessage: fieldErrorMessage
            };
        }

        return {
            isValid: true,
            errorType: null,
            displayMessage: null,
            fieldErrorMessage: null
        };
    }

    /**
     * Load price breakdown for a modal and render loan cards
     */
    function loadModalPriceBreakdown(amount, $container, maxPreapprovedAmount, initialLimit) {
        var $calculatorSection = $container.closest('.avvance-modal-body-calculator, .avvance-modal-body');
        var $calculatorRow = $calculatorSection.find('.avvance-calculator-row');
        // Validate amount
        var validation = validateLoanAmount(amount, maxPreapprovedAmount);

        // Remove any previous page-level error
        $calculatorSection.find('.avvance-page-level-error').remove();
        clearFieldLevelError($calculatorRow);
        
        if (!validation.isValid) {
            showFieldLevelError($calculatorRow, validation.fieldErrorMessage);
            // Display error message in loan options container
            $container.html(validation.displayMessage);
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
                    renderLoanCards(offers, $container, amount, initialLimit);
                } else {
                    // Technical error loading loan options — ensure only one page-level error
                    $calculatorSection.find('.avvance-page-level-error').remove();
                    $calculatorRow.before(getPageLevelErrorHtml());
                    $container.html(getLoansErrorMessage());
                }
            },
            error: function() {
                // Technical error loading loan options — ensure only one page-level error
                $calculatorSection.find('.avvance-page-level-error').remove();
                $calculatorRow.before(getPageLevelErrorHtml());
                $container.html(getLoansErrorMessage());
            }
        });
    }

    /**
     * Load the actual offers a pre-approved consumer prequalified for
     * (modal-c only) and render with optional card cap for progressive reveal.
     * Falls back to the generic price-breakdown offers if the
     * pre-approval-specific endpoint errors for any reason.
     */
    function loadPreapprovalOffers(amount, $container, maxPreapprovedAmount, initialLimit) {
        var $calculatorSection = $container.closest('.avvance-modal-body-calculator, .avvance-modal-body');
        var $calculatorRow = $calculatorSection.find('.avvance-calculator-row');
        // Validate amount
        var validation = validateLoanAmount(amount, maxPreapprovedAmount);

        // Remove any previous page-level error
        $calculatorSection.find('.avvance-page-level-error').remove();
        clearFieldLevelError($calculatorRow);

        if (!validation.isValid) {
            showFieldLevelError($calculatorRow, validation.fieldErrorMessage);
            // Display error message in loan options container
            $container.html(validation.displayMessage);
            return;
        }

        $container.empty();

        $.ajax({
            url: avvanceWidget.ajaxUrl,
            type: 'POST',
            data: {
                action: 'avvance_get_preapproval_offers',
                amount: amount,
                nonce: avvanceWidget.nonce
            },
            success: function(response) {
                if (response.success) {
                    var offers = parseOffers(response.data);
                    renderLoanCards(offers, $container, amount, initialLimit);
                } else {
                    // Fall back to generic price-breakdown offers rather than an empty modal.
                    loadModalPriceBreakdown(amount, $container, maxPreapprovedAmount, initialLimit);
                }
            },
            error: function() {
                // Fall back to generic price-breakdown offers rather than an empty modal.
                loadModalPriceBreakdown(amount, $container, maxPreapprovedAmount, initialLimit);
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
     * Slider navigation — accepts jQuery objects for slider and dots containers.
     */
    function moveSlide($sliderEl, $dotsEl, direction) {
        var $slides = $sliderEl.find('.avvance-slide');
        var $dots = $dotsEl.find('.avvance-dot');

        var activeIndex = 0;
        $slides.each(function(i) {
            if ($(this).hasClass('active')) {
                activeIndex = i;
            }
        });

        var newIndex = activeIndex + direction;
        // Non-rotational: clamp at boundaries
        if (newIndex >= $slides.length || newIndex < 0) {
            return;
        }

        $slides.removeClass('active');
        $dots.removeClass('active');
        $slides.attr('aria-hidden', 'true');
        $slides.eq(newIndex).addClass('active').removeAttr('aria-hidden');
        $dots.eq(newIndex).addClass('active');
        updateDotImages($dots, newIndex);
        updateArrowStates($sliderEl);
    }

    function setSlide($sliderEl, $dotsEl, index) {
        var $slides = $sliderEl.find('.avvance-slide');
        var $dots = $dotsEl.find('.avvance-dot');

        $slides.removeClass('active');
        $dots.removeClass('active');
        $slides.attr('aria-hidden', 'true');
        $slides.eq(index).addClass('active').removeAttr('aria-hidden');
        $dots.eq(index).addClass('active');
        updateDotImages($dots, index);
        updateArrowStates($sliderEl);
    }

    function updateArrowStates($sliderEl) {
        var sliderId = $sliderEl.attr('id');
        var $slides = $sliderEl.find('.avvance-slide');
        var total = $slides.length;
        var activeIndex = 0;
        $slides.each(function(i) {
            if ($(this).hasClass('active')) {
                activeIndex = i;
            }
        });

        var $prevBtn = $('[data-slider="' + sliderId + '"][data-dir="-1"]');
        var $nextBtn = $('[data-slider="' + sliderId + '"][data-dir="1"]');

        var imagesUrl = (typeof avvanceWidget !== 'undefined' && avvanceWidget.imagesUrl) ? avvanceWidget.imagesUrl : '';

        // Previous button
        if (activeIndex === 0) {
            $prevBtn.prop('disabled', true);
            $prevBtn.find('img').attr('src', imagesUrl + 'chevron-left-disabled.svg');
        } else {
            $prevBtn.prop('disabled', false);
            $prevBtn.find('img').attr('src', imagesUrl + 'chevron-left.svg');
        }

        // Next button
        if (activeIndex === total - 1) {
            $nextBtn.prop('disabled', true);
            $nextBtn.find('img').attr('src', imagesUrl + 'chevron-right-disabled.svg');
        } else {
            $nextBtn.prop('disabled', false);
            $nextBtn.find('img').attr('src', imagesUrl + 'chevron-right.svg');
        }
    }

    function updateDotImages($dots, newIndex) {
        $dots.each(function() {
            var $img = $(this).find('img');
            if ($img.length) {
                var inactiveSrc = $(this).data('inactive-img');
                if (inactiveSrc) $img.attr('src', inactiveSrc);
            }
        });
        var $activeDot = $dots.eq(newIndex);
        var $activeImg = $activeDot.find('img');
        if ($activeImg.length) {
            var activeSrc = $activeDot.data('active-img');
            if (activeSrc) $activeImg.attr('src', activeSrc);
        }
    }


    // Track the element that triggered the modal for focus restoration
    var modalTriggerElement = null;

    /**
     * 
     * Open Modal types:
     * - modal-a: Learn more (non-preapproved)
     * - modal-b: Check spending power (non-preapproved)
     * - modal-c: See details (pre-approved, has max-amount limit)
     */
    function openModalByType(type, amount) {
        var modalId = '#avvance-' + type;
        var $modal = $(modalId);
        if (!$modal.length) return;

        modalTriggerElement = document.activeElement;

        var $input = $modal.find('.avvance-currency-input');
        if ($input.length && amount > 0) {
            $input.val(formatCurrency(amount));
        }

        var cardsId = $modal.find('.avvance-loan-cards').attr('id');
        
        // For modal-c (pre-approved): use max-amount as pre-approval limit
        if (type === 'modal-c') {
            var maxAmount = parseFloat($modal.attr('data-max-amount')) || amount;
            if (maxAmount > 0) {
                if ($input.length) {
                    $input.val(formatCurrency(maxAmount));
                }
                if (cardsId) {
                    // Fetch the offers this consumer actually prequalified for.
                    loadPreapprovalOffers(maxAmount, $('#' + cardsId), maxAmount, PREAPPROVAL_OFFERS_PAGE_SIZE);
                }
            }
        } else {
            // For modal-a and modal-b (non-preapproved): no pre-approval max
            if (cardsId && amount > 0) {
                loadModalPriceBreakdown(amount, $('#' + cardsId), null, 0);
            }
        }

        $(document).trigger('avvance:track', ['avvance_modal_view', {
            avvance_modal: type,
            avvance_amount: amount || 0,
            link_name: 'Avvance Modal View'
        }]);

        $modal.fadeIn(200, function() {
            // Move focus to the modal dialog for accessibility
            var $dialog = $modal.find('.avvance-modal-dialog');
            $dialog.attr('tabindex', '-1').focus();
            // Reset carousel to first slide and update arrow states
            $modal.find('.avvance-carousel-container').each(function() {
                var $sliderEl = $(this);
                var sliderId = $sliderEl.attr('id');
                var dotsId = sliderId.replace('avvance-slider-', 'avvance-dots-');
                var $dotsEl = $('#' + dotsId);
                setSlide($sliderEl, $dotsEl, 0);
            });
        });
        $('body').css('overflow', 'hidden');
    }

    /**
     * Close modal (any avvance modal)
     */
    function closeModal() {
        $('.avvance-modal').fadeOut(200, function() {
            // Restore focus to the element that triggered the modal
            if (modalTriggerElement && modalTriggerElement.focus) {
                modalTriggerElement.focus();
                modalTriggerElement = null;
            }
        });
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
                    updateCTAToPreapproved(data.max_amount, data?.expiry_date);

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
    function updateCTAToPreapproved(maxAmount, expiryDate) {
        $(document).trigger('avvance:track', ['avvance_preapproval_approved', {
            avvance_preapproval_status: 'PRE_APPROVED',
            avvance_max_amount: parseFloat(maxAmount) || 0,
            avvance_offer_expiry: expiryDate || '',
            link_name: 'Pre-Approval Approved'
        }]);

        // Update inline widgets
        $(AVVANCE_WIDGET_SELECTOR).each(function() {
            var $widget = $(this);
            var offers = $widget.data('offers') || [];
            var sessionId = $widget.data('session-id') || '';
            $widget.find('.avvance-widget-content').html(
                buildWidgetHtml(offers, true, maxAmount, sessionId, $widget.data('context') || 'product', $widget)
            );
        });

        // Update checkout banner if present
        var $checkoutBanner = $('#avvance-checkout-banner');
        if ($checkoutBanner.length) {
            var formattedMax = parseFloat(maxAmount).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
            var logoUrl = getLogoUrlForElement($checkoutBanner);
            var logoHtml = '<span class="avvance-widget-logo"><img src="' + logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline"></span>';
            $checkoutBanner.html(
                '<div class="avvance-checkout-preapproved">' +
                '<div class="avvance-checkout-banner-check">&#10003;</div>' +
                '<div class="avvance-checkout-banner-text">' +
                '<strong>You\'re pre-approved for $' + formattedMax + '!</strong> ' +
                '<span class="avvance-rate-text">Pay over time with</span>' + logoHtml +
                '<a href="#" class="avvance-cta-link" data-modal="modal-c">See your details</a>' +
                '</div>' +
                '</div>'
            );
        }

        // Update modal-c (pre-approved details modal) data
        var $detailsModal = $('#avvance-modal-c');
        if ($detailsModal.length && maxAmount) {
            var formattedMax2 = parseFloat(maxAmount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            var formattedMax2Short = parseFloat(maxAmount).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
            var minAmount = avvanceWidget.minAmount || 300;
            var retailerName = avvanceWidget.retailerName || '';
            const formattedExpiryDate = expiryDate && new Date(expiryDate.replace(' ', 'T') + 'Z').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            var checkIconUrl = getLightLogoUrl().replace('avvance-logo.svg', 'Avvance-Checkmark.svg');
            $detailsModal.data('max-amount', maxAmount);
            $detailsModal.attr('data-max-amount', maxAmount);
            $detailsModal.find('.avvance-success-title').html(
                '<img src="' + checkIconUrl + '" class="avvance-success-check-icon" alt=""> Your spending power is $' + formattedMax2Short + '!'
            );
            if (formattedExpiryDate) {
                $detailsModal.find('.avvance-success-details').html(
                    '<div class="avvance-detail-row"><span class="avvance-detail-label">Single-purchase range:</span><span class="avvance-detail-value"> $' + minAmount + '–$' + formattedMax2Short + '</span></div>' +
                    '<div class="avvance-detail-row"><span class="avvance-detail-label">Eligible Merchant:</span><span class="avvance-detail-value"> ' + retailerName + '</span></div>' +
                    '<div class="avvance-detail-row"><span class="avvance-detail-label">Offer Expires:</span><span class="avvance-detail-value">' + formattedExpiryDate + '</span></div>'
                );
            } else {
                $detailsModal.find('.avvance-success-details').html(
                    '<div class="avvance-detail-row"><span class="avvance-detail-label">Single-purchase range:</span><span class="avvance-detail-value"> $' + minAmount + '–$' + formattedMax2Short + '</span></div>' +
                    '<div class="avvance-detail-row"><span class="avvance-detail-label">Eligible Merchant:</span><span class="avvance-detail-value"> ' + retailerName + '</span></div>'
                );
            }
            $detailsModal.find('.avvance-currency-input').val(formatCurrency(maxAmount));
        }
    }

    /**
     * Get pre-approval status, memoized for the lifetime of the page load so
     * multiple widget instances (e.g. several "New in store" cards) share a
     * single AJAX call instead of each querying the same visitor's status.
     */
    function getPreapprovalStatus() {
        if (!preapprovalStatusPromise) {
            preapprovalStatusPromise = $.ajax({
                url: avvanceWidget.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'avvance_check_preapproval',
                    nonce: avvanceWidget.nonce
                }
            });
        }
        return preapprovalStatusPromise;
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
                if (!response.success) {
                    // Price breakdown API returned an error — hide the widget entirely rather than
                    // showing degraded fallback text. The failure is already logged server-side.
                    console.error('Avvance: price breakdown API error, hiding widget', response && response.data);
                    $widget.hide();
                    return;
                }

                var offers = parseOffers(response.data);

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

                getPreapprovalStatus().done(function(paResponse) {
                    var hasPreapproval = false;
                    var maxAmount = 0;
                    if (paResponse.success && paResponse.data && paResponse.data.has_preapproval) {
                        hasPreapproval = true;
                        maxAmount = paResponse.data.max_amount;
                    }
                    var context = $widget.data('context') || 'product';
                    $widget.find('.avvance-widget-content').html(
                        buildWidgetHtml(offers, hasPreapproval, maxAmount, sessionId, context, $widget)
                    );
                }).fail(function() {
                    var context = $widget.data('context') || 'product';
                    $widget.find('.avvance-widget-content').html(
                        buildWidgetHtml(offers, false, 0, sessionId, context, $widget)
                    );
                });
            },
            error: function(jqXHR, textStatus) {
                // Price breakdown API unreachable — hide the widget entirely rather than
                // showing degraded fallback text.
                console.error('Avvance: price breakdown request failed (' + textStatus + '), hiding widget');
                $widget.hide();
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
    /**
     * Reposition category widgets in WooCommerce Blocks product cards.
     *
     * In the classic WooCommerce loop the PHP hook woocommerce_after_shop_loop_item_title
     * fires after the price template — correct position, no JS needed.
     *
     * In WooCommerce Blocks (Product Collection block) the same hook fires after the
     * title block but before the price block, so the widget ends up above the price.
     * There is no PHP hook between the price block and the button block.
     *
     * This function detects the Blocks context via data-block-name (absent in classic
     * loop) and moves each widget to immediately after the price block.
     */
    function repositionBlocksCategoryWidgets() {
        $('.avvance-category-widget').each(function() {
            var $widget = $(this);
            // data-block-name is only present in WooCommerce Blocks rendered markup.
            var $priceBlock = $widget.closest('li').find('[data-block-name="woocommerce/product-price"]');
            if ($priceBlock.length) {
                $priceBlock.after($widget);
            }
        });
    }

    function initWidgets() {
        var $widgets = $(AVVANCE_WIDGET_SELECTOR);

        if (isCartPage) {
            injectNewInStoreWidgets();
        }

        if ($widgets.length === 0 && isCartPage) {
            retryInjectWidgetForBlocks();
            return;
        }

        $widgets.each(function() {
            loadPriceBreakdown($(this));
        });
    }

    /**
     * Retry cart widget injection up to 3 times with 2s delay.
     * Stops early if the cart widget is found.
     */
    function retryInjectWidgetForBlocks() {
        var attemptsLeft = 3;

        function attempt() {
            if ($('.avvance-cart-widget').length) {
                return;
            }

            injectWidgetForBlocks();

            attemptsLeft--;
            if (attemptsLeft > 0 && !$('.avvance-cart-widget').length) {
                setTimeout(attempt, 2000);
            }
        }

        setTimeout(attempt, 2000);
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
     * Watch the cart total display for changes on the WooCommerce Cart block.
     * The block updates cart totals via React/Store API re-renders, not the
     * classic `updated_cart_totals` jQuery event that initCartSupport() relies
     * on, so quantity/item changes there need a MutationObserver instead.
     */
    function initBlocksCartTotalWatcher() {
        var lastKnownTotal = getCartTotalFromPage();
        var pending = null;

        function checkForTotalChange() {
            var newTotal = getCartTotalFromPage();
            if (newTotal === null || newTotal === lastKnownTotal) {
                return;
            }
            lastKnownTotal = newTotal;

            var $cartWidget = $('.avvance-cart-widget');
            if ($cartWidget.length) {
                updateWidget($cartWidget, newTotal);
            } else if (newTotal >= avvanceWidget.minAmount && newTotal <= avvanceWidget.maxAmount) {
                // Cart total became eligible (e.g. rose above the minimum) and no widget exists yet.
                injectWidgetForBlocks();
            }
        }

        var observer = new MutationObserver(function() {
            // Debounce — a single quantity change can trigger several re-renders in quick succession.
            if (pending) {
                clearTimeout(pending);
            }
            pending = setTimeout(checkForTotalChange, 400);
        });
        observer.observe(document.body, { childList: true, subtree: true, characterData: true });
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
        var configuredTheme = (avvanceWidget.theme || 'light').toString().toLowerCase();
        if (configuredTheme !== 'light' && configuredTheme !== 'dark') {
            configuredTheme = 'light';
        }

        var widgetHtml = '<div class="avvance-cart-widget avvance-cart-widget-injected avvance-widget-' + configuredTheme + '" data-amount="' + cartTotal + '" data-session-id="' + sessionId + '" data-context="cart" style="margin: 20px 0;">' +
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
     * Watch the empty-cart "New in store" block (WooCommerce's
     * woocommerce/product-new block, rendered client-side inside the Cart
     * block's empty-cart state) and inject an Avvance widget into each
     * product card. Uses a MutationObserver rather than a one-shot timer
     * because this section can mount/unmount repeatedly as the cart's
     * empty/non-empty state changes via React re-renders.
     */
    function injectNewInStoreWidgets() {
        if (!avvanceWidget.newInStoreEnabled) {
            return;
        }

        var minAmount = avvanceWidget.minAmount;
        var maxAmount = avvanceWidget.maxAmount;
        var configuredTheme = (avvanceWidget.theme || 'light').toString().toLowerCase();
        if (configuredTheme !== 'light' && configuredTheme !== 'dark') {
            configuredTheme = 'light';
        }

        function processCards() {
            $('.wp-block-woocommerce-empty-cart-block .wc-block-grid__product:not([data-avvance-processed])').each(function() {
                var $card = $(this);
                $card.attr('data-avvance-processed', '1');

                var $addToCart = $card.find('.add_to_cart_button');
                var amount = parseFloat($addToCart.attr('data-price'));
                var productId = $addToCart.attr('data-product_id') || '';

                if (!amount) {
                    var priceText = $card.find('.wc-block-grid__product-price .woocommerce-Price-amount').first().text();
                    amount = priceText ? parseCurrencyInput(priceText) : 0;
                }

                if (!amount || amount < minAmount || amount > maxAmount) {
                    return;
                }

                var sessionId = 'avv_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                var widgetHtml = '<div class="avvance-new-in-store-widget avvance-widget-' + configuredTheme + '"' +
                    ' data-amount="' + amount + '"' +
                    (productId ? ' data-product-id="' + productId + '"' : '') +
                    ' data-session-id="' + sessionId + '"' +
                    ' data-context="new-in-store"' +
                    ' data-min-amount="' + minAmount + '"' +
                    ' data-max-amount="' + maxAmount + '">' +
                    '<div class="avvance-widget-content"><div class="avvance-price-message"></div></div>' +
                    '</div>';

                var $priceEl = $card.find('.wc-block-grid__product-price');
                if ($priceEl.length) {
                    $priceEl.after(widgetHtml);
                } else {
                    $card.append(widgetHtml);
                }

                loadPriceBreakdown($card.find('.avvance-new-in-store-widget'));
            });
        }

        processCards();

        var observer = new MutationObserver(function() {
            processCards();
        });
        observer.observe(document.body, { childList: true, subtree: true });
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
        var configuredTheme = (avvanceWidget.theme || 'light').toString().toLowerCase();
        if (configuredTheme !== 'light' && configuredTheme !== 'dark') {
            configuredTheme = 'light';
        }
        var logoUrl = avvanceWidget.logoUrl;
        var logoHtml = '<img src="' + logoUrl + '" alt="U.S. Bank Avvance" class="avvance-logo-inline">';

        var bannerHtml = '<div id="avvance-checkout-banner" class="avvance-checkout-banner avvance-widget-' + configuredTheme + '"' +
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
        repositionBlocksCategoryWidgets(); // Fix widget position inside WooCommerce Blocks product cards.

        if (isProductPage) {
            initVariableProductSupport();
        }

        if (isCartPage) {
            initCartSupport();
            initBlocksCartTotalWatcher();
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
                AVVANCE_WIDGET_SELECTOR + ', #avvance-checkout-banner'
            );
            var amount = $closest.length ? parseFloat($closest.data('amount')) : 0;
            if (!amount) {
                var $widget = $(AVVANCE_WIDGET_SELECTOR).first();
                amount = $widget.length ? parseFloat($widget.data('amount')) : 0;
            }
            openModalByType(type, amount);
        });

        // Handle modal close (all avvance modals)
        $(document).on('click', '.avvance-modal-close, .avvance-modal-overlay', function() {
            closeModal();
        });
        // Close modal on Escape key and trap focus within modal
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                if ($('.avvance-modal:visible').length) {
                    closeModal();
                }
            }
            // Focus trap: keep Tab within the visible modal
            if (e.key === 'Tab' || e.keyCode === 9) {
                var $visibleModal = $('.avvance-modal:visible');
                if ($visibleModal.length) {
                    var $focusable = $visibleModal.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
                    if ($focusable.length) {
                        var firstFocusable = $focusable[0];
                        var lastFocusable = $focusable[$focusable.length - 1];
                        if (e.shiftKey) {
                            if (document.activeElement === firstFocusable) {
                                e.preventDefault();
                                lastFocusable.focus();
                            }
                        } else {
                            if (document.activeElement === lastFocusable) {
                                e.preventDefault();
                                firstFocusable.focus();
                            }
                        }
                    }
                }
            }
        });
        // Prevent modal dialog clicks from closing modal
        $(document).on('click', '.avvance-modal-dialog', function(e) {
            e.stopPropagation();
        });

        // Calculate button — reads data-target on parent .avvance-calculator-row
        $(document).on('click', '.avvance-calc-btn', function(e) {
            e.preventDefault();
            var $row = $(this).closest('.avvance-calculator-row');
            var amount = parseCurrencyInput($row.find('.avvance-currency-input').val());
            var targetId = $row.data('target');
            var $cards = targetId ? $('#' + targetId) : $row.closest('.avvance-modal-body').find('.avvance-loan-cards');
            
            // Determine if this is a pre-approved modal (modal-c)
            var $modal = $row.closest('.avvance-modal');
            if ($modal.attr('id') === 'avvance-modal-c') {
                var maxPreapprovedAmount = parseFloat($modal.attr('data-max-amount')) || null;
                loadPreapprovalOffers(amount, $cards, maxPreapprovedAmount, PREAPPROVAL_OFFERS_PAGE_SIZE);
            } else {
                loadModalPriceBreakdown(amount, $cards, null, 0);
            }
        });

        // Ensure $ prefix is always present in currency input
        $(document).on('input', '.avvance-currency-input', function() {
            var val = $(this).val();
            // Strip everything except digits and dot
            var raw = val.replace(/[^0-9.]/g, '');
            $(this).val('$' + raw);
        });
 
        // Format currency input as $X.XX on blur
        $(document).on('blur', '.avvance-currency-input', function() {
            var raw = $(this).val().replace(/[^0-9.]/g, '');
            var amount = parseFloat(raw) || 0;
            $(this).val('$' + amount.toFixed(2));
        });

        // Clear field-level error styling and message while user edits amount.
        $(document).on('input', '.avvance-currency-input', function() {
            clearFieldLevelError($(this).closest('.avvance-calculator-row'));
        });

        // Handle "Continue shopping" button
        $(document).on('click', '.avvance-continue-shopping-btn', function(e) {
            e.preventDefault();
            closeModal();
        });

        // Carousel arrow navigation
        $(document).on('click', '.avvance-arrow-nav', function() {
            var $sliderEl = $('#' + $(this).data('slider'));
            var $dotsEl = $('#' + $(this).data('dots'));
            var dir = parseInt($(this).data('dir'));
            moveSlide($sliderEl, $dotsEl, dir);
        });

        // Carousel dot navigation
        $(document).on('click', '.avvance-dot', function() {
            var $sliderEl = $('#' + $(this).data('slider'));
            var $dotsEl = $('#' + $(this).data('dots'));
            var index = parseInt($(this).data('index'));
            setSlide($sliderEl, $dotsEl, index);
        });

        // Toggletip — toggle on icon click, close when clicking elsewhere in modal
        $(document).on('click', '.avvance-toggletip-btn', function(e) {
            e.stopPropagation();
            var $tip      = $(this).find('.avvance-toggletip');
            var isVisible = $tip.hasClass('is-visible');
            $('.avvance-toggletip.is-visible').removeClass('is-visible');
            if (!isVisible) {
                $tip.addClass('is-visible');
            }
        });

        $(document).on('click', '.avvance-modal-dialog', function() {
            $('.avvance-toggletip.is-visible').removeClass('is-visible');
        });

        // Expand modal-c loan cards from first offer to all offers.
        $(document).on('click', '.avvance-see-more-btn', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $modal = $button.closest('.avvance-modal');
            var offers = $modal.data('all-offers');
            var originalAmount = $modal.data('original-amount');
            var $cards = $modal.find('.avvance-loan-cards').first();

            if (!offers || !offers.length || !$cards.length) {
                return;
            }

            var currentDisplayCount = parseInt($modal.data('display-count'), 10);
            if (isNaN(currentDisplayCount) || currentDisplayCount < 0) {
                currentDisplayCount = 0;
            }

            var nextDisplayCount = currentDisplayCount + PREAPPROVAL_OFFERS_PAGE_SIZE;
            renderLoanCards(offers, $cards, originalAmount, nextDisplayCount);
        });

        // Handle "See if you qualify" button
        $(document).on('click', '.avvance-qualify-button', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $widget = $(AVVANCE_WIDGET_SELECTOR).first();
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
                    session_id: sessionId,
                    return_url: window.location.href
                },
                success: function(response) {
                    $button.removeClass('loading').prop('disabled', false);

                    if (response.success && response.data && response.data.url) {
                        closeModal();

                        $(document).trigger('avvance:track', ['avvance_preapproval_window_open', {
                            avvance_session_id: sessionId,
                            link_name: 'Pre-Approval Application Opened'
                        }]);

                        preapprovalWindow = openCenteredPopup(response.data.url, 'avvance_preapproval', 600, 700);

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
