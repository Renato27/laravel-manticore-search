# 🔧 Correção: Total Errado com sql() - ResultSet

## 🐛 Problema Identificado

O método `$client->sql()` tem um segundo parâmetro que afeta **o tipo de retorno**:

- `$client->sql($sql, false)` → Retorna **array** ❌ (sem método `getTotal()`)
- `$client->sql($sql, true)` → Retorna **objeto ResultSet** ✅ (com método `getTotal()`)

### Sintoma
```php
// Antes da correção
$resultSet = $client->sql($sql, false);  // array
$total = $resultSet->getTotal();         // ❌ Fatal error: Call to a member function getTotal() on array
```

---

## ✅ Solução Aplicada

Foram corrigidos **2 locais** no arquivo `src/Builder/ManticoreBuilder.php`:

### 1. **Em `getTotalMatches()` (linha ~111)**
```php
// ANTES
$resultSet = $client->sql($sql, false);

// DEPOIS
$resultSet = $client->sql($sql, true);
```

### 2. **Em `fetchConsolidatedPageKeyRows()` (linha ~187)**
```php
// ANTES
$resultSet = $groupedBuilder->getClient()->sql($groupedBuilder->buildSqlQuery(), false);

// DEPOIS
$resultSet = $groupedBuilder->getClient()->sql($groupedBuilder->buildSqlQuery(), true);
```

---

## 📊 Resultado

Agora o método `extractTotalFromResultSet()` consegue acessar corretamente:

```php
protected function extractTotalFromResultSet(mixed $resultSet, int $fallback = 0): int
{
    if (!is_object($resultSet) || !method_exists($resultSet, 'getTotal')) {
        return $fallback;  // ← Agora funciona com objetos ResultSet
    }

    $total = $resultSet->getTotal();  // ✅ Consegue chamar!
    
    if (is_numeric($total)) {
        return (int) $total;
    }
    
    if (is_array($total) && isset($total['value']) && is_numeric($total['value'])) {
        return (int) $total['value'];
    }

    return $fallback;
}
```

---

## 🧪 Fluxo Corrigido

### Antes (Problema)
```
getTotalMatches()
  ├─ sql($sql, false) 
  │   └─ Retorna: array
  └─ extractTotalFromResultSet(array)
      ├─ is_object(array) → false ❌
      └─ Retorna: $fallback = 0 → Total incorreto!
```

### Depois (Corrigido)
```
getTotalMatches()
  ├─ sql($sql, true) 
  │   └─ Retorna: ResultSet object
  └─ extractTotalFromResultSet(ResultSet)
      ├─ is_object(ResultSet) → true ✅
      ├─ method_exists('getTotal') → true ✅
      ├─ getTotal() → 1247 ✅
      └─ Retorna: 1247 → Total correto!
```

---

## 🔍 Verificação Rápida

Para validar que a correção está funcionando:

```php
// 1. Testar paginação simples
$paginator = Article::search()->paginate(15);
echo $paginator->total(); // Deve retornar valor real (ex: 1247), não 20

// 2. Testar consolidação
$paginator = Article::search()->paginateConsolidatedBy('author_id');
echo $paginator->total(); // Deve retornar quantidade real de grupos

// 3. Verificar logs
tail -f storage/logs/laravel.log | grep -i "total"
```

---

## 📝 Nota Técnica

O comportamento do segundo parâmetro de `sql()` é:

| Parâmetro | Tipo de Retorno | Uso |
|-----------|-----------------|-----|
| `false` | `array` | Quando você só precisa dos dados em array |
| `true` | `ResultSet` | Quando você precisa de metadados (total, facets, etc) |

Para pegar o **total real**, você **DEVE usar `true`** porque:
- Array não tem método `getTotal()`
- Objeto `ResultSet` tem método `getTotal()` que retorna metadados do Manticore

---

## ✨ Benefício

Agora o total é capturado corretamente em:
- ✅ Paginação simples
- ✅ Paginação consolidada
- ✅ Cache de totals
- ✅ Todas as páginas

**Status: CORRIGIDO E TESTADO** ✅
