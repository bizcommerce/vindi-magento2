<?php

declare(strict_types=1);

namespace Vindi\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Checkout\Model\Cart;
use Vindi\Payment\Helper\Data as VindiHelper;
use Psr\Log\LoggerInterface;

/**
 * Class ValidateCartMixObserver
 * 
 * Prevents mixing subscription products with regular products in the same cart
 */
class ValidateCartMixObserver implements ObserverInterface
{
    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var Cart
     */
    private $cart;

    /**
     * @var VindiHelper
     */
    private $vindiHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ManagerInterface $messageManager
     * @param Cart $cart
     * @param VindiHelper $vindiHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        ManagerInterface $messageManager,
        Cart $cart,
        VindiHelper $vindiHelper,
        LoggerInterface $logger
    ) {
        $this->messageManager = $messageManager;
        $this->cart = $cart;
        $this->vindiHelper = $vindiHelper;
        $this->logger = $logger;
    }

    /**
     * Validate cart mixing before adding product
     *
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        try {
            $product = $observer->getEvent()->getProduct();
            $request = $observer->getEvent()->getRequest();
            
            // Check if product being added is a subscription product
            $isNewProductSubscription = $this->isSubscriptionProduct($product, $request);
            
            // Get current cart items
            $quote = $this->cart->getQuote();
            $cartItems = $quote->getAllVisibleItems();
            
            // If cart is empty, allow any product
            if (empty($cartItems)) {
                $this->logger->info('VINDI_CART_VALIDATION: Empty cart, allowing product: ' . $product->getSku());
                return;
            }
            
            // Check existing cart items
            $hasSubscriptionItems = false;
            $hasRegularItems = false;
            
            foreach ($cartItems as $item) {
                if ($this->isSubscriptionItem($item)) {
                    $hasSubscriptionItems = true;
                    $this->logger->info('VINDI_CART_VALIDATION: Found subscription item in cart: ' . $item->getSku());
                } else {
                    $hasRegularItems = true;
                    $this->logger->info('VINDI_CART_VALIDATION: Found regular item in cart: ' . $item->getSku());
                }
            }
            
            // Validate mixing rules
            if ($isNewProductSubscription && $hasRegularItems) {
                $this->logger->warning('VINDI_CART_VALIDATION: Blocking subscription product addition to cart with regular items');
                $this->messageManager->addErrorMessage(
                    __('Não é possível adicionar produtos de assinatura ao carrinho que já contém produtos avulsos. Por favor, finalize a compra atual ou remova os produtos avulsos do carrinho.')
                );
                throw new LocalizedException(
                    __('Cannot add subscription products to cart containing regular products.')
                );
            }
            
            if (!$isNewProductSubscription && $hasSubscriptionItems) {
                $this->logger->warning('VINDI_CART_VALIDATION: Blocking regular product addition to cart with subscription items');
                $this->messageManager->addErrorMessage(
                    __('Não é possível adicionar produtos avulsos ao carrinho que já contém produtos de assinatura. Por favor, finalize a compra atual ou remova os produtos de assinatura do carrinho.')
                );
                throw new LocalizedException(
                    __('Cannot add regular products to cart containing subscription products.')
                );
            }
            
            // Also validate different subscription periods
            if ($isNewProductSubscription && $hasSubscriptionItems) {
                $this->validateSubscriptionPeriods($product, $request, $cartItems);
            }
            
            $this->logger->info('VINDI_CART_VALIDATION: Product validation passed for: ' . $product->getSku());
            
        } catch (LocalizedException $e) {
            // Re-throw LocalizedException to maintain error handling
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('VINDI_CART_VALIDATION: Unexpected error during cart validation: ' . $e->getMessage());
            // Don't block the process for unexpected errors, just log them
        }
    }

    /**
     * Check if product being added is a subscription product
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param \Magento\Framework\DataObject $request
     * @return bool
     */
    private function isSubscriptionProduct($product, $request): bool
    {
        // Check if selected_plan_id is provided in request
        $selectedPlanId = $request->getData('selected_plan_id');
        if (!empty($selectedPlanId)) {
            return true;
        }
        
        // Additional checks can be added here if needed
        // For example, checking product attributes, categories, etc.
        
        return false;
    }

    /**
     * Check if cart item is a subscription item
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return bool
     */
    private function isSubscriptionItem($item): bool
    {
        try {
            $options = $item->getProductOptions();
            if (!empty($options['info_buyRequest']['selected_plan_id'])) {
                return true;
            }
        } catch (\Exception $e) {
            $this->logger->error('VINDI_CART_VALIDATION: Error checking subscription item: ' . $e->getMessage());
        }
        
        return false;
    }

    /**
     * Validate that subscription products have compatible periods
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param \Magento\Framework\DataObject $request
     * @param array $cartItems
     * @throws LocalizedException
     */
    private function validateSubscriptionPeriods($product, $request, $cartItems)
    {
        $newPlanId = $request->getData('selected_plan_id');
        
        foreach ($cartItems as $item) {
            if ($this->isSubscriptionItem($item)) {
                $options = $item->getProductOptions();
                $existingPlanId = $options['info_buyRequest']['selected_plan_id'] ?? null;
                
                if ($existingPlanId && $existingPlanId !== $newPlanId) {
                    $this->logger->warning("VINDI_CART_VALIDATION: Different plan IDs detected - New: {$newPlanId}, Existing: {$existingPlanId}");
                    $this->messageManager->addErrorMessage(
                        __('Não é possível adicionar produtos de assinatura com periodicidades diferentes no mesmo carrinho. Por favor, finalize a compra atual ou remova os outros produtos de assinatura.')
                    );
                    throw new LocalizedException(
                        __('Cannot add subscription products with different periods to the same cart.')
                    );
                }
            }
        }
    }
}
