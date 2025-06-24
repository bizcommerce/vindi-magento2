<?php

namespace Vindi\Payment\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vindi\Payment\Helper\InvoiceBillHelper;
use Magento\Framework\App\ResourceConnection;

/**
 * Command para testar a busca de invoices por bill ID
 */
class TestInvoiceBillSearchCommand extends Command
{
    private $invoiceBillHelper;
    private $resourceConnection;

    public function __construct(
        InvoiceBillHelper $invoiceBillHelper,
        ResourceConnection $resourceConnection
    ) {
        $this->invoiceBillHelper = $invoiceBillHelper;
        $this->resourceConnection = $resourceConnection;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('vindi:test:invoice-bill-search')
            ->setDescription('Test invoice search by bill ID')
            ->addArgument('bill_id', InputArgument::REQUIRED, 'Bill ID to search for')
            ->addOption('show-all-bills', null, InputOption::VALUE_NONE, 'Show all bill IDs in database');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $billId = $input->getArgument('bill_id');
        $showAll = $input->getOption('show-all-bills');

        if ($showAll) {
            $this->showAllBillIds($output);
        }

        $output->writeln("Searching for invoices with bill ID: $billId");
        
        $this->testDirectDatabaseQuery($billId, $output);
        
        $this->testHelperMethod($billId, $output);

        return 0;
    }

    private function showAllBillIds($output)
    {
        $output->writeln("All bill IDs in database:");
        
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('sales_invoice');
        
        $select = $connection->select()
            ->from($tableName, ['entity_id', 'increment_id', 'vindi_bill_id'])
            ->where('vindi_bill_id IS NOT NULL')
            ->order('entity_id DESC')
            ->limit(20);
        
        $results = $connection->fetchAll($select);
        
        if (empty($results)) {
            $output->writeln("  No invoices with bill IDs found");
        } else {
            foreach ($results as $row) {
                $output->writeln("  Invoice #{$row['increment_id']} (ID: {$row['entity_id']}) - Bill ID: {$row['vindi_bill_id']}");
            }
        }
        
        $output->writeln("");
    }

    private function testDirectDatabaseQuery($billId, $output)
    {
        $output->writeln("Testing direct database query:");
        
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('sales_invoice');
        
        $select = $connection->select()
            ->from($tableName, ['entity_id', 'increment_id', 'vindi_bill_id'])
            ->where('vindi_bill_id = ?', $billId);
        
        $results = $connection->fetchAll($select);
        
        if (empty($results)) {
            $output->writeln("  No results found with exact match");
            
            $selectSimilar = $connection->select()
                ->from($tableName, ['entity_id', 'increment_id', 'vindi_bill_id'])
                ->where('vindi_bill_id LIKE ?', "%$billId%");
            
            $similarResults = $connection->fetchAll($selectSimilar);
            
            if (!empty($similarResults)) {
                $output->writeln("  Similar values found:");
                foreach ($similarResults as $row) {
                    $output->writeln("    Invoice #{$row['increment_id']} - Bill ID: '{$row['vindi_bill_id']}'");
                }
            }
        } else {
            foreach ($results as $row) {
                $output->writeln("  Found: Invoice #{$row['increment_id']} (ID: {$row['entity_id']}) - Bill ID: {$row['vindi_bill_id']}");
            }
        }
        
        $output->writeln("");
    }

    private function testHelperMethod($billId, $output)
    {
        $output->writeln("Testing helper method:");
        
        try {
            $invoices = $this->invoiceBillHelper->getInvoicesByVindiBillId($billId);
            
            if (empty($invoices)) {
                $output->writeln("  No invoices found using helper method");
            } else {
                foreach ($invoices as $invoice) {
                    $output->writeln("  Found: Invoice #{$invoice->getIncrementId()} (ID: {$invoice->getEntityId()})");
                    
                    $retrievedBillId = $this->invoiceBillHelper->getVindiBillIdFromInvoice($invoice);
                    $output->writeln("    Retrieved Bill ID: $retrievedBillId");
                }
            }
        } catch (\Exception $e) {
            $output->writeln("  Error: " . $e->getMessage());
        }
    }
}
