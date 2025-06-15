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

        // Verificar se é uma bill de assinatura e se é multimeios
        $isSubscription = isset($bill['subscription']) && $bill['subscription'] !== null;
        if ($isSubscription) {
            return $this->handleSubscriptionChargeRejected($chargeData, $bill);
        }

        // Lógica original para bills não-subscription
        return $this->handleRegularChargeRejected($chargeData, $billId);
    }

    /**
     * Handle charge rejected for subscription bills (including multimeios)
     */
    private function handleSubscriptionChargeRejected($chargeData, $bill)
    {
        $billId = $bill['id'];
        $subscriptionId = $bill['subscription']['id'];
        $currentCycle = isset($bill['period']['cycle']) ? $bill['period']['cycle'] : 1;

        // Verificar se é multimeios
        $originalOrder = $this->getOriginalOrderFromSubscription($subscriptionId);
        if (!$originalOrder) {
            $this->logger->warning('CHARGE_REJECTED: Original order not found for subscription ' . $subscriptionId);
            return $this->handleRegularChargeRejected($chargeData, $billId);
        }

        $isMultiMeios = ($originalOrder->getPayment()->getMethod() === 'vindi_cardcard');
        
        if ($isMultiMeios) {
            return $this->handleMultiMeiosChargeRejected($chargeData, $bill, $currentCycle, $originalOrder);
        } else {
            return $this->handleRegularChargeRejected($chargeData, $billId);
        }
    }

    /**
     * Handle charge rejected for multimeios renewal bills
     */
    private function handleMultiMeiosChargeRejected($chargeData, $bill, $currentCycle, $originalOrder)
    {
        $billId = $bill['id'];
        $subscriptionId = $bill['subscription']['id'];
        
        $this->logger->info("MULTIMEIOS_CHARGE_REJECTED: Processing bill {$billId}, subscription {$subscriptionId}, cycle {$currentCycle}");

        // Atualizar payment split para marcar como falha
        $this->updatePaymentSplitForFailure($billId, $subscriptionId, $currentCycle, $originalOrder);

        // Verificar status de todas as bills do ciclo
        $cycleBillsStatus = $this->getCycleBillsStatus($subscriptionId, $currentCycle);
        
        $this->logger->info("MULTIMEIOS_CHARGE_REJECTED: Cycle status - Failed: {$cycleBillsStatus['failed_bills']}, Total: {$cycleBillsStatus['total_bills']}");

        if ($cycleBillsStatus['failed_bills'] === 1 && $cycleBillsStatus['total_bills'] === 2) {
            // 1 cartão falhou, 1 ainda pendente ou pode ter sido pago
            $this->logger->warning("MULTIMEIOS_CHARGE_REJECTED: One card failed in renewal for subscription {$subscriptionId}, cycle {$currentCycle}. Waiting for other card.");
            
            // Implementar estratégia de notificação para falha parcial
            $this->handlePartialFailureNotification($originalOrder, $subscriptionId, $currentCycle, $billId);
            
            return true;
            
        } elseif ($cycleBillsStatus['failed_bills'] === 2) {
            // Ambos cartões falharam
            $this->logger->error("MULTIMEIOS_CHARGE_REJECTED: Both cards failed in renewal for subscription {$subscriptionId}, cycle {$currentCycle}.");
            
            // Implementar notificação ao cliente e possível suspensão da assinatura
            $this->handleCompleteFailureNotification($originalOrder, $subscriptionId, $currentCycle);
            
            // Marcar assinatura como pendente ou suspensa
            $this->handleSubscriptionSuspension($subscriptionId, $currentCycle);
            
            return true;
        }
        
        return true;
    }

    /**
     * Get status of all bills for a specific subscription cycle
     */
    private function getCycleBillsStatus($subscriptionId, $cycle)
    {
        $splits = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('subscription_id', $subscriptionId)
            ->addFieldToFilter('cycle', $cycle);
        
        $totalBills = $splits->getSize();
        $paidBills = 0;
        $failedBills = 0;
        
        foreach ($splits as $split) {
            if ($split->getStatus() === 'paid') {
                $paidBills++;
            } elseif ($split->getStatus() === 'failed') {
                $failedBills++;
            }
        }
        
        return [
            'total_bills' => $totalBills,
            'paid_bills' => $paidBills,
            'failed_bills' => $failedBills,
            'pending_bills' => $totalBills - $paidBills - $failedBills
        ];
    }

    /**
     * Update payment split for failed renewal bill
     */
    private function updatePaymentSplitForFailure($billId, $subscriptionId, $cycle, $originalOrder)
    {
        // Procurar split existente
        $existingSplit = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('bill_id', $billId)
            ->getFirstItem();
        
        if ($existingSplit->getId()) {
            // Atualizar split existente
            $existingSplit->setStatus('failed');
            $existingSplit->setSubscriptionId($subscriptionId);
            $existingSplit->setCycle($cycle);
            $existingSplit->save();
            $this->logger->info('MULTIMEIOS_CHARGE_REJECTED: Payment split updated for bill ' . $billId);
        } else {
            // Criar novo split para bill de renovação falhada
            $split = $this->paymentSplitFactory->create();
            $split->setData([
                'bill_id' => $billId,
                'subscription_id' => $subscriptionId,
                'cycle' => $cycle,
                'status' => 'failed',
                'payment_method' => 'credit_card',
                'order_id' => $originalOrder->getId(),
                'order_increment_id' => $originalOrder->getIncrementId(),
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $split->save();
            $this->logger->info('MULTIMEIOS_CHARGE_REJECTED: New payment split created for failed bill ' . $billId);
        }
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
    }    /**
     * Handle charge rejected for regular (non-subscription) bills - original logic
     */
    private function handleRegularChargeRejected($chargeData, $billId)
    {
        $paymentSplitCollection = $this->paymentSplitFactory->create()->getCollection()
            ->addFieldToFilter('bill_id', $billId);

        if ($paymentSplitCollection->getSize() > 0) {
            $chargeId = isset($chargeData['id']) ? $chargeData['id'] : null;
            if (!$chargeId) {
                throw new \Exception('Charge ID not found in webhook data.');
            }

            $paymentSplitItems = $paymentSplitCollection->getItems();

            foreach ($paymentSplitItems as $paymentSplit) {
                if (!$paymentSplit->getIsRefunded()) {
                    $refundResult = $this->charge->refund($chargeId, ['amount' => $paymentSplit->getAmount()]);
                    if ($refundResult) {
                        $paymentSplit->setStatus('refunded');
                        $paymentSplit->setIsRefunded(1);
                        $paymentSplit->setRefundAmount($paymentSplit->getAmount());
                        $paymentSplit->setRefundDate(date('Y-m-d H:i:s'));
                        $paymentSplit->save();
                    }
                }
            }

            $firstPaymentSplit = reset($paymentSplitItems);
            $orderId = $firstPaymentSplit->getOrderId();

            try {
                $order = $this->orderRepository->get($orderId);
                if ($order->canCancel()) {
                    $order->cancel();
                    $order->addStatusHistoryComment('Order canceled due to multi-method payment refund.');
                    $this->orderRepository->save($order);
                }
            } catch (\Exception $e) {
                $this->logger->error('Error canceling order: ' . $e->getMessage());
            }

            return true;
        }

        if (!($order = $this->getOrderFromBill($billId))) {
            $this->logger->warning('Order not found');
            return false;
        }

        $gatewayMessage = $chargeData['last_transaction']['gateway_message'];
        $isLastAttempt = $chargeData['next_attempt'] === null;
        $statusIsNotPending = $chargeData['status'] != 'pending';

        if ($isLastAttempt && $statusIsNotPending) {
            $order->addStatusHistoryComment(sprintf(
                'Payment rejected. Motive: "%s"',
                $gatewayMessage
            ));
            $order->setState('canceled', true, sprintf(
                'All payment tries were rejected. Motive: "%s".',
                $gatewayMessage
            ), true);
            $order->setStatus('canceled');
            $this->logger->info(sprintf('All payment tries were rejected. Motive: "%s".', $gatewayMessage));
        } else {
            $order->addStatusHistoryComment(sprintf(
                'Payment try rejected. Motive: "%s". A new try will be made',
                $gatewayMessage
            ));
            $this->logger->info(sprintf('Payment try rejected. Motive: "%s". A new try will be made', $gatewayMessage));
        }

        $order->save();

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
     * Handle partial failure notification (one card failed)
     */
    private function handlePartialFailureNotification($originalOrder, $subscriptionId, $cycle, $failedBillId)
    {
        try {
            $this->logger->info("MULTIMEIOS_CHARGE_REJECTED: Sending partial failure notification for subscription {$subscriptionId}, cycle {$cycle}");
            
            // Preparar dados para email/notificação
            $notificationData = [
                'order' => $originalOrder,
                'subscription_id' => $subscriptionId,
                'cycle' => $cycle,
                'failed_bill_id' => $failedBillId,
                'failure_type' => 'partial',
                'message' => 'Um dos cartões da sua assinatura apresentou falha no pagamento. Verificaremos o status do segundo cartão.'
            ];
            
            // Enviar notificação (se implementado)
            $this->sendFailureNotification($notificationData);
            
            $this->logger->info("MULTIMEIOS_CHARGE_REJECTED: Partial failure notification sent for subscription {$subscriptionId}");
            
        } catch (\Exception $e) {
            $this->logger->error("MULTIMEIOS_CHARGE_REJECTED: Error sending partial failure notification: " . $e->getMessage());
        }
    }

    /**
     * Handle complete failure notification (both cards failed)
     */
    private function handleCompleteFailureNotification($originalOrder, $subscriptionId, $cycle)
    {
        try {
            $this->logger->info("MULTIMEIOS_CHARGE_REJECTED: Sending complete failure notification for subscription {$subscriptionId}, cycle {$cycle}");
            
            // Preparar dados para email/notificação
            $notificationData = [
                'order' => $originalOrder,
                'subscription_id' => $subscriptionId,
                'cycle' => $cycle,
                'failure_type' => 'complete',
                'message' => 'Ambos os cartões da sua assinatura apresentaram falha no pagamento. Sua assinatura será suspensa temporariamente.',
                'action_required' => true
            ];
            
            // Enviar notificação crítica
            $this->sendFailureNotification($notificationData);
            
            $this->logger->info("MULTIMEIOS_CHARGE_REJECTED: Complete failure notification sent for subscription {$subscriptionId}");
            
        } catch (\Exception $e) {
            $this->logger->error("MULTIMEIOS_CHARGE_REJECTED: Error sending complete failure notification: " . $e->getMessage());
        }
    }

    /**
     * Handle subscription suspension
     */
    private function handleSubscriptionSuspension($subscriptionId, $cycle)
    {
        try {
            $this->logger->info("MULTIMEIOS_CHARGE_REJECTED: Processing subscription suspension for subscription {$subscriptionId}, cycle {$cycle}");
            
            // Atualizar status da assinatura local se necessário
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $subscriptionModel = $objectManager->create(\Vindi\Payment\Model\Subscription::class);
            $subscription = $subscriptionModel->load($subscriptionId, 'vindi_id');
            
            if ($subscription->getId()) {
                $subscription->setStatus('payment_failed');
                $subscription->setData('last_failed_cycle', $cycle);
                $subscription->setData('failure_date', date('Y-m-d H:i:s'));
                $subscription->save();
                
                $this->logger->info("MULTIMEIOS_CHARGE_REJECTED: Local subscription status updated to payment_failed for subscription {$subscriptionId}");
            }
            
            // Lógica adicional de suspensão pode ser implementada aqui
            // Como pausar a assinatura na Vindi por X dias, etc.
            
        } catch (\Exception $e) {
            $this->logger->error("MULTIMEIOS_CHARGE_REJECTED: Error handling subscription suspension: " . $e->getMessage());
        }
    }

    /**
     * Send failure notification (email, SMS, etc.)
     */
    private function sendFailureNotification($notificationData)
    {
        try {
            // Por enquanto, apenas log detalhado
            // Este método pode ser expandido para enviar emails reais
            
            $this->logger->info("MULTIMEIOS_NOTIFICATION: " . json_encode([
                'type' => 'payment_failure',
                'subscription_id' => $notificationData['subscription_id'],
                'cycle' => $notificationData['cycle'],
                'failure_type' => $notificationData['failure_type'],
                'order_id' => $notificationData['order']->getIncrementId(),
                'customer_email' => $notificationData['order']->getCustomerEmail(),
                'message' => $notificationData['message']
            ]));
            
            // TODO: Implementar envio real de email
            // $emailHelper = $this->objectManager->get(\Vindi\Payment\Helper\EmailSender::class);
            // $emailHelper->sendFailureNotification($notificationData);
            
        } catch (\Exception $e) {
            $this->logger->error("MULTIMEIOS_NOTIFICATION: Error in notification: " . $e->getMessage());
        }
    }
}
