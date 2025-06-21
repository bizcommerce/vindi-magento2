<?php

namespace Vindi\Payment\Cron;

use Psr\Log\LoggerInterface;
use Vindi\Payment\Helper\WebhookQueueManager;
use Vindi\Payment\Model\WebhookQueue;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Abstract Base Class for Webhook Processors
 */
abstract class AbstractWebhookProcessor
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var WebhookQueueManager
     */
    protected $webhookQueueManager;

    /**
     * @var LockManagerInterface
     */
    protected $lockManager;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param WebhookQueueManager $webhookQueueManager
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        LoggerInterface $logger,
        WebhookQueueManager $webhookQueueManager,
        LockManagerInterface $lockManager
    ) {
        $this->logger = $logger;
        $this->webhookQueueManager = $webhookQueueManager;
        $this->lockManager = $lockManager;
    }

    /**
     * Execute webhook processing
     */
    public function execute()
    {
        $lockName = $this->getLockName();
        
        if (!$this->lockManager->lock($lockName)) {
            $this->logger->info("The job {$lockName} is already running.");
            return;
        }

        try {
            $eventType = $this->getEventType();
            $webhookQueue = $this->webhookQueueManager->getNextToProcess($eventType);

            if (!$webhookQueue) {
                $this->logger->debug("No pending {$eventType} webhook items to process.");
                return;
            }

            $this->logger->info("Processing {$eventType} webhook queue item ID: " . $webhookQueue->getQueueId());

            // Mark as processing
            $this->webhookQueueManager->markAsProcessing($webhookQueue);

            // Process the webhook
            $result = $this->processWebhook($webhookQueue);

            if ($result) {
                $this->webhookQueueManager->markAsCompleted($webhookQueue);
                $this->logger->info("Successfully processed {$eventType} webhook queue item ID: " . $webhookQueue->getQueueId());
            } else {
                $this->webhookQueueManager->markAsFailedOrRetry(
                    $webhookQueue,
                    "Webhook processing returned false"
                );
            }

        } catch (\Exception $e) {
            $errorMessage = "Error processing {$this->getEventType()} webhook: " . $e->getMessage();
            $this->logger->error($errorMessage);

            if (isset($webhookQueue)) {
                $this->webhookQueueManager->markAsFailedOrRetry($webhookQueue, $errorMessage);
            }
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * Get event type for this processor
     *
     * @return string
     */
    abstract protected function getEventType();

    /**
     * Get lock name for this processor
     *
     * @return string
     */
    abstract protected function getLockName();

    /**
     * Process specific webhook
     *
     * @param WebhookQueue $webhookQueue
     * @return bool
     */
    abstract protected function processWebhook(WebhookQueue $webhookQueue);
}
