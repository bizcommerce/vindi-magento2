<?php

namespace Vindi\Payment\Model;

use Magento\Framework\Model\AbstractModel;
use Vindi\Payment\Api\Data\PaymentSplitInterface;

/**
 * Payment Split Model
 */
class PaymentSplit extends AbstractModel implements PaymentSplitInterface
{
    /**
     * Initialize resource model.
     */
    protected function _construct()
    {
        $this->_init(\Vindi\Payment\Model\ResourceModel\PaymentSplit::class);
    }

    /**
     * Get entity ID.
     *
     * @return mixed
     */
    public function getId()
    {
        return $this->getData(self::ENTITY_ID);
    }

    /**
     * Set entity ID.
     *
     * @param mixed $id
     * @return $this
     */
    public function setId($id)
    {
        return $this->setData(self::ENTITY_ID, $id);
    }

    /**
     * Get order ID.
     *
     * @return mixed
     */
    public function getOrderId()
    {
        return $this->getData(self::ORDER_ID);
    }

    /**
     * Set order ID.
     *
     * @param mixed $orderId
     * @return $this
     */
    public function setOrderId($orderId)
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    /**
     * Get order increment ID.
     *
     * @return mixed
     */
    public function getOrderIncrementId()
    {
        return $this->getData(self::ORDER_INCREMENT_ID);
    }

    /**
     * Set order increment ID.
     *
     * @param mixed $orderIncrementId
     * @return $this
     */
    public function setOrderIncrementId($orderIncrementId)
    {
        return $this->setData(self::ORDER_INCREMENT_ID, $orderIncrementId);
    }

    /**
     * Get payment method.
     *
     * @return mixed
     */
    public function getPaymentMethod()
    {
        return $this->getData(self::PAYMENT_METHOD);
    }

    /**
     * Set payment method.
     *
     * @param mixed $paymentMethod
     * @return $this
     */
    public function setPaymentMethod($paymentMethod)
    {
        return $this->setData(self::PAYMENT_METHOD, $paymentMethod);
    }

    /**
     * Get amount.
     *
     * @return mixed
     */
    public function getAmount()
    {
        return $this->getData(self::AMOUNT);
    }

    /**
     * Set amount.
     *
     * @param mixed $amount
     * @return $this
     */
    public function setAmount($amount)
    {
        return $this->setData(self::AMOUNT, $amount);
    }

    /**
     * Get total amount.
     *
     * @return mixed
     */
    public function getTotalAmount()
    {
        return $this->getData(self::TOTAL_AMOUNT);
    }

    /**
     * Set total amount.
     *
     * @param mixed $totalAmount
     * @return $this
     */
    public function setTotalAmount($totalAmount)
    {
        return $this->setData(self::TOTAL_AMOUNT, $totalAmount);
    }

    /**
     * Get status.
     *
     * @return mixed
     */
    public function getStatus()
    {
        return $this->getData(self::STATUS);
    }

    /**
     * Set status.
     *
     * @param mixed $status
     * @return $this
     */
    public function setStatus($status)
    {
        return $this->setData(self::STATUS, $status);
    }

    /**
     * Get creation time.
     *
     * @return mixed
     */
    public function getCreatedAt()
    {
        return $this->getData(self::CREATED_AT);
    }

    /**
     * Set creation time.
     *
     * @param mixed $createdAt
     * @return $this
     */
    public function setCreatedAt($createdAt)
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    /**
     * Get update time.
     *
     * @return mixed
     */
    public function getUpdatedAt()
    {
        return $this->getData(self::UPDATED_AT);
    }

    /**
     * Set update time.
     *
     * @param mixed $updatedAt
     * @return $this
     */
    public function setUpdatedAt($updatedAt)
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }
}
