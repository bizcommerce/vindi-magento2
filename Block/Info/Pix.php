<?php

namespace Vindi\Payment\Block\Info;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Pricing\Helper\Data;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Payment\Block\Info;
use Vindi\Payment\Api\PixConfigurationInterface;
use Vindi\Payment\Model\Payment\PaymentMethod;

/**
 * Class Pix
 *
 * @package Vindi\Payment\Block\Info
 *
 * @method $this setCacheLifetime(false|int $lifetime)
 */
class Pix extends Info
{
    /**
     * @var string
     */
    protected $_template = 'Vindi_Payment::info/pix.phtml';

    /**
     * @var Data
     */
    protected $currency;

    /**
     * @var PixConfigurationInterface
     */
    protected $pixConfiguration;

    /**
     * @var TimezoneInterface
     */
    protected $timezone;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var PaymentMethod
     */
    protected $paymentMethod;

    /**
     * Pix constructor.
     *
     * @param PaymentMethod             $paymentMethod
     * @param Data                      $currency
     * @param Context                   $context
     * @param PixConfigurationInterface $pixConfiguration
     * @param TimezoneInterface         $timezone
     * @param Json                      $json
     * @param array                     $data
     */
    public function __construct(
        PaymentMethod             $paymentMethod,
        Data                      $currency,
        Context                   $context,
        PixConfigurationInterface $pixConfiguration,
        TimezoneInterface         $timezone,
        Json                      $json,
        array                     $data = []
    ) {
        parent::__construct($context, $data);
        $this->paymentMethod    = $paymentMethod;
        $this->currency         = $currency;
        $this->pixConfiguration = $pixConfiguration;
        $this->timezone         = $timezone;
        $this->json             = $json;
    }

    /**
     * Disable block cache
     */
    protected function _construct()
    {
        parent::_construct();
        $this->setCacheLifetime(false);
    }

    /**
     * Retrieve bill ID for Pix payments
     *
     * @return string|null
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getBillId()
    {
        $order = $this->getOrder();
        return $order->getVindiBillId() ?? null;
    }

    /**
     * Retrieve order instance
     *
     * @return \Magento\Sales\Model\Order
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getOrder()
    {
        return $this->getInfo()->getOrder();
    }

    /**
     * Get order payment method name
     *
     * @return string
     */
    public function getPaymentMethodName()
    {
        return $this->getOrder()->getPayment()->getMethodInstance()->getTitle();
    }

    /**
     * Get reorder URL
     *
     * @return string
     */
    public function getReorderUrl()
    {
        $order = $this->getOrder();
        return $this->getUrl('sales/order/reorder', ['order_id' => $order->getId()]);
    }

    /**
     * Determine if Pix information can be shown
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function canShowPixInfo()
    {
        $paymentMethod = $this->getOrder()->getPayment()->getMethod() === \Vindi\Payment\Model\Payment\Pix::CODE;
        $daysToPayment = $this->getMaxDaysToPayment();

        if (!$daysToPayment) {
            return true;
        }

        $timestampMaxDays = strtotime($daysToPayment);
        return $paymentMethod && $this->isValidToPayment($timestampMaxDays);
    }

    /**
     * Check if the order has any invoices
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function hasInvoice()
    {
        return $this->getOrder()->hasInvoices();
    }

    /**
     * Retrieve QR code URL for Pix payments
     *
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getQrCodePix()
    {
        return $this->getOrder()->getPayment()->getAdditionalInformation('qrcode_path');
    }

    /**
     * Get QR code warning message
     *
     * @return string
     */
    public function getQrCodeWarningMessage()
    {
        return $this->pixConfiguration->getQrCodeWarningMessage();
    }

    /**
     * Retrieve original QR code path for Pix payments
     *
     * @return bool|string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getQrcodeOriginalPath()
    {
        $path = $this->getOrder()->getPayment()->getAdditionalInformation('qrcode_original_path');
        return $this->json->serialize($path);
    }

    /**
     * Retrieve max days to keep waiting for Pix payment
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getMaxDaysToPayment(): string
    {
        return (string)$this->getOrder()->getPayment()->getAdditionalInformation('max_days_to_keep_waiting_payment');
    }

    /**
     * Validate timestamp against current store time
     *
     * @param int $timestampMaxDays
     * @return bool
     */
    protected function isValidToPayment($timestampMaxDays)
    {
        if (!$timestampMaxDays) {
            return false;
        }
        return $timestampMaxDays >= $this->timezone->scopeTimeStamp();
    }
}
