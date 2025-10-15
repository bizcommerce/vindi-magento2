<?php

namespace Vindi\Payment\Controller\PaymentProfile;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\View\Result\PageFactory;
use Vindi\Payment\Model\Payment\PaymentMethod;
use Vindi\Payment\Model\Payment\Profile as PaymentProfileManager;
use Vindi\Payment\Model\PaymentProfileFactory;
use Vindi\Payment\Model\PaymentProfileRepository;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Vindi\Payment\Model\Payment\Customer as VindiCustomer;
use Vindi\Payment\Model\SubscriptionFactory;
use Vindi\Payment\Model\ResourceModel\Subscription as SubscriptionResource;
use Vindi\Payment\Helper\Api;

/**
 * Class Save
 * @package Vindi\Payment\Controller\PaymentProfile
 */
class Save extends Action
{
    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var Session
     */
    protected $customerSession;

    /**
     * @var PaymentProfileManager
     */
    protected $paymentProfileManager;

    /**
     * @var PaymentProfileFactory
     */
    protected $paymentProfileFactory;

    /**
     * @var DataPersistorInterface
     */
    protected $dataPersistor;

    /**
     * @var PaymentProfileRepository
     */
    protected $paymentProfileRepository;

    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var VindiCustomer
     */
    protected $vindiCustomer;

    /**
     * @var SubscriptionFactory
     */
    protected $subscriptionFactory;

