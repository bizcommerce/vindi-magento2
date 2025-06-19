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

        // Log detalhado para debug
        $this->logger->info('CHARGE_REFUNDED: Processing refunded charge ' . $chargeId);
        $this->logger->info('CHARGE_REFUNDED: Charge data - ' . json_encode([
            'charge_id' => $chargeId,
            'amount' => $refundAmount,
            'bill_id' => $billId,
            'bill_code' => $billCode,
            'payment_method' => $charge['payment_method']['code'] ?? 'unknown'
        ]));

        // Verificar se é multimeios baseado na quantidade de splits do pedido
        $isMultimethod = $this->isMultimethodBill($billCode, $billId);

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
            // Primeiro, tentar encontrar o pedido para esta bill
            $order = $this->findOrderForBill($billCode);
            
            if (!$order) {
                $this->logger->warning('CHARGE_REFUNDED: Could not find order for bill code: ' . $billCode . ' - cannot determine if multimethod');
                return false;
            }

            // Buscar todos os splits para este pedido
            $allSplits = $this->paymentSplitFactory->create()
                ->getCollection()
                ->addFieldToFilter('order_increment_id', $order->getIncrementId());

            $splitCount = $allSplits->getSize();
            $this->logger->info('CHARGE_REFUNDED: Found ' . $splitCount . ' splits for order ' . $order->getIncrementId());

            // Se há mais de 1 split, é multimeios
            if ($splitCount > 1) {
                $this->logger->info('CHARGE_REFUNDED: Multimethod detected - Order ' . $order->getIncrementId() . ' has ' . $splitCount . ' payment splits');
                
                // Log detalhado dos splits para debug
                foreach ($allSplits as $split) {
                    $this->logger->info('CHARGE_REFUNDED: Split found - Bill ID: ' . $split->getBillId() . ', Status: ' . $split->getStatus() . ', Amount: ' . $split->getAmount());
                }
                
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

            // Marcar o split atual como refundado e criar creditmemo (se existir)
            $currentSplit = null;
            $creditmemo = null;
            foreach ($allSplits as $split) {
                // Tentar identificar o split da bill estornada
                if ($split->getBillId() == $charge['bill']['id']) {
                    $currentSplit = $split;

                    // ✅ NOVO: Criar creditmemo no Magento para o refund detectado
                    $creditmemo = $this->refundHelper->createSplitRefund(
                        $order,
                        $charge['amount'],
                        $charge['payment_method']['name'] ?? $split->getPaymentMethod() ?? 'Método de Pagamento'
                    );

                    $split->setStatus('refunded')
                        ->setIsRefunded(1)
                        ->setRefundAmount($charge['amount'])
                        ->setRefundDate(date('Y-m-d H:i:s'))
                        ->save();
                    $this->logger->info('CHARGE_REFUNDED: Marked split as refunded - Bill ID: ' . $split->getBillId() .
                        ($creditmemo ? ', Creditmemo: ' . $creditmemo->getIncrementId() : ', No creditmemo created'));
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

            // Adicionar comentário no pedido sobre o estorno detectado
            $commentText = sprintf(
                'Estorno de multimeios detectado: Charge %d estornado (R$ %s). Pedido SEMPRE será cancelado.',
                $chargeId,
                number_format($charge['amount'], 2, ',', '.')
            );

            if ($creditmemo) {
                $commentText .= sprintf(' Creditmemo #%s criado.', $creditmemo->getIncrementId());
            }

            $order->addStatusHistoryComment($commentText);

            // SEMPRE cancelar pedido (independente de outras condições)
            $this->logger->info('CHARGE_REFUNDED: ALWAYS canceling order due to multimethod refund');

            if ($order->canCancel()) {
                $order->cancel();
                $order->addStatusHistoryComment('Pedido cancelado devido ao estorno de charge em pagamento multimeios');
                $this->logger->info('CHARGE_REFUNDED: Order canceled successfully');
            } else {
                $this->logger->warning('CHARGE_REFUNDED: Order cannot be canceled - current state: ' . $order->getState() . ', status: ' . $order->getStatus());
                $order->addStatusHistoryComment('Estorno de multimeios detectado - pedido não pôde ser cancelado automaticamente devido ao estado atual');
            }

            $this->orderRepository->save($order);

            $this->logger->info('CHARGE_REFUNDED: Successfully processed multimethod refund for order ' . $order->getIncrementId());
            return true;

        } catch (\Exception $e) {
            $this->logger->error('CHARGE_REFUNDED: Error processing multimethod refund: ' . $e->getMessage());
            // Mesmo com erro, tentar salvar o pedido com comentário de falha
            try {
                $order->addStatusHistoryComment(sprintf(
                    'ERRO ao processar estorno de multimeios (Charge %d): %s - Verificar manualmente.',
                    $chargeId,
                    $e->getMessage()
                ));
                $this->orderRepository->save($order);
            } catch (\Exception $saveException) {
                $this->logger->error('CHARGE_REFUNDED: Failed to save order with error comment: ' . $saveException->getMessage());
            }
            return false;
        }
    }
}
