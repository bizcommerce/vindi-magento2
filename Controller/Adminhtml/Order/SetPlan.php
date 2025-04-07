<?php
namespace Vindi\Payment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Class SetPlan
 * @package Vindi\Payment\Controller\Adminhtml\Order
 */
class SetPlan extends Action
{
    /**
     * @var \Magento\Backend\Model\Session\Quote
     */
    protected $sessionQuote;

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @param Action\Context $context
     * @param \Magento\Backend\Model\Session\Quote $sessionQuote
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        Action\Context $context,
        \Magento\Backend\Model\Session\Quote $sessionQuote,
        JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
        $this->sessionQuote = $sessionQuote;
        $this->resultJsonFactory = $resultJsonFactory;
    }

    /**
     * Execute method to set plan data in admin session.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $selectedPlanId = $this->getRequest()->getParam('selected_plan_id');
        $selectedPlanPrice = $this->getRequest()->getParam('selected_plan_price');
        $selectedPlanInstallments = $this->getRequest()->getParam('selected_plan_installments');

        $this->sessionQuote->setData('selected_plan_id', $selectedPlanId);
        $this->sessionQuote->setData('selected_plan_price', $selectedPlanPrice);
        $this->sessionQuote->setData('selected_plan_installments', $selectedPlanInstallments);

        $result = $this->resultJsonFactory->create();
        return $result->setData(['success' => true]);
    }
}