    /**
     * @var SubscriptionResource
     */
    protected $subscriptionResource;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var PaymentMethod
     */
    protected $paymentMethod;

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param Session $customerSession
     * @param PaymentProfileFactory $paymentProfileFactory
     * @param PaymentProfileRepository $paymentProfileRepository
     * @param PaymentProfileManager $paymentProfileManager
     * @param DataPersistorInterface $dataPersistor
     * @param CustomerRepositoryInterface $customerRepository
     * @param VindiCustomer $vindiCustomer
     * @param SubscriptionFactory $subscriptionFactory
     * @param SubscriptionResource $subscriptionResource
     * @param Api $api
     * @param PaymentMethod $paymentMethod
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        Session $customerSession,
        PaymentProfileFactory $paymentProfileFactory,
        PaymentProfileRepository $paymentProfileRepository,
        PaymentProfileManager $paymentProfileManager,
        DataPersistorInterface $dataPersistor,
        CustomerRepositoryInterface $customerRepository,
        VindiCustomer $vindiCustomer,
        SubscriptionFactory $subscriptionFactory,
        SubscriptionResource $subscriptionResource,
        Api $api,
        PaymentMethod $paymentMethod
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->customerSession = $customerSession;
        $this->paymentProfileFactory = $paymentProfileFactory;
        $this->paymentProfileRepository = $paymentProfileRepository;
        $this->paymentProfileManager = $paymentProfileManager;
        $this->dataPersistor = $dataPersistor;
        $this->customerRepository = $customerRepository;
        $this->vindiCustomer = $vindiCustomer;
        $this->subscriptionFactory = $subscriptionFactory;
        $this->subscriptionResource = $subscriptionResource;
        $this->api = $api;
        $this->paymentMethod = $paymentMethod;
    }

    /**
     * Dispatch request
     *
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws NotFoundException
     */
    public function dispatch(RequestInterface $request)
    {
        if (!$this->customerSession->authenticate()) {
            $this->_actionFlag->set('', 'no-dispatch', true);
        }
        return parent::dispatch($request);
    }

    /**
     * Execute action
     *
     * @return ResponseInterface
     * @throws \Exception
     */
    public function execute()
    {
        $request    = $this->getRequest();
        $data       = $request->getPostValue();
        $customerId = $this->customerSession->getCustomerId();

        try {
            $entityId       = isset($data['entity_id']) ? (int) $data['entity_id'] : null;
            $subscriptionId = isset($data['subscription_id']) ? (int) $data['subscription_id'] : null;
            $paymentProfile = $this->paymentProfileFactory->create();

            if ($entityId) {
                $paymentProfile = $this->paymentProfileRepository->getById($entityId);
            }

            // Dados do cliente
            $customer = $this->customerRepository->getById($customerId);
            $customerVindiId = $this->vindiCustomer->findOrCreateFromCustomerAccount($customer);

            // === AJUSTE: Buscar perfil existente na Vindi por BIN (6) + last4 ===
            $linkedExisting = false;
            $existingProfile = null;

            list($firstSix, $lastFour) = $this->extractBinAndLast4FromForm($data);
            if ($firstSix && $lastFour) {
                // Usa o método já existente do módulo (Payment\Profile::getPaymentProfile)
                $resp = $this->paymentProfileManager->getPaymentProfile((int)$customerVindiId, (int)$firstSix, (int)$lastFour);

                if (is_array($resp)) {
                    if (isset($resp['payment_profiles']) && is_array($resp['payment_profiles']) && !empty($resp['payment_profiles'])) {
                        // já vem ordenado por sort_order=desc; pega o mais recente
                        $existingProfile = $resp['payment_profiles'][0];
                    } elseif (isset($resp['payment_profile']) && is_array($resp['payment_profile'])) {
                        $existingProfile = $resp['payment_profile'];
                    }
                }
            }

            if ($existingProfile && isset($existingProfile['id'])) {
                // Encontrou na Vindi — não cria; apenas vincula localmente
                $vindiPaymentProfile = ['payment_profile' => $existingProfile];
                $linkedExisting = true;
            } else {
                // Mantém a lógica original de criação na Vindi
                $vindiData = $this->formatPaymentProfileData($data, $customerId);
                $vindiPaymentProfile = $this->paymentProfileManager->createFromCustomerAccount(
                    $vindiData,
                    $customerVindiId,
                    'credit_card'
                );
            }

            // Mantém a lógica original de mascarar e setar dados de cartão
            $this->setCreditCardData($data);

            // Evitar duplicidade local: se já existir o mesmo payment_profile_id para o cliente, reaproveita
            $ppId = (int) ($vindiPaymentProfile['payment_profile']['id'] ?? 0);
            if ($ppId > 0 && !$entityId) {
                $alreadyLocal = $this->loadLocalByVindiPaymentProfileId($ppId, (int)$customerId);
                if ($alreadyLocal) {
                    $paymentProfile = $alreadyLocal;
                }
            }

            $paymentProfile->setData([
                'payment_profile_id' => $ppId,
                'vindi_customer_id'  => $customerVindiId,
                'customer_id'        => $customerId,
                'customer_email'     => $customer->getEmail(),
                'cc_number'          => $data['cc_number'],
                'cc_exp_date'        => $data['cc_exp_date'],
                'cc_name'            => $data['cc_name'],
                'cc_type'            => $data['cc_type'],
                'cc_last_4'          => $data['cc_last_4'],
                'status'             => $vindiPaymentProfile["payment_profile"]["status"] ?? null,
                'token'              => $vindiPaymentProfile["payment_profile"]["token"]  ?? null,
                'type'               => $vindiPaymentProfile["payment_profile"]["type"]   ?? 'credit_card'
            ]);

            $this->paymentProfileRepository->save($paymentProfile);

            if ($subscriptionId && $ppId) {
                if ($this->updateVindiPaymentProfile($ppId, $subscriptionId)) {
                    $this->updateSubscriptionPaymentProfile($subscriptionId, $ppId);
                }
            }

            // Mantém sua mensagem original (se preferir diferenciar, posso ajustar)
            $this->messageManager->addSuccessMessage(__('New payment profile created successfully.'));
            $this->dataPersistor->set('vindi_payment_profile', $data);
        } catch (\Exception $e) {
            $this->messageManager->addWarningMessage(__('An error occurred while saving the payment profile: ') . '"' . $e->getMessage() . '"');
            $this->dataPersistor->set('vindi_payment_profile', $data);
            $resultRedirect = $this->resultRedirectFactory->create();
            return $resultRedirect->setPath('*/*/edit', ['id' => $entityId]);
        }

        $resultRedirect = $this->resultRedirectFactory->create();
        return $resultRedirect->setPath('vindi_vr/paymentprofile/index');
    }

    /**
     * Format payment profile data
     *
     * @param array $data
     * @param int $customerId
     * @return array
     */
    private function formatPaymentProfileData(array $data, $customerId): array
    {
        $cardNumber = preg_replace('/\D/', '', $data['cc_number']);

        $expirationParts = explode('/', $data['cc_exp_date']);
        $expirationYear  = (strlen($expirationParts[1]) == 2) ? '20' . $expirationParts[1] : $expirationParts[1];
        $cardExpiration  = $expirationParts[0] . '/' . $expirationYear;
        $paymentCompanyCode = $data['cc_type'];

        $ccTypeCode = $this->paymentMethod->getCreditCardApiCode($paymentCompanyCode);
        return [
            'holder_name'          => $data['cc_name'],
            'card_expiration'      => $cardExpiration,
            'card_number'          => $cardNumber,
            'card_cvv'             => $data['cc_cvv'],
            'customer_id'          => $customerId,
            'payment_company_code' => $ccTypeCode,
            'payment_method_code'  => 'credit_card',
        ];
    }

    /**
     * Mask credit card number
     *
     * @param $cardNumber
     * @return string
     */
    private function maskCreditCardNumber($cardNumber)
    {
        $lastFourDigits = substr($cardNumber, -4);
        $maskLength     = strlen($cardNumber) - 4;
        $mask           = str_repeat("*", $maskLength);

        $maskedCardNumber = $mask . $lastFourDigits;

        return $maskedCardNumber;
    }

    /**
     * Set credit card data
     *
     * @param $data
     * @return void
     */
    private function setCreditCardData(&$data)
    {
        $data['cc_last_4'] = substr($data['cc_number'], -4);
        $data['cc_number'] = $this->maskCreditCardNumber($data['cc_number']);
    }

    /**
     * Update the subscription with the new payment profile
     *
     * @param int $subscriptionId
     * @param int $paymentProfileId
     * @throws \Exception
     */
    private function updateSubscriptionPaymentProfile($subscriptionId, $paymentProfileId)
    {
        $subscription = $this->subscriptionFactory->create();
        $this->subscriptionResource->load($subscription, $subscriptionId);

        if (!$subscription->getId()) {
            throw new \Exception(__('Subscription not found.'));
        }

        $subscription->setPaymentProfile($paymentProfileId);
        $this->subscriptionResource->save($subscription);
    }

    /**
     * Update Vindi payment profile
     *
     * @param int $paymentProfileId
     * @param int $subscriptionId
     * @return bool
     */
    private function updateVindiPaymentProfile($paymentProfileId, $subscriptionId)
    {
        try {
            $this->api->request('subscriptions/' . $subscriptionId, 'PUT', [
                'payment_profile' => [
                    'id' => $paymentProfileId
                ]
            ]);

            return true;
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Failed to update Vindi payment profile: ') . '"' . $e->getMessage() . '"');
            return false;
        }
    }

    /**
     * Extrai BIN (6 primeiros dígitos) e last4 (últimos 4) do número do cartão do formulário.
     *
     * @param array $data
     * @return array [string|null $firstSix, string|null $lastFour]
     */
    private function extractBinAndLast4FromForm(array $data): array
    {
        $digits = isset($data['cc_number']) ? preg_replace('/\D+/', '', (string)$data['cc_number']) : '';
        if ($digits === '') {
            return [null, null];
        }

        $firstSix = (strlen($digits) >= 6) ? substr($digits, 0, 6) : null;
        $lastFour = (strlen($digits) >= 4) ? substr($digits, -4)  : null;

        return [$firstSix, $lastFour];
    }

    /**
     * Carrega (se existir) um registro local com o mesmo payment_profile_id para o cliente.
     *
     * @param int $paymentProfileId
     * @param int $customerId
     * @return \Vindi\Payment\Model\PaymentProfile|null
     */
    private function loadLocalByVindiPaymentProfileId(int $paymentProfileId, int $customerId)
    {
        $collection = $this->paymentProfileFactory->create()->getCollection();
        $collection->addFieldToFilter('payment_profile_id', $paymentProfileId);
        $collection->addFieldToFilter('customer_id', $customerId);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        return ($item && $item->getId()) ? $item : null;
    }
}
