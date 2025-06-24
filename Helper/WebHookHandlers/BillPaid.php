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
use Psr\Log\LoggerInterface;
use Magento\Sales\Api\Data\InvoiceExtensionFactory;
use Magento\Sales\Api\Data\InvoiceInterface;

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
    private $invoiceExtensionFactory;

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
        InvoiceExtensionFactory $invoiceExtensionFactory
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
        $this->invoiceExtensionFactory         = $invoiceExtensionFactory;
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

    public function createInvoice(\Magento\Sales\Model\Order $order, $billId = null)
    {
        if (!$order->getId() || !$order->canInvoice()) {
            $this->logError('Impossible to generate invoice for order ' . $order->getId());
            return false;
        }

        $invoice = $order->prepareInvoice();
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $invoice->pay();
        $invoice->setSendEmail(true);

        if ($billId) {
            $invoice->addComment(
                'Vindi Bill ID: ' . $billId,
                false,
                false
            );
        }

        // Salva a invoice
        try {
            $this->invoiceRepository->save($invoice);

            // Salva bill ID diretamente na database
            if ($billId) {
                $this->saveBillIdToInvoice($invoice->getId(), $billId);
            }
        } catch (\Exception $e) {
            $this->logError('Failed to save invoice: ' . $e->getMessage());
            return false;
        }

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
        }
    }

    /**
     * Helper method to log error messages without translation issues
     */
    private function logError($message)
    {
        if (method_exists($this->logger, 'error')) {
            $this->logger->error($message);
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

        // Se não for multimeios, segue fluxo normal
        if ($splits->getSize() === 0) {
            $this->logInfo('Single payment method detected for order: ' . $order->getIncrementId());
            return $this->createInvoice($order, $bill['id']);
        }

        // Multimeios: sempre cria invoice para o split pago
        $currentSplit = $splits->getItemByColumnValue('bill_id', $bill['id']);
        if ($currentSplit && $currentSplit->getId() && $bill['status'] === 'paid') {
            $currentSplit->setStatus('paid')->save();

            if (in_array($currentSplit->getPaymentMethod(), ['pix', 'pix_bank_slip'])) {
                $this->clearPixData($order);
            }

            // Cria invoice apenas para o valor/configuração do split atual
            return $this->createInvoiceForSplit($order, $currentSplit, $bill);
        }

        $this->logInfo('No action taken for order: ' . $order->getIncrementId());
        return true;
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

    private function areAllSplitsPaid($splits)
    {
        foreach ($splits as $split) {
            if ($split->getStatus() !== 'paid') {
                return false;
            }
        }
        return true;
    }

    private function clearPixData($order)
    {
        $pi = $order->getPayment()->getAdditionalInformation();
        $pi['qrcode_path'] = $pi['print_url'] = $pi['due_at'] = null;
        $order->getPayment()->setAdditionalInformation($pi)->save();
    }

    private function createInvoiceForSplit($order, $split, $bill)
    {
        if (!$order->getId() || !$order->canInvoice()) {
            $this->logError('Impossible to generate invoice for order ' . $order->getId());
            return false;
        }

        // Define o valor do invoice conforme o split/bill atual
        $invoice = $order->prepareInvoice();
        foreach ($invoice->getAllItems() as $item) {
            $item->setQty($item->getQty() * ($split->getAmount() / $order->getGrandTotal()));
        }

        $invoice->setGrandTotal($split->getAmount());
        $invoice->setBaseGrandTotal($split->getAmount());

        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $invoice->pay();
        $invoice->setSendEmail(true);

        if (isset($bill['id'])) {
            $invoice->addComment(
                'Vindi Bill ID: ' . $bill['id'] . ' (Split payment)',
                false,
                false
            );
        }

        try {
            $this->invoiceRepository->save($invoice);

            if (isset($bill['id'])) {
                $this->saveBillIdToInvoice($invoice->getId(), $bill['id']);
            }
        } catch (\Exception $e) {
            $this->logError('Failed to save invoice: ' . $e->getMessage());
            return false;
        }

        $status = $this->helperData->getStatusToPaidOrder();
        if ($state = $this->helperData->getStatusState($status)) {
            $order->setState($state);
        }
        $order->addCommentToStatusHistory(
            'Partial payment confirmed and invoice created for split',
            $status
        );
        $this->orderRepository->save($order);

        $this->logInfo('Partial invoice created for order ' . $order->getIncrementId() . ' (split/bill ' . $split->getId() . ')');
        return true;
    }

    /**
     * Save bill ID directly to database
     *
     * @param int $invoiceId
     * @param string $billId
     * @return void
     */
    private function saveBillIdToInvoice($invoiceId, $billId)
    {
        try {
            $connection = $this->dbAdapter;
            $tableName = $connection->getTableName('sales_invoice');

            $connection->update(
                $tableName,
                ['vindi_bill_id' => $billId],
                ['entity_id = ?' => $invoiceId]
            );

            $this->logInfo('Saved bill ID ' . $billId . ' to invoice ' . $invoiceId);
        } catch (\Exception $e) {
            $this->logError('Failed to save bill ID to invoice: ' . $e->getMessage());
        }
    }
}
