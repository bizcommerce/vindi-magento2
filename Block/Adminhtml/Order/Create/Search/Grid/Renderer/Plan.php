<?php
namespace Vindi\Payment\Block\Adminhtml\Order\Create\Search\Grid\Renderer;

use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Magento\Framework\DataObject;

class Plan extends AbstractRenderer
{
    /**
     * Render select with recurrence plans from product attribute vindi_recurrence_data
     *
     * @param DataObject $row
     * @return string
     */
    public function render(DataObject $row)
    {
        $enabled = ($row->getData('vindi_enable_recurrence') == '1');
        $html = '<select name="plan_id"';
        if (!$enabled) {
            $html .= ' disabled="disabled"';
        }
        $html .= '>';
        if ($enabled) {
            $recurrenceDataJson = $row->getData('vindi_recurrence_data');
            $options = [];
            if ($recurrenceDataJson) {
                $recurrenceData = json_decode($recurrenceDataJson, true);
                if (is_array($recurrenceData)) {
                    $recurrenceData = array_filter($recurrenceData, function($data) {
                        return isset($data['price']) && is_numeric($data['price']) && $data['price'] > 0;
                    });
                    foreach ($recurrenceData as $data) {
                        $planId = $data['plan'];
                        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                        $optionsSource = $objectManager->get(\Vindi\Payment\Model\Config\Source\Options::class);
                        $optionsArray = $optionsSource->toOptionArray();
                        $optionsMapping = [];
                        foreach ($optionsArray as $option) {
                            $optionsMapping[$option['value']] = $option['label'];
                        }
                        $label = isset($optionsMapping[$planId]) ? $optionsMapping[$planId] : $planId;
                        $options[$planId] = $label;
                    }
                }
            }
            $html .= '<option value="">' . __('Select Plan') . '</option>';
            foreach ($options as $value => $label) {
                $html .= '<option value="' . $value . '">' . $label . '</option>';
            }
        } else {
            $html .= '<option value="">' . __('') . '</option>';
        }
        $html .= '</select>';
        return $html;
    }
}
