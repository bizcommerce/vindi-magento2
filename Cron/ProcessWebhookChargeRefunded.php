<?php

namespace Vindi\Payment\Cron;

use Psr\Log\LoggerInterface;
use Vindi\Payment\Helper\WebhookQueueManager;
use Vindi\Payment\Helper\WebHookHandlers\ChargeRefunded;
use Vindi\Payment\Model\WebhookQueue;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Process Charge Refunded Webhooks
 */
class ProcessWebhookChargeRefunded extends AbstractWebhookProcessor
{
    /**
     * @var ChargeRefunded
     */
    private $chargeRefundedHandler;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param WebhookQueueManager $webhookQueueManager
     * @param LockManagerInterface $lockManager
     * @param ChargeRefunded $chargeRefundedHandler
     */
    public function __construct(
        LoggerInterface $logger,
        WebhookQueueManager $webhookQueueManager,
        LockManagerInterface $lockManager,
        ChargeRefunded $chargeRefundedHandler
    ) {
        parent::__construct($logger, $webhookQueueManager, $lockManager);
        $this->chargeRefundedHandler = $chargeRefundedHandler;
    }

    /**
     * @inheritDoc
     */
    protected function getEventType()
    {
        return WebhookQueue::EVENT_CHARGE_REFUNDED;
    }

    /**
     * @inheritDoc
     */
    protected function getLockName()
    {
        return 'vindi_webhook_charge_refunded_processor';
    }

    /**
     * @inheritDoc
     */
    protected function processWebhook(WebhookQueue $webhookQueue)
    {
        $payload = $webhookQueue->getDecodedPayload();
        
        if (!$payload) {
            $this->logger->error('Invalid payload for charge_refunded webhook queue ID: ' . $webhookQueue->getQueueId());
            return false;
        }

        // Call the original handler
        return $this->chargeRefundedHandler->chargeRefunded($payload);
    }
}
