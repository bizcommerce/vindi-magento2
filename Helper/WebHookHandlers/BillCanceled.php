<?php
namespace Vindi\Payment\Helper\WebHookHandlers;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Vindi\Payment\Model\PaymentSplitFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Vindi\Payment\Model\Payment\Charge;
use Vindi\Payment\Model\Payment\Bill;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\CreditmemoManagementInterface;

/**
 * Class BillCanceled
 *
 * Handles the bill canceled webhook event.
 */
class BillCanceled
{
    protected $logger;
    protected $paymentSplitFactory;
    protected $orderRepository;
    protected $charge;
    protected $bill;
    protected $creditmemoFactory;
    protected $creditmemoService;
    protected $transaction;
    protected $creditmemoManagement;

    public function __construct(
        LoggerInterface $logger,
        PaymentSplitFactory $paymentSplitFactory,
        OrderRepositoryInterface $orderRepository,
        Charge $charge,
        Bill $bill,
        CreditmemoFactory $creditmemoFactory,
        CreditmemoService $creditmemoService,
        Transaction $transaction,
        CreditmemoManagementInterface $creditmemoManagement
    ) {
        $this->logger = $logger;
        $this->paymentSplitFactory = $paymentSplitFactory;
        $this->orderRepository = $orderRepository;
        $this->charge = $charge;
        $this->bill = $bill;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->transaction = $transaction;
        $this->creditmemoManagement = $creditmemoManagement;
    }

    public function billCanceled(array $data): bool
    {
        if (!isset($data['bill']) || empty($data['bill'])) {
            throw new LocalizedException(__('Bill data not found in webhook data.'));
        }

        $bill = $data['bill'];
        $billId = $bill['id'];

        // Buscar split correspondente a esta bill
        $currentSplit = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('bill_id', $billId)
            ->getFirstItem();

        if (!$currentSplit->getId()) {
            $this->logger->info('No split found for bill: ' . $billId . ' - assuming single payment flow');
            return true; // Fluxo simples, não é multimeios
        }

        // Verificar se já foi refundado
        if ($currentSplit->getIsRefunded()) {
            $this->logger->info('Split already refunded for bill: ' . $billId);
            return true;
        }

        // Obter charge para refund
        $chargeId = isset($bill['charges'][0]['id']) ? $bill['charges'][0]['id'] : null;
        if (!$chargeId) {
            $this->logger->error('No charge ID found for bill: ' . $billId);
            return false;
        }

        // Executar refund na Vindi
        $refundResult = $this->charge->refund($chargeId, ['amount' => $currentSplit->getAmount()]);
        if (!$refundResult) {
            $this->logger->error('Refund failed for bill: ' . $billId);
            return false;
        }

        // Marcar split como refundado
        $currentSplit->setStatus('refunded')
            ->setIsRefunded(1)
            ->setRefundAmount($currentSplit->getAmount())
            ->setRefundDate(date('Y-m-d H:i:s'))
            ->save();

        $this->logger->info('Split refunded successfully for bill: ' . $billId);

        // Buscar todos os splits do pedido
        $order = $this->orderRepository->get($currentSplit->getOrderId());
        $allSplits = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('order_increment_id', $order->getIncrementId());

        // Verificar se há splits já pagos (precisam ser estornados)
        $paidSplits = $this->getPaidSplits($allSplits);
        
        if (!empty($paidSplits)) {
            $this->logger->info('Found paid splits that need refund for order: ' . $order->getIncrementId());
            
            // CORREÇÃO: Se há splits pagos, sempre estornar e cancelar pedido
            // Não importa se é "parcial" - em multimeios, se um método é cancelado 
            // e outro já foi pago, devemos estornar o pago e cancelar o pedido
            $refundSuccess = $this->refundPaidSplits($paidSplits, $order);
            
            if (!$refundSuccess) {
                $this->logger->error('Failed to refund paid splits for order: ' . $order->getIncrementId());
                return false;
            }
            
            // Após estornar splits pagos, marcar o split atual como refunded também
            // para que o fluxo continue para cancelamento total
        }
        
        // Se chegou aqui, todos os splits estão cancelados/refundados - cancelar pedido
        $this->logger->info('All splits processed for order: ' . $order->getIncrementId() . ' - proceeding with full cancellation');
        
        // Verificar se o pedido já tem invoice gerada
        $hasInvoice = $this->orderHasInvoice($order);
        
        if ($hasInvoice) {
            // CENÁRIO A: Invoice já existe - gerar creditmemo
            return $this->handleRefundWithInvoice($order);
        } else {
            // CENÁRIO B: Invoice não existe - cancelamento direto
            return $this->handleCancellationWithoutInvoice($order);
        }

        return true;
    }

