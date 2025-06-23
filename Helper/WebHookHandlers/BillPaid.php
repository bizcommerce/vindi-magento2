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
use Vindi\Payment\Service\WebhookQueueService;
use Psr\Log\LoggerInterface;

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
    private $webhookQueueService;

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
        PaymentSplitFactory $paymentSplitFactory,
        WebhookQueueService $webhookQueueService
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
        $this->webhookQueueService             = $webhookQueueService;
    }

    public function billPaid($data)
    {
        $bill = $data['bill'];
        if (!$bill) {
            $this->logError('Error while interpreting webhook "bill_paid"');
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
            $this->logError('Could not acquire lock for subscription ID: ' . $subscriptionId);
            return false;
        }

        try {
            $originalOrder = $this->orderCreator->getOrderFromSubscriptionId($subscriptionId);
            if (!$originalOrder) {
                $this->logInfo('No corresponding order found for subscription ID: ' . $subscriptionId);
                return true;
            }

            $this->logInfo('Processing subscription renewal for order: ' . $originalOrder->getIncrementId() . ', subscription: ' . $subscriptionId);

            $queueItem = $this->orderCreationQueueFactory->create();
            $queueItem->setData([
                'bill_data' => json_encode($data),
                'status'    => 'pending',
                'type'      => 'bill_paid'
            ]);
            $this->orderCreationQueueRepository->save($queueItem);
            $this->logInfo('Created order creation queue item for subscription renewal.');

            return true;

        } finally {
            $this->dbAdapter->query("SELECT RELEASE_LOCK(?)", [$lockName]);
        }
    }

    public function createInvoice(\Magento\Sales\Model\Order $order)
    {
        if (!$order->getId() || !$order->canInvoice()) {
            $this->logError('Impossible to generate invoice for order ' . $order->getId());
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
            'The payment was confirmed and the order is being processed',
            $status
        );
        $this->orderRepository->save($order);

        $this->logInfo('Invoice created successfully for order ' . $order->getIncrementId());
        return true;
    }

    /**
     * Helper method to log messages without translation issues
     */
    private function logInfo($message)
    {
        if (method_exists($this->logger, 'info')) {
            $this->logger->info($message);
        } else {
            error_log('[VINDI INFO] ' . $message);
        }
    }

    /**
     * Helper method to log error messages without translation issues
     */
    private function logError($message)
    {
        if (method_exists($this->logger, 'error')) {
            $this->logger->error($message);
        } else {
            error_log('[VINDI ERROR] ' . $message);
        }
    }

    private function handleRegularOrderFlow($bill)
    {
        $order = $this->getOrderFromBill($bill);
        if (!$order) {
            $this->logError('Order not found for bill code: ' . $bill['code']);
            return false;
        }

        $splits = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('order_increment_id', $order->getIncrementId());

        // Check if it's a multimethod payment (more than one split)
        $isMultimethod = $splits->getSize() > 1;

        if (!$isMultimethod) {
            // Single payment method - process normally (original behavior)
            $this->logInfo('Single payment method detected for order: ' . $order->getIncrementId());
            return $this->createInvoice($order);
        }

        // MULTIMETHOD PAYMENT - ADD TO QUEUE for asynchronous processing
        $this->logInfo('Multimethod payment detected - adding to webhook queue for order: ' . $order->getIncrementId());
        
        $currentSplit = $splits->getItemByColumnValue('bill_id', $bill['id']);
        if ($currentSplit && $currentSplit->getId()) {
            $currentSplit->setStatus('paid')->save();

            if (in_array($currentSplit->getPaymentMethod(), ['pix', 'pix_bank_slip'])) {
                $this->clearPixData($order);
            }

            // Add to webhook queue for processing via cron
            $result = $this->webhookQueueService->addMultimethodInvoiceCreation(
                $bill,
                $order->getIncrementId(),
                (string)$bill['id']
            );
            
            if ($result) {
                $this->logInfo('Successfully added multimethod invoice creation to queue for bill_id: ' . $bill['id'] . ', order: ' . $order->getIncrementId());
                return true;
            } else {
                $this->logError('Failed to add multimethod invoice creation to queue for bill_id: ' . $bill['id'] . ', order: ' . $order->getIncrementId());
                return false;
            }
        }

        $this->logError('No payment split found for bill_id: ' . $bill['id'] . ' in order: ' . $order->getIncrementId());
        return false;
    }

    private function getOrderFromBill($bill)
    {
        if (empty($bill['code'])) {
            return null;
        }

        $code = $bill['code'];

        if (substr($code, -3) === '-01' || substr($code, -3) === '-02') {
            $code = substr($code, 0, -3);
        }

        $search = $this->searchCriteriaBuilder->addFilter('increment_id', $code, 'eq')->create();
        $items  = $this->orderRepository->getList($search)->getItems();
        return reset($items) ?: null;
    }

    private function clearPixData($order)
    {
        $pi = $order->getPayment()->getAdditionalInformation();
        $pi['qrcode_path'] = $pi['print_url'] = $pi['due_at'] = null;
        $order->getPayment()->setAdditionalInformation($pi)->save();
    }
}
