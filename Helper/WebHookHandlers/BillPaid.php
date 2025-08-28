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
use Magento\Sales\Api\Data\InvoiceExtensionFactory;

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

    /**
     * Creates and registers an invoice for the given order.
     *
     * - Generates a standard invoice or includes multiple bill IDs and split comments if provided.
     * - Sets capture case to offline, registers and marks the invoice as paid.
     * - Adds bill ID(s) and/or split comments to the invoice history.
     * - Saves the invoice to the repository and updates the order status accordingly.
     *
     * @param \Magento\Sales\Model\Order $order The order for which the invoice will be created.
     * @param string|array|null $billId Single bill ID or an array of bill IDs to associate with the invoice.
     * @param array $splitComments Additional comments related to split payments to be added to the invoice.
     *
     * @return bool True if the invoice was created successfully, false otherwise.
     */
    public function createInvoice(\Magento\Sales\Model\Order $order, $billId = null, array $splitComments = [])
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

        if ($billId && is_string($billId)) {
            $invoice->addComment('Vindi Bill ID: ' . $billId, false, false);
        }

        foreach ($splitComments as $c) {
            $invoice->addComment($c, false, false);
        }

        try {
            $this->invoiceRepository->save($invoice);

            if ($billId) {
                $ids = is_array($billId) ? $billId : [$billId];
                $this->saveBillIdToInvoice($invoice->getId(), $ids);
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
     * Handles the regular invoice flow for an order based on a received bill.
     *
     * - Attempts to retrieve the order associated with the given bill.
     * - If no split payments exist, processes the order as a single-payment invoice.
     * - If a matching split is found and marked as paid:
     *   - Updates the split status and paid timestamp.
     *   - Adds a payment confirmation comment to the order history.
     *   - Clears PIX data if the payment method is PIX-related.
     *   - If all splits are paid, ensures all split comments are added and creates a final invoice.
     * - If not all splits are paid, logs the state and waits for remaining payments.
     *
     * @param array $bill The bill data received from Vindi, containing payment and status details.
     *
     * @return bool True if the bill was processed successfully or no action was needed, false if the order was not found.
     */
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

        if ($splits->getSize() === 0) {
            $this->logInfo('Single payment method detected for order: ' . $order->getIncrementId());
            return $this->createInvoice($order, $bill['id']);
        }

        $currentSplit = $splits->getItemByColumnValue('bill_id', $bill['id']);
        if ($currentSplit && $currentSplit->getId() && $bill['status'] === 'paid') {
            $currentSplit->setStatus('paid');
            $currentSplit->setData('paid_at', $bill['paid_at'] ?? date('Y-m-d H:i:s'));
            $currentSplit->save();

            $this->addOrderCommentOnceForSplit($order, $currentSplit, $bill['id'], $currentSplit->getData('paid_at'));

            if (in_array($currentSplit->getPaymentMethod(), ['pix', 'pix_bank_slip'])) {
                $this->clearPixData($order);
            }

            if ($this->areAllSplitsPaid($splits)) {
                $this->ensureOrderCommentsForAllPaidSplits($order, $splits);
                [$comments, $billIds] = $this->buildSplitComments($splits);

                $this->logInfo('All splits paid for order ' . $order->getIncrementId() . ', creating invoice with split history.');
                return $this->createInvoice($order, $billIds, $comments);
            }

            $this->logInfo('Split pago para order ' . $order->getIncrementId() . ', aguardando demais splits.');
            return true;
        }

        $this->logInfo('No action taken for order: ' . $order->getIncrementId());
        return true;
    }

    /**
     * Retrieves a Magento order associated with the given bill.
     *
     * - Uses the bill 'code' to search for the order by its increment ID.
     * - If the code ends with "-01" or "-02", the suffix is removed before searching.
     * - Executes a repository search and returns the first matching order.
     *
     * @param array $bill The bill data received from Vindi, expected to contain a 'code' field.
     *
     * @return \Magento\Sales\Api\Data\OrderInterface|null The matching order if found, or null if no order exists.
     */
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

    /**
     * Checks if all payment splits are marked as paid.
     *
     * Iterates through the list of split objects and verifies whether
     * each split has a status equal to 'paid'. If any split is not paid,
     * the method will return false.
     *
     * @param array $splits List of split payment objects, each expected to have a getStatus() method.
     *
     * @return bool True if all splits are paid, false otherwise.
     */
    private function areAllSplitsPaid($splits)
    {
        foreach ($splits as $split) {
            if ($split->getStatus() !== 'paid') {
                return false;
            }
        }
        return true;
    }

    /**
     * Clears PIX-related payment data from the order.
     *
     * Resets specific PIX fields (`qrcode_path`, `print_url`, `due_at`)
     * in the payment's additional information array, then saves the updated data.
     *
     * @param \Magento\Sales\Model\Order $order The order object whose PIX data should be cleared.
     *
     * @return void
     */
    private function clearPixData($order)
    {
        $pi = $order->getPayment()->getAdditionalInformation();
        $pi['qrcode_path'] = $pi['print_url'] = $pi['due_at'] = null;
        $order->getPayment()->setAdditionalInformation($pi)->save();
    }

    /**
     * Checks if the order history already contains the given token.
     *
     * Performs two types of verification:
     *  - Direct case-insensitive check for the full token within history comments.
     *  - If the token contains a numeric ID (e.g., "Invoice: 17596481"),
     *    it also checks for the presence of that ID as a standalone word in the comments.
     *
     * @param \Magento\Sales\Model\Order $order The order to check for the token in its history.
     * @param string $token The token string to look for in the order history.
     *
     * @return bool True if the token (or its extracted numeric ID) is found, false otherwise.
     */
    private function orderHistoryContainsToken(\Magento\Sales\Model\Order $order, string $token): bool
    {
        $id = null;
        if (preg_match('/\b(\d{4,})\b/', $token, $m)) {
            $id = $m[1];
        }

        foreach ($order->getStatusHistories() as $history) {
            $comment = (string) $history->getComment();
            if (!$comment) {
                continue;
            }

            if (stripos($comment, $token) !== false) {
                return true;
            }

            if ($id) {
                $pattern = '/\b' . preg_quote($id, '/') . '\b/i';
                if (preg_match($pattern, $comment)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Attempts to extract `public_name` from the split's additional JSON data (`additional_data`).
     * If not found, falls back to using the raw `payment_method` field.
     *
     * The method tries multiple decoding strategies:
     * - If `additional_data` is a JSON string, it is decoded into an array.
     * - If it is already an array or object, it is normalized into an array.
     *
     * Common lookup paths checked for `public_name`:
     * - `payment_method.public_name`
     * - `charges[0].payment_method.public_name`
     *
     * If no `public_name` is found in the decoded data, the method falls back to
     * split model fields (`payment_method`, `method`, etc.).  
     * Returns `"(desconhecido)"` if no suitable value is found.
     *
     * @param mixed $split Split object containing payment data.
     * @return string The resolved public name or fallback value.
     */
    private function getSplitPublicName($split): string
    {
        $additional = $split->getData('additional_data') ?: null;
        $decoded = null;

        if ($additional) {
            if (is_string($additional)) {
                $decoded = json_decode($additional, true);
            } elseif (is_array($additional)) {
                $decoded = $additional;
            } elseif (is_object($additional)) {
                $decoded = (array) $additional;
            }
        }

        if (is_array($decoded)) {
            if (isset($decoded['payment_method']['public_name'])) {
                return $decoded['payment_method']['public_name'];
            }
            if (isset($decoded['charges'][0]['payment_method']['public_name'])) {
                return $decoded['charges'][0]['payment_method']['public_name'];
            }
        }

        $pm = $split->getPaymentMethod() ?: $split->getData('payment_method') ?: $split->getData('method') ?: null;
        return $pm ?: '(desconhecido)';
    }

    /**
     * Formats a human-readable message for a split payment, including its ID, 
     * payment method, amount, optional bill ID, and payment date.
     *
     * This function attempts to safely retrieve the split ID and amount, 
     * formats the amount as Brazilian currency (R$), and formats the payment 
     * date if provided. If no payment date is given, the current date and time 
     * are used.
     *
     * @param object $split The split payment object, expected to have `id`, `amount`, 
     *                      and optionally a `public_name` accessible via getSplitPublicName().
     * @param string|null $billId Optional bill/invoice ID associated with this payment.
     * @param string|null $paidAt Optional payment date/time in a parseable string format.
     *
     * @return string A formatted message describing the split payment details.
     */
    private function formatSplitPaidMessage($split, $billId = null, $paidAt = null)
    {
        $splitId = $split->getId() ?: $split->getData('id') ?: '(n/a)';
        $method  = $this->getSplitPublicName($split);

        $amountRaw = $split->getAmount() ?: $split->getData('amount') ?: 0;
        $amountFloat = (float) str_replace([','], ['.'], (string)$amountRaw);
        $amountFormatted = 'R$ ' . number_format($amountFloat, 2, ',', '.');

        $billStr = $billId ? "Fatura: {$billId}" : '';

        if ($paidAt) {
            $ts = strtotime($paidAt);
            $paidStr = $ts ? date('d/m/Y H:i:s', $ts) : $paidAt;
        } else {
            $paidStr = date('d/m/Y H:i:s');
        }

        return "Pagamento dividido (cobrança #{$splitId} - {$method}) | Valor: {$amountFormatted}" .
               ($billStr ? " | {$billStr}" : "") .
               " | Pago em: {$paidStr}";
    }

    /**
     * Adds a comment to the order history for a split payment, ensuring it is added only once.
     *
     * This function generates a token based on the bill ID or split ID and checks 
     * if a comment containing that token already exists in the order history. 
     * If not, it formats a split payment message and adds it to the order's status history,
     * then saves the order.
     *
     * @param \Magento\Sales\Model\Order $order The order to which the comment will be added.
     * @param object $split The split payment object, expected to have `id`, `amount`, 
     *                      and optionally a `public_name`.
     * @param string|null $billId Optional bill/invoice ID associated with this split payment.
     * @param string|null $paidAt Optional payment date/time in a parseable string format.
     *
     * @return void
     */
    private function addOrderCommentOnceForSplit(\Magento\Sales\Model\Order $order, $split, $billId = null, $paidAt = null): void
    {
        $splitId = $split->getId() ?: $split->getData('id') ?: '(n/a)';
        $token = $billId ? "Fatura: {$billId}" : ("cobrança #{$splitId}");

        if ($this->orderHistoryContainsToken($order, $token)) {
            $this->logInfo("Comentário já existente detectado para token: {$token}");
            return;
        }

        $message = $this->formatSplitPaidMessage($split, $billId, $paidAt);
        $order->addCommentToStatusHistory($message, false);
        $this->orderRepository->save($order);
    }

    /**
     * Ensures that all paid split payments for the given order have a corresponding comment in the order history.
     *
     * Iterates over the provided split payments and, for each split with a status of 'paid',
     * it retrieves the bill ID and payment date (or uses the current date/time if not available)
     * and adds a comment to the order history using addOrderCommentOnceForSplit().
     *
     * @param \Magento\Sales\Model\Order $order The order to update with split payment comments.
     * @param iterable $splits A collection or array of split payment objects, expected to have `status`, 
     *                        `bill_id`, and `paid_at` properties.
     *
     * @return void
     */
    private function ensureOrderCommentsForAllPaidSplits(\Magento\Sales\Model\Order $order, $splits): void
    {
        foreach ($splits as $s) {
            if ($s->getStatus() === 'paid') {
                $bid = $s->getBillId() ?: $s->getData('bill_id');
                $paidAt = $s->getData('paid_at') ?: date('Y-m-d H:i:s');
                $this->addOrderCommentOnceForSplit($order, $s, $bid, $paidAt);
            }
        }
    }

    /**
     * Builds formatted comments for a list of split payments.
     *
     * Iterates over the provided splits, generating a formatted message for each split 
     * using formatSplitPaidMessage() and collecting their bill IDs.
     *
     * @param iterable $splits A collection or array of split payment objects, expected to have `bill_id` and `paid_at`.
     *
     * @return array An array with two elements:
     *               1. An array of formatted split payment messages.
     *               2. An array of bill IDs (filtered to remove empty values).
     */
    private function buildSplitComments($splits): array
    {
        $comments = [];
        $billIds  = [];
        foreach ($splits as $s) {
            $bid    = $s->getBillId() ?: $s->getData('bill_id');
            $billIds[] = $bid;
            $paidAt = $s->getData('paid_at') ?: date('Y-m-d H:i:s');
            $comments[] = $this->formatSplitPaidMessage($s, $bid, $paidAt);
        }
        return [$comments, array_filter($billIds)];
    }

    /**
     * Logs an informational message if the logger supports it.
     *
     * @param string $message The message to log.
     *
     * @return void
     */
    private function logInfo($message) 
    { 
        if (method_exists($this->logger, 'info')) $this->logger->info($message); 
    }

    /**
     * Logs an error message if the logger supports it.
     *
     * @param string $message The error message to log.
     *
     * @return void
     */
    private function logError($message) 
    { 
        if (method_exists($this->logger, 'error')) $this->logger->error($message); 
    }

    /**
     * Saves one or more bill IDs directly to the database for a given invoice.
     *
     * This function merges the new bill ID(s) with any existing ones in the database,
     * ensuring uniqueness, and updates the `vindi_bill_id` field in the `sales_invoice` table.
     * Logs success or error messages accordingly.
     *
     * @param int $invoiceId The ID of the invoice to update.
     * @param string|array $billId A single bill ID or an array of bill IDs to save.
     *
     * @return void
     */
    private function saveBillIdToInvoice($invoiceId, $billId)
    {
        try {
            $connection = $this->dbAdapter;
            $tableName = $connection->getTableName('sales_invoice');

            $new = is_array($billId) ? implode(',', $billId) : (string) $billId;

            $existing = (string) $connection->fetchOne(
                "SELECT vindi_bill_id FROM {$tableName} WHERE entity_id = ?",
                [$invoiceId]
            );

            if ($existing !== '') {
                $existingParts = array_filter(array_map('trim', explode(',', $existing)));
                $newParts      = array_filter(array_map('trim', explode(',', $new)));
                $merged        = array_unique(array_merge($existingParts, $newParts));
                $new           = implode(',', $merged);
            }

            $connection->update(
                $tableName,
                ['vindi_bill_id' => $new],
                ['entity_id = ?' => $invoiceId]
            );

            $this->logInfo('Saved bill ID(s) ' . $new . ' to invoice ' . $invoiceId);
        } catch (\Exception $e) {
            $this->logError('Failed to save bill ID to invoice: ' . $e->getMessage());
        }
    }
}
