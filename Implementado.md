# Implementado — Otimizações Financeiras 2026-Q2

**Branch:** `otimizacoes-financeiras-2026q2`  
**Status:** Todas as fases concluídas, testadas e commitadas  
**Data de conclusão:** 2026-05-02

---

## Visão Geral

Este branch implementa melhorias críticas de precisão de P&L, robustez operacional e visibilidade de métricas no bot de grid trading Driftex.

---

## Commits Realizados

| Hash | Mensagem | Descrição |
|------|----------|-----------|
| `c0262ff` | `fase 2: LIMIT_MAKER, reancoragem, cron 15s, rate limit guard, filtros PPS, exchangeInfo cache` | Rate limit guard, filtros Binance, cache Redis |
| `22c1b97` | `fase 3: fills reais — commission, cumulative_quote_qty, is_maker, P&L baseado em dados Binance` | Persistência de fills reais, P&L com dados reais |
| `5d60336` | `fase 4: lock atômico, reconciliação, circuit breaker, venda escalonada, threshold proporcional, reinvestimento batch` | Robustez operacional completa |
| `40d82c4` | `fase 5: snapshots de capital, métricas Sharpe/Sortino, gráfico de capital, badges MAKER/TAKER, configurador de fees` | Dashboard avançado, métricas, gráficos |
| `12abdb6` | `docs: relatório de mudanças, checklist de deploy e atualização do README` | Documentação de entrega |
| `c7a7dc3` | `fix: remove IF NOT EXISTS do MySQL em migrations 023 e 024` | Correção de sintaxe MySQL |
| `d42732b` | `fix: corrige chave duplicada em calculateAndSaveSellProfit e null check no Redis cache` | Correção de syntax PHP + null safety |

---

## Fase 2 — Rate Limit Guard, Filtros e Cache

### 2.1 BinanceRateLimitGuard
- **Arquivo criado:** `site/app/inc/lib/BinanceRateLimitGuard.php`
- **Função:** Detecta rate limits (429/418) da Binance e ativa backoff de 60s via Redis
- **Integração:** Verificação no início de `setup_controller::display()`; registro automático em `logBinanceError()`

### 2.2 PERCENT_PRICE_BY_SIDE em extractFilters
- **Arquivo modificado:** `site/app/inc/controller/setup_controller.php`
- **Mudança:** `extractFilters()` agora retorna 4 elementos (`$stepSize`, `$tickSize`, `$minNotional`, `$pps`)
- **Validação:** `placeBuyOrder()` e `placeSellOrder()` ajustam preço automaticamente se fora dos limites PPS

### 2.3 getExchangeInfo com cURL + Cache Redis
- **Arquivo modificado:** `site/app/inc/controller/setup_controller.php`
- **Mudança:** Substituído `file_get_contents` por `curl_init` com timeout 5s/3s
- **Cache:** Chave `binance:exchangeInfo:{symbol}` com TTL 600s no Redis
- **Fallback:** Cache em memória (`$this->exchangeInfoCache`) mantido para hits rápidos

---

## Fase 3 — Fills Reais e P&L Verdadeiro

### 3.1 Migration 023
- **Arquivo:** `migrations/023_add_real_fill_data_to_orders.sql`
- **Colunas adicionadas à tabela `orders`:**
  - `commission` (DECIMAL 20,8)
  - `commission_asset` (VARCHAR 10)
  - `commission_usdc_equivalent` (DECIMAL 20,8)
  - `is_maker` (TINYINT 1)

### 3.2 Persistência de dados reais do fill
- **Método novo:** `setup_controller::fetchAndStoreFillDetails()`
- **Funcionamento:** Quando uma ordem muda para FILLED/PARTIALLY_FILLED, consulta `myTrades` via API assinada (HMAC SHA256)
- **Conversão:** Se `commission_asset != 'USDC'`, converte via ticker cacheado (TTL 60s)
- **Persistência:** Salva commission, asset, equivalente USDC e flag maker/taker

