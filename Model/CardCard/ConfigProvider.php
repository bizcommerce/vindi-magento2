<?php
namespace Vindi\Payment\Model\CardCard;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\CcConfig;
use Magento\Payment\Model\CcGenericConfigProvider;
use Vindi\Payment\Helper\Data;
use Vindi\Payment\Model\Config\Source\CardImages as CardImagesSource;
use Vindi\Payment\Model\Payment\PaymentMethod;
use Vindi\Payment\Model\ResourceModel\PaymentProfile\Collection as PaymentProfileCollection;

/**
 * Class ConfigProvider
 *
 * Provides configuration for the "vindi_cardcard" payment method.
 */
class ConfigProvider extends CcGenericConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'vindi_cardcard';

    /**
     * @var string
     */
    protected $_methodCode = self::CODE;

    /**
     * @var CcConfig
     */
    protected $ccConfig;

    /**
     * @var PaymentHelper
     */
    protected $paymentHelper;

    /**
     * @var Data
     */
    protected $helperData;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var PaymentMethod
     */
    protected $paymentMethod;

    /**
     * @var PaymentProfileCollection
     */
    protected $paymentProfileCollection;

    /**
     * @var CardImagesSource
     */
    protected $creditCardTypeSource;

    /**
     * ConfigProvider constructor.
     *
     * @param CcConfig $ccConfig
     * @param PaymentHelper $paymentHelper
     * @param Data $data
     * @param CustomerSession $customerSession
     * @param PaymentMethod $paymentMethod
     * @param PaymentProfileCollection $paymentProfileCollection
     * @param CardImagesSource $creditCardTypeSource
     */
    public function __construct(
        CcConfig $ccConfig,
        PaymentHelper $paymentHelper,
        Data $data,
        CustomerSession $customerSession,
        PaymentMethod $paymentMethod,
        PaymentProfileCollection $paymentProfileCollection,
        CardImagesSource $creditCardTypeSource
    ) {
        parent::__construct($ccConfig, $paymentHelper, [self::CODE]);
        $this->ccConfig = $ccConfig;
        $this->paymentHelper = $paymentHelper;
        $this->helperData = $data;
        $this->customerSession = $customerSession;
        $this->paymentMethod = $paymentMethod;
        $this->paymentProfileCollection = $paymentProfileCollection;
        $this->creditCardTypeSource = $creditCardTypeSource;
    }

    /**
     * Get configuration for the payment method.
     *
     * @return array
     */
    public function getConfig()
    {
        return [
            'payment' => [
                'vindi_cardcard' => [
                    'availableTypes' => $this->paymentMethod->getCreditCardCodes(),
                    'months' => [$this->_methodCode => $this->ccConfig->getCcMonths()],
                    'years' => [$this->_methodCode => $this->ccConfig->getCcYears()],
                    'hasVerification' => [$this->_methodCode => $this->ccConfig->hasVerification()],
                    'isInstallmentsAllowedInStore' => (int) $this->helperData->isInstallmentsAllowedInStore(),
                    'maxInstallments' => (int) $this->helperData->getMaxInstallments() ?: 1,
                    'minInstallmentsValue' => (int) $this->helperData->getMinInstallmentsValue(),
                    'saved_cards' => $this->getPaymentProfiles(),
                    'credit_card_images' => $this->getCreditCardImages(),
                    'double_card_enabled' => true
                ]
            ]
        ];
    }

    /**
     * Get saved payment profiles.
     *
     * @return array
     */
    public function getPaymentProfiles(): array
    {
        $paymentProfiles = [];
        if ($this->customerSession->isLoggedIn()) {
            $customerId = $this->customerSession->getCustomerId();
            $this->paymentProfileCollection->addFieldToFilter('customer_id', $customerId);
            $this->paymentProfileCollection->addFieldToFilter('cc_type', ['neq' => '']);
            foreach ($this->paymentProfileCollection as $paymentProfile) {
                $paymentProfiles[] = [
                    'id' => $paymentProfile->getId(),
                    'card_number' => (string) $paymentProfile->getCcLast4(),
                    'card_type' => (string) $paymentProfile->getCcType()
                ];
            }
        }
        return $paymentProfiles;
    }

    /**
     * Get credit card images.
     *
     * @return array
     */
    public function getCreditCardImages(): array
    {
        $ccImages = [];
        $creditCardOptionArray = $this->creditCardTypeSource->toOptionArray();
        foreach ($creditCardOptionArray as $creditCardOption) {
            $ccImages[] = [
                'code' => $creditCardOption['code'],
                'label' => $creditCardOption['label'],
                'value' => $creditCardOption['value']
            ];
        }
        return $ccImages;
    }
}
