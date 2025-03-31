<?php
namespace Vindi\Payment\Helper\WebHookHandlers;

use Vindi\Payment\Api\OrderCreationQueueRepositoryInterface;
use Vindi\Payment\Model\OrderCreationQueueFactory;
use Magento\Sales\Model\OrderRepository;
use Vindi\Payment\Helper\EmailSender;
use Vindi\Payment\Logger\Logger;
use Magento\Sales\Model\Order\Invoice;
use Vindi\Payment\Helper\Data;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Vindi\Payment\Model\PaymentSplitFactory;

/**
 * Class BillPaid
 */
class BillPaid
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var OrderCreator
     */
    private $orderCreator;

    /**
     * @var OrderCreationQueueRepositoryInterface
     */
    private $orderCreationQueueRepository;

    /**
     * @var OrderCreationQueueFactory
     */
    private $orderCreationQueueFactory;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var EmailSender
     */
    private $emailSender;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private $dbAdapter;

    /**
     * @var InvoiceRepositoryInterface
     */
    private $invoiceRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var Data
     */
    private $helperData;

    /**
     * @var PaymentSplitFactory
     */
    private $paymentSplitFactory;

    /**
     * Constructor for initializing class dependencies.
     *
     * @param Logger $logger
     * @param OrderCreator $orderCreator
     * @param OrderCreationQueueRepositoryInterface $orderCreationQueueRepository
     * @param OrderCreationQueueFactory $orderCreationQueueFactory
     * @param OrderRepository $orderRepository
     * @param EmailSender $emailSender
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param Data $helperData
     * @param PaymentSplitFactory $paymentSplitFactory
     */
    public function __construct(
        Logger $logger,
        OrderCreator $orderCreator,
        OrderCreationQueueRepositoryInterface $orderCreationQueueRepository,
        OrderCreationQueueFactory $orderCreationQueueFactory,
        OrderRepository $orderRepository,
        EmailSender $emailSender,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        InvoiceRepositoryInterface $invoiceRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Data $helperData,
        PaymentSplitFactory $paymentSplitFactory
    ) {
        $this->logger = $logger;
        $this->orderCreator = $orderCreator;
        $this->orderCreationQueueRepository = $orderCreationQueueRepository;
        $this->orderCreationQueueFactory = $orderCreationQueueFactory;
        $this->orderRepository = $orderRepository;
        $this->emailSender = $emailSender;
        $this->dbAdapter = $resourceConnection->getConnection();
        $this->invoiceRepository = $invoiceRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->helperData = $helperData;
        $this->paymentSplitFactory = $paymentSplitFactory;
    }

    /**
     * Handle the "bill_paid" webhook.
     *
     * @param array $data
     * @return bool
     */
    public function billPaid($data)
    {
        $bill = $data['bill'];

        if (!$bill) {
            $this->logger->error(__('Error while interpreting webhook "bill_paid"'));
            return false;
        }

        $isSubscription = isset($bill['subscription']) && $bill['subscription'] != null;

        if ($isSubscription) {
            return $this->handleSubscriptionFlow($bill, $data);
        } else {
            return $this->handleRegularOrderFlow($bill);
        }
    }

    /**
     * Handle the subscription flow.
     *
     * @param array $bill
     * @param array $data
     * @return bool
     */
    private function handleSubscriptionFlow($bill, $data)
    {
        $subscriptionId = $bill['subscription']['id'];
        $lockName = 'vindi_subscription_' . $subscriptionId;

        if (!$this->dbAdapter->query("SELECT GET_LOCK(?, 10)", [$lockName])->fetchColumn()) {
            $this->logger->error(__('Could not acquire lock for subscription ID: %1', $subscriptionId));
            return false;
        }

        try {
            $originalOrder = $this->orderCreator->getOrderFromSubscriptionId($subscriptionId);
            if ($originalOrder && strpos($originalOrder->getVindiBillId(), ',') !== false) {
                $paymentSplitCollection = $this->paymentSplitFactory->create()->getCollection()
                    ->addFieldToFilter('order_increment_id', $originalOrder->getIncrementId());
                $allPaid = true;
                foreach ($paymentSplitCollection as $paymentSplit) {
                    if ($paymentSplit->getStatus() != 'paid') {
                        $allPaid = false;
                        break;
                    }
                }
                if (!$allPaid) {
                    $this->logger->info(__('Not all payment splits for subscription order %1 are paid yet.', $originalOrder->getIncrementId()));
                    return true;
                }
            }
            if ($originalOrder) {
                $queueItem = $this->orderCreationQueueFactory->create();
                $queueItem->setData([
                    'bill_data' => json_encode($data),
                    'status'    => 'pending',
                    'type'      => 'bill_paid'
                ]);
                $this->orderCreationQueueRepository->save($queueItem);
                $this->logger->info(__('Created order creation queue item for subscription.'));
            } else {
                $this->logger->info(__('No corresponding order found for subscription ID: %1. Ignoring event.', $subscriptionId));
            }
            return true;
        } finally {
            $this->dbAdapter->query("SELECT RELEASE_LOCK(?)", [$lockName]);
        }
    }

    /**
     * Handle the regular order flow.
     *
     * @param array $bill
     * @return bool
     */
    private function handleRegularOrderFlow($bill)
    {
        $order = null;

        if (isset($bill['code']) && $bill['code'] != null) {
            $orderCode = $bill['code'];
            if (substr($orderCode, -3) === '-01' || substr($orderCode, -3) === '-02') {
                $orderCode = substr($orderCode, 0, -3);
            }
            $order = $this->getOrder($orderCode);
        }

        if (!$order) {
            $this->logger->error(__('Order not found for bill code: %1', $bill['code']));
            return false;
        }

        $paymentSplitCollection = $this->paymentSplitFactory->create()->getCollection()
            ->addFieldToFilter('order_increment_id', $order->getIncrementId());

        if ($paymentSplitCollection->getSize() > 0) {
            $currentPaymentSplit = $this->paymentSplitFactory->create()->getCollection()
                ->addFieldToFilter('bill_id', $bill['id'])
                ->getFirstItem();
            if ($currentPaymentSplit && $currentPaymentSplit->getId()) {
                $paymentMethod = $currentPaymentSplit->getData('payment_method');
                $currentPaymentSplit->setStatus('paid');
                $currentPaymentSplit->save();

                $orderPayment = $order->getPayment();
                $additionalInfo = $orderPayment->getAdditionalInformation();
                if ($paymentMethod == 'pix') {
                    $additionalInfo['qrcode_original_path'] = '';
                    $additionalInfo['qrcode_pix'] = '';
                } elseif ($paymentMethod == 'pix_bank_slip') {
                    $additionalInfo['qrcode_original_path'] = '';
                    $additionalInfo['qrcode_pix'] = '';
                    $additionalInfo['print_url'] = '';
                    $additionalInfo['due_at'] = '';
                }
                $orderPayment->setAdditionalInformation($additionalInfo);
            }
            $allPaid = true;
            foreach ($paymentSplitCollection as $paymentSplit) {
                if ($paymentSplit->getStatus() != 'paid') {
                    $allPaid = false;
                    break;
                }
            }
            if (!$allPaid) {
                $this->logger->info(__('Not all payment splits for order %1 are paid yet.', $order->getIncrementId()));
                return true;
            }
        }
        return $this->createInvoice($order);
    }

    /**
     * Create an invoice for a regular order.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function createInvoice(\Magento\Sales\Model\Order $order)
    {
        if (!$order->getId()) {
            return false;
        }

        $this->logger->info(__('Generating invoice for the order %1.', $order->getId()));

        if (!$order->canInvoice()) {
            $this->logger->error(__('Impossible to generate invoice for order %1.', $order->getId()));
            return false;
        }

        $invoice = $order->prepareInvoice();
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $invoice->pay();
        $invoice->setSendEmail(true);
        $this->invoiceRepository->save($invoice);

        $this->logger->info(__('Invoice created successfully.'));

        $status = $this->helperData->getStatusToPaidOrder();
        if ($state = $this->helperData->getStatusState($status)) {
            $order->setState($state);
        }

        $order->addCommentToStatusHistory(
            __('The payment was confirmed and the order is being processed'),
            $status
        );

        $this->orderRepository->save($order);

        return true;
    }

    /**
     * Retrieve the order by increment ID.
     *
     * @param string $incrementId
     * @return \Magento\Sales\Api\Data\OrderInterface|false
     */
    private function getOrder($incrementId)
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId, 'eq')
            ->create();

        $orderList = $this->orderRepository
            ->getList($searchCriteria)
            ->getItems();

        try {
            return reset($orderList);
        } catch (\Exception $e) {
            $this->logger->error(__('Order #%1 not found', $incrementId));
            $this->logger->error($e->getMessage());
        }

        return false;
    }
}
