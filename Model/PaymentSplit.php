<?php

namespace Vindi\Payment\Model;

use Magento\Framework\Model\AbstractModel;
use Vindi\Payment\Api\Data\PaymentSplitInterface;

class PaymentSplit extends AbstractModel implements PaymentSplitInterface
{
    protected function _construct()
    {
        $this->_init(\Vindi\Payment\Model\ResourceModel\PaymentSplit::class);
    }

    public function getId()
    {
        return $this->getData(self::ENTITY_ID);
    }

    public function setId($id)
    {
        return $this->setData(self::ENTITY_ID, $id);
    }

    public function getOrderId()
    {
        return $this->getData(self::ORDER_ID);
    }

    public function setOrderId($orderId)
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    public function getOrderIncrementId()
    {
        return $this->getData(self::ORDER_INCREMENT_ID);
    }

    public function setOrderIncrementId($orderIncrementId)
    {
        return $this->setData(self::ORDER_INCREMENT_ID, $orderIncrementId);
    }

    public function getPaymentMethod()
    {
        return $this->getData(self::PAYMENT_METHOD);
    }

    public function setPaymentMethod($paymentMethod)
    {
        return $this->setData(self::PAYMENT_METHOD, $paymentMethod);
    }

    public function getAmountCredit()
    {
        return $this->getData(self::AMOUNT_CREDIT);
    }

    public function setAmountCredit($amountCredit)
    {
        return $this->setData(self::AMOUNT_CREDIT, $amountCredit);
    }

    public function getAmountPix()
    {
        return $this->getData(self::AMOUNT_PIX);
    }

    public function setAmountPix($amountPix)
    {
        return $this->setData(self::AMOUNT_PIX, $amountPix);
    }

    public function getTotalAmount()
    {
        return $this->getData(self::TOTAL_AMOUNT);
    }

    public function setTotalAmount($totalAmount)
    {
        return $this->setData(self::TOTAL_AMOUNT, $totalAmount);
    }

    public function getStatusCredit()
    {
        return $this->getData(self::STATUS_CREDIT);
    }

    public function setStatusCredit($statusCredit)
    {
        return $this->setData(self::STATUS_CREDIT, $statusCredit);
    }

    public function getStatusPix()
    {
        return $this->getData(self::STATUS_PIX);
    }

    public function setStatusPix($statusPix)
    {
        return $this->setData(self::STATUS_PIX, $statusPix);
    }

    public function getCreatedAt()
    {
        return $this->getData(self::CREATED_AT);
    }

    public function setCreatedAt($createdAt)
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getUpdatedAt()
    {
        return $this->getData(self::UPDATED_AT);
    }

    public function setUpdatedAt($updatedAt)
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }
}
