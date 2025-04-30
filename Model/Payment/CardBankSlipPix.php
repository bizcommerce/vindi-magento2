<?php
namespace Vindi\Payment\Model\Payment;

use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\PaymentInterface;
use Vindi\Payment\Block\Info\CardBankslipPix as InfoBlock;

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
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_object($additionalData)) {
            $additionalData = new DataObject($additionalData ?: []);
        }
        $info = $this->getInfoInstance();

        if ($additionalData->getData("payment_profile")) {
            $profile1 = $this->getPaymentProfile($additionalData->getData("payment_profile"));

            $info->setAdditionalInformation('cc_type', (string) $this->getCardTypeCode($profile1->getCcType()));
            $info->setAdditionalInformation('cc_owner', (string) $profile1->getCcName());
            $info->setAdditionalInformation('cc_last_4', (string) $profile1->getCcLast4());
            $info->setAdditionalInformation('cc_installments', (string) $additionalData->getData("cc_installments"));
        } else {
            $info->addData([
                'cc_type'           => (string) $this->getCardTypeCode($additionalData->getData("cc_type")),
                'cc_owner'          => (string) $additionalData->getData("cc_owner"),
                'cc_last_4'         => substr((string) $additionalData->getData("cc_number"), -4),
                'cc_number'         => (string) $additionalData->getData("cc_number"),
                'cc_cvv'            => (string) $additionalData->getData("cc_cvv"),
                'cc_exp_month'      => (string) $additionalData->getData("cc_exp_month"),
                'cc_exp_year'       => (string) $additionalData->getData("cc_exp_year"),
                'cc_installments'   => (string) $additionalData->getData("cc_installments"),
            ]);
            $info->setAdditionalInformation('cc_installments', (string) $additionalData->getData("cc_installments"));
        }

        $info->setAdditionalInformation('payment_profile', $additionalData->getData("payment_profile"));
        $info->setAdditionalInformation('bankslip_pix_code', $additionalData->getBankslipPixCode());
        $info->setAdditionalInformation('amount_credit', $additionalData->getAmountCredit());
        $info->setAdditionalInformation('amount_bankslippix', $additionalData->getAmountBankslippix());
        $info->save();

        parent::assignData($data);

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
     * Get payment method code.
     *
     * @return string
     */
    protected function getPaymentMethodCode()
    {
        return PaymentMethod::CARD_BANKSLIP_PIX;
    }
}
