<?php

namespace Vindi\Payment\Cron;

use Psr\Log\LoggerInterface;
use Vindi\Payment\Helper\WebhookQueueManager;
use Vindi\Payment\Helper\WebHookHandlers\ChargeRejected;
use Vindi\Payment\Model\WebhookQueue;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Process Charge Rejected Webhooks
 */
class ProcessWebhookChargeRejected extends AbstractWebhookProcessor
{
    /**
     * @var ChargeRejected
     */
    private $chargeRejectedHandler;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param WebhookQueueManager $webhookQueueManager
     * @param LockManagerInterface $lockManager
     * @param ChargeRejected $chargeRejectedHandler
     */
    public function __construct(
        LoggerInterface $logger,
        WebhookQueueManager $webhookQueueManager,
        LockManagerInterface $lockManager,
        ChargeRejected $chargeRejectedHandler
    ) {
        parent::__construct($logger, $webhookQueueManager, $lockManager);
        $this->chargeRejectedHandler = $chargeRejectedHandler;
    }

    /**
     * @inheritDoc
     */
    protected function getEventType()
    {
        return WebhookQueue::EVENT_CHARGE_REJECTED;
    }

    /**
     * @inheritDoc
     */
    protected function getLockName()
    {
        return 'vindi_webhook_charge_rejected_processor';
    }

    /**
     * @inheritDoc
     */
    protected function processWebhook(WebhookQueue $webhookQueue)
    {
        $payload = $webhookQueue->getDecodedPayload();
        
        if (!$payload) {
            $this->logger->error('Invalid payload for charge_rejected webhook queue ID: ' . $webhookQueue->getQueueId());
            return false;
        }

        // Call the original handler
        return $this->chargeRejectedHandler->chargeRejected($payload);
    }
}
