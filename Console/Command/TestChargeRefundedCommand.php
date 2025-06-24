<?php

namespace Vindi\Payment\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Vindi\Payment\Helper\WebHookHandlers\ChargeRefunded;

/**
 * Console command para testar o cancelamento de invoice por bill ID
 */
class TestChargeRefundedCommand extends Command
{
    const BILL_ID_ARGUMENT = 'bill_id';
    const CHARGE_ID_ARGUMENT = 'charge_id';
    const AMOUNT_ARGUMENT = 'amount';

    private $chargeRefundedHandler;

    public function __construct(
        ChargeRefunded $chargeRefundedHandler,
        $name = null
    ) {
        $this->chargeRefundedHandler = $chargeRefundedHandler;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('vindi:test:charge-refunded')
            ->setDescription('Testa o cancelamento de invoice para estorno de charge')
            ->addArgument(
                self::BILL_ID_ARGUMENT,
                InputArgument::REQUIRED,
                'Vindi Bill ID'
            )
            ->addArgument(
                self::CHARGE_ID_ARGUMENT,
                InputArgument::REQUIRED,
                'Vindi Charge ID'
            )
            ->addArgument(
                self::AMOUNT_ARGUMENT,
                InputArgument::REQUIRED,
                'Valor do estorno'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $billId = $input->getArgument(self::BILL_ID_ARGUMENT);
        $chargeId = $input->getArgument(self::CHARGE_ID_ARGUMENT);
        $amount = floatval($input->getArgument(self::AMOUNT_ARGUMENT));
        
        $output->writeln('<info>Testando cancelamento de invoice para charge refunded...</info>');
        $output->writeln('Bill ID: ' . $billId);
        $output->writeln('Charge ID: ' . $chargeId);
        $output->writeln('Amount: ' . $amount);
        
        $webhookData = [
            'charge' => [
                'id' => $chargeId,
                'amount' => $amount,
                'payment_method' => [
                    'code' => 'credit_card',
                    'name' => 'Cartão de Crédito (Teste)'
                ]
            ],
            'bill' => [
                'id' => $billId,
                'code' => '100000123-01'
            ]
        ];
        
        try {
            $result = $this->chargeRefundedHandler->chargeRefunded($webhookData);
            
            if ($result) {
                $output->writeln('<info>✓ Processamento concluído com sucesso!</info>');
                $output->writeln('<comment>Verifique os logs para mais detalhes: var/log/vindi.log</comment>');
            } else {
                $output->writeln('<error>✗ Erro durante o processamento</error>');
            }
            
        } catch (\Exception $e) {
            $output->writeln('<error>Erro: ' . $e->getMessage() . '</error>');
            return 1;
        }
        
        return 0;
    }
}
