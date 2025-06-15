<?php
/**
 * Copyright © Vindi. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Vindi\Payment\Setup\Patch\Schema;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Class FixDuplicateIndexes
 * Fix duplicate indexes issue in vindi_payment_split table
 */
class FixDuplicateIndexes implements SchemaPatchInterface
{
    /**
     * @var SchemaSetupInterface
     */
    private $schemaSetup;

    /**
     * @param SchemaSetupInterface $schemaSetup
     */
    public function __construct(SchemaSetupInterface $schemaSetup)
    {
        $this->schemaSetup = $schemaSetup;
    }

    /**
     * {@inheritdoc}
     */
    public function apply()
    {
        $this->schemaSetup->startSetup();

        $connection = $this->schemaSetup->getConnection();
        $tableName = $this->schemaSetup->getTable('vindi_payment_split');

        // Remove duplicate indexes if they exist
        $indexes = [
            'VINDI_PAYMENT_SPLIT_SUBSCRIPTION_ID_CYCLE',
            'VINDI_PAYMENT_SPLIT_SUBSCRIPTION_CYCLE',
            'VINDI_PAYMENT_SPLIT_BILL_ID'
        ];

        foreach ($indexes as $indexName) {
            try {
                if ($connection->isTableExists($tableName)) {
                    $connection->dropIndex($tableName, $indexName);
                }
            } catch (\Exception $e) {
                // Index doesn't exist, continue
            }
        }

        $this->schemaSetup->endSetup();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
}
