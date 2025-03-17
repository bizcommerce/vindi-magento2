<?php
namespace Vindi\Payment\Block\Info;

use Vindi\Payment\Model\Payment\PaymentMethod;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Pricing\Helper\Data;
use Vindi\Payment\Api\PixConfigurationInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Block class for displaying information when the payment method is "Card + Pix"
 */
class CardPix extends \Magento\Payment\Block\Info
{
    use \Vindi\Payment\Block\InfoTrait;

    /**
     * Template file for CardPix block
     *
     * @var string
     */
    protected $_template = 'Vindi_Payment::info/card_pix.phtml';

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
     * CardPix constructor.
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
        $this->pixConfiguration = $pixConfiguration;
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
     * Retrieve bill id for Pix payments
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
     * Determine if Pix information can be shown
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function canShowPixInfo()
    {
        $method = $this->getOrder()->getPayment()->getMethod();
        $validMethods = [
            \Vindi\Payment\Model\Payment\Pix::CODE,
            PaymentMethod::CARD_PIX,
            PaymentMethod::CARD_BANKSLIP_PIX,
            "vindi_cardpix"
        ];

        $paymentMethod = in_array($method, $validMethods, true);
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
     * Retrieve QR Code URL for Pix payments
     *
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getQrCodePix()
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
     * Retrieve original QR Code path for Pix payments
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
     * Get the formatted date/time until which Pix payment is valid
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
     * Validate if Pix payment is still valid
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
     * Retrieve maximum days to keep waiting for Pix payment
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getMaxDaysToPayment(): string
    {
        return (string) $this->getOrder()->getPayment()->getAdditionalInformation('max_days_to_keep_waiting_payment');
    }
}
