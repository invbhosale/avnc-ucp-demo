(function() {
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { createElement } = window.wp.element;
    const { __ } = window.wp.i18n;
    
const label = createElement('span', {
    style: { display: 'flex', alignItems: 'center', gap: '8px' }
}, [
    'Pay over time with ',
    window.avvanceBlocksData?.icon && createElement('img', {
        key: 'icon',
        src: window.avvanceBlocksData.icon,
        alt: 'Avvance',
        style: { height: '24px', margin: '0 8px' }
    }),
    createElement('a', {
        key: 'learn-more',
        href: 'https://www.usbank.com/avvance-installment-loans.html',
        target: '_blank',
        rel: 'noopener noreferrer',
        style: { 
            fontSize: '0.9em',
            textDecoration: 'underline',
            marginLeft: '4px'
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
