<?php
namespace Vindi\Payment\Model\Payment;

use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\PaymentInterface;
use Vindi\Payment\Block\Info\CardPix as InfoBlock;

/**
 * Class CardBankslipPix
 *
 * Payment method for Card + Bolepix transactions.
 */
class CardBankslipPix extends AbstractMethod
{
    const CODE = 'vindi_cardbankslippix';

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isOffline = false;

    /**
     * @var string
     */
    protected $_infoBlockType = InfoBlock::class;

    /**
     * @var bool
     */
    protected $_isGateway = true;

    /**
     * @var bool
     */
    protected $_canAuthorize = true;

    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * @var bool
     */
    protected $_canCapturePartial = true;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canVoid = true;

    /**
     * @var bool
     */
    protected $_canUseInternal = true;

    /**
     * @var bool
     */
    protected $_canUseCheckout = true;

    /**
     * @var bool
     */
    protected $_canUseForMultishipping = true;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = false;

    /**
     * @var bool
     */
    protected $_canSaveCc = true;

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

        $ccType = $additionalData->getCcType();
        $ccOwner = $additionalData->getCcOwner();
        $ccLast4 = substr((string) $additionalData->getCcNumber(), -4);

        $info = $this->getInfoInstance();
        $info->setAdditionalInformation('installments', $additionalData->getCcInstallments());
        $paymentProfileId = (string) $additionalData->getData('payment_profile');
        if ($paymentProfileId) {
            $info->setAdditionalInformation('payment_profile', $paymentProfileId);
            $paymentProfile = $this->getPaymentProfile((int) $paymentProfileId);
            $ccType = $paymentProfile->getCcType();
            $ccOwner = $paymentProfile->getCcName();
            $ccLast4 = $paymentProfile->getCcLast4();
        }

        $info->addData([
            'cc_type'           => $ccType,
            'cc_owner'          => $ccOwner,
            'cc_last_4'         => $ccLast4,
            'cc_number'         => (string) $additionalData->getCcNumber(),
            'cc_cid'            => (string) $additionalData->getCcCvv(),
            'cc_exp_month'      => (string) $additionalData->getCcExpMonth(),
            'cc_exp_year'       => (string) $additionalData->getCcExpYear(),
            'cc_ss_issue'       => (string) $additionalData->getCcSsIssue(),
            'cc_ss_start_month' => (string) $additionalData->getCcSsStartMonth(),
            'cc_ss_start_year'  => (string) $additionalData->getCcSsStartYear()
        ]);

        $info->setAdditionalInformation('bankslip_pix_code', $additionalData->getBankslipPixCode());
        $info->setAdditionalInformation('amount_credit', $additionalData->getAmountCredit());
        $info->setAdditionalInformation('amount_bankslip_pix', $additionalData->getAmountBankslipPix());
        $info->save();

        parent::assignData($data);

        return $this;
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
