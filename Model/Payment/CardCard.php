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

        $info->addData([
            'cc_type'           => (string)$additionalData->getData("cc_type1"),
            'cc_owner'          => (string)$additionalData->getData("cc_owner1"),
            'cc_last_4'         => substr((string)$additionalData->getData("cc_number1"), -4),
            'cc_number'         => (string)$additionalData->getData("cc_number1"),
            'cc_cvv'            => (string)$additionalData->getData("cc_cvv1"),
            'cc_exp_month'      => (string)$additionalData->getData("cc_exp_month1"),
            'cc_exp_year'       => (string)$additionalData->getData("cc_exp_year1"),
            'cc_installments1'  => (string)$additionalData->getData("cc_installments1"),
            'cc_type2'          => (string)$additionalData->getData("cc_type2"),
            'cc_owner2'         => (string)$additionalData->getData("cc_owner2"),
            'cc_last_4_2'       => substr((string)$additionalData->getData("cc_number2"), -4),
            'cc_number2'        => (string)$additionalData->getData("cc_number2"),
            'cc_cvv2'           => (string)$additionalData->getData("cc_cvv2"),
            'cc_exp_month2'     => (string)$additionalData->getData("cc_exp_month2"),
            'cc_exp_year2'      => (string)$additionalData->getData("cc_exp_year2"),
            'cc_installments2'  => (string)$additionalData->getData("cc_installments2")
        ]);

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
