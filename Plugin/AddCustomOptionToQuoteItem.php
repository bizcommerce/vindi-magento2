<?php
namespace Vindi\Payment\Plugin;

use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Framework\Exception\LocalizedException;

/**
 * Class AddCustomOptionToQuoteItem
 * @package Vindi\Payment\Plugin
 */
class AddCustomOptionToQuoteItem
{
    /**
     * Before add product to quote, add custom options if the product has vindi_recurrence_can_show set to 1.
     *
     * @param \Magento\Quote\Model\Quote $subject
     * @param $product
     * @param \Magento\Framework\DataObject|null $request
     * @param string $processMode
     * @return array
     * @throws LocalizedException
     */
    public function beforeAddProduct(
        \Magento\Quote\Model\Quote $subject,
                                   $product,
                                   $request = null,
                                   $processMode = AbstractType::PROCESS_MODE_FULL
    ) {
        if ($product->getData('vindi_enable_recurrence') == '1') {
            if ($request instanceof \Magento\Framework\DataObject) {
                $additionalOptions = [];

                $selectedPlanId = $request->getData('selected_plan_id');

                if (empty($selectedPlanId) && $this->isLoadingExistingCart($subject)) {
                    return [$product, $request, $processMode];
                }

                if (empty($selectedPlanId)) {
                    throw new LocalizedException(__('A plan must be selected for this product.'));
                }

                $additionalOptions[] = [
                    'label' => __('Plan ID'),
                    'value' => $selectedPlanId,
                    'code'  => 'plan_id'
                ];

                $additionalOptions[] = [
                    'label' => __('Price'),
                    'value' => $request->getData('selected_plan_price'),
                    'code'  => 'plan_price'
                ];

                if ($request->getData('selected_plan_installments') > 0) {
                    $additionalOptions[] = [
                        'label' => __('Installments'),
                        'value' => $request->getData('selected_plan_installments'),
                        'code'  => 'plan_installments'
                    ];
                }

                if (!empty($additionalOptions)) {
                    $product->addCustomOption('additional_options', json_encode($additionalOptions));
                }
            }
        }

        return [$product, $request, $processMode];
    }

    /**
     * Checks if this operation is just loading an existing cart
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return bool
     */
    private function isLoadingExistingCart($quote)
    {
        if ($this->isAdminQuote()) {
            return true;
        }

        if ($quote && $quote->getAllVisibleItems() && count($quote->getAllVisibleItems()) > 0) {
            return true;
        }

        $backtracePatterns = [
            '_assignProducts',
            'getItemCollection',
            'getItems',
            'getItemCount',
            'getAllVisibleItems'
        ];

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
        foreach ($backtrace as $trace) {
            if (isset($trace['function']) && in_array($trace['function'], $backtracePatterns)) {
                return true;
            }

            if (isset($trace['class']) && (
                strpos($trace['class'], '\\Adminhtml\\Order\\') !== false ||
                strpos($trace['class'], '\\Sales\\Block\\Adminhtml\\Order\\') !== false ||
                strpos($trace['class'], '\\Quote\\Model\\ResourceModel\\Quote\\Item\\Collection') !== false
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if we're in admin area
     *
     * @return bool
     */
    private function isAdminQuote()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        try {
            $state = $objectManager->get('\Magento\Framework\App\State');
            if ($state->getAreaCode() === 'adminhtml') {
                return true;
            }
        } catch (\Exception $e) {
            // Continue with other checks
        }

        try {
            $session = $objectManager->get('\Magento\Backend\Model\Session');
            if ($session) {
                return true;
            }
        } catch (\Exception $e) {
            // Do nothing, just prevent exception breaking execution
        }

        return false;
    }
}
