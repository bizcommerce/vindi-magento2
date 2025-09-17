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

    /**
     * Process payment logic.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function processPayment(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();

        $customerId = $this->customer->findOrCreate($order);
        if (!$customerId) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Vindi customer_id cannot be blank.'));
        }

        $payment->setAdditionalInformation('customer_id', $customerId);

        $paymentMethodCode = $this->getPaymentMethodCode();
        $plan = $this->helperData->isSubscriptionOrder($order);

        $this->psrLogger->info('VINDI_PAYMENT: Payment Method Code: ' . $paymentMethodCode);
        $this->psrLogger->info('VINDI_PAYMENT: Is Subscription: ' . ($plan ? 'YES' : 'NO'));
        $this->psrLogger->info('VINDI_PAYMENT: Is Multi Method: ' . ($this->helperData->isMultiMethod($paymentMethodCode) ? 'YES' : 'NO'));

        if ($plan) {

            if ($this->helperData->isMultiMethod($paymentMethodCode)) {
                $this->psrLogger->error('VINDI_PAYMENT: Multimeios de pagamento não são suportados para assinaturas. Método: ' . $paymentMethodCode);
                throw new \Magento\Framework\Exception\LocalizedException(__('Multimeios de pagamento não são suportados para produtos com assinatura. Use apenas um método de pagamento.'));
            }

            $this->psrLogger->info('VINDI_PAYMENT: Processando assinatura com método único');
            return $this->processSingleMethodSubscriptionPayment($payment, $plan);
        } else {
            if ($this->helperData->isMultiMethod($paymentMethodCode)) {
                $this->psrLogger->info('VINDI_PAYMENT: Processando compra avulsa com multimeios');
                return $this->processMultiMethodInvoicePayment($payment, $amount);
            } else {
                $this->psrLogger->info('VINDI_PAYMENT: Processando compra avulsa com método único');
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


            if ($profileId) {
                $paymentProfile = $this->getPaymentProfileFromVindi((int)$profileId);
            }


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


        if ($profileId) {
            $paymentProfile = $this->getPaymentProfile((int)$profileId);
        }


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
        $customerId   = $this->customer->findOrCreate($order);
        $productList  = $this->productManagement->findOrCreateProductsFromOrder($order);
        $amount1      = $payment->getAdditionalInformation('amount_credit');
        $amount2      = $payment->getAdditionalInformation('amount_second_card');

        if (!$amount1 || !$amount2) {
            return $this->handleError($order);
        }

        $multiPaymentDiscountProductId = $this->getMultiPaymentDiscountProductId();

        // -----------------------
        // == CARTÃO 1 ==
        // -----------------------
        $body1 = [
            'customer_id'         => $customerId,
            'payment_method_code' => PaymentMethod::CREDIT_CARD,
            'bill_items'          => $productList,
            'code'                => $order->getIncrementId() . '-01',
        ];
        $body1['bill_items'][] = [
            'product_id' => $multiPaymentDiscountProductId,
            'amount'     => -((float)$amount2),
        ];

        /*$local1 = (int)$payment->getAdditionalInformation('payment_profile');
        if ($local1) {
            $profile1    = $this->paymentProfileRepository->getById($local1);
            $vindiId1    = $profile1->getPaymentProfileId();
            $remote1     = $this->getPaymentProfileFromVindi($vindiId1);
            if (!$remote1) {
                $remote1 = $this->createPaymentProfile($order, $payment, $customerId, 'first');
                $profile1->setPaymentProfileId($remote1['id']);
                $this->paymentProfileRepository->save($profile1);
            }
        } else {
            $remote1 = $this->createPaymentProfile($order, $payment, $customerId, 'first');
        }*/
        $remote1 = $this->choosePaymentProfile($order, $payment, $customerId, 'first', null);
        if (!$remote1) {
            $remote1 = $this->createPaymentProfile($order, $payment, $customerId, 'first');
            //$profile1->setPaymentProfileId($remote1['id']);
            //$this->paymentProfileRepository->save($profile1);
        }

        $vindiId1 = $remote1['id'];
        $body1['payment_profile'] = ['id' => $vindiId1];
        $body1['installments']    = (int)($payment->getAdditionalInformation('cc_installments') ?: 1);

        $bill1 = $this->bill->create($body1);
        if (!$bill1 || !$this->successfullyPaid($body1, $bill1)) {
            if (isset($bill1['id'])) {
                $this->bill->delete($bill1['id']);
            }
            return $this->handleError($order);
        }
        $this->handleBankSplitAdditionalInformation($payment, $body1, $bill1);

        // -----------------------
        // == CARTÃO 2 ==
        // -----------------------
        $body2 = [
            'customer_id'         => $customerId,
            'payment_method_code' => PaymentMethod::CREDIT_CARD,
            'bill_items'          => $productList,
            'code'                => $order->getIncrementId() . '-02',
        ];
        $body2['bill_items'][] = [
            'product_id' => $multiPaymentDiscountProductId,
            'amount'     => -((float)$amount1),
        ];

        /*$local2 = (int)$payment->getAdditionalInformation('payment_profile2');
        if ($local2) {
            $profile2    = $this->paymentProfileRepository->getById($local2);
            $vindiId2    = $profile2->getPaymentProfileId();
            $remote2     = $this->getPaymentProfileFromVindi($vindiId2);
            if (!$remote2) {
                $remote2 = $this->createPaymentProfile($order, $payment, $customerId, 'second');
                $profile2->setPaymentProfileId($remote2['id']);
                $this->paymentProfileRepository->save($profile2);
            }
        } else {
            $remote2 = $this->createPaymentProfile($order, $payment, $customerId, 'second');
        }*/
        $remote2 = $this->choosePaymentProfile($order, $payment, $customerId, 'second', null);
        if (!$remote2) {
            $remote2 = $this->createPaymentProfile($order, $payment, $customerId, 'second');
            $this->psrLogger->warning("REMOTE 2 NÃO ENCONTRADO");
            $this->psrLogger->warning(json_encode($remote2));
            //$profile1->setPaymentProfileId($remote1['id']);
            //$this->paymentProfileRepository->save($profile1);
        }

        $vindiId2 = $remote2['id'];
        $body2['payment_profile'] = ['id' => $vindiId2];
        $body2['installments']    = (int)($payment->getAdditionalInformation('cc_installments2') ?: 1);

        $bill2 = $this->bill->create($body2);
        if (!$bill2 || !$this->successfullyPaid($body2, $bill2)) {
            if (isset($bill2['id'])) {
                $this->bill->delete($bill2['id']);
            }
            // desfaz primeira cobrança caso dê erro
            $this->bill->delete($bill1['id']);
            return $this->handleError($order);
        }
        $this->handleBankSplitAdditionalInformation($payment, $body2, $bill2);

        $order->setData('vindi_bill_id', $bill1['id'] . ',' . $bill2['id']);
        $this->savePaymentSplitRecord(
            $order,
            $bill1,
            $bill2,
            $amount1,
            $amount2,
            PaymentMethod::CREDIT_CARD,
            PaymentMethod::CREDIT_CARD
        );
        $order->getPayment()->setMethod('vindi_cardcard');
        $this->orderRepository->save($order);

        return $bill1['id'] . '|' . $bill2['id'];
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


        if ($profileId) {
            $paymentProfile = $this->getPaymentProfileFromVindi((int)$profileId);
        }


        if (!$paymentProfile) {
            $paymentProfile = $this->createPaymentProfile($order, $payment, $customerId);
            if (!$paymentProfile) {
                $this->psrLogger->error("Failed to create payment profile for CardBankslipPix. Using null profile.");

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

                    $this->orderRepository->save($order);

                    $this->psrLogger->info('Order saved successfully. Verifying saved data...');

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

    /**
     * MÉTODO DEPRECADO - NÃO MAIS USADO
     * 
     * Este método processava split payment para assinaturas, mas foi deprecado porque
     * multimeios de pagamento não são mais suportados para assinaturas.
     * 
     * @deprecated A partir de junho 2025
     * @see processPayment() - agora bloqueia multimeios para assinaturas
     */
    protected function processMultiMethodSubscriptionPayment(InfoInterface $payment, $amount, OrderItemInterface $orderItem)
    {

        throw new \Exception('Multimeios de pagamento não são mais suportados para assinaturas. Este método foi deprecado.');
    }

    /**
     * Audita quantas bills foram criadas para uma assinatura específica
     * Usado para verificar se há bills automáticas indesejadas
     *
     * @param int $subscriptionId
     * @param string $orderIncrementId
     * @return array
     */
    protected function auditSubscriptionBills($subscriptionId, $orderIncrementId)
    {
        try {

            $response = $this->api->request("bills?query=subscription_id:{$subscriptionId}", 'GET');

            if ($response && isset($response['bills'])) {
                $bills = $response['bills'];
                $billCount = count($bills);

                $this->psrLogger->info("VINDI_AUDIT: Assinatura {$subscriptionId} (Order {$orderIncrementId}) possui {$billCount} bills:");

                foreach ($bills as $bill) {
                    $this->psrLogger->info("VINDI_AUDIT: - Bill ID: {$bill['id']}, Code: {$bill['code']}, Status: {$bill['status']}, Amount: {$bill['amount']}");
                }


                if ($billCount > 2) {
                    $this->psrLogger->error("VINDI_AUDIT: ALERTA - Assinatura {$subscriptionId} possui {$billCount} bills (esperado: 2). Possível cobrança duplicada!");
                }

                return [
                    'total_bills' => $billCount,
                    'bills' => $bills,
                    'alert' => $billCount > 2
                ];
            }

        } catch (\Exception $e) {
            $this->psrLogger->error('VINDI_AUDIT: Erro ao auditar bills da assinatura: ' . $e->getMessage());
        }

        return [
            'total_bills' => 0,
            'bills' => [],
            'alert' => false
        ];
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

            $additionalInfo = $payment->getAdditionalInformation();
            if (!is_array($additionalInfo)) {
                $additionalInfo = [];
            }


            $additionalInfo['vindi_bill_id'] = $bill['id'] ?? null;
            $additionalInfo['vindi_bill_status'] = $bill['status'] ?? null;


            if (isset($body['payment_method_code'])) {
                $additionalInfo['vindi_payment_method'] = $body['payment_method_code'];
            }


            if (isset($bill['amount'])) {
                $additionalInfo['vindi_bill_amount'] = $bill['amount'];
            }


            if (isset($bill['charges']) && is_array($bill['charges'])) {
                foreach ($bill['charges'] as $index => $charge) {
                    $additionalInfo["vindi_charge_{$index}_id"] = $charge['id'] ?? null;
                    $additionalInfo["vindi_charge_{$index}_status"] = $charge['status'] ?? null;


                    if (isset($charge['last_transaction'])) {
                        $transaction = $charge['last_transaction'];
                        $additionalInfo["vindi_transaction_{$index}_id"] = $transaction['id'] ?? null;
                        $additionalInfo["vindi_transaction_{$index}_status"] = $transaction['status'] ?? null;


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


            $payment->setAdditionalInformation($additionalInfo);




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


        if (!isset($bill['status'])) {
            return false;
        }


        $successStatuses = ['paid', 'pending', 'review'];

        if (in_array($bill['status'], $successStatuses)) {
            return true;
        }


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

                $order->setState(Order::STATE_CANCELED);
                $order->setStatus(Order::STATE_CANCELED);
                $order->addCommentToStatusHistory(
                    __('Payment failed or was cancelled by Vindi.'),
                    false
                );


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

            $paymentProfile = $this->paymentProfileRepository->getByProfileId($profileId);


            $vindiResponse = $this->profile->getPaymentProfileById($profileId);


            if (is_array($vindiResponse) && isset($vindiResponse['not_found']) && $vindiResponse['not_found']) {
                $this->psrLogger->warning("Payment profile ID {$profileId} exists locally but not in Vindi API. Profile may be stale.");


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


            if (is_array($response) && isset($response['not_found']) && $response['not_found']) {
                $this->psrLogger->warning("Payment profile ID {$profileId} not found in Vindi API (404). Will create new profile.");
                return null;
            }


            if ($response === false) {
                $this->psrLogger->warning("Payment profile ID {$profileId} API error. Will create new profile.");
                return null;
            }


            if ($response && isset($response['payment_profile']) && isset($response['payment_profile']['id'])) {
                return ['id' => $response['payment_profile']['id']];
            }


            $this->psrLogger->warning("Payment profile ID {$profileId} response has unexpected structure. Will create new profile.");
            return null;

        } catch (\Exception $e) {
            $this->psrLogger->error('Error getting payment profile from Vindi API: ' . $e->getMessage());

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
            $this->psrLogger->error('VINDI_MULTIMEIOS_NEW: No customer ID provided for profile creation');
            return null;
        }

        $this->psrLogger->info('VINDI_MULTIMEIOS_NEW: Creating payment profile with suffix: ' . $suffix);

        try {
            $paymentMethodCode = $this->getPaymentMethodCode();


            $whichCard = 'first';
            if ($suffix === 'second') {
                $whichCard = 'second';
            }

            $this->psrLogger->info('VINDI_MULTIMEIOS_NEW: Using card: ' . $whichCard);
            $this->psrLogger->info('VINDI_MULTIMEIOS_NEW: Using card: ' . json_encode($payment->getAdditionalInformation()));
            $this->psrLogger->info('VINDI_MULTIMEIOS_NEW: Using card: ' . $paymentMethodCode);

            $response = $this->profile->create($payment, $customerId, $paymentMethodCode, $whichCard);

            $this->psrLogger->info('VINDI_MULTIMEIOS_NEW: Profile creation response: ' . json_encode($response));

            if ($response && isset($response['payment_profile'])) {
                $profileId = $response['payment_profile']['id'];
                $this->psrLogger->info('VINDI_MULTIMEIOS_NEW: Profile created successfully - ID: ' . $profileId);
                return ['id' => $profileId];
            }

            $this->psrLogger->error('VINDI_MULTIMEIOS_NEW: Invalid response from profile creation');
            return null;
        } catch (\Exception $e) {
            $this->psrLogger->error('VINDI_MULTIMEIOS_NEW: Error creating payment profile (' . $suffix . '): ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if payment has valid card data for the specified card (first or second)
     *
     * @param InfoInterface $payment
     * @param string $whichCard 'first' or 'second'
     * @return bool
     */
    protected function hasValidCardData($payment, $whichCard = 'first')
    {
        if ($whichCard === 'second') {
            $number = $payment->getAdditionalInformation('cc_number2');
            $month = $payment->getAdditionalInformation('cc_exp_month2');
            $year = $payment->getAdditionalInformation('cc_exp_year2');
            $cvv = $payment->getAdditionalInformation('cc_cvv2');
            $owner = $payment->getAdditionalInformation('cc_owner2');
        } else {
            $number = $payment->getAdditionalInformation('cc_number1') ?: $payment->getAdditionalInformation('cc_number') ?: $payment->getCcNumber();
            $month = $payment->getAdditionalInformation('cc_exp_month1') ?: $payment->getAdditionalInformation('cc_exp_month') ?: $payment->getCcExpMonth();
            $year = $payment->getAdditionalInformation('cc_exp_year1') ?: $payment->getAdditionalInformation('cc_exp_year') ?: $payment->getCcExpYear();
            $cvv = $payment->getAdditionalInformation('cc_cvv1') ?: $payment->getAdditionalInformation('cc_cvv') ?: $payment->getCcCid();
            $owner = $payment->getAdditionalInformation('cc_owner1') ?: $payment->getAdditionalInformation('cc_owner') ?: $payment->getCcOwner();
        }


        $hasValidData = !empty($number) && !empty($month) && !empty($year) && !empty($cvv) && !empty($owner);

        $this->psrLogger->info('VINDI_MULTIMEIOS_NEW: hasValidCardData(' . $whichCard . '): ' . ($hasValidData ? 'YES' : 'NO'));

        return $hasValidData;
    }

    /**
     * Get or create multi payment discount product ID
     * @return int
     */
    protected function getMultiPaymentDiscountProductId()
    {

        $discountProductId = $this->helperData->getConfig('general', 'discount_product_id');
        if ($discountProductId && is_numeric($discountProductId)) {
            return (int) $discountProductId;
        }


        $response = $this->api->request('products', 'POST', [
            'name' => 'Desconto Multimeios de Pagamento',
            'code' => 'discount_multipayment_' . time(),
            'status' => 'active',
            'pricing_schema' => ['price' => 0.00]
        ]);

        if ($response && isset($response['product']['id'])) {
            $productId = $response['product']['id'];

            return $productId;
        }

        throw new \Exception('Não foi possível criar produto de desconto na Vindi');
    }

    /**
     * Save order to subscription orders table
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    protected function saveOrderToSubscriptionOrdersTable($order)
    {
        try {
            $subscriptionId = $order->getData('vindi_subscription_id');
            if (!$subscriptionId) {
                $this->psrLogger->warning('Cannot save order to subscription_orders table: no subscription_id found');
                return;
            }

            $tableName = $this->resourceConnection->getTableName('vindi_subscription_orders');
            $data = [
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId(),
                'subscription_id' => $subscriptionId,
                'created_at' => date('Y-m-d H:i:s'),
                'total' => $order->getGrandTotal(),
                'status' => $order->getStatus()
            ];

            $this->connection->insert($tableName, $data);
            $this->psrLogger->info('Order saved to subscription_orders table successfully');
        } catch (\Exception $e) {
            $this->psrLogger->error('Error saving order to subscription_orders table: ' . $e->getMessage());
        }
    }

    /**
     * Mask sensitive data from bill response
     * @param array $billData
     * @return array
     */
    protected function maskSensitiveData($billData)
    {
        if (!is_array($billData)) {
            return $billData;
        }

        $masked = $billData;


        if (isset($masked['payment_profile']['card'])) {
            if (isset($masked['payment_profile']['card']['number'])) {
                $masked['payment_profile']['card']['number'] = '****-****-****-' . substr($masked['payment_profile']['card']['number'], -4);
            }
            if (isset($masked['payment_profile']['card']['security_code'])) {
                $masked['payment_profile']['card']['security_code'] = '***';
            }
        }

        return $masked;
    }

    protected function getSelectedLocalProfileId(InfoInterface $payment, string $whichCard = 'first'): ?int
    {
        $key = ($whichCard === 'second') ? 'payment_profile2' : 'payment_profile';
        $val = $payment->getAdditionalInformation($key);
        return $val ? (int)$val : null;
    }

    protected function localSelectedProfileToVindiId(int $localEntityId): ?int
    {
        try {
            $entity = $this->paymentProfileRepository->getById($localEntityId);
            if (!$entity) { return null; }

            $candidates = [];
            if (method_exists($entity, 'getProfileId'))       { $candidates[] = (int)$entity->getProfileId(); }
            if (method_exists($entity, 'getVindiProfileId'))  { $candidates[] = (int)$entity->getVindiProfileId(); }
            if (method_exists($entity, 'getVindiId'))         { $candidates[] = (int)$entity->getVindiId(); }
            if (method_exists($entity, 'getPaymentProfileId')){ $candidates[] = (int)$entity->getPaymentProfileId(); }

            $data = method_exists($entity, 'getData') ? (array)$entity->getData() : [];
            foreach (['profile_id','vindi_profile_id','vindi_id','payment_profile_id'] as $k) {
                if (isset($data[$k]) && is_numeric($data[$k])) {
                    $candidates[] = (int)$data[$k];
                }
            }

            foreach ($candidates as $id) {
                if ($id > 0) {
                    $this->psrLogger->info("VINDI_PROFILE_LOCAL: Local #{$localEntityId} -> Vindi #{$id}");
                    return $id;
                }
            }

            $this->psrLogger->warning("VINDI_PROFILE_LOCAL: Não encontrei profile_id Vindi para local #{$localEntityId}");
            return null;
        } catch (\Exception $e) {
            $this->psrLogger->error('VINDI_PROFILE_LOCAL: erro ao mapear local->vindi: ' . $e->getMessage());
            return null;
        }
    }

    protected function extractCardBinLast4(InfoInterface $payment, string $whichCard = 'first'): array
    {
        $add = $payment->getAdditionalInformation();
        if (!is_array($add)) { $add = []; }

        $pick = function(array $keys) use ($add) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $add) && $add[$k] !== '' && $add[$k] !== null) {
                    return $add[$k];
                }
            }
            return null;
        };

        if ($whichCard === 'second') {
            $numKeys   = ['cc_number2'];
            $binKeys   = ['cc_first62','cc_bin2','cc_bin','cc_first6_2','cc_first6'];
            $last4Keys = ['cc_last_42','cc_last_4_2','cc_last_4_second','cc_last_4-2','cc_last_4_2nd','cc_last_4_2','cc_last_4-second','cc_last_4_2_card','cc_last_4_2_token','cc_last4','card_last4','last4'];
        } else {
            $numKeys   = ['cc_number1','cc_number']; 
            $binKeys   = ['cc_first61','cc_bin1','cc_bin','cc_first6_1','cc_first6'];
            $last4Keys = ['cc_last_41','cc_last_4_1','cc_last_4_first','cc_last_4','cc_last_4-1','cc_last_4_1_card','cc_last_4_1_token','cc_last4','card_last4','last4'];
        }

        $numRaw = $pick($numKeys);
        $firstSix = $pick($binKeys);
        $last4    = $pick($last4Keys);

        if ($whichCard === 'first') {
            if ($numRaw === null && method_exists($payment, 'getCcNumber')) {
                $numRaw = $payment->getCcNumber();
            }
            if ($last4 === null && method_exists($payment, 'getCcLast4')) {
                $last4 = $payment->getCcLast4();
            }
        }

        $digitsNum = $numRaw !== null ? preg_replace('/\D/','', (string)$numRaw) : null;

        if (!$firstSix && $digitsNum && strlen($digitsNum) >= 6) {
            $firstSix = substr($digitsNum, 0, 6);
        }
        if (!$last4 && $digitsNum && strlen($digitsNum) >= 4) {
            $last4 = substr($digitsNum, -4);
        }

        $firstSix = $firstSix !== null ? preg_replace('/\D/','', (string)$firstSix) : null;
        $last4    = $last4    !== null ? preg_replace('/\D/','', (string)$last4)    : null;

        $this->psrLogger->info('VINDI_PROFILE_LOOKUP.extractCardBinLast4.result', [
            'whichCard'     => $whichCard,
            'firstSix_len'  => $firstSix !== null ? strlen($firstSix) : null,
            'last4'         => $last4,
        ]);

        if (!$firstSix || !$last4) {
            $this->psrLogger->warning("VINDI_PROFILE_LOOKUP: faltou BIN/last4 para {$whichCard}");
        }

        return [$firstSix ?: null, $last4 ?: null];
    }

    protected function tryFindRemoteProfileByBinLast4(InfoInterface $payment, int $customerId, string $whichCard = 'first', ?int $excludeId = null): ?array
    {
        [$firstSix, $lastFour] = $this->extractCardBinLast4($payment, $whichCard);
        if (!$firstSix || !$lastFour) {
            return null;
        }

        try {
            $resp = $this->profile->getPaymentProfile($customerId, $firstSix, $lastFour);
            if ($resp && isset($resp['payment_profiles']) && is_array($resp['payment_profiles'])) {
                foreach ($resp['payment_profiles'] as $pp) {
                    $pid    = (int)($pp['id'] ?? 0);
                    $status = $pp['status'] ?? 'active';
                    if ($pid <= 0) continue;
                    if (in_array($status, ['deleted','inactive','canceled'], true)) continue;
                    if ($excludeId && $pid === $excludeId) continue;
                    $this->psrLogger->info("VINDI_PROFILE_LOOKUP: match BIN/last4 ({$whichCard}) -> id {$pid}");
                    return ['id' => $pid];
                }
            }
        } catch (\Exception $e) {
            $this->psrLogger->warning("VINDI_PROFILE_LOOKUP: erro ao buscar BIN/last4 ({$whichCard}): ".$e->getMessage());
        }

        return null;
    }

    protected function choosePaymentProfile(Order $order, InfoInterface $payment, int $customerId, string $whichCard = 'first', ?int $excludeId = null): ?array
    {
        $localId = $this->getSelectedLocalProfileId($payment, $whichCard);
        if ($localId) {
            $vindiId = $this->localSelectedProfileToVindiId($localId);
            if ($vindiId) {
                if ($excludeId && (int)$vindiId === (int)$excludeId) {
                    $this->psrLogger->warning("VINDI_PROFILE_PICK: {$whichCard} selecionou perfil local {$localId} -> Vindi {$vindiId}, mas é igual ao excludeId; ignorando.");
                } else {
                    $validated = $this->getPaymentProfileFromVindi((int)$vindiId);
                    if ($validated && isset($validated['id'])) {
                        $this->psrLogger->info("VINDI_PROFILE_PICK: usando PERFIL LOCAL selecionado ({$whichCard}) -> Vindi ID {$validated['id']}");
                        return ['id' => (int)$validated['id']];
                    }
                    $this->psrLogger->warning("VINDI_PROFILE_PICK: perfil local {$localId} mapeado para Vindi {$vindiId}, mas não validou na API. Tentando próximas estratégias.");
                }
            }
        }

        $byBin = $this->tryFindRemoteProfileByBinLast4($payment, $customerId, $whichCard, $excludeId);
        if ($byBin) { return $byBin; }

        $this->psrLogger->warning("VINDI_PROFILE_PICK: não foi possível resolver payment_profile para {$whichCard}.");
        return null;
    }
}
