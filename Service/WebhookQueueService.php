<?php

namespace Vindi\Payment\Service;

use Vindi\Payment\Model\WebhookQueueFactory;
use Vindi\Payment\Model\ResourceModel\WebhookQueue as WebhookQueueResource;
use Vindi\Payment\Logger\Logger;

/**
 * Class WebhookQueueService
 * Simple service to manage webhook queue for multimethod payments only
 */
class WebhookQueueService
{
    /**
     * @var WebhookQueueFactory
     */
    private $webhookQueueFactory;

    /**
     * @var WebhookQueueResource
     */
    private $webhookQueueResource;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * WebhookQueueService constructor.
     *
     * @param WebhookQueueFactory $webhookQueueFactory
     * @param WebhookQueueResource $webhookQueueResource
     * @param Logger $logger
     */
    public function __construct(
        WebhookQueueFactory $webhookQueueFactory,
        WebhookQueueResource $webhookQueueResource,
        Logger $logger
    ) {
        $this->webhookQueueFactory = $webhookQueueFactory;
        $this->webhookQueueResource = $webhookQueueResource;
        $this->logger = $logger;
    }

    /**
     * Add multimethod invoice creation to queue
     *
     * @param array $billData
     * @param string $orderIncrementId
     * @param string $billId
     * @return bool
     */
    public function addMultimethodInvoiceCreation(array $billData, string $orderIncrementId, string $billId)
    {
        try {
            $webhookQueue = $this->webhookQueueFactory->create();
            
            $webhookQueue->setData([
                'event_type' => 'bill_paid_multimethod',
                'payload' => json_encode(['bill' => $billData, 'order_increment_id' => $orderIncrementId, 'bill_id' => $billId]),
                'status' => 'pending',
                'priority' => 'high',
                'retry_count' => 0,
                'max_retries' => 3
            ]);

            $this->webhookQueueResource->save($webhookQueue);

            $this->logger->info('Added multimethod invoice creation to webhook queue', [
                'queue_id' => $webhookQueue->getQueueId(),
                'order_id' => $orderIncrementId,
                'bill_id' => $billId
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Failed to add multimethod invoice to queue: ' . $e->getMessage());
            return false;
        }
    }
}
