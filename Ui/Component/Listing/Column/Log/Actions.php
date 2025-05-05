<?php
declare(strict_types=1);

namespace Vindi\Payment\Ui\Component\Listing\Column\Log;

use Magento\Framework\UrlInterface;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;

class Actions extends Column
{
    const URL_PATH_EDIT = 'vindi_payment/logs/edit';
    const URL_PATH_DELETE = 'vindi_payment/logs/delete';

    protected $urlBuilder;

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                $item[$this->getData('name')]['edit'] = [
                    'href' => $this->urlBuilder->getUrl(self::URL_PATH_EDIT, ['entity_id' => $item['entity_id']]),
                    'label' => __('View')
                ];
            }
        }
        return $dataSource;
    }
}
