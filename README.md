# NexoFW

Base técnica em PHP para a aplicação `Driftex`.

## Separação oficial

- `NexoFW` = framework/base técnica
- `Driftex` = aplicação/produto

## Documentação principal

- Ambiente local da aplicação: [AMBIENTE_LOCAL_DRIFTEX.md](AMBIENTE_LOCAL_DRIFTEX.md)
- Deploy em produção: [MANUAL_DEPLOY_DRIFTEX.md](MANUAL_DEPLOY_DRIFTEX.md)
- Separação entre framework e aplicação: [NEXOFW_DRIFTEX.md](NEXOFW_DRIFTEX.md)
- Migrations e versionamento de banco: [MIGRATIONS.md](MIGRATIONS.md)

## Estrutura resumida

- `docker/core` = ambiente local
- `docker/prod` = ambiente de produção
- `site/app/inc` = bootstrap, controllers, libs e models
- `site/public_html` = entrada web e interface
- `site/cgi-bin` = scripts operacionais ativos
- `migrations` = estrutura versionada do banco

## Regras operacionais

- O serviço `app` é o único que pode subir `cron`
- Workers dedicados devem usar `ENABLE_CRON=false`
- A configuração da Binance é lida do banco
- O naming técnico oficial da aplicação é `driftex`

## Otimizações 2026-Q2

Conjunto de melhorias focadas em precisão de P&L, robustez operacional e visibilidade de métricas.

- [Relatório de Mudanças](RELATORIO_MUDANCAS.md)
- [Checklist de Deploy](CHECKLIST_DEPLOY.md)

**Migrations:** 023, 024, 025  
**Branch:** `otimizacoes-financeiras-2026q2`

## Estado da documentação

Este `README.md` é apenas a porta de entrada do repositório.

Para operação real, use os manuais específicos listados acima.
