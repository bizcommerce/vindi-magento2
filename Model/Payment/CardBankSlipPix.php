<?php
namespace Vindi\Payment\Model\Payment;

use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\PaymentInterface;
use Vindi\Payment\Block\Info\CardBankslipPix as InfoBlock;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Class CardBankslipPix
 *
 * Payment method for Card + Bolepix transactions.
 */
class CardBankslipPix extends AbstractMethod
{
    const CODE = 'vindi_cardbankslippix';

    /**
     * Payment method code
     *
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * Is offline flag
     *
     * @var bool
     */
    protected $_isOffline = false;

    /**
     * Info block type
     *
     * @var string
     */
    protected $_infoBlockType = InfoBlock::class;

    /**
     * Is gateway flag
     *
     * @var bool
     */
    protected $_isGateway = true;

    /**
     * Can authorize flag
     *
     * @var bool
     */
    protected $_canAuthorize = true;

    /**
     * Can capture flag
     *
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * Can capture partial flag
     *
     * @var bool
     */
    protected $_canCapturePartial = true;

    /**
     * Can refund flag
     *
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * Can void flag
     *
     * @var bool
     */
    protected $_canVoid = true;

    /**
     * Can use internal flag
     *
     * @var bool
     */
    protected $_canUseInternal = true;

    /**
     * Can use checkout flag
     *
     * @var bool
     */
    protected $_canUseCheckout = true;

    /**
     * Can use for multishipping flag
     *
     * @var bool
     */
    protected $_canUseForMultishipping = true;

    /**
     * Is initialization needed flag
     *
     * @var bool
     */
    protected $_isInitializeNeeded = false;

    /**
     * Can save credit card flag
     *
     * @var bool
     */
    protected $_canSaveCc = true;

    /**
     * Credit card type codes mapping array
     *
     * @var array
     */
    protected $methodsCodes = [
        'mastercard' => 'MC',
        'visa' => 'VI',
        'american_express' => 'AE',
        'elo' => 'ELO',
        'hipercard' => 'HC',
        'diners_club' => 'DN',
        'jcb' => 'JCB',
    ];

    /**
     * Assign data to the payment method.
     *
     * @param DataObject $data
     * @return CardBankslipPix
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(DataObject $data)
    {
        parent::assignData($data);

        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_object($additionalData)) {
            $additionalData = new DataObject($additionalData ?: []);
        }
        
        // Debug log all additional data received
        $this->psrLogger->info('VINDI_CARDBANKSLIPPIX assignData: ' . json_encode($additionalData->getData()));
        
        $info = $this->getInfoInstance();

        // Ensure additional_information is array
        $additionalInfo = $info->getAdditionalInformation();
        if (!is_array($additionalInfo)) {
            $additionalInfo = [];
        }

        if ($additionalData->getData("payment_profile")) {
            $profileId = $additionalData->getData("payment_profile");
            
            // Get real card type from saved profile or use type from frontend
            $ccType = $additionalData->getData('cc_type') ?: $this->getCardTypeFromProfile($profileId);
            
            $this->psrLogger->info('VINDI_CARDBANKSLIPPIX: Profile ID: ' . $profileId . ', cc_type from frontend: ' . ($additionalData->getData('cc_type') ?: 'EMPTY') . ', final ccType: ' . $ccType);
            
            $additionalInfo['cc_type'] = (string) $this->getCardTypeCode($ccType);
            $additionalInfo['cc_owner'] = 'Card Owner'; // Default owner  
            $additionalInfo['cc_last_4'] = '****'; // Default last 4
            $additionalInfo['cc_installments'] = (string) $additionalData->getData("cc_installments");
        } else {
            $ccType  = $additionalData->getData("cc_type");
            $ccOwner = $additionalData->getData("cc_owner");
            $ccLast4 = substr((string)$additionalData->getData("cc_number"), -4);

            $info->addData([
                'cc_type'           => (string) $this->getCardTypeCode($ccType),
                'cc_owner'          => (string) $ccOwner,
                'cc_last_4'         => $ccLast4,
                'cc_number'         => (string) $additionalData->getData("cc_number"),
                'cc_cvv'            => (string) $additionalData->getData("cc_cvv"),
                'cc_exp_month'      => (string) $additionalData->getData("cc_exp_month"),
                'cc_exp_year'       => (string) $additionalData->getData("cc_exp_year"),
                'cc_installments'   => (string) $additionalData->getData("cc_installments"),
            ]);

            $additionalInfo['cc_installments'] = (string) $additionalData->getData("cc_installments");
        }

        $additionalInfo['payment_profile'] = $additionalData->getData("payment_profile");
        $additionalInfo['bankslip_pix_code'] = $additionalData->getBankslipPixCode();
        $additionalInfo['amount_credit'] = $additionalData->getAmountCredit();
        $additionalInfo['amount_bankslippix'] = $additionalData->getAmountBankslippix();
        
        $info->setAdditionalInformation($additionalInfo);

        return $this;
    }

    /**
     * Get the credit card type code.
     *
     * @param string $ccType
     * @return string
     */
    private function getCardTypeCode($ccType)
    {
        foreach ($this->methodsCodes as $key => $value) {
            if ($key === $ccType) {
                return $key;
            }
        }
        return $ccType;
    }

    /**
     * Get card type from payment profile
     *
     * @param int $profileId
     * @return string
     */
    private function getCardTypeFromProfile($profileId)
    {
        if (!$profileId) {
            return 'VI'; // Default fallback
        }

        try {
            // Use the payment profile repository to get the profile
            $paymentProfile = $this->paymentProfileRepository->getById($profileId);
            if ($paymentProfile && $paymentProfile->getCcType()) {
                return $paymentProfile->getCcType();
            }
        } catch (\Exception $e) {
            $this->psrLogger->error('VINDI_CARDBANKSLIPPIX: Error getting card type from profile: ' . $e->getMessage());
        }

        return 'VI'; // Default fallback
    }

    /**
     * Get payment method code.
     *
     * @return string
     */
    protected function getPaymentMethodCode()
    {
        return PaymentMethod::CARD_BANKSLIP_PIX;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($quote === null) {
            $quote = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(CheckoutSession::class)
                ->getQuote();
        }
        foreach ($quote->getAllVisibleItems() as $item) {
            $product = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(ProductRepositoryInterface::class)
                ->getById($item->getProduct()->getId());
            if ($product->getData('vindi_enable_recurrence') == '1') {
                return false;
            }
        }
        return parent::isAvailable($quote);
    }
}
