# 🛠️ DEBUG: Dados de Cartão Vazios na Nova Estratégia

## 📋 Problema Atual

O erro na API da Vindi mostra que os dados de cartão estão chegando vazios:

```json
{
  "holder_name": "********",
  "card_expiration": "00/0",
  "card_number": "",
  "card_cvv": "***",
  "customer_id": "1865875",
  "payment_company_code": "visa",
  "payment_method_code": "credit_card"
}
```

## 🔍 Diagnóstico Implementado

### 1. **Logs Detalhados Adicionados**
- ✅ All Additional Information completo
- ✅ Dados dos métodos nativos do payment (`getCcOwner()`, `getCcNumber()`, etc.)
- ✅ Dados do additional_information para ambos os cartões
- ✅ Status de cada campo (SET/EMPTY)

### 2. **Correção do Profile Creation**
- ✅ Uso correto do parâmetro `$whichCard` na classe Profile
- ✅ Mapeamento correto de `suffix` → `whichCard`
- ✅ Logs detalhados de cada etapa

## 🧪 Como Testar e Diagnosticar

### 1. **Preparar Logs**
```bash
# Terminal para acompanhar logs da nova estratégia
tail -f var/log/vindi.log | grep "VINDI_MULTIMEIOS_NEW"

# Terminal para acompanhar erros da API
tail -f var/log/vindi.log | grep -E "(422|error|Error)"
```

### 2. **Fazer Pedido Multimeios**
1. Criar pedido de assinatura com 2 cartões
2. Aguardar logs aparecerem
3. Analisar dados capturados

### 3. **Analisar Logs**

#### **Log de Additional Information**
```bash
grep "All Additional Info" var/log/vindi.log | tail -1 | jq
```

#### **Log de Dados dos Cartões**
```bash
grep "Card Data Summary" var/log/vindi.log | tail -1 | jq
```

#### **Logs de Profile Creation**
```bash
grep -A 5 -B 5 "Profile creation response" var/log/vindi.log | tail -15
```

## 🔧 Possíveis Problemas e Soluções

### ❌ **Problema: Frontend não envia dados**
**Diagnóstico:** `All Additional Info` vazio ou incompleto

**Possíveis causas:**
- Formulário do checkout não configurado
- JavaScript não capturando dados
- Método de pagamento não implementado

**Solução:** Verificar frontend e configuração do checkout

### ❌ **Problema: Nomes de campos incorretos**
**Diagnóstico:** `Card Data Summary` mostra campos vazios

**Mapeamento esperado pela classe Profile:**
```
Primeiro cartão:  cc_owner, cc_number, cc_exp_month, cc_exp_year, cc_cvv, cc_type
Segundo cartão:   cc_owner2, cc_number2, cc_exp_month2, cc_exp_year2, cc_cvv2, cc_type2
```

**Solução:** Ajustar frontend para usar nomes corretos

### ❌ **Problema: Dados mascarados/criptografados**
**Diagnóstico:** API recebe dados como "***" ou vazios

**Possíveis causas:**
- Magento mascarando dados muito cedo
- Problemas na captura de dados sensíveis
- Segurança bloqueando dados

**Solução:** Verificar fluxo de captura de dados sensíveis

## 📋 Checklist de Validação

### ✅ **Dados de Entrada**
- [ ] `additional_information` contém dados dos cartões
- [ ] Campos `cc_*` para primeiro cartão estão preenchidos
- [ ] Campos `cc_*2` para segundo cartão estão preenchidos
- [ ] Valores `amount_credit` e `amount_second_card` corretos

### ✅ **Profile Creation**
- [ ] Profile 1 criado com dados do primeiro cartão
- [ ] Profile 2 criado com dados do segundo cartão
- [ ] API responde com dados válidos (não 422)
- [ ] IDs dos profiles são retornados

### ✅ **Fallback**
- [ ] Se profiles existem, são reutilizados
- [ ] Se criação falha, error handling funciona
- [ ] Logs indicam claramente onde falhou

## 🎯 Comandos de Debug Rápido

```bash
# Ver último pedido multimeios
grep -A 20 "VINDI_MULTIMEIOS_NEW: Iniciando" var/log/vindi.log | tail -25

# Ver dados de cartão capturados
grep "Card Data Summary" var/log/vindi.log | tail -1 | sed 's/.*Card Data Summary: //' | jq

# Ver resposta da API para profile creation
grep -A 5 "Profile creation response" var/log/vindi.log | tail -10

# Ver erros da API Vindi
grep -E "HTTP Status: (4|5)" var/log/vindi.log | tail -5
```

## 📌 Próximos Passos

1. **Teste Imediato**: Criar pedido e verificar logs
2. **Analisar Dados**: Verificar se frontend está enviando dados corretos
3. **Ajustar Frontend**: Se necessário, corrigir nomes dos campos
4. **Validar API**: Confirmar se dados chegam corretos na Vindi

O sistema agora possui **logs extensivos** para identificar exatamente onde está o problema! 🔍
