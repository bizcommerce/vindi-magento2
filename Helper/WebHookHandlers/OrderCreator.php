<?php

namespace Vindi\Payment\Helper\WebHookHandlers;

use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Service\OrderService;
use Magento\Framework\Exception\LocalizedException;
use Vindi\Payment\Model\SubscriptionOrderRepository;
use Vindi\Payment\Model\SubscriptionOrderFactory;
use Vindi\Payment\Model\Payment\Bill as PaymentBill;

class OrderCreator
{
    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var SubscriptionOrderRepository
     */
    protected $subscriptionOrderRepository;

    /**
     * @var SubscriptionOrderFactory
     */
    protected $subscriptionOrderFactory;

    /**
     * @var OrderService
     */
    protected $orderService;

    /**
     * @var PaymentBill
     */
    protected $paymentBill;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * OrderCreator constructor.
     * @param OrderFactory $orderFactory
     * @param SubscriptionOrderRepository $subscriptionOrderRepository
     * @param SubscriptionOrderFactory $subscriptionOrderFactory
     * @param OrderService $orderService
     * @param PaymentBill $paymentBill
     * @param OrderRepository $orderRepository
     */
    public function __construct(
        OrderFactory $orderFactory,
        SubscriptionOrderRepository $subscriptionOrderRepository,
        SubscriptionOrderFactory $subscriptionOrderFactory,
        OrderService $orderService,
        PaymentBill $paymentBill,
        OrderRepository $orderRepository
    ) {
        $this->orderFactory = $orderFactory;
        $this->subscriptionOrderRepository = $subscriptionOrderRepository;
        $this->subscriptionOrderFactory = $subscriptionOrderFactory;
        $this->orderService = $orderService;
        $this->paymentBill = $paymentBill;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Create an order from bill data
     */
    public function createOrderFromBill($billData)
    {
        try {
            if (empty($billData['bill']) || empty($billData['bill']['subscription'])) {
                throw new LocalizedException(__('Invalid bill data structure.'));
            }

            $bill = $billData['bill'];
            $subscriptionId = $bill['subscription']['id'];
            $originalOrder = $this->getOrderFromSubscriptionId($subscriptionId);

            if ($originalOrder) {
                $newOrder = $this->replicateOrder($originalOrder, $bill);
                $this->orderRepository->save($newOrder);

                $this->registerSubscriptionOrder($newOrder, $subscriptionId);

                $this->updatePaymentDetails($newOrder, $billData);

                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Fetch original order using subscription ID
     */
    public function getOrderFromSubscriptionId($subscriptionId)
    {
        $subscriptionOrder = $this->subscriptionOrderRepository->getBySubscriptionId($subscriptionId);

        if ($subscriptionOrder) {
            return $this->orderFactory->create()->load($subscriptionOrder->getOrderId());
        }

        return null;
    }

    /**
     * Get all orders associated with a subscription ID
     */
    public function getOrdersBySubscriptionId($subscriptionId)
    {
        $subscriptionOrders = $this->subscriptionOrderRepository->getListBySubscriptionId($subscriptionId);

        $orders = [];
        foreach ($subscriptionOrders as $subscriptionOrder) {
            $orders[] = $this->orderFactory->create()->load($subscriptionOrder->getOrderId());
        }

        return $orders;
    }

    /**
     * Replicate an order from an existing order
     */
    protected function replicateOrder(Order $originalOrder, $billData)
    {
        $newOrder = clone $originalOrder;
        $newOrder->setId(null);
        $newOrder->setIncrementId(null);
        $newOrder->setVindiBillId($billData['id']);
        $newOrder->setVindiSubscriptionId($billData['subscription']['id']);
        $newOrder->setCreatedAt(null);
        $newOrder->setState(Order::STATE_NEW);
        $newOrder->setStatus('pending');

        $billingAddress = clone $originalOrder->getBillingAddress();
        $billingAddress->setId(null)->setParentId(null);
        $newOrder->setBillingAddress($billingAddress);

        if ($originalOrder->getShippingAddress()) {
            $shippingAddress = clone $originalOrder->getShippingAddress();
            $shippingAddress->setId(null)->setParentId(null);
            $newOrder->setShippingAddress($shippingAddress);
        }

        $newOrderItems = [];
        foreach ($originalOrder->getAllVisibleItems() as $originalItem) {
            $newItem = clone $originalItem;
            $newItem->setId(null)->setOrderId(null);
            $newOrderItems[] = $newItem;
        }
        $newOrder->setItems($newOrderItems);

        $originalPayment = $originalOrder->getPayment();
        $newPayment = clone $originalPayment;
        $newPayment->setId(null)->setOrderId(null);
        $newOrder->setPayment($newPayment);

        $newOrder->setTotalPaid(null);
        $newOrder->setBaseTotalPaid(null);
        $newOrder->setTotalDue($newOrder->getGrandTotal());
        $newOrder->setBaseTotalDue($newOrder->getBaseGrandTotal());

        $subtotal = 0;
        $grandTotal = 0;
        foreach ($newOrderItems as $item) {
            $subtotal += $item->getRowTotal();
            $grandTotal += $item->getRowTotal() + $item->getTaxAmount() - $item->getDiscountAmount();
        }

        $taxAmount = $originalOrder->getTaxAmount();
        $baseTaxAmount = $originalOrder->getBaseTaxAmount();
        $newOrder->setTaxAmount($taxAmount);
        $newOrder->setBaseTaxAmount($baseTaxAmount);

        $shippingAmount = $originalOrder->getShippingAmount();
        $baseShippingAmount = $originalOrder->getBaseShippingAmount();
        $newOrder->setShippingAmount($shippingAmount);
        $newOrder->setBaseShippingAmount($baseShippingAmount);

        $discountAmount = $originalOrder->getDiscountAmount();
        $baseDiscountAmount = $originalOrder->getBaseDiscountAmount();
        $newOrder->setDiscountAmount($discountAmount);
        $newOrder->setBaseDiscountAmount($baseDiscountAmount);

        $grandTotal += $taxAmount + $shippingAmount - $discountAmount;
        $newOrder->setSubtotal($subtotal);
        $newOrder->setBaseSubtotal($subtotal);
        $newOrder->setGrandTotal($grandTotal);
        $newOrder->setBaseGrandTotal($grandTotal);
        $newOrder->setTotalDue($grandTotal);
        $newOrder->setBaseTotalDue($grandTotal);

        return $newOrder;
    }

    /**
     * Register the new order in the subscription orders table
     */
    protected function registerSubscriptionOrder(Order $order, $subscriptionId)
    {
        try {
            $subscriptionOrder = $this->subscriptionOrderFactory->create();

            $subscriptionOrder->setOrderId($order->getId());
            $subscriptionOrder->setIncrementId($order->getIncrementId());
            $subscriptionOrder->setSubscriptionId($subscriptionId);
            $subscriptionOrder->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
            $subscriptionOrder->setTotal($order->getGrandTotal());
            $subscriptionOrder->setStatus($order->getStatus());

            $this->subscriptionOrderRepository->save($subscriptionOrder);
        } catch (\Exception $e) {
        }
    }

    /**
     * Update payment details in the order
     */
    protected function updatePaymentDetails(Order $order, $billData)
    {
        if (($order->getPayment()->getMethod() === 'vindi_pix' || $order->getPayment()->getMethod() === 'vindi_bankslippix')
            && !empty($billData['bill']['charges'][0]['last_transaction']['gateway_response_fields'])) {
            $transactionDetails = $billData['bill']['charges'][0]['last_transaction']['gateway_response_fields'];
            $additionalInformation = $order->getPayment()->getAdditionalInformation();
            $additionalInformation['qrcode_original_path'] = $transactionDetails['qrcode_original_path'];
            $additionalInformation['qrcode_path'] = $transactionDetails['qrcode_path'];
            $additionalInformation['max_days_to_keep_waiting_payment'] = $transactionDetails['max_days_to_keep_waiting_payment'];
            $order->getPayment()->setAdditionalInformation($additionalInformation);
            $this->orderRepository->save($order);
        }
    }
}
