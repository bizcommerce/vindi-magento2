<?php
namespace Vindi\Payment\Helper\WebHookHandlers;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Vindi\Payment\Model\PaymentSplitFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Vindi\Payment\Helper\RefundHelper;
use Vindi\Payment\Helper\InvoiceBillHelper;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order\Invoice;

/**
 * Class ChargeRefunded
 *
 * Simplified handler for charge_refunded webhook.
 * Main logic is handled by BillCanceled since Vindi always sends bill_canceled after charge_refunded.
 */
class ChargeRefunded
{
    protected $logger;
    protected $paymentSplitFactory;
    protected $orderRepository;
    protected $searchCriteriaBuilder;
    protected $refundHelper;
    protected $invoiceBillHelper;
    protected $invoiceRepository;

    public function __construct(
        LoggerInterface $logger,
        PaymentSplitFactory $paymentSplitFactory,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RefundHelper $refundHelper,
        InvoiceBillHelper $invoiceBillHelper,
        InvoiceRepositoryInterface $invoiceRepository
    ) {
        $this->logger = $logger;
        $this->paymentSplitFactory = $paymentSplitFactory;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->refundHelper = $refundHelper;
        $this->invoiceBillHelper = $invoiceBillHelper;
        $this->invoiceRepository = $invoiceRepository;
    }

    public function chargeRefunded(array $data): bool
    {
        if (!isset($data['charge']) || empty($data['charge'])) {
            throw new LocalizedException(__('Charge data not found in webhook data.'));
        }

        $charge = $data['charge'];
        $chargeId = $charge['id'];
        $refundAmount = $charge['amount'];
        
        // Primeiro, tenta pegar bill data diretamente do webhook
        $billData = $data["bill"] ?? null;
        $billId = null;
        $billCode = '';

        if ($billData && isset($billData['id'])) {
            $billId = $billData['id'];
            $billCode = $billData['code'] ?? '';
            $this->logger->info('CHARGE_REFUNDED: Bill data found in webhook - Bill ID: ' . $billId . ', Code: ' . $billCode);
        } else {
            // Se não tem bill data no webhook, tenta buscar pela bill_id do charge
            if (isset($charge['bill_id'])) {
                $billId = $charge['bill_id'];
                $this->logger->info('CHARGE_REFUNDED: Using bill_id from charge: ' . $billId);
            } elseif (isset($charge['bill']['id'])) {
                $billId = $charge['bill']['id'];
                $billCode = $charge['bill']['code'] ?? '';
                $this->logger->info('CHARGE_REFUNDED: Using bill data from charge: Bill ID: ' . $billId . ', Code: ' . $billCode);
            } else {
                // Última tentativa: buscar pela invoice com o charge_id (se tivermos essa informação salva)
                $this->logger->warning('CHARGE_REFUNDED: No bill data found for charge ' . $chargeId . ' - trying to find by existing data');
                
                // Tenta buscar ordem através de payment splits
                $billId = $this->findBillIdByChargeId($chargeId);
                if (!$billId) {
                    $this->logger->error('CHARGE_REFUNDED: Could not determine bill ID for charge ' . $chargeId);
                    return false;
                }
            }
        }

        $this->logger->info('CHARGE_REFUNDED: Processing refunded charge ' . $chargeId);
        $this->logger->info('CHARGE_REFUNDED: Charge data - ' . json_encode([
                'charge_id' => $chargeId,
                'amount' => $refundAmount,
                'bill_id' => $billId,
                'bill_code' => $billCode,
                'payment_method' => $charge['payment_method']['code'] ?? 'unknown'
            ]));

        // Se não tem bill code, tenta construir baseado no bill ID
        if (empty($billCode) && $billId) {
            $billCode = $this->findBillCodeByBillId($billId);
        }

        $isMultimethod = $this->isMultimethodBill($billCode, $billId);

        if ($isMultimethod) {
            $this->logger->info('CHARGE_REFUNDED: Detected multimethod bill - canceling invoice for bill ID: ' . $billId);
            $this->cancelInvoiceByBillId($billId, $chargeId, $refundAmount);
        } else {
            $this->logger->info('CHARGE_REFUNDED: Single payment method - checking if creditmemo should be created');

            $order = $this->findOrderForBill($billCode);
            if (!$order && $billId) {
                // Tenta buscar ordem pela invoice que tem o bill_id
                $order = $this->findOrderByBillId($billId);
            }
            
            if ($order) {
                // Verifica o estado do pedido e se já foi reembolsado
                $this->logger->info('CHARGE_REFUNDED: Order state: ' . $order->getState() . 
                    ', Status: ' . $order->getStatus() . 
                    ', Total refunded: ' . $order->getTotalRefunded() . 
                    ', Grand total: ' . $order->getGrandTotal());
                
                $availableRefundAmount = $order->getGrandTotal() - $order->getTotalRefunded();
                
                if ($availableRefundAmount < $refundAmount) {
                    $this->logger->warning('CHARGE_REFUNDED: Insufficient refund amount available. ' .
                        'Available: ' . $availableRefundAmount . ', Requested: ' . $refundAmount . 
                        '. Skipping creditmemo creation and only adding comment.');
                    
                    // Apenas adiciona comentário sem criar creditmemo
                    $commentText = sprintf(
                        'Estorno detectado: Charge %d estornado (R$ %s) - Creditmemo não criado devido a valor insuficiente disponível (R$ %s)',
                        $chargeId,
                        number_format($refundAmount, 2, ',', '.'),
                        number_format($availableRefundAmount, 2, ',', '.')
                    );
                    
                    $order->addStatusHistoryComment($commentText);
                    $this->orderRepository->save($order);
                    
                    $this->logger->info('CHARGE_REFUNDED: Comment added to order without creating creditmemo');
                } else {
                    // Prossegue com a criação do creditmemo
                    try {
                        $creditmemo = $this->refundHelper->createSplitRefund(
                            $order,
                            $refundAmount,
                            $charge['payment_method']['name'] ?? 'Método de Pagamento'
                        );

                        $commentText = sprintf(
                            'Estorno detectado: Charge %d estornado (R$ %s)',
                            $chargeId,
                            number_format($refundAmount, 2, ',', '.')
                        );

                        if ($creditmemo) {
                            $commentText .= sprintf('. Creditmemo #%s criado.', $creditmemo->getIncrementId());
                        }

                        $order->addStatusHistoryComment($commentText);
                        $this->orderRepository->save($order);

                        $this->logger->info('CHARGE_REFUNDED: Single payment creditmemo created' .
                            ($creditmemo ? ' - Creditmemo: ' . $creditmemo->getIncrementId() : ' - Failed to create creditmemo'));
                            
                    } catch (\Exception $e) {
                        $this->logger->error('CHARGE_REFUNDED: Error creating creditmemo: ' . $e->getMessage());
                        
                        // Adiciona comentário mesmo que falhe ao criar creditmemo
                        $commentText = sprintf(
                            'Estorno detectado: Charge %d estornado (R$ %s) - Erro ao criar creditmemo: %s',
                            $chargeId,
                            number_format($refundAmount, 2, ',', '.'),
                            $e->getMessage()
                        );
                        
                        $order->addStatusHistoryComment($commentText);
                        $this->orderRepository->save($order);
                    }
                }
            } else {
                $this->logger->error('CHARGE_REFUNDED: Could not find order for bill code: ' . $billCode . ' or bill ID: ' . $billId);
            }
        }

        $this->logger->info('CHARGE_REFUNDED: Webhook processed successfully.');

        return true;
    }

