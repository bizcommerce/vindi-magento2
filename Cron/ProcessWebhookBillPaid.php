<?php

namespace Vindi\Payment\Cron;

use Psr\Log\LoggerInterface;
use Vindi\Payment\Helper\WebhookQueueManager;
use Vindi\Payment\Helper\WebHookHandlers\BillPaid;
use Vindi\Payment\Model\WebhookQueue;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Process Bill Paid Webhooks
 */
class ProcessWebhookBillPaid extends AbstractWebhookProcessor
{
    /**
     * @var BillPaid
     */
    private $billPaidHandler;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param WebhookQueueManager $webhookQueueManager
     * @param LockManagerInterface $lockManager
     * @param BillPaid $billPaidHandler
     */
    public function __construct(
        LoggerInterface $logger,
        WebhookQueueManager $webhookQueueManager,
        LockManagerInterface $lockManager,
        BillPaid $billPaidHandler
    ) {
        parent::__construct($logger, $webhookQueueManager, $lockManager);
        $this->billPaidHandler = $billPaidHandler;
    }

    /**
     * @inheritDoc
     */
    protected function getEventType()
    {
        return WebhookQueue::EVENT_BILL_PAID;
    }

    /**
     * @inheritDoc
     */
    protected function getLockName()
    {
        return 'vindi_webhook_bill_paid_processor';
    }

    /**
     * @inheritDoc
     */
    protected function processWebhook(WebhookQueue $webhookQueue)
    {
        $payload = $webhookQueue->getDecodedPayload();
        
        if (!$payload) {
            $this->logger->error('Invalid payload for bill_paid webhook queue ID: ' . $webhookQueue->getQueueId());
            return false;
        }

        // Call the original handler
        return $this->billPaidHandler->billPaid($payload);
    }
}
