<?php

declare(strict_types=1);

/**
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_Payment
 *
 *
 */

namespace Vindi\Payment\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * WebhookQueue Resource Model
 *
 * Handles database operations for webhook queue items
 */
class WebhookQueue extends AbstractDb
{
    /**
     * @var string
     */
    protected $_idFieldName = 'queue_id';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('vindi_webhook_queue', 'queue_id');
    }

    /**
     * Delete records older than specified days and with status not equal to 'pending'
     *
     * @param int $daysOld
     * @return void
     */
    public function deleteOldNonPendingRecords($daysOld = 30)
    {
        $connection = $this->getConnection();
        $tableName = $this->getMainTable();
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        $where = [
            'status != ?' => 'pending',
            'created_at < ?' => $cutoffDate
        ];

        $connection->delete($tableName, $where);
    }
}