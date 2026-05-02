# Checklist de Deploy — Otimizações Financeiras 2026-Q2

## Pré-requisitos

- [ ] Acesso SSH ao servidor de produção
- [ ] Docker Swarm ativo no Portainer
- [ ] Stack `driftex` parada ou em modo de manutenção
- [ ] Backup do banco de dados (mysqldump)

## Passos sequenciais

### 1. Ativar BNB Burn na Binance (se aplicável)

- [ ] Logar na conta Binance do bot
- [ ] Ir em **Wallet → Fee Settings**
- [ ] Ativar **"Use BNB for fee discount"**
- [ ] Confirmar saldo de BNB suficiente para cobrir ~30 dias de operações
- [ ] Anotar o fee real atual (maker/taker) para inserir no config.php depois

### 2. Aplicar migrations 022–025

```bash
# No servidor de produção
ssh usuario@servidor
cd /opt/driftex

# Aplicar migrations manualmente (ou aguardar cron de 5min)
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=driftex_app | head -1) \
  php /var/www/driftex/site/cgi-bin/run_migrations.php
```

- [ ] Migration 023: `add_real_fill_data_to_orders` — verificar colunas `commission`, `commission_asset`, `commission_usdc_equivalent`, `is_maker`
- [ ] Migration 024: `add_reconcile_fields` — verificar colunas `recovered_orphan`, `pending_shutdown_at`, `pending_shutdown_reason`
- [ ] Migration 025: `create_capital_snapshots` — verificar tabela `capital_snapshots`

### 3. Promover crontab (se alterada)

- [ ] Verificar se `docker/prod/crontab.txt` ou `docker/core/crontab.txt` foi modificado
- [ ] Se sim, rebuildar imagem e fazer `docker service update --force driftex_app`

### 4. Smoke test (1 hora)

```bash
# Monitorar logs em tempo real
docker service logs -f driftex_app
```

- [ ] **0-5 min:** CRON executa sem erros; lock atômico funciona (`🔒 Lock adquirido`)
- [ ] **5-15 min:** Ordens são criadas com `clientOrderId` no padrão `nx-{gridId}-{level}`
- [ ] **15-30 min:** Primeira reconciliação executa (a cada ~10min); sem erros de `reconcileWithBinance`
- [ ] **30-45 min:** Se houver fill, verificar se `fetchAndStoreFillDetails` registra `commission` e `is_maker`
- [ ] **45-60 min:** Dashboard carrega métricas em `/grid-metrics`; gráfico renderiza em `/`

### 5. Verificações pós-deploy

- [ ] Redis contém chave `metrics:grid:{id}` com TTL 60s
- [ ] Tabela `capital_snapshots` tem pelo menos 1 registro por grid ativo
- [ ] Tabela `orders` tem valores não-nulos em `commission_usdc_equivalent` para ordens FILLED recentes
- [ ] Nenhum erro 429/418 nos logs; `BinanceRateLimitGuard::isInBackoff()` retorna `false` em estado normal
- [ ] Configuração de fees salva em `AppSettings` (binance:fee_maker, binance:fee_taker, binance:bnb_burn)

## Rollback (se necessário)

```bash
# Reverter para imagem anterior
docker service update --rollback driftex_app

# Desfazer migrations (atenção: dados novos serão perdidos)
# NÃO executar em produção sem backup confirmado
```

---

**Branch:** `otimizacoes-financeiras-2026q2`  
**Data:** 2026-05-02
