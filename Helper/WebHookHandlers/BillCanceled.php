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

        $this->logger->info('BILL_CANCELED: Processing bill ' . $billId);

        $currentSplit = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('bill_id', $billId)
            ->getFirstItem();

        if (!$currentSplit->getId()) {
            $isMultimethodStandalone = $this->isMultimethodStandaloneBill($bill);

            if ($isMultimethodStandalone) {
                $order = $this->findOrderForStandaloneBill($bill);

                if ($order) {
                    return $this->handleStandaloneBillCancellation($order, $bill);
                } else {
                    $this->logger->error('BILL_CANCELED: Could not find order for standalone multimethod bill ' . $billId);
                    return false;
                }
            }

            return true;
        }

        $order = $this->orderRepository->get($currentSplit->getOrderId());
        $allSplits = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('order_increment_id', $order->getIncrementId());

        if ($allSplits->getSize() <= 1) {
            return $this->handleSinglePaymentCancellation($order, $currentSplit, $bill);
        }

        // MULTIMETHOD: process the other split (not the current one)
        $otherSplit = null;
        foreach ($allSplits as $split) {
            if ($split->getId() !== $currentSplit->getId()) {
                $otherSplit = $split;
                break;
            }
        }

        if (!$otherSplit) {
            $this->logger->error('BILL_CANCELED: No other split found for multimeios order.');
            return false;
        }

        $result = $this->processOtherSplitAndCancel($otherSplit, $order);

        // Always cancel the order after processing the other split
        $this->forceCancelMultimethodOrder($order);

        return $result;
    }

    /**
     * Process the other split in a multimethod payment according to the rules:
     * 1. If the other bill is paid, refund it, then cancel the bill in Vindi, then create a creditmemo in Magento.
     * 2. If the split is pending, just cancel it and create a creditmemo in Magento.
     *
     * @param \Vindi\Payment\Model\PaymentSplit $split
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    private function processOtherSplitAndCancel($split, $order)
    {
        $billId = $split->getBillId();
        $billData = $this->bill->getBill($billId);
        $billStatus = $billData['status'] ?? 'unknown';
        $amount = $split->getAmount();

        if ($billStatus === 'paid') {
            // Refund in Vindi
            $chargeId = $this->getChargeIdFromBillId($billId);
            if ($chargeId) {
                $refundResult = $this->charge->refund($chargeId, ['amount' => $amount]);
                if ($refundResult) {
                    $split->setStatus('refunded')
                        ->setIsRefunded(1)
                        ->setRefundAmount($amount)
                        ->setRefundDate(date('Y-m-d H:i:s'))
                        ->save();
                } else {
                    $split->setStatus('failed_refund')
                        ->setIsRefunded(0)
                        ->save();
                }
            }
            // Cancel bill in Vindi
            $this->bill->cancel($billId);
            // Create creditmemo in Magento
            $this->refundHelper->createSplitRefund(
                $order,
                $amount,
                $split->getPaymentMethod() ?: 'Método de Pagamento'
            );
            $order->addStatusHistoryComment(sprintf(
                'Multimeios: Bill %d paga foi estornada, bill cancelada na Vindi e creditmemo criado.',
                $billId
            ));
            $order->save();
            return true;
        } else {
            // Just cancel the split and create creditmemo
            $this->bill->cancel($billId);
            $split->setStatus('canceled')
                ->setIsRefunded(0)
                ->save();
            $this->refundHelper->createSplitRefund(
                $order,
                $amount,
                $split->getPaymentMethod() ?: 'Método de Pagamento'
            );
            $order->addStatusHistoryComment(sprintf(
                'Multimeios: Bill %d pendente foi cancelada na Vindi e creditmemo criado.',
                $billId
            ));
            $order->save();
            return true;
        }
    }

    private function handleSinglePaymentCancellation($order, $currentSplit, $bill)
    {
        $currentSplit->setStatus('canceled');
        $currentSplit->save();

        $order->addStatusHistoryComment(sprintf(
            'Pagamento cancelado: Bill %d foi cancelada. Não há outros métodos de pagamento.',
            $bill['id']
        ));

        if ($order->canCancel()) {
            $order->cancel();
            $order->addStatusHistoryComment('Pedido cancelado devido ao cancelamento do pagamento.');
        }

        $order->save();
        return true;
    }

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

    private function isMultimethodStandaloneBill($bill)
    {
        try {
            $billCode = $bill['code'] ?? '';
            if (strlen($billCode) >= 4 && in_array(substr($billCode, -3), ['-01', '-02'])) {
                return true;
            }
            return false;
        } catch (\Exception $e) {
            $this->logger->error('MULTIMETHOD_DETECTION: Error detecting multimethod bill: ' . $e->getMessage());
            return false;
        }
    }

    private function findOrderForStandaloneBill($bill)
    {
        try {
            $billCode = $bill['code'] ?? '';
            if (strlen($billCode) >= 4 && in_array(substr($billCode, -3), ['-01', '-02'])) {
                $baseOrderIncrementId = substr($billCode, 0, -3);
                $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter('increment_id', $baseOrderIncrementId)
                    ->create();
                $orders = $this->orderRepository->getList($searchCriteria)->getItems();
                if (!empty($orders)) {
                    return array_values($orders)[0];
                }
            }
            return null;
        } catch (\Exception $e) {
            $this->logger->error('STANDALONE_ORDER_SEARCH: Error finding order: ' . $e->getMessage());
            return null;
        }
    }

    private function handleStandaloneBillCancellation($order, $bill)
    {
        $allSplits = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('order_increment_id', $order->getIncrementId());

        $otherSplit = null;
        foreach ($allSplits as $split) {
            if ($split->getBillId() != $bill['id']) {
                $otherSplit = $split;
                break;
            }
        }

        if (!$otherSplit) {
            $order->addStatusHistoryComment(sprintf(
                'Multimeios: Bill %d cancelada, mas não foi possível localizar outro split.',
                $bill['id']
            ));
            $order->save();
            return false;
        }

        $result = $this->processOtherSplitAndCancel($otherSplit, $order);

        $this->forceCancelMultimethodOrder($order);

        return $result;
    }

    private function forceCancelMultimethodOrder($order)
    {
        try {
            if ($order->canCancel()) {
                $order->cancel();
                $order->addStatusHistoryComment('Pedido cancelado: Todos os métodos de pagamento foram estornados/cancelados na Vindi.');
            }
            $this->orderRepository->save($order);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('FORCE_CANCEL: ❌ ERROR during force cancellation: ' . $e->getMessage());
            return false;
        }
    }
}
