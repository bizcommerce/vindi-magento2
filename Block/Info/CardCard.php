<?php

namespace Vindi\Payment\Block\Info;

use Vindi\Payment\Model\Payment\PaymentMethod;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Pricing\Helper\Data;
use Vindi\Payment\Helper\BrandNormalizer;

/**
 * Class CardCard
 *
 * @package Vindi\Payment\Block\Info
 *
 * @method $this setCacheLifetime(false|int $lifetime)
 */
class CardCard extends \Magento\Payment\Block\Info
{
    use \Vindi\Payment\Block\InfoTrait;

    /**
     * @var string
     */
    protected $_template = 'Vindi_Payment::info/card_card.phtml';

    /**
     * @var Data
     */
    protected $currency;

    /**
     * @var PaymentMethod
     */
    protected $paymentMethod;

    /**
     * @var BrandNormalizer
     */
    protected $brandNormalizer;

    /**
     * CardCard constructor.
     *
     * @param PaymentMethod   $paymentMethod
     * @param Data            $currency
     * @param BrandNormalizer $brandNormalizer
     * @param Context         $context
     * @param array           $data
     */
    public function __construct(
        PaymentMethod $paymentMethod,
        Data $currency,
        BrandNormalizer $brandNormalizer,
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->paymentMethod   = $paymentMethod;
        $this->currency        = $currency;
        $this->brandNormalizer = $brandNormalizer;
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
     * Retrieve first card details
     *
     * @return array
     */
    public function getFirstCardInfo()
    {
        $payment = $this->getOrder()->getPayment();
        $rawBrand = $payment->getAdditionalInformation('cc_type') ?: $payment->getData('cc_type');
        return [
            'brand'        => $this->brandNormalizer->normalize($rawBrand),
            'owner'        => $payment->getAdditionalInformation('cc_owner')    ?: $payment->getData('cc_owner'),
            'number'       => $payment->getAdditionalInformation('cc_last_4')   ?: $payment->getData('cc_last_4'),
            'installments' => $payment->getAdditionalInformation('cc_installments') ?: $payment->getData('cc_installments')
        ];
    }

    /**
     * Retrieve second card details
     *
     * @return array
     */
    public function getSecondCardInfo()
    {
        $payment = $this->getOrder()->getPayment();
        $rawBrand = $payment->getAdditionalInformation('cc_type2') ?: $payment->getData('cc_type2');
        return [
            'brand'        => $this->brandNormalizer->normalize($rawBrand),
            'owner'        => $payment->getAdditionalInformation('cc_owner2')    ?: $payment->getData('cc_owner2'),
            'number'       => $payment->getAdditionalInformation('cc_last_4_2')  ?: $payment->getData('cc_last_4_2'),
            'installments' => $payment->getAdditionalInformation('cc_installments2') ?: $payment->getData('cc_installments2')
        ];
    }

    /**
     * Get payment method name.
     *
     * @return string
     */
    public function getPaymentMethodName()
    {
        return $this->getOrder()->getPayment()->getMethodInstance()->getTitle();
    }

    /**
     * Get first card amount from split payment
     *
     * @return float
     */
    public function getFirstCardAmount()
    {
        $payment = $this->getOrder()->getPayment();
        return (float) $payment->getAdditionalInformation('amount_credit');
    }

    /**
     * Get second card amount from split payment
     *
     * @return float
     */
    public function getSecondCardAmount()
    {
        $payment = $this->getOrder()->getPayment();
        return (float) $payment->getAdditionalInformation('amount_second_card');
    }

    /**
     * Format currency value
     *
     * @param float $amount
     * @return string
     */
    public function formatCurrency($amount)
    {
        return $this->currency->currency($amount, true, false);
    }

    /**
     * Format price (alias for formatCurrency)
     *
     * @param float $amount
     * @return string
     */
    public function formatPrice($amount)
    {
        return $this->formatCurrency($amount);
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
}
