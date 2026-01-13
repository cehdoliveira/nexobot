# Nexo Framework - Guia de Deploy em Produção

[![PHP Version](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://www.php.net/)
[![MySQL Version](https://img.shields.io/badge/MySQL-8.0-orange.svg)](https://www.mysql.com/)
[![Redis Version](https://img.shields.io/badge/Redis-7.2-red.svg)](https://redis.io/)
[![Kafka Version](https://img.shields.io/badge/Kafka-Latest-black.svg)](https://kafka.apache.org/)
[![Portainer](https://img.shields.io/badge/Portainer-2.0+-purple.svg)](https://www.portainer.io/)
[![Docker Swarm](https://img.shields.io/badge/Docker-Swarm-blue.svg)](https://docs.docker.com/engine/swarm/)

**Deploy em Produção** usando **Portainer** como orquestrador visual, **Docker Swarm** para gerenciamento de containers e **Git** para atualização do código. Este guia assume que você **já possui uma VPS** com **MySQL**, **Kafka** e **Redis** rodando como stacks no Portainer.

> **Este documento é para PRODUÇÃO.** Para desenvolvimento local, consulte [README.md](README.md)

---

## 📚 Índice

1. [Visão Geral](#-visão-geral)
2. [Pré-requisitos](#-pré-requisitos)
3. [Clonar o Projeto](#-passo-1-clonar-o-projeto)
4. [Build da Imagem](#-passo-2-build-da-imagem-customizada)
5. [Deploy no Portainer](#-passo-3-deploy-no-portainer)
6. [Configuração Kernel](#-passo-4-configurar-kernelphp)
7. [Instalar Dependências](#-passo-5-instalar-dependências-composer)
8. [Atualizações com Git](#-atualizações-com-git-pull)
9. [Monitoramento](#-monitoramento)
10. [Troubleshooting](#-troubleshooting)

---

## 🏗️ Visão Geral

Este guia pressupõe que você **já possui**:

✅ **VPS Linux** com Docker e Docker Swarm configurados  
✅ **Portainer** rodando e acessível  
✅ **Stack MySQL** com banco de dados operacional  
✅ **Stack Kafka** com broker configurado  
✅ **Traefik** configurado com SSL/TLS (Let's Encrypt)  
✅ **Rede Overlay** para comunicação entre stacks  

**Observação**: Redis será criado DENTRO da stack `nexo-app` (não precisa estar rodando previamente)  

### O que você vai fazer:

1. **Clonar** o repositório Nexo Framework no servidor
2. **Build** de uma imagem Docker customizada com PHP 8.4 + extensões
3. **Deploy** da stack no Portainer usando a imagem criada
4. **Configurar** `kernel.php` com credenciais do banco/redis/kafka
5. **Instalar** dependências Composer
6. **Acessar** via domínio com SSL

### Arquitetura Final

```
┌─────────────────────────────────────────────────┐
│           🌐 Traefik (Reverse Proxy)           │
│   ├─ site.seudominio.com → :80                 │
│   └─ manager.seudominio.com → :8080            │
└──────────────┬──────────────────────────────────┘
               │ Roteia para
┌──────────────▼──────────────────────────────────┐
│        Stack: nexo-app (Sua Aplicação)         │
│  ├─ app (2 replicas) - PHP 8.4 + Apache        │
│  ├─ redis - Redis 7.2 Alpine (Cache interno)   │
│  ├─ email_worker_site - Kafka Consumer         │
│  └─ email_worker_manager - Kafka Consumer      │
└─────┬────────────────┬──────────────────────────┘
      │ Conecta via rede overlay às stacks externas
      ├─────────────┬──────────────┬───────────────┐
      │             │              │               │
┌─────▼──────┐ ┌────▼─────┐ ┌─────▼─────┐
│   MySQL    │ │  Kafka   │ │  Traefik  │
│ (Externa)  │ │ (Externa)│ │ (Externa) │
└────────────┘ └──────────┘ └───────────┘
  Seu BD         Sua Fila      Seu Proxy
```

---

## 🛠️ Pré-requisitos

### No Servidor (VPS)

- **Sistema**: Ubuntu 20.04 LTS+ ou Debian 11+
- **Docker**: 20.10+ instalado e rodando
- **Docker Swarm**: Inicializado (`docker swarm init`)
- **Portainer**: Acessível via web (ex: `https://portainer.seudominio.com`)
- **Git**: Instalado (`apt install git`)
- **Acesso SSH**: Com permissões sudo

### Stacks Existentes no Portainer

Você deve ter estas stacks **já rodando**:

| Stack | Serviço | Porta Interna | Rede |
|-------|---------|---------------|------|
| `mysql-stack` | mysql | 3306 | overlay_network |
| `kafka-stack` | kafka | 9092 | overlay_network |
| `traefik-stack` | traefik | 80, 443 | overlay_network |

**Observação**: Redis NÃO precisa estar rodando externamente, ele será criado dentro da stack `nexo-app`.

**Nome da Rede Overlay**: Anote o nome (ex: `dotskynet`, `internet_net`). Você vai usar no compose.

### Verificar Stacks

No Portainer: e Traefik estão "Running"
2. **Networks** → Anotar nome da rede overlay (ex: `dotskynet`)

**Redis**: Não precisa verificar, será criado automaticamente na stack `nexo-app` "Running"
2. **Networks** → Anotar nome da rede overlay (ex: `dotskynet`)

---

## 📦 Passo 1: Clonar o Projeto

### 1.1 SSH no Servidor

```bash
ssh usuario@seu-servidor.com
```

### 1.2 Criar Diretório e Clonar

```bash
# Criar diretório para o projeto
sudo mkdir -p /opt/nexo
sudo chown -R $USER:$USER /opt/nexo
cd /opt/nexo

# Clonar repositório
git clone https://github.com/seu-usuario/nexofw.git .

# Verificar estrutura
ls -la
# Esperado: manager/, site/, docker/, README.md, etc.
```

### 1.3 Verificar Estrutura

```bash
tree -L 2 -d

# Esperado:
# .
# ├── docker
# │   ├── core
# │   └── prod       ← Arquivos de produção
# ├── manager
# │   ├── app
# │   ├── cgi-bin
# │   └── public_html
# ├── site
# │   ├── app
# │   ├── cgi-bin
# │   └── public_html
# └── _data          ← Volumes persistentes
```

---

## 🏗️ Passo 2: Build da Imagem Customizada

### 2.1 Editar Configurações de VirtualHost

Os arquivos de configuração do Apache precisam ter seus domínios atualizados:

**Editar Site**:
```bash
nano /opt/nexo/docker/prod/site.conf
```

Altere:
```apache
ServerName seudominio.com
ServerAdmin admin@seudominio.com
```

**Editar Manager**:
```bash
nano /opt/nexo/docker/prod/manager.conf
```

Altere:
```apache
ServerName manager.seudominio.com
ServerAdmin admin@seudominio.com
```

### 2.2 Build da Imagem

```bash
cd /opt/nexo/docker/prod

# Build da imagem (demora 5-10min na primeira vez)
docker build -t nexo-app:latest .

# Aguarde instalação de extensões PHP (redis, rdkafka, gd, etc)
```

**Saída esperada**:
```
[+] Building 450.2s (15/15) FINISHED
 => [internal] load build definition
 => => transferring dockerfile: 1.2kB
 => [internal] load .dockerignore
 => ...
 => exporting to image
 => => exporting layers
 => => writing image sha256:abc123...
 => => naming to docker.io/library/nexo-app:latest
```

### 2.3 Verificar Imagem Criada

```bash
docker images | grep nexo-app

# Esperado:
# nexo-app   latest   abc123def456   2 minutes ago   580MB
```

---

## 🚀 Passo 3: Deploy no Portainer

### 3.1 Preparar docker-compose-deploy.yml

```bash
cd /opt/nexo/docker
cp docker-compose-deploy.yml.example docker-compose-deploy.yml
nano docker-compose-deploy.yml
```

### 3.2 Atualizar Placeholders

Substitua os seguintes valores:

| Placeholder | Exemplo | Descrição |
|-------------|---------|-----------|
| `<NOME_APP>` | `nexo` | Nome do seu app |
| `<SEU_DOMINIO>` | `seusite.com` | Domínio principal |
| `<SUA_IMAGEM_CUSTOMIZADA>` | `nexo-app:latest` | Imagem que você criou |
| `<SUA_REDE_INTERNET_DO_PORTAINER>` | `dotskynet` | Nome da rede overlay |

**Exemplo de arquivo editado**:

```yaml
services:
  app:
    image: nexo-app:latest  # ← SUA IMAGEM
    deploy:
      replicas: 2
      restart_policy:
        condition: any
      labels:
        - "traefik.enable=true"
        - "traefik.docker.network=dotskynet"  # ← SUA REDE
        
        # Site (seusite.com)
        - "traefik.http.routers.nexo-site.rule=Host(`seusite.com`)"  # ← SEU DOMÍNIO
        - "traefik.http.routers.nexo-site.entrypoints=websecure"
        - "traefik.http.routers.nexo-site.tls.certresolver=letsencryptresolver"
        - "traefik.http.services.nexo-site.loadbalancer.server.port=80"
        
        # Manager (manager.seusite.com)
        - "traefik.http.routers.nexo-manager.rule=Host(`manager.seusite.com`)"  # ← SEU DOMÍNIO
        - "traefik.http.routers.nexo-manager.entrypoints=websecure"
        - "traefik.http.routers.nexo-manager.tls.certresolver=letsencryptresolver"
        - "traefik.http.services.nexo-manager.loadbalancer.server.port=8080"
    
    volumes:
      - /opt/nexo/site:/var/www/site:rw
      - /opt/nexo/manager:/var/www/manager:rw
      - /opt/nexo/_data/logs/apache2:/var/log/apache2:rw
      - /opt/nexo:/git:rw  # Para git pull
    
    networks:
      - dotskynet  # ← SUA REDE
    
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
    command: redis-server --appendonly yes --maxmemory 128mb --maxmemory-policy allkeys-lru
    volumes:
      - redis-data:/data
    networks:
      - dotskynet  # ← SUA REDE
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s

  email_worker_site:
    image: nexo-app:latest  # ← SUA IMAGEM
    deploy:
      replicas: 1
    volumes:
      - /opt/nexo/site:/var/www/site:ro
    networks:
      - dotskynet  # ← SUA REDE
    entrypoint: []
    command: ["php", "/var/www/site/cgi-bin/kafka_email_worker.php"]
    healthcheck:
      test: ["CMD-SHELL", "pgrep -f kafka_email_worker.php || exit 1"]
      interval: 30s

  email_worker_manager:
    image: nexo-app:latest  # ← SUA IMAGEM
    deploy:
      replicas: 1
    volumes:
      - /opt/nexo/manager:/var/www/manager:ro
    networks:
      - dotskynet  # ← SUA REDE
    entrypoint: []
    command: ["php", "/var/www/manager/cgi-bin/kafka_email_worker.php"]
    healthcheck:
      test: ["CMD-SHELL", "pgrep -f kafka_email_worker.php || exit 1"]
      interval: 30s

networks:  
  dotskynet:  # ← SUA REDE
    external: true

volumes:
  redis-data:
    driver: local
```

### 3.3 Deploy no Portainer

**Via Interface Web**:

1. Acesse **Portainer** → **Stacks** → **Add stack**
2. **Name**: `nexo-app`
3. **Build method**: **Web editor**
4. Cole o conteúdo do seu `docker-compose-deploy.yml` editado
5. Clique em **Deploy the stack**

**Aguarde 2-3 minutos** para:
- Criação dos serviços
- Pull das imagens (redis)
- Inicialização dos containers
- Health checks

Nota: o cron do app é instalado e iniciado automaticamente a partir de docker/prod/crontab.txt.

### 3.4 Verificar Deploy

No Portainer → **Stacks** → **nexo-app**:

```
✓ app (2/2 replicas running)
✓ redis (1/1 running)
✓ email_worker_site (1/1 running)
✓ email_worker_manager (1/1 running)
```

Todos devem estar com status **"Running"** (verde).

---

## ⚙️ Passo 4: Configurar kernel.php

### 4.1 Manager

```bash
cd /opt/nexo
nano manager/app/inc/kernel.php
```

**Conteúdo**:
```php
<?php

// ===== TIMEZONE =====
date_default_timezone_set("America/Sao_Paulo");

// ===== ENCODING E UPLOAD =====
ini_set("default_charset", "UTF-8");
ini_set("post_max_size", "4096M");
ini_set("upload_max_filesize", "4096M");

// ===== BANCO DE DADOS =====
define("DB_HOST", "mysql");              // Nome do serviço MySQL (stack externa)
define("DB_NAME", "seu_banco");          // Nome do database
define("DB_USER", "seu_usuario");        // Usuário MySQL
define("DB_PASS", "sua_senha_forte");    // Senha MySQL

// ===== REDIS (Cache) =====
define("REDIS_HOST", "redis");           // Nome do serviço Redis (da stack nexo-app)
define("REDIS_PORT", 6379);
define("REDIS_PREFIX", "nexo:manager:");
define("REDIS_DATABASE", 0);
define("REDIS_ENABLED", true);
define("REDIS_DEFAULT_TTL", 3600);

// ===== KAFKA (Emails) =====
define("KAFKA_HOST", "kafka");           // Nome do serviço Kafka (stack externa)
define("KAFKA_PORT", "9092");
define("KAFKA_TOPIC_EMAIL", "nexo_manager_emails");
define("KAFKA_CONSUMER_GROUP", "nexo-email-worker-group");

// ===== EMAIL (SMTP) =====
define("mail_from_name", "Seu Projeto - Manager");
define("mail_from_mail", "noreply@seudominio.com");
define("mail_from_host", "smtp.gmail.com");      // Servidor SMTP
define("mail_from_port", "587");                 // Porta TLS
define("mail_from_user", "seu-email@gmail.com"); // Email SMTP
define("mail_from_pwd", "sua-senha-app-gmail");  // Senha de App

// ===== APLICAÇÃO =====
define("cAppKey", "nexo_manager_session");
define("cPaginate", 150);
define("cTitle", "Nexo Manager");

// ===== PATHS =====
define("cAppRoot", "/");
define("cRootServer", sprintf("%s%s", $_SERVER["DOCUMENT_ROOT"], constant("cAppRoot")));
define("cRootServer_APP", sprintf("%s%s", $_SERVER["DOCUMENT_ROOT"], constant("cAppRoot") . "../app"));
define("cFrontend", sprintf("https://%s%s", $_SERVER["HTTP_HOST"], constant("cAppRoot")));
define("cAssets", sprintf("%s%s", constant("cFrontend"), "assets/"));

// ===== SESSÃO =====
define("SESSION_LIFETIME", 7200);
define("SESSION_USE_REDIS", false);

// ===== UPLOAD =====
define("UPLOAD_DIR", "/var/www/manager/public_html/assets/upload/");
define("UPLOAD_MAX_SIZE", 10);
define("UPLOAD_ALLOWED_TYPES", "jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx");

// ===== LOG =====
define("LOG_DIR", "/var/log/nexo/");
define("LOG_LEVEL", "debug");
```

### 4.2 Site

```bash
nano site/app/inc/kernel.php
```

**Copie do manager e altere**:
```php
define("REDIS_PREFIX", "nexo:site:");              // Prefixo diferente
define("REDIS_DATABASE", 1);                        // Database diferente
define("KAFKA_TOPIC_EMAIL", "nexo_site_emails");   // Tópico diferente
define("mail_from_name", "Seu Projeto - Site");
define("cAppKey", "nexo_site_session");
define("cTitle", "Nexo Site");
define("UPLOAD_DIR", "/var/www/site/public_html/assets/upload/");

// cFrontend e cRootServer são gerados automaticamente pelos sprintf
// mas o HTTP_HOST vai apontar para seudominio.com ao invés de manager.seudominio.com
```

### 4.3 Verificar Conectividade

```bash
# Entrar no container app
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=nexo-app_app | head -1) bash

# Testar MySQL
mysql -h mysql -u seu_usuario -p -e "SELECT 1;"
# Esperado: +---+
#           | 1 |

# Testar Redis
redis-cli -h redis ping
# Esperado: PONG

# Testar Kafka (verificar se host responde)
ping -c 1 kafka
# Esperado: 1 packets transmitted, 1 received

exit
```

---

## 📚 Passo 5: Instalar Dependências Composer

### 5.1 Manager

```bash
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=nexo-app_app | head -1) bash

cd /var/www/manager/app/inc/lib
composer install --no-dev --optimize-autoloader

# Esperado:
# Installing dependencies from lock file
# Package operations: X installs, 0 updates, 0 removals
# ...
# Generating optimized autoload files
```

### 5.2 Site

```bash
cd /var/www/site/app/inc/lib
composer install --no-dev --optimize-autoloader

exit
```

---

## ✅ Verificar Instalação

### Teste 1: Acesso HTTP

```bash
curl -I https://seudominio.com
# Esperado: HTTP/2 200

curl -I https://manager.seudominio.com
# Esperado: HTTP/2 200
```

### Teste 2: SSL/TLS

```bash
curl -v https://seudominio.com 2>&1 | grep -i "SSL"
# Esperado: SSL certificate verify ok
```

### Teste 3: Health Check

Crie arquivo `/opt/nexo/site/public_html/health.php`:

```php
<?php
require_once __DIR__ . '/../app/inc/kernel.php';

$health = [
    'status' => 'ok',
    'php' => phpversion(),
    'mysql' => 'checking...',
    'redis' => 'checking...',
];

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS
    );
    $health['mysql'] = 'ok';
} catch (Exception $e) {
    $health['mysql'] = 'error: ' . $e->getMessage();
    $health['status'] = 'degraded';
}

try {
    $redis = new Redis();
    $redis->connect(REDIS_HOST, REDIS_PORT);
    if (defined('REDIS_PASSWORD') && REDIS_PASSWORD) {
        $redis->auth(REDIS_PASSWORD);
    }
    $redis->ping();
    $health['redis'] = 'ok';
} catch (Exception $e) {
    $health['redis'] = 'error: ' . $e->getMessage();
    $health['status'] = 'degraded';
}

header('Content-Type: application/json');
echo json_encode($health, JSON_PRETTY_PRINT);
```

Acesse:
```bash
curl https://seudominio.com/health.php

# Esperado:
# {
#   "status": "ok",
#   "php": "8.4.x",
#   "mysql": "ok",
#   "redis": "ok"
# }
```

---

## 🔄 Atualizações com Git Pull

### Workflow

1. **Desenvolvimento local** → Commit e push para Git
2. **Servidor** → `git pull` para atualizar código
3. **Containers** → Usam volumes compartilhados (atualização automática!)

### Atualizar Código

```bash
ssh usuario@seu-servidor.com
cd /opt/nexo

# Puxar atualizações
git pull origin main

# Esperado:
# Updating abc1234..def5678
# Fast-forward
#  site/public_html/index.php | 10 +++++-----
#  1 file changed, 5 insertions(+), 5 deletions(-)
```

**Pronto!** Os volumes compartilhados fazem os containers usarem o código atualizado imediatamente.

### Quando Precisa Restart?

**NÃO precisa** restart para:
- ✅ Alterações em arquivos PHP
- ✅ Novos arquivos adicionados
- ✅ Alterações em HTML/CSS/JS
- ✅ Atualizações de views

**PRECISA restart** para:
- ⚠️ Alterações em `kernel.php`
- ⚠️ Alterações nas configurações Apache (VirtualHost)
- ⚠️ Atualização de dependências Composer
- ⚠️ Alterações na imagem Docker (Dockerfile)

### Restart Manual

```bash
# Via Portainer Web:
# Stacks → nexo-app → Services → app → Restart service

# Via CLI:
docker service update --force nexo-app_app
```

### Rebuild de Imagem (Mudanças no Dockerfile)

```bash
cd /opt/nexo/docker/prod

# Rebuild
docker build -t nexo-app:latest .

# Update service para usar nova imagem
docker service update --image nexo-app:latest nexo-app_app

# Também atualizar workers
docker service update --image nexo-app:latest nexo-app_email_worker_site
docker service update --image nexo-app:latest nexo-app_email_worker_manager
```

---

## 📊 Monitoramento

### Portainer Dashboard

Acesse: `https://portainer.seudominio.com`

Visualize:
- **Stacks** → Estado dos serviços
- **Containers** → CPU/RAM por container
- **Logs** → Em tempo real
- **Stats** → Gráficos de uso

### Logs via CLI

```bash
# Logs da aplicação (todas replicas)
docker service logs -f nexo-app_app

# Logs de uma replica específica
docker logs -f <container_id>

# Logs do email worker (site)
docker service logs -f nexo-app_email_worker_site

# Logs do email worker (manager)
docker service logs -f nexo-app_email_worker_manager

# Últimas 100 linhas
docker service logs --tail 100 nexo-app_app

# Filtrar por erro
docker service logs nexo-app_app 2>&1 | grep -i error
```

### Verificar Saúde dos Serviços

```bash
# Listar serviços e replicas
docker service ls

# Detalhar um serviço
docker service ps nexo-app_app

# Inspecionar
docker service inspect nexo-app_app --pretty
```

### Monitorar Workers Kafka

```bash
# Ver se está consumindo mensagens
docker service logs -f nexo-app_email_worker_site | grep -i "processing\|sent"

# Ver filas no Kafka
# (assumindo que você tem Kafka UI rodando)
# Acesse: http://seu-servidor:8080
```

---

## 🔧 Troubleshooting

### Problema: Stack não sobe

```bash
# Ver logs da stack
docker service ls | grep nexo-app

# Ver por que serviço não subiu
docker service ps nexo-app_app --no-trunc

# Comum: Imagem não encontrada
# Solução: Verificar se fez build da imagem
docker images | grep nexo-app

# Se não existir, fazer build
cd /opt/nexo/docker/prod
docker build -t nexo-app:latest .
```

### Problema: Erro 502 Bad Gateway

```bash
# Verificar se app está rodando
docker service ps nexo-app_app

# Ver logs do Traefik
docker service logs traefik_traefik | grep -i error

# Comum: Labels do Traefik errados
# Verificar docker-compose-deploy.yml:
# - Nome da rede deve ser o mesmo do Traefik
# - Porta deve ser 80 (site) e 8080 (manager)
```

### Problema: Aplicação retorna erro 500

```bash
# Ver logs PHP
docker service logs nexo-app_app | tail -50

# Comum: kernel.php não configurado
# Verificar arquivo
cat /opt/nexo/manager/app/inc/kernel.php | grep "DB_HOST"

# Comum: MySQL não acessível
# Testar conexão
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=nexo-app_app | head -1) \
  mysql -h mysql -u seu_usuario -p -e "SELECT 1;"
```

### Problema: SSL não funciona

```bash
# Ver certificados do Traefik
docker service logs traefik_traefik | grep -i "certificate"

# Comum: DNS não propagado
# Verificar:
nslookup seudominio.com
# Deve apontar para IP do servidor

# Forçar renovação (se certificado expirou)
# Via Portainer: Restart stack do Traefik
```

### Problema: Email worker não processa

```bash
# Verificar se worker está rodando
docker service ps nexo-app_email_worker_site

# Ver logs
docker service logs -f nexo-app_email_worker_site

# Comum: Kafka não acessível
# Testar:
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=nexo-app_email_worker_site) \
  ping -c 1 kafka

# Comum: Tópico não existe
# Criar tópico no Kafka (via Kafka UI ou CLI)
```

### Problema: Redis não conecta

```bash
# Verificar se Redis está rodando
docker service ps nexo-app_redis

# Testar conexão
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=nexo-app_app | head -1) \
  redis-cli -h redis ping

# Se usar Redis de stack externa, verificar rede
docker network inspect dotskynet | grep -i redis
```

---

## 📚 Comandos Úteis

### Docker Service

```bash
# Listar serviços
docker service ls

# Escalar replicas
docker service scale nexo-app_app=3

# Restart forçado
docker service update --force nexo-app_app

# Remover serviço
docker service rm nexo-app_app
```

### Git

```bash
# Status
git status

# Ver alterações
git diff

# Puxar atualizações
git pull origin main

# Ver histórico
git log --oneline -10

# Reverter para commit anterior
git checkout <commit_hash> .
```

### Composer

```bash
# Dentro do container
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=nexo-app_app | head -1) bash

# Atualizar dependências
cd /var/www/manager/app/inc/lib
composer update

# Adicionar nova dependência
composer require phpmailer/phpmailer

exit
```

---

## ✅ Checklist de Deploy e Traefik rodando (Redis será criado na stack nexo-app)

- [ ] VPS com Docker, Swarm, Portainer configurados
- [ ] Stacks MySQL, Kafka, Redis, Traefik rodando
- [ ] Rede overlay criada e anotada
- [ ] Projeto clonado em `/opt/nexo`
- [ ] Arquivos `site.conf` e `manager.conf` com domínios corretos
- [ ] Imagem `nexo-app:latest` criada com build
- [ ] `docker-compose-deploy.yml` editado com placeholders
- [ ] Stack `nexo-app` criada no Portainer
- [ ] Todos serviços "Running" (app, redis, workers)
- [ ] Arquivos `kernel.php` configurados (Manager + Site)
- [ ] Dependências Composer instaladas
- [ ] DNS apontado para servidor
- [ ] SSL/TLS funcionando (HTTPS)
- [ ] `/health.php` retornando `status: ok`
- [ ] Email workers processando mensagens

---

## 🚀 Próximos Passos

1. **Configurar Backup** - Agendar backup do MySQL e uploads
2. **Monitorar Performance** - Grafana + Prometheus (opcional)
3. **Escalar** - Aumentar replicas conforme demanda
4. **CI/CD** - Automatizar deploy com GitHub Actions
5. **Logs Centralizados** - ELK Stack ou similar

---

## 📞 Suporte

Para mais informações:

- **Desenvolvimento**: [README.md](README.md)
- **Emails**: [KAFKA_EMAIL.md](KAFKA_EMAIL.md)
- **Cache**: [REDIS.md](REDIS.md)

---

**Nexo Framework - Deploy em Produção**  
Portainer + Docker Swarm + Git  
Última atualização: Thu Jan 02 2026
