<?php
// File: app/code/Vindi/Payment/Model/Payment/Charge.php

namespace Vindi\Payment\Model\Payment;

use Vindi\Payment\Helper\Api;

/**
 * Class Charge
 * Represents operations on Vindi Charges
 */
class Charge
{
    /**
     * Possible statuses for a Charge
     */
    const PENDING_STATUS     = 'pending';
    const PAID_STATUS        = 'paid';
    const REFUNDED_STATUS    = 'refunded';
    const CONTESTED_STATUS   = 'contested';
    const REFUSED_STATUS     = 'refused';
    const CHARGEDBACK_STATUS = 'chargedback';

    /**
     * @var Api
     */
    private $api;

    /**
     * Charge constructor
     *
     * @param Api $api
     */
    public function __construct(Api $api)
    {
        $this->api = $api;
    }

    /**
     * Create a new Charge
     *
     * @param array $body
     * @return array|bool
     */
    public function create(array $body)
    {
        $response = $this->api->request('charges', 'POST', $body);
        if ($response && isset($response['charge'])) {
            return $response['charge'];
        }
        return false;
    }

    /**
     * Issue a refund for a specific Charge by ID
     *
     * @param int|string $chargeId
     * @param array $body
     * @return array|bool
     */
    public function refund($chargeId, array $body = [])
    {
        $endpoint = "charges/{$chargeId}/refund";
        $response = $this->api->request($endpoint, 'POST', $body);

        if ($response && isset($response['charge'])) {
            return $response['charge'];
        }
        return false;
    }

    /**
     * Retrieve a single Charge by ID
     *
     * @param int|string $chargeId
     * @return array|bool
     */
    public function getCharge($chargeId)
    {
        $response = $this->api->request("charges/{$chargeId}", 'GET');
        if ($response && isset($response['charge'])) {
            return $response['charge'];
        }
        return false;
    }

    /**
     * Update a Charge by ID
     *
     * @param int|string $chargeId
     * @param array $body
     * @return array|bool
     */
    public function update($chargeId, array $body)
    {
        $response = $this->api->request("charges/{$chargeId}", 'PUT', $body);
        if ($response && isset($response['charge'])) {
            return $response['charge'];
        }
        return false;
    }

    /**
     * Delete a Charge by ID
     *
     * @param int|string $chargeId
     * @return void
     */
    public function delete($chargeId)
    {
        $this->api->request("charges/{$chargeId}", 'DELETE');
    }

    /**
     * List all Charges (optional params can be used for filtering)
     *
     * @param array $params
     * @return array|bool
     */
    public function getList(array $params = [])
    {
        $queryString = http_build_query($params);
        $endpoint = 'charges';
        if ($queryString) {
            $endpoint .= '?' . $queryString;
        }
        $response = $this->api->request($endpoint, 'GET');
        if ($response && isset($response['charges'])) {
            return $response['charges'];
        }
        return false;
    }
}
