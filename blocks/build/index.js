(function() {
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { createElement, Fragment } = window.wp.element;
    const { __ } = window.wp.i18n;
    
    const iconSrc = window.avvanceBlocksData?.icon || '';
    const theme = (window.avvanceBlocksData?.theme || 'light').toLowerCase();
    const themeClass = (theme === 'dark') ? 'avvance-widget-dark' : 'avvance-widget-light';

const label = createElement('span', {
    className: themeClass + ' avvance-blocks-label'
}, [
    createElement('span', {
        key: 'label-text',
        className: 'avvance-blocks-label-text'
    }, 'Pay over time with '),
    iconSrc
        ? createElement('img', {
            key: 'icon',
            src: iconSrc,
            alt: 'U.S. Bank Avvance',
            className: 'avvance-blocks-label-icon'
        })
        : createElement('span', {
            key: 'icon-fallback',
            className: 'avvance-blocks-label-text'
        }, 'U.S. Bank Avvance')
]);

// WC Blocks calls React.cloneElement() on content/edit, so this must be a
// real (if empty) element — null/undefined aren't valid cloneElement targets.
const content = createElement(Fragment, {});

registerPaymentMethod({
    name: 'avvance',
    label: label,
    content: content,
    edit: content,
    canMakePayment: () => true,
    ariaLabel: 'U.S. Bank Avvance',  // <-- This is what gets stored
    placeOrderButtonLabel: __('Pay with U.S. Bank Avvance', 'avvance-for-woocommerce'),
    supports: {
        features: ['products']
    }
});
})();
