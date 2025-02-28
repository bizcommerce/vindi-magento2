/* File: app/code/Vindi/Payment/view/frontend/web/js/view/payment/vindi-cardcard.js */
/*jshint browser:true jquery:true*/
/*eslint-disable no-alert, no-unused-vars*/
/*browser:true*/
/*global define*/
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
            template: 'Vindi_Payment/payment/vindi-cardcard',
            paymentProfiles: [],
            creditCardType: '',
            creditCardExpYear: '',
            creditCardExpMonth: '',
            creditCardNumber: '',
            vindiCreditCardNumber: '',
            creditCardOwner: '',
            creditCardExpDate: '',
            creditCardVerificationNumber: '',
            selectedPaymentProfile: null,
            selectedCardType: null,
            selectedInstallments: null,
            creditCardInstallments: ko.observableArray([]),
            maxInstallments: 1,

            creditCardType2: '',
            creditCardExpYear2: '',
            creditCardExpMonth2: '',
            creditCardNumber2: '',
            vindiCreditCardNumber2: '',
            creditCardOwner2: '',
            creditCardExpDate2: '',
            creditCardVerificationNumber2: '',
            selectedPaymentProfile2: null,
            selectedCardType2: null,
            selectedInstallments2: null,
            creditCardInstallments2: ko.observableArray([]),
            maxInstallments2: 1,

            taxvat: taxvat
        },

        /**
         * Return payment data
         * @return {Object}
         */
        getData: function () {
            let ccExpMonth = '';
            let ccExpYear = '';
            let ccExpDate = this.creditCardExpDate();

            if (ccExpDate && ccExpDate.split('/').length >= 2) {
                let ccExpDateFull = ccExpDate.split('/');
                ccExpMonth = ccExpDateFull[0] || '';
                ccExpYear = ccExpDateFull[1] || '';
            }

            this.creditCardExpYear(ccExpYear);
            this.creditCardExpMonth(ccExpMonth);

            let ccExpMonth2 = '';
            let ccExpYear2 = '';
            let ccExpDate2 = this.creditCardExpDate2();

            if (ccExpDate2 && ccExpDate2.split('/').length >= 2) {
                let ccExpDateFull2 = ccExpDate2.split('/');
                ccExpMonth2 = ccExpDateFull2[0] || '';
                ccExpYear2 = ccExpDateFull2[1] || '';
            }

            this.creditCardExpYear2(ccExpYear2);
            this.creditCardExpMonth2(ccExpMonth2);

            return {
                'method': this.getCode(),
                'additional_data': {
                    'payment_profile': this.selectedPaymentProfile(),
                    'cc_type1': this.selectedCardType(),
                    'cc_exp_year1': (ccExpYear && ccExpYear.length === 4 ? ccExpYear : (ccExpYear ? '20' + ccExpYear : '')),
                    'cc_exp_month1': ccExpMonth,
                    'cc_number1': this.creditCardNumber(),
                    'cc_owner1': this.creditCardOwner(),
                    'cc_cvv1': this.creditCardVerificationNumber(),
                    'cc_installments1': this.selectedInstallments() ? this.selectedInstallments() : 1,

                    'payment_profile2': this.selectedPaymentProfile2(),
                    'cc_type2': this.selectedCardType2(),
                    'cc_exp_year2': (ccExpYear2 && ccExpYear2.length === 4 ? ccExpYear2 : (ccExpYear2 ? '20' + ccExpYear2 : '')),
                    'cc_exp_month2': ccExpMonth2,
                    'cc_number2': this.creditCardNumber2(),
                    'cc_owner2': this.creditCardOwner2(),
                    'cc_cvv2': this.creditCardVerificationNumber2(),
                    'cc_installments2': this.selectedInstallments2() ? this.selectedInstallments2() : 1,

                    'document': this?.taxvat?.value(),
                    'amount_credit': this.creditAmountDisplay(),
                    'amount_second_card': this.secondCardAmountDisplay()
                }
            };
        },

        /**
         * Initialize observables
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
                    'selectedCardType',
                    'selectedPaymentProfile',
                    'selectedInstallments',
                    'maxInstallments',

                    'creditCardType2',
                    'creditCardExpDate2',
                    'creditCardExpYear2',
                    'creditCardExpMonth2',
                    'creditCardNumber2',
                    'vindiCreditCardNumber2',
                    'creditCardOwner2',
                    'creditCardVerificationNumber2',
                    'selectedCardType2',
                    'selectedPaymentProfile2',
                    'selectedInstallments2',
                    'maxInstallments2'
                ]);

            self.isInstallmentsDisabled = ko.observable(false);
            self.isInstallmentsDisabled2 = ko.observable(false);

            setCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
                self.updateInstallments2();
            });

            cancelCouponCodeAction.registerSuccessCallback(function () {
                self.updateInstallments();
                self.updateInstallments2();
            });

            self.creditAmountManual = ko.observable();
            self.secondCardAmountManual = ko.observable();
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
                    } else if (self.selectedManualMethod() === 'secondCard') {
                        var secondCard = parseFloat(self.secondCardAmountManual() || 0);
                        var remaining = orderTotal - secondCard;
                        return remaining.toFixed(2);
                    }
                },
                write: function(value) {
                    if (!value) {
                        self.selectedManualMethod(null);
                        self.creditAmountManual('');
                        self.secondCardAmountManual('');
                    } else {
                        if (self.selectedManualMethod() === 'credit' || !self.selectedManualMethod()) {
                            self.selectedManualMethod('credit');
                            self.creditAmountManual(value);
                        }
                    }
                }
            });

            self.secondCardAmountDisplay = ko.computed({
                read: function() {
                    if (self.selectedManualMethod() === 'secondCard' || !self.selectedManualMethod()) {
                        return self.secondCardAmountManual();
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
                        self.secondCardAmountManual('');
                    } else {
                        if (self.selectedManualMethod() === 'secondCard' || !self.selectedManualMethod()) {
                            self.selectedManualMethod('secondCard');
                            self.secondCardAmountManual(value);
                        }
                    }
                }
            });

            self.isCreditEditable = ko.computed(function() {
                return self.selectedManualMethod() === 'credit' || !self.selectedManualMethod();
            });
            self.isSecondCardEditable = ko.computed(function() {
                return self.selectedManualMethod() === 'secondCard' || !self.selectedManualMethod();
            });

            self.creditInvalid = ko.computed(function() {
                var credit = parseFloat(self.creditAmountManual() || 0);
                return credit > self.orderTotal;
            });

            self.secondCardInvalid = ko.computed(function() {
                var secondCard = parseFloat(self.secondCardAmountManual() || 0);
                return secondCard > self.orderTotal;
            });

            self.creditAmountManual.subscribe(function() {
                self.updateInstallments();
            });

            self.secondCardAmountManual.subscribe(function() {
                self.updateInstallments2();
            });

            self.creditAmountDisplay.subscribe(function() {
                self.updateInstallments();
            });

            self.secondCardAmountDisplay.subscribe(function() {
                self.updateInstallments2();
            });

            this.vindiCreditCardNumber.subscribe(function (value) {
                let result;
                self.selectedCardType(null);
                if (!value) {
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

            this.vindiCreditCardNumber2.subscribe(function (value) {
                let result;
                self.selectedCardType2(null);
                if (!value) {
                    return false;
                }
                result = cardNumberValidator(value);
                if (!result.isValid) {
                    return false;
                }
                if (result.card !== null) {
                    self.selectedCardType2(result.card.type);
                }
                if (result.isValid) {
                    self.creditCardNumber2(value);
                    self.creditCardType2(result.card.type);
                }
            });

            this.checkPlanInstallments();

            return this;
        },

        /**
         * Validate fields
         * @return {Boolean}
         */
        validate: function () {
            var self = this;

            var documentValue = this.taxvat.value();
            if (!documentValue || documentValue === '') {
                self.messageContainer.addErrorMessage({'message': ('CPF/CNPJ é obrigatório')});
                return false;
            }
            if (!documentValidate.isValidTaxvat(documentValue)) {
                self.messageContainer.addErrorMessage({'message': ('CPF/CNPJ não é válido')});
                return false;
            }

            var credit = parseFloat(self.creditAmountDisplay() || 0);
            var secondCard = parseFloat(self.secondCardAmountDisplay() || 0);

            if (credit > self.orderTotal) {
                self.messageContainer.addErrorMessage({
                    'message': $t('The amount for the First Card cannot exceed the order total of ') + self.formattedOrderTotal()
                });
                return false;
            }
            if (secondCard > self.orderTotal) {
                self.messageContainer.addErrorMessage({
                    'message': $t('The amount for the Second Card cannot exceed the order total of ') + self.formattedOrderTotal()
                });
                return false;
            }
            if ((credit + secondCard).toFixed(2) !== self.orderTotal.toFixed(2)) {
                self.messageContainer.addErrorMessage({
                    'message': $t('The sum of the First Card and Second Card amounts must equal the total order amount.')
                });
                return false;
            }

            return true;
        },

        /**
         * Initialize
         * @return {Component}
         */
        initialize: function () {
            var self = this;
            this._super();

            this.taxvat.value(window?.checkoutConfig?.payment?.vindi_cardcard?.customer_taxvat);

            self.updateInstallments();
            self.updateInstallments2();

            this.creditCardNumber.subscribe(function (value) {
                var result;
                if (!value) {
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
                creditCardData.expirationMonth = value;
            });

            this.creditCardVerificationNumber.subscribe(function (value) {
                creditCardData.cvvCode = value;
            });

            this.selectedInstallments.subscribe(function (value) {
                creditCardData.selectedInstallments = value;
            });
        },

        /**
         * Return card icons
         * @param {String} type
         * @return {Object|Boolean}
         */
        getIcons: function (type) {
            return window.checkoutConfig.payment.vindi?.icons?.hasOwnProperty(type)
                ? window.checkoutConfig.payment.vindi.icons[type]
                : false;
        },

        /**
         * Load the first card
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
         * Load the second card
         */
        loadCardSecond: function () {
            let ccName2 = document.getElementById(this.getCode() + '_cc_owner2');
            let ccNumber2 = document.getElementById(this.getCode() + '_cc_number2');
            let ccExpDate2 = document.getElementById(this.getCode() + '_cc_exp_date2');
            let ccCvv2 = document.getElementById(this.getCode() + '_cc_cid2');
            let ccSingle2 = document.getElementById('vindi-ccsingle-second');
            let ccFront2 = document.getElementById('vindi-front-second');
            let ccBack2 = document.getElementById('vindi-back-second');

            creditCardForm(ccName2, ccNumber2, ccExpDate2, ccCvv2, ccSingle2, ccFront2, ccBack2);
        },

        /**
         * Check if active
         * @return {Boolean}
         */
        isActive: function () {
            return true;
        },

        /**
         * Return available card types
         * @return {Object}
         */
        getCcAvailableTypes: function () {
            return window.checkoutConfig.payment.vindi.availableTypes;
        },

        /**
         * Return months
         * @return {Object}
         */
        getCcMonths: function () {
            return window.checkoutConfig.payment.vindi.months['vindi'];
        },

        /**
         * Return years
         * @return {Object}
         */
        getCcYears: function () {
            return window.checkoutConfig.payment.vindi.years['vindi'];
        },

        /**
         * Check if CVV is required
         * @return {Boolean}
         */
        hasVerification: function () {
            return window.checkoutConfig.payment.vindi.hasVerification['vindi'];
        },

        /**
         * Return card type values
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
         * Check if installments are allowed
         * @return {Boolean}
         */
        installmentsAllowed: function () {
            let isAllowed = parseInt(window.checkoutConfig.payment.vindi.isInstallmentsAllowedInStore);
            return isAllowed !== 0;
        },

        /**
         * Update installments for first card
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
                            'text': i + ' de ' + self.getFormattedPrice(value)
                        });
                        if (i + 1 > installmentsTimes) {
                            break;
                        }
                    }
                } else {
                    installments.push({
                        'value': 1,
                        'text': '1 de ' + self.getFormattedPrice(totalForInstallments)
                    });
                }
            }
            self.creditCardInstallments(installments);
            self.isInstallmentsDisabled(false);
        },

        /**
         * Update installments for second card
         */
        updateInstallments2: function (maxInstallments = null) {
            let self = this;
            self.isInstallmentsDisabled2(true);
            let installments = [];
            let secondCardValue = parseFloat(self.secondCardAmountDisplay() || 0);
            let ccCheckoutConfig = window.checkoutConfig.payment.vindi;
            if (ccCheckoutConfig) {
                let maxInstallmentsNumber = maxInstallments || ccCheckoutConfig.maxInstallments;
                let minInstallmentsValue = ccCheckoutConfig.minInstallmentsValue;
                let totalForInstallments = secondCardValue;
                if (maxInstallmentsNumber > 1 && self.installmentsAllowed()) {
                    let installmentsTimes = Math.floor(totalForInstallments / minInstallmentsValue);
                    for (let i = 1; i <= maxInstallmentsNumber; i++) {
                        let value = Math.ceil((totalForInstallments / i) * 100) / 100;
                        installments.push({
                            'value': i,
                            'text': i + ' de ' + self.getFormattedPrice(value)
                        });
                        if (i + 1 > installmentsTimes) {
                            break;
                        }
                    }
                } else {
                    installments.push({
                        'value': 1,
                        'text': '1 de ' + self.getFormattedPrice(totalForInstallments)
                    });
                }
            }
            self.creditCardInstallments2(installments);
            self.isInstallmentsDisabled2(false);
        },

        /**
         * Return formatted price
         * @param {Number} price
         * @return {String}
         */
        getFormattedPrice: function (price) {
            return priceUtils.formatPrice(price, quote.getPriceFormat());
        },

        /**
         * Return payment profiles
         * @return {Array}
         */
        getPaymentProfiles: function () {
            let paymentProfiles = [];
            const savedCards = window.checkoutConfig.payment?.vindi?.saved_cards;
            if (savedCards) {
                savedCards.forEach(function (card) {
                    paymentProfiles.push({
                        'value': card.id,
                        'text': card.card_type.toUpperCase() + ' xxxx-' + card.card_number
                    });
                });
            }
            return paymentProfiles;
        },

        /**
         * Check if there are saved payment profiles for the first card
         * @return {Boolean}
         */
        hasPaymentProfiles: function () {
            return this.getPaymentProfiles().length > 0;
        },

        /**
         * Check if there are saved payment profiles for the second card
         * @return {Boolean}
         */
        hasPaymentProfiles2: function () {
            return this.getPaymentProfiles().length > 0;
        },

        /**
         * Ajax check plan installments
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
                        self.maxInstallments2(response.installments);
                        self.updateInstallments2(response.installments);
                    } else {
                        self.updateInstallments();
                        self.updateInstallments2();
                    }
                },
                error: function () {
                    self.updateInstallments();
                    self.updateInstallments2();
                }
            });
        },

        /**
         * Return URL
         * @param {String} path
         * @return {String}
         */
        getUrl: function (path) {
            return window.BASE_URL + path;
        },

        /**
         * Check if document field is active
         * @return {Boolean}
         */
        isActiveDocument: function () {
            return window?.checkoutConfig?.payment?.vindi_cardcard?.enabledDocument;
        },

        /**
         * Check CPF
         */
        checkCpf: function (self, event) {
            this.formatTaxvat(event.target);
            const message = documentValidate.isValidTaxvat(this?.taxvat?.value()) ? '' : 'CPF/CNPJ inválido';
            $('#cpfResponse').text(message);
        },

        /**
         * Format taxvat
         * @param {HTMLElement} target
         */
        formatTaxvat: function (target) {
            taxvat.formatDocument(target);
        }
    });
});
