<?php

declare(strict_types=1);

/**
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Vindi
 * @package     Vindi_Payment
 *
 *
 */

namespace Vindi\Payment\Model;

use Magento\Framework\Model\AbstractModel;
use Vindi\Payment\Api\Data\WebhookQueueInterface;

/**
 * WebhookQueue Model
 */
class WebhookQueue extends AbstractModel implements WebhookQueueInterface
{
    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('Vindi\Payment\Model\ResourceModel\WebhookQueue');
    }

    /**
     * @inheritDoc
     */
    public function getQueueId()
    {
        return $this->getData(self::QUEUE_ID);
    }

    /**
     * @inheritDoc
     */
    public function setQueueId($queueId)
    {
        return $this->setData(self::QUEUE_ID, $queueId);
    }

    /**
     * @inheritDoc
     */
    public function getEventType()
    {
        return $this->getData(self::EVENT_TYPE);
    }

    /**
     * @inheritDoc
     */
    public function setEventType($eventType)
    {
        return $this->setData(self::EVENT_TYPE, $eventType);
    }

    /**
     * @inheritDoc
     */
    public function getPayload()
    {
        return $this->getData(self::PAYLOAD);
    }

    /**
     * @inheritDoc
     */
    public function setPayload($payload)
    {
        return $this->setData(self::PAYLOAD, $payload);
    }

    /**
     * @inheritDoc
     */
    public function getStatus()
    {
        return $this->getData(self::STATUS);
    }

    /**
     * @inheritDoc
     */
    public function setStatus($status)
    {
        return $this->setData(self::STATUS, $status);
    }

    /**
     * @inheritDoc
     */
    public function getPriority()
    {
        return $this->getData(self::PRIORITY);
    }

    /**
     * @inheritDoc
     */
    public function setPriority($priority)
    {
        return $this->setData(self::PRIORITY, $priority);
    }

    /**
     * @inheritDoc
     */
    public function getRetryCount()
    {
        return $this->getData(self::RETRY_COUNT);
    }

    /**
     * @inheritDoc
     */
    public function setRetryCount($retryCount)
    {
        return $this->setData(self::RETRY_COUNT, $retryCount);
    }

    /**
     * @inheritDoc
     */
    public function getMaxRetries()
    {
        return $this->getData(self::MAX_RETRIES);
    }

    /**
     * @inheritDoc
     */
    public function setMaxRetries($maxRetries)
    {
        return $this->setData(self::MAX_RETRIES, $maxRetries);
    }

    /**
     * @inheritDoc
     */
    public function getErrorMessage()
    {
        return $this->getData(self::ERROR_MESSAGE);
    }

    /**
     * @inheritDoc
     */
    public function setErrorMessage($errorMessage)
    {
        return $this->setData(self::ERROR_MESSAGE, $errorMessage);
    }

    /**
     * @inheritDoc
     */
    public function getScheduledAt()
    {
        return $this->getData(self::SCHEDULED_AT);
    }

    /**
     * @inheritDoc
     */
    public function setScheduledAt($scheduledAt)
    {
        return $this->setData(self::SCHEDULED_AT, $scheduledAt);
    }

    /**
     * @inheritDoc
     */
    public function getProcessedAt()
    {
        return $this->getData(self::PROCESSED_AT);
    }

    /**
     * @inheritDoc
     */
    public function setProcessedAt($processedAt)
    {
        return $this->setData(self::PROCESSED_AT, $processedAt);
    }

    /**
     * @inheritDoc
     */
    public function getCreatedAt()
    {
        return $this->getData(self::CREATED_AT);
    }

    /**
     * @inheritDoc
     */
    public function setCreatedAt($createdAt)
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    /**
     * @inheritDoc
     */
    public function getUpdatedAt()
    {
        return $this->getData(self::UPDATED_AT);
    }

    /**
     * @inheritDoc
     */
    public function setUpdatedAt($updatedAt)
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }
}
