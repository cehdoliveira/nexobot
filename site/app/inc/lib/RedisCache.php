<?php

/**
 * RedisCache - Wrapper para operações de cache com Redis
 * PHP 8.4+ | Redis 7.2+
 * 
 * Fornece uma interface simplificada para operações de cache
 * com suporte a serialização automática, TTL e namespaces
 * 
 * @package Nexo
 * @version 1.0.0
 */

class RedisCache
{
    private static $instance = null;
    private $redis;
    private $prefix;
    private $connected = false;
    private $enabled = true;

    /**
     * Construtor privado para implementar Singleton
     * 
     * @param string $host Host do Redis
     * @param int $port Porta do Redis
     * @param string $password Senha do Redis
     * @param string $prefix Prefixo para chaves (namespace)
     * @param int $database Número do database (0-15)
     */
    private function __construct(
        string $host = '172.29.0.4',
        int $port = 6379,
        string $password = '',
        string $prefix = 'nexo:',
        int $database = 0
    ) {
        if (!extension_loaded('redis')) {
            error_log('RedisCache: Extensão Redis não está carregada. Cache desabilitado.');
            $this->enabled = false;
            return;
        }

        try {
            $this->redis = new Redis();
            $this->prefix = $prefix;
            
            // Conectar ao Redis
            $connected = $this->redis->connect($host, $port, 2.5);
            
            if (!$connected) {
                throw new Exception('Falha ao conectar ao Redis');
            }

            // Autenticar se senha foi fornecida
            if (!empty($password)) {
                $this->redis->auth($password);
            }

            // Selecionar database
            $this->redis->select($database);

            // Configurar opções
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
            $this->redis->setOption(Redis::OPT_PREFIX, $this->prefix);
            
            $this->connected = true;
            
        } catch (Exception $e) {
            error_log('RedisCache Error: ' . $e->getMessage());
            $this->enabled = false;
            $this->connected = false;
        }
    }

    /**
     * Retorna instância única do RedisCache (Singleton)
     * 
     * @param array $config Configurações opcionais
     * @return RedisCache|null
     */
    public static function getInstance(array $config = []): ?RedisCache
    {
        if (self::$instance === null) {
            $host = $config['host'] ?? (defined('REDIS_HOST') ? constant('REDIS_HOST') : '172.29.0.4');
            $port = $config['port'] ?? (defined('REDIS_PORT') ? constant('REDIS_PORT') : 6379);
            $password = $config['password'] ?? (defined('REDIS_PASSWORD') ? constant('REDIS_PASSWORD') : '');
            $prefix = $config['prefix'] ?? (defined('REDIS_PREFIX') ? constant('REDIS_PREFIX') : 'nexo:');
            $database = $config['database'] ?? (defined('REDIS_DATABASE') ? constant('REDIS_DATABASE') : 0);
            
            self::$instance = new self($host, $port, $password, $prefix, $database);
        }
        
        return self::$instance;
    }

