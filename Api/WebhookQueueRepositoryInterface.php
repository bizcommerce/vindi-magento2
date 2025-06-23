<?php

namespace Vindi\Payment\Api;

use Vindi\Payment\Model\WebhookQueue;

/**
 * Interface WebhookQueueRepositoryInterface
 * @package Vindi\Payment\Api
 */
interface WebhookQueueRepositoryInterface
{
    /**
     * Save webhook queue item
     *
     * @param WebhookQueue $webhookQueue
     * @return WebhookQueue
     */
    public function save(WebhookQueue $webhookQueue);

    /**
     * Get webhook queue item by ID
     *
     * @param int $id
     * @return WebhookQueue
     */
    public function getById($id);

    /**
     * Delete webhook queue item
     *
     * @param WebhookQueue $webhookQueue
     * @return bool
     */
    public function delete(WebhookQueue $webhookQueue);

    /**
     * Delete webhook queue item by ID
     *
     * @param int $id
     * @return bool
     */
    public function deleteById($id);
}
