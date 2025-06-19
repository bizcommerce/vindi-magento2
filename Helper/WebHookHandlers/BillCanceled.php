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
        $this->logger->info('BILL_CANCELED: Processing bill ' . $billId);
        $this->logger->info('BILL_CANCELED: Bill data - ' . json_encode([
            'id' => $billId,
            'status' => $bill['status'] ?? 'unknown',
            'amount' => $bill['amount'] ?? 'unknown',
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

        $this->logger->info('BILL_CANCELED: Found ' . $allSplits->getSize() . ' splits for order ' . $order->getIncrementId());

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

        // Verificar se há splits já pagos (precisam ser estornados)
        $paidSplits = $this->getPaidSplits($allSplits);
        
        $this->logger->info('BILL_CANCELED: Found ' . count($paidSplits) . ' paid splits that need refund');
        
        if (!empty($paidSplits)) {
            $this->logger->info('Found paid splits that need refund for order: ' . $order->getIncrementId());
            
            // REGRA CRÍTICA: Tentar estornar splits pagos, mas SEMPRE cancelar pedido
            // mesmo se o estorno falhar (consistente com handleStandaloneBillCancellation)
            $refundResult = $this->refundPaidSplits($paidSplits, $order);
            
            if ($refundResult['success'] == $refundResult['total']) {
                // Todos os estornos bem-sucedidos
                $order->addStatusHistoryComment(sprintf(
                    'Multimeios cancelado com estorno: Bill %d cancelada. %d pagamentos estornados automaticamente.',
                    $billId,
                    $refundResult['success']
                ));
                $this->logger->info('BILL_CANCELED: All refunds successful - proceeding with order cancellation');
            } elseif ($refundResult['success'] > 0) {
                // Estornos parcialmente bem-sucedidos
                $order->addStatusHistoryComment(sprintf(
                    'Multimeios cancelado com estorno parcial: Bill %d cancelada. %d/%d pagamentos estornados. ATENÇÃO: %d NÃO puderam ser estornados - verificar manualmente.',
                    $billId,
                    $refundResult['success'],
                    $refundResult['total'],
                    $refundResult['failed']
                ));
                $this->logger->warning('BILL_CANCELED: Partial refund success but order will be canceled anyway - manual intervention may be needed');
            } else {
                // Todos os estornos falharam
                $order->addStatusHistoryComment(sprintf(
                    'Multimeios cancelado com falha no estorno: Bill %d cancelada. ATENÇÃO: %d pagamentos NÃO puderam ser estornados automaticamente - verificar manualmente.',
                    $billId,
                    $refundResult['failed']
                ));
                $this->logger->warning('BILL_CANCELED: All refunds failed but order will be canceled anyway - manual intervention may be needed');
            }
            
            // Continuar para cancelamento do pedido independente do resultado do estorno
        } else {
            // Não há splits pagos - cancelamento simples
            $this->logger->info('BILL_CANCELED: No paid splits found - simple cancellation');
            $order->addStatusHistoryComment(sprintf(
                'Multimeios cancelado: Bill %d cancelada. Nenhum pagamento havia sido processado.',
                $billId
            ));
        }
        
        // SEMPRE cancelar o pedido, independente do resultado do estorno
        $this->logger->info('BILL_CANCELED: ALWAYS proceeding with order cancellation (regardless of refund result)');
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
     * Refund all paid splits - NEVER blocks cancellation
     *
     * @param array $paidSplits
     * @param \Magento\Sales\Model\Order $order
     * @return array ['success' => int, 'failed' => int, 'total' => int]
     */
    private function refundPaidSplits($paidSplits, $order)
    {
        $result = ['success' => 0, 'failed' => 0, 'total' => count($paidSplits)];
        
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
                    $this->logger->warning('No charge ID found for paid split - Bill ID: ' . $split->getBillId() . ' - counting as failed but continuing');
                    $result['failed']++;
                    continue;
                }

                // Executar refund na Vindi
                $refundResult = $this->charge->refund($chargeId, ['amount' => $split->getAmount()]);
                
                if (!$refundResult) {
                    $this->logger->warning('Refund failed for paid split - Bill ID: ' . $split->getBillId() . ' - marking as failed but continuing');
                    // Marcar como failed e continuar
                    $split->setStatus('failed_refund')
                        ->setIsRefunded(0)
                        ->save();
                    $result['failed']++;
                    continue;
                }

                // ✅ NOVO: Criar creditmemo no Magento para o refund do split
                $creditmemo = $this->refundHelper->createSplitRefund(
                    $order,
                    $split->getAmount(),
                    $split->getPaymentMethod()
                );

                // Marcar split como refundado
                $split->setStatus('refunded')
                    ->setIsRefunded(1)
                    ->setRefundAmount($split->getAmount())
                    ->setRefundDate(date('Y-m-d H:i:s'))
                    ->save();

                $this->logger->info('Paid split refunded successfully with creditmemo - Bill ID: ' . $split->getBillId() . 
                    ($creditmemo ? ', Creditmemo: ' . $creditmemo->getIncrementId() : ', No creditmemo created'));
                $result['success']++;

                // Adicionar comentário detalhado no pedido
                $commentText = sprintf(
                    'Estorno realizado: Método "%s" (R$ %s) foi estornado devido ao cancelamento de outro método do multimeios.',
                    $split->getPaymentMethod() ?: 'Método de Pagamento',
                    number_format($split->getAmount(), 2, ',', '.')
                );
                
                if ($creditmemo) {
                    $commentText .= sprintf(' Creditmemo #%s criado.', $creditmemo->getIncrementId());
                }
                
                $order->addStatusHistoryComment($commentText);
            }

            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error('Error refunding paid splits: ' . $e->getMessage());
            // Mesmo com exceção, retornar resultado parcial sem bloquear cancelamento
            return $result;
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
            
            // Indicador 1: Código da bill sugere sequência (ex: BIZ-VINDI-000006563-02)
            if (isset($bill['code']) && preg_match('/-\d{2}$/', $bill['code'])) {
                $this->logger->info('MULTIMETHOD_DETECTION: Bill code suggests sequence: ' . $bill['code']);
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
            
            // Estratégia 1: Extrair número base do código da bill
            if (preg_match('/BIZ-VINDI-(\d+)-\d{2}$/', $billCode, $matches)) {
                $baseOrderNumber = $matches[1];
                $this->logger->info('STANDALONE_ORDER_SEARCH: Extracted base order number: ' . $baseOrderNumber);
                
                // Buscar pedido com increment_id que contenha esse número
                $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter('increment_id', '%' . $baseOrderNumber . '%', 'like')
                    ->addFilter('state', ['new', 'processing', 'complete', 'canceled'], 'in')
                    ->create();
                
                $orders = $this->orderRepository->getList($searchCriteria)->getItems();
                
                if (!empty($orders)) {
                    $order = reset($orders);
                    $this->logger->info('STANDALONE_ORDER_SEARCH: Found order by base number: ' . $order->getIncrementId());
                    return $order;
                }
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
                $refundResult = $this->refundPaidSplits($paidSplits, $order);
                
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
}
