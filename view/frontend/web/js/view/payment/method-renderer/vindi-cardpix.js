define([
    'underscore',
    'ko',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Payment/js/model/credit-card-validation/credit-card-data',
    'Vindi_Payment/js/model/credit-card-validation/credit-card-number-validator',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/totals',
    'Magento_SalesRule/js/action/set-coupon-code',
    'Magento_SalesRule/js/action/cancel-coupon',
    'Magento_Catalog/js/price-utils',
    'mage/translate',
    'jquery',
    'vindi-card-form',
    'mageUtils',
    'Vindi_Payment/js/model/taxvat',
    'Vindi_Payment/js/model/validate'
], function (
    _,
    ko,
    Component,
    creditCardData,
    cardNumberValidator,
    quote,
    totals,
    setCouponCodeAction,
    cancelCouponCodeAction,
    priceUtils,
    $t,
    $,
    creditCardForm,
    utils,
    taxvat,
    documentValidate
) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Vindi_Payment/payment/vindi-cardpix',
            paymentProfiles: [],
            creditCardType: '',
            creditCardExpYear: '',
            creditCardExpMonth: '',
            creditCardNumber: '',
            vindiCreditCardNumber: '',
            creditCardOwner: '',
            creditCardSsStartMonth: '',
            creditCardSsStartYear: '',
            showCardData: ko.observable(true),
            creditCardVerificationNumber: '',
            selectedPaymentProfile: null,
            selectedCardType: null,
            selectedInstallments: null,
            creditCardInstallments: ko.observableArray([]),
            maxInstallments: 1,
            taxvat: taxvat
        },

        /**
         * Get payment data
         *
         * @return {Object}
         */
        getData: function () {
            let ccExpMonth = '';
            let ccExpYear = '';
            let ccExpDate = this.creditCardExpDate();

            if (typeof ccExpDate !== "undefined" && ccExpDate !== null) {
                let ccExpDateFull = ccExpDate.split('/');
                ccExpMonth = ccExpDateFull[0];
                ccExpYear = ccExpDateFull[1];
            }

            this.creditCardExpYear(ccExpYear);
            this.creditCardExpMonth(ccExpMonth);

            var data = {
                'method': this.getCode(),
                'additional_data': {
                    'payment_profile': this.selectedPaymentProfile(),
                    'cc_type': this.selectedCardType(),
                    'cc_exp_year': ccExpYear.length === 4 ? ccExpYear : '20' + ccExpYear,
                    'cc_exp_month': ccExpMonth,
                    'cc_number': this.creditCardNumber(),
                    'cc_owner': this.creditCardOwner(),
                    'cc_ss_start_month': this.creditCardSsStartMonth(),
                    'cc_ss_start_year': this.creditCardSsStartYear(),
                    'cc_cvv': this.creditCardVerificationNumber(),
                    'cc_installments': this.selectedInstallments() ? this.selectedInstallments() : 1,
                    'document': this?.taxvat?.value(),
                    'amount_credit': this.creditAmountDisplay(),
                    'amount_pix': this.pixAmountDisplay()
                }
            };

            return data;
        },

        /**
         * Initialize observables and computed properties
         *
         * @return {Component}
         */
        initObservable: function () {
            var self = this;

            this._super()
                .observe([
                    'creditCardType',
                    'creditCardExpDate',
                    'creditCardExpYear',
                    'creditCardExpMonth',
                    'creditCardNumber',
                    'vindiCreditCardNumber',
                    'creditCardOwner',
                    'creditCardVerificationNumber',
                    'creditCardSsStartMonth',
                    'creditCardSsStartYear',
                    'selectedCardType',
                    'selectedPaymentProfile',
                    'selectedInstallments',
                    'maxInstallments'
                ]);

            self.isInstallmentsDisabled = ko.observable(false);

            setCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
            });

            cancelCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
            });

            self.creditAmountManual = ko.observable();
            self.pixAmountManual = ko.observable();
            self.selectedManualMethod = ko.observable();

            var orderTotal = parseFloat(totals.getSegment('grand_total').value) || 0;
            self.orderTotal = orderTotal;
            self.formattedOrderTotal = ko.computed(function() {
                return priceUtils.formatPrice(self.orderTotal, window.checkoutConfig.currency);
            });

            self.creditAmountDisplay = ko.computed({
                read: function() {
                    if (self.selectedManualMethod() === 'credit' || !self.selectedManualMethod()) {
                        return self.creditAmountManual();
                    } else if (self.selectedManualMethod() === 'pix') {
                        var pix = parseFloat(self.pixAmountManual() || 0);
                        var remaining = orderTotal - pix;
                        return remaining.toFixed(2);
                    }
                },
                write: function(value) {
                    if (!value) {
                        self.selectedManualMethod(null);
                        self.creditAmountManual('');
                        self.pixAmountManual('');
                    } else {
                        if (self.selectedManualMethod() === 'credit' || !self.selectedManualMethod()) {
                            self.selectedManualMethod('credit');
                            self.creditAmountManual(value);
                        }
                    }
                }
            });

            self.pixAmountDisplay = ko.computed({
                read: function() {
                    if (self.selectedManualMethod() === 'pix' || !self.selectedManualMethod()) {
                        return self.pixAmountManual();
                    } else if (self.selectedManualMethod() === 'credit') {
                        var credit = parseFloat(self.creditAmountManual() || 0);
                        var remaining = orderTotal - credit;
                        return remaining.toFixed(2);
                    }
                },
                write: function(value) {
                    if (!value) {
                        self.selectedManualMethod(null);
                        self.creditAmountManual('');
                        self.pixAmountManual('');
                    } else {
                        if (self.selectedManualMethod() === 'pix' || !self.selectedManualMethod()) {
                            self.selectedManualMethod('pix');
                            self.pixAmountManual(value);
                        }
                    }
                }
            });

            self.isCreditEditable = ko.computed(function() {
                return self.selectedManualMethod() === 'credit' || !self.selectedManualMethod();
            });
            self.isPixEditable = ko.computed(function() {
                return self.selectedManualMethod() === 'pix' || !self.selectedManualMethod();
            });

            self.creditInvalid = ko.computed(function() {
                var credit = parseFloat(self.creditAmountManual() || 0);
                return credit > self.orderTotal;
            });

            self.pixInvalid = ko.computed(function() {
                var pix = parseFloat(self.pixAmountManual() || 0);
                return pix > self.orderTotal;
            });

            self.creditAmountManual.subscribe(function(newValue) {
                self.updateInstallments();
            });

            this.vindiCreditCardNumber.subscribe(function (value) {
                let result;
                self.selectedCardType(null);

                if (value === '' || value === null) {
                    return false;
                }

                result = cardNumberValidator(value);
                if (!result.isValid) {
                    return false;
                }

                if (result.card !== null) {
                    self.selectedCardType(result.card.type);
                    creditCardData.creditCard = result.card;
                }

                if (result.isValid) {
                    creditCardData.vindiCreditCardNumber = value;
                    self.creditCardNumber(value);
                    self.creditCardType(result.card.type);
                }
            });

            this.checkPlanInstallments();

            return this;
        },

        /**
         * Validate payment fields
         *
         * @return {Boolean}
         */
        validate: function () {
            var self = this;
            if (this.selectedPaymentProfile() == null || this.selectedPaymentProfile() == '') {
                if (!this.selectedCardType() || this.selectedCardType() == '') {
                    this.messageContainer.addErrorMessage({'message': $t('Please enter the Credit Card Type.')});
                    return false;
                }

                if (!this.creditCardExpDate() || this.creditCardExpDate() == '') {
                    this.messageContainer.addErrorMessage({'message': $t('Please enter the Credit Card Expiry Year.')});
                    return false;
                }

                if (!this.creditCardNumber() || this.creditCardNumber() == '') {
                    this.messageContainer.addErrorMessage({'message': $t('Please enter the Credit Card Number.')});
                    return false;
                }

                if (!this.creditCardOwner() || this.creditCardOwner() == '') {
                    this.messageContainer.addErrorMessage({'message': $t('Please enter the Credit Card Owner Name.')});
                    return false;
                }

                if (!this.creditCardVerificationNumber() || this.creditCardVerificationNumber() == '') {
                    this.messageContainer.addErrorMessage({'message': $t('Please enter the Credit Card CVV.')});
                    return false;
                }
            }

            var documentValue = this.taxvat.value();
            if (!documentValue || documentValue === '') {
                self.messageContainer.addErrorMessage({'message': ('CPF/CNPJ é obrigatório')});
                return false;
            }

            if (!documentValidate.isValidTaxvat(documentValue)) {
                self.messageContainer.addErrorMessage({'message': ('CPF/CNPJ não é válido')});
                return false;
            }

            if (this.installmentsAllowed()) {
                if (!this.selectedInstallments() || this.selectedInstallments() == '') {
                    this.messageContainer.addErrorMessage({'message': $t('Please enter the number of Installments.')});
                    return false;
                }
            } else {
                this.selectedInstallments(1);
            }

            var credit = parseFloat(self.creditAmountDisplay() || 0);
            var pix = parseFloat(self.pixAmountDisplay() || 0);

            if (credit > self.orderTotal) {
                self.messageContainer.addErrorMessage({'message': $t('The Credit Card amount cannot exceed the order total of ') + self.formattedOrderTotal()});
                return false;
            }

            if (pix > self.orderTotal) {
                self.messageContainer.addErrorMessage({'message': $t('O valor do PIX não pode exceder o total do pedido de ') + self.formattedOrderTotal()});
                return false;
            }

            if ((credit + pix).toFixed(2) != self.orderTotal.toFixed(2)) {
                self.messageContainer.addErrorMessage({'message': $t('The sum of Credit Card and PIX amounts must equal the total order amount.')});
                return false;
            }

            return true;
        },

        /**
         * Initialize component
         *
         * @return {Component}
         */
        initialize: function () {
            var self = this;
            this._super();

            this.taxvat.value(window?.checkoutConfig?.payment?.vindi_pix?.customer_taxvat);

            self.updateInstallments();

            this.creditCardNumber.subscribe(function (value) {
                var result;

                if (value == '' || value == null) {
                    return false;
                }

                result = cardNumberValidator(value);

                if (!result.isPotentiallyValid && !result.isValid) {
                    return false;
                }

                if (result.isValid) {
                    creditCardData.creditCardNumber = value;
                }
            });

            this.creditCardOwner.subscribe(function (value) {
                creditCardData.creditCardOwner = value;
            });

            this.creditCardExpYear.subscribe(function (value) {
                creditCardData.expirationYear = value;
            });

            this.creditCardExpMonth.subscribe(function (value) {
                creditCardData.expirationYear = value;
            });

            this.creditCardVerificationNumber.subscribe(function (value) {
                creditCardData.cvvCode = value;
            });
            this.selectedInstallments.subscribe(function (value) {
                creditCardData.selectedInstallments = value;
            });
        },

        /**
         * Get card icon for given type
         *
         * @param {String} type
         * @return {Object|Boolean}
         */
        getIcons: function (type) {
            return window.checkoutConfig.payment.vindi?.icons?.hasOwnProperty(type)
                ? window.checkoutConfig.payment.vindi.icons[type]
                : false;
        },

        /**
         * Load the credit card form
         */
        loadCard: function () {
            let ccName = document.getElementById(this.getCode() + '_cc_owner');
            let ccNumber = document.getElementById(this.getCode() + '_cc_number');
            let ccExpDate = document.getElementById(this.getCode() + '_cc_exp_date');
            let ccCvv = document.getElementById(this.getCode() + '_cc_cid');
            let ccSingle = document.getElementById('vindi-ccsingle');
            let ccFront = document.getElementById('vindi-front');
            let ccBack = document.getElementById('vindi-back');

            creditCardForm(ccName, ccNumber, ccExpDate, ccCvv, ccSingle, ccFront, ccBack);
        },

        /**
         * Check if component is active
         *
         * @return {Boolean}
         */
        isActive: function () {
            return true;
        },

        /**
         * Get available credit card types
         *
         * @return {Object}
         */
        getCcAvailableTypes: function () {
            return window.checkoutConfig.payment.vindi.availableTypes;
        },

        /**
         * Get credit card months
         *
         * @return {Object}
         */
        getCcMonths: function () {
            return window.checkoutConfig.payment.vindi.months['vindi'];
        },

        /**
         * Get credit card years
         *
         * @return {Object}
         */
        getCcYears: function () {
            return window.checkoutConfig.payment.vindi.years['vindi'];
        },

        /**
         * Check if credit card verification is required
         *
         * @return {Boolean}
         */
        hasVerification: function () {
            return window.checkoutConfig.payment.vindi.hasVerification['vindi'];
        },

        /**
         * Get available credit card types values
         *
         * @return {Array}
         */
        getCcAvailableTypesValues: function () {
            return _.map(this.getCcAvailableTypes(), function (value, key) {
                return {
                    'value': key,
                    'type': value
                };
            });
        },

        /**
         * Get credit card months values
         *
         * @return {Array}
         */
        getCcMonthsValues: function () {
            return _.map(this.getCcMonths(), function (value, key) {
                return {
                    'value': key,
                    'month': value
                };
            });
        },

        /**
         * Get credit card years values
         *
         * @return {Array}
         */
        getCcYearsValues: function () {
            return _.map(this.getCcYears(), function (value, key) {
                return {
                    'value': key,
                    'year': value
                };
            });
        },

        /**
         * Get credit card types values
         *
         * @return {Array}
         */
        getCcTypesValues: function () {
            return _.map(this.getCcAvailableTypes(), function (value, key) {
                return {
                    'value': key,
                    'name': value
                };
            });
        },

        /**
         * Check if installments are allowed
         *
         * @return {Boolean}
         */
        installmentsAllowed: function () {
            let isAllowed = parseInt(window.checkoutConfig.payment.vindi.isInstallmentsAllowedInStore);
            return isAllowed !== 0 ? true : false;
        },

        /**
         * Update available installments options based on the credit card amount.
         * The installments select is disabled during calculation and re-enabled after.
         *
         * @param {Number|null} maxInstallments
         */
        updateInstallments: function (maxInstallments = null) {
            let self = this;
            self.isInstallmentsDisabled(true);
            let installments = [];
            let creditValue = parseFloat(self.creditAmountDisplay() || 0);
            let ccCheckoutConfig = window.checkoutConfig.payment.vindi;
            if (ccCheckoutConfig) {
                let maxInstallmentsNumber = maxInstallments || ccCheckoutConfig.maxInstallments;
                let minInstallmentsValue = ccCheckoutConfig.minInstallmentsValue;
                let totalForInstallments = creditValue;
                if (maxInstallmentsNumber > 1 && self.installmentsAllowed()) {
                    let installmentsTimes = Math.floor(totalForInstallments / minInstallmentsValue);
                    for (let i = 1; i <= maxInstallmentsNumber; i++) {
                        let value = Math.ceil((totalForInstallments / i) * 100) / 100;
                        installments.push({
                            'value': i,
                            'text': `${i} de ${self.getFormattedPrice(value)}`
                        });
                        if (i + 1 > installmentsTimes) {
                            break;
                        }
                    }
                } else {
                    installments.push({
                        'value': 1,
                        'text': `1 de ${self.getFormattedPrice(totalForInstallments)}`
                    });
                }
            }
            self.creditCardInstallments(installments);
            self.isInstallmentsDisabled(false);
        },

        /**
         * Format price based on store configuration
         *
         * @param {Number} price
         * @return {String}
         */
        getFormattedPrice: function (price) {
            return priceUtils.formatPrice(price, quote.getPriceFormat());
        },

        /**
         * Get saved payment profiles
         *
         * @return {Array}
         */
        getPaymentProfiles: function () {
            let paymentProfiles = [];
            const savedCards = window.checkoutConfig.payment?.vindi?.saved_cards;

            if (savedCards) {
                savedCards.forEach(function (card) {
                    paymentProfiles.push({
                        'value': card.id,
                        'text': `${card.card_type.toUpperCase()} xxxx-${card.card_number}`
                    });
                });
            }

            return paymentProfiles;
        },

        /**
         * Check if there are saved payment profiles
         *
         * @return {Boolean}
         */
        hasPaymentProfiles: function () {
            return this.getPaymentProfiles().length > 0;
        },

        /**
         * Check plan installments via AJAX
         */
        checkPlanInstallments: function () {
            var self = this;
            $.ajax({
                url: self.getUrl('vindi_vr/plan/get'),
                type: 'GET',
                success: function (response) {
                    if (response && response.installments) {
                        self.maxInstallments(response.installments);
                        self.updateInstallments(response.installments);
                    } else {
                        self.updateInstallments();
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Error fetching plan installments:', error);
                    self.updateInstallments();
                }
            });
        },

        /**
         * Get URL for given path
         *
         * @param {String} path
         * @return {String}
         */
        getUrl: function (path) {
            var url = window.BASE_URL + path;
            return url;
        },

        /**
         * Get informational message for PIX payment
         *
         * @return {String}
         */
        getInfoMessage: function () {
            return window?.checkoutConfig?.payment?.vindi_pix?.info_message;
        },

        /**
         * Check if document field is active
         *
         * @return {Boolean}
         */
        isActiveDocument: function () {
            return window?.checkoutConfig?.payment?.vindi_pix?.enabledDocument;
        },

        /**
         * Check CPF input field on keyup event
         *
         * @param {Object} self
         * @param {Event} event
         */
        checkCpf: function (self, event) {
            this.formatTaxvat(event.target);
            const message = documentValidate.isValidTaxvat(this?.taxvat?.value()) ? '' : 'CPF/CNPJ inválido';
            $('#cpfResponse').text(message);
        },

        /**
         * Format document field using taxvat model
         *
         * @param {HTMLElement} target
         */
        formatTaxvat: function (target) {
            taxvat.formatDocument(target);
        }
    });
});
