<?php

declare(strict_types=1);

namespace Vindi\Payment\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Vindi\Payment\Model\Vindi\Product as VindiProduct;
use Psr\Log\LoggerInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Class MultiPaymentHelper
 * 
 * Helper for managing multi-payment specific operations
 */
class MultiPaymentHelper extends AbstractHelper
{
    /**
     * @var VindiProduct
     */
    private $vindiProduct;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var int|null
     */
    private $discountProductId = null;

    /**
     * @param Context $context
     * @param VindiProduct $vindiProduct
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        VindiProduct $vindiProduct,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->vindiProduct = $vindiProduct;
        $this->logger = $logger;
    }

    /**
     * Get or create the multi-payment discount product in Vindi
     *
     * @return int
     * @throws LocalizedException
     */
    public function getOrCreateDiscountProduct(): int
    {
        // Return cached value if available
        if ($this->discountProductId !== null) {
            return $this->discountProductId;
        }

        $discountProductCode = 'vindi-multipayment-discount';
        
        try {
            // Try to find existing product
            $existingProductId = $this->vindiProduct->findProductByCode($discountProductCode);
            
            if ($existingProductId) {
                $this->discountProductId = (int)$existingProductId;
                $this->logger->info('VINDI_MULTIPAYMENT: Found existing discount product ID: ' . $this->discountProductId);
                return $this->discountProductId;
            }

            // Create new discount product using the existing method
            $this->logger->info('VINDI_MULTIPAYMENT: Creating new discount product...');
            
            $createdProductId = $this->vindiProduct->findOrCreateProduct(
                $discountProductCode,
                'Desconto Multimeios Vindi',
                'simple'
            );
            
            if (!$createdProductId) {
                throw new LocalizedException(__('Failed to create discount product in Vindi'));
            }

            $this->discountProductId = (int)$createdProductId;
            $this->logger->info('VINDI_MULTIPAYMENT: Created discount product with ID: ' . $this->discountProductId);
            
            return $this->discountProductId;

        } catch (\Exception $e) {
            $this->logger->error('VINDI_MULTIPAYMENT: Error creating/finding discount product: ' . $e->getMessage());
            throw new LocalizedException(__('Could not create or find discount product: %1', $e->getMessage()));
        }
    }

    /**
     * Clear cached discount product ID (useful for testing)
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->discountProductId = null;
    }

    /**
     * Validate that discount product exists and is accessible
     *
     * @return bool
     */
    public function validateDiscountProduct(): bool
    {
        try {
            $productId = $this->getOrCreateDiscountProduct();
            return $productId > 0;
        } catch (\Exception $e) {
            $this->logger->error('VINDI_MULTIPAYMENT: Discount product validation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get configuration for discount product creation
     *
     * @return array
     */
    private function getDiscountProductConfig(): array
    {
        return [
            'name' => $this->scopeConfig->getValue(
                'payment/vindi_payment/discount_product_name',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            ) ?: 'Desconto Multimeios Vindi',
            'description' => $this->scopeConfig->getValue(
                'payment/vindi_payment/discount_product_description',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            ) ?: 'Produto interno utilizado para ajustar valores em pagamentos multimeios. Não deve ser vendido diretamente.'
        ];
    }
}
