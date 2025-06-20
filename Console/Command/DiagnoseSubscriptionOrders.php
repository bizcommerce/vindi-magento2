<?php
namespace Vindi\Payment\Console\Command;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\ResourceConnection;

class DiagnoseSubscriptionOrders extends Command
{
    private $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('vindi:diagnose:subscription-orders')
            ->setDescription('Diagnose subscription orders association');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $connection = $this->resourceConnection->getConnection();

        $output->writeln("=== DIAGNÓSTICO DE PEDIDOS E ASSINATURAS ===");


        $output->writeln("\n1. Verificando pedidos com vindi_subscription_id...");
        try {
            $ordersWithSubscription = $connection->select()
                ->from($connection->getTableName('sales_order'), ['entity_id', 'increment_id', 'vindi_subscription_id', 'status', 'created_at'])
                ->where('vindi_subscription_id IS NOT NULL')
                ->order('created_at DESC')
                ->limit(10);

            $orders = $connection->fetchAll($ordersWithSubscription);

            if (!empty($orders)) {
                $output->writeln("✅ Encontrados " . count($orders) . " pedidos com vindi_subscription_id:");
                foreach ($orders as $order) {
                    $output->writeln("  - Order ID: {$order['entity_id']}, Increment: {$order['increment_id']}, Subscription ID: {$order['vindi_subscription_id']}, Status: {$order['status']}, Data: {$order['created_at']}");
                }
            } else {
                $output->writeln("⚠️  Nenhum pedido encontrado com vindi_subscription_id");
            }
        } catch (\Exception $e) {
            $output->writeln("❌ Erro ao consultar pedidos: " . $e->getMessage());
        }


        $output->writeln("\n2. Verificando entradas na tabela vindi_subscription_orders...");
        try {
            $subscriptionOrders = $connection->select()
                ->from($connection->getTableName('vindi_subscription_orders'))
                ->order('created_at DESC')
                ->limit(10);

            $subOrders = $connection->fetchAll($subscriptionOrders);

            if (!empty($subOrders)) {
                $output->writeln("✅ Encontradas " . count($subOrders) . " entradas na tabela vindi_subscription_orders:");
                foreach ($subOrders as $subOrder) {
                    $output->writeln("  - Entity ID: {$subOrder['entity_id']}, Order ID: {$subOrder['order_id']}, Increment: {$subOrder['increment_id']}, Subscription ID: {$subOrder['subscription_id']}, Status: {$subOrder['status']}, Data: {$subOrder['created_at']}");
                }
            } else {
                $output->writeln("⚠️  Nenhuma entrada encontrada na tabela vindi_subscription_orders");
            }
        } catch (\Exception $e) {
            $output->writeln("❌ Erro ao consultar vindi_subscription_orders: " . $e->getMessage());
        }


        $output->writeln("\n3. Verificando discrepâncias entre pedidos e tabela de associação...");
        try {
            $orphanedOrders = $connection->select()
                ->from(['so' => $connection->getTableName('sales_order')], ['entity_id', 'increment_id', 'vindi_subscription_id'])
                ->joinLeft(
                    ['vso' => $connection->getTableName('vindi_subscription_orders')],
                    'so.entity_id = vso.order_id',
                    ['vso_entity_id' => 'entity_id']
                )
                ->where('so.vindi_subscription_id IS NOT NULL')
                ->where('vso.entity_id IS NULL');

            $orphanedOrdersResult = $connection->fetchAll($orphanedOrders);

            if (!empty($orphanedOrdersResult)) {
                $output->writeln("⚠️  Encontrados " . count($orphanedOrdersResult) . " pedidos com vindi_subscription_id mas sem entrada na tabela vindi_subscription_orders:");
                foreach ($orphanedOrdersResult as $orphan) {
                    $output->writeln("  - Order ID: {$orphan['entity_id']}, Increment: {$orphan['increment_id']}, Subscription ID: {$orphan['vindi_subscription_id']}");
                }
            } else {
                $output->writeln("✅ Todos os pedidos com vindi_subscription_id têm entrada na tabela vindi_subscription_orders");
            }
        } catch (\Exception $e) {
            $output->writeln("❌ Erro ao verificar discrepâncias: " . $e->getMessage());
        }

        return Cli::RETURN_SUCCESS;
    }
}
