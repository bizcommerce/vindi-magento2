<?php
// File: app/code/Vindi/Payment/Model/Payment/CardCard.php

namespace Vindi\Payment\Model\Payment;

use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\PaymentInterface;
use Vindi\Payment\Block\Info\CardCard as InfoBlock;
use Vindi\Payment\Model\Payment\PaymentMethod;

/**
 * Class CardCard
 *
 * @package Vindi\Payment\Model\Payment
 */
class CardCard extends AbstractMethod
{
    const CODE = 'vindi_cardcard';

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isOffline = false;

    /**
     * @var string
     */
    protected $_infoBlockType = InfoBlock::class;

    /**
     * @var bool
     */
    protected $_isGateway = true;

    /**
     * @var bool
     */
    protected $_canAuthorize = true;

    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * @var bool
     */
    protected $_canCapturePartial = true;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canVoid = true;

    /**
     * @var bool
     */
    protected $_canUseInternal = true;

    /**
     * @var bool
     */
    protected $_canUseCheckout = true;

    /**
     * @var bool
     */
    protected $_canUseForMultishipping = true;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = false;

    /**
     * @var bool
     */
    protected $_canSaveCc = true;

    /**
     * Assign data to the payment method.
     *
     * @param DataObject $data
     * @return CardCard
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(DataObject $data)
    {
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_object($additionalData)) {
            $additionalData = new DataObject($additionalData ?: []);
        }

        $info = $this->getInfoInstance();

        // First Credit Card Data (without suffix)
        $ccType = $additionalData->getCcType1();
        $ccOwner = $additionalData->getCcOwner1();
        $ccNumber = (string)$additionalData->getCcNumber1();
        $ccLast4 = substr($ccNumber, -4);

        $info->setAdditionalInformation('cc_type', $ccType);
        $info->setAdditionalInformation('cc_owner', $ccOwner);
        $info->setAdditionalInformation('cc_last_4', $ccLast4);
        $info->setAdditionalInformation('cc_number', $ccNumber);
        $info->setAdditionalInformation('cc_cvv', (string)$additionalData->getCcCvv1());
        $info->setAdditionalInformation('cc_exp_month', (string)$additionalData->getCcExpMonth1());
        $info->setAdditionalInformation('cc_exp_year', (string)$additionalData->getCcExpYear1());
        $info->setAdditionalInformation('cc_installments1', (string)$additionalData->getCcInstallments1());

        // Second Credit Card Data
        $ccType2 = $additionalData->getCcType2();
        $ccOwner2 = $additionalData->getCcOwner2();
        $ccNumber2 = (string)$additionalData->getCcNumber2();
        $ccLast4_2 = substr($ccNumber2, -4);

        $info->setAdditionalInformation('cc_type2', $ccType2);
        $info->setAdditionalInformation('cc_owner2', $ccOwner2);
        $info->setAdditionalInformation('cc_last_4_2', $ccLast4_2);
        $info->setAdditionalInformation('cc_number2', $ccNumber2);
        $info->setAdditionalInformation('cc_cvv2', (string)$additionalData->getCcCvv2());
        $info->setAdditionalInformation('cc_exp_month2', (string)$additionalData->getCcExpMonth2());
        $info->setAdditionalInformation('cc_exp_year2', (string)$additionalData->getCcExpYear2());
        $info->setAdditionalInformation('cc_installments2', (string)$additionalData->getCcInstallments2());

        // Set amounts for each card
        $info->setAdditionalInformation('amount_credit', $additionalData->getAmountCredit());
        $info->setAdditionalInformation('amount_second_card', $additionalData->getAmountSecondCard());

        $info->setAdditionalInformation('payment_profile', $additionalData["payment_profile"]);
        $info->setAdditionalInformation('payment_profile2', $additionalData["payment_profile2"]);

        $info->save();

        parent::assignData($data);

        return $this;
    }

    /**
     * Get payment method code.
     *
     * @return string
     */
    protected function getPaymentMethodCode()
    {
        return PaymentMethod::CARD_CARD;
    }
}
