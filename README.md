# Nexo Framework - Desenvolvimento Local

[![PHP Version](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://www.php.net/)
[![MySQL Version](https://img.shields.io/badge/MySQL-8.0-orange.svg)](https://www.mysql.com/)
[![Redis Version](https://img.shields.io/badge/Redis-7.2-red.svg)](https://redis.io/)
[![Kafka Version](https://img.shields.io/badge/Kafka-Latest-black.svg)](https://kafka.apache.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue.svg)](https://www.docker.com/)

**Framework web em PHP 8.4+** com arquitetura MVC, cache Redis, emails assíncronos via Kafka e MySQL 8.0. Ambiente de desenvolvimento pronto em Docker.

> **Este documento é para DESENVOLVIMENTO LOCAL.** Para produção, consulte [MANUAL_DEPLOY.md](MANUAL_DEPLOY.md)

---

## 📚 Índice

1. [Visão Geral](#-visão-geral)
2. [Pré-requisitos](#-pré-requisitos)
3. [Setup em 5 Passos](#-setup-em-5-passos)
4. [Acessar Aplicação](#-acessar-aplicação)
5. [Estrutura de Diretórios](#-estrutura-de-diretórios)
6. [Rotas & Controllers](#-rotas--controllers)
7. [Alpine.js - Interatividade](#-alpinejs-interatividade-frontend)
8. [Redis Cache](#-redis-cache)
9. [Emails com Kafka](#-emails-assíncronos-kafka)
10. [Migrations](#-migrations)
11. [Troubleshooting](#-troubleshooting)

---

## 🏗️ Visão Geral

```
Browser → Apache (<NOME_APP>.local)
         ↓
       Site [PHP 8.4 + Composer]
         ↓
    ┌────┴────┬──────────┬─────────┐
    │          │          │         │
  MySQL     Redis      Kafka     Logs
  8.0       7.2      Assync    Apache2
```

**O que está incluído:**
- ✅ Apache 2.4 com PHP 8.4 em Docker
- ✅ MySQL 8.0 para dados
- ✅ Redis 7.2 para cache automático
- ✅ Kafka para fila de emails assíncronos
- ✅ Composer para dependências
- ✅ Virtual host `<NOME_APP>.local` pré-configurado
- ✅ Kafka UI para monitoramento em http://localhost:8080

---

## 🛠️ Pré-requisitos

**Obrigatório:**
- Docker Desktop ou Docker + Compose (20.10+)
- Git
- 1GB RAM livre
- 5GB espaço em disco

**Verificar:**
```bash
docker --version
docker-compose --version
git --version
```

---

## 🚀 Setup em 5 Passos

### Passo 1: Clonar Repositório

```bash
git clone https://github.com/<USUARIO>/<REPO>.git <NOME_PROJETO>
cd <NOME_PROJETO>
```

### Passo 2: Copiar Configurações

```bash
cp site/app/inc/kernel.php.example site/app/inc/kernel.php
```

**Edite o `kernel.php` com suas credenciais** (veja seção [Configurar kernel.php](#configurar-kernelphp) abaixo).

### Passo 3: Subir Containers

```bash
cd docker
docker-compose up -d --build

# Aguarde 60 segundos para Kafka inicializar
sleep 60

# Verificar status
docker ps
```

Esperado: 4 containers rodando (mysql, redis, kafka, apache)

### Passo 4: Instalar Composer

```bash
docker exec -it apache_<NOME_APP> bash
cd /var/www/<NOME_APP>/site/app/inc/lib
composer install
exit
```

### Passo 5: Configurar Hosts Locais

Adicione ao arquivo de hosts:

**Linux/Mac:**
```bash
sudo nano /etc/hosts
# Adicione:
127.0.0.1 <NOME_APP>.local
```

**Windows:**
```
C:\Windows\System32\drivers\etc\hosts
# Adicione:
127.0.0.1 <NOME_APP>.local
```

### ✅ Pronto!

Acesse em seu navegador:
- **Site**: http://<NOME_APP>.local
- **Kafka UI**: http://localhost:8080

---

## 🌐 Acessar Aplicação

### Via Browser

```
http://<NOME_APP>.local → Site
http://localhost:8080   → Kafka UI (monitoramento)
```

### Via Terminal

```bash
# Entrar no container
docker exec -it apache_<NOME_APP> bash

# Testar MySQL
mysql -h mysql_<NOME_APP> -u user_<NOME_APP> -p<SENHA_DB> -e "SELECT 1;"

# Testar Redis
redis-cli -h redis_<NOME_APP> ping

# Ver logs Apache
tail -f /var/log/apache2/error.log

exit
```

---

## 📁 Estrutura de Diretórios

```
.
├── docker/
│   ├── docker-compose.yml           # Orquestração containers
│   └── core/
│       ├── Dockerfile               # PHP 8.4 + Apache
│       ├── entrypoint.sh
│       ├── site.conf                # VirtualHost Site
│       ├── crontab.txt              # Cron jobs
│       └── php.ini
│
├── site/                            # Site Público
│   ├── app/inc/
│   │   ├── kernel.php               # [EDITAR] Configurações
│   │   ├── main.php                 # Loader
│   │   ├── urls.php                 # Rotas
│   │   ├── controller/              # Controllers
│   │   ├── model/                   # Models
│   │   └── lib/
│   │       ├── DOLModel.php         # ORM + cache
│   │       ├── RedisCache.php       # Client Redis
│   │       ├── EmailProducer.php    # Producer Kafka
│   │       ├── dispatcher.php       # Roteador
│   │       └── vendor/              # Composer
│   ├── cgi-bin/
│   │   └── kafka_email_worker.php   # Worker emails
│   └── public_html/
│       ├── index.php                # Front controller
│       ├── assets/
│       │   ├── css/
│       │   ├── js/alpine/           # Alpine.js controllers
│       │   └── img/
│       └── ui/
│           ├── common/              # Components
│           └── page/                # Pages
│
├── _data/                           # [NÃO versionar]
│   ├── mysql-data/
│   ├── redis-data/
│   ├── kafka-data/
│   ├── logs/apache2/
│   └── upload/
│
├── migrations/
│   ├── 001_create_migrations_log.sql
│   ├── 002_users.sql
│   └── 003_...sql
│
├── MANUAL_DEPLOY.md                 # Produção
├── KAFKA_EMAIL.md                   # Emails
├── REDIS.md                         # Cache
└── README.md                        # Este arquivo
```

---

## ⚙️ Configurar kernel.php

Edite `site/app/inc/kernel.php`:

```php
<?php

// ===== BANCO DE DADOS =====
define("DB_HOST", "mysql_<NOME_APP>");
define("DB_NAME", "mysql_<NOME_APP>");
define("DB_USER", "user_<NOME_APP>");
define("DB_PASS", "123456");

// ===== REDIS =====
define("REDIS_HOST", "redis_<NOME_APP>");
define("REDIS_PORT", 6379);
define("REDIS_PREFIX", "<NOME_APP>:site:");      // Prefixo único para Site
define("REDIS_DATABASE", 0);               // DB 0 = Site
define("REDIS_ENABLED", true);
define("REDIS_DEFAULT_TTL", 3600);

// ===== KAFKA =====
define("KAFKA_HOST", "kafka_<NOME_APP>");
define("KAFKA_PORT", "9092");
define("KAFKA_TOPIC_EMAIL", "<NOME_APP>_site_emails");
define("KAFKA_CONSUMER_GROUP", "<NOME_APP>-email-worker-group");

// ===== EMAIL (SMTP) =====
define("mail_from_name", "<SEU_PROJETO>");
define("mail_from_mail", "noreply@<SEU_DOMINIO>");
define("mail_from_host", "smtp.gmail.com");
define("mail_from_port", "587");
define("mail_from_user", "<SEU_EMAIL>");
define("mail_from_pwd", "<SENHA_APP>");

// ===== APP =====
define("cAppKey", "<NOME_APP>_site_session");
define("cTitle", "<NOME_APP>");
define("cAppRoot", "/");
define("cFrontend", sprintf("http://%s%s", $_SERVER["HTTP_HOST"], constant("cAppRoot")));
define("cAssets", sprintf("%s%s", constant("cFrontend"), "assets/"));

// ===== UPLOAD =====
define("UPLOAD_DIR", "/var/www/<NOME_APP>/site/public_html/assets/upload/");
define("UPLOAD_MAX_SIZE", 10);
define("UPLOAD_ALLOWED_TYPES", "jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx");
```

---

## 🔗 Rotas & Controllers

### Definir Rota

Edite `site/app/inc/urls.php`:

```php
$GLOBALS["URLs"] = [
    "home" => [
        "method" => "get",
        "controller" => "site_controller",
        "action" => "display",
    ],
    "about" => [
        "method" => "get",
        "controller" => "site_controller",
        "action" => "about",
    ],
];
```

Acesse: `http://<NOME_APP>.local?sr=home`

### Criar Controller

Crie `site/app/inc/controller/site_controller.php`:

```php
<?php

class site_controller
{
    public function display($info)
    {
        // Definir controllers Alpine.js para esta página
        $alpineControllers = ['counterController'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/home.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function about($info)
    {
        echo "<h1>About Us</h1>";
    }
}
```

### Criar Model

Crie `site/app/inc/model/users_model.php`:

```php
<?php

class users_model extends DOLModel
{
    protected $field = ["idx", "name", "email", "password", "created_at"];
    protected $filter = ["active = 'yes'"];

    function __construct($bd = false)
    {
        return parent::__construct("users", $bd);
    }
}
```

### Usar Model no Controller

```php
<?php

class site_controller
{
    public function display($info)
    {
        // Carregar usuários (com cache automático Redis)
        $users = new users_model();
        $users->load_data();
        
        // Usar em view
        $alpineControllers = ['usersController'];
        
        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/page/users.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }
}
```

### Criar View

Crie `site/public_html/ui/page/home.php`:

```html
<div class="container mt-5">
    <h1>Bem-vindo ao <NOME_APP></h1>
    <p>Framework PHP 8.4+ com Redis, Kafka e MySQL</p>
</div>
```

---

## 🎨 Alpine.js - Interatividade Frontend

Alpine.js é um framework JavaScript minimalista para adicionar reatividade sem complexidade.

### Criar Controller Alpine.js

Crie `site/public_html/assets/js/alpine/counterController.js`:

```javascript
document.addEventListener("alpine:init", () => {
  Alpine.data("counterController", () => ({
    count: 0,

    increment() {
      this.count++;
    },

    decrement() {
      this.count--;
    },

    reset() {
      this.count = 0;
    },
  }));
});
```

### Usar no HTML

Crie `site/public_html/ui/page/home.php`:

```html
<div class="card" x-data="counterController">
    <div class="card-body">
        <h3>Contador: <span x-text="count"></span></h3>
        
        <button @click="increment()" class="btn btn-success">➕ +</button>
        <button @click="decrement()" class="btn btn-danger">➖ -</button>
        <button @click="reset()" class="btn btn-secondary">🔄 Reset</button>
    </div>
</div>
```

### Carregar no Controller PHP

```php
<?php
class site_controller
{
    public function display($info)
    {
        // Carregar apenas controllers necessários
        $alpineControllers = ['counterController'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/page/home.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }
}
```

### Diretivas Alpine.js Principais

| Diretiva | Uso |
|----------|-----|
| `x-data` | Define escopo do controller |
| `x-text` | Exibe variável |
| `x-show` | Mostra/oculta elemento |
| `x-if` | Renderização condicional |
| `x-for` | Loop sobre array |
| `@click` | Evento de clique |
| `x-model` | Two-way binding |
| `x-init` | Inicialização |

### Exemplo Completo: Dashboard

**Controller**:
```javascript
document.addEventListener("alpine:init", () => {
  Alpine.data("dashController", () => ({
    stats: { users: 0, posts: 0 },
    
    init() {
      this.loadStats();
    },
    
    async loadStats() {
      const response = await fetch('?sr=api&action=stats');
      this.stats = await response.json();
    },
  }));
});
```

**HTML**:
```html
<div x-data="dashController">
    <div class="row">
        <div class="col-md-6">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5>Usuários</h5>
                    <h2 x-text="stats.users"></h2>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5>Posts</h5>
                    <h2 x-text="stats.posts"></h2>
                </div>
            </div>
        </div>
    </div>
</div>
```

---

## 🔴 Redis Cache

Redis armazena dados em **memória** para acesso ultra-rápido.

### Uso Básico

```php
$redis = RedisCache::getInstance();

// Armazenar
$redis->set('user:123', json_encode($user), 3600);

// Recuperar
$user = json_decode($redis->get('user:123'), true);

// Remover
$redis->delete('user:123');
```

### Cache Automático no Model

```php
// 1ª chamada: busca MySQL + cache
$users = new users_model();
$users->load_data();

// 2ª chamada: retorna do cache (super rápido!)
$users2 = new users_model();
$users2->load_data();
```

### Monitorar Redis

```bash
docker exec -it redis_<NOME_APP> redis-cli

KEYS *           # Ver chaves
FLUSHDB          # Limpar tudo
INFO             # Informações

exit
```

---

## ✉️ Emails Assíncronos (Kafka)

Emails **não bloqueiam** sua aplicação. Funcionam em fila!

### Enviar Email

```php
$emailer = EmailProducer::getInstance();

// Simples
$emailer->send(
    'user@example.com',
    'Bem-vindo!',
    '<h1>Olá!</h1>'
);
```

### Iniciar Worker (Terminal Separado)

```bash
docker exec -it apache_<NOME_APP> bash

cd /var/www/<NOME_APP>/site/cgi-bin
php kafka_email_worker.php

# Esperado:
# [INFO] Email Worker iniciado
# [INFO] Aguardando mensagens...
```

### Monitorar

**Kafka UI:**
```
http://localhost:8080
```

**Logs:**
```bash
docker exec -it apache_<NOME_APP> tail -f /var/www/<NOME_APP>/site/app/logs/email_worker.log
```

---

## 📋 Migrations

Sistema simples para versionamento do banco de dados.

### Criar Migration

```bash
cat > migrations/002_users_table.sql << 'EOF'
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255),
  email VARCHAR(255) UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOF
```

### Executar

**Via CLI:**
```bash
docker exec -it apache_<NOME_APP> php /var/www/<NOME_APP>/site/cgi-bin/run_migrations.php
```

**Via Web:**
```
http://<NOME_APP>.local/migrations.php
```

### Ver Logs

```bash
tail -f _data/logs/migrations.log
```

---

## 🐛 Troubleshooting

### MySQL não conecta

```bash
docker logs mysql_<NOME_APP>
docker exec -it apache_<NOME_APP> mysql -h mysql_<NOME_APP> -u user_<NOME_APP> -p<SENHA_DB> -e "SELECT 1;"
```

### Redis não conecta

```bash
docker logs redis_<NOME_APP>
docker restart redis_<NOME_APP>
```

### Kafka demora para iniciar

```bash
# Aguarde 60 segundos após docker-compose up
sleep 60
docker logs kafka_<NOME_APP>
```

### Erro de permissão em upload

```bash
chmod -R 755 _data/logs/
chmod -R 755 site/public_html/assets/upload/
chmod -R 755 manager/public_html/assets/upload/
```

### Porta 80 ocupada

Se a porta 80 está em uso, edite `docker/docker-compose.yml`:

```yaml
services:
  apache_<NOME_APP>:
    ports:
      - "8000:80"  # Porta 8000 localmente
```

Acesse: `http://localhost:8000`

---

## 📖 Documentação Adicional

- [REDIS.md](REDIS.md) - Cache em profundidade e boas práticas
- [KAFKA_EMAIL.md](KAFKA_EMAIL.md) - Emails assíncronos detalhados
- [MANUAL_DEPLOY.md](MANUAL_DEPLOY.md) - Deploy em produção com Portainer

---

## 🚀 Próximos Passos

1. ✅ Clonar e setup
2. ✅ Configurar `kernel.php`
3. Criar Models (estender `DOLModel`)
4. Criar Controllers (lógica)
5. Criar Views (HTML)
6. Definir rotas em `urls.php`
7. Adicionar interatividade com Alpine.js
8. Implementar emails com Kafka

---

**Desenvolvido com ❤️ usando PHP 8.4+, MySQL 8.0, Redis 7.2, Apache Kafka e Docker**

Última atualização: Janeiro 2026
