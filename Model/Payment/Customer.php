<?php

namespace Vindi\Payment\Model\Payment;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Vindi\Payment\Helper\Api;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Vindi\Payment\Model\ResourceModel\PaymentProfile\CollectionFactory as PaymentProfileCollectionFactory;
use Vindi\Payment\Model\ResourceModel\VindiCustomer\CollectionFactory as VindiCustomerCollectionFactory;
use Vindi\Payment\Model\VindiCustomerFactory;
use Vindi\Payment\Helper\Data;

class Customer
{
    /**
     * @var CustomerRepositoryInterface
     */
    protected $addressRepository;

    /** @var CustomerRepositoryInterface */
    protected $customerRepository;

    /** @var Api  */
    protected $api;

    /** @var ManagerInterface  */
    protected $messageManager;

    /** @var StoreManagerInterface  */
    protected $storeManager;

    /** @var PaymentProfileCollectionFactory */
    protected $paymentProfileCollectionFactory;

    /** @var VindiCustomerCollectionFactory */
    protected $vindiCustomerCollectionFactory;

    /** @var VindiCustomerFactory */
    protected $vindiCustomerFactory;

    /** @var Data */
    protected $helper;

    /**
     * Customer constructor.
     *
     * @param CustomerRepositoryInterface $customerRepository
     * @param Api $api
     * @param ManagerInterface $messageManager
     * @param AddressRepositoryInterface $addressRepository
     * @param StoreManagerInterface $storeManager
     * @param PaymentProfileCollectionFactory $paymentProfileCollectionFactory
     * @param VindiCustomerCollectionFactory $vindiCustomerCollectionFactory
     * @param VindiCustomerFactory $vindiCustomerFactory
     * @param Data $helper
     */
    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        Api $api,
        ManagerInterface $messageManager,
        AddressRepositoryInterface $addressRepository,
        StoreManagerInterface $storeManager,
        PaymentProfileCollectionFactory $paymentProfileCollectionFactory,
        VindiCustomerCollectionFactory $vindiCustomerCollectionFactory,
        VindiCustomerFactory $vindiCustomerFactory,
        Data $helper
    ) {
        $this->customerRepository = $customerRepository;
        $this->api = $api;
        $this->messageManager = $messageManager;
        $this->addressRepository = $addressRepository;
        $this->storeManager = $storeManager;
        $this->paymentProfileCollectionFactory = $paymentProfileCollectionFactory;
        $this->vindiCustomerCollectionFactory = $vindiCustomerCollectionFactory;
        $this->vindiCustomerFactory = $vindiCustomerFactory;
        $this->helper = $helper;
    }

    /**
     * @param Order $order
     *
     * @return array|bool|mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function findOrCreate(Order $order)
    {
        $billing = $order->getBillingAddress();
        $customer = null;
        $vindiCustomerId = null;

        $environment = $this->getEnvironment();

        if (!$order->getCustomerIsGuest()) {
            $customer = $this->customerRepository->get($billing->getEmail());
            $vindiCustomerId = $this->findVindiCustomerIdByCustomerId($customer->getId());
        }

        if ($vindiCustomerId) {
            if ($order->getPayment()->getMethod() == "vindi_pix") {
                $customerVindi = $this->getVindiCustomerData($customer->getId());

                if (is_array($customerVindi)) {
                    $additionalInfo = $order->getPayment()->getAdditionalInformation();
                    $taxVatOrder = str_replace([' ', '-', '.'], '', $additionalInfo['document'] ?? '');
                    if ($customerVindi['registry_code'] != $taxVatOrder) {
                        $updateData = [
                            'registry_code' => $taxVatOrder,
                        ];
                        $this->updateVindiCustomer($vindiCustomerId, $updateData);
                        $customer->setTaxvat($additionalInfo['document'] ?? '');
                        $this->customerRepository->save($customer);
                    }
                }
            }

            return $vindiCustomerId;
        }

        $addressFields = $this->processStreetFields($billing->getStreet());

        $street = $addressFields['street'];
        $number = $addressFields['number'];
        $additionalDetails = $addressFields['additional_details'];
        $neighborhood = $addressFields['neighborhood'];

        $address = [
            'street' => $street,
            'number' => $number,
            'additional_details' => $additionalDetails,
            'neighborhood' => $neighborhood,
            'zipcode' => $billing->getPostcode(),
            'city'    => $billing->getCity(),
            'state'   => $billing->getRegionCode(),
            'country' => $billing->getCountryId(),
        ];

        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $baseUrl = preg_replace("(^https?://)", "", rtrim($baseUrl, "/"));
        $baseUrl = preg_replace('/[^a-zA-Z0-9]/', '_', $baseUrl);

        if ($customer && $customer->getId()) {
            $uniqueCode = $baseUrl . '_' . $customer->getId() . '_' . time();
        } else {
            $uniqueCode = $baseUrl . '_' . $billing->getEmail() . '_' . time();
        }

        $customerVindi = [
            'name'    => $billing->getFirstname() . ' ' . $billing->getLastname(),
            'email'   => $billing->getEmail(),
            'registry_code' => $this->getDocument($order),
            'code'    => $uniqueCode,
            'phones'  => $this->formatPhone($billing->getTelephone()),
            'address' => $address
        ];

        $vindiCustomerId = $this->createCustomer($customerVindi);

        if ($vindiCustomerId === false) {
            $this->messageManager->addErrorMessage(__('Failed while registering user. Check the data and try again'));
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Failed while registering user. Check the data and try again')
            );
        }

        if ($customer && $customer->getId()) {
            $this->registerVindiCustomer($customer->getId(), $vindiCustomerId, $uniqueCode, $environment);
        }

        return $vindiCustomerId;
    }

    /**
     * Find or create a customer on Vindi based on Magento customer account.
     *
     * @param CustomerInterface $customer
     * @return array|bool|mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function findOrCreateFromCustomerAccount(CustomerInterface $customer)
    {
        $environment = $this->getEnvironment();

        $vindiCustomerId = $this->findVindiCustomerIdByCustomerId($customer->getId());

        if ($vindiCustomerId) {
            return $vindiCustomerId;
        }

        $billingAddressId = $customer->getDefaultBilling();
        if (!$billingAddressId) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Please add a billing address to your account before proceeding.')
            );
        }

        try {
            $billingAddress = $this->addressRepository->getById($billingAddressId);
        } catch (NoSuchEntityException $e) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Billing address not set for customer.')
            );
        }

        $billingStreet = $billingAddress->getStreet();
        $addressFields = $this->processStreetFields($billingStreet);

        $street = $addressFields['street'];
        $number = $addressFields['number'];
        $additionalDetails = $addressFields['additional_details'];
        $neighborhood = $addressFields['neighborhood'];

        $region = $billingAddress->getRegion();

        $state = null;
        if ($region !== null) {
            $state = $region->getRegionCode();
        }

        if (!$state) {
            $state = $billingAddress->getRegionCode();
        }

        $address = [
            'street' => $street,
            'number' => $number,
            'additional_details' => $additionalDetails,
            'neighborhood' => $neighborhood,
            'zipcode' => $billingAddress->getPostcode(),
            'city'    => $billingAddress->getCity(),
            'state'   => $state,
            'country' => $billingAddress->getCountryId(),
        ];

        $registryCode = $customer->getTaxvat();
        if (empty($registryCode)) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('The registry code (CPF/CNPJ) is required for creating a customer on Vindi.')
            );
        }

        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $baseUrl = preg_replace("(^https?://)", "", rtrim($baseUrl, "/"));
        $baseUrl = preg_replace('/[^a-zA-Z0-9]/', '_', $baseUrl);
        $uniqueCode = $baseUrl . '_' . $customer->getId() . '_' . time();

        $customerVindi = [
            'name'    => $customer->getFirstname() . ' ' . $customer->getLastname(),
            'email'   => $customer->getEmail(),
            'registry_code' => $registryCode,
            'code'    => $uniqueCode,
            'phones'  => $this->formatPhone($billingAddress->getTelephone()),
            'address' => $address
        ];

        $vindiCustomerId = $this->createCustomer($customerVindi);

        if ($vindiCustomerId === false) {
            $this->messageManager->addErrorMessage(__('Failed while registering user. Check the data and try again'));
            return false;
        }

        $this->registerVindiCustomer($customer->getId(), $vindiCustomerId, $uniqueCode, $environment);

        return $vindiCustomerId;
    }

    /**
     * Register Vindi customer in the vindi_customers table.
     *
     * @param int $magentoCustomerId
     * @param string $vindiCustomerId
     * @param string $code
     * @param string $environment
     */
    protected function registerVindiCustomer($magentoCustomerId, $vindiCustomerId, $code, $environment)
    {
        $vindiCustomer = $this->vindiCustomerFactory->create();
        $vindiCustomer->setMagentoCustomerId($magentoCustomerId);
        $vindiCustomer->setVindiCustomerId($vindiCustomerId);
        $vindiCustomer->setCode($code);
        $vindiCustomer->setEnvironment($environment);
        $vindiCustomer->save();
    }

    /**
     * Find Vindi Customer ID by Magento Customer ID.
     *
     * @param int $customerId
     *
     * @return string|bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function findVindiCustomerIdByCustomerId($customerId)
    {
        $collection = $this->vindiCustomerCollectionFactory->create();
        $item = $collection->addFieldToFilter('magento_customer_id', $customerId)
            ->addFieldToFilter('environment', $this->getEnvironment())
            ->getFirstItem();

        if ($item->getId()) {
            return $item->getVindiCustomerId();
        }

        $collection = $this->vindiCustomerCollectionFactory->create();
        $item = $collection->addFieldToFilter('magento_customer_id', $customerId)
            ->getFirstItem();

        if (!$item->getId()) {
            return false;
        }

        $vindiCustomerId = $item->getVindiCustomerId();

        $response = $this->api->request("customers/{$vindiCustomerId}", 'GET');

        if (!$response || !isset($response['customer']['id'])) {
            return false;
        }

        $item->setEnvironment($this->getEnvironment());
        $item->setCode($response['customer']['code']);
        $item->save();

        return $vindiCustomerId;
    }


    /**
     * Make an API request to create a Customer.
     *
     * @param array $body (name, email, code)
     *
     * @return array|bool|mixed
     */
    public function createCustomer($body)
    {
        if ($response = $this->api->request('customers', 'POST', $body)) {
            return $response['customer']['id'];
        }

        return false;
    }

    /**
     * Update customer Vindi.
     *
     * @param string $customerId
     * @param array $body
     * @return array|bool|mixed
     */
    public function updateVindiCustomer($customerId, $body)
    {
        $response = $this->api->request("customers/{$customerId}", 'PUT', $body);

        if (isset($response['customer']['id'])) {
            return $response['customer']['id'];
        }

        return false;
    }

    /**
     * Make an API request to retrieve an existing Customer.
     *
     * @param string $query
     *
     * @return array|bool|mixed
     */
    public function findVindiCustomer($query)
    {
        $response = $this->api->request("customers?query=code={$query}", 'GET');

        if ($response && (1 === count($response['customers'])) && isset($response['customers'][0]['id'])) {
            return $response['customers'][0]['id'];
        }

        return false;
    }

    /**
     * Make an API request to retrieve an existing Customer by Email.
     *
     * @param string $query
     *
     * @return array|bool|mixed
     */
    public function findVindiCustomerByEmail($query)
    {
        $response = $this->api->request("customers?query=email={$query}", 'GET');

        if ($response && isset($response['customers']) && count($response['customers']) > 0) {
            $customers = $response['customers'];
            $activeCustomer = null;
            $inactiveCustomer = null;

            foreach ($customers as $customer) {
                if ($customer['status'] == 'active') {
                    $activeCustomer = $customer;
                    break;
                } elseif ($customer['status'] == 'inactive') {
                    $inactiveCustomer = $customer;
                }
            }

            if ($activeCustomer) {
                return $activeCustomer['id'];
            } elseif ($inactiveCustomer) {
                return $inactiveCustomer['id'];
            } else {
                return $customers[0]['id'];
            }
        }

        return false;
    }

    /**
     * Make an API request to retrieve an existing Customer Data.
     *
     * @param string $query
     *
     * @return array|bool|mixed
     */
    public function getVindiCustomerData($query)
    {
        $response = $this->api->request("customers?query=code={$query}", 'GET');

        if ($response && (1 === count($response['customers'])) && isset($response['customers'][0]['id'])) {
            return $response['customers'][0];
        }

        return false;
    }

    /**
     * @param $phone
     *
     * @return string|null
     */
    public function formatPhone($phone)
    {
        $digits = strlen('55' . preg_replace('/^0|\D+/', '', $phone));
        $phone_types = [
            12 => 'landline',
            13 => 'mobile',
        ];

        return array_key_exists($digits, $phone_types) ? $phone_types[$digits] : null;
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     *
     * @return mixed|string
     */
    protected function getDocument(Order $order)
    {
        $document = (string) $order->getPayment()->getAdditionalInformation('document');
        if (!$document) {
            $document = (string) $order->getData('customer_taxvat');
        }
        return $document;
    }

    /**
     * @return string
     */
    protected function getEnvironment()
    {
        $mode = $this->helper->getModuleGeneralConfig("mode");
        return ($mode === "1") ? "production" : "sandbox";
    }

    /**
     * Extract and format address fields dynamically based on available street lines.
     *
     * @param array|null $billingStreet
     * @return array
     */
    protected function processStreetFields($billingStreet)
    {
        $street = '';
        $number = '';
        $additionalDetails = '';
        $neighborhood = '';

        if (is_array($billingStreet)) {
            $lineCount = count($billingStreet);

            switch ($lineCount) {
                case 4:
                    $street = $billingStreet[0];
                    $number = $billingStreet[1];
                    $additionalDetails = $billingStreet[2];
                    $neighborhood = $billingStreet[3];
                    break;

                case 3:
                    $street = $billingStreet[0];
                    $number = $billingStreet[1];
                    $neighborhood = $billingStreet[2];
                    break;

                case 2:
                    $street = $billingStreet[0];
                    $number = $billingStreet[1];
                    break;

                case 1:
                    $street = $billingStreet[0];
                    break;

                default:
                    break;
            }
        }

        return [
            'street' => $street,
            'number' => $number,
            'additional_details' => $additionalDetails,
            'neighborhood' => $neighborhood,
        ];
    }

}
