<?php
namespace Vindi\Payment\Model\Vindi;

use Magento\Sales\Model\Order;
use Vindi\Payment\Api\ProductManagementInterface;
use Vindi\Payment\Api\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterface as MagentoProductInterface;

/**
 * Class ProductManagement
 * @package Vindi\Payment\Model\Vindi
 */
class ProductManagement implements ProductManagementInterface
{
    /**
     * @var ProductInterface
     */
    private $productRepository;

    /**
     * ProductManagement constructor.
     *
     * @param ProductInterface $productRepository
     */
    public function __construct(
        ProductInterface $productRepository
    ) {
        $this->productRepository = $productRepository;
    }

    /**
     * Find or create a product for subscription.
     *
     * @param Order $order
     * @return array
     */
    public function findOrCreateProductsToSubscription(Order $order)
    {
        $list = [];
        $discounts = [];
        $items = $this->findOrCreateProductsFromOrder($order);
        foreach ($items as $item) {
            if ($item['amount'] < 0) {
                array_push($discounts, [
                    'discount_type' => 'amount',
                    'amount' => $item['amount'] * -1
                ]);
                continue;
            }
            array_push($list, [
                'product_id' => $item['product_id'],
                'quantity' => 1,
                'pricing_schema' => [
                    'price' => $item['amount']
                ]
            ]);
        }
        if (!empty($discounts)) {
            foreach ($discounts as $discount) {
                $list[0]['discounts'][] = $discount;
            }
        }
        return $list;
    }

    /**
     * Find or create products from order.
     *
     * @param Order $order
     * @return array
     */
    public function findOrCreateProductsFromOrder(Order $order)
    {
        $list = [];
        foreach ($order->getItems() as $item) {
            $productType = $item->getProduct()->getTypeId();
            $vindiProductId = $this->productRepository->findOrCreateProduct($item->getSku(), $item->getName(), $productType);
            for ($i = 0; $i < $item->getQtyOrdered(); $i++) {
                $itemPrice = $this->getItemPrice($item, $productType);
                if (!$itemPrice) {
                    continue;
                }
                array_push($list, [
                    'product_id' => $vindiProductId,
                    'amount' => $itemPrice
                ]);
            }
        }
        $list = $this->buildTax($list, $order);
        $list = $this->buildDiscount($list, $order);
        $list = $this->buildShipping($list, $order);
        return $list;
    }

    /**
     * Get item price.
     *
     * @param mixed $item
     * @param string $productType
     * @return float|int
     */
    private function getItemPrice($item, $productType)
    {
        if ('bundle' == $productType) {
            return 0;
        }
        return $item->getPrice();
    }

    /**
     * Build tax item.
     *
     * @param array $list
     * @param Order $order
     * @return array
     */
    private function buildTax(array $list, Order $order)
    {
        if ($order->getTaxAmount() > 0) {
            $productId = $this->productRepository->findOrCreateProduct('taxa', 'Taxa');
            array_push($list, [
                'product_id' => $productId,
                'amount' => $order->getTaxAmount()
            ]);
        }
        return $list;
    }

    /**
     * Build discount item.
     *
     * @param array $list
     * @param Order $order
     * @return array
     */
    private function buildDiscount(array $list, Order $order)
    {
        if ($order->getDiscountAmount() < 0) {
            $productId = $this->productRepository->findOrCreateProduct('cupom', 'Cupom de Desconto');
            array_push($list, [
                'product_id' => $productId,
                'amount' => $order->getDiscountAmount()
            ]);
        }
        return $list;
    }

    /**
     * Build shipping item.
     *
     * @param array $list
     * @param Order $order
     * @return array
     */
    private function buildShipping(array $list, Order $order)
    {
        if ($order->getShippingAmount() > 0) {
            $productId = $this->productRepository->findOrCreateProduct('frete', 'frete');
            array_push($list, [
                'product_id' => $productId,
                'amount' => $order->getShippingAmount()
            ]);
        }
        return $list;
    }

    /**
     * Find or create a product directly in Vindi Payments.
     *
     * @param MagentoProductInterface $product
     * @return int Vindi Product ID
     */
    public function findOrCreate(MagentoProductInterface $product)
    {
        $sku = $product->getSku();
        $name = $product->getName();
        $type = $product->getTypeId();
        return $this->productRepository->findOrCreateProduct($sku, $name, $type);
    }

    /**
     * Find or create a product using the product repository.
     *
     * @param string $itemSku
     * @param string $itemName
     * @param string $itemType
     * @return int|false
     */
    public function findOrCreateProduct($itemSku, $itemName, $itemType = 'simple')
    {
        return $this->productRepository->findOrCreateProduct($itemSku, $itemName, $itemType);
    }
}
