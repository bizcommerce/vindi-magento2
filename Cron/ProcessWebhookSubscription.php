<?php

namespace Vindi\Payment\Cron;

use Psr\Log\LoggerInterface;
use Vindi\Payment\Helper\WebhookQueueManager;
use Vindi\Payment\Helper\WebHookHandlers\Subscription;
use Vindi\Payment\Model\WebhookQueue;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Process Subscription Webhooks (created, canceled, reactivated)
 */
class ProcessWebhookSubscription extends AbstractWebhookProcessor
{
    /**
     * @var Subscription
     */
    private $subscriptionHandler;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param WebhookQueueManager $webhookQueueManager
     * @param LockManagerInterface $lockManager
     * @param Subscription $subscriptionHandler
     */
    public function __construct(
        LoggerInterface $logger,
        WebhookQueueManager $webhookQueueManager,
        LockManagerInterface $lockManager,
        Subscription $subscriptionHandler
    ) {
        parent::__construct($logger, $webhookQueueManager, $lockManager);
        $this->subscriptionHandler = $subscriptionHandler;
    }

    /**
     * @inheritDoc
     */
    protected function getEventType()
    {
        // This processor handles multiple subscription events
        return 'subscription';
    }

    /**
     * @inheritDoc
     */
    protected function getLockName()
    {
        return 'vindi_webhook_subscription_processor';
    }

    /**
     * Get next item to process (override to handle multiple event types)
     */
    public function execute()
    {
        $lockName = $this->getLockName();
        
        if (!$this->lockManager->lock($lockName)) {
            $this->logger->info("The job {$lockName} is already running.");
            return;
        }

        try {
            $eventTypes = [
                WebhookQueue::EVENT_SUBSCRIPTION_CREATED,
                WebhookQueue::EVENT_SUBSCRIPTION_CANCELED,
                WebhookQueue::EVENT_SUBSCRIPTION_REACTIVATED
            ];

            foreach ($eventTypes as $eventType) {
                $webhookQueue = $this->webhookQueueManager->getNextToProcess($eventType);

                if ($webhookQueue) {
                    $this->logger->info("Processing {$eventType} webhook queue item ID: " . $webhookQueue->getQueueId());

                    // Mark as processing
                    $this->webhookQueueManager->markAsProcessing($webhookQueue);

                    // Process the webhook
                    $result = $this->processSpecificWebhook($webhookQueue, $eventType);

                    if ($result) {
                        $this->webhookQueueManager->markAsCompleted($webhookQueue);
                        $this->logger->info("Successfully processed {$eventType} webhook queue item ID: " . $webhookQueue->getQueueId());
                    } else {
                        $this->webhookQueueManager->markAsFailedOrRetry(
                            $webhookQueue,
                            "Webhook processing returned false"
                        );
                    }

                    // Process only one item per execution to avoid timeout
                    break;
                }
            }

        } catch (\Exception $e) {
            $errorMessage = "Error processing subscription webhook: " . $e->getMessage();
            $this->logger->error($errorMessage);

            if (isset($webhookQueue)) {
                $this->webhookQueueManager->markAsFailedOrRetry($webhookQueue, $errorMessage);
            }
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * @inheritDoc
     */
    protected function processWebhook(WebhookQueue $webhookQueue)
    {
        return $this->processSpecificWebhook($webhookQueue, $webhookQueue->getEventType());
    }

    /**
     * Process specific subscription webhook
     *
     * @param WebhookQueue $webhookQueue
     * @param string $eventType
     * @return bool
     */
    private function processSpecificWebhook(WebhookQueue $webhookQueue, $eventType)
    {
        $payload = $webhookQueue->getDecodedPayload();
        
        if (!$payload) {
            $this->logger->error("Invalid payload for {$eventType} webhook queue ID: " . $webhookQueue->getQueueId());
            return false;
        }

        // Call the appropriate handler method
        switch ($eventType) {
            case WebhookQueue::EVENT_SUBSCRIPTION_CREATED:
                return $this->subscriptionHandler->created($payload);
            case WebhookQueue::EVENT_SUBSCRIPTION_CANCELED:
                return $this->subscriptionHandler->canceled($payload);
            case WebhookQueue::EVENT_SUBSCRIPTION_REACTIVATED:
                return $this->subscriptionHandler->reactivated($payload);
            default:
                $this->logger->error("Unknown subscription event type: {$eventType}");
                return false;
        }
    }
}
