<?php

namespace Vindi\Payment\Helper;

use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;

/**
 * Helper class para trabalhar com Vindi Bill IDs nas invoices
 */
class InvoiceBillHelper
{
    private $invoiceRepository;
    private $searchCriteriaBuilder;
    private $resourceConnection;

    public function __construct(
        InvoiceRepositoryInterface $invoiceRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ResourceConnection $resourceConnection
    ) {
        $this->invoiceRepository = $invoiceRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Busca invoices por Vindi Bill ID
     *
     * @param string $vindiBillId
     * @return \Magento\Sales\Api\Data\InvoiceInterface[]
     */
    public function getInvoicesByVindiBillId($vindiBillId)
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('sales_invoice');
        
        // Busca entity_ids das invoices que têm o vindi_bill_id
        $select = $connection->select()
            ->from($tableName, ['entity_id'])
            ->where('vindi_bill_id = ?', $vindiBillId);
        
        $entityIds = $connection->fetchCol($select);
        
        if (empty($entityIds)) {
            return [];
        }

        // Busca as invoices pelos entity_ids encontrados
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', $entityIds, 'in')
            ->create();

        $searchResult = $this->invoiceRepository->getList($searchCriteria);
        return $searchResult->getItems();
    }

    /**
     * Obtém o Vindi Bill ID de uma invoice
     *
     * @param \Magento\Sales\Api\Data\InvoiceInterface $invoice
     * @return string|null
     */
    public function getVindiBillIdFromInvoice($invoice)
    {
        $extensionAttributes = $invoice->getExtensionAttributes();
        if ($extensionAttributes && $extensionAttributes->getVindiBillId()) {
            return $extensionAttributes->getVindiBillId();
        }

        // Fallback: busca diretamente no banco se não estiver carregado
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('sales_invoice');
        
        $select = $connection->select()
            ->from($tableName, ['vindi_bill_id'])
            ->where('entity_id = ?', $invoice->getEntityId());
        
        return $connection->fetchOne($select) ?: null;
    }

    /**
     * Verifica se uma invoice foi criada por um determinado bill ID
     *
     * @param \Magento\Sales\Api\Data\InvoiceInterface $invoice
     * @param string $vindiBillId
     * @return bool
     */
    public function isInvoiceFromBill($invoice, $vindiBillId)
    {
        return $this->getVindiBillIdFromInvoice($invoice) === $vindiBillId;
    }

    /**
     * Lista todas as invoices com Vindi Bill ID para um pedido específico
     *
     * @param int $orderId
     * @return array Array com o formato: ['invoice_id' => 'vindi_bill_id', ...]
     */
    public function getVindiBillIdsForOrder($orderId)
    {
        $connection = $this->resourceConnection->getConnection();
        $invoiceTable = $this->resourceConnection->getTableName('sales_invoice');
        
        $select = $connection->select()
            ->from($invoiceTable, ['entity_id', 'vindi_bill_id'])
            ->where('order_id = ?', $orderId)
            ->where('vindi_bill_id IS NOT NULL');
        
        $result = $connection->fetchPairs($select);
        return $result ?: [];
    }
}
