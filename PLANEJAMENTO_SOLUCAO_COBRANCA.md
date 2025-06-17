# ANÁLISE E PLANEJAMENTO DA SOLUÇÃO - CORREÇÃO DE COBRANÇA DUPLICADA

## 🔍 **ANÁLISE DO PROBLEMA ATUAL**

### **O que está acontecendo:**
1. **product_items**: Produtos originais da assinatura (R$ 100 + R$ 16,12 - R$ 10 = R$ 106,12)
2. **bill_items**: Produtos originais + item de desconto (-R$ 46,12)
3. **Resultado**: A Vindi ignora o item de desconto e usa os product_items como base

### **Por que a bill está com valor total:**
Analisando o log da resposta da API:
```json
"bill": {
  "amount": "106.12",
  "bill_items": [
    {
      "product": {"name": "Produto de Assinatura Simples"},
      "pricing_schema": {"price": "100.0"},
      "discounts": [{"amount": "10.0"}]
    },
    {
      "product": {"name": "Frete"},
      "pricing_schema": {"price": "16.12"}
    }
  ]
}
```

A API está usando os **`product_items`** (que definem a assinatura) como base, e os **`bill_items`** apenas como override pontual.

## 🎯 **SOLUÇÕES POSSÍVEIS**

### **SOLUÇÃO 1: BILL_ITEMS APENAS COM VALOR DESEJADO (RECOMENDADA)**
```php
// Não incluir product_items na assinatura, apenas bill_items
$bodySubscription = [
    'customer_id' => $customerId,
    'payment_method_code' => PaymentMethod::CREDIT_CARD,
    'plan_id' => $planId,
    // 'product_items' => [], // REMOVER - deixar vazio
    'bill_items' => [
        [
            'product_id' => $multiPaymentDiscountProductId,
            'amount' => (float)$amountCredit // Apenas valor do primeiro cartão
        ]
    ],
    'code' => $order->getIncrementId() . '-01',
    'due_at' => date('Y-m-d'),
];
```

**Vantagem:** 
- Bill criada apenas com o valor desejado
- Sem dependência dos produtos originais
- Controle total sobre o valor

**Potencial problema:**
- Assinatura pode não funcionar corretamente sem product_items
- Próximas cobranças podem falhar

### **SOLUÇÃO 2: CRIAR PRODUTOS PROPORCIONAIS**
```php
// Calcular proporção
$totalOriginal = 106.12; // Valor total original
$proportion1 = (float)$amountCredit / $totalOriginal; // Ex: 60/106.12 = 0.565

// Ajustar preços dos produtos proporcionalmente
$adjustedProductList = [];
foreach ($productList as $product) {
    $originalPrice = $product['pricing_schema']['price'];
    $adjustedPrice = $originalPrice * $proportion1;
    
    $adjustedProductList[] = [
        'product_id' => $product['product_id'],
        'quantity' => $product['quantity'],
        'pricing_schema' => ['price' => $adjustedPrice]
    ];
}

$bodySubscription = [
    'customer_id' => $customerId,
    'payment_method_code' => PaymentMethod::CREDIT_CARD,
    'plan_id' => $planId,
    'product_items' => $adjustedProductList, // Produtos com preços ajustados
    'code' => $order->getIncrementId() . '-01',
    'due_at' => date('Y-m-d'),
];
```

**Vantagem:**
- Mantém a estrutura original da assinatura
- Próximas cobranças funcionarão
- Produtos reconhecíveis nos relatórios

**Desvantagem:**
- Mais complexo
- Preços fracionados podem confundir

### **SOLUÇÃO 3: CRIAR ASSINATURA SEM BILL INICIAL + BILLS MANUAIS**
```php
// Assinatura sem bill inicial
$bodySubscription = [
    'customer_id' => $customerId,
    'payment_method_code' => PaymentMethod::CREDIT_CARD,
    'plan_id' => $planId,
    'product_items' => $productList,
    'skip_bill' => true, // Pular criação da bill inicial
    'code' => $order->getIncrementId() . '-01',
];

// Depois criar 2 bills manuais
$bill1 = $this->bill->create([
    'customer_id' => $customerId,
    'subscription_id' => $subscription['id'],
    'bill_items' => [
        ['product_id' => $multiPaymentDiscountProductId, 'amount' => $amountCredit]
    ]
]);

$bill2 = $this->bill->create([
    'customer_id' => $customerId,
    'bill_items' => [
        ['product_id' => $multiPaymentDiscountProductId, 'amount' => $amountSecondCard]
    ]
]);
```

**Vantagem:**
- Controle total sobre as bills
- Valores exatos
- Assinatura mantém estrutura original

**Potencial problema:**
- Precisa verificar se `skip_bill` existe na API
- Mais requisições à API

### **SOLUÇÃO 4: USAR DESCONTOS PROPORCIONAIS**
```php
// Bill com produtos originais + desconto proporcional
$discountAmount = $totalOriginal - (float)$amountCredit; // 106.12 - 60 = 46.12

$billItemsCard1 = $productList;
$billItemsCard1[] = [
    'product_id' => $multiPaymentDiscountProductId,
    'amount' => -$discountAmount // Desconto exato para deixar apenas o valor desejado
];
```

**Mas isso não está funcionando como esperado.**

## 🎯 **RECOMENDAÇÃO FINAL**

**Sugiro testar a SOLUÇÃO 2 (Produtos Proporcionais):**

1. **✅ Mais segura**: Mantém a estrutura de assinatura
2. **✅ Compatível**: Funciona com o modelo da Vindi
3. **✅ Próximas cobranças**: Continuarão funcionando
4. **✅ Auditável**: Produtos identificáveis nos relatórios

**Implementação seria:**
- Calcular proporção do primeiro cartão (ex: 60/106.12 = 56.5%)
- Ajustar preço de cada produto nessa proporção
- Usar `product_items` com preços ajustados
- Segunda bill continua como avulsa

**O que você acha dessa abordagem?** Quer que eu implemente a Solução 2 ou prefere testar outra?
