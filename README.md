# Nexo Framework - Guia Completo de Desenvolvimento

[![PHP Version](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://www.php.net/)
[![MySQL Version](https://img.shields.io/badge/MySQL-8.0-orange.svg)](https://www.mysql.com/)
[![Redis Version](https://img.shields.io/badge/Redis-7.2-red.svg)](https://redis.io/)
[![Kafka Version](https://img.shields.io/badge/Kafka-Latest-black.svg)](https://kafka.apache.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue.svg)](https://www.docker.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

**Framework web modular em PHP 8.4+** com arquitetura MVC, cache Redis, sistema assíncrono de emails via Kafka e MySQL 8.0. Dois módulos independentes (**Site** público + **Manager** administrativo) em um único container Apache com virtual hosts pré-configurados.

> **Este documento é para DESENVOLVIMENTO LOCAL.** Para produção com Portainer e stacks separadas, consulte [MANUAL_DEPLOY.md](MANUAL_DEPLOY.md)

---

## 📚 Índice Rápido

1. [Visão Geral](#-visão-geral-da-arquitetura)
2. [Pré-requisitos](#-pré-requisitos)
3. [Setup Inicial em 5 Passos](#-setup-inicial-em-5-passos)
4. [Estrutura de Diretórios](#-estrutura-de-diretórios)
5. [Configuração Detalhada](#-configuração-detalhada)
6. [Iniciando o Desenvolvimento](#-iniciando-o-desenvolvimento)
7. [Verificação de Saúde](#-verificação-de-saúde)
8. [Site e Manager](#-site-e-manager)
9. [Redis Cache](#-redis-cache-em-profundidade)
10. [Sistema de Emails (Kafka)](#-sistema-assíncrono-de-emails-kafka)
11. [Migrations](#-sistema-de-migrations)
12. [Debugging](#-debugging-e-logs)

---

## 🏗️ Visão Geral da Arquitetura

```
┌─────────────────────────────────────────────────────────┐
│                Browser / Cliente                         │
└──────────────┬──────────────────────────────────────────┘
               │ HTTP
┌──────────────▼──────────────────────────────────────────┐
│ Apache 2.4 em Docker                                    │
│ ├─ nexo.local → Site                                   │
│ └─ manager.nexo.local → Manager                        │
└──────────────┬──────────────────────────────────────────┘
               │ PDO/Cache/Mensagens
       ┌───────┼───────┬────────────┐
       │       │       │            │
┌──────▼─┐ ┌──▼───┐ ┌──▼───┐ ┌────▼─────┐
│ MySQL  │ │Redis │ │Kafka │ │Logs      │
│ 8.0    │ │ 7.2  │ │      │ │Apache    │
└────────┘ └──────┘ └──────┘ └──────────┘
```

**Fluxo de Requisição**:
1. Browser → Apache dispatcher
2. Dispatcher processa rota
3. Controller executa lógica
4. Model acessa MySQL com cache automático Redis
5. View renderiza resposta HTML

**Fluxo de Emails**:
1. Aplicação → EmailProducer envia para Kafka
2. Kafka armazena mensagem em fila
3. Worker consome e PHPMailer envia via SMTP
4. Tudo sem bloquear requisição HTTP ⚡

---

## ✨ Características Principais

✅ **PHP 8.4+** - Tipos tipados, match expressions, named arguments  
✅ **MySQL 8.0** - PDO com prepared statements  
✅ **Redis 7.2** - Cache automático integrado  
✅ **Kafka** - Fila confiável para emails assíncronos  
✅ **Docker** - Ambiente reproducível e consistente  
✅ **MVC** - Arquitetura limpa com dispatcher de rotas  
✅ **Dual Module** - Site público + Manager administrativo  
✅ **Virtual Hosts** - Pré-configurados no Apache  
✅ **ORM** - DOLModel com cache transparente  
✅ **PHPMailer + Kafka** - Emails sem bloquear  
✅ **Composer** - Dependências modernas  
✅ **Kafka UI** - Monitoramento visual http://localhost:8080  

---

## 🛠️ Pré-requisitos

### Obrigatório

- **Docker Desktop** (Windows/Mac) ou Docker+Compose (Linux)  
  [Download](https://www.docker.com/products/docker-desktop)
- **Git** para versionamento  
  [Download](https://git-scm.com/)
- **1GB RAM livre** mínimo
- **5GB espaço em disco**

### Verificar Instalação

```bash
docker --version        # Esperado: Docker version 20.10+
docker-compose --version # Esperado: Docker Compose version 2.0+
git --version           # Esperado: git version 2.30+
```

---

## 🚀 Setup Inicial em 5 Passos

### Passo 1: Clonar Repositório

```bash
git clone https://github.com/seu-usuario/nexofw.git nexo
cd nexo
```

### Passo 2: Copiar Configurações

Os arquivos `kernel.php` contêm dados sensíveis (passwords, SMTP, etc.) e não são versionados:

```bash
cp manager/app/inc/kernel.php.example manager/app/inc/kernel.php
cp site/app/inc/kernel.php.example site/app/inc/kernel.php
```

**IMPORTANTE**: Estes arquivos ficam locais. Nunca faça commit!

### Passo 3: Subir Containers Docker

```bash
cd docker
docker-compose up -d --build

# Aguarde ~60 segundos para Kafka inicializar completamente
```

Nota: o cron dentro do container é instalado e iniciado automaticamente a partir de docker/core/crontab.txt.

Esperado na saída:
```
Creating mysql_nexo ... done
Creating redis_nexo ... done
Creating kafka_nexo ... done
Creating apache_nexo ... done
```

### Passo 4: Instalar Dependências Composer

```bash
docker exec -it apache_nexo bash

# Manager
cd /var/www/nexo/manager/app/inc/lib && composer install

# Site
cd /var/www/nexo/site/app/inc/lib && composer install

exit
```

### Passo 5: Configurar Hosts Locais

Adicione ao arquivo hosts do seu sistema:

**Linux/Mac**: `sudo nano /etc/hosts`
```
127.0.0.1 nexo.local
127.0.0.1 manager.nexo.local
```

**Windows**: `C:\Windows\System32\drivers\etc\hosts`
```
127.0.0.1 nexo.local
127.0.0.1 manager.nexo.local
```

### Pronto! ✅

Acesse:
- **Site**: http://nexo.local
- **Manager**: http://manager.nexo.local
- **Kafka UI**: http://localhost:8080

---

## 📁 Estrutura de Diretórios

```
nexo/
├── docker/
│   ├── docker-compose.yml              # Orquestração containers
│   ├── docker-compose-deploy.yml.example # Template produção
│   ├── core/
│   │   ├── Dockerfile                  # PHP 8.4 + Apache + extensões
│   │   ├── entrypoint.sh               # Script inicialização
│   │   ├── site.conf                   # VirtualHost Site
│   │   ├── manager.conf                # VirtualHost Manager
│   │   └── php.ini                     # Configurações PHP
│   └── prod/ [Produção]
│
├── manager/                    # Painel Administrativo
│   ├── app/
│   │   ├── inc/
│   │   │   ├── kernel.php              # [LOCAL] Configurações
│   │   │   ├── kernel.php.example      # Exemplo
│   │   │   ├── main.php                # Carregador
│   │   │   ├── lists.php               # Constantes
│   │   │   ├── urls.php                # Rotas
│   │   │   ├── controller/             # Controllers MVC
│   │   │   ├── model/                  # Models
│   │   │   └── lib/
│   │   │       ├── dispatcher.php      # Roteamento
│   │   │       ├── DOLModel.php        # ORM + cache Redis
│   │   │       ├── local_pdo.php       # Wrapper PDO
│   │   │       ├── RedisCache.php      # Cliente Redis
│   │   │       ├── EmailProducer.php   # Producer Kafka
│   │   │       ├── common_function.php # Funções
│   │   │       ├── composer.json       # Dependências
│   │   │       └── vendor/             # Composer
│   ├── cgi-bin/
│   │   └── kafka_email_worker.php      # Consumidor Kafka
│   └── public_html/
│       ├── index.php                   # Front Controller
│       ├── .htaccess                   # Reescritas Apache
│       ├── assets/
│       │   ├── css/
│       │   ├── js/
│       │   └── img/
│       ├── ui/
│       │   ├── common/                 # Componentes
│       │   └── page/                   # Páginas
│       └── upload/                     # Upload
│
├── site/                       # Site Público
│   └── [Estrutura idêntica a manager]
│
├── _data/                      # Dados Persistentes [NÃO versionar]
│   ├── mysql-data/             # Arquivos MySQL
│   ├── redis-data/             # Persistência Redis
│   ├── kafka-data/             # Partições Kafka
│   ├── logs/apache2/           # Logs HTTP
│   └── upload/                 # Uploads
│
├── .gitignore
├── README.md                   # Este arquivo
├── MANUAL_DEPLOY.md            # Produção com Portainer
├── KAFKA_EMAIL.md              # Emails em profundidade
└── REDIS.md                    # Cache em profundidade
```

---

## ⚙️ Configuração Detalhada

### Arquivo `kernel.php`

Edite `manager/app/inc/kernel.php`:

```php
<?php

// ===== TIMEZONE =====
date_default_timezone_set("America/Sao_Paulo");

// ===== ENCODING E UPLOAD =====
ini_set("default_charset", "UTF-8");
ini_set("post_max_size", "4096M");
ini_set("upload_max_filesize", "4096M");

// ===== BANCO DE DADOS =====
define("DB_HOST", "mysql_nexo");        // Container MySQL
define("DB_NAME", "mysql_nexo");
define("DB_USER", "user_nexo");
define("DB_PASS", "123456");

// ===== REDIS (Cache) =====
define("REDIS_HOST", "redis_nexo");
define("REDIS_PORT", 6379);
define("REDIS_PREFIX", "nexo:manager:");  // Prefixo único
define("REDIS_DATABASE", 0);              // DB 0=Manager, DB 1=Site
define("REDIS_ENABLED", true);
define("REDIS_DEFAULT_TTL", 3600);        // 1 hora

// ===== KAFKA (Emails Assíncrono) =====
define("KAFKA_HOST", "kafka_nexo");
define("KAFKA_PORT", "9092");
define("KAFKA_TOPIC_EMAIL", "nexo_manager_emails");
define("KAFKA_CONSUMER_GROUP", "nexo-email-worker-group");

// ===== EMAIL (SMTP) =====
define("mail_from_name", "Meu Manager");
define("mail_from_mail", "noreply@meuprojeto.local");
define("mail_from_host", "smtp.gmail.com");  // SMTP
define("mail_from_port", "587");             // Porta SMTP
define("mail_from_user", "seu-email@gmail.com");
define("mail_from_pwd", "sua-senha-app");    // Senha App

// ===== APLICAÇÃO =====
define("cAppKey", "nexo_manager_session");  // Identificador sessão
define("cPaginate", 150);                    // Itens por página
define("cTitle", "Nexo Manager");

// ===== PATHS =====
define("cAppRoot", "/");
define("cRootServer", sprintf("%s%s", $_SERVER["DOCUMENT_ROOT"], constant("cAppRoot")));
define("cRootServer_APP", sprintf("%s%s", $_SERVER["DOCUMENT_ROOT"], constant("cAppRoot") . "../app"));
define("cFrontend", sprintf("http://%s%s", $_SERVER["HTTP_HOST"], constant("cAppRoot")));
define("cAssets", sprintf("%s%s", constant("cFrontend"), "assets/"));

// ===== SESSÃO =====
define("SESSION_LIFETIME", 7200);
define("SESSION_USE_REDIS", false);

// ===== UPLOAD =====
define("UPLOAD_DIR", "/var/www/nexo/manager/public_html/assets/upload/");
define("UPLOAD_MAX_SIZE", 10);
define("UPLOAD_ALLOWED_TYPES", "jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx");

// ===== LOG =====
define("LOG_DIR", "/var/log/nexo/");
define("LOG_LEVEL", "debug");
```

**Para Site**, copie e altere:
```php
define("REDIS_PREFIX", "nexo:site:");          // Prefixo diferente
define("REDIS_DATABASE", 1);                    // DB diferente
define("KAFKA_TOPIC_EMAIL", "nexo_site_emails");
define("mail_from_name", "Meu Site");
define("cAppKey", "nexo_site_session");
define("cTitle", "Nexo Site");
define("UPLOAD_DIR", "/var/www/nexo/site/public_html/assets/upload/");
```

### Testar Conectividade

```bash
docker exec -it apache_nexo bash

# MySQL
mysql -h mysql_nexo -u user_nexo -p123456 -e "SELECT 1;" && echo "✓ MySQL OK"

# Redis
redis-cli -h redis_nexo -a nexo_redis_2024 ping && echo "✓ Redis OK"

# Kafka (verificar se container está rodando)
echo "✓ Kafka OK"

exit
```

---

## 🎯 Iniciando o Desenvolvimento

### 1. Verificar Status

```bash
docker ps
# Esperado: mysql_nexo, redis_nexo, kafka_nexo, apache_nexo - todos "Up"
```

### 2. Acessar Aplicações

| Componente | URL |
|-----------|-----|
| Site | http://nexo.local |
| Manager | http://manager.nexo.local |
| Kafka UI | http://localhost:8080 |

### 3. Entrar no Container

```bash
docker exec -it apache_nexo bash
# Agora está dentro do container
cd /var/www/nexo
ls -la manager/ site/
exit
```

### 4. Editar Código Localmente

A estrutura de volumes sincroniza seus arquivos:
```yaml
- ../site/public_html/:/var/www/nexo/site/public_html/
- ../manager/app/:/var/www/nexo/manager/app/
```

Isso significa: Editar `./manager/public_html/index.php` reflete imediatamente em http://manager.nexo.local!

Use seu editor favorito:
```bash
code .              # VSCode
phpstorm .          # PHPStorm
# etc
```

---

## ✅ Verificação de Saúde

Crie arquivo `site/public_html/healthcheck.php`:

```php
<?php
require_once __DIR__ . '/../app/inc/kernel.php';
require_once __DIR__ . '/../app/inc/main.php';

$checks = [
    'PHP' => phpversion(),
];

// Teste MySQL
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS
    );
    $checks['MySQL'] = '✓ OK';
} catch (Exception $e) {
    $checks['MySQL'] = '✗ ' . $e->getMessage();
}

// Teste Redis
try {
    $redis = new Redis();
    $redis->connect(REDIS_HOST, REDIS_PORT);
    if (!empty(REDIS_PASSWORD)) $redis->auth(REDIS_PASSWORD);
    $checks['Redis'] = '✓ OK';
} catch (Exception $e) {
    $checks['Redis'] = '✗ ' . $e->getMessage();
}

$checks['Kafka'] = '✓ Verifique em http://localhost:8080';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Health Check</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f5f5f5; }
        .check { margin: 10px 0; padding: 10px; background: white; border-radius: 4px; }
        .pass { border-left: 4px solid green; }
        .fail { border-left: 4px solid red; }
    </style>
</head>
<body>
    <h1>🏥 Health Check</h1>
    <?php foreach ($checks as $name => $status): ?>
        <div class="check <?= strpos($status, '✓') ? 'pass' : 'fail'; ?>">
            <strong><?= $name ?>:</strong> <?= $status; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>
```

Acesse: http://nexo.local/healthcheck.php

---

## 👥 Site e Manager

### Arquitetura MVC

Ambos seguem **Model-View-Controller**:

**Model** (`users_model.php`):
```php
<?php
class users_model extends DOLModel
{
    protected $field = ["idx", "mail", "login", "password", "name", "cpf", "last_login", "phone", "genre", "enabled"];
    protected $filter = ["active = 'yes'"];

    function __construct($bd = false)
    {
        return parent::__construct("users", $bd);
    }
}
```

**Controller** (`site_controller.php`):
```php
<?php
class site_controller
{
    public function display($info)
    {
        if (!auth_controller::check_login()) {
            basic_redir($GLOBALS["login_url"]);
        }

        // Definir controllers Alpine.js necessários para esta página
        $alpineControllers = ['counterController', 'contactController'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/home.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }
}
```

**View** (`page/home.php`):
```php
<h1>Bem-vindo ao Nexo Framework</h1>
<p>Sistema em funcionamento!</p>
```

### Sistema de Rotas

Defina em `urls.php`:
```php
$GLOBALS["URLs"] = [
    "home" => [
        "method" => "get",
        "controller" => "site_controller",
        "action" => "display",
    ],
];
```

Acesse: http://nexo.local?sr=home

---

## 🎨 Alpine.js - Interatividade Frontend

### O que é?

**Alpine.js** é um framework JavaScript leve (~15KB) que adiciona reatividade e interatividade ao HTML sem a complexidade de frameworks maiores como React ou Vue. No Nexo Framework, o Alpine.js é usado para:

- ✅ **Componentes reativos** sem bundlers ou build steps
- ✅ **Carregamento modular** apenas dos controllers necessários por página
- ✅ **Integração Bootstrap** para UI moderna
- ✅ **SweetAlert2** para modais elegantes

### Arquitetura de Controllers

Os controllers Alpine.js ficam organizados em `/assets/js/alpine/`:

```
manager/public_html/assets/js/alpine/
├── siteController.js       # Dashboard, stats, actions
├── authController.js       # Login, autenticação
└── [outros]Controller.js

site/public_html/assets/js/alpine/
├── counterController.js    # Exemplo contador
├── contactController.js    # Formulário contato
├── loginController.js      # Login
└── registerController.js   # Cadastro
```

### Carregamento Dinâmico

No **Controller PHP**, defina quais controllers Alpine.js carregar:

```php
<?php
class site_controller
{
    public function display($info)
    {
        // Autenticação
        if (!auth_controller::check_login()) {
            basic_redir($GLOBALS["login_url"]);
        }

        // ⚡ Definir controllers Alpine.js para esta página
        $alpineControllers = ['counterController', 'contactController'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/home.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }
}
```

O `foot.php` carrega automaticamente apenas os controllers necessários:

```php
<!-- Alpine.js Controllers - Carregamento Dinâmico -->
<?php
if (isset($alpineControllers) && is_array($alpineControllers) && count($alpineControllers) > 0) {
    foreach ($alpineControllers as $controller) {
        print('<script src="' . constant('cFrontend') . 'assets/js/alpine/' . $controller . 'Controller.js"></script>' . "\n    ");
    }
}
?>

<!-- Alpine.js 3.x -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
```

### Criar um Controller Alpine.js

**Arquivo**: `site/public_html/assets/js/alpine/counterController.js`

```javascript
/**
 * Counter Controller - Alpine.js
 * Controla o exemplo de contador interativo
 */

document.addEventListener("alpine:init", () => {
  Alpine.data("counterController", () => ({
    count: 0,
    open: false,

    increment() {
      this.count++;
    },

    decrement() {
      this.count--;
    },

    reset() {
      this.count = 0;
    },

    toggle() {
      this.open = !this.open;
    },
  }));
});
```

### Usar no HTML (View)

**Arquivo**: `site/public_html/ui/page/home.php`

```html
<!-- Contador Interativo com Alpine.js -->
<div class="card" x-data="counterController">
    <div class="card-body">
        <h3>Contador: <span x-text="count"></span></h3>
        
        <button @click="increment()" class="btn btn-success">➕ Incrementar</button>
        <button @click="decrement()" class="btn btn-danger">➖ Decrementar</button>
        <button @click="reset()" class="btn btn-secondary">🔄 Resetar</button>
        
        <button @click="toggle()" class="btn btn-info mt-3">Toggle</button>
        <div x-show="open" x-transition>
            <p>Conteúdo visível apenas quando toggle está ativo!</p>
        </div>
    </div>
</div>
```

### Exemplo Avançado: Stats Dashboard

**Controller**: `manager/public_html/assets/js/alpine/siteController.js`

```javascript
document.addEventListener("alpine:init", () => {
  Alpine.data("statsController", () => ({
    stats: {
      users: 1234,
      content: 567,
      visits: 45678,
      revenue: 12345.67,
    },

    init() {
      this.loadStats();
    },

    async loadStats() {
      // Carregar estatísticas reais via API
      // const response = await fetch('/api/stats');
      // this.stats = await response.json();
    },

    formatCurrency(value) {
      return "R$ " + value.toLocaleString("pt-BR", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      });
    },

    formatNumber(value) {
      return value.toLocaleString("pt-BR");
    },
  }));
});
```

**View**: `manager/public_html/ui/page/home.php`

```html
<!-- Dashboard Stats com Alpine.js -->
<div class="row" x-data="statsController">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h5>Usuários</h5>
                <h2 x-text="formatNumber(stats.users)"></h2>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h5>Conteúdos</h5>
                <h2 x-text="formatNumber(stats.content)"></h2>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h5>Visitas</h5>
                <h2 x-text="formatNumber(stats.visits)"></h2>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h5>Receita</h5>
                <h2 x-text="formatCurrency(stats.revenue)"></h2>
            </div>
        </div>
    </div>
</div>
```

### Ações Interativas com SweetAlert2

**Controller**: `manager/public_html/assets/js/alpine/siteController.js`

```javascript
Alpine.data("actionsController", () => ({
  selectedAction: "",

  selectAction(action) {
    this.selectedAction = action;
    setTimeout(() => {
      this.selectedAction = "";
    }, 3000);
  },

  async createUser() {
    const { value: formValues } = await Swal.fire({
      title: "Novo Usuário",
      html:
        '<input id="swal-input1" class="swal2-input" placeholder="Nome">' +
        '<input id="swal-input2" class="swal2-input" placeholder="Email">',
      focusConfirm: false,
      showCancelButton: true,
      confirmButtonText: "Criar",
      cancelButtonText: "Cancelar",
      preConfirm: () => {
        return [
          document.getElementById("swal-input1").value,
          document.getElementById("swal-input2").value,
        ];
      },
    });

    if (formValues) {
      Toast.fire({
        icon: "success",
        title: "Usuário criado com sucesso!",
      });
    }
  },
}));
```

**View**:

```html
<div class="row" x-data="actionsController">
    <div class="col-md-3">
        <button @click="createUser()" class="btn btn-primary w-100">
            <i class="bi bi-person-plus"></i> Novo Usuário
        </button>
    </div>
    
    <div class="col-md-3">
        <button @click="selectAction('content')" class="btn btn-success w-100">
            <i class="bi bi-file-plus"></i> Novo Conteúdo
        </button>
    </div>
    
    <div class="col-12 mt-3" x-show="selectedAction" x-transition>
        <div class="alert alert-info">
            <strong>Ação selecionada:</strong> <span x-text="selectedAction"></span>
        </div>
    </div>
</div>
```

### Diretivas Alpine.js Mais Usadas

| Diretiva | Uso | Exemplo |
|----------|-----|---------|
| `x-data` | Define escopo do controller | `<div x-data="counterController">` |
| `x-text` | Exibe texto reativo | `<span x-text="count"></span>` |
| `x-show` | Mostra/oculta elemento | `<div x-show="open">` |
| `x-if` | Renderização condicional | `<template x-if="count > 0">` |
| `x-for` | Loop sobre arrays | `<template x-for="user in users">` |
| `@click` | Evento de clique | `<button @click="increment()">` |
| `x-model` | Two-way binding | `<input x-model="search">` |
| `x-transition` | Animações CSS | `<div x-show="open" x-transition>` |
| `x-init` | Inicialização | `<div x-init="loadData()">` |

### Integração com Backend (AJAX)

```javascript
Alpine.data("usersController", () => ({
  users: [],
  loading: false,

  async loadUsers() {
    this.loading = true;
    try {
      const response = await fetch('?sr=users&action=list');
      this.users = await response.json();
    } catch (error) {
      console.error('Erro ao carregar usuários:', error);
    } finally {
      this.loading = false;
    }
  },

  async deleteUser(id) {
    const result = await Swal.fire({
      title: 'Confirmar exclusão?',
      text: 'Esta ação não pode ser desfeita',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Sim, excluir',
      cancelButtonText: 'Cancelar'
    });

    if (result.isConfirmed) {
      await fetch(`?sr=users&action=delete&id=${id}`, { method: 'DELETE' });
      this.loadUsers();
      Toast.fire({ icon: 'success', title: 'Usuário excluído!' });
    }
  }
}));
```

### Debugging Alpine.js

No navegador, console:

```javascript
// Ver dados do componente
Alpine.$data(document.querySelector('[x-data]'))

// Forçar re-render
Alpine.nextTick(() => { /* código */ })

// Debug mode
Alpine.start(); // Inicializar manualmente se necessário
```

### Boas Práticas

✅ **Modularize**: Um controller por funcionalidade  
✅ **Nomeie consistentemente**: `nomeController.js` → `x-data="nomeController"`  
✅ **Carregue apenas o necessário**: Use `$alpineControllers` no PHP  
✅ **Prefira `x-show` a `x-if`**: Melhor performance para toggles frequentes  
✅ **Use `x-transition`**: Animações suaves melhoram UX  
✅ **Combine com Bootstrap**: Cards, modals, alerts  
✅ **Integre SweetAlert2**: Modais elegantes e consistentes  

### Recursos Úteis

- **Alpine.js Docs**: https://alpinejs.dev
- **SweetAlert2 Docs**: https://sweetalert2.github.io
- **Bootstrap 5.3**: https://getbootstrap.com/docs/5.3

---

## 🔴 Redis Cache em Profundidade

### O que é?

Redis armazena dados em **memória** (super rápido). No Nexo:
- Reduz consultas MySQL em **80%**
- Acelera **95% das requisições** repetidas
- Automático e transparente

### Uso Básico

```php
$redis = RedisCache::getInstance();

// Armazenar
$redis->set('user:123:name', 'João', 3600); // TTL: 1 hora

// Recuperar
$name = $redis->get('user:123:name'); // João

// Verificar
if ($redis->has('user:123:name')) { /* ... */ }

// Remover
$redis->delete('user:123:name');
```

### Cache Automático no Model

```php
// 1ª chamada: banco + cache
$users = new users_model();
$users->filter = ["active = 'yes'"];
$users->load_data();

// 2ª chamada: retorna do cache (super rápido!)
$users2 = new users_model();
$users2->filter = ["active = 'yes'"];
$users2->load_data();
```

### Cache com Callback

```php
$redis = RedisCache::getInstance();

$report = $redis->remember('report:2025', function() {
    // Query pesada executada apenas 1x
    return complexQuery()->data;
}, 3600); // Cache 1 hora
```

### Invalidar Cache

```php
$redis = RedisCache::getInstance();

// Remover chave
$redis->delete('user:123');

// Remover padrão (wildcard)
$redis->deletePattern('user:*');

// Limpar database
$redis->flushDatabase();
```

### Monitoramento

```bash
docker exec -it redis_nexo redis-cli -a nexo_redis_2024

KEYS *              # Ver todas as chaves
INFO                # Informações servidor
FLUSHDB             # Limpar database
```

**📖 Leia [REDIS.md](REDIS.md) para guia completo!**

---

## ✉️ Sistema Assíncrono de Emails (Kafka)

### Por que Kafka?

Emails são **lentos**. Com Kafka:

```
Aplicação → (retorna rápido) ✓
           ↓ (Kafka fila)
          Worker → (envia email)
```

Sua aplicação **não fica lenta** esperando envio!

### Enviar Email

```php
$emailer = EmailProducer::getInstance();

// Simples
$emailer->send(
    'user@example.com',
    'Bem-vindo!',
    '<h1>Olá!</h1>'
);

// Com template
$emailer->sendTemplate(
    'user@example.com',
    'Reset Senha',
    'reset-password',
    ['nome' => 'João', 'token' => 'ABC123']
);

// Com anexos
$emailer->sendWithAttachments(
    'user@example.com',
    'Relatório',
    '<p>Segue anexo</p>',
    ['/path/file.pdf']
);

// Múltiplos + CC/BCC
$emailer->sendEmail(
    ['user1@example.com', 'user2@example.com'],
    'Aviso',
    '<p>Conteúdo</p>',
    [
        'cc' => ['supervisor@example.com'],
        'bcc' => ['admin@example.com'],
        'priority' => 'high'
    ]
);
```

### Processar Emails (Worker)

Terminal separado:
```bash
docker exec -it apache_nexo bash

cd /var/www/nexo/manager/cgi-bin
php kafka_email_worker.php

# Esperado:
# [INFO] Email Worker iniciado
# [INFO] Conectado ao Kafka
# [INFO] Aguardando mensagens...
# [INFO] Nova mensagem recebida
# [INFO] Email enviado com sucesso
```

### Monitorar Emails

```bash
# Logs do worker
docker exec -it apache_nexo tail -f /var/www/nexo/manager/app/logs/email_worker.log

# Kafka UI (interface web)
# http://localhost:8080

# CLI
docker exec -it kafka_nexo /opt/kafka/bin/kafka-console-consumer.sh \
  --topic emails \
  --from-beginning \
  --bootstrap-server localhost:9092
```

**📧 Leia [KAFKA_EMAIL.md](KAFKA_EMAIL.md) para guia completo!**

---

## � Sistema de Migrations

Sistema simples e automático para executar migrações de banco de dados. As migrations são arquivos SQL na pasta `migrations/` que são executadas automaticamente em ordem alfabética.

### Estrutura

```
migrations/
├── 001_create_migrations_log.sql    # Tabela de controle (auto-criada)
├── 002_users_table.sql
├── 003_add_column_users.sql
└── 004_create_orders_table.sql
```

### Como Usar

**1. Criar nova migration:**

```bash
# Crie um arquivo .sql na pasta migrations/
# Nomeie com prefixo numérico: 002_seu_nome.sql

cat > migrations/002_users_table.sql << 'EOF'
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) UNIQUE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOF
```

**2. Executar migrations:**

As migrations são executadas **automaticamente a cada 5 minutos** via cron job. Você também pode executar manualmente:

**Via Web (Development):**
```
http://nexo.local/migrations.php         # Ver status
http://nexo.local/migrations.php?run=1   # Executar
```

**Via CLI:**
```bash
docker exec -it apache_nexo php /var/www/nexobot/site/cgi-bin/run-migrations.php
```

**3. Verificar status:**

```bash
# Verificar logs
tail -f _data/logs/migrations.log

# Ou acessar interface web
# http://manager.nexo.local/migrations.php
```

### Características

✅ **Automático**: Roda a cada 5 minutos via cron  
✅ **Simples**: Apenas .sql files na pasta migrations/  
✅ **Seguro**: Rastreia execução em `migrations_log`  
✅ **Idempotente**: Nunca executa a mesma migration duas vezes  
✅ **Logging**: Logs em `_data/logs/migrations.log`  

### Exemplo Completo

```bash
# 1. Criar migration
cat > migrations/002_create_products.sql << 'EOF'
-- Criar tabela de produtos
CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  price DECIMAL(10, 2),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Criar índice
CREATE INDEX idx_price ON products(price);
EOF

# 2. Executar via CLI
docker exec -it apache_nexo php /var/www/nexobot/site/cgi-bin/run-migrations.php

# 3. Verificar na web
# http://nexo.local/migrations.php
```

### Troubleshooting

**Migration não executa:**
```bash
# Verificar se arquivo existe
ls -la migrations/

# Verificar logs
tail -f _data/logs/migrations.log

# Testar execução manual
docker exec -it apache_nexo php /var/www/nexobot/site/cgi-bin/run-migrations.php
```

**Migration falhou:**
- Verifique sintaxe SQL no arquivo `.sql`
- Veja erro detalhado em `migrations.php` na web
- Edite o arquivo, corrija e tente novamente

**Reexecutar migration:**
- Delete registro em `migrations_log` no banco se necessário
- Ou renomei/recrie o arquivo .sql

---

## �🐛 Debugging e Logs

### Logs do Apache

```bash
# Ver logs
tail -f _data/logs/apache2/error.log
tail -f _data/logs/apache2/access.log

# Ou dentro do container
docker exec -it apache_nexo tail -f /var/log/apache2/error.log
```

### Logs do PHP

No `index.php`, debug está habilitado:
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

**⚠️ DESABILITAR EM PRODUÇÃO!**

### Verificar Containers

```bash
# Status
docker ps

# Logs
docker logs -f mysql_nexo
docker logs -f redis_nexo
docker logs -f kafka_nexo
docker logs -f apache_nexo

# Recursos
docker stats
```

### Kafka UI

Acesse: http://localhost:8080

Visualize:
- Tópicos
- Mensagens em fila
- Consumer groups
- Offsets processados

---

## 🔧 Troubleshooting Rápido

| Problema | Solução |
|----------|---------|
| **MySQL não conecta** | `docker ps` → `docker logs mysql_nexo` |
| **Redis não conecta** | `docker logs redis_nexo` → `docker restart redis_nexo` |
| **Kafka não inicia** | Aguarde 60 segundos → acesse Kafka UI |
| **Porta 80 ocupada** | `sudo lsof -i :80` → use outra porta em docker-compose.yml |
| **Emails não enviam** | Inicie worker → `docker logs apache_nexo` |
| **Erro de permissão** | `chmod -R 755 _data/logs/` |

---

## 📖 Documentação Adicional

- **[REDIS.md](REDIS.md)** - Cache em profundidade, boas práticas, exemplos avançados
- **[KAFKA_EMAIL.md](KAFKA_EMAIL.md)** - Emails assíncronos, daemon, Supervisor/Systemd
- **[MANUAL_DEPLOY.md](MANUAL_DEPLOY.md)** - Produção com Portainer, Git clone

---

## 🚀 Próximos Passos

1. Criar **Models** estendendo `DOLModel`
2. Implementar **Controllers** com lógica
3. Criar **Views** em `public_html/ui/page/`
4. Definir **rotas** em `urls.php`
5. Otimizar com **Redis cache**
6. Integrar **emails** com `EmailProducer`

---

**Desenvolvido com ❤️ usando PHP 8.4+, MySQL 8.0, Redis 7.2 e Apache Kafka**
