<?php
namespace Vindi\Payment\Block\Adminhtml\Order\Create\Search\Renderer;

use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Magento\Framework\DataObject;

class Plan extends AbstractRenderer
{
    /**
     * Render select with recurrence plans from product attribute vindi_recurrence_data and attach JS for setting admin session values.
     *
     * @param DataObject $row
     * @return string
     */
    public function render(DataObject $row)
    {
        $enabled = ($row->getData('vindi_enable_recurrence') == '1');
        $url = $this->getUrl('vindi_payment/order/setPlan');
        $html = '<select name="plan_id"';
        if (!$enabled) {
            $html .= ' disabled="disabled"';
        }
        $html .= ' data-mage-init=\'{"Vindi_Payment/js/order/set-plan": {"setPlanUrl": "' . $url . '"}}\'>';
        if ($enabled) {
            $recurrenceDataJson = $row->getData('vindi_recurrence_data');
            if ($recurrenceDataJson) {
                $recurrenceData = json_decode($recurrenceDataJson, true);
                if (is_array($recurrenceData)) {
                    $recurrenceData = array_filter($recurrenceData, function($data) {
                        return isset($data['price']) && is_numeric($data['price']) && $data['price'] > 0;
                    });
                    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                    $optionsSource = $objectManager->get(\Vindi\Payment\Model\Config\Source\Options::class);
                    $optionsArray = $optionsSource->toOptionArray();
                    $optionsMapping = [];
                    foreach ($optionsArray as $option) {
                        $optionsMapping[$option['value']] = $option['label'];
                    }
                    $html .= '<option value="">' . __('Select Plan') . '</option>';
                    foreach ($recurrenceData as $data) {
                        $planId = $data['plan'];
                        $price = $data['price'];
                        $installments = isset($data['installments']) ? $data['installments'] : 1;
                        $label = isset($optionsMapping[$planId]) ? $optionsMapping[$planId] : $planId;
                        $html .= '<option value="' . $planId . '" data-price="' . $price . '" data-installments="' . $installments . '">';
                        $html .= $label;
                        $html .= '</option>';
                    }
                } else {
                    $html .= '<option value="">' . __('Recurrence data invalid') . '</option>';
                }
            } else {
                $html .= '<option value="">' . __('No recurrence data') . '</option>';
            }
        } else {
            $html .= '<option value="">' . __('Recurrence disabled') . '</option>';
        }
        $html .= '</select>';
        return $html;
    }
}
