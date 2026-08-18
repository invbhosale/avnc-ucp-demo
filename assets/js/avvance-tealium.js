/**
 * Avvance Tealium Analytics
 *
 * Front-end reporting layer for U.S. Bank Avvance.
 * - Fires a page view (utag.view) on every Avvance-enabled page.
 * - Fires link events (utag.link) for Avvance button/CTA clicks.
 * - Prints every payload to the browser console for verification.
 *
 * Public API:
 *   AvvanceAnalytics.trackPageView( overrides )
 *   AvvanceAnalytics.trackEvent( eventName, data )
 *   AvvanceAnalytics.trackClick( element, overrides )
 *
 * Other Avvance scripts can also fire events without a hard dependency:
 *   jQuery( document ).trigger( 'avvance:track', [ 'event_name', { key: 'value' } ] );
 */

(function (window, document) {
    'use strict';

    var config = window.avvanceTealium || {};

    var CONSOLE_STYLE = 'color:#fff;background:#0c2074;padding:2px 6px;border-radius:3px;';

    /**
     * Click targets mapped to Tealium event/link names.
     * Ordered most-specific first; the first match wins.
     */
    var CLICK_MAP = [
        { selector: '[data-avvance-track]', event: null, name: null },
        { selector: '.avvance-qualify-button', event: 'avvance_prequalify_start', name: 'See If You Qualify' },
        { selector: '.avvance-learn-more-link', event: 'avvance_cta_click', name: 'Learn More' },
        { selector: '.avvance-check-spending-link', event: 'avvance_cta_click', name: 'Check Your Spending Power' },
        { selector: '.avvance-cta-link', event: 'avvance_cta_click', name: 'See Your Details' },
        { selector: '.avvance-calc-btn', event: 'avvance_calculator_submit', name: 'Calculate Payment' },
        { selector: '.avvance-see-more-btn', event: 'avvance_offers_expand', name: 'See More Offers' },
        { selector: '.avvance-continue-shopping-btn', event: 'avvance_modal_exit', name: 'Continue Shopping' },
        { selector: '.avvance-toggletip-btn', event: 'avvance_tooltip_open', name: 'APR Tooltip' },
        { selector: '.avvance-modal-close', event: 'avvance_modal_close', name: 'Close Modal' },
        { selector: '.avvance-prequal-link', event: 'avvance_prequalify_start', name: 'See If You Qualify' },
        { selector: '#avvance-check-status-btn', event: 'avvance_status_check', name: 'Check Application Status' },
        { selector: '#avvance-check-status-cart', event: 'avvance_status_check', name: 'Check Application Status' },
        { selector: '#avvance-manual-link a', event: 'avvance_application_open', name: 'Open Avvance Application' },
        { selector: '.avvance-resume-banner a', event: 'avvance_application_open', name: 'Resume Avvance Application' },
        { selector: '[data-modal]', event: 'avvance_modal_open', name: 'Open Modal' },
        { selector: '.avvance-modal button, .avvance-product-widget button, .avvance-cart-widget button, .avvance-checkout-widget button, .avvance-category-widget button, .avvance-new-in-store-widget button', event: 'avvance_button_click', name: null },
        { selector: 'button, input[type="button"], input[type="submit"], [role="button"]', event: 'button_click', name: null }
    ];

    function isDebug() {
        return !!config.debug;
    }

    function log(label, payload) {
        if (!window.console) {
            return;
        }
        if (console.groupCollapsed) {
            console.groupCollapsed('%cAvvance Tealium%c ' + label, CONSOLE_STYLE, '');
            if (console.table) {
                console.table(payload);
            }
            console.log(payload);
            console.groupEnd();
        } else {
            console.log('Avvance Tealium ' + label, payload);
        }
    }

    function closest(el, selector) {
        if (!el || el.nodeType !== 1) {
            return null;
        }
        if (el.closest) {
            return el.closest(selector);
        }
        var node = el;
        while (node && node.nodeType === 1) {
            if (node.matches && node.matches(selector)) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    function text(el) {
        if (!el) {
            return '';
        }
        var value = (el.getAttribute('aria-label') || el.textContent || '').replace(/\s+/g, ' ').trim();
        return value.substring(0, 100);
    }

    function toNumber(value) {
        var parsed = parseFloat(String(value).replace(/[^0-9.\-]/g, ''));
        return isNaN(parsed) ? null : parsed;
    }

    /**
     * Widget context (product / cart / checkout / category) for a clicked element.
     */
    function widgetContext(el) {
        var widget = closest(el, '.avvance-product-widget, .avvance-cart-widget, .avvance-checkout-widget, .avvance-category-widget, .avvance-new-in-store-widget, #avvance-checkout-banner');
        if (!widget) {
            return {};
        }
        var context = {
            avvance_widget_context: widget.getAttribute('data-context') || widget.className.split(' ')[0] || ''
        };
        var amount = toNumber(widget.getAttribute('data-amount'));
        if (amount !== null) {
            context.avvance_amount = amount;
        }
        var productId = widget.getAttribute('data-product-id');
        if (productId) {
            context.product_id = productId;
        }
        return context;
    }

    function baseData() {
        return {
            site_name: config.siteName || '',
            site_section: 'avvance',
            site_environment: config.environment || '',
            page_name: config.pageName || document.title,
            page_type: config.pageType || 'other',
            page_url: window.location.href,
            page_referrer: document.referrer || '',
            customer_logged_in: config.isLoggedIn ? 'true' : 'false',
            currency_code: config.currency || 'USD',
            avvance_plugin_version: config.version || '',
            avvance_gateway_enabled: config.gatewayEnabled ? 'true' : 'false',
            timestamp: new Date().toISOString()
        };
    }

    function merge(target, source) {
        if (!source) {
            return target;
        }
        for (var key in source) {
            if (Object.prototype.hasOwnProperty.call(source, key) && source[key] !== null && source[key] !== undefined && source[key] !== '') {
                target[key] = source[key];
            }
        }
        return target;
    }

    function send(method, payload) {
        var utag = window.utag;
        if (utag && typeof utag[method] === 'function') {
            try {
                utag[method](payload);
            } catch (e) {
                if (window.console) {
                    console.warn('Avvance Tealium: utag.' + method + ' failed', e);
                }
            }
        } else if (isDebug() && window.console) {
            console.info('Avvance Tealium: utag.' + method + ' unavailable — payload logged only.');
        }
    }

    var AvvanceAnalytics = {
        /**
         * Fire a Tealium page view.
         *
         * @param {Object} overrides Extra data layer keys.
         */
        trackPageView: function (overrides) {
            var payload = merge(baseData(), config.pageData);
            payload.tealium_event = 'page_view';
            merge(payload, overrides);
            log('page_view', payload);
            send('view', payload);
            return payload;
        },

        /**
         * Fire a Tealium link (interaction) event.
         *
         * @param {string} eventName Tealium event name.
         * @param {Object} data      Extra data layer keys.
         */
        trackEvent: function (eventName, data) {
            var payload = merge(baseData(), config.pageData);
            payload.tealium_event = eventName || 'avvance_interaction';
            merge(payload, data);
            log(payload.tealium_event, payload);
            send('link', payload);
            return payload;
        },

        /**
         * Fire a link event derived from a clicked DOM element.
         *
         * @param {Element} element   Clicked element.
         * @param {Object}  overrides Extra data layer keys.
         */
        trackClick: function (element, overrides) {
            var data = merge(
                {
                    link_text: text(element),
                    link_id: element.id || '',
                    link_url: element.getAttribute('href') || '',
                    link_region: element.getAttribute('data-avvance-track-region') || ''
                },
                widgetContext(element)
            );
            var modal = element.getAttribute('data-modal');
            if (modal) {
                data.avvance_modal = modal;
            }
            merge(data, overrides);
            return AvvanceAnalytics.trackEvent((overrides && overrides.tealium_event) || 'avvance_button_click', data);
        }
    };

    function matchClickTarget(target) {
        for (var i = 0; i < CLICK_MAP.length; i++) {
            var entry = CLICK_MAP[i];
            var el = closest(target, entry.selector);
            if (el) {
                return {
                    element: el,
                    eventName: el.getAttribute('data-avvance-track') || entry.event || 'avvance_button_click',
                    linkName: el.getAttribute('data-avvance-track-name') || entry.name || text(el)
                };
            }
        }
        return null;
    }

    function onDocumentClick(e) {
        var match = matchClickTarget(e.target);
        if (!match) {
            return;
        }
        AvvanceAnalytics.trackClick(match.element, {
            tealium_event: match.eventName,
            link_name: match.linkName
        });
    }

    function onPaymentMethodChange(e) {
        var input = e.target;
        if (!input || input.name !== 'payment_method' || !input.checked) {
            return;
        }
        AvvanceAnalytics.trackEvent('payment_method_selected', {
            payment_method: input.value,
            link_name: 'Payment Method Selected',
            is_avvance: 'avvance' === input.value ? 'true' : 'false'
        });
    }

    function init() {
        AvvanceAnalytics.trackPageView();

        document.addEventListener('click', onDocumentClick, true);
        document.addEventListener('change', onPaymentMethodChange, true);

        if (window.jQuery) {
            window.jQuery(document).on('avvance:track', function (event, eventName, data) {
                AvvanceAnalytics.trackEvent(eventName, data);
            });
        }
    }

    window.AvvanceAnalytics = AvvanceAnalytics;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
