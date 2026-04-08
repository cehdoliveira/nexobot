# NexoFW - Ambiente Local da Aplicação Driftex

Este manual descreve o ambiente local da aplicação `Driftex`, construída sobre o `NexoFW`.

## Separação oficial

- `NexoFW` = framework/base técnica
- `Driftex` = aplicação/produto
- `driftex` = slug recomendado para diretórios, imagem e chaves

## Regras locais

- O container principal da aplicação é o único que pode subir `cron`
- O controle deve ser feito por `ENABLE_CRON=true|false`
- Workers dedicados devem executar apenas seu processo específico

## Setup mínimo

1. Clonar o repositório.
2. Configurar `site/app/inc/kernel.php`.
3. Subir `docker/docker-compose.yml`.
4. Instalar dependências Composer do site.
5. Garantir que apenas o container principal tenha `ENABLE_CRON=true`.

## Convenção recomendada

- host local: `driftex.local`
- diretório local: `/var/www/driftex`
- chave de sessão: `driftex_site_session`
- prefixo Redis: `driftex:site:`

## Observação operacional

Mesmo em ambiente local, se você criar um worker separado usando a mesma imagem, ele deve usar `ENABLE_CRON=false`.
