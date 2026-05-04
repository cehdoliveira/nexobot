# Comportamento Hipotético do Bot Driftex
## Análise baseada em `setup_controller.php`

---

## Parâmetros fixos do bot (constantes do código)

| Parâmetro | Valor | Significado |
|---|---|---|
| `GRID_LEVELS` | 6 | 3 BUY + 3 SELL |
| `GRID_SPACING_PERCENT` | 1% | Espaçamento entre níveis |
| `GRID_RANGE_PERCENT` | ±5% | Range estrutural visual |
| `INITIAL_BTC_ALLOCATION` | 50% | Metade do capital vira BTC na criação |
| `CAPITAL_ALLOCATION` | 95% | Usa 95% do USDC disponível |
| `MIN_TRADE_USDC` | $11 | Mínimo por ordem |
| `MAX_DRAWDOWN_PERCENT` | 20% | Limite de perda → Stop-Loss |
| `TRAILING_STOP_PERCENT` | 15% | Queda do pico → Trailing Stop |
| `MIN_PROFIT_TO_ACTIVATE_TRAILING` | 10% | Lucro mínimo para armar trailing |
| `FEE_PERCENT` | 0,1% | Taxa por operação (taker) |
| `REINVESTMENT_THRESHOLD` | $10 | Lucro acumulado antes de reinvestir |
| `GRID_SLIDE_MAX_ITERATIONS` | 6 | Slides máximos por ciclo CRON |
| CRON | a cada ~1 min | Frequência de execução |

---

## Estado Inicial da Simulação

**Capital inicial:** $1.000 USDC, 0 BTC  
**Preço de entrada do BTC:** $95.000

### Passo 1 — Compra inicial de BTC

O bot detecta que não há BTC suficiente para as ordens SELL. Executa compra a mercado:

```
targetBtcValue = $1.000 × 50% = $500
targetBtcQty   = $500 / $95.000 = 0,005263 BTC

Bot compra 0,005263 BTC @ ~$95.000
Resultado: $500 USDC restante + 0,005263 BTC
```

### Passo 2 — Criação do Grid Híbrido

```
capital_por_nível = $1.000 / 6 = $166,67
USDC para BUYs    = $500 ÷ 3 níveis = $166,67 por BUY
BTC  para SELLs   = 0,005263 ÷ 3 níveis = 0,001754 BTC por SELL
```

### Estrutura do Grid em $95.000

```
─────────────────────────────────────────────────────────
  Nível  │  Lado  │  Preço     │  Quantidade         │ Valor
─────────────────────────────────────────────────────────
  S3     │  SELL  │ $97.850    │ 0,001754 BTC        │ ~$171,62
  S2     │  SELL  │ $96.900    │ 0,001754 BTC        │ ~$169,95
  S1     │  SELL  │ $95.950    │ 0,001754 BTC        │ ~$168,29
─────────────────────────────────────────────────────────
         │ ★ Preço atual: $95.000 ★
─────────────────────────────────────────────────────────
  B1     │  BUY   │ $94.050    │ $166,67 USDC lock   │ ~0,001772 BTC
  B2     │  BUY   │ $93.100    │ $166,67 USDC lock   │ ~0,001790 BTC
  B3     │  BUY   │ $92.150    │ $166,67 USDC lock   │ ~0,001809 BTC
─────────────────────────────────────────────────────────
```

Capital snapshot inicial:
- USDC livre: $0 (tudo travado em BUYs ou convertido em BTC)
- BTC livre: 0 (tudo em SELLs ou USDC nas BUYs)
- **Capital total marcado:** $1.000

---

## Lucro por ciclo completo (BUY→SELL)

Para um ciclo B1 → SELL pareada:

```
qty comprada    = $166,67 ÷ $94.050 = 0,001772 BTC
buyValue        = 0,001772 × $94.050 = $166,65
sellPrice       = $94.050 × 1,01   = $94.990,50
sellValue       = 0,001772 × $94.990,50 = $168,33

fee compra      = $166,65 × 0,001 = $0,167
fee venda       = $168,33 × 0,001 = $0,168

Lucro líquido   = $168,33 − $166,65 − $0,167 − $0,168 = $1,34
ROI por ciclo   = $1,34 ÷ $166,67 = 0,80%
```

