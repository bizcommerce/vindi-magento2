<?php

namespace Vindi\Payment\Api\Data;

interface PaymentSplitInterface
{
    const ENTITY_ID = 'entity_id';
    const ORDER_ID = 'order_id';
    const ORDER_INCREMENT_ID = 'order_increment_id';
    const PAYMENT_METHOD = 'payment_method';
    const AMOUNT = 'amount';
    const TOTAL_AMOUNT = 'total_amount';
    const STATUS = 'status';
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    /**
     * Get entity ID.
     *
     * @return mixed
     */
    public function getId();

    /**
     * Set entity ID.
     *
     * @param mixed $id
     * @return $this
     */
    public function setId($id);

    /**
     * Get order ID.
     *
     * @return mixed
     */
    public function getOrderId();

    /**
     * Set order ID.
     *
     * @param mixed $orderId
     * @return $this
     */
    public function setOrderId($orderId);

    /**
     * Get order increment ID.
     *
     * @return mixed
     */
    public function getOrderIncrementId();

    /**
     * Set order increment ID.
     *
     * @param mixed $orderIncrementId
     * @return $this
     */
    public function setOrderIncrementId($orderIncrementId);

    /**
     * Get payment method.
     *
     * @return mixed
     */
    public function getPaymentMethod();

    /**
     * Set payment method.
     *
     * @param mixed $paymentMethod
     * @return $this
     */
    public function setPaymentMethod($paymentMethod);

    /**
     * Get amount.
     *
     * @return mixed
     */
    public function getAmount();

    /**
     * Set amount.
     *
     * @param mixed $amount
     * @return $this
     */
    public function setAmount($amount);

    /**
     * Get total amount.
     *
     * @return mixed
     */
    public function getTotalAmount();

    /**
     * Set total amount.
     *
     * @param mixed $totalAmount
     * @return $this
     */
    public function setTotalAmount($totalAmount);

    /**
     * Get status.
     *
     * @return mixed
     */
    public function getStatus();

    /**
     * Set status.
     *
     * @param mixed $status
     * @return $this
     */
    public function setStatus($status);

    /**
     * Get creation time.
     *
     * @return mixed
     */
    public function getCreatedAt();

    /**
     * Set creation time.
     *
     * @param mixed $createdAt
     * @return $this
     */
    public function setCreatedAt($createdAt);

    /**
     * Get update time.
     *
     * @return mixed
     */
    public function getUpdatedAt();

    /**
     * Set update time.
     *
     * @param mixed $updatedAt
     * @return $this
     */
    public function setUpdatedAt($updatedAt);
}
