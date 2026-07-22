(function() {
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { createElement } = window.wp.element;
    const { __ } = window.wp.i18n;
    
const theme = (window.avvanceBlocksData?.theme || 'light').toLowerCase();
const themeClass = (theme === 'dark') ? 'avvance-widget-dark' : 'avvance-widget-light';
    const iconLight = window.avvanceBlocksData?.iconLight || window.avvanceBlocksData?.icon || '';
    const iconDark = window.avvanceBlocksData?.iconDark || iconLight;
    const iconSrc = (theme === 'dark') ? iconDark : iconLight;

const label = createElement('span', {
    className: themeClass,
    style: { 
        display: 'flex', 
        flexWrap: 'wrap',
        alignItems: 'center', 
        gap: '8px',
        width: '100%'
    }
}, [
    'Pay over time with ',
        iconSrc && createElement('img', {
        key: 'icon',
        src: iconSrc,
        alt: 'Avvance',
        style: { height: '24px', margin: '0' }
    }),
    createElement('a', {
        key: 'learn-more',
        href: 'https://www.usbank.com/avvance-installment-loans.html',
        target: '_blank',
        rel: 'noopener noreferrer',
        style: { 
            fontSize: '0.9em',
            textDecoration: 'underline'
        },
        onClick: (e) => e.stopPropagation()
    }, 'Learn more')
]);

const content = createElement('div', {
    className: 'avvance-blocks-description',
    style: { 
        fontSize: '0.9em', 
        lineHeight: '1.5',
        whiteSpace: 'pre-wrap',
        padding: '10px 0'
    }
}, window.avvanceBlocksData?.description || '');

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
