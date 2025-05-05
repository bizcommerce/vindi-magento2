<?php
namespace Vindi\Payment\Block\Adminhtml\Subscription\Tab;

use Magento\Backend\Block\Widget\Grid\Extended;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Framework\Registry;
use Vindi\Payment\Model\ResourceModel\VindiSubscriptionItem\CollectionFactory;

/**
 * Class SubscriptionItemGrid
 *
 * @package Vindi\Payment\Block\Adminhtml\Subscription\Tab
 *
 * @method $this setId(string $id)
 * @method $this setDefaultSort(string $sort)
 * @method $this setDefaultDir(string $dir)
 * @method $this setUseAjax(bool $useAjax)
 * @method $this setSaveParametersInSession(bool $save)
 */
class SubscriptionItemGrid extends Extended
{
    /**
     * @var Registry
     */
    protected $coreRegistry;

    /**
     * @var CollectionFactory
     */
    protected $subscriptionItemFactory;

    /**
     * SubscriptionItemGrid constructor.
     *
     * @param Context $context
     * @param BackendHelper $backendHelper
     * @param CollectionFactory $subscriptionItemFactory
     * @param Registry $coreRegistry
     * @param array $data
     */
    public function __construct(
        Context $context,
        BackendHelper $backendHelper,
        CollectionFactory $subscriptionItemFactory,
        Registry $coreRegistry,
        array $data = []
    ) {
        $this->subscriptionItemFactory = $subscriptionItemFactory;
        $this->coreRegistry = $coreRegistry;
        parent::__construct($context, $backendHelper, $data);
    }

    /**
     * Prepare grid settings
     *
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();

        $this->setId('vindi_grid_subscription_items');
        $this->setDefaultSort('entity_id');
        $this->setDefaultDir('ASC');
        $this->setUseAjax(true);
        $this->setSaveParametersInSession(true);

        if ($this->getRequest()->getParam('entity_id')) {
            $this->setDefaultFilter(['in_subscription_items' => 1]);
        } else {
            $this->setDefaultFilter(['in_subscription_items' => 0]);
        }
    }

    /**
     * Prepare collection
     *
     * @return $this
     */
    protected function _prepareCollection()
    {
        $subscriptionId = $this->getRequest()->getParam('id');

        $collection = $this->subscriptionItemFactory->create()
            ->addFieldToSelect([
                'entity_id',
                'product_item_id',
                'product_name',
                'price',
                'status',
                'cycles',
                'uses',
                'quantity'
            ])
            ->addFieldToFilter('subscription_id', $subscriptionId);

        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    /**
     * Prepare columns
     *
     * @return $this
     */
    protected function _prepareColumns()
    {
        $this->addColumn(
            'product_item_id',
            [
                'header' => __('Product Item ID'),
                'index'  => 'product_item_id',
                'type'   => 'number',
                'header_css_class' => 'col-type',
                'column_css_class' => 'col-type',
            ]
        );

        $this->addColumn(
            'product_name',
            [
                'header' => __('Product Name'),
                'index'  => 'product_name',
                'header_css_class' => 'col-type',
                'column_css_class' => 'col-type',
            ]
        );

        $this->addColumn(
            'price',
            [
                'header'        => __('Price'),
                'index'         => 'price',
                'type'          => 'currency',
                'currency_code' => (string)$this->_scopeConfig->getValue(
                    \Magento\Directory\Model\Currency::XML_PATH_CURRENCY_BASE,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                ),
                'header_css_class' => 'col-price',
                'column_css_class' => 'col-price',
            ]
        );

        $this->addColumn(
            'quantity',
            [
                'header' => __('Quantity'),
                'index'  => 'quantity',
                'type'   => 'number',
                'header_css_class' => 'col-quantity',
                'column_css_class' => 'col-quantity',
            ]
        );

        $this->addColumn(
            'status',
            [
                'header'  => __('Status'),
                'index'   => 'status',
                'type'    => 'options',
                'options' => [
                    'active'   => __('Active'),
                    'inactive' => __('Inactive'),
                ],
                'header_css_class' => 'col-status',
                'column_css_class' => 'col-status',
            ]
        );

        $this->addColumn(
            'duration',
            [
                'header'         => __('Duration'),
                'index'          => 'cycles',
                'frame_callback' => [$this, 'renderDurationColumn'],
                'header_css_class' => 'col-duration',
                'column_css_class' => 'col-duration',
            ]
        );

        $this->addColumn(
            'edit_action',
            [
                'header'   => __('Action'),
                'width'    => '100px',
                'type'     => 'action',
                'getter'   => 'getEntityId',
                'actions'  => [
                    [
                        'caption' => __('Edit'),
                        'url'     => [
                            'base'   => 'vindi_payment/subscription/editsubscriptionitem',
                            'params' => ['form_key' => $this->getFormKey()]
                        ],
                        'field'   => 'entity_id',
                    ],
                    [
                        'caption' => __('Delete'),
                        'url'     => [
                            'base'   => 'vindi_payment/subscription/deletesubscriptionitem',
                            'params' => ['form_key' => $this->getFormKey()]
                        ],
                        'confirm' => __('Are you sure you want to delete this item?'),
                        'field'   => 'entity_id',
                    ],
                ],
                'filter'            => false,
                'sortable'          => false,
                'header_css_class'  => 'col-action',
                'column_css_class'  => 'col-action',
            ]
        );

        return parent::_prepareColumns();
    }

    /**
     * Render the Duration column value
     *
     * @param string $value
     * @param \Magento\Framework\DataObject $row
     * @param \Magento\Backend\Block\Widget\Grid\Column $column
     * @param bool $isExport
     * @return string
     */
    public function renderDurationColumn($value, $row, $column, $isExport)
    {
        $cycle = $row->getData('cycles');
        $uses  = $row->getData('uses');

        if (is_null($cycle)) {
            return __('Permanent');
        }

        if (is_null($uses)) {
            return $cycle;
        }

        return __('Temporary (%1/%2)', $uses, $cycle);
    }

    /**
     * Get grid URL
     *
     * @return string
     */
    public function getGridUrl()
    {
        return $this->getUrl('*/subscription/grids', ['_current' => true]);
    }

    /**
     * Get selected subscription item IDs
     *
     * @return array
     */
    protected function _getSelectedSubscriptionItems()
    {
        return array_keys($this->getSelectedSubscriptionItems());
    }

    /**
     * Retrieve selected subscription items
     *
     * @return array
     */
    public function getSelectedSubscriptionItems()
    {
        $id     = $this->getRequest()->getParam('entity_id');
        $model  = $this->subscriptionItemFactory->create()->addFieldToFilter('subscription_id', $id);
        $result = [];

        foreach ($model as $item) {
            $result[$item->getEntityId()] = ['position' => "0"];
        }

        return $result;
    }
}
