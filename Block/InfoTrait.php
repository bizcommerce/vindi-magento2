<?php

namespace Vindi\Payment\Block;

trait InfoTrait
{
    /**
     * Check if credit card information can be shown
     *
     * @return bool
     */
    public function canShowCcInfo()
    {
        $method = $this->getOrder()->getPayment()->getMethod();
        return $method === 'vindi' ||
            $method === 'vindi_cardpix' ||
            $method === 'vindi_cardcard' ||
            $method === 'vindi_cardbankslippix';
    }

    /**
     * Get credit card owner
     *
     * @return string|null
     */
    public function getCcOwner()
    {
        $payment = $this->getOrder()->getPayment();
        return $payment->getData('cc_owner') ?: $payment->getAdditionalInformation('cc_owner');
    }

    /**
     * Get number of credit card installments
     *
     * @return string|null
     */
    public function getCcInstallments()
    {
        $payment = $this->getOrder()->getPayment();
        return $payment->getData('cc_installments') ?: $payment->getAdditionalInformation('installments');
    }

    /**
     * Get last four digits of credit card
     *
     * @return string|null
     */
    public function getCcNumber()
    {
        $payment = $this->getOrder()->getPayment();
        return $payment->getData('cc_last_4') ?: $payment->getAdditionalInformation('cc_last_4');
    }

    /**
     * Get credit card value
     *
     * @param int $totalQtyCard
     * @param int $cardPosition
     * @return string
     */
    public function getCcValue($totalQtyCard = 1, $cardPosition = 1)
    {
        return $this->currency->currency(
            $this->getOrder()->getPayment()->getAdditionalInformation(
                'cc_value_' . $totalQtyCard . '_' . $cardPosition
            ),
            true,
            false
        );
    }

    /**
     * Get credit card brand
     *
     * @return string|null
     */
    public function getCcBrand()
    {
        $payment = $this->getOrder()->getPayment();
        $brands = $this->paymentMethod->getCreditCardCodes();
        $ccType = $payment->getData('cc_type') ?: $payment->getAdditionalInformation('cc_type');
        return isset($brands[$ccType]) ? $brands[$ccType] : null;
    }
}
