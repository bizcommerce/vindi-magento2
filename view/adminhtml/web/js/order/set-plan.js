// File: app/code/Vindi/Payment/view/adminhtml/web/js/order/set-plan.js
define(['jquery'], function ($) {
    'use strict';
    return function (config, element) {
        $(element).on('change', function () {
            var planId = $(this).val();
            var planPrice = $(this).find('option:selected').data('price');
            var planInstallments = $(this).find('option:selected').data('installments');
            $.ajax({
                url: config.setPlanUrl,
                type: 'POST',
                data: {
                    selected_plan_id: planId,
                    selected_plan_price: planPrice,
                    selected_plan_installments: planInstallments
                },
                dataType: 'json'
            });
        });
    };
});
