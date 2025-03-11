<?php

namespace Vindi\Payment\Model\Config\Source;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Class PaymentMethods
 * Provides available payment methods as options with labels fetched from configuration.
 */
class PaymentMethods implements OptionSourceInterface
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * PaymentMethods constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Return array of options as value-label pairs.
     *
     * @return array
     */
    public function toOptionArray()
    {
        $creditCardTitle = $this->scopeConfig->getValue('payment/vindi/title', ScopeInterface::SCOPE_STORE);
        $bankSlipTitle   = $this->scopeConfig->getValue('payment/vindi_bankslip/title', ScopeInterface::SCOPE_STORE);
        $pixTitle        = $this->scopeConfig->getValue('payment/vindi_pix/title', ScopeInterface::SCOPE_STORE);
        $bolepixTitle    = $this->scopeConfig->getValue('payment/vindi_bankslippix/title', ScopeInterface::SCOPE_STORE);

        return [
            ['value' => 'credit_card',   'label' => $creditCardTitle],
            ['value' => 'bank_slip',     'label' => $bankSlipTitle],
            ['value' => 'pix',           'label' => $pixTitle],
            ['value' => 'pix_bank_slip', 'label' => $bolepixTitle],
        ];
    }
}
