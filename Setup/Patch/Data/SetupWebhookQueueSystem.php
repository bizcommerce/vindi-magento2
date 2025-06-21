<?php

namespace Vindi\Payment\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Psr\Log\LoggerInterface;

/**
 * Setup Webhook Queue System
 */
class SetupWebhookQueueSystem implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Constructor
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param LoggerInterface $logger
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        LoggerInterface $logger
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->logger = $logger;
    }

    /**
     * Apply patch
     */
    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        try {
            // Log the setup
            $this->logger->info('Vindi Webhook Queue System setup started');

            // Add indexes for performance if not already added by db_schema.xml
            $connection = $this->moduleDataSetup->getConnection();
            $tableName = $this->moduleDataSetup->getTable('vindi_webhook_queue');

            // Check if table exists
            if ($connection->isTableExists($tableName)) {
                $this->logger->info('Webhook queue table already exists - setup completed');
            } else {
                $this->logger->info('Webhook queue table will be created by db_schema.xml');
            }

            $this->logger->info('Vindi Webhook Queue System setup completed successfully');

        } catch (\Exception $e) {
            $this->logger->error('Error during Webhook Queue System setup: ' . $e->getMessage());
            throw $e;
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * Get dependencies
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * Get aliases
     */
    public function getAliases()
    {
        return [];
    }
}
