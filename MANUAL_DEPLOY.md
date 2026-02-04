# Nexo Framework - Guia de Deploy em Produção

[![PHP Version](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://www.php.net/)
[![MySQL Version](https://img.shields.io/badge/MySQL-8.0-orange.svg)](https://www.mysql.com/)
[![Redis Version](https://img.shields.io/badge/Redis-7.2-red.svg)](https://redis.io/)
[![Kafka Version](https://img.shields.io/badge/Kafka-Latest-black.svg)](https://kafka.apache.org/)
[![Portainer](https://img.shields.io/badge/Portainer-2.0+-purple.svg)](https://www.portainer.io/)
[![Docker Swarm](https://img.shields.io/badge/Docker-Swarm-blue.svg)](https://docs.docker.com/engine/swarm/)

**Deploy em Produção** usando **Portainer** como orquestrador visual, **Docker Swarm** para gerenciamento de containers e **Git** para atualização do código. Este guia segue uma sequência prática e testada para colocar sua aplicação em produção.

> **Este documento é para PRODUÇÃO.** Para desenvolvimento local, consulte [README.md](README.md)

---

## 📚 Índice

1. [Pré-requisitos](#-pré-requisitos)
2. [Passo 1: Clonar o Projeto](#-passo-1-clonar-o-projeto)
3. [Passo 2: Build da Imagem Customizada](#-passo-2-build-da-imagem-customizada)
4. [Passo 3: Configurar Kernel.php](#-passo-3-configurar-kernelphp)
5. [Passo 4: Criar Banco de Dados](#-passo-4-criar-banco-de-dados)
6. [Passo 5: Instalar Dependências Composer](#-passo-5-instalar-dependências-composer)
7. [Passo 6: Deploy no Portainer](#-passo-6-deploy-no-portainer)
8. [Passo 7: Verificação & Testes](#-passo-7-verificação--testes)
9. [Migrations](#-migrations)
10. [Atualizações com Git](#-atualizações-com-git)
11. [Monitoramento](#-monitoramento)
12. [Troubleshooting](#-troubleshooting)

---

## 🛠️ Pré-requisitos

### No Servidor (VPS)

- **Sistema**: Ubuntu 20.04 LTS+ ou Debian 11+
- **Docker**: 20.10+ instalado e rodando
- **Docker Swarm**: Inicializado (`docker swarm init`)
- **Portainer**: Acessível via web
- **Git**: Instalado (`apt install git`)
- **Acesso SSH**: Com permissões sudo

### Stacks Já Rodando no Portainer

Você deve ter estas stacks **já criadas e rodando**:

| Stack | Serviço | Porta | Rede |
|-------|---------|-------|------|
| `mysql-stack` | mysql | 3306 | `<SUA_REDE>` |
| `kafka-stack` | kafka | 9092 | `<SUA_REDE>` |
| `traefik-stack` | traefik | 80, 443 | `<SUA_REDE>` |

**Redis**: Não precisa estar externo, será criado dentro da stack `<NOME_APP>`

### Anotar Valores Importantes

Você vai precisar destes valores (anote antes de começar):

```
<SEU_SERVIDOR>          = IP ou hostname da VPS (ex: 192.168.1.10 ou vps.exemplo.com)
<USUARIO_SSH>           = Seu usuário SSH na VPS
<NOME_APP>              = Nome do seu projeto/app (ex: meu-app)
<SEU_DOMINIO>           = Seu domínio principal (ex: seusite.com)
<SEU_USUARIO_REPO>      = Seu usuário do GitHub
<SEU_REPO>              = Nome do repositório (ex: gridnexobot)
<SUA_REDE>              = Nome da rede Overlay do Portainer (ex: dotskynet)
<NOME_BANCO>            = Nome do banco MySQL (ex: meu_app_db)
<USUARIO_DB>            = Usuário MySQL (ex: app_user)
<SENHA_DB>              = Senha MySQL forte
<SEU_EMAIL_SMTP>        = Email SMTP para envios (ex: seu-email@gmail.com)
<SENHA_APP_EMAIL>       = Senha de App do Email
<SEU_PROJETO>           = Nome do projeto (ex: "Meu Projeto")
```

---

## 📦 Passo 1: Clonar o Projeto

### 1.1 SSH no Servidor

```bash
ssh <USUARIO_SSH>@<SEU_SERVIDOR>
```

### 1.2 Criar Diretório

```bash
# Criar diretório para o projeto
sudo mkdir -p /opt/<NOME_APP>
sudo chown -R $USER:$USER /opt/<NOME_APP>
cd /opt/<NOME_APP>
```

### 1.3 Clonar Repositório

```bash
# Clonar repositório
git clone https://github.com/<SEU_USUARIO_REPO>/<SEU_REPO>.git .

# Verificar estrutura
ls -la

# Esperado: site/, docker/, migrations/, README.md, etc.
```

### 1.4 Verificar Estrutura

```bash
tree -L 2 -d

# Esperado:
# .
# ├── site
# │   ├── app
# │   ├── cgi-bin
# │   └── public_html
# ├── docker
# │   ├── core
# │   └── prod       ← Arquivos de produção
# ├── migrations
# └── _data          ← Volumes persistentes

# Criar diretório de logs para o bind mount do Apache
mkdir -p /opt/<NOME_APP>/logs/apache2
```

---

## 🏗️ Passo 2: Build da Imagem Customizada

### 2.1 Editar Configuração do Apache

O arquivo `docker/prod/site.conf` precisa ter seu domínio atualizado:

```bash
nano /opt/<NOME_APP>/docker/prod/site.conf
```

Procure por `ServerName` e altere:

```apache
ServerName <SEU_DOMINIO>
ServerAdmin admin@<SEU_DOMINIO>
```

Salve (Ctrl+O, Enter, Ctrl+X).

### 2.2 Build da Imagem Docker

```bash
cd /opt/<NOME_APP>/docker/prod

# Build da imagem (demora 5-10 minutos na primeira vez)
docker build -t <NOME_APP>:latest .
```

**Aguarde** a instalação de todas as extensões PHP (redis, rdkafka, gd, etc).

Saída esperada no final:
```
[+] Building XXXs (15/15) FINISHED
...
=> => naming to docker.io/library/<NOME_APP>:latest
```

### 2.3 Verificar Imagem

```bash
docker images | grep <NOME_APP>

# Esperado:
# <NOME_APP>   latest   abc123def456   2 minutes ago   580MB
```

---

## ⚙️ Passo 3: Configurar Kernel.php

O arquivo `kernel.php` contém todas as credenciais e configurações da aplicação.

### 3.1 Editar Site Kernel.php

```bash
nano /opt/<NOME_APP>/site/app/inc/kernel.php
```

Substitua os valores:

```php
<?php

// ===== TIMEZONE =====
date_default_timezone_set("America/Sao_Paulo");

// ===== ENCODING E UPLOAD =====
ini_set("default_charset", "UTF-8");
ini_set("post_max_size", "4096M");
ini_set("upload_max_filesize", "4096M");

// ===== BANCO DE DADOS =====
define("DB_HOST", "mysql");              // Nome do serviço MySQL
define("DB_NAME", "<NOME_BANCO>");       // Ex: meu_app_db
define("DB_USER", "<USUARIO_DB>");       // Ex: app_user
define("DB_PASS", "<SENHA_DB>");         // Ex: senha_forte_123

// ===== REDIS (Cache) =====
define("REDIS_HOST", "redis");           // Nome do serviço Redis
define("REDIS_PORT", 6379);
define("REDIS_PREFIX", "<NOME_APP>:site:");
define("REDIS_DATABASE", 0);
define("REDIS_ENABLED", true);
define("REDIS_DEFAULT_TTL", 3600);

// ===== KAFKA (Emails) =====
define("KAFKA_HOST", "kafka");           // Nome do serviço Kafka
define("KAFKA_PORT", "9092");
define("KAFKA_TOPIC_EMAIL", "<NOME_APP>_site_emails");
define("KAFKA_CONSUMER_GROUP", "<NOME_APP>-email-worker-group");

// ===== EMAIL (SMTP) =====
define("mail_from_name", "<SEU_PROJETO> - Site");
define("mail_from_mail", "noreply@<SEU_DOMINIO>");
define("mail_from_host", "smtp.gmail.com");      // SMTP Server
define("mail_from_port", "587");                 // Port TLS
define("mail_from_user", "<SEU_EMAIL_SMTP>");    // SMTP User
define("mail_from_pwd", "<SENHA_APP_EMAIL>");    // SMTP Password

// ===== APLICAÇÃO =====
define("cAppKey", "<NOME_APP>_site_session");
define("cPaginate", 150);
define("cTitle", "<SEU_PROJETO> - Site");

// ===== PATHS (gerados automaticamente) =====
define("cAppRoot", "/");
define("cRootServer", sprintf("%s%s", $_SERVER["DOCUMENT_ROOT"], constant("cAppRoot")));
define("cRootServer_APP", sprintf("%s%s", $_SERVER["DOCUMENT_ROOT"], constant("cAppRoot") . "../app"));
define("cFrontend", sprintf("https://%s%s", $_SERVER["HTTP_HOST"], constant("cAppRoot")));
define("cAssets", sprintf("%s%s", constant("cFrontend"), "assets/"));

// ===== SESSÃO =====
define("SESSION_LIFETIME", 7200);
define("SESSION_USE_REDIS", false);

// ===== UPLOAD =====
define("UPLOAD_DIR", "/var/www/<NOME_APP>/site/public_html/assets/upload/");
define("UPLOAD_MAX_SIZE", 10);
define("UPLOAD_ALLOWED_TYPES", "jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx");

// ===== LOG =====
define("LOG_DIR", "/var/log/<NOME_APP>/");
define("LOG_LEVEL", "debug");
?>
```

Salve o arquivo (Ctrl+O, Enter, Ctrl+X).

---

## 📊 Passo 4: Criar Banco de Dados

### 4.1 Conectar ao MySQL

```bash
# Descubra o container MySQL ou IP
# Se está em stack externa, use seu IP

# Conectar ao MySQL
mysql -h <IP_MYSQL> -u root -p

# Quando solicitar senha, digite a senha root do MySQL
```

### 4.2 Criar Banco e Usuário

Dentro do MySQL, execute:

```sql
-- Criar banco de dados
CREATE DATABASE <NOME_BANCO> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Criar usuário
CREATE USER '<USUARIO_DB>'@'%' IDENTIFIED BY '<SENHA_DB>';

-- Conceder permissões
GRANT ALL PRIVILEGES ON <NOME_BANCO>.* TO '<USUARIO_DB>'@'%';
FLUSH PRIVILEGES;

-- Verificar criação
SHOW DATABASES;
SELECT User, Host FROM mysql.user WHERE User='<USUARIO_DB>';

-- Sair
exit
```

### 4.3 Importar Migrations Iniciais (Se existirem)

Se há migrations iniciais em `/opt/<NOME_APP>/migrations/`:

```bash
# Importar arquivo SQL inicial
mysql -h <IP_MYSQL> -u <USUARIO_DB> -p <NOME_BANCO> < /opt/<NOME_APP>/migrations/001_initial_schema.sql

# Verificar
mysql -h <IP_MYSQL> -u <USUARIO_DB> -p -e "USE <NOME_BANCO>; SHOW TABLES;"
```

---

## 📦 Passo 5: Instalar Dependências Composer

Como ainda não temos o container rodando em Swarm, vamos instalar as dependências usando a imagem que criamos.

### 5.1 Instalar Composer

```bash
# Executar Composer usando a imagem buildada, sem iniciar o Apache
docker run --rm \
  --entrypoint composer \
  -v /opt/<NOME_APP>/site:/var/www/<NOME_APP>/site \
  -w /var/www/<NOME_APP>/site/app/inc/lib \
  <NOME_APP>:latest \
  install --no-dev --optimize-autoloader
```

Observação:
- O parâmetro `--entrypoint composer` evita que o entrypoint da imagem suba o Apache/cron — roda apenas o Composer e sai.
- Alternativa (sem depender da imagem buildada):

```bash
docker run --rm \
  -v /opt/<NOME_APP>/site/app/inc/lib:/app \
  -w /app \
  composer:2 \
  install --no-dev --optimize-autoloader
```

**Aguarde** a instalação de todas as dependências.

Esperado no final:
```
Installing dependencies from lock file
Package operations: X installs, 0 updates, 0 removals
...
Generating optimized autoload files
```

### 5.2 Verificar Instalação

```bash
# Verificar se vendor foi criado
ls -la /opt/<NOME_APP>/site/app/inc/lib/vendor/

# Esperado: autoload.php, binance/, composer/, guzzlehttp/, phpmailer/, etc.
```

---

## 🚀 Passo 6: Deploy no Portainer

### 6.1 Preparar docker-compose-deploy.yml

```bash
cd /opt/<NOME_APP>/docker
cp docker-compose-deploy.yml.example docker-compose-deploy.yml
nano docker-compose-deploy.yml
```

### 6.2 Atualizar Placeholders

Substitua **todos** os placeholders:

| Placeholder | Seu Valor |
|-------------|-----------|
| `<NOME_APP>` | `meu-app` |
| `<SEU_DOMINIO>` | `seusite.com` |
| `<SUA_IMAGEM_CUSTOMIZADA>` | `meu-app:latest` |
| `<SUA_REDE_INTERNET_DO_PORTAINER>` | `dotskynet` |

**Exemplo de trechos principais do arquivo editado (modelo):**

```yaml
services:
  # ============================================
  # 📦 PHP Application Server (Site)
  # ============================================
  app:
    image: <SUA_IMAGEM_CUSTOMIZADA>  # Imagem customizada com extensões pré-instaladas
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
        # Traefik: Roteamento para Site
        - "traefik.enable=true"
        - "traefik.docker.network=<SUA_REDE_INTERNET_DO_PORTAINER>"
        
        # Site (<SEU_DOMINIO>)
        - "traefik.http.routers.<NOME_APP>-site.rule=Host(`<SEU_DOMINIO>`)"
        - "traefik.http.routers.<NOME_APP>-site.entrypoints=websecure"
        - "traefik.http.routers.<NOME_APP>-site.tls.certresolver=letsencryptresolver"
        - "traefik.http.routers.<NOME_APP>-site.service=<NOME_APP>-site"
        - "traefik.http.services.<NOME_APP>-site.loadbalancer.server.port=80"

    volumes:
      # Arquivos do Site
      - /opt/<NOME_APP>/site:/var/www/<NOME_APP>/site:rw
      
      # Migrations SQL
      - /opt/<NOME_APP>/migrations:/var/www/<NOME_APP>/migrations:rw
      
      # Logs compartilhados
      - /opt/<NOME_APP>/logs/apache2:/var/log/apache2:rw

      # Monta a raiz do projeto em /git para permitir git pull completo
      - /opt/<NOME_APP>:/git:rw
    
    networks:
      - <SUA_REDE_INTERNET_DO_PORTAINER>  # Conecta à rede para acessar outros serviços se necessário
    
    healthcheck:
      test: ["CMD-SHELL", "curl -f http://localhost/ || exit 1"]
      interval: 30s
      timeout: 10s
      retries: 5
      start_period: 90s

  # ========================================
  #  Redis Cache
  # ========================================
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
      - <SUA_REDE_INTERNET_DO_PORTAINER>  # Conecta à rede para acessar outros serviços se necessário
    
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 3s
      retries: 3

  # ========================================
  # 👷 Email Worker Site (Kafka Consumer)
  # ========================================
  email_worker_site:
    image: <SUA_IMAGEM_CUSTOMIZADA>  # Mesma imagem customizada
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
      # Worker precisa acessar o código do site
      - /opt/<NOME_APP>/site:/var/www/<NOME_APP>/site:ro
    
    networks:
      - <SUA_REDE_INTERNET_DO_PORTAINER>  # Conecta à rede para acessar outros serviços se necessário
    
    # Sobrescrever CMD padrão (apache) para rodar worker
    entrypoint: []
    command: ["php", "/var/www/<NOME_APP>/site/cgi-bin/kafka_email_worker.php"]
    
    healthcheck:
      test: ["CMD-SHELL", "pgrep -f kafka_email_worker.php || exit 1"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 60s
  
# ========================================
# 🌐 Networks
# ========================================
networks:  
  <SUA_REDE_INTERNET_DO_PORTAINER>:
    external: true

# ========================================
# 💾 Volumes
# ========================================
volumes:
  redis-data:
    driver: local
```

Salve o arquivo (Ctrl+O, Enter, Ctrl+X).

### 6.3 Deploy no Portainer (Web)

1. Acesse **Portainer** → **Stacks** → **Add Stack**
2. **Name**: `<NOME_APP>`
3. **Build method**: **Web editor**
4. Cole o conteúdo do `docker-compose-deploy.yml` **completamente editado**
5. Clique em **Deploy the stack**

**Aguarde 2-3 minutos** para:
- ✅ Criação dos serviços
- ✅ Pull das imagens (redis)
- ✅ Inicialização dos containers
- ✅ Health checks

### 6.4 Verificar Deploy

No Portainer → **Stacks** → **<NOME_APP>**:

```
✓ app (1/1 replicas running)
✓ redis (1/1 running)
✓ email_worker_site (1/1 running)
```

Todos devem estar **"Running"** (verde).

---

## ✅ Passo 7: Verificação & Testes

### 7.1 Testar Conectividade Entre Containers

```bash
# Obter ID de um container app
CONTAINER_ID=$(docker ps -q -f label=com.docker.swarm.service.name=<NOME_APP>_app | head -1)

# Entrar no container
docker exec -it $CONTAINER_ID bash

# Testar MySQL
mysql -h mysql -u <USUARIO_DB> -p<SENHA_DB> -e "SELECT 1;"
# Esperado: +---+
#           | 1 |

# Testar Redis
redis-cli -h redis ping
# Esperado: PONG

# Testar Kafka
ping -c 1 kafka
# Esperado: 1 packets transmitted, 1 received

# Sair do container
exit
```

### 7.2 Testar Acesso HTTP/HTTPS

```bash
# Testar site
curl -I https://<SEU_DOMINIO>
# Esperado: HTTP/2 200

# Testar SSL
curl -v https://<SEU_DOMINIO> 2>&1 | grep -i "SSL"
# Esperado: SSL certificate verify ok
```

### 7.3 Criar Health Check Endpoint

Crie arquivo `/opt/<NOME_APP>/site/public_html/health.php`:

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
    $redis->ping();
    $health['redis'] = 'ok';
} catch (Exception $e) {
    $health['redis'] = 'error: ' . $e->getMessage();
    $health['status'] = 'degraded';
}

header('Content-Type: application/json');
echo json_encode($health, JSON_PRETTY_PRINT);
?>
```

Testar:
```bash
curl https://<SEU_DOMINIO>/health.php

# Esperado:
# {
#   "status": "ok",
#   "php": "8.4.x",
#   "mysql": "ok",
#   "redis": "ok"
# }
```

---

## 📋 Migrations

O sistema de migrations versiona o banco de dados automaticamente. Migrations são executadas em ordem e rastreadas para nunca repetir.

### Como Funciona

- **Automático**: Roda a cada 5 minutos via cron job
- **Rastreado**: Tabela `migrations_log` controla quais foram executadas
- **Seguro**: Nunca executa a mesma migration duas vezes
- **Logs**: Registra em `/opt/<NOME_APP>/_data/logs/migrations.log`

### Criar uma Migration

Crie um arquivo `.sql` em `/opt/<NOME_APP>/migrations/`:

```bash
# Exemplo: criar tabela de auditoria
cat > /opt/<NOME_APP>/migrations/010_create_audit_table.sql << 'EOF'
CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  table_name VARCHAR(255),
  action VARCHAR(50),
  user_id INT,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOF

# Commit e push
git add migrations/010_create_audit_table.sql
git commit -m "feat: add audit table migration"
git push
```

### Deploy de Migration

Após `git pull` no servidor, a migration é executada automaticamente (no máximo em 5 minutos).

Para verificar:
```bash
tail -f /opt/<NOME_APP>/_data/logs/migrations.log
```

### Executar Manualmente

Se precisar executar imediatamente:

```bash
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=<NOME_APP>_app | head -1) \
  php /var/www/<NOME_APP>/site/cgi-bin/run_migrations.php
```

---

## 🔄 Atualizações com Git

### Workflow

1. **Local**: Commit e push para Git
2. **Servidor**: `git pull` atualiza código
3. **Containers**: Volumes compartilhados usam automaticamente

### Atualizar Código

```bash
ssh <USUARIO_SSH>@<SEU_SERVIDOR>
cd /opt/<NOME_APP>

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
- ⚠️ Alterações nas configurações Apache (site.conf)
- ⚠️ Atualização de dependências Composer
- ⚠️ Alterações na imagem Docker (Dockerfile)

### Restart Manual

```bash
# Via CLI:
docker service update --force <NOME_APP>_app

# Também reiniciar workers se houver alterações
docker service update --force <NOME_APP>_email_worker_site
```

### Rebuild de Imagem (Mudanças no Dockerfile)

```bash
cd /opt/<NOME_APP>/docker/prod

# Rebuild
docker build -t <NOME_APP>:latest .

# Update services
docker service update --image <NOME_APP>:latest <NOME_APP>_app
docker service update --image <NOME_APP>:latest <NOME_APP>_email_worker_site
```

---

## 📊 Monitoramento

### Portainer Dashboard

Acesse via web e visualize:
- **Stacks** → Estado dos serviços
- **Containers** → CPU/RAM por container
- **Logs** → Em tempo real
- **Stats** → Gráficos de uso

### Logs via CLI

```bash
# Logs da aplicação
docker service logs -f <NOME_APP>_app

# Logs do email worker
docker service logs -f <NOME_APP>_email_worker_site

# Últimas 100 linhas
docker service logs --tail 100 <NOME_APP>_app

# Filtrar por erro
docker service logs <NOME_APP>_app 2>&1 | grep -i error
```

### Verificar Saúde dos Serviços

```bash
# Listar serviços
docker service ls

# Detalhar um serviço
docker service ps <NOME_APP>_app

# Inspecionar configuração
docker service inspect <NOME_APP>_app --pretty
```

---

## 🔧 Troubleshooting

### Stack não sobe

```bash
# Ver por que serviço não subiu
docker service ps <NOME_APP>_app --no-trunc

# Comum: Imagem não encontrada
docker images | grep <NOME_APP>

# Se não existir, fazer build
cd /opt/<NOME_APP>/docker/prod
docker build -t <NOME_APP>:latest .
```

### Erro 502 Bad Gateway

```bash
# Verificar se app está rodando
docker service ps <NOME_APP>_app

# Ver logs do Traefik
docker service logs traefik_traefik | grep -i error

# Comum: Nome da rede no compose errado
# Verificar docker-compose-deploy.yml
```

### Erro 500 na Aplicação

```bash
# Ver logs PHP
docker service logs <NOME_APP>_app | tail -50

# Testar conexão MySQL
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=<NOME_APP>_app | head -1) \
  mysql -h mysql -u <USUARIO_DB> -p<SENHA_DB> -e "SELECT 1;"
```

### SSL não funciona

```bash
# Verificar certificados
docker service logs traefik_traefik | grep -i "certificate"

# Verificar DNS
nslookup <SEU_DOMINIO>
# Deve apontar para IP do servidor
```

### Email worker não processa

```bash
# Ver logs
docker service logs -f <NOME_APP>_email_worker_site

# Testar Kafka
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=<NOME_APP>_email_worker_site) \
  ping -c 1 kafka
```

### Redis não conecta

```bash
# Verificar se está rodando
docker service ps <NOME_APP>_redis

# Testar conexão
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=<NOME_APP>_app | head -1) \
  redis-cli -h redis ping
```

---

## 📚 Comandos Úteis

### Docker Service

```bash
# Listar serviços
docker service ls

# Escalar replicas
docker service scale <NOME_APP>_app=3

# Restart forçado
docker service update --force <NOME_APP>_app

# Remover serviço
docker service rm <NOME_APP>_app
```

### Git

```bash
# Status
git status

# Ver alterações
git diff

# Histórico
git log --oneline -10

# Reverter para commit anterior
git checkout <commit_hash> .
```

### Composer (Dentro do Container)

```bash
docker exec -it $(docker ps -q -f label=com.docker.swarm.service.name=<NOME_APP>_app | head -1) bash

cd /var/www/site/app/inc/lib

# Atualizar dependências
composer update

# Adicionar novo pacote
composer require phpmailer/phpmailer

exit
```

---

## ✅ Checklist Final

- [ ] Pré-requisitos verificados (MySQL, Kafka, Traefik, Rede)
- [ ] Projeto clonado em `/opt/<NOME_APP>`
- [ ] Arquivo `docker/prod/site.conf` editado com seu domínio
- [ ] Imagem `<NOME_APP>:latest` criada com build
- [ ] Arquivo `site/app/inc/kernel.php` configurado com credenciais
- [ ] Banco de dados `<NOME_BANCO>` criado no MySQL
- [ ] Dependências Composer instaladas
- [ ] `docker-compose-deploy.yml` editado com seus valores
- [ ] Stack `<NOME_APP>` deployada no Portainer
- [ ] Todos serviços rodando (app, redis, email_worker_site)
- [ ] DNS apontado para servidor
- [ ] SSL/TLS funcionando (HTTPS)
- [ ] Health endpoint `/health.php` retornando `status: ok`
- [ ] Email workers processando mensagens (logs)

---

## 🚀 Próximas Etapas

1. **Configurar Backup** - Agendar backup de MySQL e uploads
2. **Monitorar Performance** - Grafana + Prometheus (opcional)
3. **Escalar** - Aumentar replicas conforme demanda
4. **CI/CD** - Automatizar deploy com GitHub Actions
5. **Logs Centralizados** - ELK Stack ou similar

---

## 📞 Documentação Adicional

- **Desenvolvimento**: [README.md](README.md)
- **Emails com Kafka**: [KAFKA_EMAIL.md](KAFKA_EMAIL.md)
- **Cache Redis**: [REDIS.md](REDIS.md)
- **Migrations**: [MIGRATIONS.md](MIGRATIONS.md)

---

**Nexo Framework - Deploy em Produção**  
Portainer + Docker Swarm + Git  
Última atualização: Janeiro 2026
