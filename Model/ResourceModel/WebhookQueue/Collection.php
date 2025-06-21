<?php

namespace Vindi\Payment\Model\ResourceModel\WebhookQueue;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Vindi\Payment\Model\WebhookQueue;
use Vindi\Payment\Model\ResourceModel\WebhookQueue as WebhookQueueResource;

/**
 * Webhook Queue Collection
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'queue_id';

    /**
     * Initialize collection
     */
    protected function _construct()
    {
        $this->_init(WebhookQueue::class, WebhookQueueResource::class);
    }

    /**
     * Filter by event type
     *
     * @param string $eventType
     * @return $this
     */
    public function addEventTypeFilter($eventType)
    {
        return $this->addFieldToFilter('event_type', $eventType);
    }

    /**
     * Filter by status
     *
     * @param string|array $status
     * @return $this
     */
    public function addStatusFilter($status)
    {
        return $this->addFieldToFilter('status', $status);
    }

    /**
     * Filter by priority
     *
     * @param string $priority
     * @return $this
     */
    public function addPriorityFilter($priority)
    {
        return $this->addFieldToFilter('priority', $priority);
    }

    /**
     * Get pending items
     *
     * @return $this
     */
    public function getPendingItems()
    {
        return $this->addStatusFilter(WebhookQueue::STATUS_PENDING);
    }

    /**
     * Get items ready for retry
     *
     * @return $this
     */
    public function getRetryableItems()
    {
        return $this->addFieldToFilter('status', WebhookQueue::STATUS_RETRYING)
            ->addFieldToFilter('scheduled_at', ['lteq' => date('Y-m-d H:i:s')]);
    }

    /**
     * Get oldest items first (by created_at)
     *
     * @return $this
     */
    public function orderByOldest()
    {
        return $this->setOrder('created_at', 'ASC');
    }

    /**
     * Get items by priority (high first)
     *
     * @return $this
     */
    public function orderByPriority()
    {
        return $this->addOrder('priority', 'ASC')
            ->addOrder('created_at', 'ASC');
    }
}
