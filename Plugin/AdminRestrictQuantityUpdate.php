<?php
// File: app/code/Vindi/Payment/Plugin/AdminRestrictQuantityUpdate.php
namespace Vindi\Payment\Plugin;

use Magento\Sales\Model\AdminOrder\Create;
use Magento\Framework\Exception\LocalizedException;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Class AdminRestrictQuantityUpdate
 * Restricts quantity updates for subscription products in admin order create.
 *
 * Rules:
 * - Subscription products must always have quantity = 1.
 */
class AdminRestrictQuantityUpdate
{
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * AdminRestrictQuantityUpdate constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        ProductRepositoryInterface $productRepository
    ) {
        $this->productRepository = $productRepository;
    }

    /**
     * Around plugin for updateQuoteItems in admin order create.
     *
     * @param Create   $subject
     * @param \Closure $proceed
     * @param array    $data
     * @return mixed
     * @throws LocalizedException
     */
    public function aroundUpdateQuoteItems(
        Create $subject,
        \Closure $proceed,
        array $data
    ) {
        $quote = $subject->getQuote();

        foreach ($data as $itemId => $itemData) {
            if (!isset($itemData['qty'])) {
                continue;
            }
            $qty = $itemData['qty'];
            $item = $quote->getItemById($itemId);
            if (!$item) {
                continue;
            }
            $product = $this->productRepository->getById($item->getProduct()->getId());
            $attr = $product->getCustomAttribute('vindi_enable_recurrence');
            $isRecurrent = $attr && $attr->getValue() == '1';

            if ($isRecurrent && $qty > 1) {
                throw new LocalizedException(
                    __('You can only set quantity to one for a subscription product in admin order.')
                );
            }
        }

        return $proceed($data);
    }
}
