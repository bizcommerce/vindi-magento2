<?php

namespace Vindi\Payment\Controller\Adminhtml\Subscription;

use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Vindi\Payment\Helper\Api;
use Vindi\Payment\Model\Subscription;

/**
 * Class Save
 *
 * This controller saves the subscription data.
 *
 * @package Vindi\Payment\Controller\Adminhtml\Subscription
 */
class Save extends Action
{
    /**
     * @var DataPersistorInterface
     */
    protected $dataPersistor;

    /**
     * @var Api
     */
    private $api;

    /**
     * Constructor
     *
     * @param Context $context
     * @param DataPersistorInterface $dataPersistor
     * @param Api $api
     */
    public function __construct(
        Context $context,
        DataPersistorInterface $dataPersistor,
        Api $api
    ) {
        $this->dataPersistor = $dataPersistor;
        parent::__construct($context);
        $this->api = $api;
    }

    /**
     * Save action
     *
     * @return ResultInterface
     */
    public function execute()
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();

        if ($data) {
            $id = $this->getRequest()->getParam('id');
            if (!$id) {
                $this->messageManager->addErrorMessage(__('Invalid Subscription ID.'));
                return $resultRedirect->setPath('*/*/');
            }

            try {
                $requestData = [
                    'payment_method_code' => $data['payment_settings']['payment_method']
                ];

                if ($data['payment_settings']['payment_method'] === 'credit_card') {
                    if (empty($data['payment_settings']['payment_profile'])) {
                        $this->messageManager->addWarningMessage(__('You must select a payment profile for credit card subscriptions.'));
                        return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
                    }

                    $requestData['payment_profile'] = [
                        'id' => $data['payment_settings']['payment_profile']
                    ];
                }

                $request = $this->api->request('subscriptions/' . $id, 'PUT', $requestData);
            } catch (Exception $e) {
                $this->messageManager->addExceptionMessage($e, __('API request failed: %1', $e->getMessage()));
                return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
            }

            if (!is_array($request)) {
                $this->messageManager->addErrorMessage(__('This Subscription no longer exists or API request failed.'));
                return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
            }

            $model = $this->_objectManager->create(Subscription::class)->load($id);
            if (!$model->getId()) {
                $this->messageManager->addErrorMessage(__('Subscription with ID %1 does not exist.', $id));
                return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
            }

            $model->setData('payment_method', $data['payment_settings']['payment_method']);

            if (isset($data['payment_settings']['payment_profile'])) {
                $model->setData('payment_profile', $data['payment_settings']['payment_profile']);
            }

            try {
                $model->save();
                $this->_eventManager->dispatch(
                    'vindi_subscription_update',
                    ['subscription_id' => $id]
                );
                $this->messageManager->addSuccessMessage(__('You saved the subscription.'));
                $this->dataPersistor->clear('vindi_payment_subscription');

                return $this->getRequest()->getParam('back')
                    ? $resultRedirect->setPath('*/*/edit', ['id' => $id])
                    : $resultRedirect->setPath('*/*/');
            } catch (LocalizedException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            } catch (Exception $e) {
                $this->messageManager->addExceptionMessage($e, __('Something went wrong while saving the Subscription.'));
            }

            $this->dataPersistor->set('vindi_payment_subscription', $data);
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        return $resultRedirect->setPath('*/*/');
    }
}
