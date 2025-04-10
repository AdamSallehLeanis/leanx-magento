define([
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function ($, Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'LeanX_Payments/payment/leanx'
        },

        /** Overriding place order function */
        placeOrder: function (data, event) {
            var self = this;
            if (event) {
                event.preventDefault();
            }

            if (!this.validate() || !this.isPlaceOrderActionAllowed()) {
                return false;
            }

            this.isPlaceOrderActionAllowed(false);

            return this.getPlaceOrderDeferredObject()
                .done(function (orderId) {
                    console.log('Order placed, retrieving redirect URL...');

                    $.ajax({
                        url: url.build('leanx/payment/redirect'),  // Magento Controller to get URL
                        type: 'POST',
                        data: { client_data: orderId },
                        success: function (response) {
                            if (response.redirect_url) {

                                console.log("Received Response: " + response.abc);
                                window.location.href = response.redirect_url; // Redirect
                            } else {
                                console.warn('No redirect URL received, staying on checkout page.');
                                self.isPlaceOrderActionAllowed(true);
                            }
                        },
                        error: function () {
                            console.error('Failed to get redirect URL.');
                            self.isPlaceOrderActionAllowed(true);
                        }
                    });
                })
                .fail(function () {
                    console.error('Failed to place order.');
                    self.isPlaceOrderActionAllowed(true);
                });
        }
    });
});