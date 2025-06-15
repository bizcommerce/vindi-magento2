<?php

declare(strict_types=1);

namespace Vindi\Payment\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Psr\Log\LoggerInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Class ValidationHelper
 * 
 * Helper for additional validations and improvements
 */
class ValidationHelper extends AbstractHelper
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Context $context
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->logger = $logger;
    }

    /**
     * Validate minimum amount for payment methods
     *
     * @param float $amount
     * @param string $paymentMethod
     * @return bool
     * @throws LocalizedException
     */
    public function validateMinimumAmount(float $amount, string $paymentMethod): bool
    {
        $minimumAmounts = [
            'credit_card' => 1.00,
            'pix' => 0.01,
            'bank_slip' => 5.00,
            'multipayment' => 2.00
        ];

        $minimumAmount = $minimumAmounts[$paymentMethod] ?? 1.00;

        if ($amount < $minimumAmount) {
            $this->logger->warning("VINDI_VALIDATION: Amount {$amount} is below minimum {$minimumAmount} for {$paymentMethod}");
            throw new LocalizedException(
                __('Minimum amount for %1 is %2', $paymentMethod, $minimumAmount)
            );
        }

        return true;
    }

    /**
     * Validate credit card data format (basic validation)
     *
     * @param array $cardData
     * @return bool
     * @throws LocalizedException
     */
    public function validateCreditCardData(array $cardData): bool
    {
        $requiredFields = ['card_number', 'card_cvv', 'card_expiration'];
        
        foreach ($requiredFields as $field) {
            if (empty($cardData[$field])) {
                throw new LocalizedException(__('Credit card field %1 is required', $field));
            }
        }

        // Basic card number validation (remove spaces and check if numeric)
        $cardNumber = preg_replace('/\s+/', '', $cardData['card_number']);
        if (!is_numeric($cardNumber) || strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
            throw new LocalizedException(__('Invalid credit card number format'));
        }

        // Basic CVV validation
        $cvv = $cardData['card_cvv'];
        if (!is_numeric($cvv) || strlen($cvv) < 3 || strlen($cvv) > 4) {
            throw new LocalizedException(__('Invalid CVV format'));
        }

        // Basic expiration validation (MM/YY format)
        $expiration = $cardData['card_expiration'];
        if (!preg_match('/^\d{2}\/\d{2}$/', $expiration)) {
            throw new LocalizedException(__('Invalid expiration date format. Use MM/YY'));
        }

        return true;
    }

    /**
     * Validate if values are properly distributed in multi-payment
     *
     * @param float $totalAmount
     * @param array $splitAmounts
     * @return bool
     * @throws LocalizedException
     */
    public function validateMultiPaymentSplit(float $totalAmount, array $splitAmounts): bool
    {
        $sumSplits = array_sum($splitAmounts);
        
        // Allow for small rounding differences (1 cent)
        $difference = abs($totalAmount - $sumSplits);
        
        if ($difference > 0.01) {
            $this->logger->error("VINDI_VALIDATION: Multi-payment split error - Total: {$totalAmount}, Sum: {$sumSplits}, Difference: {$difference}");
            throw new LocalizedException(
                __('Multi-payment amounts do not match order total. Total: %1, Sum: %2', $totalAmount, $sumSplits)
            );
        }

        // Validate each split amount is positive
        foreach ($splitAmounts as $method => $amount) {
            if ($amount <= 0) {
                throw new LocalizedException(
                    __('Invalid amount for payment method %1: %2', $method, $amount)
                );
            }
        }

        return true;
    }

    /**
     * Sanitize log data to remove sensitive information
     *
     * @param array $data
     * @return array
     */
    public function sanitizeLogData(array $data): array
    {
        $sensitiveFields = [
            'card_number',
            'card_cvv', 
            'cvv',
            'card_token',
            'payment_token',
            'customer_email',
            'customer_phone',
            'customer_document'
        ];

        $sanitized = $data;

        foreach ($sensitiveFields as $field) {
            if (isset($sanitized[$field])) {
                $value = (string)$sanitized[$field];
                if (strlen($value) > 4) {
                    $sanitized[$field] = substr($value, 0, 4) . '****' . substr($value, -4);
                } else {
                    $sanitized[$field] = '****';
                }
            }
        }

        return $sanitized;
    }

    /**
     * Validate subscription period compatibility
     *
     * @param array $planIds
     * @return bool
     * @throws LocalizedException
     */
    public function validateSubscriptionPeriods(array $planIds): bool
    {
        if (count($planIds) <= 1) {
            return true; // Single plan or empty is always valid
        }

        // TODO: Implement actual plan period validation by fetching plan data
        // For now, just ensure all plan IDs are the same
        $firstPlanId = reset($planIds);
        foreach ($planIds as $planId) {
            if ($planId !== $firstPlanId) {
                throw new LocalizedException(
                    __('Cannot mix products with different subscription plans in the same order')
                );
            }
        }

        return true;
    }
}
