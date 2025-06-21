<?php

namespace Vindi\Payment\Api;

use Vindi\Payment\Model\WebhookQueue;

/**
 * Webhook Queue Repository Interface
 */
interface WebhookQueueRepositoryInterface
{
    /**
     * Save webhook queue item
     *
     * @param WebhookQueue $webhookQueue
     * @return WebhookQueue
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function save(WebhookQueue $webhookQueue);

    /**
     * Get webhook queue item by ID
     *
     * @param int $queueId
     * @return WebhookQueue
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById($queueId);

    /**
     * Delete webhook queue item
     *
     * @param WebhookQueue $webhookQueue
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function delete(WebhookQueue $webhookQueue);

    /**
     * Delete webhook queue item by ID
     *
     * @param int $queueId
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function deleteById($queueId);

    /**
     * Get oldest pending webhook queue item by event type
     *
     * @param string $eventType
     * @return WebhookQueue|null
     */
    public function getOldestPendingByEventType($eventType);

    /**
     * Get next item to process by event type
     *
     * @param string $eventType
     * @return WebhookQueue|null
     */
    public function getNextToProcess($eventType);

    /**
     * Clean up old completed and failed items
     *
     * @param int $daysOld
     * @return int Number of deleted items
     */
    public function cleanupOldItems($daysOld = 30);

    /**
     * Get retry-able items by event type
     *
     * @param string $eventType
     * @return \Vindi\Payment\Model\ResourceModel\WebhookQueue\Collection
     */
    public function getRetryableItems($eventType);
}
