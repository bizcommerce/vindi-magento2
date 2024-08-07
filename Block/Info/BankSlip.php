<?php
namespace Vindi\Payment\Block\Info;

use Vindi\Payment\Model\Payment\PaymentMethod;

class BankSlip extends \Magento\Payment\Block\Info
{
    /**
     * @var string
     */
    protected $_template = 'Vindi_Payment::info/bankslip.phtml';

    protected $currency;

    protected $paymentMethod;

    public function __construct(
        PaymentMethod $paymentMethod,
        \Magento\Framework\Pricing\Helper\Data $currency,
        \Magento\Backend\Block\Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->paymentMethod = $paymentMethod;
        $this->currency = $currency;
    }

    protected function _construct()
    {
        parent::_construct();
        $this->setCacheLifetime(false);
    }

    public function getOrder()
    {
        return $this->getInfo()->getOrder();
    }

    /**
     * @return bool
     */
    public function hasInvoice(): bool
    {
        return $this->getOrder()->hasInvoices();
    }

    /**
     * Get order payment method name
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

    public function canShowBankslipInfo(): bool
    {
        return $this->getOrder()->getPayment()->getMethod() === \Vindi\Payment\Model\Payment\BankSlip::CODE;
    }

    public function getPrintUrl(): string
    {
        return (string) $this->getOrder()->getPayment()->getAdditionalInformation('print_url');
    }

    public function getDueDate(): string
    {
        return (string) $this->getOrder()->getPayment()->getAdditionalInformation('due_at');
    }
}
