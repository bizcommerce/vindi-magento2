<?php

namespace Vindi\Payment\Helper\WebHookHandlers;

use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Service\OrderService;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderItemInterfaceFactory;
use Vindi\Payment\Model\SubscriptionOrderRepository;
use Vindi\Payment\Model\SubscriptionOrderFactory;
use Vindi\Payment\Model\Payment\Bill as PaymentBill;
use Vindi\Payment\Helper\Data;
use Vindi\Payment\Model\PaymentSplitFactory;
use Vindi\Payment\Api\ProductManagementInterface;
use Magento\Framework\Phrase;

/**
 * Class OrderCreator
 * @package Vindi\Payment\Helper\WebHookHandlers
 */
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
     * @var ProductFactory
     */
    private $productFactory;

    /**
     * @var OrderItemInterfaceFactory
     */
    private $orderItemFactory;

    /**
     * @var PaymentSplitFactory
     */
    protected $paymentSplitFactory;

    /**
     * @var ProductManagementInterface
     */
    protected $productManagement;

    /**
     * OrderCreator constructor.
     * @param OrderFactory $orderFactory
     * @param SubscriptionOrderRepository $subscriptionOrderRepository
     * @param SubscriptionOrderFactory $subscriptionOrderFactory
     * @param OrderService $orderService
     * @param PaymentBill $paymentBill
     * @param OrderRepository $orderRepository
     * @param ProductFactory $productFactory
     * @param OrderItemInterfaceFactory $orderItemFactory
     * @param PaymentSplitFactory $paymentSplitFactory
     * @param ProductManagementInterface $productManagement
     */
    public function __construct(
        OrderFactory $orderFactory,
        SubscriptionOrderRepository $subscriptionOrderRepository,
        SubscriptionOrderFactory $subscriptionOrderFactory,
        OrderService $orderService,
        PaymentBill $paymentBill,
        OrderRepository $orderRepository,
        ProductFactory $productFactory,
        OrderItemInterfaceFactory $orderItemFactory,
        PaymentSplitFactory $paymentSplitFactory,
        ProductManagementInterface $productManagement
    ) {
        $this->orderFactory = $orderFactory;
        $this->subscriptionOrderRepository = $subscriptionOrderRepository;
        $this->subscriptionOrderFactory = $subscriptionOrderFactory;
        $this->orderService = $orderService;
        $this->paymentBill = $paymentBill;
        $this->orderRepository = $orderRepository;
        $this->productFactory = $productFactory;
        $this->orderItemFactory = $orderItemFactory;
        $this->paymentSplitFactory = $paymentSplitFactory;
        $this->productManagement = $productManagement;
    }

    /**
     * @param array $billData
     * @return bool
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
     * @param string $subscriptionId
     * @return Order|null
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
     * @param Order $originalOrder
     * @param array $billData
     * @return Order
     */
    protected function replicateOrder(Order $originalOrder, $billData)
    {
        $newOrder = clone $originalOrder;
        $newOrder->setId(null);
        $newOrder->setIncrementId(null);
        $newOrder->setData('vindi_bill_id', $billData['id']);
        $newOrder->setData('vindi_subscription_id', $billData['subscription']['id']);
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

        $shippingAmount = 0;
        $newOrderItems = [];
        $billItems = $this->processBillItems($billData['bill_items']);

        $totalDiscount = 0;

        foreach ($billItems as $billItem) {
            if ($billItem['discount_amount'] !== null) {
                $totalDiscount += $billItem['discount_amount'];
                continue;
            }

            $sku = $billItem['product_item']['product']['code'];
            $found = false;

            foreach ($originalOrder->getAllVisibleItems() as $originalItem) {
                if (Data::sanitizeItemSku($originalItem->getSku()) === $sku) {
                    $found = true;
                    $newItem = $this->updateOrderItemFromBill($originalItem, $billItem);
                    $newOrderItems[] = $newItem;
                    break;
                }
            }

            if (!$found && $sku !== 'frete') {
                $product = $this->productFactory->create()->loadByAttribute('sku', $sku);
                if ($product) {
                    $newItem = $this->createNewOrderItem($product, $billItem);
                    $newOrderItems[] = $newItem;
                }
            }

            if ($sku === 'frete') {
                $shippingAmount = $billItem['pricing_schema']['price'];
            }
        }

        $newOrder->setItems($newOrderItems);

        $originalPayment = $originalOrder->getPayment();
        $newPayment = clone $originalPayment;
        $newPayment->setId(null)->setOrderId(null);
        $newOrder->setPayment($newPayment);

        $newOrder->setTotalPaid(null);
        $newOrder->setBaseTotalPaid(null);

        $subtotal = 0;
        $taxAmount = 0;
        $discountAmount = $totalDiscount;
        foreach ($newOrderItems as $item) {
            $subtotal += $item->getRowTotal();
            $taxAmount += $item->getTaxAmount();
        }

        $grandTotal = $subtotal + $taxAmount + $shippingAmount - $discountAmount;

        $newOrder->setSubtotal($subtotal);
        $newOrder->setBaseSubtotal($subtotal);
        $newOrder->setTaxAmount($taxAmount);
        $newOrder->setBaseTaxAmount($taxAmount);
        $newOrder->setShippingAmount($shippingAmount);
        $newOrder->setBaseShippingAmount($shippingAmount);
        $newOrder->setDiscountAmount(-abs($discountAmount));
        $newOrder->setBaseDiscountAmount(-abs($discountAmount));
        $newOrder->setGrandTotal($grandTotal);
        $newOrder->setBaseGrandTotal($grandTotal);
        $newOrder->setTotalDue($grandTotal);
        $newOrder->setBaseTotalDue($grandTotal);

        return $newOrder;
    }

    /**
     * @param array $billItems
     * @return array
     */
    protected function processBillItems(array $billItems)
    {
        $processedItems = [];
        foreach ($billItems as $billItem) {
            if ($billItem['amount'] < 0) {
                $billItem['discount_amount'] = abs($billItem['amount']);
            } else {
                $billItem['discount_amount'] = null;
            }
            $processedItems[] = $billItem;
        }
        return $processedItems;
    }

    /**
     * @param Order $originalItem
     * @param array $billItem
     * @return Order
     */
    protected function updateOrderItemFromBill($originalItem, $billItem)
    {
        $newItem = clone $originalItem;
        $newItem->setId(null)->setOrderId(null);

        $newPrice = $billItem['pricing_schema']['price'];
        $newQty = $billItem['quantity'] ?? $originalItem->getQtyOrdered();

        $newItem->setPrice($newPrice);
        $newItem->setBasePrice($newPrice);
        $newItem->setQtyOrdered($newQty);
        $newItem->setRowTotal($newPrice * $newQty);
        $newItem->setBaseRowTotal($newPrice * $newQty);

        return $newItem;
    }

    /**
     * @param $product
     * @param $billItem
     * @return Order
     */
    protected function createNewOrderItem($product, $billItem)
    {
        $orderItem = $this->orderItemFactory->create();

        $price = $billItem['pricing_schema']['price'] ?? 0;
        $qty = $billItem['quantity'] ?? 1;

        $orderItem->setProductId($product->getId());
        $orderItem->setSku($product->getSku());
        $orderItem->setName($product->getName());
        $orderItem->setPrice($price);
        $orderItem->setBasePrice($price);
        $orderItem->setQtyOrdered($qty);
        $orderItem->setRowTotal($price * $qty);
        $orderItem->setBaseRowTotal($price * $qty);

        return $orderItem;
    }

    /**
     * @param Order $order
     * @param $subscriptionId
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
            // Log the error if needed
        }
    }

    /**
     * @param Order $order
     * @param $billData
     */
    public function updatePaymentDetails(Order $order, $billData)
    {
        $paymentMethod = $order->getPayment()->getMethod();
        $charge = $billData['bill']['charges'][0] ?? [];
        $transactionDetails = $billData['bill']['charges'][0]['last_transaction']['gateway_response_fields'] ?? [];
        $additionalInformation = $order->getPayment()->getAdditionalInformation();

        switch ($paymentMethod) {
            case 'vindi_pix':
            case 'vindi_bankslippix':
                $additionalInformation = array_merge($additionalInformation, [
                    'qrcode_original_path' => $transactionDetails['qrcode_original_path'] ?? null,
                    'qrcode_path' => $transactionDetails['qrcode_path'] ?? null,
                    'qrcode_url' => $transactionDetails['qrcode_url'] ?? null,
                    'print_url' => $transactionDetails['print_url'] ?? null,
                    'max_days_to_keep_waiting_payment' => $transactionDetails['max_days_to_keep_waiting_payment'] ?? null,
                    'due_at' => $charge["due_at"] ?? null
                ]);
                break;

            case 'vindi_bankslip':
                $additionalInformation = array_merge($additionalInformation, [
                    'print_url' => $charge['print_url'] ?? null,
                    'due_at' => $charge['due_at'] ?? null,
                ]);
                break;

            case 'vindi':
                $paymentProfile = $billData['bill']['charges'][0]['last_transaction']['payment_profile'] ?? [];
                $additionalInformation = array_merge($additionalInformation, [
                    'card_holder_name' => $paymentProfile['holder_name'] ?? null,
                    'card_last_4' => $paymentProfile['card_number_last_four'] ?? null,
                    'card_expiry_date' => $paymentProfile['card_expiration'] ?? null,
                    'card_brand' => $paymentProfile['payment_company']['name'] ?? null,
                    'authorization_code' => $billData['bill']['charges'][0]['last_transaction']['gateway_authorization'] ?? null,
                    'transaction_id' => $billData['bill']['charges'][0]['last_transaction']['gateway_transaction_id'] ?? null,
                    'nsu' => $transactionDetails['nsu'] ?? null,
                ]);
                break;
        }

        $order->getPayment()->setAdditionalInformation($additionalInformation);
        $this->orderRepository->save($order);
    }

    /**
     * Cancela uma bill na Vindi usando o helper Api
     * @param string|int $billId
     * @return bool
     */
    public function cancelVindiBill($billId)
    {
        try {
            /** @var \Vindi\Payment\Helper\Api $apiHelper */
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $apiHelper = $objectManager->get(\Vindi\Payment\Helper\Api::class);
            return $apiHelper->cancelVindiBill($billId);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Enfileira ou executa a criação das duas bills manuais para recorrência multimeios
     * @param \Magento\Sales\Model\Order $originalOrder
     * @param string|int $subscriptionId
     * @param array $billData
     * @return void
     */
    public function enqueueManualBillsForMultiMeios($originalOrder, $subscriptionId, $billData)
    {
        $payment = $originalOrder->getPayment();
        $amountCredit = $payment->getAdditionalInformation('amount_credit');
        $amountSecondCard = $payment->getAdditionalInformation('amount_second_card');
        $profileId1 = $payment->getAdditionalInformation('payment_profile');
        $profileId2 = $payment->getAdditionalInformation('payment_profile2');
        $installments1 = $payment->getAdditionalInformation('cc_installments') ?: 1;
        $installments2 = $payment->getAdditionalInformation('cc_installments2') ?: 1;
        $customerId = $originalOrder->getData('vindi_customer_id');
        $cycle = isset($billData['period']['cycle']) ? $billData['period']['cycle'] : '01';
        $incrementId = $originalOrder->getIncrementId();

        $productList = $this->productManagement->findOrCreateProductsToSubscription($originalOrder);

        // Usar método centralizado para obter/criar produto de desconto
        try {
            $multiPaymentDiscountProductId = $this->getOrCreateDiscountProduct();
        } catch (\Exception $e) {
            error_log("VINDI_MULTIMEIOS: Erro ao obter produto de desconto: " . $e->getMessage());
            $multiPaymentDiscountProductId = null;
        }

        $billItemsCard1 = $productList;
        $billItemsCard2 = $productList;
        if ($multiPaymentDiscountProductId) {
            $billItemsCard1[] = [
                'product_id' => $multiPaymentDiscountProductId,
                'amount' => -((float)$amountSecondCard)
            ];
            $billItemsCard2[] = [
                'product_id' => $multiPaymentDiscountProductId,
                'amount' => -((float)$amountCredit)
            ];
        }

        $bodyCard1 = [
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'payment_method_code' => 'credit_card',
            'payment_profile' => ['id' => $profileId1],
            'bill_items' => $billItemsCard1,
            'installments' => (int)$installments1,
            'code' => $incrementId . '-' . $cycle . '-01',
        ];
        $bodyCard2 = [
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'payment_method_code' => 'credit_card',
            'payment_profile' => ['id' => $profileId2],
            'bill_items' => $billItemsCard2,
            'installments' => (int)$installments2,
            'code' => $incrementId . '-' . $cycle . '-02',
        ];

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        /** @var \Vindi\Payment\Helper\Api $apiHelper */
        $apiHelper = $objectManager->get(\Vindi\Payment\Helper\Api::class);

        try {
            // Validar se os payment profiles existem na Vindi
            $this->validatePaymentProfiles($originalOrder, $profileId1, $profileId2);
        } catch (\Exception $e) {
            error_log("VINDI_MULTIMEIOS: " . $e->getMessage());
            return;
        }

        // Tentar criar as bills com rollback automático em caso de falha
        try {
            $billsResult = $this->createBillsWithRollback($bodyCard1, $bodyCard2);

            // Atualizar pedido e splits após criação bem-sucedida das bills
            $this->updateOrderAndSplits($originalOrder, $billsResult, $amountCredit, $amountSecondCard);
        } catch (\Exception $e) {
            error_log("VINDI_MULTIMEIOS: Erro ao criar bills manuais - " . $e->getMessage());
        }
    }

    /**
     * Valida se os payment profiles existem na Vindi antes de criar as bills
     * @param \Magento\Sales\Model\Order $originalOrder
     * @param string|int $profileId1
     * @param string|int $profileId2
     * @return array
     * @throws \Exception
     */
    protected function validatePaymentProfiles($originalOrder, $profileId1, $profileId2)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $profileHelper = $objectManager->get(\Vindi\Payment\Model\Payment\Profile::class);

        // Validar profile 1
        try {
            $profile1Valid = $profileHelper->getPaymentProfileById($profileId1);
            if (!$profile1Valid || (isset($profile1Valid['not_found']) && $profile1Valid['not_found'])) {
                throw new \Exception("Payment Profile 1 (ID: {$profileId1}) não encontrado na Vindi");
            }
        } catch (\Exception $e) {
            throw new \Exception("Erro ao validar Payment Profile 1 (ID: {$profileId1}): " . $e->getMessage());
        }

        // Validar profile 2
        try {
            $profile2Valid = $profileHelper->getPaymentProfileById($profileId2);
            if (!$profile2Valid || (isset($profile2Valid['not_found']) && $profile2Valid['not_found'])) {
                throw new \Exception("Payment Profile 2 (ID: {$profileId2}) não encontrado na Vindi");
            }
        } catch (\Exception $e) {
            throw new \Exception("Erro ao validar Payment Profile 2 (ID: {$profileId2}): " . $e->getMessage());
        }

        return [$profile1Valid, $profile2Valid];
    }

    /**
     * Obtém ou cria produto de desconto para multimeios de forma consistente
     * @return int
     * @throws \Exception
     */
    protected function getOrCreateDiscountProduct()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $helperData = $objectManager->get(\Vindi\Payment\Helper\Data::class);

        // Tentar pegar da configuração primeiro
        $discountProductId = $helperData->getConfig('general', 'discount_product_id');
        if ($discountProductId && is_numeric($discountProductId)) {
            return (int) $discountProductId;
        }

        // Criar automaticamente se não existir
        $apiHelper = $objectManager->get(\Vindi\Payment\Helper\Api::class);
        $response = $apiHelper->request('products', 'POST', [
            'name' => 'Desconto Multimeios de Pagamento',
            'code' => 'discount_multipayment_' . time(),
            'status' => 'active',
            'pricing_schema' => ['price' => 0.00]
        ]);

        if ($response && isset($response['product']['id'])) {
            $productId = $response['product']['id'];
            // TODO: Implementar salvamento na configuração para uso futuro
            return $productId;
        }

        throw new \Exception('Não foi possível criar produto de desconto na Vindi');
    }

    /**
     * Cria as bills com sistema de rollback automático
     * @param array $billData1
     * @param array $billData2
     * @return array
     * @throws \Exception
     */
    protected function createBillsWithRollback($billData1, $billData2)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $apiHelper = $objectManager->get(\Vindi\Payment\Helper\Api::class);
        $createdBills = [];

        try {
            // Criar primeira bill
            $result1 = $apiHelper->request('bills', 'POST', $billData1);
            if (!$result1 || !isset($result1['bill']['id'])) {
                throw new \Exception('Falha ao criar primeira bill para multimeios: ' . json_encode($result1));
            }
            $createdBills[] = $result1['bill']['id'];

            // Criar segunda bill
            $result2 = $apiHelper->request('bills', 'POST', $billData2);
            if (!$result2 || !isset($result2['bill']['id'])) {
                throw new \Exception('Falha ao criar segunda bill para multimeios: ' . json_encode($result2));
            }
            $createdBills[] = $result2['bill']['id'];

            return ['bill1' => $result1['bill'], 'bill2' => $result2['bill']];

        } catch (\Exception $e) {
            // Rollback: cancelar bills criadas em caso de falha
            foreach ($createdBills as $billId) {
                try {
                    $apiHelper->cancelVindiBill($billId);
                    error_log("VINDI_MULTIMEIOS: Bill {$billId} cancelada durante rollback");
                } catch (\Exception $rollbackError) {
                    error_log("VINDI_MULTIMEIOS: Erro no rollback da bill {$billId}: " . $rollbackError->getMessage());
                }
            }
            throw $e;
        }
    }

    /**
     * Atualiza o pedido e os registros de payment split após criação bem-sucedida das bills
     * @param \Magento\Sales\Model\Order $originalOrder
     * @param array $billsResult
     * @param float $amountCredit
     * @param float $amountSecondCard
     * @return void
     */
    protected function updateOrderAndSplits($originalOrder, $billsResult, $amountCredit, $amountSecondCard)
    {
        $billCard1 = $billsResult['bill1'];
        $billCard2 = $billsResult['bill2'];

        // Salvar o pedido se necessário
        if (!$originalOrder->getId()) {
            $originalOrder = $this->orderRepository->save($originalOrder);
        }

        // Criar payment splits
        $dataFirst = [
            'order_id' => $originalOrder->getId(),
            'order_increment_id' => $originalOrder->getIncrementId(),
            'payment_method' => 'credit_card',
            'amount' => $amountCredit,
            'total_amount' => $amountCredit,
            'bill_id' => $billCard1['id'],
            'status' => $billCard1['status'] ?? 'pending',
            'additional_data' => json_encode($this->maskSensitiveDataForSplit($billCard1)),
            'is_refunded' => 0,
            'refund_amount' => 0
        ];

        $split1 = $this->paymentSplitFactory->create();
        $split1->setData($dataFirst);
        $split1->save();

        $dataSecond = [
            'order_id' => $originalOrder->getId(),
            'order_increment_id' => $originalOrder->getIncrementId(),
            'payment_method' => 'credit_card',
            'amount' => $amountSecondCard,
            'total_amount' => $amountSecondCard,
            'bill_id' => $billCard2['id'],
            'status' => $billCard2['status'] ?? 'pending',
            'additional_data' => json_encode($this->maskSensitiveDataForSplit($billCard2)),
            'is_refunded' => 0,
            'refund_amount' => 0
        ];

        $split2 = $this->paymentSplitFactory->create();
        $split2->setData($dataSecond);
        $split2->save();

        // Atualizar vindi_bill_id no pedido com as novas bills
        $originalOrder->setData('vindi_bill_id', $billCard1['id'] . ',' . $billCard2['id']);
        $this->orderRepository->save($originalOrder);
    }

    /**
     * Remove dados sensíveis das informações de bill antes de salvar no split
     * @param array $billData
     * @return array
     */
    protected function maskSensitiveDataForSplit($billData)
    {
        if (!is_array($billData)) {
            return $billData;
        }

        $masked = $billData;

        // Remover informações sensíveis comuns
        $sensitiveFields = ['payment_profile', 'charges'];
        foreach ($sensitiveFields as $field) {
            if (isset($masked[$field])) {
                if ($field === 'payment_profile' && is_array($masked[$field])) {
                    // Manter apenas ID do profile
                    $masked[$field] = ['id' => $masked[$field]['id'] ?? null];
                } elseif ($field === 'charges' && is_array($masked[$field])) {
                    // Remover dados sensíveis dos charges
                    foreach ($masked[$field] as &$charge) {
                        if (isset($charge['payment_method'])) {
                            unset($charge['payment_method']);
                        }
                    }
                }
            }
        }

        return $masked;
    }
}
