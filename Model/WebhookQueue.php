<?php

namespace Vindi\Payment\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Webhook Queue Model
 */
class WebhookQueue extends AbstractModel
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_RETRYING = 'retrying';

    const PRIORITY_HIGH = 'high';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_LOW = 'low';

    const EVENT_BILL_CREATED = 'bill_created';
    const EVENT_BILL_PAID = 'bill_paid';
    const EVENT_CHARGE_REJECTED = 'charge_rejected';
    const EVENT_CHARGE_REFUNDED = 'charge_refunded';
    const EVENT_BILL_CANCELED = 'bill_canceled';
    const EVENT_SUBSCRIPTION_CREATED = 'subscription_created';
    const EVENT_SUBSCRIPTION_CANCELED = 'subscription_canceled';
    const EVENT_SUBSCRIPTION_REACTIVATED = 'subscription_reactivated';

    /**
     * Initialize resource model
     */
    protected function _construct()
    {
        $this->_init(\Vindi\Payment\Model\ResourceModel\WebhookQueue::class);
    }

    /**
     * Get Queue ID
     *
     * @return int|null
     */
    public function getQueueId()
    {
        return $this->getData('queue_id');
    }

    /**
     * Set Queue ID
     *
     * @param int $queueId
     * @return $this
     */
    public function setQueueId($queueId)
    {
        return $this->setData('queue_id', $queueId);
    }

    /**
     * Get Event Type
     *
     * @return string|null
     */
    public function getEventType()
    {
        return $this->getData('event_type');
    }

    /**
     * Set Event Type
     *
     * @param string $eventType
     * @return $this
     */
    public function setEventType($eventType)
    {
        return $this->setData('event_type', $eventType);
    }

    /**
     * Get Payload
     *
     * @return string|null
     */
    public function getPayload()
    {
        return $this->getData('payload');
    }

    /**
     * Set Payload
     *
     * @param string $payload
     * @return $this
     */
    public function setPayload($payload)
    {
        return $this->setData('payload', $payload);
    }

    /**
     * Get Status
     *
     * @return string|null
     */
    public function getStatus()
    {
        return $this->getData('status');
    }

    /**
     * Set Status
     *
     * @param string $status
     * @return $this
     */
    public function setStatus($status)
    {
        return $this->setData('status', $status);
    }

    /**
     * Get Priority
     *
     * @return string|null
     */
    public function getPriority()
    {
        return $this->getData('priority');
    }

    /**
     * Set Priority
     *
     * @param string $priority
     * @return $this
     */
    public function setPriority($priority)
    {
        return $this->setData('priority', $priority);
    }

    /**
     * Get Retry Count
     *
     * @return int
     */
    public function getRetryCount()
    {
        return (int) $this->getData('retry_count');
    }

    /**
     * Set Retry Count
     *
     * @param int $retryCount
     * @return $this
     */
    public function setRetryCount($retryCount)
    {
        return $this->setData('retry_count', $retryCount);
    }

    /**
     * Get Max Retries
     *
     * @return int
     */
    public function getMaxRetries()
    {
        return (int) $this->getData('max_retries');
    }

    /**
     * Set Max Retries
     *
     * @param int $maxRetries
     * @return $this
     */
    public function setMaxRetries($maxRetries)
    {
        return $this->setData('max_retries', $maxRetries);
    }

    /**
     * Get Error Message
     *
     * @return string|null
     */
    public function getErrorMessage()
    {
        return $this->getData('error_message');
    }

    /**
     * Set Error Message
     *
     * @param string $errorMessage
     * @return $this
     */
    public function setErrorMessage($errorMessage)
    {
        return $this->setData('error_message', $errorMessage);
    }

    /**
     * Get Scheduled At
     *
     * @return string|null
     */
    public function getScheduledAt()
    {
        return $this->getData('scheduled_at');
    }

    /**
     * Set Scheduled At
     *
     * @param string $scheduledAt
     * @return $this
     */
    public function setScheduledAt($scheduledAt)
    {
        return $this->setData('scheduled_at', $scheduledAt);
    }

    /**
     * Get Processed At
     *
     * @return string|null
     */
    public function getProcessedAt()
    {
        return $this->getData('processed_at');
    }

    /**
     * Set Processed At
     *
     * @param string $processedAt
     * @return $this
     */
    public function setProcessedAt($processedAt)
    {
        return $this->setData('processed_at', $processedAt);
    }

    /**
     * Get Created At
     *
     * @return string|null
     */
    public function getCreatedAt()
    {
        return $this->getData('created_at');
    }

    /**
     * Set Created At
     *
     * @param string $createdAt
     * @return $this
     */
    public function setCreatedAt($createdAt)
    {
        return $this->setData('created_at', $createdAt);
    }

    /**
     * Get Updated At
     *
     * @return string|null
     */
    public function getUpdatedAt()
    {
        return $this->getData('updated_at');
    }

    /**
     * Set Updated At
     *
     * @param string $updatedAt
     * @return $this
     */
    public function setUpdatedAt($updatedAt)
    {
        return $this->setData('updated_at', $updatedAt);
    }

    /**
     * Increment retry count
     *
     * @return $this
     */
    public function incrementRetryCount()
    {
        $this->setRetryCount($this->getRetryCount() + 1);
        return $this;
    }

    /**
     * Check if can retry
     *
     * @return bool
     */
    public function canRetry()
    {
        return $this->getRetryCount() < $this->getMaxRetries();
    }

    /**
     * Mark as failed
     *
     * @param string $errorMessage
     * @return $this
     */
    public function markAsFailed($errorMessage = null)
    {
        $this->setStatus(self::STATUS_FAILED);
        if ($errorMessage) {
            $this->setErrorMessage($errorMessage);
        }
        return $this;
    }

    /**
     * Mark as completed
     *
     * @return $this
     */
    public function markAsCompleted()
    {
        $this->setStatus(self::STATUS_COMPLETED);
        $this->setProcessedAt(date('Y-m-d H:i:s'));
        return $this;
    }

    /**
     * Mark as processing
     *
     * @return $this
     */
    public function markAsProcessing()
    {
        $this->setStatus(self::STATUS_PROCESSING);
        return $this;
    }

    /**
     * Get decoded payload
     *
     * @return array|null
     */
    public function getDecodedPayload()
    {
        $payload = $this->getPayload();
        if ($payload) {
            return json_decode($payload, true);
        }
        return null;
    }

    /**
     * Set payload from array
     *
     * @param array $data
     * @return $this
     */
    public function setPayloadFromArray(array $data)
    {
        return $this->setPayload(json_encode($data));
    }
}
