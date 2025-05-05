<?php

namespace Vindi\Payment\Block\Info;

use Vindi\Payment\Model\Payment\PaymentMethod;
use Magento\Framework\Pricing\Helper\Data;
use Magento\Backend\Block\Template\Context;

/**
 * Class Cc
 *
 * @package Vindi\Payment\Block\Info
 *
 * @method $this setCacheLifetime(false|int $lifetime)
 */
class Cc extends \Magento\Payment\Block\Info
{
    use \Vindi\Payment\Block\InfoTrait;

    /**
     * @var string
     */
    protected $_template = 'Vindi_Payment::info/cc.phtml';

    /**
     * @var Data
     */
    protected $currency;

    /**
     * @var PaymentMethod
     */
    protected $paymentMethod;

    /**
     * Cc constructor.
     *
     * @param PaymentMethod $paymentMethod
     * @param Data          $currency
     * @param Context       $context
     * @param array         $data
     */
    public function __construct(
        PaymentMethod $paymentMethod,
        Data $currency,
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->paymentMethod = $paymentMethod;
        $this->currency      = $currency;
    }

    /**
     * Disable block cache
     */
    protected function _construct()
    {
        parent::_construct();
        $this->setCacheLifetime(false);
    }

    /**
     * Retrieve order instance
     *
     * @return \Magento\Sales\Model\Order
     */
    public function getOrder()
    {
        return $this->getInfo()->getOrder();
    }
}
