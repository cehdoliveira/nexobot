Usar locks distintos por slot (`_0`, `_15`, `_30`, `_45`) para evitar que lock do slot 0 bloqueie o slot 15 em caso de execução longa.

### 2.4 BinanceRateLimitGuard

Criar `site/app/inc/lib/BinanceRateLimitGuard.php`:

```php
<?php
class BinanceRateLimitGuard {
    private const REDIS_KEY = 'binance:backoff_until';

    public static function isInBackoff(): bool {
        $redis = RedisCache::getInstance();
        $until = $redis->get(self::REDIS_KEY);
        return $until !== false && time() < (int)$until;
    }

    public static function recordRateLimit(int $retryAfterSeconds = 60): void {
        $redis = RedisCache::getInstance();
        $until = time() + $retryAfterSeconds;
        $redis->set(self::REDIS_KEY, $until, $retryAfterSeconds + 10);
    }
}
```

No início de `setup_controller::display()`, após a checagem de lock do grid, inserir:

```php
if (BinanceRateLimitGuard::isInBackoff()) {
    $this->log("⏸️ Backoff Binance ativo — ciclo pulado", 'WARNING', 'SYSTEM');
    return;
}
```

No método que trata erros de API (localizar `logBinanceError` ou equivalente), detectar 429/418:

```php
if (str_contains($msg, '429') || str_contains($msg, '418') || str_contains($msg, 'Too many requests')) {
    $retryAfter = 60; // default se header não disponível
    BinanceRateLimitGuard::recordRateLimit($retryAfter);
    $this->log("🚫 Rate limit Binance — backoff {$retryAfter}s ativado", 'ERROR', 'SYSTEM');
}
```

### 2.5 PERCENT_PRICE_BY_SIDE em extractFilters

Arquivo: setup_controller.php → `extractFilters`

Estender retorno para incluir o filtro quando presente:

```php
$pps = null;
foreach ($filtersList as $f) {
    if ($f['filterType'] === 'PERCENT_PRICE_BY_SIDE') {
        $pps = [
            'bidMultiplierUp'   => (float)$f['bidMultiplierUp'],
            'bidMultiplierDown' => (float)$f['bidMultiplierDown'],
            'askMultiplierUp'   => (float)$f['askMultiplierUp'],
            'askMultiplierDown' => (float)$f['askMultiplierDown'],
        ];
    }
}
return [$stepSize, $tickSize, $minNotional, $pps];
```

Atualizar todos os `list(...)` / destructuring do retorno de `extractFilters` para receber 4 elementos com `$pps = null` como fallback.

Em `placeBuyOrder`/`placeSellOrder`, antes de enviar — se `$pps !== null`, validar que o preço está dentro dos multiplicadores contra o preço médio ponderado. Se fora, ajustar ao limite e logar WARNING.

### 2.6 getExchangeInfo com cURL timeout + cache Redis

Localizar a chamada de `file_get_contents` em `getExchangeInfo`. Substituir por:

```php
$cacheKey = 'binance:exchangeInfo:' . $symbol;
$redis = RedisCache::getInstance();
$cached = $redis->get($cacheKey);
if ($cached !== false) return json_decode($cached, true);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
]);
$response = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    throw new \Exception("exchangeInfo HTTP $httpCode");
}
$redis->set($cacheKey, $response, 600);
return json_decode($response, true);
```

**Commit fase 2:** `"fase 2: LIMIT_MAKER, reancoragem, cron 15s, rate limit guard, filtros PPS, exchangeInfo cache"`

---

## Fase 3 — Fills reais e P&L verdadeiro (migration 023)

### 3.1 Migration 023

Criar `migrations/023_add_real_fill_data_to_orders.sql`:

```sql
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `commission`                   DECIMAL(20,8) DEFAULT NULL AFTER `cumulative_quote_qty`,
    ADD COLUMN IF NOT EXISTS `commission_asset`             VARCHAR(10)   DEFAULT NULL AFTER `commission`,
    ADD COLUMN IF NOT EXISTS `commission_usdc_equivalent`   DECIMAL(20,8) DEFAULT NULL AFTER `commission_asset`,
    ADD COLUMN IF NOT EXISTS `is_maker`                     TINYINT(1)    DEFAULT NULL AFTER `commission_usdc_equivalent`;
```

