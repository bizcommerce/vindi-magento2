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

namespace Vindi\Payment\Model\ResourceModel\WebhookQueue;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Vindi\Payment\Model\WebhookQueue as Model;
use Vindi\Payment\Model\ResourceModel\WebhookQueue as ResourceModel;

/**
 * WebhookQueue Collection
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'queue_id';

    /**
     * Initialize resource collection
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }

    /**
     * Filter by status
     *
     * @param string $status
     * @return $this
     */
    public function addStatusFilter($status)
    {
        $this->addFieldToFilter('status', $status);
        return $this;
    }

    /**
     * Filter by event type
     *
     * @param string $eventType
     * @return $this
     */
    public function addEventTypeFilter($eventType)
    {
        $this->addFieldToFilter('event_type', $eventType);
        return $this;
    }

    /**
     * Filter by priority
     *
     * @param string $priority
     * @return $this
     */
    public function addPriorityFilter($priority)
    {
        $this->addFieldToFilter('priority', $priority);
        return $this;
    }

    /**
     * Get pending items ready for processing
     *
     * @return $this
     */
    public function addReadyForProcessingFilter()
    {
        $this->addFieldToFilter('status', 'pending')
            ->addFieldToFilter('retry_count', ['lt' => 'max_retries'])
            ->addFieldToFilter(
                'scheduled_at',
                [
                    ['null' => true],
                    ['lteq' => date('Y-m-d H:i:s')]
                ]
            );
        return $this;
    }

    /**
     * Order by priority and creation time
     *
     * @return $this
     */
    public function addProcessingOrder()
    {
        $this->setOrder('priority', 'DESC')
            ->setOrder('created_at', 'ASC');
        return $this;
    }

    /**
     * Filter items older than specified days
     *
     * @param int $days
     * @return $this
     */
    public function addOlderThanFilter($days = 30)
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $this->addFieldToFilter('created_at', ['lt' => $cutoffDate]);
        return $this;
    }

    /**
     * Filter failed items that exceeded max retries
     *
     * @return $this
     */
    public function addFailedItemsFilter()
    {
        $this->addFieldToFilter('status', 'pending')
            ->addFieldToFilter('retry_count', ['gteq' => 'max_retries']);
        return $this;
    }

    /**
     * Filter stuck processing items
     *
     * @param int $timeoutMinutes
     * @return $this
     */
    public function addStuckProcessingFilter($timeoutMinutes = 30)
    {
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$timeoutMinutes} minutes"));
        $this->addFieldToFilter('status', 'processing')
            ->addFieldToFilter('updated_at', ['lt' => $cutoffTime]);
        return $this;
    }
}