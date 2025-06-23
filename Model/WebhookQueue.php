<?php

namespace Vindi\Payment\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Webhook Queue Resource Model
 */
class WebhookQueue extends AbstractDb
{
    /**
     * Define resource model
     */
    protected function _construct()
    {
        $this->_init('vindi_webhook_queue', 'queue_id');
    }
}
