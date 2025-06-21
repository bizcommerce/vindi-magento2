<?php

namespace Vindi\Payment\Cron;

use Psr\Log\LoggerInterface;
use Vindi\Payment\Helper\WebhookQueueManager;
use Vindi\Payment\Helper\WebHookHandlers\BillCreated;
use Vindi\Payment\Model\WebhookQueue;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Process Bill Created Webhooks
 */
class ProcessWebhookBillCreated extends AbstractWebhookProcessor
{
    /**
     * @var BillCreated
     */
    private $billCreatedHandler;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param WebhookQueueManager $webhookQueueManager
     * @param LockManagerInterface $lockManager
     * @param BillCreated $billCreatedHandler
     */
    public function __construct(
        LoggerInterface $logger,
        WebhookQueueManager $webhookQueueManager,
        LockManagerInterface $lockManager,
        BillCreated $billCreatedHandler
    ) {
        parent::__construct($logger, $webhookQueueManager, $lockManager);
        $this->billCreatedHandler = $billCreatedHandler;
    }

    /**
     * @inheritDoc
     */
    protected function getEventType()
    {
        return WebhookQueue::EVENT_BILL_CREATED;
    }

    /**
     * @inheritDoc
     */
    protected function getLockName()
    {
        return 'vindi_webhook_bill_created_processor';
    }

    /**
     * @inheritDoc
     */
    protected function processWebhook(WebhookQueue $webhookQueue)
    {
        $payload = $webhookQueue->getDecodedPayload();
        
        if (!$payload) {
            $this->logger->error('Invalid payload for bill_created webhook queue ID: ' . $webhookQueue->getQueueId());
            return false;
        }

        // Call the original handler
        return $this->billCreatedHandler->billCreated($payload);
    }
}
