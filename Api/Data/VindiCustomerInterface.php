<?php
namespace Vindi\Payment\Api\Data;

/**
 * Interface VindiCustomerInterface
 *
 * Provides getters and setters for Vindi Customer entity.
 */
interface VindiCustomerInterface
{
    /**#@+
     * Constants defined for keys of data array
     */
    const ENTITY_ID = 'entity_id';
    const MAGENTO_CUSTOMER_ID = 'magento_customer_id';
    const VINDI_CUSTOMER_ID = 'vindi_customer_id';
    const CODE = 'code';
    const ENVIRONMENT = 'environment';
    /**#@-*/

    /**
     * Get entity ID
     *
     * @return int|null
     */
    public function getEntityId();

    /**
     * Get Magento Customer ID
     *
     * @return int
     */
    public function getMagentoCustomerId();

    /**
     * Get Vindi Customer ID
     *
     * @return string
     */
    public function getVindiCustomerId();

    /**
     * Get code
     *
     * @return string
     */
    public function getCode();

    /**
     * Get environment
     *
     * @return string
     */
    public function getEnvironment();

    /**
     * Set entity ID
     *
     * @param int $entityId
     * @return $this
     */
    public function setEntityId($entityId);

    /**
     * Set Magento Customer ID
     *
     * @param int $customerId
     * @return $this
     */
    public function setMagentoCustomerId($customerId);

    /**
     * Set Vindi Customer ID
     *
     * @param string $vindiCustomerId
     * @return $this
     */
    public function setVindiCustomerId($vindiCustomerId);

    /**
     * Set code
     *
     * @param string $code
     * @return $this
     */
    public function setCode($code);

    /**
     * Set environment
     *
     * @param string $environment
     * @return $this
     */
    public function setEnvironment($environment);
}
