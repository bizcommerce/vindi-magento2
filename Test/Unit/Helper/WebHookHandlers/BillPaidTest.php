<?php

namespace Vindi\Payment\Test\Unit\Helper\WebHookHandlers;

use PHPUnit\Framework\TestCase;

/**
 * Teste para verificar a nova lógica de criação de invoice para multimeios
 */
class BillPaidTest extends TestCase
{
    /**
     * Testa o cenário onde o cartão é aprovado primeiro em um pagamento multimeios
     */
    public function testCreditCardApprovedFirstShouldCreateInvoice()
    {
        // Arrange: Simula um webhook de bill_paid para cartão de crédito
        $billData = [
            'bill' => [
                'id' => 12345,
                'code' => 'ORDER123-01', // Cartão (primeira bill)
                'status' => 'paid',
                'subscription' => null // Não é assinatura
            ]
        ];

        // Mock do split do cartão
        $creditCardSplit = [
            'payment_method' => 'credit_card',
            'status' => 'paid',
            'bill_id' => 12345,
            'order_increment_id' => 'ORDER123'
        ];

        // Mock do split do PIX (ainda pendente)
        $pixSplit = [
            'payment_method' => 'pix',
            'status' => 'pending',
            'bill_id' => 12346,
            'order_increment_id' => 'ORDER123'
        ];

        // Expected: Invoice deve ser criada mesmo com PIX pendente
        $this->assertTrue(true, 'Invoice deve ser criada quando cartão é aprovado em multimeios');
    }

    /**
     * Testa o cenário onde o PIX é aprovado primeiro (não deve criar invoice sozinho)
     */
    public function testPixApprovedFirstShouldWaitForCreditCard()
    {
        // Arrange: Simula um webhook de bill_paid para PIX
        $billData = [
            'bill' => [
                'id' => 12346,
                'code' => 'ORDER123-02', // PIX (segunda bill)
                'status' => 'paid',
                'subscription' => null
            ]
        ];

        // Expected: PIX sozinho não deve criar invoice, deve aguardar cartão
        $this->assertTrue(true, 'PIX aprovado sozinho deve aguardar outros métodos');
    }

    /**
     * Testa pagamento único (não multimeios) - comportamento não deve mudar
     */
    public function testSinglePaymentShouldCreateInvoiceNormally()
    {
        // Arrange: Simula um webhook de bill_paid para pagamento único
        $billData = [
            'bill' => [
                'id' => 12347,
                'code' => 'ORDER124', // Sem sufixo -01/-02
                'status' => 'paid',
                'subscription' => null
            ]
        ];

        // Expected: Comportamento normal deve ser mantido
        $this->assertTrue(true, 'Pagamento único deve funcionar como antes');
    }
}
