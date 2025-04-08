<?php
namespace Vindi\Payment\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\OptionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;

class AddAdminCustomOptionToQuoteItem implements ObserverInterface
{
    /**
     * @var \Magento\Backend\Model\Session\Quote
     */
    protected $sessionQuote;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var OptionFactory
     */
    protected $optionFactory;

    /**
     * @param \Magento\Backend\Model\Session\Quote $sessionQuote
     * @param ProductRepositoryInterface $productRepository
     * @param OptionFactory $optionFactory
     */
    public function __construct(
        \Magento\Backend\Model\Session\Quote $sessionQuote,
        ProductRepositoryInterface $productRepository,
        OptionFactory $optionFactory
    ) {
        $this->sessionQuote = $sessionQuote;
        $this->productRepository = $productRepository;
        $this->optionFactory = $optionFactory;
    }

    /**
     * Execute observer to add custom options from admin session to quote item.
     *
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        /** @var Item $quoteItem */
        $quoteItem = $observer->getEvent()->getQuoteItem();
        $productId = $quoteItem->getProduct()->getId();
        $product = $this->productRepository->getById($productId, false, $quoteItem->getStoreId());

        if ($product->getData('vindi_enable_recurrence') == '1') {
            $selectedPlanId = $this->sessionQuote->getData('selected_plan_id');
            if (empty($selectedPlanId)) {
                throw new LocalizedException(__('A plan must be selected for this product.'));
            }

            $buyRequestData = [
                'selected_plan_id' => $selectedPlanId,
                'selected_plan_price' => $this->sessionQuote->getData('selected_plan_price'),
                'selected_plan_installments' => $this->sessionQuote->getData('selected_plan_installments')
            ];

            $option = $this->optionFactory->create();
            $option->setProductId($productId)
                ->setCode('info_buyRequest')
                ->setValue(json_encode($buyRequestData));
            $quoteItem->addOption($option);

            $additionalOptions = [];
            $additionalOptions[] = [
                'label' => __('Plan ID'),
                'value' => $selectedPlanId,
                'code'  => 'plan_id'
            ];
            $additionalOptions[] = [
                'label' => __('Price'),
                'value' => $this->sessionQuote->getData('selected_plan_price'),
                'code'  => 'plan_price'
            ];
            if ($this->sessionQuote->getData('selected_plan_installments') > 0) {
                $additionalOptions[] = [
                    'label' => __('Installments'),
                    'value' => $this->sessionQuote->getData('selected_plan_installments'),
                    'code'  => 'plan_installments'
                ];
            }
            if (!empty($additionalOptions)) {
                $product->addCustomOption('additional_options', json_encode($additionalOptions));
            }
            $this->sessionQuote->unsData('selected_plan_id');
            $this->sessionQuote->unsData('selected_plan_price');
            $this->sessionQuote->unsData('selected_plan_installments');
            $quoteItem->getQuote()->save();
        }
    }
}
