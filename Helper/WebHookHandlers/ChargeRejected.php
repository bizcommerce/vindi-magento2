<?php
namespace Vindi\Payment\Helper\WebHookHandlers;

use Vindi\Payment\Model\Payment\Bill;
use Vindi\Payment\Model\PaymentSplitFactory;
use Vindi\Payment\Model\Payment\Charge;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * Class ChargeRejected
 *
 * Handles the charge rejected webhook event.
 */
class ChargeRejected
{
    /**
     * @var Bill
     */
    private $bill;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PaymentSplitFactory
     */
    private $paymentSplitFactory;

    /**
     * @var Charge
     */
    private $charge;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * Constructor.
     *
     * @param Bill $bill
     * @param LoggerInterface $logger
     * @param PaymentSplitFactory $paymentSplitFactory
     * @param Charge $charge
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        Bill $bill,
        LoggerInterface $logger,
        PaymentSplitFactory $paymentSplitFactory,
        Charge $charge,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->bill = $bill;
        $this->logger = $logger;
        $this->paymentSplitFactory = $paymentSplitFactory;
        $this->charge = $charge;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * Process charge rejected webhook event.
     *
     * @param array $data Webhook event data.
     * @return bool
     * @throws \Exception
     */
    public function chargeRejected($data)
    {
        $chargeData = $data['charge'];
        $billId = $chargeData['bill']['id'];
        $bill = $chargeData['bill'];

        $this->logger->info('CHARGE_REJECTED: Processing for bill ' . $billId);

        // Verificar se é uma bill de assinatura
        $isSubscription = isset($bill['subscription']) && $bill['subscription'] !== null;
        if ($isSubscription) {
            return $this->handleSubscriptionChargeRejected($chargeData, $bill);
        }

        // Bills não-subscription podem ser:
        // 1. Pedido avulso simples (sem splits)
        // 2. Pedido avulso multimeios (com splits)
        return $this->handleRegularChargeRejected($chargeData, $billId);
    }

    /**
     * Handle charge rejected for subscription bills
     * Assinaturas são sempre single method - não há multimeios para renovações
     */
    private function handleSubscriptionChargeRejected($chargeData, $bill)
    {
        $billId = $bill['id'];
        $subscriptionId = $bill['subscription']['id'];
        $currentCycle = isset($bill['period']['cycle']) ? $bill['period']['cycle'] : 1;

        $this->logger->info("SUBSCRIPTION_CHARGE_REJECTED: Processing bill {$billId}, subscription {$subscriptionId}, cycle {$currentCycle}");

        // Buscar pedido original da assinatura
        $originalOrder = $this->getOriginalOrderFromSubscription($subscriptionId);
        if (!$originalOrder) {
            $this->logger->warning('SUBSCRIPTION_CHARGE_REJECTED: Original order not found for subscription ' . $subscriptionId);
            return false;
        }

        // Verificar se é a última tentativa
        $isLastAttempt = $chargeData['next_attempt'] === null;
        $gatewayMessage = $chargeData['last_transaction']['gateway_message'] ?? 'Unknown error';

        if ($isLastAttempt) {
            $this->logger->error("SUBSCRIPTION_CHARGE_REJECTED: Final attempt failed for subscription {$subscriptionId}, cycle {$currentCycle}. Gateway: {$gatewayMessage}");
            
            // Notificar cliente sobre falha definitiva
            $this->handleSubscriptionFailureNotification($originalOrder, $subscriptionId, $currentCycle, $gatewayMessage, true);
            
            // Marcar assinatura como com problema
            $this->markSubscriptionAsFailed($subscriptionId, $currentCycle, $gatewayMessage);
        } else {
            $this->logger->info("SUBSCRIPTION_CHARGE_REJECTED: Retry will be attempted for subscription {$subscriptionId}, cycle {$currentCycle}. Gateway: {$gatewayMessage}");
            
            // Notificar cliente sobre tentativa falhada (retry será feito)
            $this->handleSubscriptionFailureNotification($originalOrder, $subscriptionId, $currentCycle, $gatewayMessage, false);
        }
        
        return true;
    }

