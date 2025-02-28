<?php
// File: app/code/Vindi/Payment/Model/Payment/Profile.php

namespace Vindi\Payment\Model\Payment;

use Vindi\Payment\Helper\Data;

class Profile
{
    private $api;

    private $helperData;

    private $paymentMethod;

    public function __construct(
        \Vindi\Payment\Helper\Api $api,
        Data $helperData,
        PaymentMethod $paymentMethod
    ) {
        $this->api = $api;
        $this->helperData = $helperData;
        $this->paymentMethod = $paymentMethod;
    }

    /**
     * Create a payment profile from card data.
     *
     * @param mixed $payment
     * @param int $customerId
     * @param string $paymentMethodCode
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function create($payment, $customerId, $paymentMethodCode)
    {
        $ccType = $payment->getCcType() ?? '';
        $ccTypeCode = $this->paymentMethod->getCreditCardApiCode($ccType);
        $creditCardData = [
            'holder_name' => $payment->getCcOwner(),
            'card_expiration' => str_pad((string)$payment->getCcExpMonth(), 2, '0', STR_PAD_LEFT)
                . '/' . $payment->getCcExpYear(),
            'card_number' => $payment->getCcNumber(),
            'card_cvv' => $payment->getCcCid() ?: '',
            'customer_id' => $customerId,
            'payment_company_code' => $ccTypeCode,
            'payment_method_code' => $paymentMethodCode
        ];

        $paymentProfile = $this->createPaymentProfile($creditCardData);

        if ($paymentProfile === false) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Error while informing credit card data. Verify data and try again'));
        }

        $verifyMethod = $this->helperData->getShouldVerifyProfile();

        if ($verifyMethod && !$this->verifyPaymentProfile($paymentProfile['payment_profile']['id'])) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Impossible to validate your credit card'));
        }
        return $paymentProfile;
    }

    /**
     * Create a payment profile using provided credit card data.
     *
     * @param array $body
     * @return bool|mixed
     */
    private function createPaymentProfile($body)
    {
        $dataToLog = $body;
        $cardNumber = $dataToLog['card_number'] ?? '';
        $dataToLog['card_number'] = ($cardNumber !== '') ? '**** *' . substr($cardNumber, -3) : '';
        $dataToLog['card_cvv'] = '***';

        return $this->api->request('payment_profiles', 'POST', $body, $dataToLog);
    }

    /**
     * Create a payment profile from customer account.
     *
     * @param mixed $payment
     * @param int $customerId
     * @param string $paymentMethodCode
     * @return bool|mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function createFromCustomerAccount($payment, $customerId, $paymentMethodCode)
    {
        $payment['customer_id'] = $customerId;
        $paymentProfile = $this->createPaymentProfileFromCustomerAccount($payment);

        if ($paymentProfile === false) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Error while informing credit card data. Verify data and try again'));
        }

        $verifyMethod = $this->helperData->getShouldVerifyProfile();

        if ($verifyMethod && !$this->verifyPaymentProfile($paymentProfile['payment_profile']['id'])) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Impossible to validate your credit card'));
        }
        return $paymentProfile;
    }

    /**
     * Create a payment profile using provided credit card data from customer account.
     *
     * @param array $body
     * @return bool|mixed
     */
    private function createPaymentProfileFromCustomerAccount($body)
    {
        $dataToLog = $body;
        $cardNumber = $dataToLog['card_number'] ?? '';
        $dataToLog['card_number'] = ($cardNumber !== '') ? '**** *' . substr($cardNumber, -3) : '';
        $dataToLog['card_cvv'] = '***';
        $body['allow_as_fallback'] = true;

        return $this->api->request('payment_profiles', 'POST', $body, $dataToLog);
    }

    /**
     * Verify the payment profile.
     *
     * @param int $paymentProfileId
     * @return bool
     */
    public function verifyPaymentProfile($paymentProfileId)
    {
        $verify_status = $this->api->request('payment_profiles/' . $paymentProfileId . '/verify', 'POST');
        return ($verify_status['transaction']['status'] === 'success');
    }

    /**
     * Update a payment profile.
     *
     * @param int $paymentProfileId
     * @param array $dataToUpdate
     * @return bool|mixed
     */
    public function updatePaymentProfile($paymentProfileId, $dataToUpdate)
    {
        $body = [
            "body" => $dataToUpdate,
            "allow_as_fallback" => true
        ];

        $updateStatus = $this->api->request('payment_profiles/' . $paymentProfileId, 'PUT', $body);
        return $updateStatus;
    }

    /**
     * Delete a payment profile.
     *
     * @param int $paymentProfileId
     * @return bool|mixed
     */
    public function deletePaymentProfile($paymentProfileId)
    {
        return $this->api->request('payment_profiles/' . $paymentProfileId, 'DELETE');
    }

    /**
     * Get a payment profile.
     *
     * @param int $customerId
     * @param int $firstSix
     * @param int $lastFour
     * @return bool|mixed
     */
    public function getPaymentProfile($customerId, $firstSix, $lastFour)
    {
        $query = "customer_id={$customerId} card_number_first_six={$firstSix} card_number_last_four={$lastFour} status=active";
        return $this->api->request('payment_profiles/?query=' . urlencode($query) . '&sort_order=desc', 'GET');
    }
}
