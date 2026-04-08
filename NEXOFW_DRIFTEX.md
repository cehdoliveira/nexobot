# NexoFW x Driftex

Este arquivo define a separação oficial entre framework e aplicação neste repositório.

## Definições

### NexoFW

`NexoFW` é o nome do framework/base técnica.

Inclui:

- arquitetura MVC própria;
- bootstrap global;
- dispatcher;
- models base;
- camada de banco;
- cache Redis;
- integração Kafka/email;
- helpers;
- Dockerfiles, entrypoints e arquivos de infraestrutura.

### Driftex

`Driftex` é o nome da aplicação/produto.

Inclui:

- domínio de grid trading;
- dashboard;
- autenticação da aplicação;
- telas;
- regras de ordens, grids, trades e proteção;
- scripts operacionais específicos do bot.

## Regra de nomenclatura

### Marca

- Framework: `NexoFW`
- Aplicação: `Driftex`

### Slugs e nomes técnicos recomendados

- slug da aplicação: `driftex`
- diretório de deploy: `/opt/driftex`
- imagem Docker: `driftex:latest`
- session/app key: `driftex_*`
- prefixos de logs/cache: `driftex:*`

### Legado

Os nomes abaixo devem ser tratados como legado e gradualmente removidos da documentação e da UI:

- `NexoBot`
- `GridNexoBot`
- `nexobot`
- `gridnexobot`

## Regra operacional

- O framework pode ser reutilizado.
- A aplicação tem branding, domínio e configurações próprias.
- Em produção, somente o serviço `app` sobe `cron`.
- Workers nunca devem subir `cron`.
- O controle disso deve ser feito com `ENABLE_CRON=true|false`.
