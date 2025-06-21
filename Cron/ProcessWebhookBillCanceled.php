<?php

namespace Vindi\Payment\Cron;

use Psr\Log\LoggerInterface;
use Vindi\Payment\Helper\WebhookQueueManager;
use Vindi\Payment\Helper\WebHookHandlers\BillCanceled;
use Vindi\Payment\Model\WebhookQueue;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Process Bill Canceled Webhooks
 */
class ProcessWebhookBillCanceled extends AbstractWebhookProcessor
{
    /**
     * @var BillCanceled
     */
    private $billCanceledHandler;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param WebhookQueueManager $webhookQueueManager
     * @param LockManagerInterface $lockManager
     * @param BillCanceled $billCanceledHandler
     */
    public function __construct(
        LoggerInterface $logger,
        WebhookQueueManager $webhookQueueManager,
        LockManagerInterface $lockManager,
        BillCanceled $billCanceledHandler
    ) {
        parent::__construct($logger, $webhookQueueManager, $lockManager);
        $this->billCanceledHandler = $billCanceledHandler;
    }

    /**
     * @inheritDoc
     */
    protected function getEventType()
    {
        return WebhookQueue::EVENT_BILL_CANCELED;
    }

    /**
     * @inheritDoc
     */
    protected function getLockName()
    {
        return 'vindi_webhook_bill_canceled_processor';
    }

    /**
     * @inheritDoc
     */
    protected function processWebhook(WebhookQueue $webhookQueue)
    {
        $payload = $webhookQueue->getDecodedPayload();
        
        if (!$payload) {
            $this->logger->error('Invalid payload for bill_canceled webhook queue ID: ' . $webhookQueue->getQueueId());
            return false;
        }

        // Call the original handler
        return $this->billCanceledHandler->billCanceled($payload);
    }
}
