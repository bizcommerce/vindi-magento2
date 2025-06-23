<?php

namespace Vindi\Payment\Api\Data;

/**
 * Interface WebhookQueueInterface
 * Data interface for webhook queue
 */
interface WebhookQueueInterface
{
    const QUEUE_ID = 'queue_id';
    const EVENT_TYPE = 'event_type';
    const PAYLOAD = 'payload';
    const STATUS = 'status';
    const PRIORITY = 'priority';
    const RETRY_COUNT = 'retry_count';
    const MAX_RETRIES = 'max_retries';
    const ERROR_MESSAGE = 'error_message';
    const SCHEDULED_AT = 'scheduled_at';
    const PROCESSED_AT = 'processed_at';
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    const PRIORITY_HIGH = 'high';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_LOW = 'low';

    /**
     * Get queue ID
     *
     * @return int|null
     */
    public function getQueueId();

    /**
     * Set queue ID
     *
     * @param int $queueId
     * @return $this
     */
    public function setQueueId($queueId);

    /**
     * Get event type
     *
     * @return string
     */
    public function getEventType();

    /**
     * Set event type
     *
     * @param string $eventType
     * @return $this
     */
    public function setEventType($eventType);

    /**
     * Get payload
     *
     * @return string
     */
    public function getPayload();

    /**
     * Set payload
     *
     * @param string $payload
     * @return $this
     */
    public function setPayload($payload);

    /**
     * Get status
     *
     * @return string
     */
    public function getStatus();

    /**
     * Set status
     *
     * @param string $status
     * @return $this
     */
    public function setStatus($status);

    /**
     * Get priority
     *
     * @return string
     */
    public function getPriority();

    /**
     * Set priority
     *
     * @param string $priority
     * @return $this
     */
    public function setPriority($priority);

    /**
     * Get retry count
     *
     * @return int
     */
    public function getRetryCount();

    /**
     * Set retry count
     *
     * @param int $retryCount
     * @return $this
     */
    public function setRetryCount($retryCount);

    /**
     * Get max retries
     *
     * @return int
     */
    public function getMaxRetries();

    /**
     * Set max retries
     *
     * @param int $maxRetries
     * @return $this
     */
    public function setMaxRetries($maxRetries);

    /**
     * Get error message
     *
     * @return string|null
     */
    public function getErrorMessage();

    /**
     * Set error message
     *
     * @param string $errorMessage
     * @return $this
     */
    public function setErrorMessage($errorMessage);

    /**
     * Get scheduled at
     *
     * @return string|null
     */
    public function getScheduledAt();

    /**
     * Set scheduled at
     *
     * @param string $scheduledAt
     * @return $this
     */
    public function setScheduledAt($scheduledAt);

    /**
     * Get processed at
     *
     * @return string|null
     */
    public function getProcessedAt();

    /**
     * Set processed at
     *
     * @param string $processedAt
     * @return $this
     */
    public function setProcessedAt($processedAt);

    /**
     * Get created at
     *
     * @return string
     */
    public function getCreatedAt();

    /**
     * Set created at
     *
     * @param string $createdAt
     * @return $this
     */
    public function setCreatedAt($createdAt);

    /**
     * Get updated at
     *
     * @return string
     */
    public function getUpdatedAt();

    /**
     * Set updated at
     *
     * @param string $updatedAt
     * @return $this
     */
    public function setUpdatedAt($updatedAt);
}
