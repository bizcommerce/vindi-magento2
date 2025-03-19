<?php
namespace Vindi\Payment\Helper\WebHookHandlers;

use Vindi\Payment\Model\Payment\Bill;
use Vindi\Payment\Model\PaymentSplitFactory;
use Vindi\Payment\Model\Payment\Charge;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * Class ChargeRejected
 *
 * Handles the charge rejected webhook event.
 */
class ChargeRejected
{
    /**
     * @var Bill
     */
    private $bill;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PaymentSplitFactory
     */
    private $paymentSplitFactory;

    /**
     * @var Charge
     */
    private $charge;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * Constructor.
     *
     * @param Bill $bill
     * @param LoggerInterface $logger
     * @param PaymentSplitFactory $paymentSplitFactory
     * @param Charge $charge
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        Bill $bill,
        LoggerInterface $logger,
        PaymentSplitFactory $paymentSplitFactory,
        Charge $charge,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->bill = $bill;
        $this->logger = $logger;
        $this->paymentSplitFactory = $paymentSplitFactory;
        $this->charge = $charge;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * Process charge rejected webhook event.
     *
     * @param array $data Webhook event data.
     * @return bool
     * @throws \Exception
     */
    public function chargeRejected($data)
    {
        $chargeData = $data['charge'];
        $billId = $chargeData['bill']['id'];

        $paymentSplitCollection = $this->paymentSplitFactory->create()->getCollection()
            ->addFieldToFilter('bill_id', $billId);

        if ($paymentSplitCollection->getSize() > 0) {
            $chargeId = isset($chargeData['id']) ? $chargeData['id'] : null;
            if (!$chargeId) {
                throw new \Exception(__('Charge ID not found in webhook data.'));
            }

            $paymentSplitItems = $paymentSplitCollection->getItems();

            foreach ($paymentSplitItems as $paymentSplit) {
                if (!$paymentSplit->getIsRefunded()) {
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
        }

        if (!($order = $this->getOrderFromBill($billId))) {
            $this->logger->warning(__('Order not found'));
            return false;
        }

        $gatewayMessage = $chargeData['last_transaction']['gateway_message'];
        $isLastAttempt = $chargeData['next_attempt'] === null;
        $statusIsNotPending = $chargeData['status'] != 'pending';

        if ($isLastAttempt && $statusIsNotPending) {
            $order->addStatusHistoryComment(sprintf(
                'Payment rejected. Motive: "%s"',
                $gatewayMessage
            ));
            $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED, true, sprintf(
                'All payment tries were rejected. Motive: "%s".',
                $gatewayMessage
            ), true);
            $order->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
            $this->logger->info(sprintf('All payment tries were rejected. Motive: "%s".', $gatewayMessage));
        } else {
            $order->addStatusHistoryComment(sprintf(
                'Payment try rejected. Motive: "%s". A new try will be made',
                $gatewayMessage
            ));
            $this->logger->info(sprintf('Payment try rejected. Motive: "%s". A new try will be made', $gatewayMessage));
        }

        $order->save();

        return true;
    }

    /**
     * Retrieve order from bill.
     *
     * @param mixed $billId
     * @return \Magento\Sales\Model\Order|false
     */
    private function getOrderFromBill($billId)
    {
        $bill = $this->bill->getBill($billId);
        if (!$bill) {
            return false;
        }
        if (isset($bill['code'])) {
            $orderCode = $bill['code'];
            if (substr($orderCode, -3) === '-01' || substr($orderCode, -3) === '-02') {
                $orderCode = substr($orderCode, 0, -3);
            }
            $criteria = $this->searchCriteriaBuilder->addFilter('increment_id', $orderCode, 'eq')->create();
            $orders = $this->orderRepository->getList($criteria)->getItems();
            return $orders ? reset($orders) : false;
        }
        return false;
    }
}
