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
        $billData = $data["bill"] ?? null;

        if (!$billData || !isset($billData['id'])) {
            $this->logger->warning('CHARGE_REFUNDED: No bill data found for charge ' . $chargeId);
            return true;
        }

        $billId = $billData['id'];
        $billCode = $billData['code'] ?? '';

        $this->logger->info('CHARGE_REFUNDED: Processing refunded charge ' . $chargeId);
        $this->logger->info('CHARGE_REFUNDED: Charge data - ' . json_encode([
                'charge_id' => $chargeId,
                'amount' => $refundAmount,
                'bill_id' => $billId,
                'bill_code' => $billCode,
                'payment_method' => $charge['payment_method']['code'] ?? 'unknown'
            ]));

        $isMultimethod = $this->isMultimethodBill($billCode, $billId);

        if ($isMultimethod) {
            $this->logger->info('CHARGE_REFUNDED: Detected multimethod bill - canceling invoice for bill ID: ' . $billId);
            $this->cancelInvoiceByBillId($billId, $chargeId, $refundAmount);
        } else {
            $this->logger->info('CHARGE_REFUNDED: Single payment method - creating creditmemo only');


            $order = $this->findOrderForBill($billCode);
            if ($order) {
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
                if (intval($invoice->getState()) === Invoice::STATE_PAID) {
                    try {
                        // Cancela a invoice offline (já foi estornada na Vindi)
                        $invoice->setState(Invoice::STATE_CANCELED);

                        // Adiciona comentário explicativo
                        $commentText = sprintf(
                            'Invoice cancelada devido ao estorno do Charge %d (Bill ID: %d) - Valor estornado: R$ %s',
                            $chargeId,
                            $billId,
                            number_format($refundAmount, 2, ',', '.')
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
}
