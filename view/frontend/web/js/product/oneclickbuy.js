/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'jquery',
    'mage/translate',
    'underscore',
    'mage/url',
], function ($, $t, _,url) {
    'use strict';

    return function () {
        let cardSelector = $("#card-selector");
        let productId = $("#product-id").text();
        let submitButton = $("#payment-oneclickbuy");

        submitButton.on( "click", function() {
            let param = {
                profile:  cardSelector.val(),
                productId : productId
            };
            $.ajax({
                showLoader: true,
                url: BASE_URL + 'vindi_vr/oneclickbuy/transaction',
                data: param,
                type: "POST"
            })
        } );

        return $.mage;
    }
});