Para ciclos dos SELLs iniciais (S1, S2, S3 — BTC comprado ao criar grid):

```
S1: qty = 0,001754, custo do BTC = $95.000, venda = $95.950
  lucro = (0,001754×$95.950) − (0,001754×$95.000) − fees
        = $168,30 − $166,63 − $0,335 = $1,34
```

Todos os ciclos geram ~**$1,34 de lucro líquido**, independente do nível.

---

## Cenário 1 — Mercado lateral: preço oscila dentro do grid

**Preço de referência:** $95.000 ± $3.000 (oscila livremente entre $92.150 e $97.850)

### Linha do tempo com valores

```
T+0h   Preço: $95.000 → Grid criado. Estado acima.

T+2h   Preço cai para $94.050
       ↳ B1 executa (FILLED): bot comprou 0,001772 BTC @ $94.050
       ↳ CRON detecta → cria SELL pareada @ $94.050×1,01 = $94.990,50
       ↳ Estrutura: B1 aberto→fechado, SELL-B1 aguardando

T+4h   Preço sobe para $94.990
       ↳ SELL-B1 executa: 0,001772 BTC vendidos @ $94.990,50
       ↳ Lucro: +$1,34. accumulated_profit = $1,34
       ↳ CRON: cria novo BUY @ $94.990,50×0,99 = $94.040 (~B1 reciclado)
       ↳ Capital disponível: ≈ $166,67 USDC (da SELL)

T+6h   Preço cai para $93.100
       ↳ B2 executa: 0,001790 BTC @ $93.100
       ↳ CRON cria SELL-B2 @ $93.100×1,01 = $94.031
       ─ NOTA: as duas SELLs (SELL-B1 e SELL-B2) ficam separadas por ~$10

T+8h   Preço sobe para $94.031 e depois $94.040
       ↳ SELL-B2 executa: lucro +$1,34
       ↳ SELL-B1-reciclada executa: lucro +$1,34
       ↳ "Modo Violão": 2 SELLs executaram no mesmo ciclo CRON
         USDC disponível ÷ 2 = 2 BUYs criadas com USDC igualmente dividido
       ↳ accumulated_profit = $1,34 + $1,34 + $1,34 = $4,02

T+10h  Preço sobe para $95.950
       ↳ S1 executa: 0,001754 BTC vendidos @ $95.950
       ↳ Lucro: +$1,34. accumulated_profit = $5,36
       ↳ CRON cria BUY @ $95.950×0,99 = $94.990

T+14h  Após 8 ciclos completos:
       ↳ 8 × $1,34 = $10,72 de lucro acumulado
       ↳ accumulated_profit ≥ $10 → REINVESTIMENTO BATCH
         $10,72 adicionado ao capital base das próximas BUYs
         capital_per_level aumenta ligeiramente
```

### Estado após 30 ciclos (~48-72h de oscilação intensa)

```
Lucro bruto estimado: 30 × $1,34 = $40,20
Capital total: $1.040,20
ROI acumulado: +4,02%
Drawdown máximo atingido: ~2-3% (oscilações normais)
Trailing Stop: NÃO armado (precisa de +10% de lucro)
Stop-Loss: NÃO ativado (precisa de -20%)
```

### Comportamento visual

```
$97.850 ──── S3 (aguardando)
$96.900 ──── S2 (aguardando)
$95.950 ──── S1 (alternando: executada → BUY reativa criada)
─ ─ ─ ─ ─ ─ preço oscilando (capturando lucro a cada toque)
$94.050 ──── B1 (alternando: executada → SELL reativa criada)
$93.100 ──── B2 (aguardando)
$92.150 ──── B3 (aguardando)
```

---

## Cenário 2 — Alta forte: BTC sobe $95.000 → $112.000

