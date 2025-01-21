<?php

namespace Vindi\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Class PaymentMethods
 * Provides available payment methods as options.
 */
class PaymentMethods implements OptionSourceInterface
{
    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'credit_card', 'label' => __('Vindi - Credit Card')],
            ['value' => 'bank_slip', 'label' => __('Vindi - Bank Slip')],
            ['value' => 'pix', 'label' => __('Vindi - Pix')],
            ['value' => 'pix_bank_slip', 'label' => __('Vindi - Bolepix')],
        ];
    }
}
