<?php

namespace Vindi\Payment\Model;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Vindi\Payment\Api\WebhookQueueRepositoryInterface;
use Vindi\Payment\Model\WebhookQueue;
use Vindi\Payment\Model\WebhookQueueFactory;
use Vindi\Payment\Model\ResourceModel\WebhookQueue as WebhookQueueResource;

/**
 * Webhook Queue Repository
 */
class WebhookQueueRepository implements WebhookQueueRepositoryInterface
{
    /**
     * @var WebhookQueueResource
     */
    private $resource;

    /**
     * @var WebhookQueueFactory
     */
    private $webhookQueueFactory;

    /**
     * Constructor
     *
     * @param WebhookQueueResource $resource
     * @param WebhookQueueFactory $webhookQueueFactory
     */
    public function __construct(
        WebhookQueueResource $resource,
        WebhookQueueFactory $webhookQueueFactory
    ) {
        $this->resource = $resource;
        $this->webhookQueueFactory = $webhookQueueFactory;
    }

    /**
     * @inheritDoc
     */
    public function save(WebhookQueue $webhookQueue)
    {
        try {
            $this->resource->save($webhookQueue);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__(
                'Could not save the webhook queue item: %1',
                $exception->getMessage()
            ));
        }
        return $webhookQueue;
    }

    /**
     * @inheritDoc
     */
    public function getById($queueId)
    {
        $webhookQueue = $this->webhookQueueFactory->create();
        $this->resource->load($webhookQueue, $queueId);
        if (!$webhookQueue->getQueueId()) {
            throw new NoSuchEntityException(__('Webhook queue item with id "%1" does not exist.', $queueId));
        }
        return $webhookQueue;
    }

    /**
     * @inheritDoc
     */
    public function delete(WebhookQueue $webhookQueue)
    {
        try {
            $this->resource->delete($webhookQueue);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(__(
                'Could not delete the webhook queue item: %1',
                $exception->getMessage()
            ));
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteById($queueId)
    {
        return $this->delete($this->getById($queueId));
    }
}
