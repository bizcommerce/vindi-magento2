<?php

namespace Vindi\Payment\Model;

use Magento\Framework\ObjectManagerInterface;

/**
 * Webhook Queue Factory
 */
class WebhookQueueFactory
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * Constructor
     *
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Create webhook queue instance
     *
     * @param array $data
     * @return WebhookQueue
     */
    public function create(array $data = [])
    {
        return $this->objectManager->create(WebhookQueue::class, $data);
    }
}