**Movimento:** +17,9% em ~5-7 dias. Impulso constante para cima.

### Fase 1 — SELLs executando em sequência ($95.000 → $97.850)

```
T+0h   Preço: $95.000 → Grid inicial (conforme acima)

T+6h   Preço: $95.950 → S1 executa
       ↳ 0,001754 BTC vendidos. USDC recebido: $168,30
       ↳ Lucro: +$1,34
       ↳ BUY criada @ $94.990 com ~$166,67 USDC

T+10h  Preço: $96.900 → S2 executa
       ↳ USDC recebido: $169,95
       ↳ Lucro: +$1,34
       ↳ BUY criada @ $95.940 com ~$166,67 USDC

T+14h  Preço: $97.850 → S3 executa
       ↳ USDC recebido: $171,62
       ↳ Lucro: +$1,34
       ↳ BUY criada @ $96.890 com ~$166,67 USDC

Estado em $97.850 — todas as 3 SELLs iniciais executaram:
  Lucro acumulado: 3 × $1,34 = $4,02
  BUYs ativas:
    B3 @ $92.150 (original)
    B2 @ $93.100 (original)
    B1 @ $94.050 (original)
    BUY-S1 @ $94.990
    BUY-S2 @ $95.940
    BUY-S3 @ $96.890   ← highest buy
  SELLs ativas: nenhuma
```

### Fase 2 — Slide UP ativo ($97.850 → $110.000)

O preço ultrapassou **$96.890** (a BUY mais alta). Condição de slide:
`currentPrice ($97.850) > highestBuy ($96.890)` → **Slide UP dispara**

```
SLIDE UP #1:
  Cancela B3 @ $92.150 (mais distante/mais baixa)
  USDC liberado = 0,001809 BTC × $92.150 × (qty) = ~$166,67 USDC
  Nova BUY criada @ $96.890 × 1,01 = $97.859

SLIDE UP #2 (mesmo ciclo CRON — limite 6 iterações):
  Se preço ainda > $97.859:
  Cancela B2 @ $93.100
  Nova BUY @ $97.859 × 1,01 = $98.838

... e assim sucessivamente
```

À medida que o preço sobe, o grid "escala" junto:

```
Preço $100.000:
  BUYs ativas ficam em: ~$99.000 / $98.000 / $97.000 (aproximado)
  O bot acompanhou a alta cancelando as BUYs mais distantes
  e criando novas mais próximas ao preço corrente
  USDC: reciclado, não gasto — apenas realocado em cima

Preço $104.500 (+10% sobre $95.000):
  ★ TRAILING STOP ARMADO ★
  peak_capital ≥ $1.100 (+10% de $1.000)
  Bot continua operando normalmente
  E-mail de alerta enviado: "Trailing Stop armado"

Preço $110.000 (+15.8% sobre $95.000):
  SELLs reativas vão executando (criadas pelos BUYs que estão preenchendo)
  Lucro acumulado estimado: ~$30-40 (muitos ciclos completados na alta)
  peak_capital: ~$1.030-$1.040 (BTC valorizado + lucros)
  O bot está com muitas BUYs travando USDC em preços altos
```

### Fase 3 — Reversão da alta ($110.000 → $92.500)

Queda de 15,9% a partir do pico em ~$110.000.

```
peak_capital = $1.040 (estimado)
drop_from_peak = ($1.040 − $current_capital) / $1.040

Quando currentCapital cair para:
  $1.040 × (1 − 0.15) = $884

Mas espera: o capital total inclui BTC a preço de mercado.
Se o preço cai de $110.000 → $92.500 (~−16%):
  BTC que o bot detém perde valor
  USDC locked em BUYs a $108.000, $107.000 etc. (que não executaram) = USDC imobilizado
  currentCapital = USDC_livre + BTC_total × $92.500

Cálculo estimado:
  0,002 BTC livre × $92.500 = $185
  USDC nas BUYs (locked) = $650 (BUYs em ~$100-$108k não executadas)
  USDC livre = $50
  currentCapital ≈ $185 + $650 + $50 = $885

peak_capital = $1.040
dropFromPeak = ($1.040 − $885) / $1.040 = 14,9% → PRÓXIMO ao limite de 15%!

Se continuar caindo mais um pouco:
  ★ TRAILING STOP ACIONA ★
  Lucro preservado: $885 − $1.000 = −$115 (perda — o trailing não garantiu lucro
  porque o peak foi formado por valorização do BTC, não por lucro realizado)
```

