<?php
namespace Vindi\Payment\Model\Payment;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Payment\Helper\Data as PaymentDataHelper;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod as OriginAbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;
use Vindi\Payment\Api\PlanManagementInterface;
use Vindi\Payment\Api\ProductManagementInterface;
use Vindi\Payment\Api\SubscriptionInterface;
use Vindi\Payment\Helper\Api;
use Vindi\Payment\Model\PaymentProfile;
use Vindi\Payment\Model\PaymentProfileFactory;
use Vindi\Payment\Model\PaymentProfileRepository;
use Magento\Framework\App\ResourceConnection;
use Vindi\Payment\Model\VindiPlanRepository;
use Vindi\Payment\Model\Subscription;
use Vindi\Payment\Model\SubscriptionRepository;
use Vindi\Payment\Model\ResourceModel\Subscription\Collection as SubscriptionCollection;
use Vindi\Payment\Model\PaymentSplitFactory;
use Magento\Sales\Api\OrderRepositoryInterface;

abstract class AbstractMethod extends OriginAbstractMethod
{
    protected $api;
    protected $invoiceService;
    protected $customer;
    protected $bill;
    protected $profile;
    protected $paymentMethod;
    protected $psrLogger;
    protected $date;
    protected $productManagement;
    protected $helperData;
    protected $planManagement;
    protected $subscriptionRepository;
    protected $paymentProfileFactory;
    protected $paymentProfileRepository;
    protected $resourceConnection;
    protected $connection;
    protected $vindiPlanRepository;
    protected $subscriptionRepositoryModel;
    protected $subscriptionCollection;
    protected $paymentSplitFactory;
    protected $orderRepository;

