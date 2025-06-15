<?php

namespace Vindi\Payment\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Magento\Framework\App\State;
use Magento\Framework\App\ResourceConnection;
use Vindi\Payment\Helper\Api;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\Api\SearchCriteriaBuilder;

class DiagnoseMultiPayments extends Command
{
    private $appState;
    private $resourceConnection;
    private $apiHelper;
    private $orderRepository;
    private $searchCriteriaBuilder;

    public function __construct(
        State $appState,
        ResourceConnection $resourceConnection,
        Api $apiHelper,
        OrderRepository $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->appState = $appState;
        $this->resourceConnection = $resourceConnection;
        $this->apiHelper = $apiHelper;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('vindi:diagnose:multi-payments')
            ->setDescription('Diagnose multi-payment methods bill creation issues')
            ->addArgument(
                'order_increment_id',
                InputArgument::OPTIONAL,
                'Specific order increment ID to analyze'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area already set
        }

        $connection = $this->resourceConnection->getConnection();
        $orderIncrementId = $input->getArgument('order_increment_id');

        $output->writeln("=== DIAGNÓSTICO DE MULTIMEIOS DE PAGAMENTO ===\n");

        // 1. Verificar pedidos com multimeios
        $output->writeln("1. Verificando pedidos com métodos de pagamento múltiplos...");
        
        $orderWhere = [];
        if ($orderIncrementId) {
            $orderWhere[] = "increment_id = '$orderIncrementId'";
            $output->writeln("   Filtrando por pedido específico: $orderIncrementId");
        }

        $whereClause = $orderWhere ? ' AND ' . implode(' AND ', $orderWhere) : '';
        
        $multiPaymentMethods = ['vindi_cardcard', 'vindi_cardpix', 'vindi_cardbankslippix'];
        
        foreach ($multiPaymentMethods as $method) {
            $output->writeln("\n--- Método: $method ---");
            
            // Buscar pedidos com este método
            $ordersQuery = $connection->select()
                ->from(
                    ['o' => $connection->getTableName('sales_order')],
                    ['entity_id', 'increment_id', 'created_at', 'status', 'vindi_bill_id']
                )
                ->joinLeft(
                    ['p' => $connection->getTableName('sales_order_payment')],
                    'o.entity_id = p.parent_id',
                    ['method', 'additional_information']
                )
                ->where('p.method = ?', $method)
                ->where('o.created_at >= ?', date('Y-m-d', strtotime('-30 days')));

            if ($orderIncrementId) {
                $ordersQuery->where('o.increment_id = ?', $orderIncrementId);
            }

            $orders = $connection->fetchAll($ordersQuery);
            
            if (empty($orders)) {
                $output->writeln("   ❌ Nenhum pedido encontrado para $method");
                continue;
            }

            $output->writeln("   ✅ Encontrados " . count($orders) . " pedidos:");

            foreach ($orders as $orderData) {
                $output->writeln("   
   📦 Pedido: {$orderData['increment_id']} (ID: {$orderData['entity_id']})
      Status: {$orderData['status']}
      Data: {$orderData['created_at']}
      Vindi Bill ID: " . ($orderData['vindi_bill_id'] ?: 'NENHUM'));

                // Decodificar additional_information
                $additionalInfo = json_decode($orderData['additional_information'], true) ?: [];
                
                if ($method === 'vindi_cardcard') {
                    $amountCredit = $additionalInfo['amount_credit'] ?? 'N/A';
                    $amountSecondCard = $additionalInfo['amount_second_card'] ?? 'N/A';
                    $output->writeln("      Valor Cartão 1: $amountCredit");
                    $output->writeln("      Valor Cartão 2: $amountSecondCard");
                } elseif ($method === 'vindi_cardpix') {
                    $amountCredit = $additionalInfo['amount_credit'] ?? 'N/A';
                    $amountPix = $additionalInfo['amount_pix'] ?? 'N/A';
                    $output->writeln("      Valor Cartão: $amountCredit");
                    $output->writeln("      Valor PIX: $amountPix");
                } elseif ($method === 'vindi_cardbankslippix') {
                    $amountCredit = $additionalInfo['amount_credit'] ?? 'N/A';
                    $amountBankslipPix = $additionalInfo['amount_bankslippix'] ?? 'N/A';
                    $output->writeln("      Valor Cartão: $amountCredit");
                    $output->writeln("      Valor Boleto PIX: $amountBankslipPix");
                }

                // Verificar payment splits
                $splitsQuery = $connection->select()
                    ->from($connection->getTableName('vindi_payment_split'))
                    ->where('order_increment_id = ?', $orderData['increment_id']);

                $splits = $connection->fetchAll($splitsQuery);
                
                if (empty($splits)) {
                    $output->writeln("      ❌ PROBLEMA: Nenhum payment split encontrado!");
                } else {
                    $output->writeln("      ✅ Payment Splits encontrados: " . count($splits));
                    foreach ($splits as $split) {
                        $output->writeln("         - Método: {$split['payment_method']}, Valor: {$split['amount']}, Bill ID: {$split['bill_id']}, Status: {$split['status']}");
                    }
                }

                // Verificar bills na Vindi (se tiver bill_id)
                if ($orderData['vindi_bill_id']) {
                    $this->checkVindiBills($orderData['vindi_bill_id'], $orderData['increment_id'], $output);
                }
            }
        }

        // 2. Verificar logs de API recentes
        $output->writeln("\n\n2. Verificando logs de API recentes...");
        $this->checkApiLogs($connection, $output, $orderIncrementId);

        return 0;
    }

    private function checkVindiBills($vindiBillIds, $incrementId, $output)
    {
        $output->writeln("\n      🔍 Verificando bills na Vindi para pedido $incrementId:");
        
        $billIds = explode(',', $vindiBillIds);
        foreach ($billIds as $billId) {
            $billId = trim($billId);
            if (!$billId) continue;

            try {
                $response = $this->apiHelper->request("bills/$billId", 'GET');
                if ($response && isset($response['bill'])) {
                    $bill = $response['bill'];
                    $output->writeln("         ✅ Bill $billId: Status = {$bill['status']}, Code = {$bill['code']}, Valor = {$bill['amount']}");
                } else {
                    $output->writeln("         ❌ Bill $billId: NÃO ENCONTRADA na Vindi");
                }
            } catch (\Exception $e) {
                $output->writeln("         ❌ Erro ao verificar bill $billId: " . $e->getMessage());
            }
        }
    }

    private function checkApiLogs($connection, $output, $orderIncrementId = null)
    {
        try {
            $logsQuery = $connection->select()
                ->from($connection->getTableName('vindi_api_logs'))
                ->where('endpoint LIKE ?', '%bills%')
                ->where('created_at >= ?', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->order('created_at DESC')
                ->limit(10);

            $logs = $connection->fetchAll($logsQuery);

            if (empty($logs)) {
                $output->writeln("   ❌ Nenhum log de API encontrado nas últimas 24h");
                return;
            }

            $output->writeln("   ✅ Últimos " . count($logs) . " logs de bills (24h):");

            foreach ($logs as $log) {
                $requestBody = json_decode($log['request_body'], true) ?: [];
                $responseBody = json_decode($log['response_body'], true) ?: [];
                
                $billCode = $requestBody['code'] ?? 'N/A';
                $status = $log['status_code'];
                
                if ($orderIncrementId && strpos($billCode, $orderIncrementId) === false) {
                    continue; // Pular se não for o pedido específico
                }

                $output->writeln("   
      📝 Log: {$log['created_at']}
         Endpoint: {$log['endpoint']} ({$log['method']})
         Status: $status
         Code: $billCode");

                if ($status >= 400) {
                    $errorMsg = $responseBody['errors'][0]['parameter'] ?? 'Erro desconhecido';
                    $output->writeln("         ❌ ERRO: $errorMsg");
                } elseif (isset($responseBody['bill'])) {
                    $billId = $responseBody['bill']['id'] ?? 'N/A';
                    $billStatus = $responseBody['bill']['status'] ?? 'N/A';
                    $output->writeln("         ✅ Bill criada: ID = $billId, Status = $billStatus");
                }
            }
        } catch (\Exception $e) {
            $output->writeln("   ❌ Erro ao verificar logs: " . $e->getMessage());
        }
    }
}
