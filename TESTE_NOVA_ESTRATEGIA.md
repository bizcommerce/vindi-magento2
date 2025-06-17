# Script de Teste - Nova Estratégia Multimeios

## Comandos para testar a nova implementação

### 1. Verificar logs da nova estratégia
```bash
# Logs da nova estratégia (criação inicial)
tail -f var/log/vindi.log | grep "VINDI_MULTIMEIOS_NEW"

# Logs de webhooks multimeios
tail -f var/log/vindi.log | grep "VINDI_MULTIMEIOS"
```

### 2. Verificar auditoria de bills
```bash
# Logs de auditoria automática
tail -f var/log/vindi.log | grep "VINDI_AUDIT"
```

### 3. Monitorar criação de assinatura
```bash
# Acompanhar criação de assinatura em tempo real
tail -f var/log/vindi.log | grep -E "(Creating subscription|Bill created|VINDI_MULTIMEIOS_NEW)"
```

### 4. Verificar estrutura de bills
Após criar um pedido multimeios, verificar na Vindi se:

- **Exatamente 2 bills foram criadas**
- **Bill 1**: Código `{pedido}-01`, valor = valor_cartao_1
- **Bill 2**: Código `{pedido}-02`, valor = valor_cartao_2
- **Sem bills extras** ou automáticas

### 5. Testar renovação
Para testar renovação (aguardar ciclo ou simular webhook):

```bash
# Monitorar webhooks de renovação
tail -f var/log/vindi.log | grep -E "(bill_created|enqueueManualBillsForMultiMeios)"
```

### 6. Validar dados do pedido
No admin do Magento, verificar se o pedido contém:

- `vindi_subscription_id`: ID da assinatura
- `vindi_bill_id`: "ID1,ID2" (IDs das duas bills)
- Payment method: `vindi_cardcard`

### 7. Verificar assinatura visível para cliente
Na área do cliente, verificar se a assinatura aparece corretamente.

## Checklist de Validação

### ✅ Criação Inicial
- [ ] Apenas 2 bills criadas (não 3)
- [ ] Valores corretos em cada bill
- [ ] Códigos com sufixos (-01, -02)
- [ ] Assinatura criada e vinculada ao pedido
- [ ] Logs indicam sucesso

### ✅ Renovação
- [ ] Bill automática cancelada (se criada)
- [ ] 2 bills manuais criadas para renovação
- [ ] Códigos com padrão de ciclo (-C02-01, -C02-02)
- [ ] Valores corretos mantidos

### ✅ Auditoria
- [ ] Auditoria automática executada
- [ ] Alertas se mais de 2 bills detectadas
- [ ] Logs de auditoria claros

### ✅ Compatibilidade
- [ ] Assinaturas existentes não afetadas
- [ ] Outros métodos de pagamento funcionando
- [ ] Webhooks processando corretamente

## Simulação de Teste

### Cenário: Pedido R$ 100 (R$ 60 + R$ 40)

**Esperado:**
1. **Bill 1**: `123456-01`, R$ 60, Cartão 1
2. **Bill 2**: `123456-02`, R$ 40, Cartão 2
3. **Total**: R$ 100 (sem cobrança extra)

**Renovação (Ciclo 2):**
1. **Bill 1**: `123456-C02-01`, R$ 60, Cartão 1
2. **Bill 2**: `123456-C02-02`, R$ 40, Cartão 2

## Possíveis Problemas e Soluções

### Problema: 3 Bills ainda sendo criadas
**Solução**: Verificar se webhooks estão cancelando bills automáticas corretamente

### Problema: Valores incorretos
**Solução**: Verificar cálculo do produto de desconto

### Problema: Códigos sem sufixos
**Solução**: Verificar se nova estratégia está sendo usada

### Problema: Falha na criação
**Solução**: Verificar logs para identificar ponto de falha e rollback

## Comandos de Debug

```bash
# Verificar último pedido multimeios
mysql -u root -p magento2_db -e "
SELECT increment_id, vindi_subscription_id, vindi_bill_id, method 
FROM sales_order o 
JOIN sales_order_payment p ON o.entity_id = p.parent_id 
WHERE p.method = 'vindi_cardcard' 
ORDER BY o.created_at DESC LIMIT 5;"

# Verificar logs específicos do pedido
tail -f var/log/vindi.log | grep "Order: 123456"
```
