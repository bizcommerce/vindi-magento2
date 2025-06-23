<?php

namespace Vindi\Payment\Plugin;

use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\InvoiceExtensionFactory;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Plugin for handling vindi_bill_id extension attribute on invoices
 */
class InvoiceRepositoryPlugin
{
    private $invoiceExtensionFactory;
    private $resourceConnection;

    public function __construct(
        InvoiceExtensionFactory $invoiceExtensionFactory,
        ResourceConnection $resourceConnection
    ) {
        $this->invoiceExtensionFactory = $invoiceExtensionFactory;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Load vindi_bill_id from database and set it as extension attribute
     *
     * @param InvoiceRepositoryInterface $subject
     * @param InvoiceInterface $invoice
     * @return InvoiceInterface
     */
    public function afterGet(InvoiceRepositoryInterface $subject, InvoiceInterface $invoice)
    {
        $this->loadVindiBillId($invoice);
        return $invoice;
    }

    /**
     * Load vindi_bill_id for multiple invoices
     *
     * @param InvoiceRepositoryInterface $subject
     * @param \Magento\Sales\Api\Data\InvoiceSearchResultInterface $searchResult
     * @return \Magento\Sales\Api\Data\InvoiceSearchResultInterface
     */
    public function afterGetList(InvoiceRepositoryInterface $subject, $searchResult)
    {
        foreach ($searchResult->getItems() as $invoice) {
            $this->loadVindiBillId($invoice);
        }
        return $searchResult;
    }

    /**
     * Save vindi_bill_id to database
     *
     * @param InvoiceRepositoryInterface $subject
     * @param InvoiceInterface $invoice
     * @return InvoiceInterface
     */
    public function beforeSave(InvoiceRepositoryInterface $subject, InvoiceInterface $invoice)
    {
        $extensionAttributes = $invoice->getExtensionAttributes();
        if ($extensionAttributes && $extensionAttributes->getVindiBillId()) {
            $this->saveVindiBillId($invoice, $extensionAttributes->getVindiBillId());
        }
        return $invoice;
    }

    /**
     * Load vindi_bill_id from database and set as extension attribute
     *
     * @param InvoiceInterface $invoice
     */
    private function loadVindiBillId(InvoiceInterface $invoice)
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('sales_invoice');
        
        $select = $connection->select()
            ->from($tableName, ['vindi_bill_id'])
            ->where('entity_id = ?', $invoice->getEntityId());
        
        $vindiBillId = $connection->fetchOne($select);
        
        if ($vindiBillId) {
            $extensionAttributes = $invoice->getExtensionAttributes();
            if (!$extensionAttributes) {
                $extensionAttributes = $this->invoiceExtensionFactory->create();
            }
            $extensionAttributes->setVindiBillId($vindiBillId);
            $invoice->setExtensionAttributes($extensionAttributes);
        }
    }

    /**
     * Save vindi_bill_id to database
     *
     * @param InvoiceInterface $invoice
     * @param string $vindiBillId
     */
    private function saveVindiBillId(InvoiceInterface $invoice, $vindiBillId)
    {
        if ($invoice->getEntityId()) {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('sales_invoice');
            
            $connection->update(
                $tableName,
                ['vindi_bill_id' => $vindiBillId],
                ['entity_id = ?' => $invoice->getEntityId()]
            );
        }
    }
}
