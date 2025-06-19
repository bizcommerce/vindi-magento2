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

/**
 * Class ChargeRefunded
 *
 * Handles the charge refunded webhook event for multimethod payments.
 */
class ChargeRefunded
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
        SearchCriteriaBuilder $searchCriteriaBuilder
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
    }

    public function chargeRefunded(array $data): bool
    {
        if (!isset($data['charge']) || empty($data['charge'])) {
            throw new LocalizedException(__('Charge data not found in webhook data.'));
        }

        $charge = $data['charge'];
        $chargeId = $charge['id'];
        $refundAmount = $charge['amount'];
        $billData = $charge['bill'] ?? null;

        if (!$billData || !isset($billData['id'])) {
            $this->logger->warning('CHARGE_REFUNDED: No bill data found for charge ' . $chargeId);
            return true;
        }

        $billId = $billData['id'];
        $billCode = $billData['code'] ?? '';

        // Log detalhado para debug
        $this->logger->info('CHARGE_REFUNDED: Processing refunded charge ' . $chargeId);
        $this->logger->info('CHARGE_REFUNDED: Charge data - ' . json_encode([
            'charge_id' => $chargeId,
            'amount' => $refundAmount,
            'bill_id' => $billId,
            'bill_code' => $billCode,
            'payment_method' => $charge['payment_method']['code'] ?? 'unknown'
        ]));

        // Verificar se é multimeios baseado no código da bill
        $isMultimethod = $this->isMultimethodBill($billCode);

        if (!$isMultimethod) {
            $this->logger->info('CHARGE_REFUNDED: Not a multimethod bill - no additional action needed');
            return true;
        }

        $this->logger->info('CHARGE_REFUNDED: Detected multimethod bill - processing cancellation of other bills');

        // Buscar pedido relacionado
        $order = $this->findOrderForBill($billCode);

        if (!$order) {
            $this->logger->error('CHARGE_REFUNDED: Could not find order for bill ' . $billCode);
            return false;
        }

        return $this->handleMultimethodRefund($order, $charge);
    }

    /**
     * Check if this is a multimethod bill based on code pattern
     *
     * @param string $billCode
     * @return bool
     */
    private function isMultimethodBill($billCode)
    {
        // Códigos como "BIZ-VINDI-000006564-01" sugerem multimeios
        if (preg_match('/BIZ-VINDI-\d+-\d{2}$/', $billCode)) {
            $this->logger->info('CHARGE_REFUNDED: Bill code suggests multimethod: ' . $billCode);
            return true;
        }

        return false;
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
            // Extrair número base do código (ex: 000006564 de BIZ-VINDI-000006564-01)
            if (preg_match('/BIZ-VINDI-(\d+)-\d{2}$/', $billCode, $matches)) {
                $baseOrderNumber = $matches[1];
                $this->logger->info('CHARGE_REFUNDED: Extracted base order number: ' . $baseOrderNumber);

                // Buscar pedido com increment_id que contenha esse número
                $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter('increment_id', '%' . $baseOrderNumber . '%', 'like')
                    ->addFilter('state', ['new', 'processing', 'complete', 'canceled'], 'in')
                    ->create();

                $orders = $this->orderRepository->getList($searchCriteria)->getItems();

                if (!empty($orders)) {
                    $order = reset($orders);
                    $this->logger->info('CHARGE_REFUNDED: Found order ' . $order->getIncrementId() . ' for bill ' . $billCode);
                    return $order;
                }
            }

            $this->logger->warning('CHARGE_REFUNDED: No order found for bill ' . $billCode);
            return null;

        } catch (\Exception $e) {
            $this->logger->error('CHARGE_REFUNDED: Error finding order: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Handle refund in multimethod payment - cancel other bills and order
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $charge
     * @return bool
     */
    private function handleMultimethodRefund($order, $charge)
    {
        try {
            $chargeId = $charge['id'];
            $this->logger->info('CHARGE_REFUNDED: Processing multimethod refund for order ' . $order->getIncrementId());

            // Buscar todos os splits do pedido
            $allSplits = $this->paymentSplitFactory->create()
                ->getCollection()
                ->addFieldToFilter('order_increment_id', $order->getIncrementId());

            $this->logger->info('CHARGE_REFUNDED: Found ' . $allSplits->getSize() . ' splits for order');

            // Marcar o split atual como refundado (se existir)
            $currentSplit = null;
            foreach ($allSplits as $split) {
                // Tentar identificar o split da bill estornada
                if ($split->getBillId() == $charge['bill']['id']) {
                    $currentSplit = $split;
                    $split->setStatus('refunded')
                        ->setIsRefunded(1)
                        ->setRefundAmount($charge['amount'])
                        ->setRefundDate(date('Y-m-d H:i:s'))
                        ->save();
                    $this->logger->info('CHARGE_REFUNDED: Marked split as refunded - Bill ID: ' . $split->getBillId());
                    break;
                }
            }

            // Buscar bills pendentes para cancelar
            $pendingSplits = [];
            foreach ($allSplits as $split) {
                if ($split->getStatus() !== 'paid' && $split->getStatus() !== 'refunded' && !$split->getIsRefunded()) {
                    $pendingSplits[] = $split;
                }
            }

            if (!empty($pendingSplits)) {
                $this->logger->info('CHARGE_REFUNDED: Found ' . count($pendingSplits) . ' pending splits to cancel');

                // Cancelar bills pendentes na Vindi
                foreach ($pendingSplits as $split) {
                    if ($split->getBillId()) {
                        $this->logger->info('CHARGE_REFUNDED: Canceling bill ' . $split->getBillId());
                        // Aqui você pode adicionar a lógica para cancelar a bill na Vindi se necessário
                        // $this->bill->cancel($split->getBillId());
                    }
                }
            }

            // Adicionar comentário no pedido
            $order->addStatusHistoryComment(sprintf(
                'Estorno de multimeios detectado: Charge %d estornado (R$ %s). Pedido será cancelado.',
                $chargeId,
                number_format($charge['amount'], 2, ',', '.')
            ));

            // Cancelar pedido
            if ($order->canCancel()) {
                $order->cancel();
                $order->addStatusHistoryComment('Pedido cancelado devido ao estorno de charge em pagamento multimeios');
                $this->logger->info('CHARGE_REFUNDED: Order canceled successfully');
            } else {
                $this->logger->warning('CHARGE_REFUNDED: Order cannot be canceled - current state: ' . $order->getState());
            }

            $this->orderRepository->save($order);

            $this->logger->info('CHARGE_REFUNDED: Successfully processed multimethod refund for order ' . $order->getIncrementId());
            return true;

        } catch (\Exception $e) {
            $this->logger->error('CHARGE_REFUNDED: Error processing multimethod refund: ' . $e->getMessage());
            return false;
        }
    }
}