    /**
     * Check if all splits for an order are refunded
     *
     * @param \Vindi\Payment\Model\ResourceModel\PaymentSplit\Collection $splits
     * @return bool
     */
    private function areAllSplitsRefunded($splits)
    {
        foreach ($splits as $split) {
            if (!$split->getIsRefunded()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get splits that are already paid and need to be refunded
     *
     * @param \Vindi\Payment\Model\ResourceModel\PaymentSplit\Collection $splits
     * @return array
     */
    private function getPaidSplits($splits)
    {
        $paidSplits = [];
        
        foreach ($splits as $split) {
            // Se o split está pago (status 'paid') e não foi refundado ainda
            if ($split->getStatus() === 'paid' && !$split->getIsRefunded()) {
                $paidSplits[] = $split;
            }
        }
        
        return $paidSplits;
    }

    /**
     * Refund all paid splits
     *
     * @param array $paidSplits
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    private function refundPaidSplits($paidSplits, $order)
    {
        try {
            foreach ($paidSplits as $split) {
                $this->logger->info(sprintf(
                    'Refunding paid split - Bill ID: %s, Amount: %s, Order: %s',
                    $split->getBillId(),
                    $split->getAmount(),
                    $order->getIncrementId()
                ));

                // Buscar charge para fazer refund
                $chargeId = $this->getChargeIdFromBillId($split->getBillId());
                
                if (!$chargeId) {
                    $this->logger->error('No charge ID found for paid split - Bill ID: ' . $split->getBillId());
                    continue;
                }

                // Executar refund na Vindi
                $refundResult = $this->charge->refund($chargeId, ['amount' => $split->getAmount()]);
                
                if (!$refundResult) {
                    $this->logger->error('Refund failed for paid split - Bill ID: ' . $split->getBillId());
                    return false;
                }

                // Marcar split como refundado
                $split->setStatus('refunded')
                    ->setIsRefunded(1)
                    ->setRefundAmount($split->getAmount())
                    ->setRefundDate(date('Y-m-d H:i:s'))
                    ->save();

                $this->logger->info('Paid split refunded successfully - Bill ID: ' . $split->getBillId());

                // Adicionar comentário detalhado no pedido
                $order->addStatusHistoryComment(sprintf(
                    'Estorno realizado: Método "%s" (R$ %s) foi estornado devido ao cancelamento de outro método do multimeios.',
                    $split->getPaymentMethod() ?: 'Método de Pagamento',
                    number_format($split->getAmount(), 2, ',', '.')
                ));
            }

            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Error refunding paid splits: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get charge ID from bill ID
     *
     * @param int $billId
     * @return int|null
     */
    private function getChargeIdFromBillId($billId)
    {
        try {
            $bill = $this->bill->getBill($billId);
            
            if (isset($bill['charges'][0]['id'])) {
                return $bill['charges'][0]['id'];
            }
            
            return null;
            
        } catch (\Exception $e) {
            $this->logger->error('Error getting charge ID for bill ' . $billId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if order has invoice generated
     *
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    private function orderHasInvoice($order)
    {
        $invoiceCollection = $order->getInvoiceCollection();
        return $invoiceCollection->getSize() > 0;
    }

    /**
     * Check if all splits are being canceled (not just one)
     *
     * @param \Vindi\Payment\Model\ResourceModel\PaymentSplit\Collection $allSplits
     * @param \Vindi\Payment\Model\PaymentSplit $currentSplit
     * @return bool
     */
    private function areAllSplitsBeingCanceled($allSplits, $currentSplit)
    {
        foreach ($allSplits as $split) {
            // Se há algum split que não é o atual e não está refundado, então não é cancelamento total
            if ($split->getId() !== $currentSplit->getId() && !$split->getIsRefunded()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Handle refund when order already has invoice (CENÁRIO A: Cartão+Cartão)
     * Gera creditmemo para estorno e pode cancelar o pedido
     *
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    private function handleRefundWithInvoice($order)
    {
        try {
            $this->logger->info('REFUND_WITH_INVOICE: Processing order ' . $order->getIncrementId() . ' - invoice exists');
            
            // Adicionar comentário explicativo
            $order->addStatusHistoryComment(
                'Estorno processado: Todos os métodos de pagamento foram estornados. ' .
                'Creditmemo gerado automaticamente para reembolso.'
            );

            // Criar creditmemo para valor total do pedido
            if ($order->canCreditmemo()) {
                $invoice = $order->getInvoiceCollection()->getFirstItem();
                if ($invoice && $invoice->getId()) {
                    $creditmemo = $this->creditmemoFactory->createByInvoice($invoice);
                    $this->creditmemoService->refund($creditmemo);
                    
                    $this->logger->info('REFUND_WITH_INVOICE: Creditmemo created for order: ' . $order->getIncrementId());
                    
                    $order->addStatusHistoryComment(
                        sprintf(
                            'Creditmemo #%s criado automaticamente devido ao estorno de todos os métodos de pagamento.',
                            $creditmemo->getIncrementId()
                        )
                    );
                }
            }

            // Verificar se deve cancelar o pedido após creditmemo
            // Em casos de estorno total, normalmente o pedido deve ser cancelado
            if ($order->canCancel()) {
                $order->cancel();
                $order->addStatusHistoryComment('Pedido cancelado após estorno total dos pagamentos');
                $this->logger->info('REFUND_WITH_INVOICE: Order canceled after full refund: ' . $order->getIncrementId());
            }

            $this->orderRepository->save($order);
            return true;

        } catch (\Exception $e) {
            $this->logger->error('REFUND_WITH_INVOICE: Error processing refund with invoice: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle cancellation when order has no invoice (CENÁRIO B: Cartão+PIX/Boleto)
     * Cancela pedido diretamente sem necessidade de creditmemo
     *
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    private function handleCancellationWithoutInvoice($order)
    {
        try {
            $this->logger->info('CANCEL_WITHOUT_INVOICE: Processing order ' . $order->getIncrementId() . ' - no invoice exists');
            
            // Adicionar comentário explicativo
            $order->addStatusHistoryComment(
                'Pedido cancelado: Métodos de pagamento estornados antes da geração da invoice. ' .
                'Cancelamento direto sem necessidade de creditmemo.'
            );

            // Cancelar pedido diretamente
            if ($order->canCancel()) {
                $order->cancel();
                $order->addStatusHistoryComment('Cancelamento processado - estornos já realizados na Vindi');
                $this->logger->info('CANCEL_WITHOUT_INVOICE: Order canceled directly: ' . $order->getIncrementId());
            } else {
                // Se não pode cancelar, definir status apropriado
                $order->setState('closed');
                $order->setStatus('closed');
                $order->addStatusHistoryComment('Pedido fechado devido ao estorno de todos os métodos de pagamento');
                $this->logger->info('CANCEL_WITHOUT_INVOICE: Order closed (could not cancel): ' . $order->getIncrementId());
            }

            $this->orderRepository->save($order);
            return true;

        } catch (\Exception $e) {
            $this->logger->error('CANCEL_WITHOUT_INVOICE: Error processing cancellation without invoice: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle partial refund when order has invoice (ex: estorno de 1 cartão em Cartão+Cartão)
     * Gera creditmemo parcial para o valor estornado
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Vindi\Payment\Model\PaymentSplit $canceledSplit
     * @param array $paidSplits
     * @return bool
     */
    private function handlePartialRefundWithInvoice($order, $canceledSplit, $paidSplits)
    {
        try {
            $this->logger->info('PARTIAL_REFUND_WITH_INVOICE: Processing partial refund for order ' . $order->getIncrementId());
            
            // Estornar apenas os splits que estão sendo cancelados
            foreach ($paidSplits as $paidSplit) {
                if ($paidSplit->getBillId() == $canceledSplit->getBillId()) {
                    // Este é o split que está sendo cancelado
                    $chargeId = $this->getChargeIdFromBillId($paidSplit->getBillId());
                    
                    if ($chargeId) {
                        // Executar refund na Vindi
                        $refundResult = $this->charge->refund($chargeId, ['amount' => $paidSplit->getAmount()]);
                        
                        if ($refundResult) {
                            // Marcar split como refundado
                            $paidSplit->setStatus('refunded')
                                     ->setIsRefunded(1)
                                     ->setRefundAmount($paidSplit->getAmount())
                                     ->setRefundDate(date('Y-m-d H:i:s'))
                                     ->save();

                            $this->logger->info('PARTIAL_REFUND_WITH_INVOICE: Split refunded - Bill ID: ' . $paidSplit->getBillId());
                        }
                    }
                }
            }

            // Criar creditmemo parcial se possível
            if ($order->canCreditmemo()) {
                $invoice = $order->getInvoiceCollection()->getFirstItem();
                if ($invoice && $invoice->getId()) {
                    // Para creditmemo parcial, seria necessário implementar lógica mais complexa
                    // Por enquanto, adicionar comentário sobre o estorno parcial
                    $order->addStatusHistoryComment(sprintf(
                        'Estorno parcial processado: Método "%s" (R$ %s) foi estornado. ' .
                        'Pedido mantém outros métodos de pagamento ativos.',
                        $canceledSplit->getPaymentMethod() ?: 'Método de Pagamento',
                        number_format($canceledSplit->getAmount(), 2, ',', '.')
                    ));
                    
                    $this->logger->info('PARTIAL_REFUND_WITH_INVOICE: Partial refund comment added to order ' . $order->getIncrementId());
                }
            }

            $this->orderRepository->save($order);
            return true;

        } catch (\Exception $e) {
            $this->logger->error('PARTIAL_REFUND_WITH_INVOICE: Error processing partial refund: ' . $e->getMessage());
            return false;
        }
    }
}
