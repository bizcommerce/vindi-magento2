<?php

namespace Vindi\Payment\Model;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Vindi\Payment\Api\WebhookQueueRepositoryInterface;
use Vindi\Payment\Model\WebhookQueue;
use Vindi\Payment\Model\WebhookQueueFactory;
use Vindi\Payment\Model\ResourceModel\WebhookQueue as WebhookQueueResource;
use Vindi\Payment\Model\ResourceModel\WebhookQueue\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Webhook Queue Repository
 */
class WebhookQueueRepository implements WebhookQueueRepositoryInterface
{
    /**
     * @var WebhookQueueResource
     */
    private $resource;

    /**
     * @var WebhookQueueFactory
     */
    private $webhookQueueFactory;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Constructor
     *
     * @param WebhookQueueResource $resource
     * @param WebhookQueueFactory $webhookQueueFactory
     * @param CollectionFactory $collectionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        WebhookQueueResource $resource,
        WebhookQueueFactory $webhookQueueFactory,
        CollectionFactory $collectionFactory,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->webhookQueueFactory = $webhookQueueFactory;
        $this->collectionFactory = $collectionFactory;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function save(WebhookQueue $webhookQueue)
    {
        try {
            $this->resource->save($webhookQueue);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__(
                'Could not save the webhook queue item: %1',
                $exception->getMessage()
            ));
        }
        return $webhookQueue;
    }

    /**
     * @inheritDoc
     */
    public function getById($queueId)
    {
        $webhookQueue = $this->webhookQueueFactory->create();
        $this->resource->load($webhookQueue, $queueId);
        if (!$webhookQueue->getQueueId()) {
            throw new NoSuchEntityException(__('Webhook queue item with id "%1" does not exist.', $queueId));
        }
        return $webhookQueue;
    }

    /**
     * @inheritDoc
     */
    public function delete(WebhookQueue $webhookQueue)
    {
        try {
            $this->resource->delete($webhookQueue);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(__(
                'Could not delete the webhook queue item: %1',
                $exception->getMessage()
            ));
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteById($queueId)
    {
        return $this->delete($this->getById($queueId));
    }

    /**
     * @inheritDoc
     */
    public function getOldestPendingByEventType($eventType)
    {
        $collection = $this->collectionFactory->create();
        $collection->addEventTypeFilter($eventType)
            ->getPendingItems()
            ->orderByOldest()
            ->setPageSize(1);

        return $collection->getFirstItem()->getQueueId() ? $collection->getFirstItem() : null;
    }

    /**
     * @inheritDoc
     */
    public function getNextToProcess($eventType)
    {
        // First try to get pending items
        $pendingItem = $this->getOldestPendingByEventType($eventType);
        
        if ($pendingItem) {
            return $pendingItem;
        }

        // If no pending items, try to get retryable items
        $collection = $this->collectionFactory->create();
        $collection->addEventTypeFilter($eventType)
            ->getRetryableItems()
            ->orderByOldest()
            ->setPageSize(1);

        return $collection->getFirstItem()->getQueueId() ? $collection->getFirstItem() : null;
    }

    /**
     * @inheritDoc
     */
    public function cleanupOldItems($daysOld = 30)
    {
        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getMainTable();
            
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));
            
            $deletedCount = $connection->delete(
                $table,
                [
                    'status IN (?)' => [WebhookQueue::STATUS_COMPLETED, WebhookQueue::STATUS_FAILED],
                    'created_at < ?' => $cutoffDate
                ]
            );

            $this->logger->info("Cleaned up {$deletedCount} old webhook queue items older than {$daysOld} days");
            
            return $deletedCount;
        } catch (\Exception $e) {
            $this->logger->error('Error cleaning up old webhook queue items: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @inheritDoc
     */
    public function getRetryableItems($eventType)
    {
        $collection = $this->collectionFactory->create();
        return $collection->addEventTypeFilter($eventType)
            ->getRetryableItems()
            ->orderByOldest();
    }
}
