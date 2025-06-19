<?php

namespace Vindi\Payment\Model\Payment;

use Vindi\Payment\Helper\Api;

/**
 * Class Bill
 * @package Vindi\Payment\Model\Payment
 */
class Bill
{

    const PAID_STATUS = 'paid';
    const REVIEW_STATUS = 'review';
    const FRAUD_REVIEW_STATUS = 'fraud_review';
    const WAITING_STATUS = 'waiting';
    const CANCELED_STATUS = 'canceled';
    const PENDING_STATUS = 'pending';

    /**
     * @var Api
     */
    private $api;

    /**
     * @param Api $api
     */
    public function __construct(Api $api)
    {
        $this->api = $api;
    }

    /**
     * @param array $body
     *
     * @return int|bool
     */
    public function create($body)
    {
        if ($response = $this->api->request('bills', 'POST', $body)) {
            return $response['bill'];
        }

        return false;
    }

    /**
     * @param $billId
     */
    public function delete($billId)
    {
        $this->api->request("bills/{$billId}", 'DELETE');
    }

    /**
     * @param $billId
     *
     * @return array|bool
     */
    public function getBill($billId)
    {
        $response = $this->api->request("bills/{$billId}", 'GET');

        if (! $response || ! isset($response['bill'])) {
            return false;
        }

        return $response['bill'];
    }

    /**
     * Cancel a bill using DELETE API
     *
     * @param int $billId
     * @return bool
     */
    public function cancel($billId)
    {
        try {
            $this->api->request("bills/{$billId}", 'DELETE');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if bill can be canceled (not paid yet)
     *
     * @param int $billId
     * @return bool
     */
    public function canCancel($billId)
    {
        $bill = $this->getBill($billId);
        
        if (!$bill) {
            return false;
        }
        
        $status = $bill['status'] ?? '';
        
        // Bill pode ser cancelada se não estiver paga nem já cancelada
        $cancelableStatuses = [
            self::PENDING_STATUS,
            self::WAITING_STATUS,
            self::REVIEW_STATUS,
            self::FRAUD_REVIEW_STATUS
        ];
        
        return in_array($status, $cancelableStatuses);
    }
}