### Estado final — Trailing Stop acionado

```
EMERGENCY SHUTDOWN executado:
1. Todas as ordens abertas canceladas na Binance
   (BUYs a $100k-$108k canceladas — USDC retorna ao saldo livre)
2. Todo BTC livre vendido a mercado @ ~$92.500
3. Grid marcado como 'stopped', trailing_stop_triggered = 'yes'
4. CRON não recria grid automaticamente
5. Requer clique manual em "Religar Bot" pelo usuário

Capital final estimado: ~$885-$920 USDC
Perda vs inicial: −8% a −11,5%

ATENÇÃO: a perda ocorre porque o bot acumulou BTC enquanto o preço subia
(BUYs a preços altos que nunca executaram, ficando com USDC travado
próximo ao topo), e o trailing capturou a reversão.
```

### Resumo visual do Cenário 2

```
$112.000  ─ Pico de mercado
$110.000  ─ Trailing Stop armado (capital +10%)
                      ↓ reversão
$97.850   ─ Todas as SELLs iniciais executaram aqui
$95.000   ─ ★ Criação do grid
$92.500   ─ Trailing Stop acionado (−15% do pico de capital)
             Bot para. USDC recuperado.

Lucro real capturado: ~$30-40 (ciclos completados na subida)
Perda na reversão: −$80-115 (BTC depreciado nas BUYs altas)
Resultado líquido: estimado entre −$80 e −$40
```

---

## Cenário 3 — Queda forte: BTC cai $95.000 → $72.000

**Movimento:** −24,2% em ~3-5 dias. Queda rápida sem recuperação.

### Fase 1 — BUYs executando em sequência ($95.000 → $92.150)

```
T+0h   Grid criado @ $95.000

T+4h   Preço: $94.050 → B1 executa
       ↳ Comprou 0,001772 BTC @ $94.050 → gasta $166,67 USDC
       ↳ SELL reativa criada @ $94.990 (aguardando recuperação)

T+8h   Preço: $93.100 → B2 executa
       ↳ Comprou 0,001790 BTC @ $93.100 → gasta $166,67 USDC
       ↳ SELL reativa criada @ $94.031

T+12h  Preço: $92.150 → B3 executa
       ↳ Comprou 0,001809 BTC @ $92.150 → gasta $166,67 USDC
       ↳ SELL reativa criada @ $93.071

Estado em $92.150 — todas as 3 BUYs originais executaram:
  USDC disponível: ~$0 (tudo usado em compras)
  BTC acumulado: 0,005263 (inicial) + 0,001772 + 0,001790 + 0,001809 = 0,010634 BTC
  SELLs ativas: S3 @ $97.850, S2 @ $96.900, S1 @ $95.950 (iniciais)
                + SELL-B1 @ $94.990, SELL-B2 @ $94.031, SELL-B3 @ $93.071 (reativas)
  Capital marcado: 0,010634 BTC × $92.150 + $0 USDC = $979,97 → drawdown de ~2%
```

### Fase 2 — Slide DOWN ativo ($92.150 → $80.000)

O preço está em $92.150, que é **abaixo da SELL mais próxima** ($93.071).
Condição: `currentPrice ($92.150) < lowestSell ($93.071)` → **Slide DOWN dispara**