    abstract protected function getPaymentMethodCode();

    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentDataHelper $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        Api $api,
        InvoiceService $invoiceService,
        Customer $customer,
        ProductManagementInterface $productManagement,
        PlanManagementInterface $planManagement,
        SubscriptionInterface $subscriptionRepository,
        VindiPlanRepository $vindiPlanRepository,
        PaymentProfileFactory $paymentProfileFactory,
        PaymentProfileRepository $paymentProfileRepository,
        ResourceConnection $resourceConnection,
        Bill $bill,
        Profile $profile,
        PaymentMethod $paymentMethod,
        LoggerInterface $psrLogger,
        TimezoneInterface $date,
        \Vindi\Payment\Helper\Data $helperData,
        SubscriptionRepository $subscriptionRepositoryModel,
        SubscriptionCollection $subscriptionCollection,
        PaymentSplitFactory $paymentSplitFactory,
        OrderRepositoryInterface $orderRepository,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->api = $api;
        $this->invoiceService = $invoiceService;
        $this->customer = $customer;
        $this->bill = $bill;
        $this->profile = $profile;
        $this->paymentMethod = $paymentMethod;
        $this->psrLogger = $psrLogger;
        $this->date = $date;
        $this->productManagement = $productManagement;
        $this->helperData = $helperData;
        $this->planManagement = $planManagement;
        $this->vindiPlanRepository = $vindiPlanRepository;
        $this->subscriptionRepository = $subscriptionRepository;
        $this->paymentProfileFactory = $paymentProfileFactory;
        $this->paymentProfileRepository = $paymentProfileRepository;
        $this->resourceConnection = $resourceConnection;
        $this->connection = $this->resourceConnection->getConnection();
        $this->subscriptionRepositoryModel = $subscriptionRepositoryModel;
        $this->subscriptionCollection = $subscriptionCollection;
        $this->paymentSplitFactory = $paymentSplitFactory;
        $this->orderRepository = $orderRepository;
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (
            $this->getPaymentMethodCode() == PaymentMethod::BANK_SLIP
            || $this->getPaymentMethodCode() == PaymentMethod::BANK_SLIP_PIX
            || $this->getPaymentMethodCode() == PaymentMethod::PIX
        ) {
            $items = $quote ? $quote->getItems() : [];
            if (is_array($items) || $items instanceof \Traversable) {
                foreach ($items as $item) {
                    if ($this->helperData->isVindiPlan($item->getProductId())) {
                        $product = $this->helperData->getProductById($item->getProductId());
                        if (
                            $product->getData('vindi_billing_trigger_day') > 0 ||
                            $product->getData('vindi_billing_trigger_type') == 'end_of_period'
                        ) {
                            return false;
                        }
                    }
                }
            }
        }
        return parent::isAvailable($quote);
    }

    public function assignData(DataObject $data)
    {
        parent::assignData($data);
        return $this;
    }

    public function validate()
    {
        parent::validate();
        return $this;
    }

    public function authorize(InfoInterface $payment, $amount)
    {
        parent::authorize($payment, $amount);
        return $this->processPayment($payment, $amount);
    }

    public function capture(InfoInterface $payment, $amount)
    {
        parent::capture($payment, $amount);
        return $this->processPayment($payment, $amount);
    }

    protected function processPayment(InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();

        $customerId = $this->customer->findOrCreate($order);
        if (!$customerId) {
            throw new LocalizedException(__('Vindi customer_id cannot be blank.'));
        }

        $payment->setAdditionalInformation('customer_id', $customerId);

        $paymentMethodCode = $this->getPaymentMethodCode();
        $plan = $this->helperData->isSubscriptionOrder($order);

        // DEBUG TEMPORÁRIO - LOG DO FLUXO
        $this->psrLogger->info('VINDI_DEBUG: Payment Method Code: ' . $paymentMethodCode);
        $this->psrLogger->info('VINDI_DEBUG: Is Subscription: ' . ($plan ? 'YES' : 'NO'));
        $this->psrLogger->info('VINDI_DEBUG: Is Multi Method: ' . ($this->helperData->isMultiMethod($paymentMethodCode) ? 'YES' : 'NO'));

        if ($plan) {
            if ($this->helperData->isMultiMethod($paymentMethodCode)) {
                if ($paymentMethodCode !== PaymentMethod::CARD_CARD) {
                    $this->psrLogger->error('VINDI_DEBUG: Multi method não é CARD_CARD - Método: ' . $paymentMethodCode);
                    return $this->handleError($order);
                }
                $this->psrLogger->info('VINDI_DEBUG: Entrando no fluxo processMultiMethodSubscriptionPayment');
                return $this->processMultiMethodSubscriptionPayment($payment, $amount, $plan);
            } else {
                $this->psrLogger->info('VINDI_DEBUG: Entrando no fluxo processSingleMethodSubscriptionPayment');
                return $this->processSingleMethodSubscriptionPayment($payment, $plan);
            }
        } else {
            if ($this->helperData->isMultiMethod($paymentMethodCode)) {
                return $this->processMultiMethodInvoicePayment($payment, $amount);
            } else {
                return $this->processSingleMethodInvoicePayment($payment, $amount);
            }
        }
    }

    protected function processSingleMethodInvoicePayment(InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $paymentMethodCode = $this->getPaymentMethodCode();
        $customerId = $this->customer->findOrCreate($order);
        $productList = $this->productManagement->findOrCreateProductsFromOrder($order);

        $body = [
            'customer_id' => $customerId,
            'payment_method_code' => $paymentMethodCode,
            'bill_items' => $productList,
            'code' => $order->getIncrementId()
        ];

        if ($paymentMethodCode === PaymentMethod::CREDIT_CARD) {
            $paymentProfile = null;
            $profileId = $payment->getAdditionalInformation('payment_profile');
            
            // Try to get existing payment profile if ID is provided
            if ($profileId) {
                $paymentProfile = $this->getPaymentProfileFromVindi((int)$profileId);
            }
            
            // If profile not found or not provided, create new one
            if (!$paymentProfile) {
                $paymentProfile = $this->createPaymentProfile($order, $payment, $customerId);
            }

            $body['payment_profile'] = ['id' => $paymentProfile['id'] ?? null];
        }

        $installments = $payment->getAdditionalInformation('installments') ?: $payment->getInstallments();
        if ($installments) {
            $body['installments'] = (int)$installments;
        }

        $bill = $this->bill->create($body);
        if ($bill) {
            $this->handleBankSplitAdditionalInformation($payment, $body, $bill);
            if ($this->successfullyPaid($body, $bill)) {
                $order->setData('vindi_bill_id', $bill['id']);
                return $bill['id'];
            }
            $this->bill->delete($bill['id']);
        }
        return $this->handleError($order);
    }

    protected function processMultiMethodInvoicePayment(InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $paymentMethodCode = $this->getPaymentMethodCode();

        if ($paymentMethodCode === PaymentMethod::CARD_PIX) {
            return $this->processCardPix($payment, $order);
        }

        if ($paymentMethodCode === PaymentMethod::CARD_CARD) {
            return $this->processTwoCards($payment, $order);
        }

        if ($paymentMethodCode === PaymentMethod::CARD_BANKSLIP_PIX) {
            return $this->processCardBankslipPix($payment, $order);
        }

        return $this->handleError($order);
    }

    protected function processCardPix(InfoInterface $payment, Order $order)
    {
        $this->psrLogger->info('=== INICIANDO PROCESSO CARTÃO + PIX ===');
        $this->psrLogger->info('Pedido: ' . $order->getIncrementId() . ' | Total: R$ ' . $order->getGrandTotal());
        
        $customerId = $this->customer->findOrCreate($order);
        $productList = $this->productManagement->findOrCreateProductsFromOrder($order);

        $amountCredit = $payment->getAdditionalInformation('amount_credit');
        $amountPix    = $payment->getAdditionalInformation('amount_pix');
        
        $this->psrLogger->info('Valores: Cartão R$ ' . $amountCredit . ' | PIX R$ ' . $amountPix);
        
        if (!$amountCredit || !$amountPix) {
            $this->psrLogger->error('ERRO: Valores de cartão ou PIX não definidos');
            return $this->handleError($order);
        }

        $multiPaymentDiscountProductId = $this->getMultiPaymentDiscountProductId();
        $this->psrLogger->info('Produto de desconto ID: ' . $multiPaymentDiscountProductId);

        $bodyCredit = [
            'customer_id'         => $customerId,
            'payment_method_code' => PaymentMethod::CREDIT_CARD,
            'bill_items'          => $productList,
            'code'                => $order->getIncrementId() . '-01',
        ];
        $bodyCredit['bill_items'][] = [
            'product_id' => $multiPaymentDiscountProductId,
            'amount'     => -((float)$amountPix),
        ];

        $paymentProfile = null;
        $profileId = $payment->getAdditionalInformation('payment_profile');
        
        // Try to get existing payment profile if ID is provided
        if ($profileId) {
            $paymentProfile = $this->getPaymentProfile((int)$profileId);
        }
        
        // If profile not found or not provided, create new one
        if (!$paymentProfile) {
            $paymentProfile = $this->createPaymentProfile($order, $payment, $customerId);
        }

        $bodyCredit['payment_profile'] = ['id' => $paymentProfile['id'] ?? null];

        $installments = $payment->getAdditionalInformation('cc_installments')
            ?: $payment->getAdditionalInformation('installments')
                ?: $payment->getInstallments();
        if ($installments) {
            $bodyCredit['installments'] = (int)$installments;
        }

        $this->psrLogger->info('Criando BILL 1 (Cartão) com código: ' . $order->getIncrementId() . '-01');
        $billCredit = $this->bill->create($bodyCredit);
        
        if (!$billCredit) {
            $this->psrLogger->error('ERRO: Falha na criação da BILL 1 (Cartão)');
            return $this->handleError($order);
        }
        
        $this->psrLogger->info('BILL 1 criada com sucesso. ID: ' . ($billCredit['id'] ?? 'N/A') . ' | Status: ' . ($billCredit['status'] ?? 'N/A'));
        
        if (!$this->successfullyPaid($bodyCredit, $billCredit)) {
            $this->psrLogger->error('ERRO: BILL 1 não passou na validação de pagamento. Status: ' . ($billCredit['status'] ?? 'N/A'));
            if ($billCredit && isset($billCredit['id'])) {
                $this->bill->delete($billCredit['id']);
                $this->psrLogger->info('BILL 1 deletada devido à falha na validação');
            }
            return $this->handleError($order);
        }
        
        $bodyPix = [
            'customer_id'         => $customerId,
            'payment_method_code' => PaymentMethod::PIX,
            'bill_items'          => $productList,
            'code'                => $order->getIncrementId() . '-02',
        ];
        $bodyPix['bill_items'][] = [
            'product_id' => $multiPaymentDiscountProductId,
            'amount'     => -((float)$amountCredit),
        ];

        $this->psrLogger->info('Criando BILL 2 (PIX) com código: ' . $order->getIncrementId() . '-02');
        $billPix = $this->bill->create($bodyPix);
        
        if (!$billPix) {
            $this->psrLogger->error('ERRO: Falha na criação da BILL 2 (PIX)');
            $this->bill->delete($billCredit['id']);
            $this->psrLogger->info('BILL 1 deletada devido à falha na criação da BILL 2');
            return $this->handleError($order);
        }
        
        $this->psrLogger->info('BILL 2 criada com sucesso. ID: ' . ($billPix['id'] ?? 'N/A') . ' | Status: ' . ($billPix['status'] ?? 'N/A'));
        
        if (!$this->successfullyPaid($bodyPix, $billPix)) {
            $this->psrLogger->error('ERRO: BILL 2 não passou na validação de pagamento. Status: ' . ($billPix['status'] ?? 'N/A'));
            if ($billPix && isset($billPix['id'])) {
                $this->bill->delete($billPix['id']);
                $this->psrLogger->info('BILL 2 deletada devido à falha na validação');
            }
            $this->bill->delete($billCredit['id']);
            $this->psrLogger->info('BILL 1 deletada devido à falha da BILL 2');
            return $this->handleError($order);
        }
        
        $this->psrLogger->info('=== PROCESSO CARTÃO + PIX CONCLUÍDO COM SUCESSO ===');
        $this->psrLogger->info('Bills criadas: ' . $billCredit['id'] . ' (Cartão) + ' . $billPix['id'] . ' (PIX)');
        
        $order->setData('vindi_bill_id', $billCredit['id'] . ',' . $billPix['id']);
        $this->savePaymentSplitRecord(
            $order,
            $billCredit,
            $billPix,
            $amountCredit,
            $amountPix,
            PaymentMethod::CREDIT_CARD,
            PaymentMethod::PIX
        );
        $order->getPayment()->setMethod('vindi_cardpix');
        $this->orderRepository->save($order);

        return $billCredit['id'] . '|' . $billPix['id'];
    }

    protected function processTwoCards(InfoInterface $payment, Order $order)
    {
        $customerId = $this->customer->findOrCreate($order);
        $productList = $this->productManagement->findOrCreateProductsFromOrder($order);

        $amountCredit = $payment->getAdditionalInformation('amount_credit');
        $amountSecondCard = $payment->getAdditionalInformation('amount_second_card');
        if (!$amountCredit || !$amountSecondCard) {
            return $this->handleError($order);
        }

        $multiPaymentDiscountProductId = $this->getMultiPaymentDiscountProductId();

        $bodyCard1 = [
            'customer_id' => $customerId,
            'payment_method_code' => PaymentMethod::CREDIT_CARD,
            'bill_items' => $productList,
            'code' => $order->getIncrementId() . '-01'
        ];
        $bodyCard1['bill_items'][] = [
            'product_id' => $multiPaymentDiscountProductId,
            'amount' => -((float)$amountSecondCard)
        ];

        $profileId1 = (int)$payment->getAdditionalInformation('payment_profile');
        if ($profileId1) {
            $paymentProfile1 = $this->getPaymentProfileFromVindi($profileId1);
            if (!$paymentProfile1) {
                $paymentProfile1 = $this->createPaymentProfile($order, $payment, $customerId, 'first');
            }
        } else {
            $paymentProfile1 = $this->createPaymentProfile($order, $payment, $customerId, 'first');
        }
        $bodyCard1['payment_profile'] = ['id' => $paymentProfile1['id'] ?? null];

        $installments1 = $payment->getAdditionalInformation('cc_installments') ?: 1;
        $bodyCard1['installments'] = (int)$installments1;

        $bodyCard2 = [
            'customer_id' => $customerId,
            'payment_method_code' => PaymentMethod::CREDIT_CARD,
            'bill_items' => $productList,
            'code' => $order->getIncrementId() . '-02'
        ];
        $bodyCard2['bill_items'][] = [
            'product_id' => $multiPaymentDiscountProductId,
            'amount' => -((float)$amountCredit)
        ];

        $profileId2 = (int)$payment->getAdditionalInformation('payment_profile2');
        if ($profileId2) {
            $paymentProfile2 = $this->getPaymentProfileFromVindi($profileId2);
            if (!$paymentProfile2) {
                $paymentProfile2 = $this->createPaymentProfile($order, $payment, $customerId, 'second');
            }
        } else {
            $paymentProfile2 = $this->createPaymentProfile($order, $payment, $customerId, 'second');
        }
        $bodyCard2['payment_profile'] = ['id' => $paymentProfile2['id'] ?? null];

        $installments2 = $payment->getAdditionalInformation('cc_installments2') ?: 1;
        $bodyCard2['installments'] = (int)$installments2;

        $billCard1 = $this->bill->create($bodyCard1);
        if (!$billCard1 || !$this->successfullyPaid($bodyCard1, $billCard1)) {
            if ($billCard1 && isset($billCard1['id'])) {
                $this->bill->delete($billCard1['id']);
            }
            return $this->handleError($order);
        }
        $this->handleBankSplitAdditionalInformation($payment, $bodyCard1, $billCard1);

        $billCard2 = $this->bill->create($bodyCard2);
        if (!$billCard2 || !$this->successfullyPaid($bodyCard2, $billCard2)) {
            if ($billCard2 && isset($billCard2['id'])) {
                $this->bill->delete($billCard2['id']);
            }
            $this->bill->delete($billCard1['id']);
            return $this->handleError($order);
        }
        $this->handleBankSplitAdditionalInformation($payment, $bodyCard2, $billCard2);

        $order->setData('vindi_bill_id', $billCard1['id'] . ',' . $billCard2['id']);
        $this->savePaymentSplitRecord(
            $order,
            $billCard1,
            $billCard2,
            $amountCredit,
            $amountSecondCard,
            PaymentMethod::CREDIT_CARD,
            PaymentMethod::CREDIT_CARD
        );
        $order->getPayment()->setMethod('vindi_cardcard');
        $this->orderRepository->save($order);
        return $billCard1['id'] . '|' . $billCard2['id'];
    }

    protected function processCardBankslipPix(InfoInterface $payment, Order $order)
    {
        $customerId   = $this->customer->findOrCreate($order);
        $productList  = $this->productManagement->findOrCreateProductsFromOrder($order);

        $amountCredit      = $payment->getAdditionalInformation('amount_credit');
        $amountBankslipPix = $payment->getAdditionalInformation('amount_bankslippix');
        if (!$amountCredit || !$amountBankslipPix) {
            return $this->handleError($order);
        }

        $multiPaymentDiscountProductId = $this->getMultiPaymentDiscountProductId();

        $bodyCredit = [
            'customer_id'         => $customerId,
            'payment_method_code' => PaymentMethod::CREDIT_CARD,
            'bill_items'          => $productList,
            'code'                => $order->getIncrementId() . '-01',
        ];
        $bodyCredit['bill_items'][] = [
            'product_id' => $multiPaymentDiscountProductId,
            'amount'     => -((float)$amountBankslipPix),
        ];

        $paymentProfile = null;
        $profileId = $payment->getAdditionalInformation('payment_profile');
        
        // Try to get existing payment profile if ID is provided
        if ($profileId) {
            $paymentProfile = $this->getPaymentProfileFromVindi((int)$profileId);
        }
        
        // If profile not found or not provided, create new one
        if (!$paymentProfile) {
            $paymentProfile = $this->createPaymentProfile($order, $payment, $customerId);
            if (!$paymentProfile) {
                $this->psrLogger->error("Failed to create payment profile for CardBankslipPix. Using null profile.");
                // Continue processing without profile, API will handle this case
            }
        }

        $bodyCredit['payment_profile'] = ['id' => $paymentProfile['id'] ?? null];

        $installments = $payment->getAdditionalInformation('cc_installments')
            ?: $payment->getAdditionalInformation('installments')
                ?: $payment->getInstallments();
        if ($installments) {
            $bodyCredit['installments'] = (int)$installments;
        }

        $billCredit = $this->bill->create($bodyCredit);
        if (!$billCredit || !$this->successfullyPaid($bodyCredit, $billCredit)) {
            if ($billCredit && isset($billCredit['id'])) {
                $this->bill->delete($billCredit['id']);
            }
            return $this->handleError($order);
        }
        $this->handleBankSplitAdditionalInformation($payment, $bodyCredit, $billCredit);

        $bodyBankslipPix = [
            'customer_id'         => $customerId,
            'payment_method_code' => PaymentMethod::BANK_SLIP_PIX,
            'bill_items'          => $productList,
            'code'                => $order->getIncrementId() . '-02',
        ];
        $bodyBankslipPix['bill_items'][] = [
            'product_id' => $multiPaymentDiscountProductId,
            'amount'     => -((float)$amountCredit),
        ];

        $billBankslipPix = $this->bill->create($bodyBankslipPix);
        if (!$billBankslipPix || !$this->successfullyPaid($bodyBankslipPix, $billBankslipPix)) {
            if ($billBankslipPix && isset($billBankslipPix['id'])) {
                $this->bill->delete($billBankslipPix['id']);
            }
            $this->bill->delete($billCredit['id']);
            return $this->handleError($order);
        }
        $this->handleBankSplitAdditionalInformation($payment, $bodyBankslipPix, $billBankslipPix);

        $order->setData('vindi_bill_id', $billCredit['id'] . ',' . $billBankslipPix['id']);
        $this->savePaymentSplitRecord(
            $order,
            $billCredit,
            $billBankslipPix,
            $amountCredit,
            $amountBankslipPix,
            PaymentMethod::CREDIT_CARD,
            PaymentMethod::BANK_SLIP_PIX
        );
        $order->getPayment()->setMethod('vindi_cardbankslippix');
        $this->orderRepository->save($order);

        return $billCredit['id'] . '|' . $billBankslipPix['id'];
    }

    protected function processSingleMethodSubscriptionPayment(InfoInterface $payment, OrderItemInterface $orderItem)
    {
        try {
            $order = $payment->getOrder();
            $customerId = $this->customer->findOrCreate($order);
            $vindiPlan = null;
            $options = $orderItem->getProductOptions();
            if (!empty($options['info_buyRequest']['selected_plan_id'])) {
                $planId = $options['info_buyRequest']['selected_plan_id'];
                $vindiPlan = $this->vindiPlanRepository->getById($planId);
                $planId = $vindiPlan->getVindiId();
            } else {
                $planId = $this->planManagement->create($orderItem->getProductId());
            }
            $productItems = $this->productManagement->findOrCreateProductsToSubscription($order);
            $body = [
                'customer_id' => $customerId,
                'payment_method_code' => $this->getPaymentMethodCode(),
                'plan_id' => $planId,
                'product_items' => $productItems,
                'code' => $order->getIncrementId(),
                'bill_items' => []
            ];
            $installments = $payment->getAdditionalInformation('installments');
            if ($body['payment_method_code'] === PaymentMethod::CREDIT_CARD) {
                $paymentProfile = ($payment->getAdditionalInformation('payment_profile'))
                    ? $this->getPaymentProfileFromVindi((int)$payment->getAdditionalInformation('payment_profile'))
                    : $this->createPaymentProfile($order, $payment, $customerId);

                if ($paymentProfile) {
                    $body['payment_profile'] = ['id' => $paymentProfile['id'] ?? null];
                }
                if ($vindiPlan && $vindiPlan->getInstallments() != null) {
                    if ((int)$installments > (int)$vindiPlan->getInstallments()) {
                        throw new LocalizedException(__('The number of installments cannot be greater than the number of installments of the plan.'));
                    }
                }
            }
            if ($installments) {
                $body['installments'] = (int)$installments;
            }
            $responseData = $this->subscriptionRepository->create($body);
            if ($responseData) {
                if (!isset($responseData['bill'])) {
                    $order->setData('vindi_subscription_can_create_new_order', true);
                }
                $bill = $responseData['bill'];
                $subscription = $responseData['subscription'];
                $billId = !$bill ? null : $bill['id'];
                if ($subscription) {
                    $this->saveSubscriptionToDatabase($subscription, $order, $billId);
                }
                if ($bill) {
                    $this->handleBankSplitAdditionalInformation($payment, $body, $bill);
                }
                if ($this->successfullyPaid($body, $bill, $subscription)) {
                    $billId = $bill['id'] ?? 0;
                    $subscriptionId = $responseData['subscription']['id'];
                    
                    $this->psrLogger->info('Setting vindi_bill_id: ' . $billId . ' for order: ' . $order->getIncrementId());
                    $order->setData('vindi_bill_id', $billId);
                    
                    $this->psrLogger->info('Setting vindi_subscription_id: ' . $subscriptionId . ' for order: ' . $order->getIncrementId());
                    $order->setData('vindi_subscription_id', $subscriptionId);
                    
                    $this->psrLogger->info('Saving order to subscription orders table...');
                    $this->saveOrderToSubscriptionOrdersTable($order);
                    
                    $this->psrLogger->info('Saving order via repository...');
                    // Save the order to persist subscription_id and bill_id
                    $this->orderRepository->save($order);
                    
                    $this->psrLogger->info('Order saved successfully. Verifying saved data...');
                    // Verify the data was saved
                    $savedSubscriptionId = $order->getData('vindi_subscription_id');
                    $this->psrLogger->info('Verified subscription_id after save: ' . ($savedSubscriptionId ?: 'NULL'));
                    
                    return $billId;
                } else {
                    $this->subscriptionRepository->deleteAndCancelBills($subscription['id']);
                    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                    $sub = $objectManager->create(\Vindi\Payment\Model\Subscription::class)->load($subscription['id']);
                    $sub->setStatus('canceled');
                    $sub->save();
                    if ($body['payment_method_code'] === PaymentMethod::CREDIT_CARD) {
                        $paymentProfileId = $paymentProfile['id'] ?? null;
                        if ($paymentProfileId) {
                            $this->profile->deletePaymentProfile($paymentProfileId);
                            $paymentProfileRepositoryModel = $this->paymentProfileRepository->getByProfileId($paymentProfileId);
                            $this->paymentProfileRepository->delete($paymentProfileRepositoryModel);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            return $this->handleError($order);
        }
        return $this->handleError($order);
    }

    protected function processMultiMethodSubscriptionPayment(InfoInterface $payment, $amount, OrderItemInterface $orderItem)
    {
        $order = $payment->getOrder();
        $customerId = $this->customer->findOrCreate($order);
        $productList = $this->productManagement->findOrCreateProductsToSubscription($order);

        $amountCredit = $payment->getAdditionalInformation('amount_credit');
        $amountSecondCard = $payment->getAdditionalInformation('amount_second_card');
        
        // DEBUG TEMPORÁRIO - LOG DOS VALORES
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Amount Credit: ' . ($amountCredit ?: 'NULL/EMPTY'));
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Amount Second Card: ' . ($amountSecondCard ?: 'NULL/EMPTY'));
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Customer ID: ' . $customerId);
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Order ID: ' . $order->getIncrementId());
        
        if (!$amountCredit || !$amountSecondCard) {
            $this->psrLogger->error('VINDI_MULTIMEIOS_DEBUG: Valores dos cartões não encontrados - retornando erro');
            return $this->handleError($order);
        }

        // NOVA ABORDAGEM: Criar assinatura primeiro, depois ajustar bills
        $options = $orderItem->getProductOptions();
        if (!empty($options['info_buyRequest']['selected_plan_id'])) {
            $planId = $options['info_buyRequest']['selected_plan_id'];
            $vindiPlan = $this->vindiPlanRepository->getById($planId);
            $planId = $vindiPlan->getVindiId();
        } else {
            $planId = $this->planManagement->create($orderItem->getProductId());
        }

        // Criar assinatura normalmente (Vindi criará uma bill automática)
        $bodySubscription = [
            'customer_id'         => $customerId,
            'payment_method_code' => PaymentMethod::CREDIT_CARD,
            'plan_id'             => $planId,
            'product_items'       => $productList,
            'code'                => $order->getIncrementId()
        ];
        $installments = $payment->getAdditionalInformation('installments');
        if ($installments) {
            $bodySubscription['installments'] = (int)$installments;
        }
        
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Criando assinatura - Vindi criará bill automática');
        $responseData = $this->subscriptionRepository->create($bodySubscription);
        
        if (!$responseData || !isset($responseData['subscription'])) {
            $this->psrLogger->error('VINDI_MULTIMEIOS_DEBUG: Falha ao criar assinatura');
            return $this->handleError($order);
        }

        $subscription = $responseData['subscription'];
        $automaticBill = $responseData['bill'] ?? null;
        
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Assinatura criada - ID: ' . $subscription['id']);
        if ($automaticBill) {
            $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Bill automática criada - ID: ' . $automaticBill['id']);
        }

        // Cancelar a bill automática
        if ($automaticBill && isset($automaticBill['id'])) {
            $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Cancelando bill automática: ' . $automaticBill['id']);
            try {
                $this->bill->delete($automaticBill['id']);
                $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Bill automática cancelada com sucesso');
            } catch (\Exception $e) {
                $this->psrLogger->error('VINDI_MULTIMEIOS_DEBUG: Erro ao cancelar bill automática: ' . $e->getMessage());
            }
        }

        // Agora criar as 2 bills manuais com rateio correto
        $totalOrder = $order->getGrandTotal();
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Total do pedido: ' . $totalOrder);
        
        // Calcular rateio proporcional considerando frete e descontos
        $ratioCard1 = (float)$amountCredit / ((float)$amountCredit + (float)$amountSecondCard);
        $ratioCard2 = (float)$amountSecondCard / ((float)$amountCredit + (float)$amountSecondCard);
        
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Ratio Card1: ' . $ratioCard1 . ' | Ratio Card2: ' . $ratioCard2);

        // Criar bills com referência à assinatura
        $subscriptionReference = "#{$subscription['id']}: {$subscription['plan']['name']}";
        
        // Bill 1
        $bodyCard1 = [
            'customer_id' => $customerId,
            'subscription_id' => $subscription['id'],
            'payment_method_code' => PaymentMethod::CREDIT_CARD,
            'bill_items' => $this->calculateProportionalItems($productList, $ratioCard1),
            'code' => $order->getIncrementId() . '-C1',
            'due_at' => date('Y-m-d'),
            'notes' => 'Cartão 1 - ' . $subscriptionReference
        ];
        
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Criando Bill 1 com referência à assinatura');
        $billCard1 = $this->bill->create($bodyCard1);
        
        if (!$billCard1 || !$this->successfullyPaid($bodyCard1, $billCard1)) {
            $this->psrLogger->error('VINDI_MULTIMEIOS_DEBUG: Falha na criação da Bill 1 - Response: ' . json_encode($billCard1));
            return $this->handleError($order);
        }
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Bill 1 criada com sucesso - ID: ' . $billCard1['id']);

        // Bill 2
        $bodyCard2 = [
            'customer_id' => $customerId,
            'subscription_id' => $subscription['id'],
            'payment_method_code' => PaymentMethod::CREDIT_CARD,
            'bill_items' => $this->calculateProportionalItems($productList, $ratioCard2),
            'code' => $order->getIncrementId() . '-C2',
            'due_at' => date('Y-m-d'),
            'notes' => 'Cartão 2 - ' . $subscriptionReference
        ];
        
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Criando Bill 2 com referência à assinatura');
        $billCard2 = $this->bill->create($bodyCard2);
        
        if (!$billCard2 || !$this->successfullyPaid($bodyCard2, $billCard2)) {
            $this->psrLogger->error('VINDI_MULTIMEIOS_DEBUG: Falha na criação da Bill 2 - Response: ' . json_encode($billCard2));
            // Cancelar a bill 1 em caso de falha
            $this->bill->delete($billCard1['id']);
            return $this->handleError($order);
        }
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Bill 2 criada com sucesso - ID: ' . $billCard2['id']);

        $combinedBillId = $billCard1['id'] . ',' . $billCard2['id'];
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Combined Bill ID: ' . $combinedBillId);
        
        $this->handleBankSplitAdditionalInformation($payment, $bodyCard1, $billCard1);
        $this->handleBankSplitAdditionalInformation($payment, $bodyCard2, $billCard2);
        
        $this->savePaymentSplitRecord(
            $order,
            $billCard1,
            $billCard2,
            $amountCredit,
            $amountSecondCard,
            PaymentMethod::CREDIT_CARD,
            PaymentMethod::CREDIT_CARD
        );
        if ($responseData) {
            $subscription = $responseData['subscription'] ?? null;

        // Salvar dados da assinatura e bills no pedido
        if ($subscription) {
            $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Salvando dados da assinatura no pedido');
            $this->saveSubscriptionToDatabase($subscription, $order, $combinedBillId);
            
            $order->setData('vindi_subscription_id', $subscription['id']);
        }

        $order->setData('vindi_bill_id', $combinedBillId);
        $order->getPayment()->setMethod('vindi_cardcard');

        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Salvando pedido...');
        $this->saveOrderToSubscriptionOrdersTable($order);
        $this->orderRepository->save($order);
        
        $this->psrLogger->info('VINDI_MULTIMEIOS_DEBUG: Processo de multimeios para assinatura concluído com sucesso');

        return $this;
        }

        return $this->handleError($order);
    }

    /**
     * Obtém o(s) bill_id(s) associado(s) ao pedido (split multimeios).
     * Retorna string com IDs separados por vírgula, ou array se solicitado.
     *
     * @param object $order Instância de Magento\Sales\Model\Order
     * @param bool $asArray
     * @return string|array|null
     */
    public function getSplitBillIds($order, $asArray = false)
    {
        $billIds = $order->getData('vindi_bill_id');
        if (!$billIds) {
            return $asArray ? [] : null;
        }
        return $asArray ? explode(',', $billIds) : $billIds;
    }

    /**
     * Define o(s) bill_id(s) do split multimeios no pedido.
     *
     * @param object $order Instância de Magento\Sales\Model\Order
     * @param string|array $billIds
     * @return void
     */
    public function setSplitBillIds($order, $billIds)
    {
        if (is_array($billIds)) {
            $billIds = implode(',', $billIds);
        }
        $order->setData('vindi_bill_id', $billIds);
    }

    /**
     * Salva o registro de split de pagamento para conciliação multimeios.
     * Cada split é salvo individualmente, permitindo rastreabilidade e reembolso parcial.
     *
     * @param object $order Instância de Magento\Sales\Model\Order
     * @param mixed $billFirst
     * @param mixed $billSecond
     * @param float $amountFirst
     * @param float $amountSecond
     * @param string $paymentMethodFirst
     * @param string $paymentMethodSecond
     * @return void
     */
    protected function savePaymentSplitRecord($order, $billFirst, $billSecond, $amountFirst, $amountSecond, $paymentMethodFirst, $paymentMethodSecond)
    {
        if (!$order->getId()) {
            $order = $this->orderRepository->save($order);
        }
        $paymentSplitFirst = $this->paymentSplitFactory->create();
        $dataFirst = [
            'order_id' => $order->getId(),
            'order_increment_id' => $order->getIncrementId(),
            'payment_method' => $paymentMethodFirst,
            'amount' => $amountFirst,
            'total_amount' => $amountFirst,
            'bill_id' => isset($billFirst['id']) ? $billFirst['id'] : '',
            'status' => isset($billFirst['status']) ? $billFirst['status'] : '',
            'additional_data' => json_encode($this->maskSensitiveData($billFirst)),
            'is_refunded' => 0,
            'refund_amount' => 0
        ];
        $paymentSplitFirst->setData($dataFirst);
        try {
            $paymentSplitFirst->save();
        } catch (\Exception $e) {
            $this->psrLogger->error('Error saving payment split record (first): ' . $e->getMessage());
        }

        $paymentSplitSecond = $this->paymentSplitFactory->create();
        $dataSecond = [
            'order_id' => $order->getId(),
            'order_increment_id' => $order->getIncrementId(),
            'payment_method' => $paymentMethodSecond,
            'amount' => $amountSecond,
            'total_amount' => $amountSecond,
            'bill_id' => isset($billSecond['id']) ? $billSecond['id'] : '',
            'status' => isset($billSecond['status']) ? $billSecond['status'] : '',
            'additional_data' => json_encode($this->maskSensitiveData($billSecond)),
            'is_refunded' => 0,
            'refund_amount' => 0
        ];
        $paymentSplitSecond->setData($dataSecond);
        try {
            $paymentSplitSecond->save();
        } catch (\Exception $e) {
            $this->psrLogger->error('Error saving payment split record (second): ' . $e->getMessage());
        }
    }

    /**
     * Save subscription to database.
     *
     * @param array $subscription
     * @param Order $order
     * @param mixed $billId
     * @return void
     */
    protected function saveSubscriptionToDatabase(array $subscription, Order $order, $billId = null)
    {
        $tableName = $this->resourceConnection->getTableName('vindi_subscription');
        $startAt = new \DateTime($subscription['start_at']);
        $data = [
            'id'              => $subscription['id'],
            'client'          => $subscription['customer']['name'],
            'customer_email'  => $subscription['customer']['email'],
            'customer_id'     => $order->getCustomerId(),
            'plan'            => $subscription['plan']['name'],
            'payment_method'  => $subscription['payment_method']['code'],
            'payment_profile' => $subscription['payment_profile']['id'] ?? null,
            'status'          => $subscription['status'],
            'start_at'        => $startAt->format('Y-m-d H:i:s')
        ];
        if ($billId) {
            $data['bill_id'] = $billId;
        }
        if (isset($subscription['next_billing_at'])) {
            $nextBillingAt = new \DateTime($subscription['next_billing_at']);
            $data['next_billing_at'] = $nextBillingAt->format('Y-m-d H:i:s');
        }
        $data['response_data'] = json_encode($subscription);
        try {
            $this->connection->insert($tableName, $data);
        } catch (\Exception $e) {
            $this->psrLogger->error('Error saving subscription to database: ' . $e->getMessage());
        }
    }

    /**
     * Handle bank split additional information for payment processing.
     *
     * @param InfoInterface $payment
     * @param array $body
     * @param array $bill
     * @return void
     */
    protected function handleBankSplitAdditionalInformation($payment, $body, $bill)
    {
        if (!$payment || !$bill) {
            return;
        }

        try {
            // Get existing additional information and ensure it's an array
            $additionalInfo = $payment->getAdditionalInformation();
            if (!is_array($additionalInfo)) {
                $additionalInfo = [];
            }
            
            // Store bill information in payment additional information
            $additionalInfo['vindi_bill_id'] = $bill['id'] ?? null;
            $additionalInfo['vindi_bill_status'] = $bill['status'] ?? null;
            
            // Store payment method information if available
            if (isset($body['payment_method_code'])) {
                $additionalInfo['vindi_payment_method'] = $body['payment_method_code'];
            }
            
            // Store amount information
            if (isset($bill['amount'])) {
                $additionalInfo['vindi_bill_amount'] = $bill['amount'];
            }
            
            // Store charges information if available
            if (isset($bill['charges']) && is_array($bill['charges'])) {
                foreach ($bill['charges'] as $index => $charge) {
                    $additionalInfo["vindi_charge_{$index}_id"] = $charge['id'] ?? null;
                    $additionalInfo["vindi_charge_{$index}_status"] = $charge['status'] ?? null;
                    
                    // Store payment information if available
                    if (isset($charge['last_transaction'])) {
                        $transaction = $charge['last_transaction'];
                        $additionalInfo["vindi_transaction_{$index}_id"] = $transaction['id'] ?? null;
                        $additionalInfo["vindi_transaction_{$index}_status"] = $transaction['status'] ?? null;
                        
                        // Store bank slip or PIX specific information
                        if (isset($transaction['payment_profile'])) {
                            $paymentProfile = $transaction['payment_profile'];
                            if (isset($paymentProfile['bank_slip_url'])) {
                                $additionalInfo["vindi_bank_slip_url_{$index}"] = $paymentProfile['bank_slip_url'];
                            }
                            if (isset($paymentProfile['pix_qr_code'])) {
                                $additionalInfo["vindi_pix_qr_code_{$index}"] = $paymentProfile['pix_qr_code'];
                            }
                            if (isset($paymentProfile['pix_code'])) {
                                $additionalInfo["vindi_pix_code_{$index}"] = $paymentProfile['pix_code'];
                            }
                        }
                    }
                }
            }
            
            // Set all additional information at once
            $payment->setAdditionalInformation($additionalInfo);
            
            // Don't save the payment here - let the parent process handle it
            // This prevents foreign key constraint violations
            
        } catch (\Exception $e) {
            $this->psrLogger->error('Error handling bank split additional information: ' . $e->getMessage());
        }
    }

    /**
     * Check if payment was successfully processed.
     *
     * @param array $body
     * @param array $bill
     * @param array $subscription
     * @return bool
     */
    protected function successfullyPaid($body, $bill, $subscription = null)
    {
        if (!$bill || !isset($bill['id'])) {
            return false;
        }

        // Check if bill was created successfully
        if (!isset($bill['status'])) {
            return false;
        }

        // Consider these statuses as successful
        $successStatuses = ['paid', 'pending', 'review'];
        
        if (in_array($bill['status'], $successStatuses)) {
            return true;
        }

        // For subscription payments, also check charges
        if (isset($bill['charges']) && is_array($bill['charges'])) {
            foreach ($bill['charges'] as $charge) {
                if (isset($charge['status']) && in_array($charge['status'], $successStatuses)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Handle payment errors and set appropriate order status.
     *
     * @param Order $order
     * @return $this
     */
    protected function handleError($order)
    {
        try {
            if ($order && $order->getId()) {
                // Set order status to payment failed or cancelled
                $order->setState(Order::STATE_CANCELED);
                $order->setStatus(Order::STATE_CANCELED);
                $order->addCommentToStatusHistory(
                    __('Payment failed or was cancelled by Vindi.'),
                    false
                );
                
                // Save the order
                $this->orderRepository->save($order);
                
                $this->psrLogger->error('Payment failed for order: ' . $order->getIncrementId());
            }
        } catch (\Exception $e) {
            $this->psrLogger->error('Error handling payment error: ' . $e->getMessage());
        }
        
        return $this;
    }

    /**
     * Get payment profile by ID.
     *
     * @param int $profileId
     * @return array|null
     */
    /**
     * Get payment profile from local database.
     * This method is used by payment method classes like CardBankSlipPix.
     *
     * @param int $profileId
     * @return PaymentProfile|null
     */
    protected function getPaymentProfile($profileId)
    {
        if (!$profileId) {
            return null;
        }

        try {
            // Try to get from local database
            $paymentProfile = $this->paymentProfileRepository->getByProfileId($profileId);
            
            // Verify if the profile still exists in Vindi API
            $vindiResponse = $this->profile->getPaymentProfileById($profileId);
            
            // If not found in Vindi (404), the local profile is stale
            if (is_array($vindiResponse) && isset($vindiResponse['not_found']) && $vindiResponse['not_found']) {
                $this->psrLogger->warning("Payment profile ID {$profileId} exists locally but not in Vindi API. Profile may be stale.");
                // Return the local profile anyway for backward compatibility
                // The payment processing logic will handle creating a new one if needed
                return $paymentProfile;
            }
            
            return $paymentProfile;
            
        } catch (\Exception $e) {
            $this->psrLogger->error('Error getting payment profile from local database: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get payment profile data from Vindi API (for internal use in payment processing).
     *
     * @param int $profileId
     * @return array|null
     */
    protected function getPaymentProfileFromVindi($profileId)
    {
        if (!$profileId) {
            return null;
        }

        try {
            $response = $this->profile->getPaymentProfileById($profileId);
            
            // Check if response indicates not found (404)
            if (is_array($response) && isset($response['not_found']) && $response['not_found']) {
                $this->psrLogger->warning("Payment profile ID {$profileId} not found in Vindi API (404). Will create new profile.");
                return null;
            }
            
            // Check if response is false (other API errors)
            if ($response === false) {
                $this->psrLogger->warning("Payment profile ID {$profileId} API error. Will create new profile.");
                return null;
            }
            
            // Check if response has the expected structure
            if ($response && isset($response['payment_profile']) && isset($response['payment_profile']['id'])) {
                return ['id' => $response['payment_profile']['id']];
            }
            
            // If response doesn't have expected structure, log and return null
            $this->psrLogger->warning("Payment profile ID {$profileId} response has unexpected structure. Will create new profile.");
            return null;
            
        } catch (\Exception $e) {
            $this->psrLogger->error('Error getting payment profile from Vindi API: ' . $e->getMessage());
            // Return null to force creation of new profile
            return null;
        }
    }

    /**
     * Create payment profile for the order.
     *
     * @param Order $order
     * @param InfoInterface $payment
     * @param int $customerId
     * @param string $suffix
     * @return array|null
     */
    protected function createPaymentProfile($order, $payment, $customerId, $suffix = '')
    {
        if (!$customerId) {
            return null;
        }

        try {
            $paymentMethodCode = $this->getPaymentMethodCode();
            $response = $this->profile->create($payment, $customerId, $paymentMethodCode);
            if ($response && isset($response['payment_profile'])) {
                return ['id' => $response['payment_profile']['id']];
            }
            return null;
        } catch (\Exception $e) {
            $this->psrLogger->error('Error creating payment profile: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get multi payment discount product ID.
     *
     * @return int|null
     */
    protected function getMultiPaymentDiscountProductId()
    {
        try {
            // First check if we have it in cache or configuration
            $discountProductId = $this->helperData->getConfig('general', 'discount_product_id');
            
            if ($discountProductId && $discountProductId > 0) {
                return (int) $discountProductId;
            }
            
            // Use the new helper to create/find the discount product automatically
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $multiPaymentHelper = $objectManager->create(\Vindi\Payment\Helper\MultiPaymentHelper::class);
            
            $discountProductId = $multiPaymentHelper->getOrCreateDiscountProduct();
            
            $this->psrLogger->info('VINDI_MULTIPAYMENT: Using discount product ID: ' . $discountProductId);
            
            return $discountProductId;
            
        } catch (\Exception $e) {
            $this->psrLogger->error('VINDI_MULTIPAYMENT: Error getting/creating discount product: ' . $e->getMessage());
            
            // Fallback: try to create a basic product ID if all else fails
            try {
                $productManagement = $this->productManagement;
                if (method_exists($productManagement, 'createDiscountProduct')) {
                    return $productManagement->createDiscountProduct();
                }
            } catch (\Exception $fallbackException) {
                $this->psrLogger->error('VINDI_MULTIPAYMENT: Fallback also failed: ' . $fallbackException->getMessage());
            }
            
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Could not create or find discount product for multi-payment. Please check Vindi configuration.')
            );
        }
    }

    /**
     * Mask sensitive data in bill information before storing.
     *
     * @param array $billData
     * @return array
     */
    protected function maskSensitiveData($billData)
    {
        if (!is_array($billData)) {
            return $billData;
        }

        $maskedData = $billData;

        // Remove or mask sensitive payment information
        $sensitiveFields = [
            'payment_profile' => function($data) {
                if (is_array($data) && isset($data['card_number'])) {
                    $data['card_number'] = $this->maskCardNumber($data['card_number']);
                }
                if (is_array($data) && isset($data['card_cvv'])) {
                    $data['card_cvv'] = '***';
                }
                return $data;
            },
            'charges' => function($charges) {
                if (is_array($charges)) {
                    foreach ($charges as &$charge) {
                        if (isset($charge['payment_method']) && is_array($charge['payment_method'])) {
                            if (isset($charge['payment_method']['card_number'])) {
                                $charge['payment_method']['card_number'] = $this->maskCardNumber($charge['payment_method']['card_number']);
                            }
                            if (isset($charge['payment_method']['card_cvv'])) {
                                $charge['payment_method']['card_cvv'] = '***';
                            }
                        }
                    }
                }
                return $charges;
            }
        ];

        foreach ($sensitiveFields as $field => $maskingFunction) {
            if (isset($maskedData[$field])) {
                $maskedData[$field] = $maskingFunction($maskedData[$field]);
            }
        }

        return $maskedData;
    }

    /**
     * Mask card number showing only last 4 digits.
     *
     * @param string $cardNumber
     * @return string
     */
    protected function maskCardNumber($cardNumber)
    {
        if (empty($cardNumber) || strlen($cardNumber) < 4) {
            return '****';
        }

        $last4 = substr($cardNumber, -4);
        return '**** **** **** ' . $last4;
    }

    /**
     * Save order information to subscription orders table.
     *
     * @param Order $order
     * @return void
     */
    protected function saveOrderToSubscriptionOrdersTable($order)
    {
        try {
            $this->psrLogger->info('Starting saveOrderToSubscriptionOrdersTable for order: ' . $order->getIncrementId());
            
            $subscriptionId = $order->getData('vindi_subscription_id');
            $this->psrLogger->info('Retrieved subscription ID: ' . ($subscriptionId ?: 'NULL'));
            
            if (!$subscriptionId) {
                $this->psrLogger->warning('No subscription ID found for order ' . $order->getIncrementId());
                return;
            }

            // Use the proper Repository/Model approach instead of direct DB insertion
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $subscriptionOrderFactory = $objectManager->get(\Vindi\Payment\Model\SubscriptionOrderFactory::class);
            $subscriptionOrderRepository = $objectManager->get(\Vindi\Payment\Api\SubscriptionOrderRepositoryInterface::class);

            /** @var \Vindi\Payment\Model\SubscriptionOrder $subscriptionOrder */
            $subscriptionOrder = $subscriptionOrderFactory->create();
            
            // Set all the required data
            $subscriptionOrder->setSubscriptionId($subscriptionId);
            $subscriptionOrder->setOrderId($order->getId());
            $subscriptionOrder->setIncrementId($order->getIncrementId());
            $subscriptionOrder->setStatus($order->getStatus());
            $subscriptionOrder->setTotal($order->getGrandTotal());
            $subscriptionOrder->setCreatedAt($order->getCreatedAt() ?: date('Y-m-d H:i:s'));
            
            $this->psrLogger->info('Subscription order data prepared: ' . json_encode([
                'subscription_id' => $subscriptionId,
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId(),
                'status' => $order->getStatus(),
                'total' => $order->getGrandTotal()
            ]));

            // Save using repository
            $subscriptionOrderRepository->save($subscriptionOrder);
            $this->psrLogger->info('Successfully saved order ' . $order->getIncrementId() . ' to subscription orders table using repository.');

        } catch (\Exception $e) {
            $this->psrLogger->error('Error saving order to subscription orders table: ' . $e->getMessage());
            $this->psrLogger->error('Stack trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * Calcula itens proporcionais para rateio entre cartões
     * @param array $productList
     * @param float $ratio
     * @return array
     */
    protected function calculateProportionalItems($productList, $ratio)
    {
        $proportionalItems = [];
        
        foreach ($productList as $item) {
            $proportionalItem = $item;
            
            // Calcular preço proporcional
            if (isset($item['pricing_schema']['price'])) {
                $originalPrice = (float)$item['pricing_schema']['price'];
                $proportionalPrice = round($originalPrice * $ratio, 2);
                $proportionalItem['pricing_schema']['price'] = $proportionalPrice;
            }
            
            // Calcular descontos proporcionais
            if (isset($item['discounts']) && is_array($item['discounts'])) {
                foreach ($proportionalItem['discounts'] as &$discount) {
                    if (isset($discount['amount'])) {
                        $originalDiscount = (float)$discount['amount'];
                        $proportionalDiscount = round($originalDiscount * $ratio, 2);
                        $discount['amount'] = $proportionalDiscount;
                    }
                }
            }
            
            $proportionalItems[] = $proportionalItem;
        }
        
        return $proportionalItems;
    }
}
