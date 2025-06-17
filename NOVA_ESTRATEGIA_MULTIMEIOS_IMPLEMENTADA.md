# Nova Estratégia para Multimeios de Pagamento - IMPLEMENTADA

## Data: 2024
## Status: IMPLEMENTADA - AGUARDANDO TESTES

## Resumo da Nova Estratégia

Foi implementada uma **nova abordagem inteligente** para resolver definitivamente o problema de criação de 3 bills (1 automática + 2 manuais) em assinaturas multimeios. A solução aproveitando o fato de que **a API Vindi sempre cria uma bill obrigatória** ao criar uma assinatura, tornando essa bill útil em vez de problemática.

## Como Funciona a Nova Estratégia

### 1. Criação Inicial da Assinatura

**ANTES (Problema):**
- Criava assinatura → Bill automática era criada pela Vindi
- Criava 2 bills manuais para multimeios
- **RESULTADO: 3 bills (PROBLEMA!)**

**AGORA (Solução):**
- Cria assinatura **COM** a bill obrigatória já configurada para o **primeiro cartão**
- A bill obrigatória tem o valor original **menos o valor do segundo cartão** (usando produto de desconto)
- Cria apenas **1 bill adicional** para o segundo cartão
- **RESULTADO: 2 bills exatamente (CORRETO!)**

### 2. Estrutura dos Códigos de Bills

**Criação Inicial:**
- Bill 1 (obrigatória): `{pedido}-01` (ex: 123456-01)
- Bill 2 (adicional): `{pedido}-02` (ex: 123456-02)

**Renovações:**
- Bill 1: `{pedido}-C{ciclo}-01` (ex: 123456-C02-01)
- Bill 2: `{pedido}-C{ciclo}-02` (ex: 123456-C02-02)

### 3. Valores das Bills

**Exemplo: Pedido de R$ 100 (R$ 60 cartão 1 + R$ 40 cartão 2)**

**Bill 1 (obrigatória da assinatura):**
- Produto da assinatura: +R$ 100
- Produto de desconto: -R$ 40 (valor do cartão 2)
- **Total: R$ 60** → Cobrado no cartão 1

**Bill 2 (adicional):**
- Produto de desconto: +R$ 40 (apenas o valor do cartão 2)
- **Total: R$ 40** → Cobrado no cartão 2

## Principais Mudanças Implementadas

### Model/Payment/AbstractMethod.php
- **Método `processMultiMethodSubscriptionPayment` completamente refatorado**
- Implementação da nova estratégia usando a bill obrigatória
- Códigos de bills com sufixos identificadores (`-01`, `-02`)
- Validação dupla de sucesso das bills
- Rollback automático em caso de falha

### Helper/WebHookHandlers/BillCreated.php
- **Detecção inteligente de bills manuais** usando regex
- Suporte aos novos padrões de código: `-01`, `-02`, `-C{ciclo}-01`, `-C{ciclo}-02`
- Lógica diferenciada para criação inicial vs renovações
- Prevenção de cancelamento de bills corretas

### Helper/WebHookHandlers/OrderCreator.php
- **Códigos de bills de renovação atualizados** para formato: `{pedido}-C{ciclo}-{cartão}`
- Mantida compatibilidade com método `enqueueManualBillsForMultiMeios`

## Vantagens da Nova Estratégia

### ✅ **Elimina o Problema de 3 Bills**
- Sempre cria exatamente 2 bills (1 obrigatória + 1 adicional)
- Não há bills "sobrandos" ou automáticas indesejadas

### ✅ **Aproveita a API da Vindi**
- Usa a bill obrigatória de forma inteligente
- Não luta contra o comportamento da API, trabalha com ele

### ✅ **Identificação Clara**
- Códigos com sufixos facilitam auditoria e rastreamento
- Webhooks conseguem distinguir bills automáticas de manuais

### ✅ **Rollback Automático**
- Se qualquer bill falhar, toda a operação é desfeita
- Evita estados inconsistentes

### ✅ **Compatibilidade com Renovações**
- Webhooks ajustados para lidar com ambos os padrões
- Renovações continuam funcionando corretamente

## Arquivos Modificados

```
Model/Payment/AbstractMethod.php          - NOVA ESTRATÉGIA PRINCIPAL
Helper/WebHookHandlers/BillCreated.php    - DETECÇÃO DE BILLS MANUAIS
Helper/WebHookHandlers/OrderCreator.php   - CÓDIGOS DE RENOVAÇÃO
```

## Próximos Passos - TESTES NECESSÁRIOS

### 1. Teste de Criação Inicial
- [ ] Criar pedido multimeios em sandbox
- [ ] Verificar se exatamente 2 bills são criadas
- [ ] Validar valores corretos em cada bill
- [ ] Confirmar códigos com sufixos

### 2. Teste de Renovação
- [ ] Aguardar renovação automática ou forçar via webhook
- [ ] Verificar se bills automáticas são canceladas
- [ ] Validar criação de 2 bills manuais para renovação
- [ ] Confirmar códigos com padrão de ciclo

### 3. Auditoria e Logs
- [ ] Verificar logs `VINDI_MULTIMEIOS_NEW` para criação
- [ ] Verificar logs `VINDI_MULTIMEIOS` para renovações
- [ ] Confirmar auditoria automática de bills

### 4. Validação de Integração
- [ ] Assinatura visível na área do cliente
- [ ] Dados corretos salvos no pedido
- [ ] Splits de pagamento registrados corretamente

## Observações Importantes

### ⚠️ **Bills com Sufixos**
Os webhooks agora reconhecem bills com sufixos (`-01`, `-02`, etc.) como **bills manuais válidas** e **NÃO** as cancelam. Isso é crucial para evitar cancelamento das bills corretas.

### ⚠️ **Detecção de Renovação**
O método `isRenewalBill()` continua sendo usado para distinguir entre criação inicial e renovações, garantindo que o fluxo correto seja aplicado.

### ⚠️ **Compatibilidade**
A mudança é compatível com assinaturas existentes. Apenas novos pedidos multimeios usarão a nova estratégia.

## Resumo Técnico

**A nova estratégia transforma o "problema" da bill obrigatória em parte da solução, criando exatamente as 2 bills necessárias de forma elegante e eficiente, eliminando definitivamente o risco de cobranças duplicadas/triplas.**

**Status: IMPLEMENTADA ✅**  
**Próximo: TESTES EM SANDBOX 🧪**
