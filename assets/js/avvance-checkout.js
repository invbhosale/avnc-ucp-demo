/**
 * Avvance Checkout JavaScript
 */
(function($) {
    'use strict';
    
    // Nothing needed here for now - all interactions are inline
    // This file is reserved for future enhancements
    
    $(document).ready(function() {
        // Log that Avvance is loaded
        if (window.console && window.console.log) {
            console.log('Avvance for WooCommerce loaded');
        }
		
		 // Change place order button text when Avvance is selected
    $('input[name="payment_method"]').on('change', function() {
        if ($(this).val() === 'avvance' && $(this).is(':checked')) {
            $('#place_order').text('Pay with U.S. Bank Avvance');
        } else {
            $('#place_order').text($('#place_order').data('original-text') || 'Place order');
        }
    });
    
    // Store original button text
    $('#place_order').data('original-text', $('#place_order').text());
    
    // Trigger on page load if Avvance is already selected
    if ($('input[name="payment_method"]:checked').val() === 'avvance') {
        $('#place_order').text('Pay with U.S. Bank Avvance');
    }
    });
    
})(jQuery);