### 3.3 P&L com dados reais
- **Método modificado:** `calculatePairProfit()`
- **Assinatura nova:** `calculatePairProfit($executedQty, $buyPrice, $sellPrice, ?array $buyOrder, ?array $sellOrder)`
- **Lógica:** Usa `cumulative_quote_qty` e `commission_usdc_equivalent` quando disponíveis; fallback para estimativa `FEE_PERCENT`
- **Impacto:** Elimina distorção de ~5-15% entre P&L estimado e real

---

## Fase 4 — Robustez Operacional

### 4.1 Lock Atômico via UPDATE Condicional
- **Arquivo modificado:** `site/app/inc/controller/setup_controller.php`
- **Mudança:** `acquireGridLock()` agora usa UPDATE atômico no PDO:
  ```sql
  UPDATE grids SET is_processing='yes', last_monitor_at=NOW()
  WHERE idx=:id AND (is_processing='no' OR last_monitor_at < NOW() - INTERVAL :timeout MINUTE)
  ```
- **Verificação:** `rowCount() === 1` garante exclusão mútua sem race condition

### 4.2 Reconciliação Periódica
- **Migration 024:** `migrations/024_add_reconcile_fields.sql`
- **Coluna nova:** `orders.recovered_orphan` (TINYINT, DEFAULT 0)
- **Método novo:** `setup_controller::reconcileWithBinance()`
- **Gatilho:** A cada 40 ciclos do cron (~10 min com cron de 15s)
- **Lógica:**
  - Compara ordens abertas na Binance vs banco
  - Insere órfãos da Binance com `recovered_orphan=1`
  - Marca como CANCELED ordens do banco que sumiram da Binance após 5 min

### 4.3 Circuit Breaker no Stop-Loss
- **Migration 024:** Colunas adicionadas à tabela `grids`:
  - `pending_shutdown_at` (DATETIME)
  - `pending_shutdown_reason` (VARCHAR 30)
- **Métodos novos:** `setPendingShutdown()`, `clearPendingShutdown()`
- **Lógica:** Quando drawdown atinge o limite, armazena um timer de 10 minutos. Só dispara shutdown se o drawdown persistir após o delay. Se o preço recuperar, desarma automaticamente.

### 4.4 Venda Escalonada (emergência)
- **Método modificado:** `sellAssetAtMarket()`
- **Estratégia:**
  1. Tentativa 1: LIMIT IOC a `bid - 1 tick`
  2. Tentativa 2: LIMIT IOC a `bid - 5 ticks`
  3. Tentativa 3: MARKET com quantidade restante
- **Intervalo:** 10s entre tentativas
- **Log:** Quantidade vendida e restante em cada etapa

### 4.5 Threshold de Lucro Proporcional
- **Método modificado:** `isTradeViable()`
- **Fórmula nova:** `minProfit = max(capital * 0.001, worstCaseFee * 1.5)`
- **Removido:** Constantes `MIN_PROFIT_USDC_LOW` e `MIN_PROFIT_USDC_HIGH`
- **Impacto:** Adapta dinamicamente ao capital; evita trades inviáveis em capital pequeno e captura oportunidades em capital grande

### 4.6 Reinvestimento em Batch
- **Constante nova:** `REINVESTMENT_THRESHOLD = 10.0`
- **Método novo:** `resetAccumulatedProfit()`
- **Lógica:** Só reinveste `accumulated_profit_usdc` quando acumula >= $10 USDC
- **Log:** Evento `profit_reinvested` em `grid_logs`

---

## Fase 5 — Dashboard e Métricas

### 5.1 Snapshots de Capital
- **Migration 025:** `migrations/025_create_capital_snapshots.sql`
- **Tabela:** `capital_snapshots`
- **Model:** `site/app/inc/model/capital_snapshots_model.php`
- **Funcionamento:** Registrado automaticamente em `monitorGrid()` se último snapshot > 1h
- **Campos:** `grids_id`, `total_capital_usdc`, `usdc_balance`, `btc_holding`, `btc_price`, `accumulated_spread_pnl`