    /**
     * Check if this is a multimethod bill by verifying if there are other bills
     * for the same order in the payment splits table
     *
     * @param string $billCode
     * @param int $billId
     * @return bool
     */
    private function isMultimethodBill($billCode, $billId)
    {
        try {

            $order = $this->findOrderForBill($billCode);

            if (!$order) {
                $this->logger->warning('CHARGE_REFUNDED: Could not find order for bill code: ' . $billCode . ' - cannot determine if multimethod');
                return false;
            }


            $allSplits = $this->paymentSplitFactory->create()
                ->getCollection()
                ->addFieldToFilter('order_increment_id', $order->getIncrementId());

            $splitCount = $allSplits->getSize();
            $this->logger->info('CHARGE_REFUNDED: Found ' . $splitCount . ' splits for order ' . $order->getIncrementId());


            if ($splitCount > 1) {
                $this->logger->info('CHARGE_REFUNDED: Multimethod detected - Order ' . $order->getIncrementId() . ' has ' . $splitCount . ' payment splits');
                return true;
            }

            $this->logger->info('CHARGE_REFUNDED: Single payment method - Order ' . $order->getIncrementId() . ' has only ' . $splitCount . ' split');
            return false;

        } catch (\Exception $e) {
            $this->logger->error('CHARGE_REFUNDED: Error checking if multimethod bill: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find order for bill using code pattern
     *
     * @param string $billCode
     * @return \Magento\Sales\Model\Order|null
     */
    private function findOrderForBill($billCode)
    {
        try {

            if (strlen($billCode) >= 4 && in_array(substr($billCode, -3), ['-01', '-02'])) {

                $baseOrderIncrementId = substr($billCode, 0, -3);
                $suffix = substr($billCode, -3);

                $this->logger->info('CHARGE_REFUNDED: Detected multimethod pattern - Increment ID: ' . $baseOrderIncrementId . ', Suffix: ' . $suffix . ' from bill: ' . $billCode);


                $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter('increment_id', $baseOrderIncrementId)
                    ->addFilter('state', ['new', 'processing', 'complete', 'canceled'], 'in')
                    ->create();

                $orders = $this->orderRepository->getList($searchCriteria)->getItems();

                if (!empty($orders)) {
                    $order = reset($orders);
                    $this->logger->info('CHARGE_REFUNDED: Found order ' . $order->getIncrementId() . ' for multimethod bill ' . $billCode);
                    return $order;
                }

                $this->logger->warning('CHARGE_REFUNDED: No order found with increment_id: ' . $baseOrderIncrementId . ' for bill: ' . $billCode);
            } else {
                $this->logger->info('CHARGE_REFUNDED: Bill code does not end with -01 or -02, not a multimethod bill: ' . $billCode);
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('CHARGE_REFUNDED: Error finding order: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Cancel invoice by bill ID for multimethod payments
     *
     * @param int $billId
     * @param int $chargeId
     * @param float $refundAmount
     * @return bool
     */
    private function cancelInvoiceByBillId($billId, $chargeId, $refundAmount)
    {
        try {
            // Converte bill ID para string para garantir compatibilidade
            $billIdStr = (string)$billId;

            $this->logger->info('CHARGE_REFUNDED: Searching for invoices with bill ID: ' . $billIdStr . ' (original: ' . $billId . ', type: ' . gettype($billId) . ')');

            // Busca invoices pelo bill ID
            $invoices = $this->invoiceBillHelper->getInvoicesByVindiBillId($billIdStr);

            if (empty($invoices)) {
                $this->logger->warning('CHARGE_REFUNDED: No invoices found for bill ID: ' . $billIdStr);

                // Tentativa adicional: buscar por bill ID como integer
                $billIdInt = (int)$billId;
                if ($billIdInt > 0 && $billIdInt != $billIdStr) {
                    $this->logger->info('CHARGE_REFUNDED: Trying search with integer bill ID: ' . $billIdInt);
                    $invoices = $this->invoiceBillHelper->getInvoicesByVindiBillId($billIdInt);

                    if (!empty($invoices)) {
                        $this->logger->info('CHARGE_REFUNDED: Found invoices using integer bill ID: ' . $billIdInt);
                    }
                }

                if (empty($invoices)) {
                    return false;
                }
            } else {
                $this->logger->info('CHARGE_REFUNDED: Found ' . count($invoices) . ' invoice(s) for bill ID: ' . $billIdStr);
            }

            $invoicesCanceled = 0;

            foreach ($invoices as $invoice) {
                $this->logger->info('CHARGE_REFUNDED: Processing invoice ' . $invoice->getIncrementId() . 
                    ' - State: ' . $invoice->getState() . ' - Total: ' . $invoice->getGrandTotal() . 
                    ' - Refund Amount: ' . $refundAmount);

                // Verifica se a invoice pode ser cancelada
                if ($invoice->getState() == Invoice::STATE_PAID) {
                    
                    // Verifica se o valor do estorno corresponde ao valor da invoice
                    $invoiceTotal = (float)$invoice->getGrandTotal();
                    $refundAmountFloat = (float)$refundAmount;
                    
                    // Tolerância para diferenças de centavos
                    $tolerance = 0.01;
                    $amountDifference = abs($invoiceTotal - $refundAmountFloat);
                    
                    if ($amountDifference > $tolerance) {
                        $this->logger->warning('CHARGE_REFUNDED: Amount mismatch - Invoice total: ' . 
                            $invoiceTotal . ', Refund amount: ' . $refundAmountFloat . 
                            ', Difference: ' . $amountDifference);
                            
                        // Se a diferença for significativa, ainda assim cancela a invoice
                        // porque o estorno já foi processado na Vindi
                        $this->logger->info('CHARGE_REFUNDED: Proceeding with cancellation despite amount difference');
                    }
                    
                    try {
                        // Cancela a invoice offline (já foi estornada na Vindi)
                        $invoice->setState(Invoice::STATE_CANCELED);

                        // Adiciona comentário explicativo
                        $commentText = sprintf(
                            'Invoice cancelada devido ao estorno do Charge %d (Bill ID: %d) - Valor estornado: R$ %s (Invoice: R$ %s)',
                            $chargeId,
                            $billId,
                            number_format($refundAmountFloat, 2, ',', '.'),
                            number_format($invoiceTotal, 2, ',', '.')
                        );

                        $invoice->addComment($commentText, false, false);

                        // Salva a invoice
                        $this->invoiceRepository->save($invoice);

                        $invoicesCanceled++;

                        $this->logger->info('CHARGE_REFUNDED: Invoice ' . $invoice->getIncrementId() . ' canceled for bill ID: ' . $billId);

                        // Atualiza o pedido com comentário
                        $order = $invoice->getOrder();
                        if ($order) {
                            $orderComment = sprintf(
                                'Invoice #%s cancelada devido ao estorno do pagamento (Charge %d, Bill ID: %d)',
                                $invoice->getIncrementId(),
                                $chargeId,
                                $billId
                            );
                            $order->addStatusHistoryComment($orderComment);
                            $this->orderRepository->save($order);
                        }

                    } catch (\Exception $e) {
                        $this->logger->error('CHARGE_REFUNDED: Error canceling invoice ' . $invoice->getIncrementId() . ': ' . $e->getMessage());
                    }
                } else {
                    $this->logger->info('CHARGE_REFUNDED: Invoice ' . $invoice->getIncrementId() . ' is not in PAID state (current state: ' . $invoice->getState() . '), skipping cancellation');
                }
            }

            $this->logger->info('CHARGE_REFUNDED: Successfully canceled ' . $invoicesCanceled . ' invoice(s) for bill ID: ' . $billId);
            return $invoicesCanceled > 0;

        } catch (\Exception $e) {
            $this->logger->error('CHARGE_REFUNDED: Error processing invoice cancellation for bill ID ' . $billId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find bill ID by charge ID (busca nas invoices que já foram salvas)
     *
     * @param int $chargeId
     * @return int|null
     */
    private function findBillIdByChargeId($chargeId)
    {
        try {
            // Como não temos charge_id salvo nos splits, vamos tentar buscar
            // nas invoices recentes que tenham vindi_bill_id
            // Isso é uma tentativa de fallback para casos onde não conseguimos
            // obter o bill_id do webhook
            
            $this->logger->info('CHARGE_REFUNDED: Attempting to find bill ID by checking recent invoices with vindi_bill_id');
            
            // Para esta implementação, vamos retornar null e deixar que o erro seja logado
            // Em produção, pode ser necessário implementar uma lógica mais específica
            // baseada em como os charges são relacionados aos bills no seu sistema
            
            $this->logger->warning('CHARGE_REFUNDED: Could not find bill ID for charge ' . $chargeId . ' - webhook should include bill data');

            return null;
        } catch (\Exception $e) {
            $this->logger->error('CHARGE_REFUNDED: Error finding bill ID by charge ID: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Find bill code by bill ID
     *
     * @param int $billId
     * @return string|null
     */
    private function findBillCodeByBillId($billId)
    {
        try {
            // Busca order pela invoice que tem esse bill ID
            $invoices = $this->invoiceBillHelper->getInvoicesByVindiBillId($billId);
            
            if (!empty($invoices)) {
                $invoice = reset($invoices);
                $order = $invoice->getOrder();
                
                if ($order) {
                    // Verifica se é multimeios
                    $splits = $this->paymentSplitFactory->create()
                        ->getCollection()
                        ->addFieldToFilter('order_increment_id', $order->getIncrementId())
                        ->addFieldToFilter('bill_id', $billId);
                        
                    if ($splits->getSize() > 0) {
                        // É multimeios, precisa do sufixo
                        $splitData = $splits->getFirstItem();
                        $paymentMethod = $splitData->getPaymentMethod();
                        
                        // Determina sufixo baseado no método de pagamento
                        $suffix = (in_array($paymentMethod, ['credit_card', 'debit_card'])) ? '-01' : '-02';
                        return $order->getIncrementId() . $suffix;
                    } else {
                        // Pagamento único
                        return $order->getIncrementId();
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('CHARGE_REFUNDED: Error finding bill code by bill ID: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Find order by bill ID
     *
     * @param int $billId
     * @return \Magento\Sales\Model\Order|null
     */
    private function findOrderByBillId($billId)
    {
        try {
            $invoices = $this->invoiceBillHelper->getInvoicesByVindiBillId($billId);
            
            if (!empty($invoices)) {
                $invoice = reset($invoices);
                return $invoice->getOrder();
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('CHARGE_REFUNDED: Error finding order by bill ID: ' . $e->getMessage());
            return null;
        }
    }
}