### 3.2 Persistir dados reais do fill

Em `syncOrdersWithBinance`, quando uma ordem muda para FILLED ou PARTIALLY_FILLED, consultar `myTrades` filtrado por `orderId`. Para cada trade: acumular `commission`. Se `commissionAsset !== 'USDC'`, converter via ticker cacheado (TTL 60s, chave `ticker:BNBUSDC` ou equivalente). Criar método:

```php
private function fetchAndStoreFillDetails(int $orderDbId, string $binanceOrderId, string $symbol): void
```

Persistir: `commission` (soma), `commission_asset`, `commission_usdc_equivalent` (convertida), `is_maker` (do primeiro trade da ordem), `cumulative_quote_qty` (do próprio getOrder).

### 3.3 calculatePairProfit com dados reais

Localizar `calculatePairProfit`. Atualizar para usar dados reais quando disponíveis:

```php
$buyValue  = (float)($buyOrder['cumulative_quote_qty']
             ?? ($buyOrder['executed_qty'] * $buyOrder['price']));
$sellValue = (float)($sellOrder['cumulative_quote_qty']
             ?? ($sellOrder['executed_qty'] * $sellOrder['price']));
$buyFee    = (float)($buyOrder['commission_usdc_equivalent']
             ?? $buyValue  * $this->getFeeRate('taker'));
$sellFee   = (float)($sellOrder['commission_usdc_equivalent']
             ?? $sellValue * $this->getFeeRate('taker'));
return $sellValue - $buyValue - $buyFee - $sellFee;
```

O `??` garante retrocompatibilidade para ordens antigas sem os novos campos.

**Commit fase 3:** `"fase 3: fills reais — commission, cumulative_quote_qty, is_maker, P&L baseado em dados Binance"`

---

## Fase 4 — Robustez operacional (migrations 024)

### 4.1 Lock atômico via UPDATE condicional

Localizar `acquireGridLock`. Substituir o padrão leitura→checagem→escrita pelo UPDATE atômico:

```php
$pdo  = /* obter instância PDO do projeto */;
$stmt = $pdo->prepare("
    UPDATE grids
    SET    is_processing   = 'yes',
           last_monitor_at = NOW()
    WHERE  idx = :id
      AND  (is_processing = 'no'
            OR last_monitor_at < (NOW() - INTERVAL :timeout MINUTE))
");
$stmt->execute([':id' => $gridId, ':timeout' => self::LOCK_TIMEOUT_MINUTES]);
return $stmt->rowCount() === 1;
```

Confirmar o nome correto da classe de acesso ao PDO (`grep -rn "class.*pdo\|new PDO\|getInstance" site/app/inc/lib/` antes de escrever).

### 4.2 Reconciliação periódica

Criar `private function reconcileWithBinance(int $gridId, string $symbol): void`.

Gatilho: contador Redis `nexobot:reconcile_counter:$gridId`, incrementado a cada ciclo. Executa quando `counter % 40 === 0` (~10min com cron 15s).

Lógica:
- Listar `getOpenOrders($symbol)` na Binance
- Listar ordens no banco com `status IN ('NEW','PARTIALLY_FILLED')` e `grids_id = $gridId`
- Binance-only com `clientOrderId` começando em `nx-$gridId-`: inserir no banco com `recovered_orphan = 1`
- Banco-only com `status = 'NEW'` há mais de 5min e inexistente na Binance: atualizar para `CANCELED`

Migration `migrations/024_add_reconcile_fields.sql`:

```sql
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `recovered_orphan` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_maker`;
```

### 4.3 Circuit breaker no stop-loss

Migration `migrations/024_add_reconcile_fields.sql` (mesma migration, adicionar):

```sql
ALTER TABLE `grids`
    ADD COLUMN IF NOT EXISTS `pending_shutdown_at`     DATETIME     DEFAULT NULL AFTER `trailing_stop_triggered_at`,
    ADD COLUMN IF NOT EXISTS `pending_shutdown_reason` VARCHAR(30)  DEFAULT NULL AFTER `pending_shutdown_at`;
