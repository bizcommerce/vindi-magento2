<?php

namespace Vindi\Payment\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Vindi\Payment\Helper\Data as HelperData;

/**
 * Command to diagnose multi-payment method issues
 */
class DiagnoseMultiPaymentBills extends Command
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var HelperData
     */
    protected $helperData;

    public function __construct(
        ResourceConnection $resourceConnection,
        OrderRepository $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        HelperData $helperData,
        string $name = null
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->helperData = $helperData;
        parent::__construct($name);
    }

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this->setName('vindi:diagnose:multipayment-bills')
            ->setDescription('Diagnosticar problemas na criação de bills para multimeios de pagamento')
            ->addArgument('order_increment', InputArgument::OPTIONAL, 'Increment ID do pedido específico');
    }

    /**
     * Execute the command
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("🔍 DIAGNÓSTICO: Multimeios de Pagamento - Criação de Bills");
        $output->writeln("=" . str_repeat("=", 60));

        $orderIncrement = $input->getArgument('order_increment');
        $connection = $this->resourceConnection->getConnection();


        $output->writeln("\n1. 📋 Verificando configuração do produto de desconto...");
        $configQuery = $connection->select()
            ->from($connection->getTableName('core_config_data'))
            ->where("path LIKE '%discount_product%' OR path LIKE '%vindi%discount%'");

        $configs = $connection->fetchAll($configQuery);
        if (empty($configs)) {
            $output->writeln("   ⚠️  PROBLEMA: Nenhuma configuração de produto de desconto encontrada!");
            $output->writeln("   💡 SOLUÇÃO: Configurar 'payment/vindi/discount_product_id' no admin");
        } else {
            foreach ($configs as $config) {
                $output->writeln("   ✅ {$config['path']} = {$config['value']}");
            }
        }


        $output->writeln("\n2. 📊 Verificando logs da API para criação de bills...");
        $logsQuery = $connection->select()
            ->from($connection->getTableName('vindi_api_logs'))
            ->where("endpoint LIKE '%bills%'")
            ->order('created_at DESC')
            ->limit(5);

        $logs = $connection->fetchAll($logsQuery);
        if (empty($logs)) {
            $output->writeln("   ⚠️  Nenhum log de API para bills encontrado");
        } else {
            foreach ($logs as $log) {
                $status = $log['status_code'] == 200 ? "✅" : "❌";
                $output->writeln("   {$status} [{$log['created_at']}] {$log['method']} {$log['endpoint']} - Status: {$log['status_code']} - {$log['description']}");
            }
        }


        $output->writeln("\n3. 🛒 Verificando pedidos com multimeios de pagamento...");

        $multiMethods = ['vindi_cardpix', 'vindi_cardcard', 'vindi_cardbankslippix'];
        $whereConditions = [];
        foreach ($multiMethods as $method) {
            $whereConditions[] = "sop.method = '{$method}'";
        }

        $ordersQuery = $connection->select()
            ->from(['so' => $connection->getTableName('sales_order')], ['increment_id', 'entity_id', 'status', 'grand_total', 'created_at'])
            ->joinInner(
                ['sop' => $connection->getTableName('sales_order_payment')],
                'so.entity_id = sop.parent_id',
                ['method', 'additional_information']
            )
            ->where('(' . implode(' OR ', $whereConditions) . ')')
            ->order('so.created_at DESC')
            ->limit($orderIncrement ? 1 : 10);

        if ($orderIncrement) {
            $ordersQuery->where('so.increment_id = ?', $orderIncrement);
        }

        $orders = $connection->fetchAll($ordersQuery);

        if (empty($orders)) {
            $output->writeln("   ⚠️  Nenhum pedido com multimeios encontrado");
        } else {
            foreach ($orders as $order) {
                $output->writeln("\n   📦 PEDIDO: {$order['increment_id']} (ID: {$order['entity_id']})");
                $output->writeln("      └── Método: {$order['method']}");
                $output->writeln("      └── Status: {$order['status']}");
                $output->writeln("      └── Total: R$ {$order['grand_total']}");
                $output->writeln("      └── Data: {$order['created_at']}");


                $additionalInfo = json_decode($order['additional_information'], true);
                if ($additionalInfo) {
                    $amountCredit = $additionalInfo['amount_credit'] ?? 'N/A';
                    $amountPix = $additionalInfo['amount_pix'] ?? 'N/A';
                    $amountSecond = $additionalInfo['amount_second_card'] ?? 'N/A';
                    $amountBankslip = $additionalInfo['amount_bankslip'] ?? 'N/A';

                    $output->writeln("      └── Valor Cartão: R$ {$amountCredit}");
                    if ($amountPix !== 'N/A') $output->writeln("      └── Valor PIX: R$ {$amountPix}");
                    if ($amountSecond !== 'N/A') $output->writeln("      └── Valor 2º Cartão: R$ {$amountSecond}");
                    if ($amountBankslip !== 'N/A') $output->writeln("      └── Valor Boleto: R$ {$amountBankslip}");
                }


                $splitsQuery = $connection->select()
                    ->from($connection->getTableName('vindi_payment_split'))
                    ->where('order_increment_id = ?', $order['increment_id']);

                $splits = $connection->fetchAll($splitsQuery);

                if (empty($splits)) {
                    $output->writeln("      ❌ PROBLEMA: Nenhum payment split encontrado!");
                } else {
                    $output->writeln("      ✅ Payment Splits encontrados: " . count($splits));
                    foreach ($splits as $split) {
                        $billStatus = !empty($split['bill_id']) ? "Bill ID: {$split['bill_id']}" : "❌ SEM BILL ID";
                        $output->writeln("         - Método: {$split['payment_method']}, Valor: R$ {$split['amount']}, {$billStatus}, Status: {$split['status']}");
                    }
                }


                $billIdQuery = $connection->select()
                    ->from($connection->getTableName('sales_order'), ['vindi_bill_id'])
                    ->where('entity_id = ?', $order['entity_id']);

                $billIds = $connection->fetchOne($billIdQuery);
                if ($billIds) {
                    $output->writeln("      ✅ Vindi Bill IDs: {$billIds}");
                } else {
                    $output->writeln("      ❌ PROBLEMA: Nenhum vindi_bill_id encontrado no pedido!");
                }
            }
        }


        $output->writeln("\n4. ❌ Verificando erros específicos nos logs da API...");
        $errorLogsQuery = $connection->select()
            ->from($connection->getTableName('vindi_api_logs'))
            ->where("endpoint LIKE '%bills%' AND (status_code != 200 OR description LIKE '%error%')")
            ->order('created_at DESC')
            ->limit(5);

        $errorLogs = $connection->fetchAll($errorLogsQuery);
        if (empty($errorLogs)) {
            $output->writeln("   ✅ Nenhum erro específico encontrado nos logs recentes");
        } else {
            foreach ($errorLogs as $log) {
                $output->writeln("   ❌ [{$log['created_at']}] {$log['method']} {$log['endpoint']} - Status: {$log['status_code']}");
                $output->writeln("      Descrição: {$log['description']}");
                if (!empty($log['response_body'])) {
                    $response = json_decode($log['response_body'], true);
                    if (isset($response['errors'])) {
                        foreach ($response['errors'] as $error) {
                            $output->writeln("      Erro API: {$error['id']} - {$error['message']}");
                        }
                    }
                }
            }
        }

        $output->writeln("\n" . str_repeat("=", 70));
        $output->writeln("✅ Diagnóstico concluído!");

        return Command::SUCCESS;
    }
}