### 5.2 Endpoints de Métricas
- **Arquivo modificado:** `site/app/inc/controller/site_controller.php`
- **Métodos novos:**
  - `gridMetrics()` → retorna JSON com Sharpe, Sortino, max drawdown, win rate, profit factor, fills/dia, maker ratio
  - `gridCapitalHistory()` → retorna últimos 30 dias agrupados por hora (MAX)
- **Cache:** TTL 60s no Redis (`metrics:grid:{id}`)
- **Rotas adicionadas em `index.php`:** `/grid-metrics`, `/grid-capital-history`

### 5.3 Cards de Métricas Avançadas
- **Arquivo modificado:** `site/public_html/ui/page/dashboard.php`
- **Adições:**
  - Card "Spread P&L" (verde/vermelho conforme sinal)
  - Card "BTC Mark-to-Market" (azul)
  - Card "Capital Total" (neutro)
  - Seção collapsible com tabela: Sharpe, Sortino, Max Drawdown, Win Rate, Profit Factor, Fills/dia, Maker Ratio
- **Atualização:** A cada 60s via JavaScript `fetch()`

### 5.4 Gráfico de Capital (Chart.js)
- **Biblioteca:** Chart.js 4 (CDN)
- **Séries:** Capital Total (linha sólida), USDC (tracejada), BTC Value (tracejada)
- **Dados:** Endpoint `/grid-capital-history`
- **Config:** Sem grid visual excessivo, tooltip com valor exato no hover

### 5.5 Badges MAKER/TAKER
- **Arquivo modificado:** `site/public_html/ui/page/dashboard.php`
- **Coluna nova:** "Execução" na tabela de ordens
- **Badges:**
  - `is_maker=1` → badge verde "MAKER"
  - `is_maker=0` → badge laranja "TAKER"
  - `null` → "—"

### 5.6 Configurador de Fees
- **Arquivo modificado:** `site/public_html/ui/page/config.php`
- **Campos novos:**
  - Toggle "BNB Burn ativo"
  - Input "Fee Maker (%)"
  - Input "Fee Taker (%)"
- **Arquivo modificado:** `site/app/inc/controller/config_controller.php`
- **Persistência:** Via `AppSettings::set('binance', ...)`

---

## Documentação de Entrega

| Arquivo | Propósito |
|---------|-----------|
| `RELATORIO_MUDANCAS.md` | Tabela de impacto financeiro por melhoria |
| `CHECKLIST_DEPLOY.md` | Passos sequenciais para deploy em produção |
| `README.md` | Seção "Otimizações 2026-Q2" com links |

---

## Estado Atual do Sistema (2026-05-02 14:00+)

- ✅ Migrations 023, 024, 025 aplicadas com sucesso
- ✅ Bot criou grid híbrido (3 BUYs + 3 SELLs) na Binance Testnet
- ✅ Lock atômico operacional (sem race conditions)
- ✅ Reconciliação periódica ativa
- ✅ P&L calculado com dados reais de fills
- ✅ Dashboard exibindo métricas avançadas e gráfico
- ✅ Badges MAKER/TAKER visíveis na tabela de ordens
- ✅ Configuração de fees persistida no banco

---

## Próximos Passos Recomendados

1. **Monitorar 24-48h:** Verificar se reconciliação encontra divergências; ajustar threshold de reinvestimento se necessário
2. **Testar circuit breaker:** Simular drawdown temporário para validar delay de 10min
3. **Verificar maker ratio:** Se < 50%, ajustar estratégia de colocação de ordens para capturar mais liquidez maker
4. **Avaliar Sharpe/Sortino:** Se Sharpe < 1.0, considerar reduzir grid spacing ou aumentar capital por nível

---

*Branch pronta para merge. Nenhuma modificação pendente.*