```

Localizar `checkStopLoss`. Implementar delay de 10 minutos:

```php
if ($drawdown >= self::MAX_DRAWDOWN_PERCENT) {
    if (empty($grid['pending_shutdown_at'])) {
        // Primeiro disparo — armar o timer
        $this->setPendingShutdown($gridId, 'stop_loss');
        $this->log("⚠️ Drawdown {$drawdown}% — circuit breaker armado (aguardando 10min)", 'WARNING', 'RISK');
        return false;
    }
    $minutesWaiting = (time() - strtotime($grid['pending_shutdown_at'])) / 60;
    if ($minutesWaiting < 10) {
        $this->log("⏳ Circuit breaker aguardando ({$minutesWaiting}min/10min)", 'INFO', 'RISK');
        return false;
    }
    if ($drawdown >= (self::MAX_DRAWDOWN_PERCENT - 0.02)) {
        $this->log("🛑 Circuit breaker confirmado — executando shutdown", 'ERROR', 'RISK');
        return true; // dispara emergencyShutdown
    }
    // Recuperou — desarmar
    $this->clearPendingShutdown($gridId);
    $this->log("✅ Drawdown recuperou — circuit breaker desarmado", 'INFO', 'RISK');
    return false;
}
if (!empty($grid['pending_shutdown_at'])) {
    $this->clearPendingShutdown($gridId);
}
return false;
```

### 4.4 sellAssetAtMarket escalonado

Localizar `sellAssetAtMarket`. Substituir a ordem MARKET única por 3 tentativas:

```php
// Tentativa 1: LIMIT IOC agressiva bid - 1 tick
// Tentativa 2: LIMIT IOC bid - 5 ticks
// Tentativa 3: MARKET com quantidade restante
```

Implementar com loop de 3 iterações. Aguardar 10s entre tentativas consultando status. Logar quantidade vendida e quantidade restante em cada etapa.

### 4.5 Threshold de lucro proporcional

Localizar `isTradeViable`. Remover as constantes `MIN_PROFIT_USDC_LOW` e `MIN_PROFIT_USDC_HIGH`. Substituir a lógica por:

```php
$worstCaseFee = $capitalUsdc * ($this->getFeeRate('taker') * 2);
$minProfit    = max($capitalUsdc * 0.001, $worstCaseFee * 1.5);
// usar $minProfit no lugar de MIN_PROFIT_USDC_*
```

### 4.6 Reinvestimento em batch

Localizar onde `accumulatedProfit` é dividido por `GRID_LEVELS` para adicionar capital às ordens. Substituir por:

```php
private const REINVESTMENT_THRESHOLD = 10.0;