    /**
     * Handle charge rejected for regular (non-subscription) bills
     */
    private function handleRegularChargeRejected($chargeData, $billId)
    {
        $this->logger->info("REGULAR_CHARGE_REJECTED: Processing bill {$billId}");

        // Buscar splits relacionados a esta bill
        $paymentSplitCollection = $this->paymentSplitFactory->create()->getCollection()
            ->addFieldToFilter('bill_id', $billId);

        if ($paymentSplitCollection->getSize() > 0) {
            // Pedido multimeios - tem splits
            return $this->handleMultiMeiosRegularChargeRejected($chargeData, $billId, $paymentSplitCollection);
        } else {
            // Pedido simples - sem splits
            return $this->handleSimpleChargeRejected($chargeData, $billId);
        }
    }

    /**
     * Handle charge rejected for multimeios regular orders (non-subscription)
     */
    private function handleMultiMeiosRegularChargeRejected($chargeData, $billId, $paymentSplitCollection)
    {
        $this->logger->info("MULTIMEIOS_REGULAR_CHARGE_REJECTED: Processing bill {$billId}");

        $gatewayMessage = $chargeData['last_transaction']['gateway_message'] ?? 'Unknown error';

        // Buscar o split correspondente a esta bill
        $currentSplit = $paymentSplitCollection->getFirstItem();
        if (!$currentSplit->getId()) {
            $this->logger->error("MULTIMEIOS_REGULAR_CHARGE_REJECTED: Split not found for bill {$billId}");
            return false;
        }

        // Marcar split como 'failed' (NÃO refunded!)
        $currentSplit->setStatus('failed');
        $currentSplit->save();

        $this->logger->info("MULTIMEIOS_REGULAR_CHARGE_REJECTED: Split marked as failed for bill {$billId}");

        // Buscar todos os splits do pedido
        $order = $this->orderRepository->get($currentSplit->getOrderId());
        $allSplits = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('order_increment_id', $order->getIncrementId());

        // Verificar se todos splits falharam
        $allFailed = $this->areAllSplitsFailed($allSplits);
        
        if (!$allFailed) {
            $this->logger->info("MULTIMEIOS_REGULAR_CHARGE_REJECTED: Not all splits failed for order {$order->getIncrementId()} - waiting for other methods");
            
            // Adicionar comentário sobre falha parcial
            $order->addStatusHistoryComment(sprintf(
                'One payment method failed. Motive: "%s". Waiting for other payment methods.',
                $gatewayMessage
            ));
            $this->orderRepository->save($order);
            
            return true; // Aguardar outros métodos
        }

        // Todos splits falharam - cancelar pedido
        $this->logger->error("MULTIMEIOS_REGULAR_CHARGE_REJECTED: All payment methods failed for order {$order->getIncrementId()} - canceling order");
        
        if ($order->canCancel()) {
            $order->cancel();
            $order->addStatusHistoryComment(sprintf(
                'Order canceled - all payment methods failed. Last error: "%s"',
                $gatewayMessage
            ));
            $this->orderRepository->save($order);
        }
        
        return true;
    }

    /**
     * Handle charge rejected for simple orders (single payment method)
     */
    private function handleSimpleChargeRejected($chargeData, $billId)
    {
        $this->logger->info("SIMPLE_CHARGE_REJECTED: Processing bill {$billId}");

        $order = $this->getOrderFromBill($billId);
        if (!$order) {
            $this->logger->warning("SIMPLE_CHARGE_REJECTED: Order not found for bill {$billId}");
            return false;
        }

        $gatewayMessage = $chargeData['last_transaction']['gateway_message'] ?? 'Unknown error';
        $isLastAttempt = $chargeData['next_attempt'] === null;
        $statusIsNotPending = $chargeData['status'] != 'pending';

        if ($isLastAttempt && $statusIsNotPending) {
            // Última tentativa falhou - cancelar pedido
            $this->logger->error("SIMPLE_CHARGE_REJECTED: Final attempt failed for order {$order->getIncrementId()} - canceling order");
            
            $order->addStatusHistoryComment(sprintf(
                'Payment rejected. Motive: "%s"',
                $gatewayMessage
            ));
            $order->setState('canceled', true, sprintf(
                'All payment tries were rejected. Motive: "%s".',
                $gatewayMessage
            ), true);
            $order->setStatus('canceled');
        } else {
            // Tentativa falhou mas há retry - apenas comentar
            $this->logger->info("SIMPLE_CHARGE_REJECTED: Retry will be attempted for order {$order->getIncrementId()}");
            
            $order->addStatusHistoryComment(sprintf(
                'Payment try rejected. Motive: "%s". A new try will be made',
                $gatewayMessage
            ));
        }

        $this->orderRepository->save($order);
        return true;
    }

