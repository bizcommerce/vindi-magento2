<?php
namespace Vindi\Payment\Plugin;

use Magento\Sales\Model\AdminOrder\Create;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;

/**
 * Class AdminPreventAddProduct
 * Prevents adding products inconsistent with subscription rules
 * in the admin order create flow.
 *
 * Rules:
 * - If cart has a subscription product, no other products can be added.
 * - Only one subscription product allowed per order.
 * - Cannot add a subscription if any non-subscription products already exist.
 *
 * @package Vindi\Payment\Plugin
 */
class AdminPreventAddProduct
{
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * AdminPreventAddProduct constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param ManagerInterface           $messageManager
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        ManagerInterface $messageManager
    ) {
        $this->productRepository = $productRepository;
        $this->messageManager    = $messageManager;
    }

    /**
     * Around plugin for addProducts in admin order create.
     *
     * @param Create   $subject
     * @param \Closure $proceed
     * @param array    $productsInfo
     * @return mixed
     * @throws LocalizedException
     */
    public function aroundAddProducts(
        Create $subject,
        \Closure $proceed,
        array $productsInfo
    ) {
        $quote = $subject->getQuote();
        $existingItems = $quote->getAllItems();

        $hasSubscription = false;
        foreach ($existingItems as $item) {
            $product = $this->productRepository->getById($item->getProduct()->getId());
            $existingAttr = $product->getData('vindi_enable_recurrence');
            if ($existingAttr && $existingAttr == '1') {
                $hasSubscription = true;
                break;
            }
        }

        $incomingSubscriptionCount = 0;
        foreach ($productsInfo as $productId => $info) {
            $product = $this->productRepository->getById($productId);
            $attr = $product->getCustomAttribute('vindi_enable_recurrence');
            $isRecurrent = $attr && $attr->getValue() == '1';

            if ($isRecurrent) {
                $incomingSubscriptionCount++;
                if (isset($info['qty']) && $info['qty'] > 1) {
                    throw new LocalizedException(
                        __('You can only add one unit of a subscription product per order. Please adjust the quantity.')
                    );
                }
            }
        }

        if ($hasSubscription && count($productsInfo) > 0) {
            throw new LocalizedException(
                __('You cannot add additional products when a subscription product already exists in the order. Remove it first.')
            );
        }

        if (!$hasSubscription && count($existingItems) > 0 && $incomingSubscriptionCount > 0) {
            throw new LocalizedException(
                __('You cannot add a subscription product when non-subscription products exist in the order. Please remove them first.')
            );
        }

        if ($incomingSubscriptionCount > 1) {
            throw new LocalizedException(
                __('You can only add one subscription product per order. Please add them one at a time.')
            );
        }

        return $proceed($productsInfo);
    }
}
