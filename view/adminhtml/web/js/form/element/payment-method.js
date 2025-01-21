define([
    'jquery',
    'uiRegistry',
    'Magento_Ui/js/form/element/select'
], function ($, uiRegistry, select) {
    'use strict';
    return select.extend({

        initialize: function () {
            this._super();
            this.togglePaymentProfileField(this.value());
            return this;
        },

        /**
         * On value change handler.
         *
         * @param {String} value
         */
        onUpdate: function (value) {
            this.togglePaymentProfileField(value);
            return this._super();
        },

        /**
         * Toggle the payment profile field visibility based on payment method
         *
         * @param {String} value
         */
        togglePaymentProfileField: function (value) {
            let paymentProfileField = uiRegistry.get('index = payment_profile');

            if (paymentProfileField) {
                if (value === 'credit_card') {
                    paymentProfileField.show();
                } else {
                    paymentProfileField.hide();
                }
            }
        }
    });
});
