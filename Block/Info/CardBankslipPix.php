<?php
// File: app/code/Vindi/Payment/Block/Info/CardBankslipPix.php
namespace Vindi\Payment\Block\Info;

use Vindi\Payment\Model\Payment\PaymentMethod;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Pricing\Helper\Data;
use Vindi\Payment\Api\PixConfigurationInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class CardBankslipPix extends \Magento\Payment\Block\Info
{
    use \Vindi\Payment\Block\InfoTrait;

    /**
     * Template file for CardBankslipPix block
     *
     * @var string
     */
    protected $_template = 'Vindi_Payment::info/card_bankslippix.phtml';

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
     * CardBankslipPix constructor.
     *
     * @param PaymentMethod $paymentMethod
     * @param Data $currency
     * @param Context $context
     * @param PixConfigurationInterface $pixConfiguration
     * @param TimezoneInterface $timezone
     * @param Json $json
     * @param array $data
     */
    public function __construct(
        PaymentMethod $paymentMethod,
        Data $currency,
        Context $context,
        PixConfigurationInterface $pixConfiguration,
        TimezoneInterface $timezone,
        Json $json,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->paymentMethod   = $paymentMethod;
        $this->currency        = $currency;
        $this->pixConfiguration  = $pixConfiguration;
        $this->timezone        = $timezone;
        $this->json            = $json;
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
     * Retrieve order instance
     *
     * @return \Magento\Sales\Model\Order
     */
    public function getOrder()
    {
        return $this->getInfo()->getOrder();
    }

    /**
     * Retrieve bill id for Bolepix payments
     *
     * @return string|null
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getBillId()
    {
        $order = $this->getOrder();
        $billId = $order->getVindiBillId() ?? null;
        return $billId;
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
     * Get reorder URL for the order
     *
     * @return string
     */
    public function getReorderUrl()
    {
        $order = $this->getOrder();
        return $this->getUrl('sales/order/reorder', ['order_id' => $order->getId()]);
    }

    /**
     * Determine if Bolepix information can be shown
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function canShowBolepixInfo()
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
     * Check if the order has any invoice
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function hasInvoice()
    {
        return $this->getOrder()->hasInvoices();
    }

    /**
     * Retrieve QR Code URL for Bolepix payments
     *
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getQrCodeBolepix()
    {
        return $this->getOrder()->getPayment()->getAdditionalInformation('qrcode_path');
    }

    /**
     * Get warning message for QR Code display
     *
     * @return string
     */
    public function getQrCodeWarningMessage()
    {
        return $this->pixConfiguration->getQrCodeWarningMessage();
    }

    /**
     * Retrieve original QR Code path for Bolepix payments
     *
     * @return bool|string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getQrcodeOriginalPath()
    {
        $qrcodeOriginalPath = $this->getOrder()->getPayment()->getAdditionalInformation('qrcode_original_path');
        return $this->json->serialize($qrcodeOriginalPath);
    }

    /**
     * Get the formatted date/time until which Bolepix payment is valid
     *
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getDaysToKeepWaitingPayment()
    {
        $daysToPayment = $this->getMaxDaysToPayment();
        if (!$daysToPayment) {
            return null;
        }
        $timestampMaxDays = strtotime($daysToPayment);
        return date('d/m/Y H:i:s', $timestampMaxDays);
    }

    /**
     * Validate if Bolepix payment is still valid
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
     * Retrieve maximum days to keep waiting for Bolepix payment
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getMaxDaysToPayment(): string
    {
        return (string) $this->getOrder()->getPayment()->getAdditionalInformation('max_days_to_keep_waiting_payment');
    }

    /**
     * Get print URL for Bolepix payments
     *
     * @return string
     */
    public function getPrintUrl(): string
    {
        return (string) $this->getOrder()->getPayment()->getAdditionalInformation('print_url');
    }

    /**
     * Get due date for Bolepix payments
     *
     * @return string
     */
    public function getDueDate(): string
    {
        return (string) $this->getOrder()->getPayment()->getAdditionalInformation('due_at');
    }
}
