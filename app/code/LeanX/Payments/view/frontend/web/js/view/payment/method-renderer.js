define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'leanx',
                component: 'LeanX_Payments/js/view/payment/method-renderer/leanx_payment'
            }
        );
        return Component.extend({});
    }
);