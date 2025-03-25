<?php
// File: app/code/Vindi/Payment/Helper/WebHookHandlers/BillCanceled.php
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
use Magento\Framework\App\ObjectManager;

/**
 * Class BillCanceled
 *
 * Handles the bill canceled webhook event.
 */
class BillCanceled
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var PaymentSplitFactory
     */
    protected $paymentSplitFactory;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var Charge
     */
    protected $charge;

    /**
     * @var CreditmemoFactory
     */
    protected $creditmemoFactory;

    /**
     * @var CreditmemoService
     */
    protected $creditmemoService;

    /**
     * @var Transaction
     */
    protected $transaction;

    /**
     * @var CreditmemoManagementInterface
     */
    protected $creditmemoManagement;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger
     * @param PaymentSplitFactory $paymentSplitFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param Charge $charge
     * @param CreditmemoFactory $creditmemoFactory
     * @param CreditmemoService $creditmemoService
     * @param Transaction $transaction
     * @param CreditmemoManagementInterface $creditmemoManagement
     */
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

    /**
     * Process bill canceled webhook event.
     *
     * @param array $data Webhook event data.
     * @return bool
     * @throws LocalizedException
     */
    public function billCanceled(array $data): bool
    {
        if (!isset($data['bill']) || empty($data['bill'])) {
            throw new LocalizedException(__('Bill data not found in webhook data.'));
        }
        $bill = $data['bill'];

        if (empty($bill['id'])) {
            throw new LocalizedException(__('Bill ID not found in webhook data.'));
        }
        $billId = $bill['id'];

        if (empty($bill['charges']) || !isset($bill['charges'][0]['id'])) {
            throw new LocalizedException(__('Charge data not found in webhook data.'));
        }
        $chargeId = $bill['charges'][0]['id'];

        $paymentSplitCollection = $this->paymentSplitFactory->create()->getCollection()
            ->addFieldToFilter('bill_id', $billId);

        if ($paymentSplitCollection->getSize() > 0) {
            $paymentSplitItems = $paymentSplitCollection->getItems();
            foreach ($paymentSplitItems as $paymentSplit) {
                if (!$paymentSplit->getIsRefunded()) {
                    if (!$chargeId) {
                        continue;
                    }
                    $refundResult = $this->charge->refund($chargeId, ['amount' => $paymentSplit->getAmount()]);
                    if ($refundResult) {
                        $paymentSplit->setStatus('refunded')
                            ->setIsRefunded(1)
                            ->setRefundAmount($paymentSplit->getAmount())
                            ->setRefundDate(date('Y-m-d H:i:s'));
                        $paymentSplit->save();

                        $order = $this->orderRepository->get($paymentSplit->getOrderId());
                        if ($order->canCreditmemo()) {
                            $invoice = $order->getInvoiceCollection()->getFirstItem();
                            if (!$invoice || !$invoice->getId()) {
                                throw new LocalizedException(__('Invoice not found for credit memo creation.'));
                            }
                            $creditmemo = $this->creditmemoFactory->createByOrder($order);
                            $creditmemo->setInvoice($invoice)
                                ->setBaseShippingAmount(0)
                                ->setShippingAmount(0)
                                ->setBaseGrandTotal($paymentSplit->getAmount())
                                ->setGrandTotal($paymentSplit->getAmount());
                            foreach ($creditmemo->getAllItems() as $item) {
                                $item->setBackToStock(false);
                            }
                            $this->creditmemoService->refund($creditmemo);
                        }
                    }
                }
            }

            $firstPaymentSplit = reset($paymentSplitItems);
            $orderId = $firstPaymentSplit->getOrderId();
            try {
                $order = $this->orderRepository->get($orderId);
                if ($order->canCancel()) {
                    $order->cancel();
                    $order->addStatusHistoryComment(__('Order canceled due to multi-method payment refund.'));
                    $this->transaction->addObject($order);
                    $this->transaction->save();
                }
            } catch (\Exception $e) {
                $this->logger->error(__('Error canceling order: %1', $e->getMessage()));
            }

            return true;
        } else {
            $this->logger->info(__('Bill canceled event processed for single-method payment.'));
            return true;
        }
    }
}
