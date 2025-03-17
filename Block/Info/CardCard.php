<?php
namespace Vindi\Payment\Block\Info;

use Vindi\Payment\Model\Payment\PaymentMethod;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Pricing\Helper\Data;

/**
 * CardCard block information
 */
class CardCard extends \Magento\Payment\Block\Info
{
    use \Vindi\Payment\Block\InfoTrait;

    /**
     * Template file for CardCard block
     *
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
     * CardCard constructor.
     *
     * @param PaymentMethod $paymentMethod
     * @param Data $currency
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        PaymentMethod $paymentMethod,
        Data $currency,
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->paymentMethod = $paymentMethod;
        $this->currency = $currency;
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
     * Retrieve first card details
     *
     * @return array
     */
    public function getFirstCardInfo()
    {
        $payment = $this->getOrder()->getPayment();
        return [
            'brand' => $payment->getData('cc_type') ?: $payment->getAdditionalInformation('cc_type'),
            'owner' => $payment->getData('cc_owner') ?: $payment->getAdditionalInformation('cc_owner'),
            'number' => $payment->getData('cc_last_4') ?: $payment->getAdditionalInformation('cc_last_4'),
            'installments' => $payment->getData('cc_installments1') ?: $payment->getAdditionalInformation('cc_installments1')
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
        return [
            'brand' => $payment->getData('cc_type2') ?: $payment->getAdditionalInformation('cc_type2'),
            'owner' => $payment->getData('cc_owner2') ?: $payment->getAdditionalInformation('cc_owner2'),
            'number' => $payment->getData('cc_last_4_2') ?: $payment->getAdditionalInformation('cc_last_4_2'),
            'installments' => $payment->getData('cc_installments2') ?: $payment->getAdditionalInformation('cc_installments2')
        ];
    }

    /**
     * Get payment method name
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
}
