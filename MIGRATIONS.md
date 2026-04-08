# 🚀 Sistema de Migrations

Sistema simples e prático de versionamento de banco de dados via SQL migrations.

## 📋 Visão Geral

O sistema de migrations permite:
- ✅ Versionamento de estrutura de banco de dados
- ✅ Rastreamento de migrations executadas
- ✅ Execução manual via CLI ou web interface
- ✅ Execução automática via cron job
- ✅ Suporte para Dev (Docker local) e Prod (VPS com Portainer)

## 📁 Estrutura

```
driftex/
├── migrations/                          # 📂 Pasta de migrations SQL
│   ├── 001_create_migrations_log.sql
│   ├── 002_create_table_orders_trades.sql
│   ├── 003_create_table_orders.sql
│   ├── 004_create_table_tradelogs.sql
│   ├── 005_create_table_trades.sql
│   └── 006_create_table_walletbalances.sql
│
└── site/
    ├── app/inc/lib/
    │   ├── MigrationRunner.php          # 🔧 Classe principal
    │   └── local_pdo.php                # 📦 Conexão com banco
    │
    ├── cgi-bin/
    │   └── run-migrations.php           # 🖥️  CLI Script
    │
    └── public_html/
        └── migrations.php               # 🌐 Web Interface
```

## 🎯 Como Usar

### 1️⃣ CLI - Executar migrations manualmente

```bash
# Dev (local Docker)
docker exec -it apache_nexo php /var/www/driftex/site/cgi-bin/run-migrations.php

# Prod (VPS com Portainer)
ssh usuario@seu-servidor.com
cd /var/www/driftex
php site/cgi-bin/run-migrations.php
```

**Output esperado:**
```
========================================
🚀 Executando Migrations
========================================
📁 Diretório: /home/cehdoliveira/Projetos/driftex/migrations
   Existe? ✅ SIM
   Arquivos .sql: 6

✅ Executadas: 6
⏭️  Ignoradas: 0
❌ Falhas: 0
========================================
```

### 2️⃣ Web Interface - Visualizar e executar via navegador

```
http://nexo.local/migrations.php
```

**Funcionalidades:**
- 📊 Dashboard com resumo de migrations
- 🔄 Ver migrations pendentes, executadas e falhas
- ▶️ Botão para executar manualmente
- 📋 Histórico completo com timestamps

### 3️⃣ Automático - Cron job (cada 5 minutos)

O sistema está configurado para executar automaticamente a cada 5 minutos:

**Dev:**
```
*/5 * * * * php /var/www/driftex/site/cgi-bin/run-migrations.php >> /var/log/driftex/migrations.log 2>&1
```
Localização: `/docker/core/crontab.txt`

**Prod:**
```
*/5 * * * * php /var/www/driftex/site/cgi-bin/run-migrations.php >> /var/log/driftex/migrations.log 2>&1
```
Localização: `/docker/prod/crontab.txt`

## 📝 Criando Novas Migrations

### Passo 1: Criar arquivo SQL

Nome: `NNN_descricao_da_migracao.sql` (NNN = número sequencial)

Exemplo: `007_add_column_user_status.sql`

```sql
-- Descrição da mudança
ALTER TABLE users ADD COLUMN status ENUM('active', 'inactive') DEFAULT 'active';

-- Opcional: inserir dados padrão
UPDATE users SET status = 'active' WHERE status IS NULL;
```

### Passo 2: Salvar em `/migrations/`

O arquivo será descoberto automaticamente.

### Passo 3: Executar

Via CLI ou Web interface, ou esperar o cron executar.

## 🔍 Rastreamento de Migrations

Todas as migrations executadas são registradas na tabela `migrations_log`:

```sql
SELECT * FROM migrations_log ORDER BY executed_at DESC;
```

Colunas:
- `id` - ID único
- `filename` - Nome do arquivo SQL
- `executed_at` - Timestamp de execução
- `status` - 'pending', 'executed', 'failed'
- `error_message` - Mensagem de erro (se houver)

