<?php

namespace Vindi\Payment\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PaymentSplit extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('vindi_payment_split', 'entity_id');
    }
}
