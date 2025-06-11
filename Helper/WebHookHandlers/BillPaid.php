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
    private $logger;
    private $orderCreator;
    private $orderCreationQueueRepository;
    private $orderCreationQueueFactory;
    private $orderRepository;
    private $emailSender;
    private $dbAdapter;
    private $invoiceRepository;
    private $searchCriteriaBuilder;
    private $helperData;
    private $paymentSplitFactory;

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
        $this->logger                          = $logger;
        $this->orderCreator                    = $orderCreator;
        $this->orderCreationQueueRepository    = $orderCreationQueueRepository;
        $this->orderCreationQueueFactory       = $orderCreationQueueFactory;
        $this->orderRepository                 = $orderRepository;
        $this->emailSender                     = $emailSender;
        $this->dbAdapter                       = $resourceConnection->getConnection();
        $this->invoiceRepository               = $invoiceRepository;
        $this->searchCriteriaBuilder           = $searchCriteriaBuilder;
        $this->helperData                      = $helperData;
        $this->paymentSplitFactory             = $paymentSplitFactory;
    }

    public function billPaid($data)
    {
        $bill = $data['bill'];
        if (!$bill) {
            $this->logger->error(__('Error while interpreting webhook "bill_paid"'));
            return false;
        }

        $isSubscription = isset($bill['subscription']) && $bill['subscription'] !== null;
        if ($isSubscription) {
            return $this->handleSubscriptionFlow($bill, $data);
        } else {
            return $this->handleRegularOrderFlow($bill);
        }
    }

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

            if ($originalOrder) {
                $vindiBillId = $originalOrder->getVindiBillId();
                $billIds = array_map('trim', explode(',', $vindiBillId));
                $currentSplit = $this->paymentSplitFactory->create()
                    ->getCollection()
                    ->addFieldToFilter('bill_id', $bill['id'])
                    ->getFirstItem();
                if ($currentSplit && $currentSplit->getId()) {
                    $currentSplit->setStatus('paid')->save();
                }
                $splits = $this->paymentSplitFactory->create()
                    ->getCollection()
                    ->addFieldToFilter('bill_id', ['in' => $billIds]);
                $allPaid = true;
                foreach ($splits as $split) {
                    if ($split->getStatus() !== 'paid') {
                        $allPaid = false;
                        break;
                    }
                }
                if (!$allPaid) {
                    $this->logger->info(__('Not all payment splits for subscription order %1 are paid yet.', $originalOrder->getIncrementId()));
                    return true;
                }
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

    private function handleRegularOrderFlow($bill)
    {
        $order = null;
        if (!empty($bill['code'])) {
            $code = $bill['code'];
            if (substr($code, -3) === '-01' || substr($code, -3) === '-02') {
                $code = substr($code, 0, -3);
            }
            $search = $this->searchCriteriaBuilder->addFilter('increment_id', $code, 'eq')->create();
            $items  = $this->orderRepository->getList($search)->getItems();
            $order  = reset($items) ?: null;
        }

        if (!$order) {
            $this->logger->error(__('Order not found for bill code: %1', $bill['code']));
            return false;
        }

        $splits = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('order_increment_id', $order->getIncrementId());

        if ($splits->getSize() > 0) {
            $current = $this->paymentSplitFactory->create()
                ->getCollection()
                ->addFieldToFilter('bill_id', $bill['id'])
                ->getFirstItem();
            if ($current->getId()) {
                $current->setStatus('paid')->save();
                $pi = $order->getPayment()->getAdditionalInformation();
                if ($current->getPaymentMethod() === 'pix' || $current->getPaymentMethod() === 'pix_bank_slip') {
                    $pi['qrcode_path'] = $pi['print_url'] = $pi['due_at'] = null;
                    $order->getPayment()->setAdditionalInformation($pi)->save();
                }
            }
            foreach ($splits as $split) {
                if ($split->getStatus() !== 'paid') {
                    $this->logger->info(__('Not all payment splits for order %1 are paid yet.', $order->getIncrementId()));
                    return true;
                }
            }
        }

        return $this->createInvoice($order);
    }

    public function createInvoice(\Magento\Sales\Model\Order $order)
    {
        if (!$order->getId() || !$order->canInvoice()) {
            $this->logger->error(__('Impossible to generate invoice for order %1.', $order->getId()));
            return false;
        }

        $invoice = $order->prepareInvoice();
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE)
            ->register()
            ->pay()
            ->setSendEmail(true);

        $this->invoiceRepository->save($invoice);

        $status = $this->helperData->getStatusToPaidOrder();
        if ($state = $this->helperData->getStatusState($status)) {
            $order->setState($state);
        }
        $order->addCommentToStatusHistory(
            __('The payment was confirmed and the order is being processed'),
            $status
        );
        $this->orderRepository->save($order);

        $this->logger->info(__('Invoice created successfully for order %1.', $order->getIncrementId()));
        return true;
    }
}
