<?php

namespace Vindi\Payment\Cron;

use Psr\Log\LoggerInterface;
use Vindi\Payment\Helper\WebhookQueueManager;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Clean Old Webhook Queue Items
 */
class CleanWebhookQueue
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var WebhookQueueManager
     */
    private $webhookQueueManager;

    /**
     * @var LockManagerInterface
     */
    private $lockManager;

    /**
     * Lock name for this cron job
     */
    private const LOCK_NAME = 'vindi_webhook_queue_cleanup';

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
     * Execute cleanup
     */
    public function execute()
    {
        if (!$this->lockManager->lock(self::LOCK_NAME)) {
            $this->logger->info('Webhook queue cleanup job is already running.');
            return;
        }

        try {
            $this->logger->info('Starting webhook queue cleanup...');

            // Clean up items older than 30 days
            $deletedCount = $this->webhookQueueManager->cleanupOldItems(30);

            $this->logger->info("Webhook queue cleanup completed. Deleted {$deletedCount} old items.");

        } catch (\Exception $e) {
            $this->logger->error('Error during webhook queue cleanup: ' . $e->getMessage());
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }
}
