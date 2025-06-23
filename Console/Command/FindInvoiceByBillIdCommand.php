<?php

namespace Vindi\Payment\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Vindi\Payment\Helper\InvoiceBillHelper;

/**
 * Console command para demonstrar como buscar invoices por Vindi Bill ID
 */
class FindInvoiceByBillIdCommand extends Command
{
    const BILL_ID_ARGUMENT = 'bill_id';

    private $invoiceBillHelper;

    public function __construct(
        InvoiceBillHelper $invoiceBillHelper,
        $name = null
    ) {
        $this->invoiceBillHelper = $invoiceBillHelper;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('vindi:invoice:find-by-bill-id')
            ->setDescription('Busca invoices por Vindi Bill ID')
            ->addArgument(
                self::BILL_ID_ARGUMENT,
                InputArgument::REQUIRED,
                'Vindi Bill ID'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vindiBillId = $input->getArgument(self::BILL_ID_ARGUMENT);
        
        $output->writeln('<info>Buscando invoices para Vindi Bill ID: ' . $vindiBillId . '</info>');
        
        $invoices = $this->invoiceBillHelper->getInvoicesByVindiBillId($vindiBillId);
        
        if (empty($invoices)) {
            $output->writeln('<comment>Nenhuma invoice encontrada para o Bill ID informado.</comment>');
            return 0;
        }
        
        $output->writeln('<info>Encontradas ' . count($invoices) . ' invoice(s):</info>');
        
        foreach ($invoices as $invoice) {
            $output->writeln('');
            $output->writeln('Invoice ID: ' . $invoice->getEntityId());
            $output->writeln('Invoice Increment ID: ' . $invoice->getIncrementId());
            $output->writeln('Order ID: ' . $invoice->getOrderId());
            $output->writeln('Grand Total: ' . $invoice->getGrandTotal());
            $output->writeln('State: ' . $invoice->getState());
            $output->writeln('Vindi Bill ID: ' . $this->invoiceBillHelper->getVindiBillIdFromInvoice($invoice));
            $output->writeln('Created At: ' . $invoice->getCreatedAt());
            
            // Informações do pedido relacionado
            $order = $invoice->getOrder();
            if ($order) {
                $output->writeln('Order Increment ID: ' . $order->getIncrementId());
                $output->writeln('Order Total: ' . $order->getGrandTotal());
            }
            
            $output->writeln('---');
        }
        
        return 0;
    }
}
