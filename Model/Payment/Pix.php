<?php

namespace Vindi\Payment\Model\Payment;


use Magento\Framework\DataObject;
use Vindi\Payment\Block\Info\Pix as InfoBlock;

/**
 * Class Pix
 *
 * @package Vindi\Payment\Model\Payment
 */
class Pix extends AbstractMethod
{

    const CODE = 'vindi_pix';

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isOffline = true;

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
    protected $_canCapturePartial = false;

    /**
     * @var bool
     */
    protected $_canRefund = false;

    /**
     * @var bool
     */
    protected $_canVoid = false;

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
    protected $_canUseForMultishipping = false;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = false;

    /**
     * @var bool
     */
    protected $_canSaveCc = false;

    /**
     * @param mixed $data
     *
     * @return \Vindi\Payment\Model\Payment\Pix
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(DataObject $data)
    {
        parent::assignData($data);

        $info = $this->getInfoInstance();
        
        // Ensure additional_information is array
        $additionalInfo = $info->getAdditionalInformation();
        if (!is_array($additionalInfo)) {
            $additionalInfo = [];
        }
        
        $additionalInfo['installments'] = 1;
        $info->setAdditionalInformation($additionalInfo);

        return $this;
    }

    /**
     * @return string
     */
    protected function getPaymentMethodCode()
    {
        return PaymentMethod::PIX;
    }
}
