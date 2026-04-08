# NexoFW - Deploy da Aplicação Driftex

Guia operacional completo para publicar a aplicação `Driftex` em produção usando:

- `Portainer`
- `Docker Swarm`
- `Traefik`
- `MySQL` em stack separada
- `Kafka` em stack separada
- `Git pull` como forma de update

Este é o manual oficial de deploy da aplicação.

## Separação oficial

- `NexoFW` = framework/base técnica
- `Driftex` = aplicação/produto
- nomes antigos como `Driftex`, `GridDriftex`, `driftex` e `driftex` devem ser tratados como legado e removidos do fluxo operacional

## Naming oficial

Use este padrão daqui para frente:

- diretório no host: `/opt/driftex`
- imagem Docker: `driftex:latest`
- path interno: `/var/www/driftex`
- stack no Portainer: `driftex`
- labels Traefik: `driftex-site`
- prefixos de sessão/cache quando aplicável: `driftex_*`

## Pré-requisitos

### No servidor

- Ubuntu/Debian com Docker funcionando
- Docker Swarm inicializado
- Portainer funcionando
- Git instalado
- acesso SSH com permissão para editar `/opt`

### Stacks externas já existentes

Seu ambiente já usa stacks externas para:

- `traefik`
- `portainer`
- `mysql`
- `kafka`

Rede externa usada:

- `dotskynet`

### Regra crítica de produção

Somente o serviço `app` deve subir `cron`.

Configuração obrigatória:

- `app` com `ENABLE_CRON=true`
- `email_worker_site` com `ENABLE_CRON=false`

Se isso não for respeitado, o bot pode executar tarefas duplicadas após restart.

---

## Passo 1: Preparar diretório no servidor

Conecte no servidor:

```bash
ssh <USUARIO>@<SERVIDOR>
```

Crie o diretório da aplicação:

```bash
sudo mkdir -p /opt/driftex
sudo chown -R $USER:$USER /opt/driftex
cd /opt/driftex
```

Crie os diretórios persistentes:

```bash
mkdir -p /opt/driftex/logs
mkdir -p /opt/driftex/site
mkdir -p /opt/driftex/migrations
```

---

## Passo 2: Clonar o repositório

```bash
git clone https://github.com/<USUARIO>/<REPOSITORIO>.git .
```

Verifique a estrutura:

```bash
ls -la
```

Você deve ter, no mínimo:

- `site/`
- `docker/`
- `migrations/`
- `README.md`

---

## Passo 3: Build da imagem

Entre no diretório de produção:

```bash
cd /opt/driftex/docker/prod
```

Faça o build:

```bash
docker build -t driftex:latest .
```

Verifique a imagem:

```bash
docker images | grep driftex
```

Saída esperada:

```bash
driftex   latest   <IMAGE_ID>   <DATA>   <SIZE>
```

---

## Passo 4: Configurar a aplicação

Edite:

```bash
nano /opt/driftex/site/app/inc/kernel.php
```

Garanta que os pontos abaixo estejam corretos:

- `DB_HOST` apontando para o hostname do MySQL acessível pela rede `dotskynet`
- `DB_NAME`, `DB_USER`, `DB_PASS`
- `REDIS_HOST=redis`
- `KAFKA_HOST` apontando para o hostname real do broker Kafka da sua stack
- `KAFKA_PORT=9092`
- SMTP configurado corretamente
- paths condizentes com `/var/www/driftex/...`

Se sua stack Kafka usa o serviço `kafka_broker`, então o hostname correto tende a ser:

```php
define("KAFKA_HOST", "kafka_broker");
```

Além disso, ajuste os paths internos do `kernel.php` e demais configurações para o novo padrão `driftex`.

---

## Passo 5: Instalar dependências Composer

Como a aplicação usa bind mount do código, é importante garantir que o `vendor/` exista no path montado.

Rode:

```bash
docker run --rm \
  -v /opt/driftex/site:/var/www/driftex/site \
  -v /opt/driftex/logs:/var/log \
  --entrypoint bash \
  driftex:latest \
  -lc "cd /var/www/driftex/site/app/inc/lib && composer install --no-interaction --prefer-dist --optimize-autoloader"
```

Depois confirme:

```bash
ls -la /opt/driftex/site/app/inc/lib/vendor
```

---

## Passo 6: Preparar o stack do Portainer

Use como base o arquivo:

- `docker/docker-compose-deploy.yml.example`

Ou use diretamente o stack abaixo.

### Stack recomendado

