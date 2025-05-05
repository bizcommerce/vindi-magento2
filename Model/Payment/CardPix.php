<?php

namespace Vindi\Payment\Model\Payment;

use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\PaymentInterface;
use Vindi\Payment\Block\Info\CardPix as InfoBlock;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Class CardPix
 *
 * @package Vindi\Payment\Model\Payment
 */
class CardPix extends AbstractMethod
{
    const CODE = 'vindi_cardpix';

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
     * Card type codes mapping array
     *
     * @var array
     */
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
     * Assign data to the payment method
     *
     * @param DataObject $data
     * @return CardPix
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(DataObject $data)
    {
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_object($additionalData)) {
            $additionalData = new DataObject($additionalData ?: []);
        }
        $info = $this->getInfoInstance();

        if ($additionalData->getData("payment_profile")) {
            $profile = $this->getPaymentProfile($additionalData->getData("payment_profile"));

            $info->setAdditionalInformation('cc_type', (string) $this->getCardTypeCode($profile->getCcType()));
            $info->setAdditionalInformation('cc_owner', (string) $profile->getCcName());
            $info->setAdditionalInformation('cc_last_4', (string) $profile->getCcLast4());
            $info->setAdditionalInformation('cc_installments', (string) $additionalData->getData("cc_installments"));
        } else {
            $ccType  = $additionalData->getCcType();
            $ccOwner = $additionalData->getCcOwner();
            $ccLast4 = substr((string)$additionalData->getCcNumber(), -4);

            $info->addData([
                'cc_type'           => (string) $ccType,
                'cc_owner'          => (string) $ccOwner,
                'cc_last_4'         => $ccLast4,
                'cc_number'         => (string) $additionalData->getCcNumber(),
                'cc_cid'            => (string) $additionalData->getCcCvv(),
                'cc_exp_month'      => (string) $additionalData->getCcExpMonth(),
                'cc_exp_year'       => (string) $additionalData->getCcExpYear(),
                'cc_ss_issue'       => (string) $additionalData->getCcSsIssue(),
                'cc_ss_start_month' => (string) $additionalData->getCcSsStartMonth(),
                'cc_ss_start_year'  => (string) $additionalData->getCcSsStartYear(),
                'cc_installments'   => (string) $additionalData->getData("cc_installments"),
            ]);

            $info->setAdditionalInformation('cc_installments', (string) $additionalData->getData("cc_installments"));
        }

        $info->setAdditionalInformation('payment_profile', $additionalData->getData("payment_profile"));
        $info->setAdditionalInformation('pix_code', $additionalData->getPixCode());
        $info->setAdditionalInformation('amount_credit', $additionalData->getAmountCredit());
        $info->setAdditionalInformation('amount_pix', $additionalData->getAmountPix());
        $info->save();

        parent::assignData($data);

        return $this;
    }

    /**
     * Get card type code from the provided type
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
     * Retrieve the payment method code
     *
     * @return string
     */
    protected function getPaymentMethodCode()
    {
        return PaymentMethod::CARD_PIX;
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
