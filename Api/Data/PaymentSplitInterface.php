<?php

namespace Vindi\Payment\Api\Data;

interface PaymentSplitInterface
{
    const ENTITY_ID = 'entity_id';
    const ORDER_ID = 'order_id';
    const ORDER_INCREMENT_ID = 'order_increment_id';
    const PAYMENT_METHOD = 'payment_method';
    const AMOUNT_CREDIT = 'amount_credit';
    const AMOUNT_PIX = 'amount_pix';
    const TOTAL_AMOUNT = 'total_amount';
    const STATUS_CREDIT = 'status_credit';
    const STATUS_PIX = 'status_pix';
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    public function getId();
    public function setId($id);

    public function getOrderId();
    public function setOrderId($orderId);

    public function getOrderIncrementId();
    public function setOrderIncrementId($orderIncrementId);

    public function getPaymentMethod();
    public function setPaymentMethod($paymentMethod);

    public function getAmountCredit();
    public function setAmountCredit($amountCredit);

    public function getAmountPix();
    public function setAmountPix($amountPix);

    public function getTotalAmount();
    public function setTotalAmount($totalAmount);

    public function getStatusCredit();
    public function setStatusCredit($statusCredit);

    public function getStatusPix();
    public function setStatusPix($statusPix);

    public function getCreatedAt();
    public function setCreatedAt($createdAt);

    public function getUpdatedAt();
    public function setUpdatedAt($updatedAt);
}
