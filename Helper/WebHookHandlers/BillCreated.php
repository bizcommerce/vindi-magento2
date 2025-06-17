<?php

namespace Vindi\Payment\Helper\WebHookHandlers;

use Vindi\Payment\Api\OrderCreationQueueRepositoryInterface;
use Vindi\Payment\Model\OrderCreationQueueFactory;
use Magento\Sales\Model\OrderRepository;
use Vindi\Payment\Helper\EmailSender;
use Vindi\Payment\Logger\Logger;

/**
 * Class BillCreated
 */
class BillCreated
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
     * Constructor for initializing class dependencies.
     */
    public function __construct(
        Logger $logger,
        OrderCreator $orderCreator,
        OrderCreationQueueRepositoryInterface $orderCreationQueueRepository,
        OrderCreationQueueFactory $orderCreationQueueFactory,
        OrderRepository $orderRepository,
        EmailSender $emailSender,
        \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
        $this->logger = $logger;
        $this->orderCreator = $orderCreator;
        $this->orderCreationQueueRepository = $orderCreationQueueRepository;
        $this->orderCreationQueueFactory = $orderCreationQueueFactory;
        $this->orderRepository = $orderRepository;
        $this->emailSender = $emailSender;
        $this->dbAdapter = $resourceConnection->getConnection();
    }

    /**
     * Handle 'bill_created' event.
     * The bill can be related to a subscription or a single payment.
     *
     * @param array $data
     *
     * @return bool
     */
    public function billCreated($data)
    {
        $bill = $data['bill'];

        if (!$bill) {
            $this->logger->error(__('Error while interpreting webhook "bill_created"'));
            return false;
        }

        if (!isset($bill['subscription']) || $bill['subscription'] === null || !isset($bill['subscription']['id'])) {
            $this->logger->info(__('Ignoring the event "bill_created" for single sell'));
            return false;
        }

        $subscriptionId = $bill['subscription']['id'];
        $lockName = 'vindi_subscription_' . $subscriptionId;
        if (!$this->dbAdapter->query("SELECT GET_LOCK(?, 10)", [$lockName])->fetchColumn()) {
            $this->logger->error(__('Could not acquire lock for subscription ID: %1', $subscriptionId));
            return false;
        }

        try {
            $originalOrder = $this->orderCreator->getOrderFromSubscriptionId($subscriptionId);
            $isMultiMeios = false;
            if ($originalOrder) {
                $payment = $originalOrder->getPayment();
                if ($payment && $payment->getMethod() === 'vindi_cardcard') {
                    $isMultiMeios = true;
                }
            }
            if ($isMultiMeios) {
                // Verificar se é renovação ou criação inicial
                $isRenewalBill = $this->isRenewalBill($bill);
                $billId = $bill['id'] ?? null;
                $billCode = $bill['code'] ?? '';
                
                // Verificar se é uma bill manual já criada com sufixo identificador
                // Padrões: {order}-01, {order}-02 (criação inicial) ou {order}-C{cycle}-01, {order}-C{cycle}-02 (renovações)
                $isManualBillWithSuffix = (preg_match('/-0[12]$/', $billCode) || preg_match('/-C\d+-0[12]$/', $billCode));
                
                if ($isRenewalBill) {
                    // Para renovações: cancelar apenas bills automáticas (sem sufixo), criar bills manuais
                    if (!$isManualBillWithSuffix) {
                        try {
                            $this->orderCreator->cancelVindiBill($billId);
                            error_log('VINDI_MULTIMEIOS: Cancelled automatic renewal bill: ' . $billId);
                        } catch (\Exception $e) {
                            error_log('VINDI_MULTIMEIOS: Error cancelling automatic renewal bill: ' . $e->getMessage());
                        }
                        
                        $this->orderCreator->enqueueManualBillsForMultiMeios($originalOrder, $subscriptionId, $bill);
                    } else {
                        error_log('VINDI_MULTIMEIOS: Skipping processing for manual bill with suffix: ' . $billCode);
                    }
                } else {
                    // Para criação inicial: verificar se bill já foi tratada no AbstractMethod
                    if (!$isManualBillWithSuffix) {
                        // É uma bill automática criada apesar do novo fluxo
                        if ($billId) {
                            try {
                                $this->orderCreator->cancelVindiBill($billId);
                                error_log('VINDI_MULTIMEIOS: Cancelled unexpected automatic bill: ' . $billId);
                            } catch (\Exception $e) {
                                error_log('VINDI_MULTIMEIOS: Error cancelling unexpected automatic bill: ' . $e->getMessage());
                            }
                        }
                    } else {
                        error_log('VINDI_MULTIMEIOS: Processing manual bill from new strategy: ' . $billCode);
                    }
                }
                
                return true;
            }

            if ($originalOrder && $originalOrder->getData('vindi_subscription_can_create_new_order') == true) {
                $originalOrder->setData('vindi_subscription_can_create_new_order', false);
                $originalOrder->setData('vindi_bill_id', $bill['id']);
                $this->orderRepository->save($originalOrder);
                $this->logger->info(__('Vindi bill ID set for the order.'));

                $this->orderCreator->updatePaymentDetails($originalOrder, $data);

                $this->emailSender->sendQrCodeAvailableEmail($originalOrder);
                return true;
            }

            if ($originalOrder) {
                if (isset($bill['period']) && isset($bill['period']['cycle'])) {
                    $queueItem = $this->orderCreationQueueFactory->create();
                    $queueItem->setData([
                        'bill_data' => json_encode($data),
                        'status'    => 'pending',
                        'type'      => 'bill_created'
                    ]);
                    $this->orderCreationQueueRepository->save($queueItem);
                    $this->logger->info(__('Created order creation queue item for subscription.'));
                }
            } else {
                $this->logger->info(__('No corresponding order found for subscription ID: %1. Ignoring event.', $subscriptionId));
            }

            return true;
        } finally {
            $this->dbAdapter->query("SELECT RELEASE_LOCK(?)", [$lockName]);
        }
    }

    /**
     * Check if bill is from a renewal cycle (not the initial creation)
     */
    private function isRenewalBill($bill)
    {
        // Verificar se existe informação de período/ciclo
        if (isset($bill['period']) && isset($bill['period']['cycle'])) {
            // Se o ciclo é maior que 1, é renovação
            return (int)$bill['period']['cycle'] > 1;
        }
        
        // Verificar pela data de criação da bill vs data da assinatura
        if (isset($bill['subscription']) && isset($bill['subscription']['created_at']) && isset($bill['created_at'])) {
            $subscriptionCreated = strtotime($bill['subscription']['created_at']);
            $billCreated = strtotime($bill['created_at']);
            
            // Se a bill foi criada mais de 1 dia após a assinatura, é renovação
            return ($billCreated - $subscriptionCreated) > 86400; // 24 horas
        }
        
        // Se não conseguir determinar, assumir que é renovação (mais seguro)
        return true;
    }
}
