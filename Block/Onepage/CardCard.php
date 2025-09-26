<?php
namespace Vindi\Payment\Block\Onepage;

use Magento\Checkout\Model\Session;
use Magento\Framework\View\Element\Template;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;

class CardCard extends Template
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
        return $p->getMethod() === 'vindi_cardcard';
    }

    private function addl(string $key)
    {
        $p = $this->getPayment();
        return $p ? $p->getAdditionalInformation($key) : null;
    }

    private function pick(array $keys)
    {
        foreach ($keys as $k) {
            $v = $this->addl($k);
            if ($v !== null && $v !== '') return $v;
        }
        return null;
    }

    public function getFirstCardAmount(): float
    {
        $v = $this->pick(['amount_credit', 'amount_first_card', 'first_card_amount']);
        return ($v !== null && $v !== '') ? (float)$v : 0.0;
    }

    public function getSecondCardAmount(): float
    {
        $v = $this->pick(['amount_second_card', 'second_card_amount']);
        return ($v !== null && $v !== '') ? (float)$v : 0.0;
    }

    public function formatCurrency($amount): string
    {
        return $this->priceHelper->currency((float)$amount, true, false);
    }

    public function canShowCcInfo(): bool
    {
        $f = $this->getFirstCardInfo();
        $s = $this->getSecondCardInfo();
        return (bool)(
            ($f['brand'] ?? '') || ($f['number'] ?? '') || ($f['installments'] ?? 0)
            || ($s['brand'] ?? '') || ($s['number'] ?? '') || ($s['installments'] ?? 0)
        );
    }

    public function getFirstCardInfo(): array
    {
        $brand = (string)$this->pick(['card_brand', 'cc_type1', 'cc_type', 'brand_first', 'brand1']) ?: '';
        $owner = (string)$this->pick(['cc_owner1', 'cc_owner', 'owner_first', 'owner1']) ?: '';
        $last4 = (string)$this->pick(['cc_last_41','cc_last_4_1','cc_last4','card_last4','last4']) ?: '';
        $number = $last4 ? ('**** **** **** ' . $last4) : '';
        $installments = (int)($this->pick(['cc_installments','cc_installments1','installments_first','installments1']) ?: 0);

        return [
            'brand'        => $brand,
            'owner'        => $owner,
            'number'       => $number,
            'installments' => $installments
        ];
    }

    public function getSecondCardInfo(): array
    {
        $brand = (string)$this->pick(['cc_type2', 'brand_second', 'brand2']) ?: '';
        $owner = (string)$this->pick(['cc_owner2', 'owner_second', 'owner2']) ?: '';
        $last4 = (string)$this->pick(['cc_last_42','cc_last_4_2','cc_last4_2','card_last4_2','last42']) ?: '';
        $number = $last4 ? ('**** **** **** ' . $last4) : '';
        $installments = (int)($this->pick(['cc_installments2','installments_second','installments2']) ?: 0);

        return [
            'brand'        => $brand,
            'owner'        => $owner,
            'number'       => $number,
            'installments' => $installments
        ];
    }

    public function getPaymentMethodName(): string
    {
        $m = $this->getMethod();
        return $m ? (string)$m->getTitle() : 'Card + Card';
    }

    protected function _toHtml()
    {
        return $this->canShow() ? parent::_toHtml() : '';
    }
}
