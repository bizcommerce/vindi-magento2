<?php
namespace Vindi\Payment\Model\Payment;

use Magento\Framework\Exception\LocalizedException;
use Vindi\Payment\Helper\Data;
use Vindi\Payment\Model\Payment\PaymentMethod;

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
     * @param mixed  $payment
     * @param int    $customerId
     * @param string $paymentMethodCode
     * @param string $whichCard
     * @return mixed
     * @throws LocalizedException
     */
    public function create($payment, $customerId, $paymentMethodCode, $whichCard = 'first')
    {
        $creditCardData = $this->buildCreditCardData($payment, $customerId, $paymentMethodCode, $whichCard);
        $paymentProfile = $this->createPaymentProfile($creditCardData);

        if ($paymentProfile === false) {
            throw new LocalizedException(__('Error while informing credit card data. Verify data and try again'));
        }

        $verifyMethod = $this->helperData->getShouldVerifyProfile();
        if ($verifyMethod && !$this->verifyPaymentProfile($paymentProfile['payment_profile']['id'])) {
            throw new LocalizedException(__('Impossible to validate your credit card'));
        }

        return $paymentProfile;
    }

    /**
     * Create a payment profile from customer account.
     *
     * @param mixed  $payment
     * @param int    $customerId
     * @param string $paymentMethodCode
     * @return bool|mixed
     * @throws LocalizedException
     */
    public function createFromCustomerAccount($payment, $customerId, $paymentMethodCode)
    {
        $payment['customer_id'] = $customerId;
        $paymentProfile = $this->createPaymentProfileFromCustomerAccount($payment);

        if ($paymentProfile === false) {
            throw new LocalizedException(__('Error while informing credit card data. Verify data and try again'));
        }

        $verifyMethod = $this->helperData->getShouldVerifyProfile();
        if ($verifyMethod && !$this->verifyPaymentProfile($paymentProfile['payment_profile']['id'])) {
            throw new LocalizedException(__('Impossible to validate your credit card'));
        }

        return $paymentProfile;
    }

    /**
     * Extract and build the credit card data array.
     *
     * @param mixed  $payment
     * @param int    $customerId
     * @param string $paymentMethodCode
     * @param string $whichCard
     * @return array
     * @throws LocalizedException
     */
    private function buildCreditCardData($payment, $customerId, $paymentMethodCode, $whichCard)
    {
        if (empty($customerId)) {
            throw new LocalizedException(__('customer_id cannot be blank'));
        }

        $holder  = $whichCard === 'second'
            ? ($payment->getAdditionalInformation('cc_owner2')      ?: $payment->getCcOwner())
            : ($payment->getAdditionalInformation('cc_owner')       ?: $payment->getCcOwner());

        $month   = $whichCard === 'second'
            ? ($payment->getAdditionalInformation('cc_exp_month2')  ?: $payment->getCcExpMonth())
            : ($payment->getAdditionalInformation('cc_exp_month')   ?: $payment->getCcExpMonth());

        $year    = $whichCard === 'second'
            ? ($payment->getAdditionalInformation('cc_exp_year2')   ?: $payment->getCcExpYear())
            : ($payment->getAdditionalInformation('cc_exp_year')    ?: $payment->getCcExpYear());

        $number  = $whichCard === 'second'
            ? ($payment->getAdditionalInformation('cc_number2')     ?: $payment->getCcNumber())
            : ($payment->getAdditionalInformation('cc_number')      ?: $payment->getCcNumber());

        $cvv     = $whichCard === 'second'
            ? ($payment->getAdditionalInformation('cc_cvv2')        ?: $payment->getCcCid())
            : ($payment->getAdditionalInformation('cc_cvv')         ?: $payment->getCcCid());

        $ccType  = $whichCard === 'second'
            ? ($payment->getAdditionalInformation('cc_type2')       ?: $payment->getCcType())
            : ($payment->getAdditionalInformation('cc_type')        ?: $payment->getCcType());

        if (empty($holder)) {
            throw new LocalizedException(__('holder_name cannot be blank'));
        }

        $methodCode = $paymentMethodCode;
        if ($this->helperData->isMultiMethod($methodCode)) {
            $methodCode = PaymentMethod::CREDIT_CARD;
        }

        $ccTypeCode = $this->paymentMethod->getCreditCardApiCode($ccType);

        return [
            'holder_name'          => $holder,
            'card_expiration'      => str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '/' . $year,
            'card_number'          => $number,
            'card_cvv'             => $cvv,
            'customer_id'          => $customerId,
            'payment_company_code' => $ccTypeCode,
            'payment_method_code'  => $methodCode
        ];
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
        $dataToLog['card_number'] = $cardNumber !== '' ? '**** *' . substr($cardNumber, -3) : '';
        $dataToLog['card_cvv'] = '***';

        return $this->api->request('payment_profiles', 'POST', $body, $dataToLog);
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
        $dataToLog['card_number'] = $cardNumber !== '' ? '**** *' . substr($cardNumber, -3) : '';
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
     * @param int   $paymentProfileId
     * @param array $dataToUpdate
     * @return bool|mixed
     */
    public function updatePaymentProfile($paymentProfileId, $dataToUpdate)
    {
        $body = [
            'body'              => $dataToUpdate,
            'allow_as_fallback' => true
        ];

        return $this->api->request('payment_profiles/' . $paymentProfileId, 'PUT', $body);
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
        $query = "customer_id={$customerId} card_number_first_six={$firstSix}"
            . " card_number_last_four={$lastFour} status=active";
        return $this->api->request(
            'payment_profiles/?query=' . urlencode($query) . '&sort_order=desc',
            'GET'
        );
    }
}
