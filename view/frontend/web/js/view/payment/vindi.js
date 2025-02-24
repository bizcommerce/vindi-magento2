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
                type: 'vindi',
                component: 'Vindi_Payment/js/view/payment/method-renderer/vindi-cc'
            },
            {
                type: 'vindi_bankslip',
                component: 'Vindi_Payment/js/view/payment/method-renderer/vindi-bankslip'
            },
            {
                type: 'vindi_bankslippix',
                component: 'Vindi_Payment/js/view/payment/method-renderer/vindi-bankslippix'
            },
            {
                type: 'vindi_pix',
                component: 'Vindi_Payment/js/view/payment/method-renderer/vindi-pix'
            },
            {
                type: 'vindi_cardpix',
                component: 'Vindi_Payment/js/view/payment/method-renderer/vindi-cardpix'
            },
            {
                type: 'vindi_cardcard',
                component: 'Vindi_Payment/js/view/payment/method-renderer/vindi-cardcard'
            },
            {
                type: 'vindi_cardbankslippix',
                component: 'Vindi_Payment/js/view/payment/method-renderer/vindi-cardbankslippix'
            }
        );
        return Component.extend({});
    }
);
