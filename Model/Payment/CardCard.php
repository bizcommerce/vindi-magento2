<?php
namespace Vindi\Payment\Model\Payment;

use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\PaymentInterface;
use Vindi\Payment\Block\Info\CardCard as InfoBlock;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Class CardCard
 *
 * @package Vindi\Payment\Model\Payment
 */
class CardCard extends AbstractMethod
{
    const CODE = 'vindi_cardcard';

    /** @var string */
    protected $_code = self::CODE;

    /** @var bool */
    protected $_isOffline = false;

    /** @var string */
    protected $_infoBlockType = InfoBlock::class;

    /** @var bool */
    protected $_isGateway = true;

    /** @var bool */
    protected $_canAuthorize = true;

    /** @var bool */
    protected $_canCapture = true;

    /** @var bool */
    protected $_canCapturePartial = true;

    /** @var bool */
    protected $_canRefund = true;

    /** @var bool */
    protected $_canVoid = true;

    /** @var bool */
    protected $_canUseInternal = true;

    /** @var bool */
    protected $_canUseCheckout = true;

    /** @var bool */
    protected $_canUseForMultishipping = true;

    /** @var bool */
    protected $_isInitializeNeeded = false;

    /** @var bool */
    protected $_canSaveCc = true;

    /** @var array */
    protected $methodsCodes = [
        'mastercard'        => 'MC',
        'visa'              => 'VI',
        'american_express'  => 'AE',
        'elo'               => 'ELO',
        'hipercard'         => 'HC',
        'diners_club'       => 'DN',
        'jcb'               => 'JCB',
    ];

