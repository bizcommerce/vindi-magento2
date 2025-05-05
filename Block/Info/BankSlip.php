<?php
namespace Vindi\Payment\Block\Info;

use Vindi\Payment\Model\Payment\PaymentMethod;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Backend\Block\Template\Context;

/**
 * Class BankSlip
 *
 * @package Vindi\Payment\Block\Info
 *
 * @method $this setCacheLifetime(false|int $lifetime)
 */
class BankSlip extends \Magento\Payment\Block\Info
{
    /**
     * @var string
     */
    protected $_template = 'Vindi_Payment::info/bankslip.phtml';

    /**
     * @var PricingHelper
     */
    protected $currency;

    /**
     * @var PaymentMethod
     */
    protected $paymentMethod;

    /**
     * BankSlip constructor.
     *
     * @param PaymentMethod    $paymentMethod
     * @param PricingHelper    $currency
     * @param Context          $context
     * @param array            $data
     */
    public function __construct(
        PaymentMethod $paymentMethod,
        PricingHelper $currency,
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->paymentMethod = $paymentMethod;
        $this->currency      = $currency;
    }

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        parent::_construct();
        $this->setCacheLifetime(false);
    }

    /**
     * Retrieve order from payment info
     *
     * @return \Magento\Sales\Model\Order
     */
    public function getOrder()
    {
        return $this->getInfo()->getOrder();
    }

    /**
     * Check if order has any invoices
     *
     * @return bool
     */
    public function hasInvoice(): bool
    {
        return $this->getOrder()->hasInvoices();
    }

    /**
     * Get order payment method title
     *
     * @return string
     */
    public function getPaymentMethodName(): string
    {
        try {
            return $this->getOrder()->getPayment()->getMethodInstance()->getTitle();
        } catch (\Exception $e) {
            return 'Boleto';
        }
    }

    /**
     * Determine if Bank Slip info can be shown
     *
     * @return bool
     */
    public function canShowBankslipInfo(): bool
    {
        return $this->getOrder()->getPayment()->getMethod() === \Vindi\Payment\Model\Payment\BankSlip::CODE;
    }

    /**
     * Get URL for printing the slip
     *
     * @return string
     */
    public function getPrintUrl(): string
    {
        return (string)$this->getOrder()->getPayment()->getAdditionalInformation('print_url');
    }

    /**
     * Get due date of the slip
     *
     * @return string
     */
    public function getDueDate(): string
    {
        return (string)$this->getOrder()->getPayment()->getAdditionalInformation('due_at');
    }
}
