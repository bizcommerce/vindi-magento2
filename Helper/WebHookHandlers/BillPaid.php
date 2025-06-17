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
        $currentCycle = isset($bill['period']['cycle']) ? $bill['period']['cycle'] : 1;
        
        $lockName = 'vindi_subscription_' . $subscriptionId;
        if (!$this->dbAdapter->query("SELECT GET_LOCK(?, 10)", [$lockName])->fetchColumn()) {
            $this->logError('Could not acquire lock for subscription ID: ' . $subscriptionId);
            return false;
        }

        try {
            $originalOrder = $this->orderCreator->getOrderFromSubscriptionId($subscriptionId);

            if (!$originalOrder) {
                $this->logInfo('No corresponding order found for subscription ID: ' . $subscriptionId . '. Ignoring event.');
                return true;
            }

            // REFATORAÇÃO: Multimeios não é mais suportado para assinaturas
            // Todas as assinaturas são tratadas como single method
            return $this->handleSingleCardSubscriptionFlow($bill, $data, $originalOrder);

        } finally {
            $this->dbAdapter->query("SELECT RELEASE_LOCK(?)", [$lockName]);
        }
    }

    /**
     * Handle bill_paid for multimeios (2 cards) subscriptions
     * @deprecated Esta funcionalidade foi removida. Multimeios não é mais suportado para assinaturas.
     * Método mantido apenas para evitar erros de referência.
     */
    private function handleMultiMeiosSubscriptionFlow($bill, $data, $currentCycle, $originalOrder)
    {
        $this->logError('DEPRECATED: handleMultiMeiosSubscriptionFlow called. Multimeios is no longer supported for subscriptions.');
        // Redirecionar para o fluxo padrão de single card
        return $this->handleSingleCardSubscriptionFlow($bill, $data, $originalOrder);
    }

    /**
     * Handle bill_paid for single card subscriptions (original logic)
     */
    private function handleSingleCardSubscriptionFlow($bill, $data, $originalOrder)
    {
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
            $this->logInfo('Not all payment splits for subscription order ' . $originalOrder->getIncrementId() . ' are paid yet.');
            return true;
        }
        
        $queueItem = $this->orderCreationQueueFactory->create();
        $queueItem->setData([
            'bill_data' => json_encode($data),
            'status'    => 'pending',
            'type'      => 'bill_paid'
        ]);
        $this->orderCreationQueueRepository->save($queueItem);
        $this->logInfo('Created order creation queue item for subscription.');
        
        return true;
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
            $this->logError('Order not found for bill code: ' . $bill['code']);
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
                    $this->logInfo('Not all payment splits for order ' . $order->getIncrementId() . ' are paid yet.');
                    return true;
                }
            }
        }

        return $this->createInvoice($order);
    }

    /**
     * Get status of all bills for a specific subscription cycle
     * @deprecated Esta funcionalidade foi removida. Multimeios não é mais suportado para assinaturas.
     * Método mantido apenas para evitar erros de referência.
     */
    private function getCycleBillsStatus($subscriptionId, $cycle)
    {
        $this->logError('DEPRECATED: getCycleBillsStatus called. This method is no longer used as multimeios is not supported for subscriptions.');
        
        // Retornar estrutura mínima para evitar erros
        return [
            'total_bills' => 1,
            'paid_bills' => 1,
            'failed_bills' => 0,
            'pending_bills' => 0
        ];
    }

    /**
     * Update or create payment split for renewal bills
     * @deprecated Esta funcionalidade foi removida. Multimeios não é mais suportado para assinaturas.
     * Método mantido apenas para evitar erros de referência.
     */
    private function updatePaymentSplitForRenewal($billId, $status, $subscriptionId, $cycle, $originalOrder)
    {
        $this->logError('DEPRECATED: updatePaymentSplitForRenewal called. This method is no longer used as multimeios is not supported for subscriptions.');
        
        // Implementação mínima para evitar erros críticos
        // Atualizar apenas o split existente se existir
        $existingSplit = $this->paymentSplitFactory->create()
            ->getCollection()
            ->addFieldToFilter('bill_id', $billId)
            ->getFirstItem();
        
        if ($existingSplit->getId()) {
            $existingSplit->setStatus($status);
            $existingSplit->save();
            $this->logInfo('Updated existing payment split for bill ' . $billId);
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
}
