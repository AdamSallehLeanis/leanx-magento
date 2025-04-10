define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        // 'use strict';
        console.log('Renderer List Before:', rendererList);

        rendererList.push({
            type: 'leanx',
            component: 'LeanX_Payments/js/view/payment/method-renderer/leanx'
        });
        console.log('Renderer List After:', rendererList);
        return Component.extend({});
    }
);