$extraCapital = 0;
if ($accumulatedProfit >= self::REINVESTMENT_THRESHOLD) {
    $extraCapital = $accumulatedProfit;
    $this->resetAccumulatedProfit($gridId);
}
```

Criar `private function resetAccumulatedProfit(int $gridId): void` que zera `grids.accumulated_profit_usdc` e registra em `grid_logs` com `event_type = 'profit_reinvested'`.

**Commit fase 4:** `"fase 4: lock atômico, reconciliação, circuit breaker, venda escalonada, threshold proporcional, reinvestimento batch"`

---

## Fase 5 — Dashboard e métricas (risco BAIXO — frontend isolado)

### 5.1 Migration 025: capital_snapshots

Criar `migrations/025_create_capital_snapshots.sql`:

```sql
CREATE TABLE IF NOT EXISTS `capital_snapshots` (
    `idx`                   INT          NOT NULL AUTO_INCREMENT,
    `created_at`            DATETIME     NOT NULL,
    `grids_id`              INT          NOT NULL,
    `total_capital_usdc`    DECIMAL(20,8) NOT NULL,
    `usdc_balance`          DECIMAL(20,8) NOT NULL,
    `btc_holding`           DECIMAL(20,8) NOT NULL,
    `btc_price`             DECIMAL(20,8) NOT NULL,
    `accumulated_spread_pnl` DECIMAL(20,8) NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_grids_created` (`grids_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Criar `site/app/inc/model/capital_snapshots_model.php` seguindo padrão DOL dos models existentes (copiar estrutura de outro model como referência — não inventar).

Em `monitorGrid`, após `updateCapitalTracking`, chamar `$this->maybeRecordCapitalSnapshot($gridId, $symbol)` — registra se último snapshot do grid for > 1h.

### 5.2 Endpoint gridMetrics

Arquivo: site_controller.php

Adicionar método público `gridMetrics($info)`:
- Ler `$_GET['grid_id']`
- Cache Redis TTL 60s chave `metrics:grid:{id}`
- Calcular e retornar JSON com: `spread_pnl_total`, `btc_mtm`, `total_capital_change`, `sharpe_ratio`, `sortino_ratio`, `max_drawdown`, `win_rate`, `profit_factor`, `fills_per_day`, `maker_ratio`
- Para Sharpe/Sortino: usar série de `capital_snapshots` horários, retornos `(cap_t - cap_{t-1}) / cap_{t-1}`, anualizar com `√(24×365)`
- Para `btc_mtm`: custo médio ponderado calculado sobre `grids_orders` BUYs com `status = FILLED`

Adicionar rota em `urls.php`: `'grid-metrics' => ['site_controller', 'gridMetrics']`

Adicionar rota em `urls.php`: `'grid-capital-history' => ['site_controller', 'gridCapitalHistory']`

Adicionar método `gridCapitalHistory($info)`: retorna últimos 30 dias de `capital_snapshots` agrupados por hora (MAX por hora).

### 5.3–5.7 Dashboard

Arquivo: `ui/page/dashboard.php` e `dashboardController.js`

Antes de editar: ler o arquivo completo para mapear a estrutura atual de cards e tabelas.

**5.3** — Adicionar fileira de 3 cards após a fileira de cards existente. Dados via fetch ao endpoint `grid-metrics`. Atualizar a cada 60s via AlpineJS `setInterval`. Cards:
- Spread P&L — verde/vermelho conforme sinal. Subtítulo: "Lucro real do grid (após fees)"
- BTC Mark-to-Market — azul. Subtítulo: "Variação do BTC acumulado"
- Capital Total — neutro. Subtítulo: "Variação vs capital inicial"

**5.4** — Seção collapsible (Bootstrap collapse) abaixo dos cards com tabela de métricas: Sharpe, Sortino, Max Drawdown %, Win Rate %, Profit Factor, Fills/dia, Maker Ratio %. Cada header com `data-bs-toggle="tooltip"` e `title=""` explicando em 1 linha.

**5.5** — Adicionar `<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>` antes do `</body>`. Canvas com 3 séries (total capital linha sólida, USDC tracejada, BTC value tracejada). Dados via `grid-capital-history`. Sem grid visual excessivo, tooltip com valor exato no hover.

**5.6** — Na tabela de ordens existente, adicionar coluna "Execução" com badges: `is_maker=1` → badge verde "MAKER"; `is_maker=0` → badge laranja "TAKER"; `null` → "—".

**5.7** — Em `ui/page/config.php` (ler o arquivo antes para confirmar que existe e entender a estrutura de formulário). Adicionar toggle "BNB Burn ativo" e inputs "Fee Maker (%)" / "Fee Taker (%)". Submit via AJAX que chama endpoint existente de saveConfig ou cria endpoint `saveGridConfig` em site_controller que persiste via `AppSettings::set('binance', ...)`.

**Commit fase 5:** `"fase 5: snapshots de capital, métricas Sharpe/Sortino, gráfico de capital, badges MAKER/TAKER, configurador de fees"`

---

## Entregáveis finais (após fase 5 aprovada)

1. Criar `RELATORIO_MUDANCAS.md` na raiz com tabela: melhoria | arquivo | trecho alterado | impacto financeiro esperado
2. Criar `CHECKLIST_DEPLOY.md` com passos sequenciais para prod: ativar BNB Burn na Binance → aplicar migrations 022–025 → promover crontab → smoke test 1h
3. Atualizar `README.md` adicionando seção `## Otimizações 2026-Q2` com link para `RELATORIO_MUDANCAS.md`

---

## Como começar

1. Confirme acesso ao repositório: `find site/app/inc/controller -name "setup_controller.php"`
2. Rode `git checkout -b otimizacoes-financeiras-2026q2`
3. Leia as primeiras 100 linhas de `setup_controller.php` para confirmar constantes atuais
4. Inicie pela fase 1.1 — leia a função `slideGrid` completa antes de qualquer edição
5. NUNCA combine fases no mesmo commit
6. NUNCA pule uma fase
7. PARE após cada fase e aguarde confirmação explícita