    /**
     * Check if all splits for an order failed
     */
    private function areAllSplitsFailed($splits)
    {
        foreach ($splits as $split) {
            if ($split->getStatus() !== 'failed') {
                return false;
            }
        }
        return true;
    }

    /**
     * Retrieve order from bill.
     *
     * @param mixed $billId
     * @return \Magento\Sales\Model\Order|false
     */
    private function getOrderFromBill($billId)
    {
        $bill = $this->bill->getBill($billId);
        if (!$bill) {
            return false;
        }
        if (isset($bill['code'])) {
            $orderCode = $bill['code'];
            if (substr($orderCode, -3) === '-01' || substr($orderCode, -3) === '-02') {
                $orderCode = substr($orderCode, 0, -3);
            }
            $criteria = $this->searchCriteriaBuilder->addFilter('increment_id', $orderCode, 'eq')->create();
            $orders = $this->orderRepository->getList($criteria)->getItems();
            return $orders ? reset($orders) : false;
        }
        return false;
    }

    /**
     * Get original order from subscription ID
     */
    private function getOriginalOrderFromSubscription($subscriptionId)
    {
        // Buscar pedido que tem essa subscription_id
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('vindi_subscription_id', $subscriptionId, 'eq')
            ->create();
        
        $orders = $this->orderRepository->getList($criteria)->getItems();
        return $orders ? reset($orders) : null;
    }

    /**
     * Handle subscription failure notification
     */
    private function handleSubscriptionFailureNotification($originalOrder, $subscriptionId, $cycle, $gatewayMessage, $isFinalAttempt)
    {
        try {
            $notificationType = $isFinalAttempt ? 'final_failure' : 'retry_failure';
            
            $this->logger->info("SUBSCRIPTION_NOTIFICATION: Sending {$notificationType} notification for subscription {$subscriptionId}, cycle {$cycle}");
            
            // Preparar dados para notificação
            $notificationData = [
                'order' => $originalOrder,
                'subscription_id' => $subscriptionId,
                'cycle' => $cycle,
                'gateway_message' => $gatewayMessage,
                'is_final_attempt' => $isFinalAttempt,
                'notification_type' => $notificationType
            ];
            
            // Log detalhado da notificação
            $this->logger->info("SUBSCRIPTION_NOTIFICATION: " . json_encode([
                'type' => 'subscription_payment_failure',
                'subscription_id' => $subscriptionId,
                'cycle' => $cycle,
                'is_final_attempt' => $isFinalAttempt,
                'order_id' => $originalOrder->getIncrementId(),
                'customer_email' => $originalOrder->getCustomerEmail(),
                'gateway_message' => $gatewayMessage
            ]));
            
            // TODO: Implementar envio real de email/notificação
            // $emailHelper = $this->objectManager->get(\Vindi\Payment\Helper\EmailSender::class);
            // $emailHelper->sendSubscriptionFailureNotification($notificationData);
            
        } catch (\Exception $e) {
            $this->logger->error("SUBSCRIPTION_NOTIFICATION: Error in notification: " . $e->getMessage());
        }
    }

    /**
     * Mark subscription as failed
     */
    private function markSubscriptionAsFailed($subscriptionId, $cycle, $gatewayMessage)
    {
        try {
            $this->logger->info("SUBSCRIPTION_STATUS: Marking subscription {$subscriptionId} as failed for cycle {$cycle}");
            
            // TODO: Implementar atualização do status da assinatura
            // Por enquanto apenas log
            $this->logger->info("SUBSCRIPTION_STATUS: " . json_encode([
                'action' => 'mark_as_failed',
                'subscription_id' => $subscriptionId,
                'cycle' => $cycle,
                'gateway_message' => $gatewayMessage,
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
        } catch (\Exception $e) {
            $this->logger->error("SUBSCRIPTION_STATUS: Error marking subscription as failed: " . $e->getMessage());
        }
    }

}
