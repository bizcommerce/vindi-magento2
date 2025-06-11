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
        $plan = $this->isSubscriptionOrder($order);

        if ($plan) {
            if ($this->helperData->isMultiMethod($paymentMethodCode)) {
                if ($paymentMethodCode !== PaymentMethod::CARD_CARD) {
                    return $this->handleError($order);
                }
                return $this->processMultiMethodSubscriptionPayment($payment, $amount, $plan);
            } else {
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
            $paymentProfile = ($payment->getAdditionalInformation('payment_profile'))
                ? $this->getPaymentProfile((int)$payment->getAdditionalInformation('payment_profile'))
                : $this->createPaymentProfile($order, $payment, $customerId);

            $body['payment_profile'] = ['id' => $paymentProfile->getData('payment_profile_id')];
        }

        $installments = $payment->getAdditionalInformation('installments') ?: $payment->getInstallments();
        if ($installments) {
            $body['installments'] = (int)$installments;
        }

        $bill = $this->bill->create($body);
        if ($bill) {
            $this->handleBankSplitAdditionalInformation($payment, $body, $bill);
            if ($this->successfullyPaid($body, $bill)) {
                $order->setVindiBillId($bill['id']);
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
        $customerId = $this->customer->findOrCreate($order);
        $productList = $this->productManagement->findOrCreateProductsFromOrder($order);

        $amountCredit = $payment->getAdditionalInformation('amount_credit');
        $amountPix    = $payment->getAdditionalInformation('amount_pix');
        if (!$amountCredit || !$amountPix) {
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
            'amount'     => -((float)$amountPix),
        ];

        $paymentProfile = $payment->getAdditionalInformation('payment_profile')
            ? $this->getPaymentProfile((int)$payment->getAdditionalInformation('payment_profile'))
            : $this->createPaymentProfile($order, $payment, $customerId);

        $bodyCredit['payment_profile'] = ['id' => $paymentProfile->getData('payment_profile_id')];

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

        $billPix = $this->bill->create($bodyPix);
        if (!$billPix || !$this->successfullyPaid($bodyPix, $billPix)) {
            if ($billPix && isset($billPix['id'])) {
                $this->bill->delete($billPix['id']);
            }
            $this->bill->delete($billCredit['id']);
            return $this->handleError($order);
        }
        $this->handleBankSplitAdditionalInformation($payment, $bodyPix, $billPix);

        $order->setVindiBillId($billCredit['id'] . ',' . $billPix['id']);
        $this->savePaymentSplitRecord(
            $order,
            $billCredit,
            $billPix,
            $amountCredit,
            $amountPix,
            PaymentMethod::CREDIT_CARD,
            PaymentMethod::PIX
        );
        $order->getPayment()->setMethod(CardPix::CODE);
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
            $paymentProfile1 = $this->getPaymentProfile($profileId1);
        } else {
            $paymentProfile1 = $this->createPaymentProfile($order, $payment, $customerId, 'first');
        }
        $bodyCard1['payment_profile'] = ['id' => $paymentProfile1->getData('payment_profile_id')];

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
            $paymentProfile2 = $this->getPaymentProfile($profileId2);
        } else {
            $paymentProfile2 = $this->createPaymentProfile($order, $payment, $customerId, 'second');
        }
        $bodyCard2['payment_profile'] = ['id' => $paymentProfile2->getData('payment_profile_id')];

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

        $order->setVindiBillId($billCard1['id'] . ',' . $billCard2['id']);
        $this->savePaymentSplitRecord(
            $order,
            $billCard1,
            $billCard2,
            $amountCredit,
            $amountSecondCard,
            PaymentMethod::CREDIT_CARD,
            PaymentMethod::CREDIT_CARD
        );
        $order->getPayment()->setMethod(CardCard::CODE);
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

        $paymentProfile = $payment->getAdditionalInformation('payment_profile')
            ? $this->getPaymentProfile((int)$payment->getAdditionalInformation('payment_profile'))
            : $this->createPaymentProfile($order, $payment, $customerId);

        $bodyCredit['payment_profile'] = ['id' => $paymentProfile->getData('payment_profile_id')];

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

        $order->setVindiBillId($billCredit['id'] . ',' . $billBankslipPix['id']);
        $this->savePaymentSplitRecord(
            $order,
            $billCredit,
            $billBankslipPix,
            $amountCredit,
            $amountBankslipPix,
            PaymentMethod::CREDIT_CARD,
            PaymentMethod::BANK_SLIP_PIX
        );
        $order->getPayment()->setMethod(CardBankslipPix::CODE);
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
                    ? $this->getPaymentProfile((int)$payment->getAdditionalInformation('payment_profile'))
                    : $this->createPaymentProfile($order, $payment, $customerId);

                if ($paymentProfile) {
                    $body['payment_profile'] = ['id' => $paymentProfile->getData('payment_profile_id')];
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
                    $order->setVindiBillId($billId);
                    $order->setVindiSubscriptionId($responseData['subscription']['id']);
                    $this->saveOrderToSubscriptionOrdersTable($order);
                    return $billId;
                } else {
                    $this->subscriptionRepository->deleteAndCancelBills($subscription['id']);
                    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                    $sub = $objectManager->create(\Vindi\Payment\Model\Subscription::class)->load($subscription['id']);
                    $sub->setStatus('canceled');
                    $sub->save();
                    if ($body['payment_method_code'] === PaymentMethod::CREDIT_CARD) {
                        $paymentProfileId = $paymentProfile->getPaymentProfileId();
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
        if (!$amountCredit || !$amountSecondCard) {
            return $this->handleError($order);
        }

        $multiPaymentDiscountProductId = $this->getMultiPaymentDiscountProductId();

        $bodyCard1 = [
            'customer_id'        => $customerId,
            'payment_method_code'=> PaymentMethod::CREDIT_CARD,
            'bill_items'         => $productList,
            'code'               => $order->getIncrementId() . '-01'
        ];
        $bodyCard1['amount'] = $amountCredit;
        $billCard1 = $this->bill->create($bodyCard1);
        if (!$billCard1 || !$this->successfullyPaid($bodyCard1, $billCard1)) {
            if ($billCard1 && isset($billCard1['id'])) {
                $this->bill->delete($billCard1['id']);
            }
            return $this->handleError($order);
        }
        $this->handleBankSplitAdditionalInformation($payment, $bodyCard1, $billCard1);

        $bodyCard2 = [
            'customer_id'        => $customerId,
            'payment_method_code'=> PaymentMethod::CREDIT_CARD,
            'bill_items'         => $productList,
            'code'               => $order->getIncrementId() . '-02'
        ];
        $bodyCard2['amount'] = $amountSecondCard;
        $billCard2 = $this->bill->create($bodyCard2);
        if (!$billCard2 || !$this->successfullyPaid($bodyCard2, $billCard2)) {
            if ($billCard2 && isset($billCard2['id'])) {
                $this->bill->delete($billCard2['id']);
            }
            $this->bill->delete($billCard1['id']);
            return $this->handleError($order);
        }
        $this->handleBankSplitAdditionalInformation($payment, $bodyCard2, $billCard2);

        $combinedBillId = $billCard1['id'] . ',' . $billCard2['id'];
        $this->savePaymentSplitRecord(
            $order,
            $billCard1,
            $billCard2,
            $amountCredit,
            $amountSecondCard,
            PaymentMethod::CREDIT_CARD,
            PaymentMethod::CREDIT_CARD
        );

        $options = $orderItem->getProductOptions();
        if (!empty($options['info_buyRequest']['selected_plan_id'])) {
            $planId = $options['info_buyRequest']['selected_plan_id'];
            $vindiPlan = $this->vindiPlanRepository->getById($planId);
            $planId = $vindiPlan->getVindiId();
        } else {
            $planId = $this->planManagement->create($orderItem->getProductId());
        }

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

        $responseData = $this->subscriptionRepository->create($bodySubscription);
        if ($responseData) {
            $subscription = $responseData['subscription'] ?? null;

            if ($subscription) {
                $this->saveSubscriptionToDatabase($subscription, $order, $combinedBillId);
                $order->setVindiSubscriptionId($subscription['id']);
            }

            $order->setVindiBillId($combinedBillId);
            $order->getPayment()->setMethod(CardCard::CODE);

            $this->saveOrderToSubscriptionOrdersTable($order);

            $this->orderRepository->save($order);

            return $combinedBillId;
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
        $billIds = $order->getVindiBillId();
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
        $order->setVindiBillId($billIds);
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
}
