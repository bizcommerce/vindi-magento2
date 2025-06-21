<?php

namespace Vindi\Payment\Helper;

use Vindi\Payment\Model\WebhookQueue;
use Vindi\Payment\Model\WebhookQueueFactory;
use Vindi\Payment\Api\WebhookQueueRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Webhook Queue Manager Helper
 */
class WebhookQueueManager
{
    /**
     * @var WebhookQueueFactory
     */
    private $webhookQueueFactory;

    /**
     * @var WebhookQueueRepositoryInterface
     */
    private $webhookQueueRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Constructor
     *
     * @param WebhookQueueFactory $webhookQueueFactory
     * @param WebhookQueueRepositoryInterface $webhookQueueRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        WebhookQueueFactory $webhookQueueFactory,
        WebhookQueueRepositoryInterface $webhookQueueRepository,
        LoggerInterface $logger
    ) {
        $this->webhookQueueFactory = $webhookQueueFactory;
        $this->webhookQueueRepository = $webhookQueueRepository;
        $this->logger = $logger;
    }

    /**
     * Add webhook to queue
     *
     * @param string $eventType
     * @param array $payload
     * @param string $priority
     * @return WebhookQueue
     */
    public function addToQueue($eventType, array $payload, $priority = WebhookQueue::PRIORITY_NORMAL)
    {
        try {
            $webhookQueue = $this->webhookQueueFactory->create();
            $webhookQueue->setEventType($eventType);
            $webhookQueue->setPayloadFromArray($payload);
            $webhookQueue->setPriority($priority);
            $webhookQueue->setStatus(WebhookQueue::STATUS_PENDING);
            $webhookQueue->setRetryCount(0);
            $webhookQueue->setMaxRetries($this->getMaxRetriesForEventType($eventType));

            $this->webhookQueueRepository->save($webhookQueue);

            $this->logger->info("Webhook {$eventType} added to queue with ID: " . $webhookQueue->getQueueId());

            return $webhookQueue;
        } catch (\Exception $e) {
            $this->logger->error("Failed to add webhook {$eventType} to queue: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get next item to process for specific event type
     *
     * @param string $eventType
     * @return WebhookQueue|null
     */
    public function getNextToProcess($eventType)
    {
        return $this->webhookQueueRepository->getNextToProcess($eventType);
    }

    /**
     * Mark item as processing
     *
     * @param WebhookQueue $webhookQueue
     * @return void
     */
    public function markAsProcessing(WebhookQueue $webhookQueue)
    {
        try {
            $webhookQueue->markAsProcessing();
            $this->webhookQueueRepository->save($webhookQueue);
        } catch (\Exception $e) {
            $this->logger->error("Failed to mark webhook queue item as processing: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mark item as completed
     *
     * @param WebhookQueue $webhookQueue
     * @return void
     */
    public function markAsCompleted(WebhookQueue $webhookQueue)
    {
        try {
            $webhookQueue->markAsCompleted();
            $this->webhookQueueRepository->save($webhookQueue);

            $this->logger->info("Webhook queue item {$webhookQueue->getQueueId()} completed successfully");
        } catch (\Exception $e) {
            $this->logger->error("Failed to mark webhook queue item as completed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mark item as failed or schedule for retry
     *
     * @param WebhookQueue $webhookQueue
     * @param string $errorMessage
     * @return void
     */
    public function markAsFailedOrRetry(WebhookQueue $webhookQueue, $errorMessage)
    {
        try {
            $webhookQueue->incrementRetryCount();
            $webhookQueue->setErrorMessage($errorMessage);

            if ($webhookQueue->canRetry()) {
                $webhookQueue->setStatus(WebhookQueue::STATUS_RETRYING);
                $webhookQueue->setScheduledAt($this->getRetryScheduleTime($webhookQueue->getRetryCount()));
                
                $this->logger->info("Webhook queue item {$webhookQueue->getQueueId()} scheduled for retry #{$webhookQueue->getRetryCount()}");
            } else {
                $webhookQueue->markAsFailed($errorMessage);
                
                $this->logger->error("Webhook queue item {$webhookQueue->getQueueId()} failed permanently after {$webhookQueue->getRetryCount()} attempts: {$errorMessage}");
            }

            $this->webhookQueueRepository->save($webhookQueue);
        } catch (\Exception $e) {
            $this->logger->error("Failed to mark webhook queue item as failed/retry: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get max retries for specific event type
     *
     * @param string $eventType
     * @return int
     */
    private function getMaxRetriesForEventType($eventType)
    {
        $maxRetries = [
            WebhookQueue::EVENT_BILL_CREATED => 5,
            WebhookQueue::EVENT_BILL_PAID => 5,
            WebhookQueue::EVENT_CHARGE_REJECTED => 3,
            WebhookQueue::EVENT_CHARGE_REFUNDED => 3,
            WebhookQueue::EVENT_BILL_CANCELED => 3,
            WebhookQueue::EVENT_SUBSCRIPTION_CREATED => 3,
            WebhookQueue::EVENT_SUBSCRIPTION_CANCELED => 3,
            WebhookQueue::EVENT_SUBSCRIPTION_REACTIVATED => 3,
        ];

        return $maxRetries[$eventType] ?? 3;
    }

    /**
     * Get retry schedule time based on attempt number
     *
     * @param int $attemptNumber
     * @return string
     */
    private function getRetryScheduleTime($attemptNumber)
    {
        // Exponential backoff: 1min, 5min, 15min, 30min, 60min
        $delayMinutes = [1, 5, 15, 30, 60];
        $delay = $delayMinutes[min($attemptNumber - 1, count($delayMinutes) - 1)];
        
        return date('Y-m-d H:i:s', strtotime("+{$delay} minutes"));
    }

    /**
     * Get priority for event type
     *
     * @param string $eventType
     * @return string
     */
    public function getPriorityForEventType($eventType)
    {
        $priorities = [
            WebhookQueue::EVENT_BILL_PAID => WebhookQueue::PRIORITY_HIGH,
            WebhookQueue::EVENT_CHARGE_REJECTED => WebhookQueue::PRIORITY_HIGH,
            WebhookQueue::EVENT_CHARGE_REFUNDED => WebhookQueue::PRIORITY_HIGH,
            WebhookQueue::EVENT_BILL_CANCELED => WebhookQueue::PRIORITY_HIGH,
            WebhookQueue::EVENT_BILL_CREATED => WebhookQueue::PRIORITY_NORMAL,
            WebhookQueue::EVENT_SUBSCRIPTION_CREATED => WebhookQueue::PRIORITY_NORMAL,
            WebhookQueue::EVENT_SUBSCRIPTION_CANCELED => WebhookQueue::PRIORITY_NORMAL,
            WebhookQueue::EVENT_SUBSCRIPTION_REACTIVATED => WebhookQueue::PRIORITY_LOW,
        ];

        return $priorities[$eventType] ?? WebhookQueue::PRIORITY_NORMAL;
    }

    /**
     * Clean up old webhook queue items
     *
     * @param int $daysOld
     * @return int
     */
    public function cleanupOldItems($daysOld = 30)
    {
        return $this->webhookQueueRepository->cleanupOldItems($daysOld);
    }
}