    /**
     * Assign data to the payment method.
     *
     * @param DataObject $data
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(DataObject $data)
    {
        parent::assignData($data);

        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_object($additionalData)) {
            $additionalData = new DataObject($additionalData ?: []);
        }

        $this->psrLogger->info('VINDI_CARDCARD assignData: ' . json_encode($additionalData->getData()));

        $info = $this->getInfoInstance();

        $additionalInfo = $info->getAdditionalInformation();
        if (!is_array($additionalInfo)) {
            $additionalInfo = [];
        }

        if ($additionalData->getData('payment_profile')) {
            $ccOwner1 = 'Card Owner';
            $ccLast41 = '****';
            $profileId = $additionalData->getData('payment_profile');
            $profileData = $this->getCardInfoFromLocalProfile($profileId);
            if ($profileData) {
                $ccOwner1 = $profileData['cc_owner'];
                $ccLast41 = $profileData['cc_last_4'];
            }

            $ccType1 = $additionalData->getData('cc_type1') ?: $this->getCardTypeFromProfile($profileId);

            $this->psrLogger->info('VINDI_CARDCARD: First card - Profile ID: ' . $profileId . ', cc_type1 from frontend: ' . ($additionalData->getData('cc_type1') ?: 'EMPTY') . ', final ccType1: ' . $ccType1);

            $additionalInfo['cc_type'] = (string) $this->getCardTypeCode($ccType1);
            $additionalInfo['cc_owner'] = (string) $ccOwner1;
            $additionalInfo['cc_last_4'] = $ccLast41;
            $additionalInfo['cc_installments'] = (string) $additionalData->getData('cc_installments1');
        } else {
            $ccType1  = $additionalData->getData('cc_type1');
            $ccOwner1 = $additionalData->getData('cc_owner1');
            $ccLast41 = substr((string)$additionalData->getData('cc_number1'), -4);

            $info->addData([
                'cc_type'           => (string) $this->getCardTypeCode($ccType1),
                'cc_owner'          => (string) $ccOwner1,
                'cc_last_4'         => $ccLast41,
                'cc_number'         => (string) $additionalData->getData('cc_number1'),
                'cc_cvv'            => (string) $additionalData->getData('cc_cvv1'),
                'cc_exp_month'      => (string) $additionalData->getData('cc_exp_month1'),
                'cc_exp_year'       => (string) $additionalData->getData('cc_exp_year1'),
                'cc_installments'   => (string) $additionalData->getData('cc_installments1'),
            ]);

            $additionalInfo['cc_installments'] = (string) $additionalData->getData('cc_installments1');
        }

        if ($additionalData->getData('payment_profile2')) {
            $ccOwner2 = 'Card Owner';
            $ccLast42 = '****';
            $profileId2 = $additionalData->getData('payment_profile2');
            $profileData2 = $this->getCardInfoFromLocalProfile($profileId2);
            if ($profileData2) {
                $ccOwner2 = $profileData2['cc_owner'];
                $ccLast42 = $profileData2['cc_last_4'];
            }

            $ccType2 = $additionalData->getData('cc_type2') ?: $this->getCardTypeFromProfile($profileId2);

            $this->psrLogger->info('VINDI_CARDCARD: Second card - Profile ID: ' . $profileId2 . ', cc_type2 from frontend: ' . ($additionalData->getData('cc_type2') ?: 'EMPTY') . ', final ccType2: ' . $ccType2);

            $additionalInfo['cc_type2'] = (string) $this->getCardTypeCode($ccType2);
            $additionalInfo['cc_owner2'] = (string) $ccOwner2;
            $additionalInfo['cc_last_4_2'] = $ccLast42;
            $additionalInfo['cc_installments2'] = (string) $additionalData->getData('cc_installments2');
        } else {
            $additionalInfo['cc_type2'] = (string) $this->getCardTypeCode($additionalData->getData('cc_type2'));
            $additionalInfo['cc_owner2'] = (string) $additionalData->getData('cc_owner2');
            $additionalInfo['cc_last_4_2'] = substr((string) $additionalData->getData('cc_number2'), -4);
            $additionalInfo['cc_number2'] = (string) $additionalData->getData('cc_number2');
            $additionalInfo['cc_cvv2'] = (string) $additionalData->getData('cc_cvv2');
            $additionalInfo['cc_exp_month2'] = (string) $additionalData->getData('cc_exp_month2');
            $additionalInfo['cc_exp_year2'] = (string) $additionalData->getData('cc_exp_year2');
            $additionalInfo['cc_installments2'] = (string) $additionalData->getData('cc_installments2');
        }

        $additionalInfo['amount_credit'] = $additionalData->getAmountCredit();
        $additionalInfo['amount_second_card'] = $additionalData->getAmountSecondCard();
        $additionalInfo['payment_profile'] = $additionalData->getData('payment_profile');
        $additionalInfo['payment_profile2'] = $additionalData->getData('payment_profile2');

        $info->setAdditionalInformation($additionalInfo);

        return $this;
    }

    /**
     * Returns card data based on the locally saved payment profile ID.
     *
     * @param int $localEntityId
     * @return array|null
     */
    protected function getCardInfoFromLocalProfile(int $localEntityId)
    {
        try {
            $profile = $this->paymentProfileRepository->getById($localEntityId);
            return [
                'cc_owner'  => $profile->getCcName(),
                'cc_last_4' => $profile->getCcLast4(),
                'cc_type'   => $profile->getCcType(),
            ];
        } catch (\Exception $e) {
            $this->logger->error("Erro ao buscar payment profile local: " . $e->getMessage());
            return null;
        }
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
            return 'VI';
        }

        try {
            $paymentProfile = $this->paymentProfileRepository->getById($profileId);
            if ($paymentProfile && $paymentProfile->getCcType()) {
                return $paymentProfile->getCcType();
            }
        } catch (\Exception $e) {
            $this->psrLogger->error('VINDI_CARDCARD: Error getting card type from profile: ' . $e->getMessage());
        }

        return 'VI';
    }

    /**
     * Get payment method code.
     *
     * @return string
     */
    protected function getPaymentMethodCode()
    {
        return \Vindi\Payment\Model\Payment\PaymentMethod::CARD_CARD;
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
