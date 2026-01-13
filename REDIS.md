# 🚀 Guia de Uso do Redis no Projeto Nexo

## 📋 Índice
- [Introdução](#introdução)
- [Configuração](#configuração)
- [Exemplos de Uso](#exemplos-de-uso)
- [Cache Automático no DOLModel](#cache-automático-no-dolmodel)
- [Melhores Práticas](#melhores-práticas)
- [Troubleshooting](#troubleshooting)

## 🎯 Introdução

O Redis foi integrado ao projeto Nexo para fornecer cache de alto desempenho, reduzindo a carga no banco de dados MySQL e melhorando significativamente os tempos de resposta da aplicação.

### Características

- ✅ **Cache Automático**: Integrado no DOLModel para cache transparente de consultas
- ✅ **Singleton Pattern**: Instância única compartilhada em toda a aplicação
- ✅ **TTL Configurável**: Controle de tempo de vida do cache
- ✅ **Namespaces**: Separação de cache entre Manager e Site
- ✅ **Invalidação Inteligente**: Limpeza automática após INSERT/UPDATE/DELETE
- ✅ **Fallback Gracioso**: Funciona sem Redis se não estiver disponível

## ⚙️ Configuração

### 1. Configurar o kernel.php

Copie o arquivo de exemplo e ajuste as configurações:

```bash
# Manager
cp manager/app/inc/kernel.php.example manager/app/inc/kernel.php

# Site
cp site/app/inc/kernel.php.example site/app/inc/kernel.php
```

Edite as configurações do Redis no `kernel.php`:

```php
// Configurações do Redis
define("REDIS_HOST", "172.29.0.4");           // IP do container Redis
define("REDIS_PORT", 6379);                    // Porta padrão
define("REDIS_PASSWORD", "nexo_redis_2024");  // Senha configurada
define("REDIS_PREFIX", "nexo:manager:");       // Namespace único
define("REDIS_DATABASE", 0);                   // Database 0 = Manager, 1 = Site
define("REDIS_ENABLED", true);                 // Habilitar cache
define("REDIS_DEFAULT_TTL", 3600);            // TTL padrão: 1 hora
```

### 2. Rebuild dos Containers

Após configurar, rebuilde os containers para instalar a extensão Redis:

```bash
cd docker
docker-compose down
docker-compose up -d --build
```

### 3. Instalar Dependências

Execute composer em ambos os módulos:

```bash
docker exec -it apache_nexo bash

# Manager
cd /var/www/nexo/manager/app/inc/lib
composer dump-autoload

# Site
cd /var/www/nexo/site/app/inc/lib
composer dump-autoload

exit
```

## 💡 Exemplos de Uso

### Uso Básico da Classe RedisCache

```php
// Obter instância do Redis
$redis = RedisCache::getInstance();

// Verificar se está conectado
if ($redis->isConnected()) {
    echo "Redis conectado!";
}

// Armazenar dados (TTL: 1 hora)
$redis->set('user:1', [
    'name' => 'João Silva',
    'email' => 'joao@exemplo.com'
], 3600);

// Recuperar dados
$user = $redis->get('user:1');
if ($user) {
    echo "Nome: " . $user['name'];
}

// Verificar se existe
if ($redis->has('user:1')) {
    echo "Usuário existe no cache";
}

// Remover do cache
$redis->delete('user:1');

// Remover por padrão
$redis->deletePattern('user:*'); // Remove todos os usuários
```

### Cache com Callback (remember)

```php
$redis = RedisCache::getInstance();

// Busca no cache ou executa função
$products = $redis->remember('products:active', function() {
    $model = new products_model();
    $model->filter = ["active = 'yes'"];
    $model->load_data();
    return $model->data;
}, 1800); // TTL: 30 minutos

// Primeira chamada: consulta banco e armazena no cache
// Chamadas seguintes: retorna direto do cache
```

### Armazenar Múltiplos Valores

```php
$redis = RedisCache::getInstance();

// Salvar vários itens de uma vez
$data = [
    'config:app_name' => 'Nexo',
    'config:version' => '1.0.0',
    'config:debug' => false
];

$redis->setMultiple($data, 86400); // TTL: 1 dia

// Recuperar múltiplos valores
$keys = ['config:app_name', 'config:version', 'config:debug'];
$configs = $redis->getMultiple($keys);
```

### Contadores

```php
$redis = RedisCache::getInstance();

// Incrementar visualizações
$views = $redis->increment('page:home:views'); // +1
$views = $redis->increment('page:home:views', 5); // +5

// Decrementar estoque
$stock = $redis->decrement('product:123:stock'); // -1

// Define expiração para contador
$redis->expire('page:home:views', 3600); // Expira em 1 hora
```

### Controle de TTL

```php
$redis = RedisCache::getInstance();

// Armazenar sem expiração
$redis->set('permanent:data', $value, 0);

// Verificar tempo restante
$ttl = $redis->ttl('user:session:123');
if ($ttl > 0) {
    echo "Expira em {$ttl} segundos";
} elseif ($ttl === -1) {
    echo "Não tem expiração";
} elseif ($ttl === -2) {
    echo "Chave não existe";
}

// Redefinir expiração
$redis->expire('user:session:123', 7200); // Mais 2 horas
```

## 🔄 Cache Automático no DOLModel

O DOLModel foi estendido para incluir cache automático de consultas.

### Funcionamento Padrão

```php
// Cache é transparente - funciona automaticamente
$user = new users_model();
$user->filter = ["active = 'yes'"];
$user->order = ["name ASC"];
$user->load_data(); // Primeira vez: consulta banco + armazena cache
                    // Próximas vezes: retorna do cache

// Salvamentos invalidam o cache automaticamente
$user->field = [
    'name' => 'novo nome'
];
$user->filter = ["idx = 123"];
$user->save(); // Salva no banco + limpa cache da tabela
```

### Controlar Cache no Model

```php
$product = new products_model();

// Desabilitar cache temporariamente
$product->setCacheEnabled(false);
$product->load_data(); // Força busca no banco

// Alterar TTL para esta consulta
$product->setCacheTTL(300); // 5 minutos
$product->load_data();

// Reabilitar cache
$product->setCacheEnabled(true);
```

### Invalidação Manual

```php
// Se precisar limpar cache manualmente
$user = new users_model();
$user->clearTableCache(); // Limpa todo cache da tabela users

// Ou usar RedisCache diretamente
$redis = RedisCache::getInstance();
$redis->deletePattern('query:*users*'); // Limpa queries da tabela users
```

## 🎯 Melhores Práticas

### 1. TTL Adequado

```php
// Dados raramente alterados: TTL longo
$redis->set('config:settings', $settings, 86400); // 1 dia

// Dados frequentemente atualizados: TTL curto
$redis->set('stats:realtime', $stats, 60); // 1 minuto

// Dados da sessão do usuário
$redis->set('user:session:' . $userId, $sessionData, 7200); // 2 horas
```

### 2. Nomenclatura de Chaves

Use padrões consistentes para facilitar limpeza e organização:

```php
// ✅ BOM: Hierárquico e descritivo
'user:123:profile'
'product:456:details'
'cart:session:789'
'query:users:active'

// ❌ RUIM: Genérico e difícil de gerenciar
'u123'
'data'
'temp'
```

### 3. Usar Namespaces

Já configurado automaticamente via `REDIS_PREFIX`:

```php
// Manager usa: nexo:manager:*
// Site usa: nexo:site:*
// Isso evita conflitos entre módulos
```

### 4. Tratamento de Erros

```php
$redis = RedisCache::getInstance();

if (!$redis->isConnected()) {
    // Fallback: usar banco de dados diretamente
    error_log('Redis não disponível, usando MySQL');
    // continuar sem cache
}
```

### 5. Cache de Consultas Complexas

```php
// Para consultas pesadas, use cache com callback
$redis = RedisCache::getInstance();

$report = $redis->remember('report:monthly:' . date('Y-m'), function() {
    // Query complexa que demora
    $model = new sales_model();
    $model->field = [
        'SUM(total) as total_sales',
        'COUNT(*) as total_orders',
        'AVG(total) as avg_order'
    ];
    $model->filter = ["DATE_FORMAT(created_at, '%Y-%m') = '" . date('Y-m') . "'"];
    $model->load_data();
    return $model->data[0];
}, 3600); // Cache por 1 hora
```

## 🔧 Troubleshooting

### Redis não conecta

```bash
# Verificar se container está rodando
docker ps | grep redis_nexo

# Ver logs do Redis
docker logs redis_nexo

# Testar conexão manual
docker exec -it redis_nexo redis-cli -a nexo_redis_2024 ping
# Deve retornar: PONG
```

### Verificar chaves armazenadas

```bash
# Acessar Redis CLI
docker exec -it redis_nexo redis-cli -a nexo_redis_2024

# Listar todas as chaves
KEYS *

# Listar chaves por padrão
KEYS nexo:manager:*

# Ver valor de uma chave
GET nexo:manager:user:123

# Ver TTL de uma chave
TTL nexo:manager:query:abc123

# Limpar tudo (cuidado!)
FLUSHDB
```

### Cache não está funcionando

```php
// Debug: Verificar configuração
$redis = RedisCache::getInstance();

if (!$redis->isConnected()) {
    echo "Redis não conectado!\n";
    
    // Verificar configurações
    echo "REDIS_ENABLED: " . (defined('REDIS_ENABLED') ? REDIS_ENABLED : 'não definido') . "\n";
    echo "REDIS_HOST: " . (defined('REDIS_HOST') ? REDIS_HOST : 'não definido') . "\n";
    echo "REDIS_PORT: " . (defined('REDIS_PORT') ? REDIS_PORT : 'não definido') . "\n";
}

// Testar escrita e leitura
$testKey = 'test:' . time();
$testValue = ['test' => 'data'];

if ($redis->set($testKey, $testValue, 60)) {
    echo "Escrita OK\n";
    
    $retrieved = $redis->get($testKey);
    if ($retrieved === $testValue) {
        echo "Leitura OK\n";
    } else {
        echo "Erro na leitura\n";
    }
    
    $redis->delete($testKey);
} else {
    echo "Erro na escrita\n";
}
```

### Limpar cache específico

```php
// Por aplicação
$redis = RedisCache::getInstance();
$redis->flush(); // Limpa database atual (0 ou 1)

// Por padrão
$redis->deletePattern('user:*');      // Todos os usuários
$redis->deletePattern('query:users*'); // Todas queries de users
$redis->deletePattern('session:*');    // Todas sessões

// Por chave específica
$redis->delete('user:123');
$redis->delete(['user:123', 'user:456', 'user:789']); // Múltiplas
```

### Monitorar Uso do Redis

```php
// Obter informações do servidor
$redis = RedisCache::getInstance();
$info = $redis->info();

echo "Versão: " . $info['redis_version'] . "\n";
echo "Memória Usada: " . $info['used_memory_human'] . "\n";
echo "Total de Chaves: " . $info['db0'] . "\n";
echo "Hits: " . $info['keyspace_hits'] . "\n";
echo "Misses: " . $info['keyspace_misses'] . "\n";
```

## 📊 Exemplos Práticos

### Sistema de Login com Cache

```php
// Login Controller
$redis = RedisCache::getInstance();
$sessionKey = 'session:' . session_id();

// Armazenar dados da sessão
$redis->set($sessionKey, [
    'user_id' => $userId,
    'name' => $userName,
    'permissions' => $permissions,
    'last_activity' => time()
], SESSION_LIFETIME);

// Verificar sessão
$session = $redis->get($sessionKey);
if ($session && (time() - $session['last_activity']) < SESSION_LIFETIME) {
    // Sessão válida, renovar TTL
    $redis->expire($sessionKey, SESSION_LIFETIME);
} else {
    // Sessão expirada, fazer logout
    $redis->delete($sessionKey);
    header('Location: /login');
}
```

### Cache de Listagens

```php
// Lista de produtos com filtros
$category = $_GET['category'] ?? 'all';
$page = $_GET['page'] ?? 1;

$redis = RedisCache::getInstance();
$cacheKey = "products:list:category:{$category}:page:{$page}";

$products = $redis->remember($cacheKey, function() use ($category, $page) {
    $model = new products_model();
    $model->filter = ["active = 'yes'"];
    
    if ($category !== 'all') {
        $model->filter[] = "category_id = '" . $category . "'";
    }
    
    $model->paginate = [($page - 1) * 20, 20];
    $model->order = ['created_at DESC'];
    $model->load_data();
    
    return $model->data;
}, 600); // 10 minutos
```

### Rate Limiting

```php
// Limitar tentativas de login
function checkLoginAttempts($username) {
    $redis = RedisCache::getInstance();
    $key = 'login:attempts:' . $username;
    
    $attempts = $redis->get($key, 0);
    
    if ($attempts >= 5) {
        $ttl = $redis->ttl($key);
        throw new Exception("Muitas tentativas. Tente novamente em {$ttl} segundos.");
    }
    
    return $attempts;
}

function recordLoginAttempt($username, $success = false) {
    $redis = RedisCache::getInstance();
    $key = 'login:attempts:' . $username;
    
    if ($success) {
        // Login bem-sucedido, limpar tentativas
        $redis->delete($key);
    } else {
        // Incrementar e definir expiração de 15 minutos
        $attempts = $redis->increment($key);
        if ($attempts === 1) {
            $redis->expire($key, 900);
        }
    }
}
```

## 🎓 Conclusão

O Redis está completamente integrado ao projeto Nexo e pronto para uso. O cache é automático no DOLModel, mas você tem controle total quando precisar de funcionalidades avançadas através da classe `RedisCache`.

Para mais informações:
- [Documentação oficial do Redis](https://redis.io/documentation)
- [PHP Redis Extension](https://github.com/phpredis/phpredis)