## 🛠️ Tecnologias

- **Linguagem:** PHP 8.4+
- **Banco:** MySQL 8.0+
- **Framework Web:** Apache + .htaccess
- **CLI:** PHP CLI
- **Scheduler:** Cron
- **Containerização:** Docker (Dev) / VPS (Prod)

## ⚙️ Configuração

### Banco de dados

As credenciais vêm de `/site/app/inc/kernel.php`:

```php
define('DB_HOST', 'mysql');        // Host do MySQL
define('DB_NAME', 'mysql_driftex'); // Database
define('DB_USER', 'root');          // Usuário
define('DB_PASS', 'senha');         // Senha
```

### Path da pasta de migrations

Detectado automaticamente em:
1. Relativo: `__DIR__/../../../../migrations`
2. Docker: `/var/www/driftex/migrations`
3. Absoluto: `/home/cehdoliveira/Projetos/driftex/migrations`

## 🐛 Troubleshooting

### "Nenhuma migration encontrada"

**Possíveis causas:**
- Pasta `/migrations/` não existe
- Nenhum arquivo `.sql` na pasta
- Permissões insuficientes

**Solução:**
```bash
# Verificar pasta
ls -la /home/cehdoliveira/Projetos/driftex/migrations/

# Verificar permissões
chmod 755 /home/cehdoliveira/Projetos/driftex/migrations/
chmod 644 /home/cehdoliveira/Projetos/driftex/migrations/*.sql
```

### "Operation timed out"

O banco de dados não está acessível. O sistema detectará automaticamente e mostrará:
```
❌ ERRO de Conexão
SQLSTATE[HY000] [2002] Operation timed out

ℹ️  Tentando modo diagnóstico (sem banco)...

✅ Estrutura OK:
   📁 Diretório: /home/cehdoliveira/Projetos/driftex/migrations
   📄 Migrations encontradas: 6
```

Aguarde a conexão ser reestabelecida. As migrations serão executadas automaticamente.

### Erro ao executar migration

Verifique o SQL no arquivo para:
- Sintaxe correta
- Permissões (CREATE, ALTER, etc)
- Dependências de tabelas/colunas

Ver log detalhado:
```bash
# CLI
php site/cgi-bin/run-migrations.php

# Web
http://nexo.local/migrations.php

# Banco
SELECT * FROM migrations_log WHERE status = 'failed';
```

## 📊 Monitoramento

### Verificar status atual

```bash
# Via CLI com diagnóstico
php site/cgi-bin/run-migrations.php

# Via web
http://nexo.local/migrations.php

# Diretamente no banco
SELECT filename, status, executed_at FROM migrations_log ORDER BY executed_at DESC;
```

### Ver log do cron

```bash
# Dev (Docker)
docker exec apache_nexo tail -f /var/log/driftex/migrations.log

# Prod
ssh usuario@servidor
tail -f /var/log/driftex/migrations.log
```

## 🔐 Segurança

### Validações

- ✅ Apenas `.sql` são aceitos
- ✅ Migrations já executadas não são re-executadas
- ✅ Erros não interrompem outras migrations
- ✅ Registra tentativas e resultados

### Best Practices

1. **Sempre test migrations em dev primeiro**
2. **Use nomes descritivos**
3. **Uma mudança por arquivo**
4. **Adicionar comments no SQL**
5. **Testar rollback (se aplicável)**

## 📚 Referências

- [Diretório de migrations](./migrations/)
- [Classe MigrationRunner](./site/app/inc/lib/MigrationRunner.php)
- [Script CLI](./site/cgi-bin/run-migrations.php)
- [Interface Web](./site/public_html/migrations.php)
- [Docker compose](./docker/docker-compose.yml)

---

**Última atualização:** 2026-01-14
**Status:** ✅ Funcional e testado
