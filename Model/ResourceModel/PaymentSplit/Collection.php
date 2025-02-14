<?php

namespace Vindi\Payment\Model\ResourceModel\PaymentSplit;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Vindi\Payment\Model\PaymentSplit;
use Vindi\Payment\Model\ResourceModel\PaymentSplit as PaymentSplitResource;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(PaymentSplit::class, PaymentSplitResource::class);
    }
}
