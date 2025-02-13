<?php

namespace Vindi\Payment\Model\Payment;

use Magento\Framework\DataObject;
use Vindi\Payment\Block\Info\CardPix as InfoBlock;

/**
 * Class CardPix
 *
 * @package Vindi\Payment\Model\Payment
 */
class CardPix extends AbstractMethod
{
    const CODE = 'vindi_cardpix';

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
     * Assign data to the payment method
     *
     * @param DataObject $data
     * @return CardPix
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(DataObject $data)
    {
        $info = $this->getInfoInstance();
        $info->setAdditionalInformation('installments', $data->getAdditionalData('installments'));
        $info->setAdditionalInformation('pix_code', $data->getAdditionalData('pix_code'));
        $info->save();

        parent::assignData($data);

        return $this;
    }

    /**
     * @return string
     */
    protected function getPaymentMethodCode()
    {
        return PaymentMethod::CARD_PIX;
    }
}