```
SLIDE DOWN #1:
  Cancela SELL mais distante (maior preço): S3 @ $97.850
  BTC liberado: 0,001754 BTC (ficava travado no topo)
  original_cost_price registrado: $97.850 (ou preço de criação)
  Nova SELL criada @ $93.071 × (1−0,01) = $92.140
  ↳ BTC reciclado 0,001754 @ $92.140 (nível deslizante)

SLIDE DOWN #2:
  Cancela S2 @ $96.900
  Nova SELL @ $92.140 × 0,99 = $91.219

SLIDE DOWN #3:
  Cancela S1 @ $95.950
  Nova SELL @ $91.219 × 0,99 = $90.307

(Até 6 iterações por ciclo CRON)
```

À medida que o preço cai:

```
Preço $88.000:
  As SELLs foram recicladas várias vezes
  Estão agrupadas em torno de $87.000-$91.000
  BTC acumulado: ainda ~0,010 BTC (aguardando SELLs executarem)
  USDC: praticamente zero (tudo convertido em BTC nas BUYs)
  Capital marcado: 0,010 BTC × $88.000 = $880
  Drawdown: ($1.000 − $880) / $1.000 = 12% → MONITORANDO

Preço $80.000 (−15,8% de $95.000):
  Capital marcado: 0,010634 BTC × $80.000 = $850,72
  Drawdown: ($1.000 − $850,72) / $1.000 = 14,9%
  ★ Ainda abaixo de 20% — bot continua ★

Preço $76.000 (−20% de $95.000):
  Capital marcado: 0,010634 BTC × $76.000 = $808,18
  Drawdown: ($1.000 − $808,18) / $1.000 = 19,18% → PERIGOSO

Preço $75.200 (−20,8% de $95.000):
  Capital marcado: 0,010634 BTC × $75.200 = $799,68
  Drawdown: ($1.000 − $799,68) / $1.000 = 20,03% ≥ 20%
  
  ★ CIRCUIT BREAKER ARMADO ★
  O bot registra pending_shutdown_at = agora
  Aguarda 10 minutos verificando se o drawdown persiste
```

### Fase 3 — Circuit Breaker (10 minutos de confirmação)

```
T+0min (drawdown 20%):  Circuit breaker armado. Bot NÃO para ainda.
T+3min: Preço $74.800 → drawdown 20,4%. Aguardando.
T+7min: Preço $75.100 → drawdown 20,1%. Aguardando.
T+10min: Preço $74.500 → drawdown 20,5%. Confirmado!
  drawdown ≥ (20% − 2% tolerância) = 18% ✓

★★★ STOP-LOSS ACIONADO ★★★
```

### Fase 4 — Emergency Shutdown

```
SEQUÊNCIA DE ENCERRAMENTO:
1. Cancelar todas as ordens na Binance
   → SELLs ativas canceladas (BTC liberado dessas ordens)
   → BUYs (se houver alguma ativa) canceladas (USDC liberado)

2. Vender TODO o BTC a mercado:
   BTC total: ~0,010634 BTC
   Preço de venda: ~$74.500 (mercado a mercado, slippage incluso)
   USDC recebido: 0,010634 × $74.500 = ~$792,23
   
   ─ Bot tenta LIMIT IOC agressivo primeiro (bid-1tick)
   ─ Se falhar, usa MARKET order

3. Grid marcado: status = 'stopped', stop_loss_triggered = 'yes'

4. CRON verifica: stop-loss acionado → NÃO recria o grid
   Necessário clique em "Religar Bot" manualmente

Capital final: ~$792-$800 USDC
Perda vs capital inicial ($1.000): ~$200-$208
Perda percentual real: ~20-21%
```

### Detalhamento de por que a perda é ~20%

