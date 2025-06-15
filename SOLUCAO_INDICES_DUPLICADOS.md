# Solução para Erro de Índices Duplicados - Vindi Payment

## Problema
```
SQLSTATE[42000]: Syntax error or access violation: 1061 Duplicate key name 'VINDI_PAYMENT_SPLIT_SUBSCRIPTION_ID_CYCLE'
```

## Causa
O Magento está tentando criar índices que já existem na tabela `vindi_payment_split` com nomes ligeiramente diferentes.

## Soluções

### Solução 1: Executar Script SQL (Recomendada)
Execute o arquivo `fix_vindi_indexes.sql` no banco de dados:

```bash
mysql -u [usuario] -p [senha] [database] < fix_vindi_indexes.sql
```

### Solução 2: SQL Manual
Execute diretamente no MySQL/MariaDB:

```sql
-- Verificar índices existentes
SHOW INDEX FROM vindi_payment_split;

-- Remover índices duplicados
DROP INDEX IF EXISTS VINDI_PAYMENT_SPLIT_SUBSCRIPTION_ID_CYCLE ON vindi_payment_split;
DROP INDEX IF EXISTS VINDI_PAYMENT_SPLIT_SUBSCRIPTION_CYCLE ON vindi_payment_split;
DROP INDEX IF EXISTS VINDI_PAYMENT_SPLIT_BILL_ID ON vindi_payment_split;
```

### Solução 3: Executar Patch Automático
O patch `Setup/Patch/Schema/FixDuplicateIndexes.php` foi criado e será executado automaticamente na próxima atualização do módulo.

### Solução 4: Comando Magento
Após aplicar uma das soluções acima, execute:

```bash
php bin/magento setup:upgrade
php bin/magento cache:flush
```

## Verificação
Após aplicar a solução, verifique se os índices foram criados corretamente:

```sql
SHOW INDEX FROM vindi_payment_split WHERE Key_name LIKE 'VINDI_PAYMENT_SPLIT%';
```

## Arquivos Modificados
- `etc/db_schema.xml` - Corrigido para usar a sintaxe `<index>` ao invés de `<constraint xsi:type="index">`
- `Setup/Patch/Schema/FixDuplicateIndexes.php` - Patch para limpeza automática
- `fix_vindi_indexes.sql` - Script manual de correção

## Status
✅ Schema corrigido
✅ Patch de correção criado  
✅ Script SQL de fallback criado
⏳ Pendente: Executar uma das soluções no ambiente