    /**
     * Verifica se o Redis está conectado e habilitado
     * 
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->enabled;
    }

    /**
     * Armazena um valor no cache
     * 
     * @param string $key Chave do cache
     * @param mixed $value Valor a ser armazenado (será serializado automaticamente)
     * @param int $ttl Tempo de vida em segundos (0 = sem expiração)
     * @return bool
     */
    public function set(string $key, $value, int $ttl = 3600): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            if ($ttl > 0) {
                return $this->redis->setex($key, $ttl, $value);
            } else {
                return $this->redis->set($key, $value);
            }
        } catch (Exception $e) {
            error_log('RedisCache::set Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Recupera um valor do cache
     * 
     * @param string $key Chave do cache
     * @param mixed $default Valor padrão se a chave não existir
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        if (!$this->isConnected()) {
            return $default;
        }

        try {
            $value = $this->redis->get($key);
            return ($value === false) ? $default : $value;
        } catch (Exception $e) {
            error_log('RedisCache::get Error: ' . $e->getMessage());
            return $default;
        }
    }

    /**
     * Verifica se uma chave existe no cache
     * 
     * @param string $key Chave do cache
     * @return bool
     */
    public function has(string $key): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return $this->redis->exists($key) > 0;
        } catch (Exception $e) {
            error_log('RedisCache::has Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove uma ou mais chaves do cache
     * 
     * @param string|array $keys Chave(s) a serem removidas
     * @return int Número de chaves removidas
     */
    public function delete($keys): int
    {
        if (!$this->isConnected()) {
            return 0;
        }

        try {
            return $this->redis->del($keys);
        } catch (Exception $e) {
            error_log('RedisCache::delete Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Remove todas as chaves que correspondem a um padrão
     * 
     * @param string $pattern Padrão (ex: 'user:*', 'session:*')
     * @return int Número de chaves removidas
     */
    public function deletePattern(string $pattern): int
    {
        if (!$this->isConnected()) {
            return 0;
        }

        try {
            $keys = $this->redis->keys($pattern);
            if (empty($keys)) {
                return 0;
            }
            return $this->redis->del($keys);
        } catch (Exception $e) {
            error_log('RedisCache::deletePattern Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Limpa todo o cache do database atual
     * 
     * @return bool
     */
    public function flush(): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return $this->redis->flushDB();
        } catch (Exception $e) {
            error_log('RedisCache::flush Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Incrementa um valor numérico
     * 
     * @param string $key Chave do cache
     * @param int $value Valor a incrementar (padrão: 1)
     * @return int|false Novo valor ou false em caso de erro
     */
    public function increment(string $key, int $value = 1)
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return $this->redis->incrBy($key, $value);
        } catch (Exception $e) {
            error_log('RedisCache::increment Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Decrementa um valor numérico
     * 
     * @param string $key Chave do cache
     * @param int $value Valor a decrementar (padrão: 1)
     * @return int|false Novo valor ou false em caso de erro
     */
    public function decrement(string $key, int $value = 1)
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return $this->redis->decrBy($key, $value);
        } catch (Exception $e) {
            error_log('RedisCache::decrement Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Define tempo de expiração para uma chave existente
     * 
     * @param string $key Chave do cache
     * @param int $ttl Tempo de vida em segundos
     * @return bool
     */
    public function expire(string $key, int $ttl): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return $this->redis->expire($key, $ttl);
        } catch (Exception $e) {
            error_log('RedisCache::expire Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retorna o tempo de vida restante de uma chave
     * 
     * @param string $key Chave do cache
     * @return int Segundos restantes (-1 = sem expiração, -2 = chave não existe)
     */
    public function ttl(string $key): int
    {
        if (!$this->isConnected()) {
            return -2;
        }

        try {
            return $this->redis->ttl($key);
        } catch (Exception $e) {
            error_log('RedisCache::ttl Error: ' . $e->getMessage());
            return -2;
        }
    }

    /**
     * Armazena múltiplos valores de uma vez
     * 
     * @param array $data Array associativo [chave => valor]
     * @param int $ttl Tempo de vida em segundos (aplicado a todas as chaves)
     * @return bool
     */
    public function setMultiple(array $data, int $ttl = 3600): bool
    {
        if (!$this->isConnected() || empty($data)) {
            return false;
        }

        try {
            $this->redis->multi();
            
            foreach ($data as $key => $value) {
                if ($ttl > 0) {
                    $this->redis->setex($key, $ttl, $value);
                } else {
                    $this->redis->set($key, $value);
                }
            }
            
            $results = $this->redis->exec();
            return !in_array(false, $results, true);
        } catch (Exception $e) {
            error_log('RedisCache::setMultiple Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Recupera múltiplos valores de uma vez
     * 
     * @param array $keys Array de chaves
     * @return array Array associativo [chave => valor]
     */
    public function getMultiple(array $keys): array
    {
        if (!$this->isConnected() || empty($keys)) {
            return [];
        }

        try {
            $values = $this->redis->mGet($keys);
            return array_combine($keys, $values);
        } catch (Exception $e) {
            error_log('RedisCache::getMultiple Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Cache com callback - busca no cache ou executa função
     * 
     * @param string $key Chave do cache
     * @param callable $callback Função a executar se não estiver em cache
     * @param int $ttl Tempo de vida em segundos
     * @return mixed
     */
    public function remember(string $key, callable $callback, int $ttl = 3600)
    {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        
        return $value;
    }

    /**
     * Retorna informações sobre o servidor Redis
     * 
     * @return array|false
     */
    public function info()
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            return $this->redis->info();
        } catch (Exception $e) {
            error_log('RedisCache::info Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fecha a conexão com o Redis
     */
    public function close(): void
    {
        if ($this->connected && $this->redis) {
            try {
                $this->redis->close();
                $this->connected = false;
            } catch (Exception $e) {
                error_log('RedisCache::close Error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Previne clonagem do objeto Singleton
     */
    private function __clone() {}

    /**
     * Previne deserialização do objeto Singleton
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}
