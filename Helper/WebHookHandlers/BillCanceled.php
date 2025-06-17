<?php
namespace Vindi\Payment\Helper\WebHookHandlers;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Vindi\Payment\Model\PaymentSplitFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Vindi\Payment\Model\Payment\Charge;
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
    protected $creditmemoFactory;
    protected $creditmemoService;
    protected $transaction;
    protected $creditmemoManagement;

    public function __construct(
        LoggerInterface $logger,
        PaymentSplitFactory $paymentSplitFactory,
        OrderRepositoryInterface $orderRepository,
        Charge $charge,
        CreditmemoFactory $creditmemoFactory,
        CreditmemoService $creditmemoService,
        Transaction $transaction,
        CreditmemoManagementInterface $creditmemoManagement
    ) {
        $this->logger = $logger;
        $this->paymentSplitFactory = $paymentSplitFactory;
        $this->orderRepository = $orderRepository;
        $this->charge = $charge;
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

        // Verificar se todos splits foram refundados
        $allRefunded = $this->areAllSplitsRefunded($allSplits);
        
        if (!$allRefunded) {
            $this->logger->info('Not all splits refunded yet for order: ' . $order->getIncrementId());
            return true; // Aguardar outros refunds
        }

        // Todos splits refundados - proceder com cancelamento total
        $this->logger->info('All splits refunded for order: ' . $order->getIncrementId() . ' - proceeding with full cancellation');
        
        try {
            // Criar creditmemo para valor total do pedido
            if ($order->canCreditmemo()) {
                $invoice = $order->getInvoiceCollection()->getFirstItem();
                if ($invoice && $invoice->getId()) {
                    $creditmemo = $this->creditmemoFactory->createByInvoice($invoice);
                    // Usar valor total da invoice, não parcial
                    $this->creditmemoService->refund($creditmemo);
                    $this->logger->info('Creditmemo created for order: ' . $order->getIncrementId());
                }
            }

            // Cancelar pedido
            if ($order->canCancel()) {
                $order->cancel();
                $order->addStatusHistoryComment('Order canceled - all payment methods refunded');
                $this->transaction->addObject($order);
                $this->transaction->save();
                $this->logger->info('Order canceled: ' . $order->getIncrementId());
            }

        } catch (\Exception $e) {
            $this->logger->error('Error processing full cancellation: ' . $e->getMessage());
            return false;
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
}
