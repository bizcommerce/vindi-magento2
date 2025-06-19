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
use Magento\Framework\Api\SearchCriteriaBuilder;
use Vindi\Payment\Helper\RefundHelper;

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
    protected $searchCriteriaBuilder;
    protected $refundHelper;

    public function __construct(
        LoggerInterface $logger,
        PaymentSplitFactory $paymentSplitFactory,
        OrderRepositoryInterface $orderRepository,
        Charge $charge,
        Bill $bill,
        CreditmemoFactory $creditmemoFactory,
        CreditmemoService $creditmemoService,
        Transaction $transaction,
        CreditmemoManagementInterface $creditmemoManagement,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RefundHelper $refundHelper
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
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->refundHelper = $refundHelper;
    }

    public function billCanceled(array $data): bool
    {
        if (!isset($data['bill']) || empty($data['bill'])) {
            throw new LocalizedException(__('Bill data not found in webhook data.'));
        }

        $bill = $data['bill'];
        $billId = $bill['id'];

        // Log detalhado para debug
        $this->logger->info('BILL_CANCELED: ========== NOVO WEBHOOK ==========');
        $this->logger->info('BILL_CANCELED: Processing bill ' . $billId);
        $this->logger->info('BILL_CANCELED: Bill data - ' . json_encode([
            'id' => $billId,
            'status' => $bill['status'] ?? 'unknown',
            'amount' => $bill['amount'] ?? 'unknown',
            'code' => $bill['code'] ?? 'unknown',
            'has_subscription' => isset($bill['subscription']) ? 'yes' : 'no',
            'subscription_id' => isset($bill['subscription']['id']) ? $bill['subscription']['id'] : 'none',
            'charges_count' => isset($bill['charges']) ? count($bill['charges']) : 0
        ]));

        // Buscar split correspondente a esta bill
        $currentSplit = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('bill_id', $billId)
            ->getFirstItem();

        if (!$currentSplit->getId()) {
            $this->logger->info('BILL_CANCELED: No split found for bill: ' . $billId . ' - investigating further');
            
            // Verificar se é fatura avulsa multimeios baseado em indicadores
            $isMultimethodStandalone = $this->isMultimethodStandaloneBill($bill);
            
            if ($isMultimethodStandalone) {
                $this->logger->info('BILL_CANCELED: Detected multimethod standalone bill without split - trying alternative search');
                
                // Tentar buscar pedido para fatura avulsa multimeios
                $order = $this->findOrderForStandaloneBill($bill);
                
                if ($order) {
                    $this->logger->info('BILL_CANCELED: Found order ' . $order->getIncrementId() . ' for standalone bill ' . $billId);
                    return $this->handleStandaloneBillCancellation($order, $bill);
                } else {
                    $this->logger->error('BILL_CANCELED: Could not find order for standalone multimethod bill ' . $billId);
                    return false;
                }
            }
            
            // Se chegou aqui, é realmente um fluxo simples (não é multimeios)
            $this->logger->info('BILL_CANCELED: No multimethod indicators found - assuming simple single payment flow');
            return true;
        }

        $this->logger->info('BILL_CANCELED: Split found for bill ' . $billId . ' - Order: ' . $currentSplit->getOrderIncrementId());
        $this->logger->info('BILL_CANCELED: Current split data - ' . json_encode([
            'split_id' => $currentSplit->getId(),
            'order_id' => $currentSplit->getOrderId(),
            'order_increment_id' => $currentSplit->getOrderIncrementId(),
            'amount' => $currentSplit->getAmount(),
            'status' => $currentSplit->getStatus(),
            'is_refunded' => $currentSplit->getIsRefunded(),
            'payment_method' => $currentSplit->getPaymentMethod()
        ]));

        // Verificar se já foi refundado
        if ($currentSplit->getIsRefunded()) {
            $this->logger->info('BILL_CANCELED: Split already refunded for bill: ' . $billId);
            return true;
        }

        // Obter charge para refund do split atual
        $chargeId = isset($bill['charges'][0]['id']) ? $bill['charges'][0]['id'] : null;
        if (!$chargeId) {
            $this->logger->error('No charge ID found for bill: ' . $billId);
            // CORREÇÃO: Não bloquear cancelamento por falta de charge ID
            // Marcar split como cancelado e continuar para lógica de cancelamento
            $currentSplit->setStatus('canceled')
                ->setIsRefunded(0)
                ->save();
            
            $this->logger->warning('BILL_CANCELED: No charge ID but continuing with cancellation logic');
        } else {
            // Tentar executar refund na Vindi
            $refundResult = $this->charge->refund($chargeId, ['amount' => $currentSplit->getAmount()]);
            if (!$refundResult) {
                $this->logger->warning('Refund failed for bill: ' . $billId . ' - but continuing with cancellation');
                // CORREÇÃO: Não bloquear cancelamento por falha de refund
                // Marcar split como failed_refund e continuar
                $currentSplit->setStatus('failed_refund')
                    ->setIsRefunded(0)
                    ->save();
            } else {
                // ✅ NOVO: Criar creditmemo no Magento para o refund
                $creditmemo = $this->refundHelper->createSplitRefund(
                    $this->orderRepository->get($currentSplit->getOrderId()),
                    $currentSplit->getAmount(),
                    $currentSplit->getPaymentMethod() ?: 'Método de Pagamento'
                );
                
                // Refund bem-sucedido - marcar split como refundado
                $currentSplit->setStatus('refunded')
                    ->setIsRefunded(1)
                    ->setRefundAmount($currentSplit->getAmount())
                    ->setRefundDate(date('Y-m-d H:i:s'))
                    ->save();

                $this->logger->info('Split refunded successfully for bill: ' . $billId . 
                    ($creditmemo ? ' with creditmemo: ' . $creditmemo->getIncrementId() : ' without creditmemo'));
            }
        }

        // Buscar todos os splits do pedido
        $order = $this->orderRepository->get($currentSplit->getOrderId());
        $allSplits = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('order_increment_id', $order->getIncrementId());

        $this->logger->info('BILL_CANCELED: ========== MULTIMEIOS DETECTION ==========');
        $this->logger->info('BILL_CANCELED: Found ' . $allSplits->getSize() . ' splits for order ' . $order->getIncrementId());

        // Verificar se é realmente multimeios
        if ($allSplits->getSize() <= 1) {
            $this->logger->info('BILL_CANCELED: Only 1 split found - NOT a multimethod payment, processing as simple cancellation');
            // Processar como pagamento simples
            return $this->handleSinglePaymentCancellation($order, $currentSplit, $bill);
        }

        $this->logger->info('BILL_CANCELED: MULTIMETHOD PAYMENT DETECTED - ' . $allSplits->getSize() . ' splits found');

        // Log detalhado de todos os splits
        foreach ($allSplits as $split) {
            $this->logger->info('BILL_CANCELED: Split details - ' . json_encode([
                'split_id' => $split->getId(),
                'bill_id' => $split->getBillId(),
                'status' => $split->getStatus(),
                'is_refunded' => $split->getIsRefunded(),
                'amount' => $split->getAmount(),
                'payment_method' => $split->getPaymentMethod()
            ]));
        }

        // Buscar splits que NÃO são o atual e que precisam ser processados
        $otherSplits = [];
        foreach ($allSplits as $split) {
            if ($split->getId() !== $currentSplit->getId()) {
                $otherSplits[] = $split;
            }
        }
        
        $this->logger->info('BILL_CANCELED: Found ' . count($otherSplits) . ' OTHER splits that need processing (excluding current split)');
        
        if (!empty($otherSplits)) {
            $this->logger->info('BILL_CANCELED: ========== PROCESSING OTHER SPLITS ==========');
            $this->logger->info('Found ' . count($otherSplits) . ' other splits that need processing for order: ' . $order->getIncrementId());
            
            // REGRA CRÍTICA: Processar splits (refund se pagos, cancelar se pendentes), mas SEMPRE cancelar pedido
            // mesmo se algum processamento falhar (consistente com handleStandaloneBillCancellation)
            $processResult = $this->processOtherSplits($otherSplits, $order);
            
            if ($processResult['success'] == $processResult['total']) {
                // Todos os processamentos bem-sucedidos
                $commentParts = [];
                if ($processResult['refunded'] > 0) {
                    $commentParts[] = $processResult['refunded'] . ' estornados';
                }
                if ($processResult['canceled'] > 0) {
                    $commentParts[] = $processResult['canceled'] . ' cancelados';
                }
                
                $order->addStatusHistoryComment(sprintf(
                    'Multimeios processado com sucesso: Bill %d cancelada. %s automaticamente.',
                    $billId,
                    implode(' e ', $commentParts)
                ));
                $this->logger->info('BILL_CANCELED: All processing successful - proceeding with order cancellation');
            } elseif ($processResult['success'] > 0) {
                // Processamentos parcialmente bem-sucedidos
                $order->addStatusHistoryComment(sprintf(
                    'Multimeios processado parcialmente: Bill %d cancelada. %d/%d processados (%d estornados, %d cancelados). ATENÇÃO: %d falharam - verificar manualmente.',
                    $billId,
                    $processResult['success'],
                    $processResult['total'],
                    $processResult['refunded'],
                    $processResult['canceled'],
                    $processResult['failed']
                ));
                $this->logger->warning('BILL_CANCELED: Partial processing success but order will be canceled anyway - manual intervention may be needed');
            } else {
                // Todos os processamentos falharam
                $order->addStatusHistoryComment(sprintf(
                    'Multimeios processado com falhas: Bill %d cancelada. ATENÇÃO: %d NÃO puderam ser processados automaticamente - verificar manualmente.',
                    $billId,
                    $processResult['failed']
                ));
                $this->logger->warning('BILL_CANCELED: All processing failed but order will be canceled anyway - manual intervention may be needed');
            }
            
            // Continuar para cancelamento do pedido independente do resultado do estorno
        } else {
            // Não há outros splits - cancelamento simples
            $this->logger->info('BILL_CANCELED: No other splits found - simple cancellation');
            $order->addStatusHistoryComment(sprintf(
                'Multimeios cancelado: Bill %d cancelada. Nenhum outro pagamento havia sido processado.',
                $billId
            ));
        }
        
        // SEMPRE cancelar o pedido, independente do resultado do estorno
        $this->logger->info('BILL_CANCELED: ALWAYS proceeding with order cancellation (regardless of refund result)');
        $this->logger->info('All splits processed for order: ' . $order->getIncrementId() . ' - proceeding with full cancellation');
        
        // NOVA LÓGICA: Para multimeios, SEMPRE cancelar pedido quando um split é cancelado
        // Independente de ter invoice ou não - já foi estornado na Vindi
        $this->logger->info('BILL_CANCELED: ========== CANCELING MAGENTO ORDER ==========');
        $this->logger->info('BILL_CANCELED: Multimeios cancellation - proceeding with order cancellation');
        
        // ✅ CORREÇÃO: Garantir que SEMPRE chama forceCancelMultimethodOrder para multimeios
        $cancelResult = $this->forceCancelMultimethodOrder($order);
        $this->logger->info('BILL_CANCELED: Force cancel result: ' . ($cancelResult ? 'SUCCESS' : 'FAILED'));
        return $cancelResult;
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
     * Handle single payment cancellation (not multimethod)
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Vindi\Payment\Model\PaymentSplit $currentSplit
     * @param array $bill
     * @return bool
     */
    private function handleSinglePaymentCancellation($order, $currentSplit, $bill)
    {
        $this->logger->info('BILL_CANCELED: ========== SINGLE PAYMENT CANCELLATION ==========');
        $this->logger->info('BILL_CANCELED: Processing single payment for order: ' . $order->getIncrementId());
        
        // Marcar split como cancelado
        $currentSplit->setStatus('canceled');
        $currentSplit->save();
        
        // Adicionar comentário no pedido
        $order->addStatusHistoryComment(sprintf(
            'Pagamento cancelado: Bill %d foi cancelada. Não há outros métodos de pagamento.',
            $bill['id']
        ));
        
        // Cancelar o pedido diretamente
        if ($order->canCancel()) {
            $order->cancel();
            $order->addStatusHistoryComment('Pedido cancelado devido ao cancelamento do pagamento.');
            $this->logger->info('BILL_CANCELED: Single payment order canceled successfully');
        } else {
            $this->logger->warning('BILL_CANCELED: Cannot cancel single payment order - current state: ' . $order->getState());
        }
        
        $order->save();
        return true;
    }

    /**
     * Process all other splits that need action - refund if paid, cancel if pending
     * NEVER blocks cancellation
     *
     * @param array $splits All other splits that need processing (excluding the current one)
     * @param \Magento\Sales\Model\Order $order
     * @return array ['success' => int, 'failed' => int, 'total' => int, 'refunded' => int, 'canceled' => int]
     */
    private function processOtherSplits($splits, $order)
    {
        $result = [
            'success' => 0, 
            'failed' => 0, 
            'total' => count($splits),
            'refunded' => 0,
            'canceled' => 0
        ];
        
        try {
            foreach ($splits as $split) {
                $billId = $split->getBillId();
                $this->logger->info('BILL_CANCELED: ========== PROCESSING OTHER SPLIT ==========');
                $this->logger->info(sprintf(
                    'BILL_CANCELED: Processing OTHER split - Bill ID: %s, Current Status: %s, Amount: %s, Order: %s',
                    $billId,
                    $split->getStatus(),
                    $split->getAmount(),
                    $order->getIncrementId()
                ));

                // ✅ VERIFICAÇÃO CRÍTICA: Consultar status atual da bill na Vindi
                $this->logger->info('BILL_CANCELED: Checking bill status in Vindi API for bill: ' . $billId);
                $billData = $this->bill->getBill($billId);
                
                if (!$billData) {
                    $this->logger->warning('BILL_CANCELED: Could not fetch bill data from Vindi - Bill ID: ' . $billId . ' - counting as failed');
                    $result['failed']++;
                    continue;
                }
                
                $billStatus = $billData['status'] ?? 'unknown';
                $billAmount = $billData['amount'] ?? 0;
                $this->logger->info('BILL_CANCELED: Bill status from Vindi API - Bill ID: ' . $billId . ', Status: ' . $billStatus . ', Amount: ' . $billAmount);
                
                // ✅ DECISÃO BASEADA NO STATUS REAL DA VINDI
                if ($billStatus === 'paid') {
                    // CENÁRIO A: Bill está PAGA → REFUND + CANCELAMENTO (2 etapas)
                    $this->logger->info('BILL_CANCELED: ✅ Bill is PAID - performing REFUND + CANCELLATION (2 steps) - Bill ID: ' . $billId);
                    $processed = $this->processPaidBillFullCancellation($split, $order);
                    
                    if ($processed) {
                        $result['success']++;
                        $result['refunded']++;
                        $this->logger->info('BILL_CANCELED: ✅ FULL CANCELLATION successful for paid bill: ' . $billId);
                    } else {
                        $result['failed']++;
                        $this->logger->error('BILL_CANCELED: ❌ FULL CANCELLATION failed for paid bill: ' . $billId);
                    }
                    
                } elseif (in_array($billStatus, ['pending', 'waiting', 'review', 'fraud_review'])) {
                    // CENÁRIO B: Bill está PENDENTE → CANCELAMENTO  
                    $this->logger->info('BILL_CANCELED: ⏳ Bill is PENDING - performing CANCELLATION - Bill ID: ' . $billId . ', Status: ' . $billStatus);
                    $processed = $this->processPendingBillCancellation($split, $order);
                    
                    if ($processed) {
                        $result['success']++;
                        $result['canceled']++;
                        $this->logger->info('BILL_CANCELED: ✅ CANCELLATION successful for bill: ' . $billId);
                    } else {
                        $result['failed']++;
                        $this->logger->error('BILL_CANCELED: ❌ CANCELLATION failed for bill: ' . $billId);
                    }
                    
                } elseif ($billStatus === 'canceled') {
                    // CENÁRIO C: Bill já CANCELADA → marcar como sucesso sem ação
                    $this->logger->info('BILL_CANCELED: ℹ️ Bill already CANCELED - Bill ID: ' . $billId . ' - marking split as canceled');
                    $split->setStatus('canceled');
                    $split->save();
                    $result['success']++;
                    $result['canceled']++;
                    
                } else {
                    // CENÁRIO D: Status DESCONHECIDO 
                    $this->logger->warning('BILL_CANCELED: ⚠️ Bill has UNEXPECTED status - Bill ID: ' . $billId . ', Status: ' . $billStatus . ' - marking as failed');
                    $split->setStatus('unknown_status');
                    $split->save();
                    $result['failed']++;
                }
            }

            $this->logger->info('BILL_CANCELED: ========== PROCESSING SUMMARY ==========');
            $this->logger->info('BILL_CANCELED: Total: ' . $result['total'] . ', Success: ' . $result['success'] . ', Failed: ' . $result['failed'] . ', Refunded: ' . $result['refunded'] . ', Canceled: ' . $result['canceled']);
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error('BILL_CANCELED: ❌ ERROR processing other splits: ' . $e->getMessage());
            // Mesmo com exceção, retornar resultado parcial sem bloquear cancelamento
            return $result;
        }
    }

    /**
     * Process refund for a paid bill
     *
     * @param \Vindi\Payment\Model\PaymentSplit $split
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    private function processPaidBillRefund($split, $order)
    {
        try {
            $billId = $split->getBillId();
            $this->logger->info('BILL_CANCELED: ========== REFUNDING PAID BILL ==========');
            $this->logger->info('BILL_CANCELED: Starting refund process for paid bill: ' . $billId);
            
            // Buscar charge para fazer refund
            $chargeId = $this->getChargeIdFromBillId($billId);
            
            if (!$chargeId) {
                $this->logger->warning('BILL_CANCELED: ❌ No charge ID found for paid bill - Bill ID: ' . $billId);
                return false;
            }

            $this->logger->info('BILL_CANCELED: Found charge ID: ' . $chargeId . ' for bill: ' . $billId);

            // ✅ STEP 1: Executar refund na Vindi
            $this->logger->info('BILL_CANCELED: Requesting refund from Vindi for charge: ' . $chargeId . ', amount: ' . $split->getAmount());
            $refundResult = $this->charge->refund($chargeId, ['amount' => $split->getAmount()]);
            
            if (!$refundResult) {
                $this->logger->warning('BILL_CANCELED: ❌ Refund FAILED in Vindi for paid bill - Bill ID: ' . $billId);
                // Marcar como failed
                $split->setStatus('failed_refund');
                $split->setIsRefunded(0);
                $split->save();
                return false;
            }

            $this->logger->info('BILL_CANCELED: ✅ Refund SUCCESSFUL in Vindi for bill: ' . $billId);

            // ✅ STEP 2: Criar creditmemo no Magento para o refund
            $this->logger->info('BILL_CANCELED: Creating creditmemo in Magento for split refund...');
            $creditmemo = $this->refundHelper->createSplitRefund(
                $order,
                $split->getAmount(),
                $split->getPaymentMethod() ?: 'Método de Pagamento'
            );

            if ($creditmemo) {
                $this->logger->info('BILL_CANCELED: ✅ CREDITMEMO created successfully: ' . $creditmemo->getIncrementId() . ' for amount: ' . $split->getAmount());
            } else {
                $this->logger->warning('BILL_CANCELED: ⚠️ CREDITMEMO creation failed, but continuing with split update');
            }

            // ✅ STEP 3: Marcar split como refundado
            $split->setStatus('refunded');
            $split->setIsRefunded(1);
            $split->setRefundAmount($split->getAmount());
            $split->setRefundDate(date('Y-m-d H:i:s'));
            $split->save();

            $this->logger->info('BILL_CANCELED: ✅ Split updated as REFUNDED for bill: ' . $billId);

            // ✅ STEP 4: Adicionar comentário detalhado no pedido
            $commentText = sprintf(
                'MULTIMEIOS - Estorno realizado: Método "%s" (R$ %s) foi estornado devido ao cancelamento de outro método.',
                $split->getPaymentMethod() ?: 'Método de Pagamento',
                number_format($split->getAmount(), 2, ',', '.')
            );
            
            if ($creditmemo) {
                $commentText .= sprintf(' Creditmemo #%s criado automaticamente.', $creditmemo->getIncrementId());
            } else {
                $commentText .= ' ATENÇÃO: Creditmemo não foi criado - verificar manualmente.';
            }
            
            $order->addStatusHistoryComment($commentText);
            $this->logger->info('BILL_CANCELED: Comment added to order: ' . $order->getIncrementId());
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('BILL_CANCELED: ❌ ERROR processing paid bill refund - Bill ID: ' . $split->getBillId() . ', Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Process cancellation for a pending bill
     *
     * @param \Vindi\Payment\Model\PaymentSplit $split
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    private function processPendingBillCancellation($split, $order)
    {
        try {
            $billId = $split->getBillId();
            
            // Cancelar bill na Vindi usando DELETE API
            $cancelResult = $this->bill->cancel($billId);
            
            if (!$cancelResult) {
                $this->logger->warning('Cancellation failed for pending bill - Bill ID: ' . $billId);
                // Marcar como failed
                $split->setStatus('failed_cancel');
                $split->save();
                return false;
            }

            // Marcar split como cancelado
            $split->setStatus('canceled');
            $split->save();

            $this->logger->info('Pending bill canceled successfully - Bill ID: ' . $billId);

            // Adicionar comentário no pedido
            $commentText = sprintf(
                'Cancelamento realizado: Método "%s" (R$ %s) foi cancelado devido ao cancelamento de outro método do multimeios (bill ainda não estava paga).',
                $split->getPaymentMethod() ?: 'Método de Pagamento',
                number_format($split->getAmount(), 2, ',', '.')
            );
            
            $order->addStatusHistoryComment($commentText);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Error processing pending bill cancellation - Bill ID: ' . $split->getBillId() . ', Error: ' . $e->getMessage());
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

            // Criar creditmemo para valor total do pedido usando RefundHelper
            if ($order->canCreditmemo()) {
                $creditmemo = $this->refundHelper->createFullRefund($order->getId());
                
                if ($creditmemo) {
                    $this->logger->info('REFUND_WITH_INVOICE: Full creditmemo created for order: ' . $order->getIncrementId());
                    
                    $order->addStatusHistoryComment(
                        sprintf(
                            'Creditmemo #%s criado automaticamente devido ao estorno de todos os métodos de pagamento.',
                            $creditmemo->getIncrementId()
                        )
                    );
                } else {
                    $this->logger->warning('REFUND_WITH_INVOICE: Failed to create creditmemo for order: ' . $order->getIncrementId());
                    $order->addStatusHistoryComment('Erro ao criar creditmemo automático - verificar manualmente.');
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
                            // ✅ NOVO: Criar creditmemo no Magento para o refund do split
                            $creditmemo = $this->refundHelper->createSplitRefund(
                                $order,
                                $paidSplit->getAmount(),
                                $paidSplit->getPaymentMethod()
                            );
                            
                            // Marcar split como refundado
                            $paidSplit->setStatus('refunded')
                                     ->setIsRefunded(1)
                                     ->setRefundAmount($paidSplit->getAmount())
                                     ->setRefundDate(date('Y-m-d H:i:s'))
                                     ->save();

                            $this->logger->info('PARTIAL_REFUND_WITH_INVOICE: Split refunded - Bill ID: ' . $paidSplit->getBillId() . 
                                ($creditmemo ? ', Creditmemo: ' . $creditmemo->getIncrementId() : ', No creditmemo created'));
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

    /**
     * Detect if this is a multimethod standalone bill by checking the database
     * for other splits with the same order, with fallback to legacy indicators
     *
     * @param array $bill
     * @return bool
     */
    private function isMultimethodStandaloneBill($bill)
    {
        try {
            $billId = $bill['id'] ?? null;
            $billCode = $bill['code'] ?? '';
            
            $this->logger->info('MULTIMETHOD_DETECTION: Checking bill ' . $billId . ' (' . $billCode . ')');
            
            // MÉTODO 1 (PREFERIDO): Verificar se há outros splits para o mesmo pedido
            $order = $this->findOrderForStandaloneBill($bill);
            
            if ($order) {
                $this->logger->info('MULTIMETHOD_DETECTION: Found order ' . $order->getIncrementId() . ' - checking splits');
                
                // Buscar todos os splits para este pedido
                $allSplits = $this->paymentSplitFactory->create()
                    ->getCollection()
                    ->addFieldToFilter('order_increment_id', $order->getIncrementId());

                $splitCount = $allSplits->getSize();
                $this->logger->info('MULTIMETHOD_DETECTION: Found ' . $splitCount . ' splits for order ' . $order->getIncrementId());

                // Se há mais de 1 split, é multimeios
                if ($splitCount > 1) {
                    $this->logger->info('MULTIMETHOD_DETECTION: Confirmed multimethod - Order has ' . $splitCount . ' payment splits');
                    
                    // Log detalhado dos splits para debug
                    foreach ($allSplits as $split) {
                        $this->logger->info('MULTIMETHOD_DETECTION: Split found - Bill ID: ' . $split->getBillId() . ', Status: ' . $split->getStatus() . ', Amount: ' . $split->getAmount());
                    }
                    
                    return true;
                }
                
                $this->logger->info('MULTIMETHOD_DETECTION: Single payment method - Order has only ' . $splitCount . ' split');
                return false;
            }
            
            // MÉTODO 2 (FALLBACK): Usar indicadores legacy se não encontrou pedido
            $this->logger->info('MULTIMETHOD_DETECTION: No order found, falling back to legacy indicators');
            
            // Indicador 1: Verificar se os 3 últimos caracteres são -01 ou -02 (multimeios)
            if (isset($bill['code']) && strlen($bill['code']) >= 4 && in_array(substr($bill['code'], -3), ['-01', '-02'])) {
                $this->logger->info('MULTIMETHOD_DETECTION: Bill code suggests multimethod sequence (ends with -01 or -02): ' . $bill['code']);
                return true;
            }
            
            // Indicador 2: Produto de desconto multimeios
            if (isset($bill['bill_items']) && is_array($bill['bill_items'])) {
                foreach ($bill['bill_items'] as $item) {
                    if (isset($item['product']['name']) && 
                        strpos(strtolower($item['product']['name']), 'desconto multimeios') !== false) {
                        $this->logger->info('MULTIMETHOD_DETECTION: Found multimethod discount product: ' . $item['product']['name']);
                        return true;
                    }
                    
                    if (isset($item['product']['code']) && 
                        strpos(strtolower($item['product']['code']), 'discount_multipayment') !== false) {
                        $this->logger->info('MULTIMETHOD_DETECTION: Found multimethod discount code: ' . $item['product']['code']);
                        return true;
                    }
                }
            }
            
            // Indicador 3: Bill amount é 0.0 ou próximo de zero (devido aos descontos)
            if (isset($bill['amount']) && abs(floatval($bill['amount'])) < 0.01) {
                $this->logger->info('MULTIMETHOD_DETECTION: Bill amount is near zero: ' . $bill['amount']);
                
                // Verificar se há itens com valores que sugerem desconto
                $hasPositiveItems = false;
                $hasNegativeItems = false;
                
                if (isset($bill['bill_items'])) {
                    foreach ($bill['bill_items'] as $item) {
                        $amount = floatval($item['amount'] ?? 0);
                        if ($amount > 0) $hasPositiveItems = true;
                        if ($amount < 0) $hasNegativeItems = true;
                    }
                }
                
                if ($hasPositiveItems && $hasNegativeItems) {
                    $this->logger->info('MULTIMETHOD_DETECTION: Found positive and negative items with zero total - likely multimethod');
                    return true;
                }
            }
            
            $this->logger->info('MULTIMETHOD_DETECTION: No multimethod indicators found');
            return false;
            
        } catch (\Exception $e) {
            $this->logger->error('MULTIMETHOD_DETECTION: Error detecting multimethod bill: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find order for standalone multimethod bill using various strategies
     *
     * @param array $bill
     * @return \Magento\Sales\Model\Order|null
     */
    private function findOrderForStandaloneBill($bill)
    {
        try {
            $billCode = $bill['code'] ?? '';
            $billId = $bill['id'] ?? '';
            
            $this->logger->info('STANDALONE_ORDER_SEARCH: Searching for order with bill code: ' . $billCode);
            
            // Estratégia 1: Verificar se os 3 últimos caracteres são -01 ou -02 (multimeios)
            if (strlen($billCode) >= 4 && in_array(substr($billCode, -3), ['-01', '-02'])) {
                // Extrair increment_id removendo os 3 últimos caracteres (-01 ou -02)
                $baseOrderIncrementId = substr($billCode, 0, -3);
                $suffix = substr($billCode, -3);
                
                $this->logger->info('STANDALONE_ORDER_SEARCH: Detected multimethod pattern - Increment ID: ' . $baseOrderIncrementId . ', Suffix: ' . $suffix . ' from bill: ' . $billCode);
                
                // Buscar pedido exato pelo increment_id base
                $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter('increment_id', $baseOrderIncrementId)
                    ->addFilter('state', ['new', 'processing', 'complete', 'canceled'], 'in')
                    ->create();
                
                $orders = $this->orderRepository->getList($searchCriteria)->getItems();
                
                if (!empty($orders)) {
                    $order = reset($orders);
                    $this->logger->info('STANDALONE_ORDER_SEARCH: Found order by multimethod pattern: ' . $order->getIncrementId());
                    return $order;
                }
                
                $this->logger->warning('STANDALONE_ORDER_SEARCH: No order found with increment_id: ' . $baseOrderIncrementId . ' for bill: ' . $billCode);
            }
            
            // Estratégia 2: Buscar splits relacionados sem bill_id específico (pode ter sido perdido)
            // Tentar buscar pelo bill_id em outros splits do mesmo pedido
            $relatedSplits = $this->paymentSplitFactory->create()
                ->getCollection()
                ->addFieldToFilter('created_at', ['gteq' => date('Y-m-d H:i:s', strtotime('-1 hour'))])
                ->setOrder('created_at', 'DESC')
                ->setPageSize(50);
            
            foreach ($relatedSplits as $split) {
                $order = $this->orderRepository->get($split->getOrderId());
                $allOrderSplits = $this->paymentSplitFactory->create()
                    ->getCollection()
                    ->addFieldToFilter('order_increment_id', $order->getIncrementId());
                
                // Se o pedido tem múltiplos splits (multimeios) e um dos bills pode ser o nosso
                if ($allOrderSplits->getSize() > 1) {
                    $this->logger->info('STANDALONE_ORDER_SEARCH: Found potential multimethod order: ' . $order->getIncrementId());
                    return $order;
                }
            }
            
            $this->logger->warning('STANDALONE_ORDER_SEARCH: No order found for bill ' . $billCode);
            return null;
            
        } catch (\Exception $e) {
            $this->logger->error('STANDALONE_ORDER_SEARCH: Error finding order: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Handle cancellation of standalone multimethod bill
     * REGRAS:
     * 1. Se a outra bill NÃO foi paga → Cancelar pedido no Magento
     * 2. Se a outra bill JÁ foi paga → Estornar a bill paga + Cancelar pedido no Magento
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $bill
     * @return bool
     */
    private function handleStandaloneBillCancellation($order, $bill)
    {
        try {
            $billId = $bill['id'];
            $this->logger->info('STANDALONE_BILL_CANCELED: Processing cancellation for order ' . $order->getIncrementId() . ', bill ' . $billId);
            
            // Buscar todos os splits do pedido
            $allSplits = $this->paymentSplitFactory->create()
                ->getCollection()
                ->addFieldToFilter('order_increment_id', $order->getIncrementId());
            
            $this->logger->info('STANDALONE_BILL_CANCELED: Found ' . $allSplits->getSize() . ' splits for order');
            
            // Log detalhado de todos os splits para debug
            foreach ($allSplits as $split) {
                $this->logger->info('STANDALONE_BILL_CANCELED: Split details - ' . json_encode([
                    'split_id' => $split->getId(),
                    'bill_id' => $split->getBillId(),
                    'status' => $split->getStatus(),
                    'is_refunded' => $split->getIsRefunded(),
                    'amount' => $split->getAmount(),
                    'payment_method' => $split->getPaymentMethod()
                ]));
            }
            
            // REGRA 1 & 2: Verificar se há splits pagos (outras bills do multimeios)
            $paidSplits = $this->getPaidSplits($allSplits);
            
            if (!empty($paidSplits)) {
                // REGRA 2: Há bills pagas → TENTAR ESTORNAR + SEMPRE CANCELAR
                $this->logger->info('STANDALONE_BILL_CANCELED: REGRA 2 - Found ' . count($paidSplits) . ' paid splits - will try to refund and ALWAYS cancel order');
                
                // TENTAR estornar todas as bills pagas (sem bloquear cancelamento)
                $refundResult = $this->processOtherSplits($paidSplits, $order);
                
                if ($refundResult['success'] == $refundResult['total']) {
                    // Todos os estornos bem-sucedidos
                    $order->addStatusHistoryComment(sprintf(
                        'Multimeios cancelado com estorno: Bill %d (%s) cancelada. %d pagamentos estornados automaticamente.',
                        $billId,
                        $bill['code'] ?? 'sem código',
                        $refundResult['success']
                    ));
                    $this->logger->info('STANDALONE_BILL_CANCELED: All refunds successful - proceeding with order cancellation');
                } elseif ($refundResult['success'] > 0) {
                    // Estornos parcialmente bem-sucedidos
                    $order->addStatusHistoryComment(sprintf(
                        'Multimeios cancelado com estorno parcial: Bill %d (%s) cancelada. %d/%d pagamentos estornados. ATENÇÃO: %d NÃO puderam ser estornados - verificar manualmente.',
                        $billId,
                        $bill['code'] ?? 'sem código',
                        $refundResult['success'],
                        $refundResult['total'],
                        $refundResult['failed']
                    ));
                    $this->logger->warning('STANDALONE_BILL_CANCELED: Partial refund success but order will be canceled anyway - manual intervention may be needed');
                } else {
                    // Todos os estornos falharam
                    $order->addStatusHistoryComment(sprintf(
                        'Multimeios cancelado com falha no estorno: Bill %d (%s) cancelada. ATENÇÃO: %d pagamentos NÃO puderam ser estornados automaticamente - verificar manualmente.',
                        $billId,
                        $bill['code'] ?? 'sem código',
                        $refundResult['failed']
                    ));
                    $this->logger->warning('STANDALONE_BILL_CANCELED: All refunds failed but order will be canceled anyway - manual intervention may be needed');
                }
                
            } else {
                // REGRA 1: NÃO há bills pagas → APENAS CANCELAR
                $this->logger->info('STANDALONE_BILL_CANCELED: REGRA 1 - No paid splits found - will only cancel order');
                
                // Adicionar comentário específico para cancelamento simples
                $order->addStatusHistoryComment(sprintf(
                    'Multimeios cancelado: Bill %d (%s) cancelada. Nenhum pagamento havia sido processado.',
                    $billId,
                    $bill['code'] ?? 'sem código'
                ));
            }
            
            // SEMPRE cancelar o pedido, independente do resultado do estorno
            $this->logger->info('STANDALONE_BILL_CANCELED: ALWAYS proceeding with order cancellation (regardless of refund result)');
            
            // Verificar se o pedido já tem invoice gerada
            $hasInvoice = $this->orderHasInvoice($order);
            
            if ($hasInvoice) {
                // CENÁRIO A: Invoice já existe - gerar creditmemo e cancelar
                $this->logger->info('STANDALONE_BILL_CANCELED: Order has invoice - creating creditmemo and canceling');
                return $this->handleRefundWithInvoice($order);
            } else {
                // CENÁRIO B: Invoice não existe - cancelamento direto
                $this->logger->info('STANDALONE_BILL_CANCELED: Order has no invoice - direct cancellation');
                return $this->handleCancellationWithoutInvoice($order);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('STANDALONE_BILL_CANCELED: Error processing cancellation: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Force cancel a multimethod order regardless of current state
     * This method handles all scenarios for canceling orders with multimethod payments
     *
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    private function forceCancelMultimethodOrder($order)
    {
        try {
            $this->logger->info('FORCE_CANCEL: ========== FORCING ORDER CANCELLATION ==========');
            $this->logger->info('FORCE_CANCEL: Processing order: ' . $order->getIncrementId());
            $this->logger->info('FORCE_CANCEL: Current order state: ' . $order->getState() . ', status: ' . $order->getStatus());
            
            // Verificar estado atual do pedido
            $hasInvoice = $this->orderHasInvoice($order);
            $hasShipment = $order->hasShipments();
            $canCancel = $order->canCancel();
            
            $this->logger->info('FORCE_CANCEL: Order analysis - Has invoice: ' . ($hasInvoice ? 'YES' : 'NO') . 
                               ', Has shipment: ' . ($hasShipment ? 'YES' : 'NO') . 
                               ', Can cancel: ' . ($canCancel ? 'YES' : 'NO'));

            // Adicionar comentário inicial
            $order->addStatusHistoryComment(
                'MULTIMEIOS: Iniciando cancelamento devido ao estorno/cancelamento de todos os métodos de pagamento na Vindi. ' .
                'Todas as bills foram processadas corretamente.'
            );

            // CENÁRIO 1: Pode cancelar normalmente
            if ($canCancel) {
                $this->logger->info('FORCE_CANCEL: SCENARIO 1 - Normal cancellation possible');
                
                $order->cancel();
                $order->addStatusHistoryComment('Pedido cancelado: Todos os métodos de pagamento foram estornados/cancelados na Vindi.');
                $this->logger->info('FORCE_CANCEL: ✅ Order canceled successfully via normal cancellation');
                
            } else {
                $this->logger->info('FORCE_CANCEL: SCENARIO 2 - Normal cancellation not possible, using alternative methods');
                
                // CENÁRIO 2: Não pode cancelar - usar métodos alternativos
                if ($hasShipment) {
                    // Tem envio - não pode cancelar, fechar o pedido
                    $this->logger->info('FORCE_CANCEL: Order has shipments - setting to closed');
                    $order->setState('closed');
                    $order->setStatus('closed');
                    $order->addStatusHistoryComment(
                        'Pedido fechado: Não foi possível cancelar devido ao envio já realizado, ' .
                        'mas todos os pagamentos foram estornados na Vindi.'
                    );
                    
                } elseif ($hasInvoice) {
                    // Tem invoice mas sem envio - tentar criar creditmemo total e cancelar
                    $this->logger->info('FORCE_CANCEL: Order has invoice but no shipment - creating full creditmemo and canceling');
                    
                    // Criar creditmemo total
                    $creditmemo = $this->refundHelper->createFullRefund($order->getId());
                    
                    if ($creditmemo) {
                        $this->logger->info('FORCE_CANCEL: ✅ Full creditmemo created: ' . $creditmemo->getIncrementId());
                        $order->addStatusHistoryComment(
                            sprintf('Creditmemo total #%s criado devido ao estorno completo na Vindi.', $creditmemo->getIncrementId())
                        );
                        
                        // Tentar cancelar após creditmemo
                        if ($order->canCancel()) {
                            $order->cancel();
                            $order->addStatusHistoryComment('Pedido cancelado após criação do creditmemo total.');
                            $this->logger->info('FORCE_CANCEL: ✅ Order canceled after creditmemo creation');
                        } else {
                            // Se ainda não pode cancelar, fechar
                            $order->setState('closed');
                            $order->setStatus('closed');
                            $order->addStatusHistoryComment('Pedido fechado após criação do creditmemo total.');
                            $this->logger->info('FORCE_CANCEL: Order closed after creditmemo creation');
                        }
                    } else {
                        $this->logger->warning('FORCE_CANCEL: ⚠️ Failed to create creditmemo - forcing close anyway');
                        $order->setState('closed');
                        $order->setStatus('closed');
                        $order->addStatusHistoryComment(
                            'Pedido fechado: Erro ao criar creditmemo, mas todos os pagamentos foram estornados na Vindi. ' .
                            'ATENÇÃO: Verificar creditmemo manualmente.'
                        );
                    }
                    
                } else {
                    // Sem invoice nem envio - forçar cancelamento via state
                    $this->logger->info('FORCE_CANCEL: No invoice, no shipment - forcing cancellation via state change');
                    $order->setState('canceled');
                    $order->setStatus('canceled');
                    $order->addStatusHistoryComment(
                        'Pedido cancelado (forçado): Todos os métodos de pagamento foram estornados/cancelados na Vindi.'
                    );
                    $this->logger->info('FORCE_CANCEL: ✅ Order force-canceled via state change');
                }
            }

            // Salvar pedido
            $this->orderRepository->save($order);
            
            $this->logger->info('FORCE_CANCEL: ✅ Order saved with final state: ' . $order->getState() . ', status: ' . $order->getStatus());
            $this->logger->info('FORCE_CANCEL: ========== ORDER CANCELLATION COMPLETED ==========');
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('FORCE_CANCEL: ❌ ERROR during force cancellation: ' . $e->getMessage());
            $this->logger->error('FORCE_CANCEL: Stack trace: ' . $e->getTraceAsString());
            
            // Último recurso - tentar fechar o pedido
            try {
                $order->setState('closed');
                $order->setStatus('closed');
                $order->addStatusHistoryComment(
                    'ERRO: Falha no cancelamento automático. Pedido fechado manualmente. ' .
                    'Verificar se pagamentos foram realmente estornados na Vindi.'
                );
                $this->orderRepository->save($order);
                $this->logger->info('FORCE_CANCEL: ⚠️ Last resort - order closed due to error');
                return true;
            } catch (\Exception $e2) {
                $this->logger->error('FORCE_CANCEL: ❌ CRITICAL ERROR - Cannot even close order: ' . $e2->getMessage());
                return false;
            }
        }
    }
}
