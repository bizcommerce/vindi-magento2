<?php
namespace Vindi\Payment\Helper\WebHookHandlers;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Vindi\Payment\Model\PaymentSplitFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Vindi\Payment\Model\Payment\Charge;

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
     * Constructor.
     *
     * @param LoggerInterface $logger
     * @param PaymentSplitFactory $paymentSplitFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param Charge $charge
     */
    public function __construct(
        LoggerInterface $logger,
        PaymentSplitFactory $paymentSplitFactory,
        OrderRepositoryInterface $orderRepository,
        Charge $charge
    ) {
        $this->logger = $logger;
        $this->paymentSplitFactory = $paymentSplitFactory;
        $this->orderRepository = $orderRepository;
        $this->charge = $charge;
    }

    /**
     * Process bill canceled webhook event.
     *
     * @param array $data Webhook event data.
     * @return bool
     * @throws LocalizedException
     */
    public function billCanceled($data)
    {
        $bill = $data['bill'];

        if (!$bill) {
            throw new LocalizedException(__('Bill data not found in webhook data.'));
        }

        $billId = $bill['id'];
        if (!$billId) {
            throw new LocalizedException(__('Bill ID not found in webhook data.'));
        }

        if (empty($bill['charges']) || !isset($bill['charges'][0]['id'])) {
            throw new LocalizedException(__('Charge data not found in webhook data.'));
        }

        $chargeId = isset($bill['charges'][0]['id']) ? $bill['charges'][0]['id'] : null;

        $paymentSplitCollection = $this->paymentSplitFactory->create()->getCollection()
            ->addFieldToFilter('bill_id', $billId);

        if ($paymentSplitCollection->getSize() > 0) {

            $paymentSplitItems = $paymentSplitCollection->getItems();

            foreach ($paymentSplitItems as $paymentSplit) {
                if (!$paymentSplit->getIsRefunded()) {

                    if (!$chargeId) {
                        break;
                    }

                    $refundResult = $this->charge->refund($chargeId, ['amount' => $paymentSplit->getAmount()]);
                    if ($refundResult) {
                        $paymentSplit->setStatus('refunded');
                        $paymentSplit->setIsRefunded(1);
                        $paymentSplit->setRefundAmount($paymentSplit->getAmount());
                        $paymentSplit->setRefundDate(date('Y-m-d H:i:s'));
                        $paymentSplit->save();
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
                    $this->orderRepository->save($order);
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
