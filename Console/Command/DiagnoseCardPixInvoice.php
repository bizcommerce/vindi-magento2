<?php

namespace Vindi\Payment\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\OrderFactory;
use Vindi\Payment\Model\PaymentSplitFactory;

/**
 * Comando para diagnosticar problemas com invoice em pedidos Cartão + PIX
 */
class DiagnoseCardPixInvoice extends Command
{
    private $resourceConnection;
    private $orderFactory;
    private $paymentSplitFactory;

    public function __construct(
        ResourceConnection $resourceConnection,
        OrderFactory $orderFactory,
        PaymentSplitFactory $paymentSplitFactory
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->orderFactory = $orderFactory;
        $this->paymentSplitFactory = $paymentSplitFactory;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('vindi:diagnose:card-pix-invoice')
            ->setDescription('Diagnóstica problemas com criação de invoice em pedidos Cartão + PIX')
            ->addArgument('order_increment_id', InputArgument::REQUIRED, 'ID do pedido para analisar');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $orderIncrementId = $input->getArgument('order_increment_id');
        $connection = $this->resourceConnection->getConnection();

        $output->writeln("🔍 DIAGNÓSTICO: Pedido $orderIncrementId");
        $output->writeln("=======================================\n");

        $orderQuery = $connection->select()
            ->from($connection->getTableName('sales_order'), ['entity_id', 'increment_id', 'status', 'method' => 'vindi_bill_id'])
            ->joinLeft(
                ['payment' => $connection->getTableName('sales_order_payment')],
                'main_table.entity_id = payment.parent_id',
                ['payment_method' => 'method', 'additional_information']
            )
            ->where('increment_id = ?', $orderIncrementId);

        $orderData = $connection->fetchRow($orderQuery);

        if (!$orderData) {
            $output->writeln("❌ ERRO: Pedido não encontrado!");
            return 1;
        }

        $output->writeln("✅ PEDIDO ENCONTRADO:");
        $output->writeln("   - ID: {$orderData['entity_id']}");
        $output->writeln("   - Status: {$orderData['status']}");
        $output->writeln("   - Método de Pagamento: {$orderData['payment_method']}");
        $output->writeln("   - Vindi Bill ID: " . ($orderData['method'] ?: 'NÃO DEFINIDO'));

        if ($orderData['additional_information']) {
            $additionalInfo = json_decode($orderData['additional_information'], true);
            $output->writeln("\n📋 INFORMAÇÕES DO PAGAMENTO:");
            $output->writeln("   - Valor Cartão: R$ " . ($additionalInfo['amount_credit'] ?? 'N/A'));
            $output->writeln("   - Valor PIX: R$ " . ($additionalInfo['amount_pix'] ?? 'N/A'));
        }

        $splitsQuery = $connection->select()
            ->from($connection->getTableName('vindi_payment_split'))
            ->where('order_increment_id = ?', $orderIncrementId);

        $splits = $connection->fetchAll($splitsQuery);

        $output->writeln("\n🔄 PAYMENT SPLITS:");
        if (empty($splits)) {
            $output->writeln("   ❌ NENHUM SPLIT ENCONTRADO!");
            $output->writeln("   → PROBLEMA: Bills não foram salvas nos splits");
        } else {
            $output->writeln("   ✅ " . count($splits) . " splits encontrados:");
            foreach ($splits as $split) {
                $output->writeln("      - Método: {$split['payment_method']}");
                $output->writeln("        Bill ID: {$split['bill_id']}");
                $output->writeln("        Status: {$split['status']}");
                $output->writeln("        Valor: R$ {$split['amount']}");
                $output->writeln("");
            }
        }

        $invoiceQuery = $connection->select()
            ->from($connection->getTableName('sales_invoice'), ['increment_id', 'created_at', 'state'])
            ->where('order_id = ?', $orderData['entity_id']);

        $invoices = $connection->fetchAll($invoiceQuery);

        $output->writeln("📄 INVOICES:");
        if (empty($invoices)) {
            $output->writeln("   ❌ NENHUMA INVOICE ENCONTRADA!");
        } else {
            $output->writeln("   ✅ " . count($invoices) . " invoices encontradas:");
            foreach ($invoices as $invoice) {
                $output->writeln("      - ID: {$invoice['increment_id']}");
                $output->writeln("        Estado: {$invoice['state']}");
                $output->writeln("        Criada em: {$invoice['created_at']}");
            }
        }

        $output->writeln("\n🧪 SIMULAÇÃO DA LÓGICA DO WEBHOOK:");
        
        $creditCardSplit = null;
        foreach ($splits as $split) {
            if ($split['payment_method'] === 'credit_card') {
                $creditCardSplit = $split;
                break;
            }
        }

        if (!$creditCardSplit) {
            $output->writeln("   ❌ SPLIT DO CARTÃO NÃO ENCONTRADO!");
            $output->writeln("   → Possível causa: payment_method não está sendo salvo como 'credit_card'");
            
            $output->writeln("\n   🔍 VALORES ENCONTRADOS:");
            foreach ($splits as $split) {
                $output->writeln("      - '{$split['payment_method']}'");
            }
        } else {
            $output->writeln("   ✅ Split do cartão encontrado");
            
            $hasInvoices = !empty($invoices);
            $isMultiMethod = count($splits) > 1;
            
            $output->writeln("   - Tem invoices já? " . ($hasInvoices ? "SIM ❌ (bloqueia)" : "NÃO ✅"));
            $output->writeln("   - É multimeios? " . ($isMultiMethod ? "SIM ✅" : "NÃO ❌ (bloqueia)"));
            $output->writeln("   - Status do cartão: {$creditCardSplit['status']}");
            
            if (!$hasInvoices && $isMultiMethod && $creditCardSplit['status'] === 'paid') {
                $output->writeln("\n   ✅ DEVERIA CRIAR INVOICE AUTOMATICAMENTE!");
            } else {
                $output->writeln("\n   ❌ NÃO DEVERIA CRIAR INVOICE AINDA");
                if ($hasInvoices) $output->writeln("      → Já tem invoice");
                if (!$isMultiMethod) $output->writeln("      → Não é multimeios");
                if ($creditCardSplit['status'] !== 'paid') $output->writeln("      → Cartão não está pago");
            }
        }

        $output->writeln("\n📊 PRÓXIMOS PASSOS:");
        $output->writeln("1. Verificar logs do webhook:");
        $output->writeln("   tail -f /var/log/vindi/webhook.log | grep '$orderIncrementId'");
        $output->writeln("\n2. Verificar se webhook chegou:");
        $output->writeln("   grep 'bill_paid' /var/log/vindi/webhook.log | grep -i '{$orderData['method']}'");
        $output->writeln("\n3. Se o problema persistir, verifique:");
        $output->writeln("   - Timing entre criação dos splits e chegada do webhook");
        $output->writeln("   - Se payment_method está sendo salvo corretamente");
        $output->writeln("   - Se bill_id está fazendo match correto");

        return 0;
    }
}
