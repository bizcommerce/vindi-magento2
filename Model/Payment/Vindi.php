<?php

namespace Vindi\Payment\Model\Payment;

use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\PaymentInterface;
use Vindi\Payment\Block\Info\Cc;
use Vindi\Payment\Model\PaymentProfile;

class Vindi extends \Vindi\Payment\Model\Payment\AbstractMethod
{
    const CODE = 'vindi';

    protected $_code = self::CODE;
    protected $_isOffline = true;
    protected $_infoBlockType = Cc::class;

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
    protected $_canCapturePartial = false;

    /**
     * @var bool
     */
    protected $_canRefund = false;

    /**
     * @var bool
     */
    protected $_canVoid = false;

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
    protected $_canUseForMultishipping = false;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = false;

    /**
     * @var bool
     */
    protected $_canSaveCc = false;

    /**
     * Assign data to info model instance
     *
     * @param mixed $data
     *
     * @return $this
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        // Constrói payload (additional_data pode vir array/objeto/null)
        $rawAdditional = $data->getData(\Magento\Quote\Api\Data\PaymentInterface::KEY_ADDITIONAL_DATA);
        if ($rawAdditional instanceof \Magento\Framework\DataObject) {
            $payload = $rawAdditional;
        } elseif (is_array($rawAdditional)) {
            $payload = new \Magento\Framework\DataObject($rawAdditional);
        } else {
            $payload = new \Magento\Framework\DataObject([]);
        }

        $info = $this->getInfoInstance();

        // parcelas (suporta 'cc_installments' ou 'installments')
        $installments = $payload->getData('cc_installments') ?: $payload->getData('installments');
        if ($installments !== null && $installments !== '') {
            $info->setAdditionalInformation('installments', $installments);
        }

        // ---- Fluxo: perfil salvo ----
        $selectedProfile = $payload->getData('payment_profile'); // geralmente ID LOCAL
        if ($selectedProfile) {
            // persiste a seleção (id local, assim seu AbstractMethod->choosePaymentProfile mapeia depois)
            $info->setAdditionalInformation('payment_profile', (string)$selectedProfile);

            $ccType = null; $ccOwner = null; $ccLast4 = null;

            // 1) tenta como ID LOCAL (mais comum nos checkouts)
            $entity = null;
            try {
                $entity = $this->paymentProfileRepository->getById((int)$selectedProfile);
            } catch (\Exception $e) { /* ignora */ }

            if ($entity && $entity->getId()) {
                // pega dados do cartão da entidade local
                if (method_exists($entity, 'getCcType'))  { $ccType  = $entity->getCcType(); }
                if (method_exists($entity, 'getCcName'))  { $ccOwner = $entity->getCcName(); }
                if (method_exists($entity, 'getCcLast4')) { $ccLast4 = $entity->getCcLast4(); }

                // também guarda o ID da Vindi (payment_profile_id) se existir
                if (method_exists($entity, 'getPaymentProfileId') && $entity->getPaymentProfileId()) {
                    $info->setAdditionalInformation('vindi_payment_profile_id', (int)$entity->getPaymentProfileId());
                }
            } else {
                // 2) fallback: pode ter vindo o ID REMOTO da Vindi; tenta encontrá-lo na base local
                $byProfile = $this->getPaymentProfile((int)$selectedProfile); // busca por payment_profile_id
                if ($byProfile) {
                    if (method_exists($byProfile, 'getCcType'))  { $ccType  = $byProfile->getCcType(); }
                    if (method_exists($byProfile, 'getCcName'))  { $ccOwner = $byProfile->getCcName(); }
                    if (method_exists($byProfile, 'getCcLast4')) { $ccLast4 = $byProfile->getCcLast4(); }
                }
            }

            // Preenche no info para evitar nulls em validações/blocks
            if ($ccType)  { $info->setCcType($ccType); }
            if ($ccOwner) { $info->setCcOwner($ccOwner); }
            if ($ccLast4) { $info->setCcLast4($ccLast4); }

            // mantém seguro: zera número/cvv no fluxo de perfil salvo
            $info->setCcNumber(null);
            $info->setCcCid(null);

            // não chame o parent aqui novamente para não sobrescrever o que setamos
            return $this;
        }

        // ---- Fluxo: cartão novo ----
        // suporta chaves comuns dos checkouts
        $ccType     = $payload->getData('cc_type')      ?: $payload->getData('cc_type1');
        $ccOwner    = $payload->getData('cc_owner')     ?: $payload->getData('cc_owner1');
        $ccNumber   = $payload->getData('cc_number')    ?: $payload->getData('cc_number1') ?: $payload->getData('cc_number_single');
        $ccCvv      = $payload->getData('cc_cvv')       ?: $payload->getData('cc_cid')     ?: $payload->getData('cc_cvv1') ?: $payload->getData('cc_cid1');
        $ccExpMonth = $payload->getData('cc_exp_month') ?: $payload->getData('cc_exp_month1');
        $ccExpYear  = $payload->getData('cc_exp_year')  ?: $payload->getData('cc_exp_year1');

        // grava nos campos padrão do payment info
        if ($ccType)     { $info->setCcType($ccType); }
        if ($ccOwner)    { $info->setCcOwner($ccOwner); }
        if ($ccNumber)   { $info->setCcNumber($ccNumber); }
        if ($ccCvv)      { $info->setCcCid($ccCvv); }
        if ($ccExpMonth) { $info->setCcExpMonth($ccExpMonth); }
        if ($ccExpYear)  { $info->setCcExpYear($ccExpYear); }

        // opcional: também guarda BIN/last4 como additional_information (útil para matching depois)
        if ($ccNumber) {
            $digits = preg_replace('/\D+/', '', (string)$ccNumber);
            if ($digits !== '') {
                if (strlen($digits) >= 6) { $info->setAdditionalInformation('cc_first6', substr($digits, 0, 6)); }
                if (strlen($digits) >= 4) { $info->setAdditionalInformation('cc_last4',  substr($digits, -4)); }
            }
        }

        return parent::assignData($data);
    }

    /**
     * @return string
     */
    protected function getPaymentMethodCode()
    {
        return PaymentMethod::CREDIT_CARD;
    }

    public function validate()
    {
        $info = $this->getInfoInstance();
        $paymentProfile = $info->getAdditionalInformation('payment_profile');

        if (!$paymentProfile) {
            $ccNumber = $info->getCcNumber();
            if ($ccNumber) {
                $ccNumber = preg_replace('/\D/', '', (string)$ccNumber);

                $info->setCcNumber($ccNumber);

                if (!$this->paymentMethod->isCcTypeValid($info->getCcType())) {
                    throw new \Exception(__('Credit card type is not allowed for this payment method.'));
                }
            }
        }

        return $this;
    }
}
