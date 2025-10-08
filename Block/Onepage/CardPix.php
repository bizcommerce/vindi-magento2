<?php
namespace Vindi\Payment\Block\Onepage;

use Magento\Checkout\Model\Session;
use Magento\Framework\View\Element\Template;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;

class CardPix extends Template
{
    /** @var Session */
    protected $checkoutSession;

    /** @var PriceHelper */
    protected $priceHelper;

    public function __construct(
        Session $checkoutSession,
        PriceHelper $priceHelper,
        \Magento\Framework\View\Element\Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->checkoutSession = $checkoutSession;
        $this->priceHelper     = $priceHelper;
    }

    /** @return \Magento\Sales\Model\Order|null */
    public function getOrder()
    {
        return $this->checkoutSession->getLastRealOrder();
    }

    /** @return \Magento\Sales\Model\Order\Payment|null */
    public function getPayment()
    {
        $o = $this->getOrder();
        return $o ? $o->getPayment() : null;
    }

    public function getMethod()
    {
        $p = $this->getPayment();
        return $p ? $p->getMethodInstance() : null;
    }

    public function canShow(): bool
    {
        $p = $this->getPayment();
        if (!$p) { return false; }
        return $p->getMethod() === 'vindi_cardpix';
    }

    private function addl(string $key)
    {
        $p = $this->getPayment();
        return $p ? $p->getAdditionalInformation($key) : null;
    }

    public function getCreditAmount(): float
    {
        $v = $this->addl('amount_credit');
        return ($v !== null && $v !== '') ? (float)$v : 0.0;
    }

    public function getPixAmount(): float
    {
        $v = $this->addl('amount_pix');
        return ($v !== null && $v !== '') ? (float)$v : 0.0;
    }

    public function formatCurrency($amount): string
    {
        return $this->priceHelper->currency((float)$amount, true, false);
    }

    public function canShowCcInfo(): bool
    {
        return (bool)($this->getCcBrand() || $this->getCcNumber() || $this->getCcInstallments());
    }

    public function getCcBrand(): string
    {
        return (string)($this->addl('card_brand') ?? '');
    }

    public function getCcOwner(): string
    {
        return (string)($this->addl('card_holder_name') ?? '');
    }

    public function getCcNumber(): string
    {
        $last4 = (string)($this->addl('card_last_4') ?? '');
        return $last4 ? ('**** **** **** ' . $last4) : '';
    }

    public function getCcInstallments(): int
    {
        $i = $this->addl('cc_installments') ?? $this->addl('installments');
        return (int)($i ?: 0);
    }

    public function hasInvoice(): bool
    {
        $o = $this->getOrder();
        return $o ? $o->hasInvoices() : false;
    }

    public function isCanceled(): bool
    {
        $o = $this->getOrder();
        return $o ? $o->isCanceled() : false;
    }

    public function canShowPixInfo(): bool
    {
        return (bool)($this->getQrCodePix() || $this->getQrcodeOriginalPath());
    }

    public function getQrCodePix(): ?string
    {
        return $this->addl('qrcode_path')
            ?? $this->addl('vindi_charge_0_qrcode_path')
            ?? null;
    }

    public function getQrcodeOriginalPath(): ?string
    {
        return $this->addl('qrcode_original_path')
            ?? $this->addl('vindi_charge_0_qrcode_original_path')
            ?? null;
    }

    public function getDaysToKeepWaitingPayment(): ?string
    {
        $expires = $this->addl('pix_expires_at') ?? $this->addl('vindi_charge_0_pix_expires_at');
        $due     = $expires ?: ($this->addl('due_at') ?? $this->addl('vindi_charge_0_due_at'));
        if (!$due) { return null; }
        try {
            $dt = new \DateTime($due);
            return $dt->format('d/m/Y H:i');
        } catch (\Exception $e) {
            return (string)$due;
        }
    }

    public function getQrCodeWarningMessage(): string
    {
        return '';
    }

    public function getBillId(): ?string
    {
        $id = $this->addl('vindi_bill_id');
        return $id !== null ? (string)$id : null;
    }

    protected function _toHtml()
    {
        return $this->canShow() ? parent::_toHtml() : '';
    }
}
