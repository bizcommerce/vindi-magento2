<?php
namespace Vindi\Payment\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Framework\DB\Transaction;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\Order;

/**
 * Class RefundHelper
 *
 * Handles creditmemo creation for refunds in Magento 2
 */
class RefundHelper extends AbstractHelper
{
    protected $orderRepository;
    protected $invoiceRepository;
    protected $creditmemoFactory;
    protected $creditmemoService;
    protected $transaction;
    protected $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        InvoiceRepositoryInterface $invoiceRepository,
        CreditmemoFactory $creditmemoFactory,
        CreditmemoService $creditmemoService,
        Transaction $transaction,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->transaction = $transaction;
        $this->logger = $logger;
    }

    /**
     * Creates a full refund creditmemo for an order/invoice
     *
     * @param int $orderId
     * @param int|null $invoiceId If null, will use first invoice
     * @return \Magento\Sales\Model\Order\Creditmemo|null
     * @throws \Exception
     */
    public function createFullRefund($orderId, $invoiceId = null)
    {
        try {
            $order = $this->orderRepository->get($orderId);

            if (!$order->canCreditmemo()) {
                $this->logger->warning('REFUND_HELPER: Order cannot have creditmemo - Order ID: ' . $orderId);
                return null;
            }

            if ($invoiceId) {
                $invoice = $this->invoiceRepository->get($invoiceId);
            } else {
                $invoice = $order->getInvoiceCollection()->getFirstItem();
                if (!$invoice || !$invoice->getId()) {
                    $this->logger->warning('REFUND_HELPER: No invoice found for order - Order ID: ' . $orderId);
                    return null;
                }
            }

            $qtys = [];
            foreach ($invoice->getAllItems() as $item) {
                $qtys[$item->getOrderItemId()] = $item->getQty();
            }

            $creditmemo = $this->creditmemoFactory->createByInvoice($invoice, [
                'qtys' => $qtys
            ]);

            $creditmemo->setOfflineRequested(true);

            $this->creditmemoService->refund($creditmemo);

            $this->logger->info('REFUND_HELPER: Full creditmemo created - Order: ' . $order->getIncrementId() . ', Creditmemo: ' . $creditmemo->getIncrementId());

            return $creditmemo;

        } catch (\Exception $e) {
            $this->logger->error('REFUND_HELPER: Error creating full refund - Order ID: ' . $orderId . ', Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Creates a partial refund creditmemo for a specific amount
     *
     * @param int $orderId
     * @param float $amount Amount to refund
     * @param string $reason Reason for the refund
     * @param int|null $invoiceId If null, will use first invoice
     * @return \Magento\Sales\Model\Order\Creditmemo|null
     * @throws \Exception
     */
    public function createPartialRefund($orderId, $amount, $reason = '', $invoiceId = null)
    {
        try {
            $order = $this->orderRepository->get($orderId);

            if (!$order->canCreditmemo()) {
                $this->logger->warning('REFUND_HELPER: Order cannot have creditmemo - Order ID: ' . $orderId);
                return null;
            }

            if ($invoiceId) {
                $invoice = $this->invoiceRepository->get($invoiceId);
            } else {
                $invoice = $order->getInvoiceCollection()->getFirstItem();
                if (!$invoice || !$invoice->getId()) {
                    $this->logger->warning('REFUND_HELPER: No invoice found for order - Order ID: ' . $orderId);
                    return null;
                }
            }

            $creditmemo = $this->creditmemoFactory->createByInvoice($invoice, []);

            $creditmemo->setBaseGrandTotal($amount);
            $creditmemo->setGrandTotal($amount);
            $creditmemo->setBaseSubtotal($amount);
            $creditmemo->setSubtotal($amount);

            if ($reason) {
                $creditmemo->addComment($reason);
            }

            $creditmemo->setOfflineRequested(true);

            $this->creditmemoService->refund($creditmemo);

            $this->logger->info('REFUND_HELPER: Partial creditmemo created - Order: ' . $order->getIncrementId() . ', Amount: ' . $amount . ', Creditmemo: ' . $creditmemo->getIncrementId());

            return $creditmemo;

        } catch (\Exception $e) {
            $this->logger->error('REFUND_HELPER: Error creating partial refund - Order ID: ' . $orderId . ', Amount: ' . $amount . ', Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Creates a creditmemo for multimethod payment split refund
     *
     * @param \Magento\Sales\Model\Order $order
     * @param float $splitAmount Amount of the split being refunded
     * @param string $paymentMethod Name of payment method being refunded
     * @return \Magento\Sales\Model\Order\Creditmemo|null
     */
    public function createSplitRefund($order, $splitAmount, $paymentMethod = '')
    {
        try {
            $invoice = $order->getInvoiceCollection()->getFirstItem();
            if (!$invoice || !$invoice->getId()) {
                $this->logger->warning('REFUND_HELPER: No invoice found for split refund - Order: ' . $order->getIncrementId());
                return null;
            }

            $creditmemo = $this->creditmemoFactory->createByInvoice($invoice, []);

            $creditmemo->setBaseGrandTotal($splitAmount);
            $creditmemo->setGrandTotal($splitAmount);
            $creditmemo->setBaseSubtotal($splitAmount);
            $creditmemo->setSubtotal($splitAmount);

            $comment = sprintf(
                'Estorno parcial de multimeios: %s (R$ %s)',
                $paymentMethod ?: 'Método de Pagamento',
                number_format($splitAmount, 2, ',', '.')
            );
            $creditmemo->addComment($comment);

            $creditmemo->setOfflineRequested(true);

            $this->creditmemoService->refund($creditmemo);

            $this->logger->info('REFUND_HELPER: Split creditmemo created - Order: ' . $order->getIncrementId() . ', Amount: ' . $splitAmount . ', Method: ' . $paymentMethod . ', Creditmemo: ' . $creditmemo->getIncrementId());

            return $creditmemo;

        } catch (\Exception $e) {
            $this->logger->error('REFUND_HELPER: Error creating split refund - Order: ' . $order->getIncrementId() . ', Amount: ' . $splitAmount . ', Error: ' . $e->getMessage());
            return null;
        }
    }
}