```yaml
services:
  app:
    image: driftex:latest
    environment:
      - ENABLE_CRON=true
    deploy:
      replicas: 1
      restart_policy:
        condition: any
        delay: 5s
        max_attempts: 3
      update_config:
        parallelism: 1
        delay: 10s
        order: start-first
      resources:
        limits:
          memory: 512M
        reservations:
          memory: 256M
      labels:
        - "traefik.enable=true"
        - "traefik.docker.network=dotskynet"
        - "traefik.http.routers.driftex-site.rule=Host(`botgrid.dotsky.com.br`)"
        - "traefik.http.routers.driftex-site.entrypoints=websecure"
        - "traefik.http.routers.driftex-site.tls.certresolver=letsencryptresolver"
        - "traefik.http.routers.driftex-site.service=driftex-site"
        - "traefik.http.services.driftex-site.loadbalancer.server.port=80"

    volumes:
      - /opt/driftex/site:/var/www/driftex/site:rw
      - /opt/driftex/migrations:/var/www/driftex/migrations:rw
      - /opt/driftex/logs:/var/log:rw
      - /opt/driftex:/git:rw

    networks:
      - dotskynet

    healthcheck:
      test: ["CMD-SHELL", "curl -f http://localhost/ || exit 1"]
      interval: 30s
      timeout: 10s
      retries: 5
      start_period: 90s

  redis:
    image: redis:7-alpine
    deploy:
      replicas: 1
      restart_policy:
        condition: any
      resources:
        limits:
          memory: 256M
        reservations:
          memory: 128M

    command: redis-server --appendonly yes --maxmemory 128mb --maxmemory-policy allkeys-lru

    volumes:
      - redis-data:/data

    networks:
      - dotskynet

    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 3s
      retries: 3

  email_worker_site:
    image: driftex:latest
    environment:
      - ENABLE_CRON=false
    deploy:
      replicas: 1
      restart_policy:
        condition: any
      resources:
        limits:
          memory: 512M
        reservations:
          memory: 256M

    volumes:
      - /opt/driftex/site:/var/www/driftex/site:ro
      - /opt/driftex/logs:/var/log:rw

    networks:
      - dotskynet

    command: ["php", "/var/www/driftex/site/cgi-bin/kafka_email_worker.php"]

    healthcheck:
      test: ["CMD-SHELL", "pgrep -f kafka_email_worker.php || exit 1"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 60s

networks:
  dotskynet:
    external: true

volumes:
  redis-data:
    driver: local
```

### Observações importantes sobre esse stack

- `app` é o único serviço com `ENABLE_CRON=true`
- `email_worker_site` usa a mesma imagem, mas não sobe cron
- o worker executa somente `kafka_email_worker.php`
- o mount `/opt/driftex/logs:/var/log` ajuda no diagnóstico do app e do worker

---

## Passo 7: Ajustar paths internos do projeto

Antes de subir a stack com o naming novo, revise o projeto para trocar referências rígidas a `driftex` e `driftex` por `driftex`.

Os pontos mínimos a revisar são:

- `docker/prod/entrypoint.sh`
- `docker/prod/site.conf`
- `docker/core/entrypoint.sh`
- `docker/core/site.conf`
- `site/app/inc/kernel.php`
- scripts em `site/cgi-bin/`
- helpers e caminhos fixos para `/var/www/...`

Sem isso, o stack pode subir, mas a aplicação pode tentar acessar paths antigos.

---

## Passo 8: Subir no Portainer

No Portainer:

1. Entre em `Stacks`
2. Clique em `Add stack`
3. Defina o nome da stack, por exemplo:

```text
driftex
```

4. Cole o YAML do stack
5. Clique em `Deploy the stack`

Depois verifique se os serviços ficaram com `1/1`.

---

## Passo 9: Validar o deploy

### Verificar serviços

```bash
docker service ls
docker service ps driftex_app
docker service ps driftex_email_worker_site
docker service ps driftex_redis
```

### Verificar logs do app

```bash
docker service logs -f driftex_app
```

### Verificar logs do worker

```bash
docker service logs -f driftex_email_worker_site
```

### Verificar HTTP

```bash
curl -I https://botgrid.dotsky.com.br
```

### Verificar Redis do container app

```bash
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=driftex_app | head -1) \
  redis-cli -h redis ping
```

### Verificar cron dentro do app

```bash
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=driftex_app | head -1) \
  crontab -l
```

Você deve ver a linha do `verify_entry.php`.

### Verificar que o worker não tem cron

```bash
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=driftex_email_worker_site | head -1) \
  sh -lc "crontab -l || true"
```

O esperado é:

- nenhuma crontab configurada
- ou retorno vazio

---

## Passo 10: Atualizar a aplicação

Entre no servidor:

```bash
cd /opt/driftex
git pull origin main
```

### Quando basta forçar restart dos serviços

Use restart simples quando mudar:

- arquivos PHP
- views
- JS/CSS
- scripts do bot

```bash
docker service update --force driftex_app
docker service update --force driftex_email_worker_site
```

### Quando precisa rebuildar a imagem

Rebuild necessário quando mudar:

- `Dockerfile`
- `entrypoint.sh`
- `php.ini`
- extensões PHP
- qualquer coisa em `docker/prod`

```bash
cd /opt/driftex/docker/prod
docker build -t driftex:latest .
docker service update --image driftex:latest driftex_app
docker service update --image driftex:latest driftex_email_worker_site
```

---

## Passo 11: Troubleshooting

### Problema: cron duplicado após restart

Cheque:

```bash
docker service logs driftex_app | tail -100
docker service logs driftex_email_worker_site | tail -100
```

Confirme o stack:

- `app` com `ENABLE_CRON=true`
- `email_worker_site` com `ENABLE_CRON=false`

Confirme também se a imagem foi rebuildada depois da mudança do `entrypoint`.

### Problema: worker sobe mas não processa email

Cheque:

```bash
docker service logs -f driftex_email_worker_site
```

Valide:

- hostname Kafka correto no `kernel.php`
- porta `9092`
- conectividade na rede `dotskynet`
- tópico Kafka correto

### Problema: app não conecta no MySQL

Cheque no `kernel.php`:

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

Cheque também se o MySQL está acessível pela rede do Swarm.

### Problema: 502 no Traefik

Cheque:

```bash
docker service logs traefik_traefik | tail -100
docker service ps driftex_app
```

Confirme:

- labels do Traefik corretas
- rede `dotskynet`
- container app saudável

---

## Resumo operacional

Siga este fluxo:

1. clonar em `/opt/driftex`
2. buildar `driftex:latest`
3. ajustar `kernel.php` e paths internos do projeto
4. instalar `vendor/`
5. subir stack no Portainer
6. validar `app`, `redis` e `email_worker_site`
7. confirmar que só o `app` sobe `cron`

O naming correto da aplicação agora é `Driftex`, tanto em marca quanto em deploy.