```
Capital inicial: $1.000
  → $500 USDC (3 BUYs) + $500 em BTC (3 SELLs @ $95.000)

Trajetória de queda:
  SELLs deslizantes tentaram acompanhar o preço para baixo
  BTC das SELLs originais foi reciclado em SELLs mais abaixo
  BUYs executaram comprando BTC mais caro (máx @ $94.050) do que vendemos no final (~$74.500)

Perda mark-to-market a cada -$1.000 de BTC:
  Cada $1.000 de queda no preço do BTC:
  0,010634 BTC × $1.000 = $10,63 de perda no capital marcado
  Queda total de $95.000 → $74.500 = $20.500 de queda
  Perda: 0,010634 × $20.500 = $217,90 (aprox.)
  Mas compensado parcialmente pelos lucros iniciais (~$5)
  Resultado: perda líquida de ~$213, ou seja ~21,3%
  ↳ Stop-loss aciona em 20% exatamente para cortar antes disso
```

### Resumo visual do Cenário 3

```
$97.850  ─ SELLs iniciais (nunca executaram — preço só caiu)
$95.000  ─ ★ Criação do grid
$94.050  ─ B1 executou → comprou BTC
$93.100  ─ B2 executou → comprou BTC
$92.150  ─ B3 executou → comprou BTC
$91.000  ─ SELLs deslizantes (Slide DOWN ativo)
$88.000  ─ Mais slides, mais BTC acumulado
$80.000  ─ Drawdown ~14,9% — bot monitorando
$75.200  ─ ★ Drawdown 20% — Circuit Breaker ARMADO
$74.500  ─ ★★ STOP-LOSS ACIONADO (10min depois) ★★
             Tudo vendido a mercado
             Capital final: ~$792 USDC
             Perda: ~$208 (−20,8%)
```

---

## Tabela Comparativa dos 3 Cenários

| | Cenário 1 — Lateral | Cenário 2 — Alta Forte | Cenário 3 — Queda Forte |
|---|---|---|---|
| Movimento | ±3% oscilação | +18% em 5 dias | −24% em 5 dias |
| Mecânica dominante | BUY→SELL ciclos | Slide UP + SELLs executando | Slide DOWN + BUYs executando |
| Lucro/Perda | +$40 em 30 ciclos | −$40 a −$80 (depende do pico) | −$200 a −$210 |
| Proteção ativa | Nenhuma | Trailing Stop (após +10%) | Stop-Loss (circuit breaker) |
| Gatilho de parada | Nenhum | Queda 15% do pico | Queda 20% do capital inicial |
| Capital final | ~$1.040 | ~$885-$960 | ~$790-$800 |
| Grid parado? | Não | Sim (trailing) | Sim (stop-loss) |
| Restart | Automático (CRON) | Manual ("Religar Bot") | Manual ("Religar Bot") |

---

## Observações Importantes

### Capital "marcado" vs capital "realizado"

O bot calcula o capital como:
```
capital_atual = USDC_livre + USDC_locked_em_BUYs + BTC_total × preco_atual
```

Isso significa que **valorização ou queda do BTC impacta o capital** mesmo sem nada ser vendido. Uma alta de $95k → $110k com as mesmas posições aumenta o capital marcado em ~15%, mas esse ganho não está "no bolso" até os BUYs executarem e o BTC ser realizado.

### Por que o Trailing Stop pode resultar em perda?

Se o BTC sobe 10% mas o bot ainda tem muito BTC comprado (BUYs executadas, SELLs aguardando), o pico de capital reflete a valorização do BTC. Se o preço cair 15% do pico, o bot encerra com BTC desvalorizado — podendo fechar abaixo do capital inicial dependendo de quantas SELLs executaram para realizar lucros.

### O Slide não gera perda por si só

O Slide DOWN recicla o BTC: cancela SELL distante e cria uma nova mais próxima, preservando o `original_cost_price`. Quando essa SELL deslizante executa, o lucro é calculado contra o custo original do BTC (não contra o preço deslizado). Portanto, **slides apenas reposicionam ordens, não cristalizam perdas**.

### O maior risco é a queda contínua

No Cenário 3, as BUYs executam acumulando BTC enquanto o preço cai. O valor do BTC acumulado cai junto. O Stop-Loss trava a sangria em 20% (com 10min de confirmação para evitar falsos positivos por flash crashes).

---

*Gerado em 2026-05-03 | Baseado em setup_controller.php — valores hipotéticos para fins educativos*
