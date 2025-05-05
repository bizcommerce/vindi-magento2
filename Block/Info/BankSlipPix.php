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
 * Class BankSlipPix
 *
 * @package Vindi\Payment\Block\Info
 *
 * @method $this setCacheLifetime(false|int $lifetime)
 */
class BankSlipPix extends Info
{
    /**
     * @var string
     */
    protected $_template = 'Vindi_Payment::info/bankslippix.phtml';

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
     * BankSlipPix constructor.
     *
     * @param PaymentMethod             $paymentMethod
     * @param Data                      $currency
     * @param Context                   $context
     * @param PixConfigurationInterface $pixConfiguration
     * @param Json                      $json
     * @param TimezoneInterface         $timezone
     * @param array                     $data
     */
    public function __construct(
        PaymentMethod             $paymentMethod,
        Data                      $currency,
        Context                   $context,
        PixConfigurationInterface $pixConfiguration,
        Json                      $json,
        TimezoneInterface         $timezone,
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
     * Retrieve bill ID for the order
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
     */
    public function getOrder()
    {
        return $this->getInfo()->getOrder();
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
     * Get order payment method name
     *
     * @return string
     */
    public function getPaymentMethodName()
    {
        return $this->getOrder()->getPayment()->getMethodInstance()->getTitle();
    }

    /**
     * Determine if BankSlipPix info can be shown
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function canShowBankSlipPixInfo()
    {
        $paymentMethod = $this->getOrder()->getPayment()->getMethod() === \Vindi\Payment\Model\Payment\BankSlipPix::CODE;
        $daysToPayment = $this->getMaxDaysToPayment();

        if (!$daysToPayment) {
            return true;
        }

        $timestampMaxDays = strtotime($daysToPayment);
        return $paymentMethod && $this->isValidToPayment($timestampMaxDays);
    }

    /**
     * Retrieve QR code path for Pix
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
        return (string) $this->pixConfiguration->getQrCodeWarningMessage();
    }

    /**
     * Retrieve the original QR code path and serialize it
     *
     * @return string
     */
    public function getQrcodeOriginalPath(): string
    {
        try {
            $path = (string)$this->getOrder()->getPayment()->getAdditionalInformation('qrcode_original_path');
            return $this->json->serialize($path);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Retrieve formatted due date
     *
     * @return string
     */
    public function getDaysToKeepWaitingPayment()
    {
        $days = $this->getMaxDaysToPayment();
        if (!$days) {
            return '';
        }

        $timestamp = strtotime($days);
        return date('d/m/Y H:i:s', $timestamp);
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

    /**
     * Retrieve max days to payment information
     *
     * @return string
     */
    protected function getMaxDaysToPayment(): string
    {
        return (string)$this->getOrder()->getPayment()->getAdditionalInformation('max_days_to_keep_waiting_payment');
    }

    /**
     * Get print URL
     *
     * @return string
     */
    public function getPrintUrl(): string
    {
        return (string)$this->getOrder()->getPayment()->getAdditionalInformation('print_url');
    }

    /**
     * Get due date
     *
     * @return string
     */
    public function getDueDate(): string
    {
        return (string)$this->getOrder()->getPayment()->getAdditionalInformation('due_at');
    }
}
