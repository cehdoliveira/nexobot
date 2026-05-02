<?php

use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;
use Binance\Client\Spot\Model\NewOrderRequest;
use Binance\Client\Spot\Model\Side;
use Binance\Client\Spot\Model\OrderType;

/**
 * Grid Trading Strategy Controller
 * 
 * Implementa a estratégia de Grid Trading para BTC/USDC
 * Controller independente que não depende do setup_controller
 * 
 * Execução: CRON a cada 1 minuto(s)
 */
class setup_controller
{
    // Configuração de símbolos e grid
    private const SYMBOLS = ['BTCUSDC'];
    private const GRID_LEVELS = 6;              // 6 níveis por grid
    private const GRID_RANGE_PERCENT = 0.05;     // ±5% do preço atual
    private const GRID_SPACING_PERCENT = 0.01;   // 1% entre níveis
    private const REBALANCE_THRESHOLD = 0.01;    // Rebalancear se sair 1% do range
    private const CAPITAL_ALLOCATION = 0.95;     // 95% do capital USDC disponível
    private const MIN_TRADE_USDC = 11;           // Mínimo por trade
    private const MAX_ALGO_ORDERS = 5;           // Limite Binance de ordens algorítmicas
    private const INITIAL_BTC_ALLOCATION = 0.50; // 50% do capital inicial convertido em BTC para ordens de venda superiores
    private const SAFETY_MARGIN = 1.15;          // 15% de margem sobre o mínimo da Binance
    private const CAPITAL_USAGE_PERCENT = 0.85;   // Usa 85% do USDC disponível como fallback

    // Proteção: Stop-Loss Global
    private const MAX_DRAWDOWN_PERCENT = 0.20;    // 20% perda máxima do capital inicial
    private const ENABLE_STOP_LOSS = true;

    // Proteção: Trailing Stop (proteção de lucros)
    private const TRAILING_STOP_PERCENT = 0.15;           // 15% de queda do pico
    private const MIN_PROFIT_TO_ACTIVATE_TRAILING = 0.10;  // Ativa após 10% de lucro
    private const ENABLE_TRAILING_STOP = true;
    private const AUTO_CONTRIBUTION_THRESHOLD_PERCENT = 0.25; // Rebase automático se capital saltar 25% ou mais em um ciclo
    private const AUTO_CONTRIBUTION_USDC_CONFIRMATION_RATIO = 0.95; // Delta de USDC deve cobrir ao menos 95% do salto para confirmar aporte

    // Proteção: Fee Threshold (validação de lucro mínimo)
    private const FEE_PERCENT = 0.001;                     // 0.1% por operação
    private const MIN_PROFIT_USDC_HIGH = 0.25;             // Mínimo para capital_per_level >= 50
    private const MIN_PROFIT_USDC_LOW = 0.0025;            // Mínimo para capital_per_level < 50

    // Proteção: Race Condition
    private const LOCK_TIMEOUT_MINUTES = 2;                // Lock travado após 2 minutos
    private const CRON_STALE_ALERT_MINUTES = 5;            // Considera CRON parada/lenta após 5 minutos sem sucesso

    // Sliding Grid
    private const GRID_SLIDE_MAX_ITERATIONS = 6;           // Máximo de slides por ciclo CRON

    // Logs aprimorados
    private const DEBUG_MODE = false;                       // Desativar para reduzir tamanho de logs. INFO/SYSTEM não serão salvos

    private const LOG_RETENTION_DAYS = 30;

    // Cache TTL
    private const CACHE_TTL_ACCOUNT_INFO = 5;    // 5 segundos para account info
    private const CACHE_TTL_EXCHANGE_INFO = 60;  // 60 segundos para exchange info
    private const BINANCE_RECV_WINDOW = 10000; // Janela de recepcao para chamadas autenticadas da Binance

    // Logs
    private const ERROR_LOG = 'error.log';
    private const API_LOG = 'binance_api.log';
    private const TRADE_LOG = 'trading.log';

    private array $activeGrids = [];             // Cache de grids ativos em memória
    private array $symbolPrices = [];            // Cache de preços atuais
    private float $totalCapital = 0.0;           // Capital USDC total disponível
    private $client = null;                      // Cliente Binance API
    private array $logBuffer = [];
    private int $logBufferSize = 100;
    private string $logPath;
    private ?array $accountInfoCache = null;     // Cache de informações da conta
    private int $accountInfoCacheTime = 0;       // Timestamp do cache de account info
    private array $exchangeInfoCache = [];       // Cache de informações de exchange por símbolo
    private string $executionId = '';               // ID único da execução CRON

    public function __construct()
    {
        // Inicializar variáveis de cache para evitar deprecated warnings
        $this->accountInfoCache = null;
        $this->accountInfoCacheTime = 0;
        $this->exchangeInfoCache = [];

        // Gerar ID único para esta execução (rastreabilidade)
        $this->executionId = substr(md5(uniqid(mt_rand(), true)), 0, 8);

        $this->initializeBinanceClient();
        $this->initializeLogger();
        register_shutdown_function([$this, 'flushLogs']);
    }

    public function __destruct()
    {
        $this->flushLogs();
    }

    /**
     * Inicializa cliente Binance API
     */
    private function initializeBinanceClient(): void
    {
        $binanceConfig = BinanceConfig::getActiveCredentials();

        try {
            $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
            $configurationBuilder->apiKey($binanceConfig['apiKey'])
                ->secretKey($binanceConfig['secretKey']);
            $configurationBuilder->url($binanceConfig['baseUrl']);

            $this->client = new SpotRestApi($configurationBuilder->build());
        } catch (Exception $e) {
            throw new Exception("Erro ao inicializar cliente Binance: " . $e->getMessage());
        }
    }

    /**
     * Inicializa sistema de logs
     */
    private function applyRecvWindowToOrderRequest(NewOrderRequest $orderRequest): NewOrderRequest
    {
        if (method_exists($orderRequest, 'setRecvWindow')) {
            $orderRequest->setRecvWindow(self::BINANCE_RECV_WINDOW);
        }

        return $orderRequest;
    }

    private function initializeLogger(): void
    {
        // /var/log é mapeado para /opt/driftex/logs no host (volume do Portainer)
        // Mesmo local onde cron.log é gravado pelo verify_entry.php
        $this->logPath = '/var/log/';
    }

    /**
     * Sistema de logging com execution ID e filtro de níveis
     * Em modo normal (não-DEBUG): apenas WARNING, ERROR, SUCCESS são salvos
     * Em DEBUG_MODE: todos os logs são salvos
     */
    private function log(string $message, string $level = 'ERROR', string $type = 'SYSTEM'): void
    {
        // Filtro de níveis: em produção, descartar INFO e SYSTEM (ruído)
        if (!self::DEBUG_MODE) {
            if (in_array($level, ['INFO', 'SYSTEM']) && $type === 'SYSTEM') {
                return; // Não salvar logs de INFO/SYSTEM em produção
            }
        }

        $basePath = $this->logPath ?: rtrim(sys_get_temp_dir(), '/') . '/';
        $logFile = match ($type) {
            'API' => $basePath . self::API_LOG,
            'TRADE' => $basePath . self::TRADE_LOG,
            default => $basePath . self::ERROR_LOG
        };

        $execId = $this->executionId;

        $this->logBuffer[$logFile][] = sprintf(
            "[%s] [%s] [%s] [%s] - %s\n",
            date('Y-m-d H:i:s'),
            $execId,
            $level,
            $type,
            $message
        );

        if (count($this->logBuffer[$logFile]) >= $this->logBufferSize) {
            $this->flushLogFile($logFile);
        }
    }

    private function flushLogFile(string $logFile): void
    {
        if (empty($this->logBuffer[$logFile])) {
            return;
        }

        try {
            $dir = dirname($logFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            file_put_contents($logFile, implode('', $this->logBuffer[$logFile]), FILE_APPEND | LOCK_EX);
            $this->logBuffer[$logFile] = [];
        } catch (Exception $e) {
            // Silenciar erros de log para evitar loops infinitos
        }
    }

    public function flushLogs(): void
    {
        foreach (array_keys($this->logBuffer) as $logFile) {
            $this->flushLogFile($logFile);
        }
    }

    /**
     * Métodos auxiliares para integração com Binance API
     */
    private function logBinanceError(string $method, string $error, array $params = []): void
    {
        $message = "Método: {$method} | Erro: {$error}";
        if (!empty($params)) {
            $message .= " | Parâmetros: " . json_encode($params);
        }

        // Rate limit detection: 429 / 418 / Too many requests
        if (str_contains($error, '429') || str_contains($error, '418') || str_contains($error, 'Too many requests')) {
            $retryAfter = 60; // default se header não disponível
            BinanceRateLimitGuard::recordRateLimit($retryAfter);
            $this->log("🚫 Rate limit Binance — backoff {$retryAfter}s ativado", 'ERROR', 'SYSTEM');
        }

        $this->log($message, 'ERROR', 'API');
    }

    private function getAccountInfo(bool $forceRefresh = false): array
    {
        try {
            $now = time();
            if (
                !$forceRefresh &&
                $this->accountInfoCache !== null &&
                ($now - $this->accountInfoCacheTime) < self::CACHE_TTL_ACCOUNT_INFO
            ) {
                return $this->accountInfoCache;
            }

            $resp = $this->client->getAccount(null, self::BINANCE_RECV_WINDOW);
            $accountData = $resp->getData();
            $this->accountInfoCache = json_decode(json_encode($accountData), true);
            $this->accountInfoCacheTime = $now;
            return $this->accountInfoCache;
        } catch (Exception $e) {
            throw new Exception("Erro ao obter informações da conta: " . $e->getMessage());
        }
    }

    /**
     * Extrai o valor de um campo de saldo para um ativo específico
     * da lista de saldos retornada pela API da Binance.
     *
     * @param array  $balances Lista de saldos (accountInfo['balances'])
     * @param string $asset    Símbolo do ativo (ex: 'BTC', 'USDC')
     * @param string $field    Campo a extrair: 'free' (padrão) ou 'locked'
     * @return float           Valor do campo, ou 0.0 se não encontrado
     */
    private function getBalanceForAsset(array $balances, string $asset, string $field = 'free'): float
    {
        foreach ($balances as $balance) {
            if (($balance['asset'] ?? '') === $asset) {
                return (float)($balance[$field] ?? 0.0);
            }
        }
        return 0.0;
    }

    /**
     * Lê um valor de uma resposta da API Binance que pode ser objeto ou array.
     *
     * @param mixed  $data     Resposta da API (objeto ou array associativo)
     * @param string $getter   Nome do método getter (ex: 'getStatus')
     * @param string $arrayKey Chave do array de fallback (ex: 'status')
     * @param mixed  $default  Valor padrão se não encontrado
     * @return mixed
     */
    private function extractBinanceValue(mixed $data, string $getter, string $arrayKey, mixed $default = null): mixed
    {
        if (is_object($data) && method_exists($data, $getter)) {
            return $data->$getter();
        }
        return $data[$arrayKey] ?? $default;
    }

    /**
     * Obtém o saldo USDC disponível (free) que não está alocado em ordens pendentes
     * 
     * @param bool $forceRefresh Force latest account info from API
     * @return float Saldo USDC livre em USDC
     */
    private function getAvailableUsdcBalance(bool $forceRefresh = false): float
    {
        try {
            $accountInfo = $this->getAccountInfo($forceRefresh);

            return $this->getBalanceForAsset($accountInfo['balances'], 'USDC');
        } catch (Exception $e) {
            $this->log("Erro ao obter saldo USDC disponível: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return 0.0;
        }
    }

    /**
     * Calcula o capital para uma nova ordem de compra, incluindo reinvestimento de lucros
     * 
     * Distribui o lucro acumulado entre as novas ordens de compra (1/6 do lucro por ordem,
     * já que temos 6 níveis total: 3 BUY + 3 SELL iniciais).
     * 
     * @param int $gridId ID do grid
     * @param array $gridData Dados atuais do grid
     * @return float Capital ajustado com reinvestimento de lucros
     */
    private const REINVESTMENT_THRESHOLD = 10.0;

    private function getCapitalForNewBuyOrder(int $gridId, array $gridData): float
    {
        try {
            $baseCapital = (float)$gridData['capital_per_level'];
            $accumulatedProfit = (float)($gridData['accumulated_profit_usdc'] ?? 0.0);

            // Reinvestimento em batch: só reinveste quando acumula >= $10
            $extraCapital = 0.0;
            if ($accumulatedProfit >= self::REINVESTMENT_THRESHOLD) {
                $extraCapital = $accumulatedProfit;
                $this->resetAccumulatedProfit($gridId);
                $this->log(
                    "💰 Reinvestimento batch: \$" . number_format($extraCapital, 2) .
                        " adicionado ao capital (threshold: " . self::REINVESTMENT_THRESHOLD . ")",
                    'INFO',
                    'TRADE'
                );
            }

            $capitalWithReinvestment = $baseCapital + $extraCapital;

            // PRIORIDADE 1: Verificar se USDC livre comporta o capital base
            $availableUsdc = $this->getAvailableUsdcBalance(true);

            if ($availableUsdc >= $capitalWithReinvestment) {
                return $capitalWithReinvestment; // Capital base disponível — usar normalmente
            }

            // PRIORIDADE 2: Capital base indisponível — usar 85% do USDC livre como fallback
            $adjustedCapital = $availableUsdc * self::CAPITAL_USAGE_PERCENT;

            // PRIORIDADE 3: Validar se capital ajustado atinge o mínimo viável
            $minViable = self::MIN_TRADE_USDC * self::SAFETY_MARGIN;

            if ($adjustedCapital >= $minViable) {
                $this->log(
                    "📉 Capital ajustado para BUY: \$" . number_format($adjustedCapital, 2) .
                        " (base esperado: \$" . number_format($capitalWithReinvestment, 2) .
                        " | USDC disponível: \$" . number_format($availableUsdc, 2) . ")",
                    'INFO',
                    'TRADE'
                );
                return $adjustedCapital;
            }

            // PRIORIDADE 4: USDC insuficiente até para fallback
            $this->log(
                "⚠️ USDC insuficiente para BUY: disponível \$" . number_format($availableUsdc, 2) .
                    " | mínimo viável \$" . number_format($minViable, 2) .
                    " | base esperado \$" . number_format($capitalWithReinvestment, 2),
                'WARNING',
                'TRADE'
            );
            return 0.0;
        } catch (Exception $e) {
            $this->log("Erro ao calcular capital com reinvestimento: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return (float)$gridData['capital_per_level'];
        }
    }

    public function getExchangeInfo(string $symbol): array
    {
        try {
            // Cache em memória por CACHE_TTL_EXCHANGE_INFO segundos (padrão 60s)
            if (isset($this->exchangeInfoCache[$symbol])) {
                return $this->exchangeInfoCache[$symbol];
            }

            // Cache Redis por 600s
            $cacheKey = 'binance:exchangeInfo:' . $symbol;
            $redis = RedisCache::getInstance();
            $cached = $redis->get($cacheKey);
            if ($cached !== false && $cached !== null) {
                $decoded = json_decode($cached, true);
                $this->exchangeInfoCache[$symbol] = $decoded;
                return $decoded;
            }

            $url = "https://api.binance.com/api/v3/exchangeInfo?symbol={$symbol}";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$response || $httpCode !== 200) {
                throw new \Exception("exchangeInfo HTTP $httpCode");
            }

            $exchangeData = json_decode($response, true);
            if (!isset($exchangeData['symbols'][0])) {
                throw new Exception("Símbolo {$symbol} não encontrado na API da Binance.");
            }

            $redis->set($cacheKey, $response, 600);
            $this->exchangeInfoCache[$symbol] = $exchangeData['symbols'][0];
            return $this->exchangeInfoCache[$symbol];
        } catch (Exception $e) {
            throw new Exception("Erro ao obter exchange info: " . $e->getMessage());
        }
    }

    private function extractFilters(array $symbolData): array
    {
        $filters = array_column($symbolData['filters'], null, 'filterType');
        if (!isset($filters['LOT_SIZE'], $filters['PRICE_FILTER'])) {
            throw new Exception("Filtros não encontrados nos dados do símbolo.");
        }

        $minNotional = null;
        if (isset($filters['MIN_NOTIONAL']['minNotional'])) {
            $minNotional = (float)$filters['MIN_NOTIONAL']['minNotional'];
        } elseif (isset($filters['NOTIONAL']['minNotional'])) {
            $minNotional = (float)$filters['NOTIONAL']['minNotional'];
        }

        $pps = null;
        if (isset($filters['PERCENT_PRICE_BY_SIDE'])) {
            $pps = [
                'bidMultiplierUp'   => (float)$filters['PERCENT_PRICE_BY_SIDE']['bidMultiplierUp'],
                'bidMultiplierDown' => (float)$filters['PERCENT_PRICE_BY_SIDE']['bidMultiplierDown'],
                'askMultiplierUp'   => (float)$filters['PERCENT_PRICE_BY_SIDE']['askMultiplierUp'],
                'askMultiplierDown' => (float)$filters['PERCENT_PRICE_BY_SIDE']['askMultiplierDown'],
            ];
        }

        return [
            $filters['LOT_SIZE']['stepSize'],
            $filters['PRICE_FILTER']['tickSize'],
            $minNotional,
            $pps
        ];
    }

    private function calculateAdjustedQuantity(float $investimento, float $currentPrice, string $stepSize): string
    {
        $stepSizeFloat = (float)$stepSize;
        $quantity = $investimento / $currentPrice;
        $decimalPlacesQty = $this->getDecimalPlaces($stepSize);
        $quantity = floor($quantity / $stepSizeFloat) * $stepSizeFloat;

        return number_format($quantity, $decimalPlacesQty, '.', '');
    }

    private function adjustPriceToTickSize(float $price, string $tickSize): string
    {
        $decimalPlacesPrice = $this->getDecimalPlaces($tickSize);
        return number_format($price, $decimalPlacesPrice, '.', '');
    }

    private function getDecimalPlaces(string $value): int
    {
        $trimmed = rtrim($value, '0');
        $dotPos = strpos($trimmed, '.');
        return $dotPos !== false ? strlen($trimmed) - $dotPos - 1 : 0;
    }

    /**
     * Retorna o spacing do grid específico para cada moeda
     */
    private function getGridSpacing(string $symbol): float
    {
        $spacings = [
            'BTCUSDC' => self::GRID_SPACING_PERCENT,  // 0.01 (1%)
            'ETHUSDC' => 0.012,  // 1.2%
            'XRPUSDC' => 0.008,  // 0.8%
            'BNBUSDC' => 0.010   // 1.0%
        ];

        return $spacings[$symbol] ?? self::GRID_SPACING_PERCENT;
    }

    /**
     * Retorna o preço canônico do slot mais próximo na grade geométrica.
     *   slot  = round(log($targetPrice / $centerPrice) / log(1 + $gridSpacing))
     *   preço = $centerPrice × (1 + $gridSpacing)^slot
     *
     * Normalizar o preço antes de criar ordens garante que todos os níveis
     * fiquem em posições exatas da grade, eliminando desvios de float/tickSize.
     */
    private function normalizeToGrid(float $targetPrice, float $centerPrice, float $gridSpacing): float
    {
        if ($centerPrice <= 0 || $gridSpacing <= 0 || $targetPrice <= 0) {
            return $targetPrice;
        }
        $slot = (int)round(log($targetPrice / $centerPrice) / log(1 + $gridSpacing));
        return $centerPrice * pow(1 + $gridSpacing, $slot);
    }

    /**
     * Método principal de execução
     * Ponto de entrada do bot que é chamado via cron
     */
    public function display(): void
    {
        $startTime = microtime(true);
        $successCount = 0;
        $failureCount = 0;

        try {
            // Log de início com separador visual
            $this->log("════════════════════════════════════════════════════════════", 'INFO', 'SYSTEM');
            $this->log("=== Grid Trading Bot INICIADO | Exec: {$this->executionId} | Host: " . gethostname() . " ===", 'INFO', 'SYSTEM');

            // Rate limit guard: se Binance está em backoff, pular ciclo inteiro
            if (BinanceRateLimitGuard::isInBackoff()) {
                $this->log("⏸️ Backoff Binance ativo — ciclo pulado", 'WARNING', 'SYSTEM');
                return;
            }

            // Rotação de logs antigos
            $this->rotateOldLogs();

            // 1. Carregar capital USDC disponível
            $this->loadCapitalInfo();
            $this->log("💰 Capital USDC disponível: \$" . number_format($this->totalCapital, 2), 'INFO', 'SYSTEM');

            // 2. Processar cada símbolo
            foreach (self::SYMBOLS as $symbol) {
                try {
                    // $this->log("--- Processando $symbol ---", 'INFO', 'TRADE');
                    $this->processSymbol($symbol);
                    $successCount++;
                } catch (Exception $e) {
                    $this->log("Erro ao processar $symbol: " . $e->getMessage(), 'ERROR', 'TRADE');
                    $failureCount++;
                    continue;
                }
            }

            // 3. Estatísticas finais
            $execTime = round((microtime(true) - $startTime) * 1000, 2);
            $totalSymbols = count(self::SYMBOLS);
            $this->log(
                "=== Grid Trading Bot FINALIZADO | Exec: {$this->executionId} | " .
                    "Símbolos: {$successCount}/{$totalSymbols} OK, {$failureCount} falhas | " .
                    "Tempo: {$execTime}ms ===",
                'INFO',
                'SYSTEM'
            );
            $this->log("════════════════════════════════════════════════════════════", 'INFO', 'SYSTEM');
        } catch (Exception $e) {
            $this->log("ERRO CRÍTICO no display(): " . $e->getMessage(), 'ERROR', 'SYSTEM');
        } finally {
            $this->flushLogs();
        }
    }

    /**
     * Cancela o grid ativo e todas suas ordens abertas na Binance
     * Usar uma única vez para resetar grid com distribuição de níveis incorreta
     */
    public function resetCurrentGrid(string $symbol = 'BTCUSDC'): void
    {
        try {
            $grid = $this->getActiveGrid($symbol);

            if (!$grid) {
                $this->log("Nenhum grid ativo encontrado para $symbol", 'WARNING', 'SYSTEM');
                return;
            }

            $gridId = (int)$grid['idx'];
            $this->log("🔄 Resetando grid #$gridId para $symbol...", 'INFO', 'SYSTEM');

            // 1. Cancelar todas ordens abertas na Binance
            $this->cancelAllGridOrders($gridId);

            // 2. Marcar grid como cancelado
            $this->updateGridStatus($gridId, 'cancelled');

            // 3. Desativar ordens no banco (bulk UPDATE — mais eficiente que loop por registro)
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter(["grids_id = '{$gridId}'"]);
            $gridsOrdersModel->load_data();

            $idxList = implode(',', array_map('intval', array_column($gridsOrdersModel->data, 'idx')));
            if ($idxList !== '') {
                $bulkModel = new grids_orders_model();
                $bulkModel->set_filter(["idx IN ($idxList)"]);
                $bulkModel->populate(['active' => 'no']);
                $bulkModel->save();
            }

            // 4. Limpar cache em memória
            unset($this->activeGrids[$symbol]);

            $this->log("✅ Grid #$gridId resetado! Pronto para criar novo grid com 3+3 níveis.", 'INFO', 'SYSTEM');
        } catch (Exception $e) {
            $this->log("Erro ao resetar grid: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            throw $e;
        }
    }

    /**
     * Processa um símbolo: verifica se grid existe, monitora ou cria novo
     * Inclui proteção contra race condition via lock de processamento
     */
    private function processSymbol(string $symbol): void
    {
        try {
            // 1. Verificar se já existe grid ativo
            $activeGrid = $this->getActiveGrid($symbol);

            if ($activeGrid) {
                $gridId = (int)$activeGrid['idx'];

                // ══════ RACE CONDITION PROTECTION ══════
                // Tentar adquirir lock ANTES de processar o grid
                if (!$this->acquireGridLock($gridId)) {
                    $this->log(
                        "⏳ Grid #$gridId ($symbol) já está sendo processado por outra instância. Pulando...",
                        'WARNING',
                        'SYSTEM'
                    );
                    return;
                }

                try {
                    // Grid existe → Sincronizar ordens e depois monitorar
                    // (não depende de saldo USDC livre — o capital já está alocado em ordens/BTC)
                    $this->syncOrdersWithBinance($activeGrid['idx']);
                    $this->monitorGrid($activeGrid);
                } finally {
                    // GARANTIR liberação do lock mesmo em caso de exceção
                    $this->releaseGridLock($gridId);
                }
            } else {
                // Grid não existe → VERIFICAR PROTEÇÕES ANTES DE CRIAR

                // ══════ PROTEÇÃO: STOP-LOSS ACIONADO ══════
                // Se botão "Emergência" foi clicado, NÃO recriar automaticamente
                // Usuário deve usar "Religar Bot" explicitamente
                if ($this->hasStopLossTriggered($symbol)) {
                    $this->log(
                        "🛑 Stop-loss acionado para $symbol. Grid bloqueado até uso de 'Religar Bot'. CRON não criará novo grid.",
                        'WARNING',
                        'SYSTEM'
                    );
                    return;
                }

                // ══════ PROTEÇÃO: CAPITAL MÍNIMO ══════
                if ($this->totalCapital < self::MIN_TRADE_USDC) {
                    $this->log(
                        "Capital USDC insuficiente para criar novo grid em $symbol: {$this->totalCapital} USDC (mínimo: " . self::MIN_TRADE_USDC . " USDC)",
                        'WARNING',
                        'SYSTEM'
                    );
                    return;
                }

                // ✅ Tudo OK, criar novo grid
                $this->createNewGrid($symbol);
            }
        } catch (Exception $e) {
            throw new Exception("Erro ao processar $symbol: " . $e->getMessage());
        }
    }

    /**
     * Sincroniza status das ordens do grid com a Binance
     * Atualiza status e quantidade executada das ordens pendentes
     */
    private function syncOrdersWithBinance(int $gridId): void
    {
        try {
            // Buscar ordens pendentes do grid usando framework DOL
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "grids_id = '{$gridId}'",
                "orders_id IN (SELECT idx FROM orders WHERE status IN ('NEW', 'PARTIALLY_FILLED'))"
            ]);

            // CRITICAL: load_data() ANTES de join()
            $gridsOrdersModel->load_data();

            // JOIN para carregar dados da tabela orders
            $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);

            if (empty($gridsOrdersModel->data)) {
                return;
            }

            $updatedCount = 0;

            foreach ($gridsOrdersModel->data as $gridOrder) {
                // Acessar ordem via orders_attach
                $order = $gridOrder['orders_attach'][0] ?? null;

                if (!$order || !in_array($order['status'], ['NEW', 'PARTIALLY_FILLED'])) {
                    continue;
                }

                try {
                    // Consultar status na Binance
                    $response = $this->client->getOrder($order['symbol'], $order['binance_order_id'], null, self::BINANCE_RECV_WINDOW);
                    $binanceOrder = $response->getData();

                    $newStatus   = $this->extractBinanceValue($binanceOrder, 'getStatus', 'status', null);
                    $executedQty = $this->extractBinanceValue($binanceOrder, 'getExecutedQty', 'executedQty', 0);

                    // Atualizar ordem se status mudou OU se executed_qty diverge
                    $dbExecutedQty = (float)($order['executed_qty'] ?? 0);
                    $binanceExecutedQty = (float)$executedQty;
                    $statusChanged = ($newStatus && $newStatus !== $order['status']);
                    $qtyChanged = (abs($binanceExecutedQty - $dbExecutedQty) > 0.00000001 && $binanceExecutedQty > 0);

                    if ($statusChanged || $qtyChanged) {
                        $updateData = [
                            'executed_qty' => $binanceExecutedQty
                        ];
                        if ($statusChanged) {
                            $updateData['status'] = $newStatus;
                        }

                        $ordersModel = new orders_model();
                        $ordersModel->set_filter(["idx = '{$order['idx']}'"]);
                        $ordersModel->populate($updateData);
                        $ordersModel->save();

                        $updatedCount++;

                        $reason = $statusChanged ? "{$order['status']} → {$newStatus}" : "qty corrigida: {$dbExecutedQty} → {$binanceExecutedQty}";
                        $this->log(
                            "Ordem {$order['binance_order_id']} atualizada: {$reason}",
                            'INFO',
                            'API'
                        );

                        // Fills reais: quando muda para FILLED ou PARTIALLY_FILLED, buscar myTrades
                        if ($statusChanged && in_array($newStatus, ['FILLED', 'PARTIALLY_FILLED'])) {
                            $this->fetchAndStoreFillDetails((int)$order['idx'], (string)$order['binance_order_id'], (string)$order['symbol']);
                        }
                    }
                } catch (Exception $e) {
                    // Silencioso para evitar spam de logs (ex: timestamp errors)
                    continue;
                }
            }

            if ($updatedCount > 0) {
                $this->log("$updatedCount ordem(ns) sincronizada(s) com a Binance", 'INFO', 'SYSTEM');
            }

            // PASSO 2: Corrigir ordens FILLED com executed_qty=0 (dados corrompidos)
            $this->fixFilledOrdersWithZeroQty($gridId);
        } catch (Exception $e) {
            $this->log("Erro ao sincronizar ordens: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Busca dados reais de fills (myTrades) na Binance e persiste commission/is_maker.
     */
    private function fetchAndStoreFillDetails(int $orderDbId, string $binanceOrderId, string $symbol): void
    {
        try {
            $creds = BinanceConfig::getActiveCredentials();
            $apiKey = $creds['apiKey'];
            $secretKey = $creds['secretKey'];
            $baseUrl = $creds['baseUrl'] ?? 'https://api.binance.com';

            $timestamp = round(microtime(true) * 1000);
            $query = "symbol={$symbol}&orderId={$binanceOrderId}&timestamp={$timestamp}";
            $signature = hash_hmac('sha256', $query, $secretKey);
            $url = "{$baseUrl}/api/v3/myTrades?{$query}&signature={$signature}";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPHEADER     => ["X-MBX-APIKEY: {$apiKey}"],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$response || $httpCode !== 200) {
                $this->log("myTrades HTTP {$httpCode} para ordem #{$binanceOrderId}", 'WARNING', 'API');
                return;
            }

            $trades = json_decode($response, true);
            if (empty($trades) || !is_array($trades)) {
                return;
            }

            $totalCommission = 0.0;
            $commissionAsset = null;
            $isMaker = null;

            foreach ($trades as $trade) {
                $commission = (float)($trade['commission'] ?? 0);
                $asset = $trade['commissionAsset'] ?? '';
                if ($commissionAsset === null) {
                    $commissionAsset = $asset;
                }
                $totalCommission += $commission;
                if ($isMaker === null) {
                    $isMaker = ($trade['isMaker'] ?? false) ? 1 : 0;
                }
            }

            $commissionUsdc = null;
            if ($commissionAsset === 'USDC') {
                $commissionUsdc = $totalCommission;
            } elseif ($commissionAsset && $totalCommission > 0) {
                $tickerKey = 'ticker:' . $commissionAsset . 'USDC';
                $redis = RedisCache::getInstance();
                $rate = $redis->get($tickerKey);
                if ($rate === false) {
                    $tickerUrl = "{$baseUrl}/api/v3/ticker/price?symbol=" . $commissionAsset . "USDC";
                    $tickerCh = curl_init($tickerUrl);
                    curl_setopt_array($tickerCh, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 3,
                        CURLOPT_CONNECTTIMEOUT => 2,
                    ]);
                    $tickerResp = curl_exec($tickerCh);
                    curl_close($tickerCh);
                    if ($tickerResp) {
                        $tickerData = json_decode($tickerResp, true);
                        $rate = (float)($tickerData['price'] ?? 0);
                        if ($rate > 0) {
                            $redis->set($tickerKey, $rate, 60);
                        }
                    }
                }
                if ($rate && (float)$rate > 0) {
                    $commissionUsdc = $totalCommission * (float)$rate;
                }
            }

            $ordersModel = new orders_model();
            $ordersModel->set_filter(["idx = '{$orderDbId}'"]);
            $ordersModel->populate([
                'commission' => $totalCommission > 0 ? $totalCommission : null,
                'commission_asset' => $commissionAsset,
                'commission_usdc_equivalent' => $commissionUsdc !== null ? $commissionUsdc : null,
                'is_maker' => $isMaker,
            ]);
            $ordersModel->save();

            $this->log("Fill details persistido para ordem #{$binanceOrderId} (commission: {$totalCommission} {$commissionAsset})", 'INFO', 'API');
        } catch (Exception $e) {
            $this->log("Erro em fetchAndStoreFillDetails: " . $e->getMessage(), 'ERROR', 'API');
        }
    }

    /**
     * Corrige ordens FILLED que têm executed_qty=0 no banco — consulta Binance para obter o valor real.
     * Isso resolve a causa raiz de loops infinitos em handleBuyOrderFilled.
     */
    private function fixFilledOrdersWithZeroQty(int $gridId): void
    {
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "grids_id = '{$gridId}'",
                "orders_id IN (SELECT idx FROM orders WHERE status = 'FILLED' AND (executed_qty = 0 OR executed_qty IS NULL))"
            ]);
            $gridsOrdersModel->load_data();
            $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);

            if (empty($gridsOrdersModel->data)) {
                return;
            }

            $fixedCount = 0;

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders_attach'][0] ?? null;
                if (!$order) {
                    continue;
                }

                $binanceOrderId = $order['binance_order_id'] ?? null;
                if (!$binanceOrderId) {
                    $this->log(
                        "[FixFilledQty] Ordem idx={$order['idx']} FILLED com qty=0 sem binance_order_id. Não é possível corrigir.",
                        'WARNING',
                        'SYSTEM'
                    );
                    continue;
                }

                try {
                    $response = $this->client->getOrder($order['symbol'], $binanceOrderId, null, self::BINANCE_RECV_WINDOW);
                    $binanceData = $response->getData();
                    $realQty = (float)$this->extractBinanceValue($binanceData, 'getExecutedQty', 'executedQty', 0);

                    if ($realQty > 0) {
                        $ordersModel = new orders_model();
                        $ordersModel->set_filter(["idx = '{$order['idx']}'"]);
                        $ordersModel->populate(['executed_qty' => $realQty]);
                        $ordersModel->save();
                        $fixedCount++;
                        $this->log(
                            "[FixFilledQty] ✅ Ordem {$binanceOrderId} (idx={$order['idx']}): executed_qty corrigido 0 → {$realQty}",
                            'SUCCESS',
                            'SYSTEM'
                        );
                    } else {
                        $this->log(
                            "[FixFilledQty] ⚠️ Ordem {$binanceOrderId} (idx={$order['idx']}): Binance também retorna qty=0.",
                            'WARNING',
                            'SYSTEM'
                        );
                    }
                } catch (Exception $e) {
                    $this->log(
                        "[FixFilledQty] Erro ao consultar Binance para ordem {$binanceOrderId}: " . $e->getMessage(),
                        'ERROR',
                        'SYSTEM'
                    );
                }
            }

            if ($fixedCount > 0) {
                $this->log("[FixFilledQty] {$fixedCount} ordem(ns) FILLED com qty=0 corrigida(s)", 'SUCCESS', 'SYSTEM');
            }
        } catch (Exception $e) {
            $this->log("[FixFilledQty] Erro: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Cancela ordens obsoletas/travadas que estão impedindo a recuperação de BTC órfão
     * Busca ordens SELL abertas na Binance que não deveriam estar ativas
     * 
     * @param int $gridId ID do grid
     * @param string $symbol Par de negociação 
     */
    private function cancelObsoleteOrders(int $gridId, string $symbol): void
    {
        try {
            // Buscar ordens ABERTAS na Binance
            $response = $this->client->getOpenOrders($symbol);

            if ($response === null) {
                return;
            }

            $responseData = $response->getData();

            if ($responseData === null) {
                $responseData = [];
            }

            // Converter resposta para array
            $openOrders = [];
            if (is_array($responseData)) {
                $openOrders = $responseData;
            } elseif (is_object($responseData)) {
                // Tentar chamar getItems() se existir (padrão do Binance SDK)
                if (method_exists($responseData, 'getItems')) {
                    $openOrders = $responseData->getItems();
                } else {
                    // Fallback: tentar json_encode
                    $jsonStr = json_encode($responseData);
                    $openOrders = json_decode($jsonStr, true) ?? [];
                }
            }

            if (empty($openOrders)) {
                return;
            }

            $canceledCount = 0;
            $currentTime = (int)(microtime(true) * 1000); // em millisegundos
            $maxAgeMs = 15 * 60 * 1000; // 15 minutos em millisegundos

            foreach ($openOrders as $binanceOrder) {
                try {
                    if (!is_array($binanceOrder)) {
                        $binanceOrder = json_decode(json_encode($binanceOrder), true);
                    }

                    $binanceOrderId = $binanceOrder['orderId'] ?? null;
                    if ($binanceOrderId === null) {
                        continue;
                    }

                    $side = $binanceOrder['side'] ?? 'UNKNOWN';
                    $status = $binanceOrder['status'] ?? 'UNKNOWN';

                    // Buscar ordem no banco
                    $ordersModel = new orders_model();
                    $ordersModel->set_filter(["binance_order_id = '{$binanceOrderId}'"]);
                    $ordersModel->load_data();

                    // Se não está no banco, cancelar (órfã)
                    if (count($ordersModel->data) === 0) {
                        try {
                            $this->client->deleteOrder($symbol, $binanceOrderId, null, null, null, self::BINANCE_RECV_WINDOW);
                            $this->log(
                                "✅ Ordem órfã {$binanceOrderId} cancelada",
                                'SUCCESS',
                                'SYSTEM'
                            );
                        } catch (Exception $cancelErr) {
                            $this->log(
                                "❌ Erro ao cancelar órfã {$binanceOrderId}: " . $cancelErr->getMessage(),
                                'ERROR',
                                'SYSTEM'
                            );
                        }
                        $canceledCount++;
                        continue;
                    }

                    $dbOrder = $ordersModel->data[0];
                    $dbStatus = $dbOrder['status'] ?? 'UNKNOWN';
                    $dbOrderIdx = $dbOrder['idx'] ?? 'N/A';
                    $createdAt = (int)($dbOrder['order_created_at'] ?? 0);
                    $ageMinutes = $createdAt > 0 ? round(($currentTime - $createdAt) / 1000 / 60) : 0;

                    // Se status NEW e ficou aberto por > 15 min, cancelar (não vai executar)
                    if ($status === 'NEW' && $createdAt > 0 && ($currentTime - $createdAt) > $maxAgeMs) {
                        $this->log(
                            "⚠️ Ordem ID={$dbOrderIdx} ({$side}) NEW por {$ageMinutes}min. Cancelando...",
                            'WARNING',
                            'SYSTEM'
                        );

                        try {
                            $this->client->deleteOrder($symbol, $binanceOrderId, null, null, null, self::BINANCE_RECV_WINDOW);
                            $ordersModel->load_byIdx($dbOrderIdx);
                            $ordersModel->populate(['status' => 'CANCELED']);
                            $ordersModel->save();
                            $this->log(
                                "✅ Ordem cancelada",
                                'SUCCESS',
                                'SYSTEM'
                            );
                            $canceledCount++;
                        } catch (Exception $e) {
                            $this->log(
                                "❌ Erro ao cancelar: " . $e->getMessage(),
                                'ERROR',
                                'SYSTEM'
                            );
                        }
                    }

                    // NOTA: NÃO cancelar SELLs NEW indiscriminadamente!
                    // SELLs NEW são ordens válidas esperando execução (BTC bloqueado).
                    // Apenas o bloco anterior (> 15 min) lida com ordens que ficaram paradas tempo demais.
                } catch (Exception $e) {
                    continue;
                }
            }

            if ($canceledCount > 0) {
                $this->log(
                    "✅ {$canceledCount} ordem(s) cancelada(s)",
                    'SUCCESS',
                    'SYSTEM'
                );
            }
        } catch (Exception $e) {
            $this->log(
                "Erro ao cancelar ordens: " . $e->getMessage(),
                'ERROR',
                'SYSTEM'
            );
        }
    }

    /**
     * Prepara o capital inicial: 60% USDC (compras) + 40% BTC (vendas superiores)
     * Compra BTC automaticamente se o saldo disponível for insuficiente.
     *
     * @param string $symbol Par de negociação (ex: BTCUSDC)
     * @return array ['usdc_for_buys', 'btc_for_sells', 'current_price', 'total_capital_usd']
     */
    private function prepareInitialCapital(string $symbol): array
    {
        try {
            $baseAsset = str_replace('USDC', '', $symbol);
            $accountInfo = $this->getAccountInfo(true);

            $usdcBalance = 0.0;
            $btcBalance  = 0.0;

            $usdcBalance = $this->getBalanceForAsset($accountInfo['balances'], 'USDC');
            $btcBalance  = $this->getBalanceForAsset($accountInfo['balances'], $baseAsset);

            $currentPrice  = $this->getCurrentPrice($symbol);
            $totalCapital  = $usdcBalance + ($btcBalance * $currentPrice);

            // Quanto BTC devemos ter (40% do capital total)
            $targetBtcValue = $totalCapital * self::INITIAL_BTC_ALLOCATION;
            $targetBtcQty   = $targetBtcValue / $currentPrice;
            $needToBuy      = $targetBtcQty - $btcBalance;

            if ($needToBuy > 0.0001) {
                $this->log(
                    "🤖 Comprando " . number_format($needToBuy, 8) . " $baseAsset para grid híbrido (~$" . number_format($needToBuy * $currentPrice, 2) . " USDC)",
                    'INFO',
                    'TRADE'
                );

                $this->buyBtcForGrid($symbol, $needToBuy);

                // Recarregar saldos após compra
                $accountInfo = $this->getAccountInfo(true);
                $usdcBalance = $this->getBalanceForAsset($accountInfo['balances'], 'USDC');
                $btcBalance  = $this->getBalanceForAsset($accountInfo['balances'], $baseAsset);

                $this->log(
                    "✅ Compra inicial concluída! Saldo: $btcBalance $baseAsset (~$" . number_format($btcBalance * $currentPrice, 2) . ") | $usdcBalance USDC",
                    'SUCCESS',
                    'TRADE'
                );
            } else {
                $this->log(
                    "✅ Saldo $baseAsset suficiente: $btcBalance $baseAsset (~$" . number_format($btcBalance * $currentPrice, 2) . ")",
                    'INFO',
                    'TRADE'
                );
            }

            return [
                'usdc_for_buys'    => $usdcBalance,
                'btc_for_sells'    => $btcBalance,
                'current_price'    => $currentPrice,
                'total_capital_usd' => $usdcBalance + ($btcBalance * $currentPrice)
            ];
        } catch (Exception $e) {
            throw new Exception("Erro ao preparar capital inicial: " . $e->getMessage());
        }
    }

    /**
     * Compra BTC a mercado para alocação inicial do grid híbrido
     *
     * @param string $symbol   Par de negociação (ex: BTCUSDC)
     * @param float  $quantity Quantidade de BTC a comprar
     */
    private function buyBtcForGrid(string $symbol, float $quantity): void
    {
        try {
            $symbolData = $this->getExchangeInfo($symbol);
                list($stepSize, $tickSize, $minNotional, $pps) = $this->extractFilters($symbolData);

            $stepSizeFloat  = (float)$stepSize;
            $adjustedQty    = floor($quantity / $stepSizeFloat) * $stepSizeFloat;

            if ($adjustedQty <= 0) {
                throw new Exception("Quantidade inválida para compra inicial de BTC: $adjustedQty");
            }

            $orderReq = new NewOrderRequest();
            $orderReq->setSymbol($symbol);
            $orderReq->setSide(Side::BUY);
            $orderReq->setType(OrderType::MARKET);
            $orderReq->setQuantity((float)$adjustedQty);

            $response  = $this->client->newOrder($this->applyRecvWindowToOrderRequest($orderReq));
            $orderData = $response->getData();

            $executedQty     = $this->extractBinanceValue($orderData, 'getExecutedQty', 'executedQty', $adjustedQty);
            $cumulativeQuote = (float)$this->extractBinanceValue($orderData, 'getCummulativeQuoteQty', 'cummulativeQuoteQty', 0.0);
            $avgPrice = ($executedQty > 0 && $cumulativeQuote > 0)
                ? $cumulativeQuote / $executedQty
                : 0.0;

            $this->log(
                "✅ Compra MARKET inicial: $executedQty $symbol @ ~$" . number_format($avgPrice, 2),
                'SUCCESS',
                'TRADE'
            );
        } catch (Exception $e) {
            throw new Exception("Erro ao comprar BTC inicial: " . $e->getMessage());
        }
    }

    /**
     * Cria um novo grid híbrido: ordens BUY abaixo (USDC) + ordens SELL acima (BTC)
     */
    private function createNewGrid(string $symbol): void
    {
        try {
            // 0. PREPARAR CAPITAL INICIAL (40% BTC + 60% USDC)
            $this->log("🔄 Preparando capital inicial para grid híbrido...", 'INFO', 'TRADE');
            $capital = $this->prepareInitialCapital($symbol);

            // 1. OBTER PREÇO ATUAL
            $currentPrice = $capital['current_price'];
            if ($currentPrice <= 0) {
                $this->log("Preço inválido para $symbol", 'ERROR', 'TRADE');
                return;
            }

            // 2. CALCULAR RANGE DO GRID (referência para saveGridConfig)
            $gridMin = $currentPrice * (1 - self::GRID_RANGE_PERCENT);
            $gridMax = $currentPrice * (1 + self::GRID_RANGE_PERCENT);

            // 3. DEFINIR NÍVEIS DE PREÇO (SIMÉTRICO: 3 ABAIXO + 3 ACIMA)
            // Garante sempre exatamente 3 BUYs + 3 SELLs, independente do preço atual.
            $buyLevels  = [];
            $sellLevels = [];
            $gridSpacing = $this->getGridSpacing($symbol); // 1% por padrão

            // CALCULAR 3 NÍVEIS DE COMPRA (abaixo do preço atual)
            // Nível 3 = mais distante | Nível 1 = mais próximo
            for ($i = 1; $i <= 3; $i++) {
                $buyPrice   = $currentPrice * (1 - ($i * $gridSpacing));
                $buyLevels[] = [
                    'level' => 4 - $i, // 3, 2, 1 — Nível 1 mais próximo
                    'price' => $buyPrice
                ];
            }

            // CALCULAR 3 NÍVEIS DE VENDA (acima do preço atual)
            // Nível 1 = mais próximo | Nível 3 = mais distante
            for ($i = 1; $i <= 3; $i++) {
                $sellPrice    = $currentPrice * (1 + ($i * $gridSpacing));
                $sellLevels[] = [
                    'level' => $i, // 1, 2, 3
                    'price' => $sellPrice
                ];
            }

            $numBuyLevels  = count($buyLevels);  // Sempre 3
            $numSellLevels = count($sellLevels); // Sempre 3

            $this->log(
                "📊 Grid configurado: {$numBuyLevels} BUYs (abaixo) + {$numSellLevels} SELLs (acima) | Preço central: $" . number_format($currentPrice, 2),
                'INFO',
                'TRADE'
            );

            // 4. DIVIDIR CAPITAL
            $capitalPerBuyLevel  = $capital['usdc_for_buys'] / $numBuyLevels;
            $btcPerSellLevel     = $numSellLevels > 0 ? $capital['btc_for_sells'] / $numSellLevels : 0;
            $totalCapital        = $capital['total_capital_usd'];

            // 5. SALVAR CONFIGURAÇÃO DO GRID NO BANCO
            $gridId = $this->saveGridConfig(
                $symbol,
                $gridMin,
                $gridMax,
                $currentPrice,
                $totalCapital,
                $capitalPerBuyLevel,
                (float)$capital['usdc_for_buys']
            );

            // 6. CRIAR ORDENS LIMIT DE COMPRA (níveis ABAIXO do preço — usa USDC)
            $successBuys = 0;
            $failedBuys  = [];
            foreach ($buyLevels as $level) {
                try {
                    $orderDbId = $this->placeBuyOrder(
                        $gridId,
                        $symbol,
                        $level['level'],
                        $level['price'],
                        $capitalPerBuyLevel,
                        true // skipProfitValidation: criação inicial do grid
                    );
                    if ($orderDbId) {
                        $successBuys++;
                        $this->log("✅ BUY Nível {$level['level']} @ $" . number_format($level['price'], 2), 'INFO', 'TRADE');
                    } else {
                        $failedBuys[] = $level['level'];
                        $this->log("❌ Falha BUY Nível {$level['level']}", 'WARNING', 'TRADE');
                    }
                } catch (Exception $e) {
                    $failedBuys[] = $level['level'];
                    $this->log("❌ Exceção BUY Nível {$level['level']}: " . $e->getMessage(), 'ERROR', 'TRADE');
                }
            }

            // 7. CRIAR ORDENS LIMIT DE VENDA (níveis ACIMA do preço — usa BTC)
            $successSells = 0;
            $failedSells  = [];
            foreach ($sellLevels as $level) {
                try {
                    $orderDbId = $this->placeSellOrder(
                        $gridId,
                        $symbol,
                        $level['level'],
                        $level['price'],
                        $btcPerSellLevel,
                        null // sem paired_order_id: é venda inicial do grid
                    );
                    if ($orderDbId) {
                        $successSells++;
                        $this->log("✅ SELL Nível {$level['level']} @ $" . number_format($level['price'], 2), 'INFO', 'TRADE');
                    } else {
                        $failedSells[] = $level['level'];
                        $this->log("❌ Falha SELL Nível {$level['level']}", 'WARNING', 'TRADE');
                    }
                } catch (Exception $e) {
                    $failedSells[] = $level['level'];
                    $this->log("❌ Exceção SELL Nível {$level['level']}: " . $e->getMessage(), 'ERROR', 'TRADE');
                }
            }

            $allOk = ($successBuys === $numBuyLevels && $successSells === $numSellLevels);

            // Recalibrar a baseline logo apos criar as ordens iniciais usando o
            // mesmo snapshot de capital do monitoramento. Isso evita o grid nascer
            // com initial_capital menor que o capital real observado depois.
            $baselineSnapshot = $this->calculateCurrentCapitalSnapshot($symbol);
            $baselineCurrentCapital = (float)($baselineSnapshot['total'] ?? 0.0);
            $baselineUsdcBalance = (float)($baselineSnapshot['usdc'] ?? 0.0);

            if ($baselineCurrentCapital > 0) {
                $gridsModel = new grids_model();
                $gridsModel->set_filter(["idx = '" . $gridId . "'"]);
                $gridsModel->populate([
                    'capital_allocated_usdc' => $baselineCurrentCapital,
                    'initial_capital_usdc' => $baselineCurrentCapital,
                    'peak_capital_usdc' => $baselineCurrentCapital,
                    'current_capital_usdc' => $baselineCurrentCapital,
                    'last_usdc_balance_usdc' => $baselineUsdcBalance,
                    'capital_per_level' => $baselineCurrentCapital / self::GRID_LEVELS,
                ]);
                $gridsModel->save();
            }

            $this->log(
                "🎉 Grid HÍBRIDO criado para $symbol | BUYs: $successBuys/$numBuyLevels | SELLs: $successSells/$numSellLevels",
                $allOk ? 'SUCCESS' : 'WARNING',
                'TRADE'
            );

            $this->saveGridLog($gridId, 'grid_created_hybrid', $allOk ? 'success' : 'warning', "Grid híbrido criado para $symbol", [
                'grid_min'            => $gridMin,
                'grid_max'            => $gridMax,
                'center_price'        => $currentPrice,
                'buy_levels'          => $numBuyLevels,
                'sell_levels'         => $numSellLevels,
                'buy_orders_created'  => $successBuys,
                'sell_orders_created' => $successSells,
                'buy_orders_failed'   => count($failedBuys),
                'sell_orders_failed'  => count($failedSells),
                'usdc_allocated'      => $capital['usdc_for_buys'],
                'btc_allocated'       => $capital['btc_for_sells'],
                'total_capital_usd'   => $totalCapital,
                'baseline_capital_usd' => $baselineCurrentCapital > 0 ? $baselineCurrentCapital : $totalCapital,
                'baseline_usdc_balance' => $baselineUsdcBalance
            ]);
        } catch (Exception $e) {
            throw new Exception("Erro ao criar grid híbrido para $symbol: " . $e->getMessage());
        }
    }

    /**
     * Monitora um grid existente: processa ordens executadas e rebalanceia se necessário
     * Inclui proteções: Stop-Loss → Trailing Stop → processamento normal
     */
    private function monitorGrid(array $gridData): void
    {
        try {
            $symbol = $gridData['symbol'];
            $gridId = $gridData['idx'];

            $capitalSnapshot = $this->calculateCurrentCapitalSnapshot($symbol);
            $currentCapital = (float)($capitalSnapshot['total'] ?? 0.0);
            if ($currentCapital > 0) {
                $gridData = $this->autoRegisterContributionIfNeeded(
                    (int)$gridId,
                    $gridData,
                    $currentCapital,
                    (float)($capitalSnapshot['usdc'] ?? 0.0)
                );
            }

            // ══════ PROTEÇÃO 1: GLOBAL STOP-LOSS ══════
            // Verificação ANTES de qualquer processamento de ordens
            if (self::ENABLE_STOP_LOSS && $this->checkStopLoss((int)$gridId, $gridData)) {
                $this->log("🛑 STOP-LOSS acionado para grid #$gridId ($symbol). Interrompendo monitoramento.", 'ERROR', 'SYSTEM');
                return; // Interrompe imediatamente
            }

            // ══════ PROTEÇÃO 2: TRAILING STOP ══════
            // Verificação APÓS stop-loss mas ANTES de processar ordens
            if (self::ENABLE_TRAILING_STOP && $this->checkTrailingStop((int)$gridId, $gridData)) {
                $this->log("🛡️ TRAILING STOP acionado para grid #$gridId ($symbol). Interrompendo monitoramento.", 'WARNING', 'SYSTEM');
                return; // Interrompe imediatamente
            }

            // ══════ ATUALIZAR TRACKING DE CAPITAL ══════
            if ($currentCapital > 0) {
                $this->updateCapitalTracking((int)$gridId, $currentCapital);
                $this->maybeRecordCapitalSnapshot((int)$gridId, $symbol);
            }

            // 0. SINCRONIZAR STATUS DAS ORDENS COM A BINANCE
            $this->syncOrdersWithBinance($gridId);

            // 0.5 RECONCILIAÇÃO PERIÓDICA (~a cada 10 min com cron 15s)
            try {
                $redis = RedisCache::getInstance();
                $reconcileKey = "nexobot:reconcile_counter:{$gridId}";
                $counter = (int)$redis->get($reconcileKey);
                $counter++;
                $redis->set($reconcileKey, $counter, 3600);
                if ($counter % 40 === 0) {
                    $this->reconcileWithBinance((int)$gridId, (string)$symbol);
                }
            } catch (Exception $reconcileEx) {
                $this->log("Erro no contador de reconciliação: " . $reconcileEx->getMessage(), 'WARNING', 'SYSTEM');
            }

            // 1. BUSCAR ORDENS EXECUTADAS MAS NÃO PROCESSADAS
            $executedOrders = $this->getExecutedUnprocessedOrders($gridId);

            if (count($executedOrders) > 0) {
                $this->log("Processando " . count($executedOrders) . " ordens executadas no grid $gridId", 'INFO', 'TRADE');

                // ══════ LÓGICA "VIOLÃO" REATIVA ══════
                // Processar ordens em BATCH (não uma por uma)
                // Se 2 SELLs executam → divide USDC disponível por 2
                // Se 3 BUYs executam → divide BTC disponível por 3
                $this->handleFilledOrdersBatch($gridId, $executedOrders, $symbol);
            }

            // 2. SLIDING GRID (substitui rebalanceamento — desloca níveis sem cancelar tudo)
            $currentPrice = $this->getCurrentPrice($symbol);
            $this->slideGrid((int)$gridId, $symbol, $currentPrice, $gridData);

            // 3. ATUALIZAR ESTATÍSTICAS DO GRID
            $this->updateGridStats($gridId);
        } catch (Exception $e) {
            throw new Exception("Erro ao monitorar grid: " . $e->getMessage());
        }
    }


    /**
     * Reconcilia ordens do banco com a Binance periodicamente.
     * Recupera órfãos da Binance e marca como CANCELED ordens que sumiram.
     */
    private function reconcileWithBinance(int $gridId, string $symbol): void
    {
        try {
            // 1. Ordens abertas na Binance
            $openOrdersResp = $this->client->getOpenOrders($symbol, self::BINANCE_RECV_WINDOW);
            $openOrdersData = $openOrdersResp->getData();
            $binanceOrders = [];
            if (is_array($openOrdersData)) {
                foreach ($openOrdersData as $o) {
                    $binanceOrders[$o['clientOrderId'] ?? $o['orderId']] = $o;
                }
            }

            // 2. Ordens pendentes no banco
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "grids_id = '{$gridId}'",
                "orders_id IN (SELECT idx FROM orders WHERE status IN ('NEW', 'PARTIALLY_FILLED'))"
            ]);
            $gridsOrdersModel->load_data();
            $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);

            $dbOrders = [];
            foreach ($gridsOrdersModel->data as $go) {
                $ord = $go['orders_attach'][0] ?? null;
                if ($ord) {
                    $dbOrders[$ord['binance_client_order_id'] ?? $ord['binance_order_id']] = $ord;
                }
            }

            // 3. Binance-only (órfãos)
            foreach ($binanceOrders as $clientId => $bo) {
                if (str_starts_with((string)$clientId, "nx-{$gridId}-") && !isset($dbOrders[$clientId])) {
                    // Inserir como órfão recuperado
                    $orderParams = [
                        'grids_id' => $gridId,
                        'binance_order_id' => $bo['orderId'],
                        'binance_client_order_id' => $clientId,
                        'symbol' => $symbol,
                        'side' => $bo['side'],
                        'type' => $bo['type'],
                        'price' => $bo['price'],
                        'quantity' => $bo['origQty'],
                        'executed_qty' => $bo['executedQty'],
                        'status' => $bo['status'],
                        'recovered_orphan' => 1,
                        'order_created_at' => round(microtime(true) * 1000)
                    ];
                    $this->saveGridOrder($orderParams);
                    $this->log("🔄 Órfão recuperado: {$clientId} @ {$symbol} (status: {$bo['status']})", 'WARNING', 'TRADE');
                }
            }

            // 4. Banco-only (sumidas da Binance)
            foreach ($dbOrders as $clientId => $dbOrder) {
                $isNew = ($dbOrder['status'] ?? '') === 'NEW';
                $createdAt = (int)($dbOrder['order_created_at'] ?? 0);
                $minutesOld = (round(microtime(true) * 1000) - $createdAt) / 60000;

                if ($isNew && $minutesOld > 5 && !isset($binanceOrders[$clientId])) {
                    $ordersModel = new orders_model();
                    $ordersModel->set_filter(["idx = '{$dbOrder['idx']}'"]);
                    $ordersModel->populate(['status' => 'CANCELED']);
                    $ordersModel->save();
                    $this->log("🗑️ Ordem sumida da Binance marcada CANCELED: {$clientId}", 'WARNING', 'TRADE');
                }
            }
        } catch (Exception $e) {
            $this->log("Erro na reconciliação: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Processa a execução de uma ordem de compra
     * CRITICAL: Cria UMA venda pareada com EXATAMENTE a quantidade comprada
     */
    private function handleBuyOrderFilled(int $gridId, array $buyOrder): void
    {
        try {
            $symbol   = $buyOrder['symbol'];
            $baseAsset = str_replace('USDC', '', $symbol);
            $buyPrice = (float)$buyOrder['price'];
            $buyQty   = (float)$buyOrder['executed_qty'];
            $gridLevel = $buyOrder['grid_level'];
            $gridsOrdersIdx = $buyOrder['grids_orders_idx'];

            // CORREÇÃO: Se executed_qty=0 no banco mas ordem está FILLED, buscar valor real na Binance
            if ($buyQty <= 0) {
                $this->log(
                    "⚠️ BUY idx=$gridsOrdersIdx tem executed_qty=0 no banco. Consultando Binance...",
                    'WARNING',
                    'TRADE'
                );
                try {
                    $binanceOrderId = $buyOrder['binance_order_id'] ?? null;
                    if ($binanceOrderId) {
                        $response = $this->client->getOrder($symbol, $binanceOrderId, null, self::BINANCE_RECV_WINDOW);
                        $binanceData = $response->getData();
                        $realQty = (float)$this->extractBinanceValue($binanceData, 'getExecutedQty', 'executedQty', 0);

                        if ($realQty > 0) {
                            $buyQty = $realQty;
                            // Atualizar banco para corrigir permanently
                            $ordersModel = new orders_model();
                            $ordersModel->set_filter(["idx = '{$buyOrder['idx']}'"]);
                            $ordersModel->populate(['executed_qty' => $realQty]);
                            $ordersModel->save();
                            $this->log(
                                "✅ executed_qty corrigido via Binance: $realQty BTC",
                                'SUCCESS',
                                'TRADE'
                            );
                        } else {
                            $this->log(
                                "❌ Binance também retorna qty=0. Marcando como processada para evitar loop.",
                                'ERROR',
                                'TRADE'
                            );
                            // Marcar como processada para quebrar o loop infinito
                            $this->markOrderAsProcessed($gridsOrdersIdx);
                            return;
                        }
                    } else {
                        // binance_order_id indisponível — impossível consultar Binance
                        $this->log(
                            "❌ BUY idx=$gridsOrdersIdx com qty=0 e sem binance_order_id. " .
                                "Marcando como processada para evitar loop infinito.",
                            'ERROR',
                            'TRADE'
                        );
                        $this->markOrderAsProcessed($gridsOrdersIdx);
                        return;
                    }
                } catch (Exception $e) {
                    $this->log(
                        "❌ Erro ao consultar Binance para BUY idx=$gridsOrdersIdx: " . $e->getMessage(),
                        'ERROR',
                        'TRADE'
                    );
                    // Marcar como processada para evitar loop eterno
                    $this->markOrderAsProcessed($gridsOrdersIdx);
                    return;
                }
            }

            $this->log(
                "🔄 Processando BUY FILLED: grids_orders_idx=$gridsOrdersIdx, qty=$buyQty BTC @ $$buyPrice",
                'INFO',
                'TRADE'
            );

            // 1. VERIFICAR SE JÁ EXISTE SELL PAREADA (proteção contra duplicação)
            if ($this->hasPairedSellOrder($gridsOrdersIdx)) {
                $this->log(
                    "⚠️ BUY idx=$gridsOrdersIdx já possui SELL pareada. Pulando...",
                    'WARNING',
                    'TRADE'
                );
                return;
            }

            // 2. VERIFICAR SALDO DE BTC DISPONÍVEL
            $accountInfo = $this->getAccountInfo(true);
            $availableBtc = $this->getBalanceForAsset($accountInfo['balances'], $baseAsset);

            if ($availableBtc < $buyQty) {
                $this->log(
                    "⚠️ BTC disponível ($availableBtc) é menor que qty comprada ($buyQty). " .
                        "Criando SELL com saldo disponível.",
                    'WARNING',
                    'TRADE'
                );
                $sellQty = $availableBtc;
            } else {
                $sellQty = $buyQty; // Vender exatamente o que foi comprado
            }

            if ($sellQty <= 0) {
                throw new Exception(
                    "Nenhum BTC disponível para criar SELL pareada (BUY idx=$gridsOrdersIdx). " .
                        "Ordem será reprocessada."
                );
            }

            // 3. CALCULAR PREÇO DE VENDA (1 grid spacing acima)
            $gridSpacing = $this->getGridSpacing($symbol);
            $sellPrice = $buyPrice * (1 + $gridSpacing);

            // 4. CRIAR SELL PAREADA COM A QUANTIDADE EXATA
            $sellOrderId = $this->placeSellOrder(
                $gridId,
                $symbol,
                $gridLevel,
                $sellPrice,
                $sellQty,
                $gridsOrdersIdx // paired_order_id
            );

            if (!$sellOrderId) {
                throw new Exception(
                    "Falha ao criar SELL pareada para BUY idx=$gridsOrdersIdx. " .
                        "Ordem será reprocessada."
                );
            }

            $this->log(
                "✅ SELL pareada criada: Nível $gridLevel @ $" . number_format($sellPrice, 2) .
                    " | Qty: " . number_format($sellQty, 8) . " $baseAsset | Pareada com BUY idx=$gridsOrdersIdx",
                'SUCCESS',
                'TRADE'
            );

            $this->saveGridLog(
                $gridId,
                'buy_filled_sell_created',
                'success',
                "Compra executada e venda pareada criada",
                [
                    'buy_grids_orders_idx' => $gridsOrdersIdx,
                    'buy_price' => $buyPrice,
                    'buy_qty' => $buyQty,
                    'sell_price' => $sellPrice,
                    'sell_qty' => $sellQty,
                    'grid_level' => $gridLevel,
                    'symbol' => $symbol
                ]
            );
        } catch (Exception $e) {
            throw new Exception("Erro ao processar compra preenchida: " . $e->getMessage());
        }
    }

    /**
     * Retorna todas as ordens de COMPRA executadas que ainda não têm venda pareada
     */
    private function getPendingSellOrdersForGrid(int $gridId): array
    {
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "grids_id = '{$gridId}'"
            ]);
            $gridsOrdersModel->load_data(); // CRITICAL: load_data() ANTES de join()
            $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);

            $pendingSells = [];

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders_attach'][0] ?? null;

                if (!$order) {
                    continue;
                }

                // VERIFICAR SE É UMA COMPRA EXECUTADA
                $isBuyOrder = $order['side'] === 'BUY';
                $isExecuted = $order['status'] === 'FILLED';

                if (!$isBuyOrder || !$isExecuted) {
                    continue;
                }

                // VERIFICAR SE JÁ EXISTE VENDA PAREADA ATIVA
                $hasSellOrder = $this->hasPairedSellOrder($gridOrder['idx']);

                if (!$hasSellOrder) {
                    // Esta compra NÃO tem venda → adicionar à lista
                    $pendingSells[] = [
                        'symbol' => $order['symbol'],
                        'price' => $order['price'],
                        'executed_qty' => $order['executed_qty'],
                        'grid_level' => $gridOrder['grid_level'],
                        'grids_orders_idx' => $gridOrder['idx'],
                        'order_idx' => $order['idx']
                    ];
                }
            }

            $this->log(
                "🔍 Compras pendentes de venda no grid $gridId: " . count($pendingSells),
                'INFO',
                'TRADE'
            );

            return $pendingSells;
        } catch (Exception $e) {
            $this->log("Erro ao buscar compras pendentes: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return [];
        }
    }

    /**
     * Verifica se já existe uma ordem BUY ativa em faixa de preço próxima
     * Previne criação de ordens duplicadas quando múltiplas SELLs executam simultaneamente
     * 
     * @param int $gridId ID do grid
     * @param float $targetPrice Preço da nova BUY que seria criada
     * @param float $tolerance Tolerância percentual (default: 0.005 = 0.5%)
     * @return bool true se já existe BUY ativa próxima ao preço alvo
     */
    private function hasActiveBuyOrderNearPrice(int $gridId, float $targetPrice, float $tolerance = 0.005): bool
    {
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "grids_id = '{$gridId}'",
                "active = 'yes'"
            ]);
            $gridsOrdersModel->load_data();
            $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders_attach'][0] ?? null;

                if ($order && $order['side'] === 'BUY') {
                    // Verificar se a BUY está ativa (aguardando execução)
                    if (in_array($order['status'], ['NEW', 'PARTIALLY_FILLED'])) {
                        $existingPrice = (float)$order['price'];
                        $priceDiffPercent = abs($existingPrice - $targetPrice) / $existingPrice;

                        // Se diferença < tolerância (0.5%), considera duplicação
                        if ($priceDiffPercent < $tolerance) {
                            $this->log(
                                "🔍 Duplicação detectada: BUY existente @ \${$existingPrice} vs nova @ \${$targetPrice} " .
                                    "(diferença: " . number_format($priceDiffPercent * 100, 2) . "%)",
                                'INFO',
                                'TRADE'
                            );
                            return true; // BUY muito próxima encontrada
                        }
                    }
                }
            }

            return false;
        } catch (Exception $e) {
            $this->log("Erro ao verificar BUY ativa próxima ao preço $targetPrice: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return false;
        }
    }

    /**
     * Verifica se já existe uma ordem ativa (NEW/PARTIALLY_FILLED) no slot canônico
     * $slot para o lado $side. Substitui hasActiveBuyOrderNearPrice: usa a grade
     * geométrica em vez de tolerância percentual — imune a falsos positivos por
     * arredondamento de float ou diferenças menores que 1 tickSize.
     *
     * @param int    $gridId      ID do grid
     * @param string $side        'BUY' ou 'SELL'
     * @param int    $slot        Slot canônico calculado via normalizeToGrid
     * @param float  $centerPrice Preço central do grid
     * @param float  $gridSpacing Espaçamento percentual (ex. 0.01)
     * @return bool true se já existe ordem ativa nesse slot
     */
    private function hasActiveOrderAtSlot(int $gridId, string $side, int $slot, float $centerPrice, float $gridSpacing): bool
    {
        if ($centerPrice <= 0 || $gridSpacing <= 0) {
            return false; // sem dados suficientes para calcular slot — não bloqueia
        }
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "grids_id = '{$gridId}'",
                "active = 'yes'"
            ]);
            $gridsOrdersModel->load_data();
            $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);

            $logBase = log(1 + $gridSpacing);

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders_attach'][0] ?? null;
                if (!$order || $order['side'] !== $side) {
                    continue;
                }
                if (!in_array($order['status'], ['NEW', 'PARTIALLY_FILLED'])) {
                    continue;
                }
                $existingPrice = (float)$order['price'];
                $existingSlot  = (int)round(log($existingPrice / $centerPrice) / $logBase);
                if ($existingSlot === $slot) {
                    $this->log(
                        "🔍 Duplicação (slot {$slot}): {$side} existente @ \${$existingPrice} — nova ordem bloqueada",
                        'INFO',
                        'TRADE'
                    );
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            $this->log("Erro ao verificar slot $slot ({$side}): " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return false;
        }
    }

    /**
     * Verifica se uma ordem de compra já tem uma venda pareada ativa
     */
    private function hasPairedSellOrder(int $buyGridOrderIdx): bool
    {
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "paired_order_id = '{$buyGridOrderIdx}'"
            ]);
            // CRITICAL: load_data() ANTES de join()
            $gridsOrdersModel->load_data();
            $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);

            // Se encontrou alguma venda pareada ATIVA ou PENDENTE
            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders_attach'][0] ?? null;

                if ($order && $order['side'] === 'SELL') {
                    // Verificar se a venda está ativa (não cancelada)
                    // IMPORTANTE: Se status=FILLED mas is_processed=no, o BTC já foi vendido
                    // mas o sistema ainda não processou (não criou nova BUY)
                    if (in_array($order['status'], ['NEW', 'PARTIALLY_FILLED'])) {
                        return true; // SELL ativa aguardando execução
                    }

                    // Se FILLED e is_processed=yes, foi processada corretamente
                    if ($order['status'] === 'FILLED' && $gridOrder['is_processed'] === 'yes') {
                        return true; // SELL executada e processada (nova BUY já foi criada)
                    }

                    // Se FILLED mas is_processed=no, o BTC foi vendido mas não foi processado ainda
                    // Será processado no próximo ciclo (handleSellOrderFilled)
                    if ($order['status'] === 'FILLED' && $gridOrder['is_processed'] === 'no') {
                        return true; // BTC não está órfão (foi vendido, aguardando processamento)
                    }
                }
            }

            return false;
        } catch (Exception $e) {
            $this->log("Erro ao verificar venda pareada: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return false;
        }
    }

    /**
     * Retorna informações completas da SELL pareada (se existir)
     * 
     * @param int $buyGridOrderIdx ID do grids_orders da BUY
     * @return array|null Dados da ordem SELL pareada ou null se não existir
     */
    private function getPairedSellInfo(int $buyGridOrderIdx): ?array
    {
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "paired_order_id = '{$buyGridOrderIdx}'"
            ]);
            $gridsOrdersModel->load_data();
            $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);

            // Se houver múltiplas SELLs pareadas (original + recovery), retornar a com maior quantidade
            $bestSell = null;
            $maxQty = 0;

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders_attach'][0] ?? null;

                if ($order && $order['side'] === 'SELL') {
                    $qty = (float)($order['executed_qty'] ?? 0);

                    if ($qty >= $maxQty) {
                        $maxQty = $qty;
                        $bestSell = [
                            'grids_orders_idx' => $gridOrder['idx'],
                            'order_id' => $order['idx'],
                            'status' => $order['status'],
                            'price' => $order['price'],
                            'executed_qty' => $qty,
                            'is_processed' => $gridOrder['is_processed']
                        ];
                    }
                }
            }

            return $bestSell;
        } catch (Exception $e) {
            $this->log("Erro ao buscar info da venda pareada: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return null;
        }
    }

    /**
     * Calcula quanto BTC está alocado em ordens SELL ativas (NEW / PARTIALLY_FILLED)
     * Usado para proteger o BTC das vendas superiores ao processar uma nova compra.
     *
     * @param int    $gridId ID do grid
     * @param string $symbol Par de negociação
     * @return float Quantidade total de BTC ainda comprometida em SELLs abertas
     */
    private function getBtcAllocatedInActiveSells(int $gridId, string $symbol): float
    {
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "grids_id = '{$gridId}'"
            ]);
            // CRITICAL: load_data() ANTES de join()
            $gridsOrdersModel->load_data();
            $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);

            $totalAllocated = 0.0;

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders_attach'][0] ?? null;

                if (!$order) {
                    continue;
                }

                $isSell   = $order['side'] === 'SELL';
                $isActive = in_array($order['status'], ['NEW', 'PARTIALLY_FILLED']);

                if ($isSell && $isActive) {
                    $remainingQty = (float)$order['quantity'];

                    if ($order['status'] === 'PARTIALLY_FILLED') {
                        $remainingQty -= (float)$order['executed_qty'];
                    }

                    $totalAllocated += max(0.0, $remainingQty);
                }
            }

            $this->log(
                "🔍 BTC alocado em SELLs ativas (grid $gridId): " . number_format($totalAllocated, 8),
                'INFO',
                'TRADE'
            );

            return $totalAllocated;
        } catch (Exception $e) {
            $this->log("Erro ao calcular BTC alocado em SELLs: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return 0.0;
        }
    }

    /**
     * LÓGICA "VIOLÃO" REATIVA: Processa ordens executadas em BATCH
     * 
     * Quando múltiplas ordens executam antes da CRON rodar:
     * - 2 SELLs executam → divide USDC disponível por 2 → cria 2 BUYs
     * - 3 BUYs executam → divide BTC disponível por 3 → cria 3 SELLs
     * 
     * Cada ordem reativa é criada 1% acima/abaixo do preço EXATO onde executou
     * 
     * @param int $gridId ID do grid
     * @param array $executedOrders Lista de ordens executadas
     * @param string $symbol Par de negociação
     */
    private function handleFilledOrdersBatch(int $gridId, array $executedOrders, string $symbol): void
    {
        try {
            // Separar ordens por tipo
            $sellOrders = array_filter($executedOrders, fn($o) => $o['side'] === 'SELL');
            $buyOrders = array_filter($executedOrders, fn($o) => $o['side'] === 'BUY');

            $sellCount = count($sellOrders);
            $buyCount = count($buyOrders);

            $this->log(
                "🎸 Modo Violão: $sellCount SELL(s) + $buyCount BUY(s) executadas",
                'INFO',
                'TRADE'
            );

            // ══════════════════════════════════════════════════
            // PARTE 1: PROCESSAR VENDAS (SELL → criar BUYs)
            // ══════════════════════════════════════════════════
            if ($sellCount > 0) {
                $this->log("🔴 Processando $sellCount venda(s)...", 'INFO', 'TRADE');

                // 1. Calcular lucro de cada SELL
                foreach ($sellOrders as $sellOrder) {
                    $this->calculateAndSaveSellProfit($gridId, $sellOrder);
                }

                // 2. Buscar USDC disponível
                $availableUsdc = $this->getAvailableUsdcBalance(true);

                // 3. Dividir capital igualmente entre as SELLs
                $capitalPerBuyRaw = $availableUsdc / $sellCount;

                // 4. Aplicar teto por nível para evitar que uma única BUY consuma caixa em excesso
                $gridData = $this->getGridById($gridId);
                $baseCapitalPerLevel = (float)($gridData['capital_per_level'] ?? 0.0);
                $accumulatedProfit = (float)($gridData['accumulated_profit_usdc'] ?? 0.0);
                $extraCapital = 0.0;
                if ($accumulatedProfit >= self::REINVESTMENT_THRESHOLD) {
                    $extraCapital = $accumulatedProfit;
                    $this->resetAccumulatedProfit($gridId);
                }
                $capitalCapPerBuy = $baseCapitalPerLevel + $extraCapital;
                $capitalPerBuy = min($capitalPerBuyRaw, $capitalCapPerBuy);

                $this->log(
                    "💵 USDC disponível: $" . number_format($availableUsdc, 2) .
                        " | bruto por ordem: $" . number_format($capitalPerBuyRaw, 2) .
                        " | teto por nível: $" . number_format($capitalCapPerBuy, 2) .
                        " | final por ordem: $" . number_format($capitalPerBuy, 2),
                    'INFO',
                    'TRADE'
                );

                // 5. Validar se atinge mínimo viável por ordem
                $minUsdcPerOrder = self::MIN_TRADE_USDC * self::SAFETY_MARGIN;

                if ($capitalPerBuy < $minUsdcPerOrder) {
                    $this->log(
                        "⚠️ Capital insuficiente para $sellCount BUY(s): final $" . number_format($capitalPerBuy, 2) .
                            " por ordem (mínimo viável: $" . number_format($minUsdcPerOrder, 2) . "). Aguardando mais capital.",
                        'WARNING',
                        'TRADE'
                    );

                    // Marcar SELLs como processadas mesmo sem criar BUYs
                    // (evita reprocessamento infinito)
                    foreach ($sellOrders as $sellOrder) {
                        $this->markOrderAsProcessed($sellOrder['grids_orders_idx']);
                    }

                    // Não criar BUYs, mas continuar para processar BUYs se houver
                } else {
                    // 6. Criar uma BUY para cada SELL executada
                    foreach ($sellOrders as $sellOrder) {
                        try {
                            $sellPrice = (float)$sellOrder['price'];
                            $gridSpacing = $this->getGridSpacing($symbol);

                            // Preço da BUY: 1% ABAIXO do preço EXATO onde SELL executou
                            $buyPrice = $sellPrice * (1 - $gridSpacing);

                            // Proteção anti-duplicação: pular se slot canônico já está ocupado por BUY ativa
                            $_gd_b = $this->getGridById($gridId);
                            $_cp_b = (float)($_gd_b['current_price'] ?? 0);
                            $_slot_b = $_cp_b > 0
                                ? (int)round(log($buyPrice / $_cp_b) / log(1 + $gridSpacing))
                                : PHP_INT_MIN;
                            if ($this->hasActiveOrderAtSlot($gridId, 'BUY', $_slot_b, $_cp_b, $gridSpacing)) {
                                $this->log(
                                    "⚠️ BUY reativa nível {$sellOrder['grid_level']} pulada: slot {$_slot_b} já ocupado (proteção anti-duplicação)",
                                    'WARNING',
                                    'TRADE'
                                );
                                $this->markOrderAsProcessed($sellOrder['grids_orders_idx']);
                                continue;
                            }

                            $this->log(
                                "🎸 Criando BUY reativa: SELL @ $" . number_format($sellPrice, 2) .
                                    " → BUY @ $" . number_format($buyPrice, 2) .
                                    " (capital: $" . number_format($capitalPerBuy, 2) . ")",
                                'INFO',
                                'TRADE'
                            );

                            $newBuyOrderId = $this->placeBuyOrder(
                                $gridId,
                                $symbol,
                                $sellOrder['grid_level'], // reutiliza nível da SELL
                                $buyPrice,
                                $capitalPerBuy
                            );

                            if ($newBuyOrderId) {
                                $this->log(
                                    "✅ BUY criada: nível {$sellOrder['grid_level']} @ $" . number_format($buyPrice, 2),
                                    'SUCCESS',
                                    'TRADE'
                                );
                            }

                            // Marcar SELL como processada
                            $this->markOrderAsProcessed($sellOrder['grids_orders_idx']);
                        } catch (Exception $e) {
                            $this->log(
                                "❌ Erro ao criar BUY reativa para SELL #{$sellOrder['idx']}: " . $e->getMessage(),
                                'ERROR',
                                'TRADE'
                            );

                            // Não marcar como processada → retenta na próxima CRON
                        }
                    }
                }
            }

            // ══════════════════════════════════════════════════
            // PARTE 2: PROCESSAR COMPRAS (BUY → criar SELLs)
            // ══════════════════════════════════════════════════
            if ($buyCount > 0) {
                $this->log("🟢 Processando $buyCount compra(s)...", 'INFO', 'TRADE');

                // 1. Buscar BTC disponível
                $baseAsset = str_replace('USDC', '', $symbol);
                $accountInfo = $this->getAccountInfo(true);
                $btcFree = $this->getBalanceForAsset($accountInfo['balances'], $baseAsset);

                // 2. Dividir BTC igualmente entre as BUYs
                $btcPerSell = $btcFree / $buyCount;

                $this->log(
                    "₿ BTC disponível: " . number_format($btcFree, 5) .
                        " → " . number_format($btcPerSell, 5) . " por ordem",
                    'INFO',
                    'TRADE'
                );

                // 3. Validar se atinge quantidade mínima
                $symbolData = $this->getExchangeInfo($symbol);
            list($stepSize, $tickSize, $minNotional, $pps) = $this->extractFilters($symbolData);
                $minQty = (float)($symbolData['filters'][array_search('LOT_SIZE', array_column($symbolData['filters'], 'filterType'))]['minQty'] ?? 0.00001);

                if ($btcPerSell < $minQty) {
                    $this->log(
                        "⚠️ BTC insuficiente para $buyCount SELL(s): " . number_format($btcPerSell, 5) .
                            " por ordem (mínimo: " . number_format($minQty, 5) . "). Aguardando mais BTC.",
                        'WARNING',
                        'TRADE'
                    );

                    // Marcar BUYs como processadas mesmo sem criar SELLs
                    foreach ($buyOrders as $buyOrder) {
                        $this->markOrderAsProcessed($buyOrder['grids_orders_idx']);
                    }
                } else {
                    // 4. Criar uma SELL para cada BUY executada
                    foreach ($buyOrders as $buyOrder) {
                        try {
                            $buyPrice = (float)$buyOrder['price'];
                            $gridSpacing = $this->getGridSpacing($symbol);

                            // Preço da SELL: 1% ACIMA do preço EXATO onde BUY executou
                            $sellPrice = $buyPrice * (1 + $gridSpacing);

                            // Guard: notional mínimo (qty × price >= minNotional da Binance)
                            $notionalValue = $btcPerSell * $sellPrice;
                            if ($minNotional !== null && $notionalValue < (float)$minNotional) {
                                $this->log(
                                    "⚠️ SELL reativa nível {$buyOrder['grid_level']} ignorada: notional $" .
                                        number_format($notionalValue, 4) . " abaixo do mínimo $" .
                                        number_format((float)$minNotional, 2) .
                                        " (qty=" . number_format($btcPerSell, 8) .
                                        " × price=$" . number_format($sellPrice, 2) . ")",
                                    'WARNING',
                                    'TRADE'
                                );
                                $this->markOrderAsProcessed($buyOrder['grids_orders_idx']);
                                continue;
                            }

                            $this->log(
                                "🎸 Criando SELL reativa: BUY @ $" . number_format($buyPrice, 2) .
                                    " → SELL @ $" . number_format($sellPrice, 2) .
                                    " (qty: " . number_format($btcPerSell, 5) . " BTC)",
                                'INFO',
                                'TRADE'
                            );

                            // Criar ordem SELL
                            $newSellOrderId = $this->placeSellOrder(
                                $gridId,
                                $symbol,
                                $buyOrder['grid_level'], // reutiliza nível da BUY
                                $sellPrice,
                                $btcPerSell,
                                $buyOrder['grids_orders_idx'] // paired_order_id
                            );

                            if ($newSellOrderId) {
                                $this->log(
                                    "✅ SELL criada: nível {$buyOrder['grid_level']} @ $" . number_format($sellPrice, 2),
                                    'SUCCESS',
                                    'TRADE'
                                );
                            }

                            // Marcar BUY como processada
                            $this->markOrderAsProcessed($buyOrder['grids_orders_idx']);
                        } catch (Exception $e) {
                            $this->log(
                                "❌ Erro ao criar SELL reativa para BUY #{$buyOrder['idx']}: " . $e->getMessage(),
                                'ERROR',
                                'TRADE'
                            );

                            // Não marcar como processada → retenta na próxima CRON
                        }
                    }
                }
            }

            $this->log("🎸 Modo Violão finalizado", 'INFO', 'TRADE');
        } catch (Exception $e) {
            $this->log(
                "❌ Erro no processamento BATCH: " . $e->getMessage(),
                'ERROR',
                'TRADE'
            );
            throw $e;
        }
    }

    /**
     * Calcula o lucro líquido de um par BUY→SELL descontando taxas de negociação.
     *
     * @param float $executedQty Quantidade do ativo negociada
     * @param float $buyPrice    Preço de compra (ou custo do ativo)
     * @param float $sellPrice   Preço de venda
     * @return float             Lucro líquido em USDC
     */
    private function calculatePairProfit(float $executedQty, float $buyPrice, float $sellPrice, ?array $buyOrder = null, ?array $sellOrder = null): float
    {
        $buyValue  = (float)($buyOrder['cumulative_quote_qty'] ?? ($executedQty * $buyPrice));
        $sellValue = (float)($sellOrder['cumulative_quote_qty'] ?? ($executedQty * $sellPrice));
        $buyFee    = (float)($buyOrder['commission_usdc_equivalent'] ?? $buyValue * self::FEE_PERCENT);
        $sellFee   = (float)($sellOrder['commission_usdc_equivalent'] ?? $sellValue * self::FEE_PERCENT);
        return $sellValue - $buyValue - $buyFee - $sellFee;
    }

    /**
     * Calcula e salva o lucro de uma ordem SELL executada
     * (Extraído para reutilização no modo BATCH)
     */
    private function calculateAndSaveSellProfit(int $gridId, array $sellOrder): void
    {
        try {
            $symbol = $sellOrder['symbol'];
            $sellPrice = (float)$sellOrder['price'];
            $executedQty = (float)$sellOrder['executed_qty'];

            $profit = 0.0;

            // CASO 0: SELL de nível deslizante — usa original_cost_price como custo do BTC reciclado
            $isSlidingLevel = (int)($sellOrder['is_sliding_level'] ?? 0) === 1;
            $slidingCostPrice = (float)($sellOrder['original_cost_price'] ?? 0);

            if ($isSlidingLevel && $slidingCostPrice > 0) {
                $profit = $this->calculatePairProfit($executedQty, $slidingCostPrice, $sellPrice, null, $sellOrder);

                $this->updateOrderProfit($sellOrder['grids_orders_idx'], $profit);
                $this->incrementGridProfit($gridId, $profit);

                $profitLabel = $profit >= 0 ? 'Lucro' : 'Prejuízo';
                $profitColor = $profit >= 0 ? 'SUCCESS' : 'WARNING';

                $this->log(
                    "SELL SLIDING em $symbol: $profitLabel = $" . number_format(abs($profit), 4) .
                        " | Custo BTC reciclado: \$$slidingCostPrice × $executedQty BTC | Venda: \$$sellPrice",
                    $profitColor,
                    'TRADE'
                );
                return;
            }

            // Buscar ordem de compra pareada
            $buyOrder = null;
            if ($sellOrder['paired_order_id']) {
                $gridsOrdersModel = new grids_orders_model();
                $gridsOrdersModel->set_filter(["idx='" . $sellOrder['paired_order_id'] . "'"]);
                $gridsOrdersModel->load_data();
                $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);
                if (!empty($gridsOrdersModel->data)) {
                    $buyOrderData = $gridsOrdersModel->data[0];
                    if (!empty($buyOrderData['orders_attach'])) {
                        $buyOrder = $buyOrderData['orders_attach'][0];
                    }
                }
            }

            $gridData = $this->getGridById($gridId);

            if ($buyOrder) {
                // CASO 1: SELL reativa — TEM ordem de compra pareada
                $buyPrice = (float)$buyOrder['price'];
                $profit   = $this->calculatePairProfit($executedQty, $buyPrice, $sellPrice, $buyOrder, $sellOrder);

                $this->updateOrderProfit($sellOrder['grids_orders_idx'], $profit);
                $this->incrementGridProfit($gridId, $profit);

                $profitLabel = $profit >= 0 ? 'Lucro' : 'Prejuízo';
                $profitColor = $profit >= 0 ? 'SUCCESS' : 'WARNING';

                $this->log(
                    "PAR COMPLETO em $symbol: $profitLabel = $" . number_format(abs($profit), 4) .
                        " | Compra: \$$buyPrice × $executedQty BTC | Venda: \$$sellPrice",
                    $profitColor,
                    'TRADE'
                );
            } else {
                // CASO 2: SELL inicial do grid híbrido — SEM ordem de compra pareada
                $btcCostPrice = (float)($gridData['current_price'] ?? 0);

                if ($btcCostPrice > 0) {
                    $profit = $this->calculatePairProfit($executedQty, $btcCostPrice, $sellPrice, null, $sellOrder);

                    $this->updateOrderProfit($sellOrder['grids_orders_idx'], $profit);
                    $this->incrementGridProfit($gridId, $profit);

                    $this->log(
                        "SELL HÍBRIDO em $symbol: Lucro = $" . number_format($profit, 4) .
                            " (Custo BTC: \$$btcCostPrice | Venda: \$$sellPrice)",
                        'SUCCESS',
                        'TRADE'
                    );
                } else {
                    $this->log("SELL HÍBRIDO em $symbol: current_price não disponível para calcular lucro", 'WARNING', 'TRADE');
                }
            }
        } catch (Exception $e) {
            $this->log(
                "Erro ao calcular lucro da SELL #{$sellOrder['idx']}: " . $e->getMessage(),
                'ERROR',
                'TRADE'
            );
        }
    }

    /**
     * Processa a execução de uma ordem de venda
     * Calcula o lucro e recria a ordem de compra no mesmo nível
     */
    private function handleSellOrderFilled(int $gridId, array $sellOrder): void
    {
        try {
            $symbol = $sellOrder['symbol'];
            $sellPrice = (float)$sellOrder['price'];
            $executedQty = (float)$sellOrder['executed_qty'];

            // Buscar ordem de compra pareada
            $buyOrder = null;
            if ($sellOrder['paired_order_id']) {
                $gridsOrdersModel = new grids_orders_model();
                $gridsOrdersModel->set_filter(["idx='" . $sellOrder['paired_order_id'] . "'"]);
                $gridsOrdersModel->load_data(); // CRITICAL: load_data() ANTES de join()
                $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);
                if (!empty($gridsOrdersModel->data)) {
                    $buyOrderData = $gridsOrdersModel->data[0];
                    if (!empty($buyOrderData['orders_attach'])) {
                        $buyOrder = $buyOrderData['orders_attach'][0];
                    }
                }
            }

            $profit   = 0.0;
            $gridData = $this->getGridById($gridId); // usado tanto no cálculo de lucro quanto na nova ordem

            if ($buyOrder) {
                // CASO 1: SELL reativa — TEM ordem de compra pareada
                $buyPrice = (float)$buyOrder['price'];

                // Calcular lucro (desconta fee de 0.1% em cada lado)
                $profit = $this->calculatePairProfit($executedQty, $buyPrice, $sellPrice);

                // Salvar lucro na ordem de venda
                $this->updateOrderProfit($sellOrder['grids_orders_idx'], $profit);

                // Atualizar lucro acumulado do grid
                $this->incrementGridProfit($gridId, $profit);

                $profitLabel = $profit >= 0 ? 'Lucro' : 'Prejuízo';
                $profitColor = $profit >= 0 ? 'SUCCESS' : 'WARNING';

                $this->log(
                    "PAR COMPLETO em $symbol: $profitLabel = $" . number_format(abs($profit), 4) . " | Compra: \$$buyPrice × $executedQty BTC | Venda: \$$sellPrice",
                    $profitColor,
                    'TRADE'
                );

                $this->saveGridLog(
                    $gridId,
                    'sell_order_filled',
                    'success',
                    "Par completo com lucro",
                    [
                        'buy_price'  => $buyPrice,
                        'sell_price' => $sellPrice,
                        'quantity'   => $executedQty,
                        'profit'     => $profit
                    ]
                );
            } else {
                // CASO 2: SELL inicial do grid híbrido — SEM ordem de compra pareada
                // Usa o center_price do grid como custo de aquisição do BTC
                $btcCostPrice = (float)($gridData['current_price'] ?? 0);

                if ($btcCostPrice > 0) {
                    $profit = $this->calculatePairProfit($executedQty, $btcCostPrice, $sellPrice);

                    // Salvar lucro na ordem de venda
                    $this->updateOrderProfit($sellOrder['grids_orders_idx'], $profit);

                    // Atualizar lucro acumulado do grid
                    $this->incrementGridProfit($gridId, $profit);

                    $this->log(
                        "SELL HÍBRIDO em $symbol: Lucro = $" . number_format($profit, 4) . " (Custo BTC: \$$btcCostPrice | Venda: \$$sellPrice)",
                        'SUCCESS',
                        'TRADE'
                    );

                    $this->saveGridLog(
                        $gridId,
                        'sell_order_filled_hybrid',
                        'success',
                        "Sell inicial híbrido executado",
                        [
                            'btc_cost_price' => $btcCostPrice,
                            'sell_price'     => $sellPrice,
                            'quantity'       => $executedQty,
                            'profit'         => $profit
                        ]
                    );
                } else {
                    $this->log(
                        "⚠️ Não foi possível calcular lucro da SELL inicial: center_price não encontrado no grid $gridId",
                        'WARNING',
                        'TRADE'
                    );
                }
            }

            // Recriar ordem de COMPRA no mesmo nível
            $gridSpacing = $this->getGridSpacing($symbol);
            $buyPrice = $sellPrice * (1 - $gridSpacing);

            // Calcular capital com fallback flexível (já valida USDC disponível internamente)
            $capitalForBuy = $this->getCapitalForNewBuyOrder($gridId, $gridData);

            // Se retornou 0, USDC é insuficiente até para fallback — aguardar próximo ciclo
            if ($capitalForBuy <= 0) {
                // Log já emitido dentro de getCapitalForNewBuyOrder
                return;
            }

            // ══════ DUPLICATE ORDER PREVENTION ══════
            // Verificação por slot canônico — imune a desvios de float/tickSize
            $_cp_s = (float)($gridData['current_price'] ?? 0);
            $_slot_s = $_cp_s > 0
                ? (int)round(log($buyPrice / $_cp_s) / log(1 + $gridSpacing))
                : PHP_INT_MIN;
            if ($this->hasActiveOrderAtSlot($gridId, 'BUY', $_slot_s, $_cp_s, $gridSpacing)) {
                $this->log(
                    "⚠️ Nova BUY pós-SELL nível {$sellOrder['grid_level']} pulada: slot {$_slot_s} já ocupado (proteção anti-duplicação)",
                    'WARNING',
                    'TRADE'
                );
                return;
            }

            // ══════ FEE THRESHOLD VALIDATION ══════
            // Validar se a nova BUY será lucrativa antes de criar
            if (!$this->isTradeViable($capitalForBuy, $symbol)) {
                $this->log(
                    "⚠️ Nova BUY pós-SELL nível {$sellOrder['grid_level']} rejeitada: lucro esperado abaixo do mínimo " .
                        "(capital: \$" . number_format($capitalForBuy, 4) . ")",
                    'WARNING',
                    'TRADE'
                );
            } else {
                $newBuyOrderId = $this->placeBuyOrder(
                    $gridId,
                    $symbol,
                    $sellOrder['grid_level'],
                    $buyPrice,
                    $capitalForBuy
                );

                if ($newBuyOrderId) {
                    $this->log("Nova ordem de compra criada para nível {$sellOrder['grid_level']}", 'INFO', 'TRADE');
                }
            }
        } catch (Exception $e) {
            throw new Exception("Erro ao processar venda preenchida: " . $e->getMessage());
        }
    }

    /**
     * Verifica se o preço saiu do range do grid e rebalanceamento é necessário
     */
    private function checkRebalanceNeeded(array $gridData, float $currentPrice): bool
    {
        $gridMin = (float)$gridData['lower_price'];
        $gridMax = (float)$gridData['upper_price'];

        if ($currentPrice < $gridMin) {
            $deviation = ($gridMin - $currentPrice) / $gridMin;
            return $deviation > self::REBALANCE_THRESHOLD;
        }

        if ($currentPrice > $gridMax) {
            $deviation = ($currentPrice - $gridMax) / $gridMax;
            return $deviation > self::REBALANCE_THRESHOLD;
        }

        return false;
    }

    /**
     * Rebalanceia o grid: cancela ordens, vende ativos e cria novo grid
     * @deprecated Substituído por slideGrid(). Mantido para rollback de emergência.
     */
    private function rebalanceGridLegacy(int $gridId, string $symbol, float $newCenterPrice): void
    {
        try {
            $this->log("REBALANCEAMENTO iniciado para $symbol (novo centro: $newCenterPrice)", 'WARNING', 'TRADE');

            // 1. CANCELAR TODAS ORDENS ABERTAS DO GRID (rastreadas no banco)
            $this->cancelAllGridOrders($gridId);

            // 1.5 CANCELAR TODAS ORDENS RESTANTES NA BINANCE
            // Garante que ordens órfãs (não rastreadas) também sejam canceladas,
            // liberando qualquer BTC bloqueado antes de consultar o saldo.
            try {
                $this->client->deleteOpenOrders($symbol, self::BINANCE_RECV_WINDOW);
                $this->log("Todas as ordens abertas em $symbol canceladas na Binance", 'INFO', 'TRADE');
            } catch (Exception $e) {
                $this->log("Aviso ao cancelar ordens restantes na Binance: " . $e->getMessage(), 'WARNING', 'TRADE');
            }

            // Aguarda a Binance processar os cancelamentos e liberar o BTC bloqueado
            sleep(2);

            // 2. BUSCAR SALDO REAL E LIVRE NA BINANCE APÓS CANCELAMENTOS
            // NÃO usa tracking interno — consulta diretamente a API com force-refresh
            // para garantir que o BTC liberado dos cancelamentos está incluído.
            $baseAsset = str_replace('USDC', '', $symbol);
            $accountInfo = $this->getAccountInfo(true);
            $freeBtc = $this->getBalanceForAsset($accountInfo['balances'], $baseAsset);

            $this->log(
                "💰 Saldo real $baseAsset disponível para venda (Binance): " . number_format($freeBtc, 8),
                'INFO',
                'TRADE'
            );

            if ($freeBtc > 0) {
                $this->sellAssetAtMarket($symbol, $freeBtc, $gridId);
            }

            // 3. MARCAR GRID COMO REBALANCEADO
            $this->updateGridStatus($gridId, 'rebalanced');

            // 4. CRIAR NOVO GRID COM NOVO PREÇO CENTRAL
            $this->createNewGrid($symbol);

            $this->log("REBALANCEAMENTO concluído para $symbol", 'SUCCESS', 'TRADE');

            $this->saveGridLog(
                $gridId,
                'rebalance_completed',
                'success',
                "Grid rebalanceado com sucesso",
                [
                    'old_center_price' => (float)$this->getGridById($gridId)['current_price'],
                    'new_center_price' => $newCenterPrice
                ]
            );
        } catch (Exception $e) {
            $this->log("Erro ao rebalancear grid: " . $e->getMessage(), 'ERROR', 'TRADE');
            $this->saveGridLog(
                $gridId,
                'rebalance_error',
                'error',
                "Erro ao rebalancear: " . $e->getMessage()
            );
        }
    }

    /**
     * Sliding Grid: desloca o grid quando o preço sai além do range estrutural
     * pelo desvio definido em REBALANCE_THRESHOLD.
     * Slide DOWN (SELL→SELL): cancela SELL mais distante (maior preço) → cria nova SELL 1% abaixo da mais próxima.
     * Slide UP   (BUY→BUY):  cancela BUY  mais distante (menor preço) → cria nova BUY  1% acima da mais próxima.
     */
    private function slideGrid(int $gridId, string $symbol, float $currentPrice, array $gridData): void
    {
        try {
            // Carregar todas as ordens ativas do grid
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "grids_id = '{$gridId}'"
            ]);
            $gridsOrdersModel->load_data();
            $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);

            $activeBuyOrders  = [];
            $activeSellOrders = [];

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders_attach'][0] ?? null;
                if (!$order || !in_array($order['status'], ['NEW', 'PARTIALLY_FILLED'])) {
                    continue;
                }
                $entry = [
                    'grids_orders_idx'    => $gridOrder['idx'],
                    'orders_idx'          => $order['idx'],
                    'binance_order_id'    => $order['binance_order_id'],
                    'price'               => (float)$order['price'],
                    'quantity'            => (float)($order['quantity'] ?? 0),
                    'executed_qty'        => (float)($order['executed_qty'] ?? 0),
                    'status'              => $order['status'],
                    'grid_level'          => $gridOrder['grid_level'],
                    'is_sliding_level'    => (int)($gridOrder['is_sliding_level'] ?? 0),
                    'original_cost_price' => (float)($gridOrder['original_cost_price'] ?? 0),
                ];
                if ($order['side'] === 'BUY')  $activeBuyOrders[]  = $entry;
                if ($order['side'] === 'SELL') $activeSellOrders[] = $entry;
            }

            // Sem ordens ativas em nenhum lado — nada a processar
            if (empty($activeSellOrders) && empty($activeBuyOrders)) {
                return;
            }

            // ── GATE: só deslizar se o preço saiu do range do grid além do limiar ──
            // O slide é uma operação extrema — a lógica reativa (BUY→SELL, SELL→BUY)
            // cuida de oscilações dentro do range. O slide só deve atuar quando o preço
            // se afastou mais que REBALANCE_THRESHOLD do range estrutural atual do grid
            // definido por lower_price / upper_price.
            $gridMin = (float)($gridData['lower_price'] ?? 0);
            $gridMax = (float)($gridData['upper_price'] ?? 0);

            if ($gridMin > 0 && $gridMax > 0) {
                $belowRange = false;
                $aboveRange = false;

                if ($currentPrice < $gridMin) {
                    $deviation = ($gridMin - $currentPrice) / $gridMin;
                    $belowRange = $deviation > self::REBALANCE_THRESHOLD;
                }
                if ($currentPrice > $gridMax) {
                    $deviation = ($currentPrice - $gridMax) / $gridMax;
                    $aboveRange = $deviation > self::REBALANCE_THRESHOLD;
                }

                if (!$belowRange && !$aboveRange) {
                    // Preço ainda dentro do range ou fora dele abaixo do limiar — não deslizar
                    return;
                }
            }

            // ── Preparar dados comuns para slides ──────────────────────────────
            $gridSpacing = $this->getGridSpacing($symbol);
            $symbolData  = $this->getExchangeInfo($symbol);
                list($stepSize, $tickSize, $minNotional, $pps) = $this->extractFilters($symbolData);
            $filters = array_column($symbolData['filters'], null, 'filterType');
            $minQty  = isset($filters['LOT_SIZE']['minQty']) ? (float)$filters['LOT_SIZE']['minQty'] : 0.00001;

            // ── 6.2  Slide para BAIXO (SELL → SELL) ──────────────────────────────
            // Preço caiu: SELLs ficaram distantes demais.
            // Cancela a SELL mais distante (maior preço), libera o BTC travado,
            // e cria nova SELL 1% abaixo da SELL mais próxima (menor preço).
            // Funciona com ou sem BUYs ativas.
            if (!empty($activeSellOrders)) {
                $lowestSellPrice = min(array_column($activeSellOrders, 'price'));

                if ($currentPrice < $lowestSellPrice) {
                    $iteration = 0;
                    while ($currentPrice < $lowestSellPrice && $iteration < self::GRID_SLIDE_MAX_ITERATIONS) {
                        $iteration++;

                        if (count($activeSellOrders) < 2) {
                            $this->log("⚠️ Slide DOWN: precisa de pelo menos 2 SELLs para reciclar (grid #$gridId)", 'WARNING', 'TRADE');
                            break;
                        }

                        // Passo 1: SELL mais distante (maior preço) a cancelar
                        usort($activeSellOrders, fn($a, $b) => $b['price'] <=> $a['price']);
                        $sellToCancel = $activeSellOrders[0];

                        // Passo 2: cancelar na Binance
                        try {
                            $this->client->deleteOrder($symbol, $sellToCancel['binance_order_id'], null, null, null, self::BINANCE_RECV_WINDOW);
                        } catch (Exception $e) {
                            $this->log("⚠️ Slide DOWN: erro ao cancelar SELL #{$sellToCancel['binance_order_id']}: " . $e->getMessage() . " — sincronizando", 'WARNING', 'TRADE');
                            $this->syncOrdersWithBinance($gridId);
                            break;
                        }

                        // Passo 3: marcar como cancelada no banco
                        $ordersModel = new orders_model();
                        $ordersModel->set_filter(["idx = '{$sellToCancel['orders_idx']}'"]);
                        $ordersModel->populate(['status' => 'CANCELED']);
                        $ordersModel->save();

                        $goCancelModel = new grids_orders_model();
                        $goCancelModel->set_filter(["idx = '{$sellToCancel['grids_orders_idx']}'"]);
                        $goCancelModel->populate(['active' => 'no']);
                        $goCancelModel->save();

                        // Passo 4: BTC liberado + custo original preservado
                        $btcQty            = $sellToCancel['quantity'];
                        $originalCostPrice = $sellToCancel['original_cost_price'] > 0
                            ? $sellToCancel['original_cost_price']
                            : $sellToCancel['price'];

                        // Ajustar ao stepSize
                        $stepSizeFloat    = (float)$stepSize;
                        $decimalPlacesQty = $this->getDecimalPlaces($stepSize);
                        $btcQty = floor($btcQty / $stepSizeFloat) * $stepSizeFloat;
                        $btcQty = round($btcQty, $decimalPlacesQty);

                        if ($btcQty < $minQty) {
                            $this->log("⚠️ Slide DOWN: qty $btcQty < mínimo $minQty (grid #$gridId)", 'WARNING', 'TRADE');
                            break;
                        }

                        // Remover SELL cancelada do array local
                        $activeSellOrders = array_values(array_filter(
                            $activeSellOrders,
                            fn($o) => $o['grids_orders_idx'] !== $sellToCancel['grids_orders_idx']
                        ));

                        if (empty($activeSellOrders)) {
                            $this->log("⚠️ Slide DOWN: sem SELLs restantes após cancelamento (grid #$gridId)", 'WARNING', 'TRADE');
                            break;
                        }

                        // Passo 5: novo preço = menor SELL ativa × (1 - spacing)
                        $lowestSellPrice = min(array_column($activeSellOrders, 'price'));
                        $newSellPrice    = (float)$this->adjustPriceToTickSize($lowestSellPrice * (1 - $gridSpacing), $tickSize);

                        if ($minNotional && ($newSellPrice * $btcQty) < $minNotional) {
                            $this->log("⚠️ Slide DOWN: valor abaixo do mínimo notional (grid #$gridId)", 'WARNING', 'TRADE');
                            break;
                        }

                        // Passo 6: criar nova SELL abaixo, com BTC reciclado
                        $this->log(
                            "⬇️ Slide DOWN #$iteration: reciclando SELL @ \${$sellToCancel['price']} → nova SELL @ \$$newSellPrice",
                            'INFO',
                            'TRADE'
                        );
                        $newSellId = $this->placeSellOrder(
                            $gridId,
                            $symbol,
                            $sellToCancel['grid_level'],
                            $newSellPrice,
                            $btcQty,
                            null,   // sem paired buy (reciclagem)
                            true,   // isSlidingLevel
                            $originalCostPrice
                        );

                        if (!$newSellId) {
                            $this->log("❌ Slide DOWN: falha ao criar SELL @ \$$newSellPrice (grid #$gridId)", 'ERROR', 'TRADE');
                            break;
                        }

                        // Passo 7-8: contadores e log
                        $this->incrementSlideCount($gridId, 'down');
                        $this->saveGridLog($gridId, 'grid_slide_down', 'success', 'Grid deslizou para baixo (SELL→SELL)', [
                            'current_price'        => $currentPrice,
                            'cancelled_sell_price' => $sellToCancel['price'],
                            'new_sell_price'       => $newSellPrice,
                            'recycled_quantity'    => $btcQty,
                            'original_cost_price'  => $originalCostPrice,
                            'iteration'            => $iteration,
                        ]);

                        // Atualizar estado local para próxima iteração
                        $lowestSellPrice    = $newSellPrice;
                        $activeSellOrders[] = [
                            'price'               => $newSellPrice,
                            'grids_orders_idx'    => $newSellId,
                            'is_sliding_level'    => 1,
                            'original_cost_price' => $originalCostPrice,
                            'quantity'            => $btcQty,
                            'binance_order_id'    => null,
                        ];
                    }
                }
            }

            // ── 6.3  Slide para CIMA (BUY → BUY) ────────────────────────────────
            // Preço subiu: BUYs ficaram distantes demais.
            // Cancela a BUY mais distante (menor preço), libera o USDC travado,
            // e cria nova BUY 1% acima da BUY mais próxima (maior preço).
            // Funciona com ou sem SELLs ativas.
            if (!empty($activeBuyOrders)) {
                $highestBuyPrice = max(array_column($activeBuyOrders, 'price'));

                if ($currentPrice > $highestBuyPrice) {
                    $iteration = 0;
                    while ($currentPrice > $highestBuyPrice && $iteration < self::GRID_SLIDE_MAX_ITERATIONS) {
                        $iteration++;

                        if (count($activeBuyOrders) < 2) {
                            $this->log("⚠️ Slide UP: precisa de pelo menos 2 BUYs para reciclar (grid #$gridId)", 'WARNING', 'TRADE');
                            break;
                        }

                        // Passo 1: BUY mais distante (menor preço) a cancelar
                        usort($activeBuyOrders, fn($a, $b) => $a['price'] <=> $b['price']);
                        $buyToCancel = $activeBuyOrders[0];

                        // Passo 2: cancelar na Binance
                        try {
                            $this->client->deleteOrder($symbol, $buyToCancel['binance_order_id'], null, null, null, self::BINANCE_RECV_WINDOW);
                        } catch (Exception $e) {
                            $this->log("⚠️ Slide UP: erro ao cancelar BUY #{$buyToCancel['binance_order_id']}: " . $e->getMessage() . " — sincronizando", 'WARNING', 'TRADE');
                            $this->syncOrdersWithBinance($gridId);
                            break;
                        }

                        // Passo 3: marcar como cancelada no banco
                        $ordersModel2 = new orders_model();
                        $ordersModel2->set_filter(["idx = '{$buyToCancel['orders_idx']}'"]);
                        $ordersModel2->populate(['status' => 'CANCELED']);
                        $ordersModel2->save();

                        $goCancelModel2 = new grids_orders_model();
                        $goCancelModel2->set_filter(["idx = '{$buyToCancel['grids_orders_idx']}'"]);
                        $goCancelModel2->populate(['active' => 'no']);
                        $goCancelModel2->save();

                        // Passo 4: USDC liberado = qty × preço da BUY cancelada
                        $capitalUsdc = $buyToCancel['quantity'] * $buyToCancel['price'];

                        if ($capitalUsdc <= 0) {
                            $this->log("⚠️ Slide UP: capital USDC inválido para BUY #{$buyToCancel['grids_orders_idx']} (grid #$gridId)", 'WARNING', 'TRADE');
                            break;
                        }
                        if ($minNotional && $capitalUsdc < $minNotional) {
                            $this->log("⚠️ Slide UP: capital $capitalUsdc abaixo do mínimo notional (grid #$gridId)", 'WARNING', 'TRADE');
                            break;
                        }

                        // Remover BUY cancelada do array local
                        $activeBuyOrders = array_values(array_filter(
                            $activeBuyOrders,
                            fn($o) => $o['grids_orders_idx'] !== $buyToCancel['grids_orders_idx']
                        ));

                        if (empty($activeBuyOrders)) {
                            $this->log("⚠️ Slide UP: sem BUYs restantes após cancelamento (grid #$gridId)", 'WARNING', 'TRADE');
                            break;
                        }

                        // Passo 5: novo preço = maior BUY ativa × (1 + spacing)
                        $highestBuyPrice = max(array_column($activeBuyOrders, 'price'));
                        $newBuyPrice     = (float)$this->adjustPriceToTickSize($highestBuyPrice * (1 + $gridSpacing), $tickSize);

                        // Passo 6: criar nova BUY acima, com USDC reciclado
                        $newBuyId = $this->placeBuyOrder(
                            $gridId,
                            $symbol,
                            $buyToCancel['grid_level'],
                            $newBuyPrice,
                            $capitalUsdc,
                            true,   // skipProfitValidation
                            true    // isSlidingLevel
                        );

                        if (!$newBuyId) {
                            $this->log("❌ Slide UP: falha ao criar BUY @ \$$newBuyPrice (grid #$gridId)", 'ERROR', 'TRADE');
                            break;
                        }

                        // Passo 7-8: contadores e log
                        $this->incrementSlideCount($gridId, 'up');
                        $this->log(
                            "⬆️ Slide UP #$iteration: BUY reciclada \${$buyToCancel['price']} → \$$newBuyPrice (USDC: \$" . number_format($capitalUsdc, 2) . ")",
                            'INFO',
                            'TRADE'
                        );
                        $this->saveGridLog($gridId, 'grid_slide_up', 'success', 'Grid deslizou para cima (BUY→BUY)', [
                            'current_price'          => $currentPrice,
                            'cancelled_buy_price'    => $buyToCancel['price'],
                            'new_buy_price'          => $newBuyPrice,
                            'recycled_capital_usdc'  => $capitalUsdc,
                            'iteration'              => $iteration,
                        ]);

                        // Atualizar estado local para próxima iteração
                        $highestBuyPrice   = $newBuyPrice;
                        $activeBuyOrders[] = [
                            'price'            => $newBuyPrice,
                            'grids_orders_idx' => $newBuyId,
                            'quantity'         => $capitalUsdc / max($newBuyPrice, 0.00000001),
                            'orders_idx'       => null,
                            'binance_order_id' => null,
                        ];
                    }
                }
            }

            // ── Atualizar centro e range estrutural após slides ───────────────────
            // Reancora o grid no novo centro válido e persiste lower/upper usando
            // a mesma geometria da criação inicial (GRID_RANGE_PERCENT).
            $newCenterPrice = null;

            if (!empty($activeBuyOrders) && !empty($activeSellOrders)) {
                $highestActiveBuy = max(array_column($activeBuyOrders,  'price'));
                $lowestActiveSell = min(array_column($activeSellOrders, 'price'));
                $newCenterPrice   = ($lowestActiveSell + $highestActiveBuy) / 2.0;

                $this->log(
                    "📍 Center price atualizado: \$" . number_format($newCenterPrice, 2) .
                        " (BUY max: \$" . number_format($highestActiveBuy, 2) .
                        " | SELL min: \$" . number_format($lowestActiveSell, 2) . ")",
                    'INFO',
                    'TRADE'
                );
            } elseif (!empty($activeSellOrders)) {
                $lowestActiveSell = min(array_column($activeSellOrders, 'price'));
                $newCenterPrice = $lowestActiveSell;

                $this->log(
                    "📍 Center price atualizado: \$" . number_format($lowestActiveSell, 2) .
                        " (apenas SELLs ativas — menor SELL)",
                    'INFO',
                    'TRADE'
                );
            } elseif (!empty($activeBuyOrders)) {
                $highestActiveBuy = max(array_column($activeBuyOrders, 'price'));
                $newCenterPrice = $highestActiveBuy;

                $this->log(
                    "📍 Center price atualizado: \$" . number_format($highestActiveBuy, 2) .
                        " (apenas BUYs ativas — maior BUY)",
                    'INFO',
                    'TRADE'
                );
            }

            if ($newCenterPrice !== null && $newCenterPrice > 0) {
                $newLowerPrice = $newCenterPrice * (1 - self::GRID_RANGE_PERCENT);
                $newUpperPrice = $newCenterPrice * (1 + self::GRID_RANGE_PERCENT);

                $gridsCenter = new grids_model();
                $gridsCenter->set_filter(["idx = '{$gridId}'"]);
                $gridsCenter->populate([
                    'current_price' => number_format($newCenterPrice, 8, '.', ''),
                    'lower_price' => number_format($newLowerPrice, 8, '.', ''),
                    'upper_price' => number_format($newUpperPrice, 8, '.', ''),
                ]);
                $gridsCenter->save();

                $this->log(
                    "🧭 Range estrutural atualizado: center \$" . number_format($newCenterPrice, 2) .
                        " | lower \$" . number_format($newLowerPrice, 2) .
                        " | upper \$" . number_format($newUpperPrice, 2),
                    'INFO',
                    'TRADE'
                );
            }
        } catch (Exception $e) {
            $this->log("Erro no slideGrid para grid #$gridId ($symbol): " . $e->getMessage(), 'ERROR', 'TRADE');
        }
    }

    /**
     * Incrementa os contadores de slide no registro do grid.
     */
    private function incrementSlideCount(int $gridId, string $direction): void
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter(["idx = '{$gridId}'"]);
            $gridsModel->load_data();
            if (empty($gridsModel->data)) {
                return;
            }

            $currentTotal = (int)($gridsModel->data[0]['slide_count']      ?? 0);
            $currentDir   = $direction === 'down'
                ? (int)($gridsModel->data[0]['slide_count_down'] ?? 0)
                : (int)($gridsModel->data[0]['slide_count_up']   ?? 0);

            $fields = ['slide_count' => $currentTotal + 1];
            if ($direction === 'down') {
                $fields['slide_count_down'] = $currentDir + 1;
            } else {
                $fields['slide_count_up'] = $currentDir + 1;
            }

            $gridsModel2 = new grids_model();
            $gridsModel2->set_filter(["idx = '{$gridId}'"]);
            $gridsModel2->populate($fields);
            $gridsModel2->save();
        } catch (Exception $e) {
            $this->log("Erro ao incrementar slide_count: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Coloca uma ordem de compra LIMIT na Binance
     * Inclui validação de lucro mínimo (Fee Threshold)
     */
    private function placeBuyOrder(
        int $gridId,
        string $symbol,
        int $gridLevel,
        float $price,
        float $capitalUsdc,
        bool $skipProfitValidation = false,
        bool $isSlidingLevel = false,
        float $originalCostPrice = 0.0
    ): ?int {
        try {
            // ══════ FEE THRESHOLD VALIDATION ══════
            // Não aplica na criação inicial do grid ($skipProfitValidation = true)
            if (!$skipProfitValidation && !$this->isTradeViable($capitalUsdc, $symbol)) {
                $this->log(
                    "⚠️ BUY nível $gridLevel rejeitada: lucro esperado abaixo do mínimo (capital: \$" .
                        number_format($capitalUsdc, 4) . ")",
                    'INFO',
                    'TRADE'
                );
                return null;
            }

            // 0. Normalizar preço ao slot canônico da grade geométrica
            $_gd = $this->getGridById($gridId);
            $_cp = (float)($_gd['current_price'] ?? 0);
            $_gs = $this->getGridSpacing($symbol);
            if ($_cp > 0 && $_gs > 0) {
                $price = $this->normalizeToGrid($price, $_cp, $_gs);
            }

            // 1. Obter filtros do símbolo
            $symbolData = $this->getExchangeInfo($symbol);
                list($stepSize, $tickSize, $minNotional, $pps) = $this->extractFilters($symbolData);

            // 2. Ajustar preço ao tickSize
            $adjustedPrice = $this->adjustPriceToTickSize($price, $tickSize);

            // 2.5 Validar PERCENT_PRICE_BY_SIDE
            if ($pps !== null && $_cp > 0) {
                $minPrice = $_cp * $pps['bidMultiplierDown'];
                $maxPrice = $_cp * $pps['bidMultiplierUp'];
                if ((float)$adjustedPrice < $minPrice || (float)$adjustedPrice > $maxPrice) {
                    $oldPrice = $adjustedPrice;
                    $adjustedPrice = number_format(max($minPrice, min((float)$adjustedPrice, $maxPrice)), $this->getDecimalPlaces($tickSize), '.', '');
                    $this->log("⚠️ BUY preço ajustado por PPS: \${$oldPrice} → \${$adjustedPrice} (range: \${$minPrice}-\${$maxPrice})", 'WARNING', 'TRADE');
                }
            }

            // 3. Calcular quantidade
            $quantity = $this->calculateAdjustedQuantity($capitalUsdc, (float)$adjustedPrice, $stepSize);

            if ((float)$quantity <= 0) {
                $this->log("Quantidade inválida para ordem de compra em $symbol", 'ERROR', 'TRADE');
                return null;
            }

            // 4. Validar minNotional
            $orderValue = (float)$adjustedPrice * (float)$quantity;
            if ($minNotional && $orderValue < $minNotional) {
                $this->log("Valor da ordem ($orderValue) abaixo do mínimo ($minNotional) para $symbol", 'ERROR', 'TRADE');
                return null;
            }

            // 5. Criar ordem LIMIT na Binance
            $orderReq = new NewOrderRequest();
            $orderReq->setSymbol($symbol);
            $orderReq->setSide(Side::BUY);
            $orderReq->setType(OrderType::LIMIT);
            $orderReq->setTimeInForce('GTC');
            $orderReq->setPrice((float)$adjustedPrice);
            $orderReq->setQuantity((float)$quantity);

            $response = $this->client->newOrder($this->applyRecvWindowToOrderRequest($orderReq));
            $orderData = $response->getData();

            $binanceOrderId       = $this->extractBinanceValue($orderData, 'getOrderId', 'orderId', null);
            $binanceClientOrderId = $this->extractBinanceValue($orderData, 'getClientOrderId', 'clientOrderId', null);
            $status               = $this->extractBinanceValue($orderData, 'getStatus', 'status', 'UNKNOWN');

            // 6. Salvar ordem no banco
            $orderParams = [
                'grids_id' => $gridId,
                'binance_order_id' => $binanceOrderId,
                'binance_client_order_id' => $binanceClientOrderId,
                'symbol' => $symbol,
                'side' => 'BUY',
                'type' => 'LIMIT',
                'grid_level' => $gridLevel,
                'price' => $adjustedPrice,
                'quantity' => $quantity,
                'status' => $status,
                'order_created_at' => round(microtime(true) * 1000)
            ];
            if ($isSlidingLevel) {
                $orderParams['is_sliding_level'] = 1;
                if ($originalCostPrice > 0) {
                    $orderParams['original_cost_price'] = $originalCostPrice;
                }
            }
            $orderDbId = $this->saveGridOrder($orderParams);

            $this->log("Ordem BUY criada: $symbol @ $adjustedPrice (Qty: $quantity, Nível: $gridLevel)" . ($isSlidingLevel ? ' [SLIDE]' : ''), 'INFO', 'TRADE');

            return $orderDbId;
        } catch (Exception $e) {
            $this->logBinanceError('placeBuyOrder', $e->getMessage(), [
                'symbol' => $symbol,
                'price' => $price,
                'capital' => $capitalUsdc,
                'grid_level' => $gridLevel
            ]);
            return null;
        }
    }

    /**
     * Coloca uma ordem de venda LIMIT na Binance
     */
    private function placeSellOrder(
        int $gridId,
        string $symbol,
        int $gridLevel,
        float $price,
        float $quantity,
        ?int $pairedBuyOrderId = null,
        bool $isSlidingLevel = false,
        float $originalCostPrice = 0.0
    ): ?int {
        try {
            // 0. Normalizar preço ao slot canônico da grade geométrica
            $_gd = $this->getGridById($gridId);
            $_cp = (float)($_gd['current_price'] ?? 0);
            $_gs = $this->getGridSpacing($symbol);
            if ($_cp > 0 && $_gs > 0) {
                $price = $this->normalizeToGrid($price, $_cp, $_gs);
            }

            // 1. Obter filtros do símbolo
            $symbolData = $this->getExchangeInfo($symbol);
                list($stepSize, $tickSize, $minNotional, $pps) = $this->extractFilters($symbolData);

            // 2. Ajustar preço ao tickSize
            $adjustedPrice = $this->adjustPriceToTickSize($price, $tickSize);

            // 2.5 Validar PERCENT_PRICE_BY_SIDE
            $_cp2 = (float)($_gd['current_price'] ?? 0);
            if ($pps !== null && $_cp2 > 0) {
                $minPrice = $_cp2 * $pps['askMultiplierDown'];
                $maxPrice = $_cp2 * $pps['askMultiplierUp'];
                if ((float)$adjustedPrice < $minPrice || (float)$adjustedPrice > $maxPrice) {
                    $oldPrice = $adjustedPrice;
                    $adjustedPrice = number_format(max($minPrice, min((float)$adjustedPrice, $maxPrice)), $this->getDecimalPlaces($tickSize), '.', '');
                    $this->log("⚠️ SELL preço ajustado por PPS: \${$oldPrice} → \${$adjustedPrice} (range: \${$minPrice}-\${$maxPrice})", 'WARNING', 'TRADE');
                }
            }

            // 3. Ajustar quantidade ao stepSize
            $stepSizeFloat = (float)$stepSize;
            $decimalPlacesQty = $this->getDecimalPlaces($stepSize);
            $adjustedQty = floor((float)$quantity / $stepSizeFloat) * $stepSizeFloat;
            $adjustedQty = number_format($adjustedQty, $decimalPlacesQty, '.', '');

            if ((float)$adjustedQty <= 0) {
                $this->log("Quantidade inválida para ordem de venda em $symbol", 'ERROR', 'TRADE');
                return null;
            }

            // 4. Validar minNotional
            $orderValue = (float)$adjustedPrice * (float)$adjustedQty;
            if ($minNotional && $orderValue < $minNotional) {
                $this->log("Valor da ordem ($orderValue) abaixo do mínimo ($minNotional) para $symbol", 'ERROR', 'TRADE');
                return null;
            }

            // 5. Criar ordem LIMIT na Binance
            $orderReq = new NewOrderRequest();
            $orderReq->setSymbol($symbol);
            $orderReq->setSide(Side::SELL);
            $orderReq->setType(OrderType::LIMIT);
            $orderReq->setTimeInForce('GTC');
            $orderReq->setPrice((float)$adjustedPrice);
            $orderReq->setQuantity((float)$adjustedQty);

            $response = $this->client->newOrder($this->applyRecvWindowToOrderRequest($orderReq));
            $orderData = $response->getData();

            $binanceOrderId       = $this->extractBinanceValue($orderData, 'getOrderId', 'orderId', null);
            $binanceClientOrderId = $this->extractBinanceValue($orderData, 'getClientOrderId', 'clientOrderId', null);
            $status               = $this->extractBinanceValue($orderData, 'getStatus', 'status', 'UNKNOWN');

            // 6. Salvar ordem no banco
            $orderParams = [
                'grids_id' => $gridId,
                'binance_order_id' => $binanceOrderId,
                'binance_client_order_id' => $binanceClientOrderId,
                'symbol' => $symbol,
                'side' => 'SELL',
                'type' => 'LIMIT',
                'grid_level' => $gridLevel,
                'price' => $adjustedPrice,
                'quantity' => $adjustedQty,
                'status' => $status,
                'order_created_at' => round(microtime(true) * 1000),
                'paired_order_id' => $pairedBuyOrderId
            ];
            if ($isSlidingLevel) {
                $orderParams['is_sliding_level'] = 1;
                if ($originalCostPrice > 0) {
                    $orderParams['original_cost_price'] = $originalCostPrice;
                }
            }
            $orderDbId = $this->saveGridOrder($orderParams);

            $this->log("Ordem SELL criada: $symbol @ $adjustedPrice (Qty: $adjustedQty, Nível: $gridLevel)" . ($isSlidingLevel ? ' [SLIDE]' : ''), 'INFO', 'TRADE');

            return $orderDbId;
        } catch (Exception $e) {
            $this->logBinanceError('placeSellOrder', $e->getMessage(), [
                'symbol' => $symbol,
                'price' => $price,
                'quantity' => $quantity
            ]);
            return null;
        }
    }

    /**
     * Vende um ativo a mercado para desfazer posição durante rebalanceamento
     */
    private function sellAssetAtMarket(string $symbol, float $quantity, int $gridId): void
    {
        try {
            $symbolData = $this->getExchangeInfo($symbol);
            list($stepSize, $tickSize, $minNotional, $pps) = $this->extractFilters($symbolData);
            $stepSizeFloat = (float)$stepSize;
            $decimalPlacesQty = $this->getDecimalPlaces($stepSize);
            $decimalPlacesPrice = $this->getDecimalPlaces($tickSize);

            $filters = array_column($symbolData['filters'], null, 'filterType');
            $minQty = isset($filters['LOT_SIZE']['minQty']) ? (float)$filters['LOT_SIZE']['minQty'] : 0.00001;

            $remainingQty = floor($quantity / $stepSizeFloat) * $stepSizeFloat;
            if ($remainingQty <= 0 || $remainingQty < $minQty) {
                $this->log(
                    "Quantidade insuficiente para venda a mercado: " . number_format($remainingQty, 8) .
                        " (mínimo LOT_SIZE: " . number_format($minQty, 8) . ")",
                    'WARNING',
                    'TRADE'
                );
                return;
            }

            $tickSizeFloat = (float)$tickSize;
            $currentPrice = $this->getCurrentPrice($symbol);

            // Tentativa 1: LIMIT IOC agressiva (bid - 1 tick)
            // Tentativa 2: LIMIT IOC agressiva (bid - 5 ticks)
            // Tentativa 3: MARKET com quantidade restante
            $attempts = [
                ['type' => 'LIMIT_IOC', 'offset' => 1 * $tickSizeFloat, 'label' => 'LIMIT IOC -1 tick'],
                ['type' => 'LIMIT_IOC', 'offset' => 5 * $tickSizeFloat, 'label' => 'LIMIT IOC -5 ticks'],
                ['type' => 'MARKET', 'offset' => 0, 'label' => 'MARKET'],
            ];

            foreach ($attempts as $attempt) {
                if ($remainingQty < $minQty || $remainingQty <= 0) {
                    break;
                }

                $sellReq = new NewOrderRequest();
                $sellReq->setSymbol($symbol);
                $sellReq->setSide(Side::SELL);
                $sellReq->setQuantity((float)number_format($remainingQty, $decimalPlacesQty, '.', ''));

                if ($attempt['type'] === 'LIMIT_IOC') {
                    $limitPrice = max($tickSizeFloat, $currentPrice - $attempt['offset']);
                    $sellReq->setType(OrderType::LIMIT);
                    $sellReq->setPrice((float)number_format($limitPrice, $decimalPlacesPrice, '.', ''));
                    $sellReq->setTimeInForce('IOC');
                } else {
                    $sellReq->setType(OrderType::MARKET);
                }

                try {
                    $resp = $this->client->newOrder($this->applyRecvWindowToOrderRequest($sellReq));
                    $data = $resp->getData();

                    $orderId = $this->extractBinanceValue($data, 'getOrderId', 'orderId', null);
                    $status  = $this->extractBinanceValue($data, 'getStatus', 'status', 'UNKNOWN');
                    $executedQty = (float)$this->extractBinanceValue($data, 'getExecutedQty', 'executedQty', 0);

                    $this->log("Venda de emergência [{$attempt['label']}]: $symbol (Qty: {$remainingQty}, Executed: {$executedQty}, Status: $status)", 'SUCCESS', 'TRADE');

                    $remainingQty -= $executedQty;

                    if ($remainingQty < $minQty) {
                        $this->saveGridLog(
                            $gridId,
                            'emergency_sell',
                            'success',
                            "Venda escalonada concluída",
                            [
                                'quantity_total' => $quantity,
                                'quantity_sold' => $quantity - $remainingQty,
                                'quantity_remaining' => $remainingQty,
                                'order_id' => $orderId,
                                'status' => $status
                            ]
                        );
                        return;
                    }

                    // Aguardar 10s antes da próxima tentativa
                    if ($attempt['type'] !== 'MARKET') {
                        sleep(10);
                    }
                } catch (Exception $attemptEx) {
                    $this->log("Erro na tentativa {$attempt['label']}: " . $attemptEx->getMessage(), 'WARNING', 'TRADE');
                    if ($attempt['type'] !== 'MARKET') {
                        sleep(10);
                    }
                }
            }

            if ($remainingQty > 0) {
                $this->log("⚠️ Venda escalonada incompleta: " . number_format($remainingQty, 8) . " $symbol não vendido", 'WARNING', 'TRADE');
            }
        } catch (Exception $e) {
            $this->log("Erro ao executar venda a mercado: " . $e->getMessage(), 'ERROR', 'TRADE');
            $this->saveGridLog(
                $gridId,
                'emergency_sell_error',
                'error',
                "Erro ao vender: " . $e->getMessage()
            );
        }
    }

    /**
     * Obtém o preço atual de um símbolo via API Binance
     */
    private function getCurrentPrice(string $symbol): float
    {
        try {
            $response = $this->client->tickerPrice($symbol);
            $data = $response->getData();

            if ($data && method_exists($data, 'getTickerPriceResponse1')) {
                $priceData = $data->getTickerPriceResponse1();
                if ($priceData && method_exists($priceData, 'getPrice')) {
                    $price = (float)$priceData->getPrice();
                    $this->symbolPrices[$symbol] = $price;
                    return $price;
                }
            }

            if ($data && method_exists($data, 'getPrice')) {
                $price = (float)$data->getPrice();
                $this->symbolPrices[$symbol] = $price;
                return $price;
            }

            $price = (float)($data['price'] ?? 0);
            $this->symbolPrices[$symbol] = $price;
            return $price;
        } catch (Exception $e) {
            $this->log("Erro ao obter preço de $symbol: " . $e->getMessage(), 'ERROR', 'API');
            return 0.0;
        }
    }

    /**
     * Carrega informações de capital disponível (USDC)
     */
    private function loadCapitalInfo(): void
    {
        try {
            $accountInfo = $this->getAccountInfo(true);

            $this->totalCapital = $this->getBalanceForAsset($accountInfo['balances'], 'USDC');
            if ($this->totalCapital === 0.0) {
                $this->log("AVISO: Nenhum saldo USDC encontrado", 'WARNING', 'SYSTEM');
                return;
            }
            $this->log("Capital USDC disponível: {$this->totalCapital}", 'INFO', 'SYSTEM');
        } catch (Exception $e) {
            $this->log("Erro ao carregar capital: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            $this->totalCapital = 0.0;
        }
    }

    /**
     * Retorna a alocação de capital para cada símbolo
     */
    private function getSymbolAllocation(string $symbol): float
    {
        $allocations = [
            'BTCUSDC' => 1.0,
        ];

        return $allocations[$symbol] ?? 0.0;
    }

    // ========== MÉTODOS DE PROTEÇÃO ==========

    /**
     * STOP-LOSS GLOBAL: Verifica se drawdown excedeu o limite permitido
     * Calcula: (initial_capital - current_capital) / initial_capital
     * Se drawdown >= 20%, aciona shutdown emergencial
     *
     * @param int $gridId ID do grid
     * @param array $gridData Dados do grid
     * @return bool true se stop-loss foi acionado (grid encerrado)
     */
    private function checkStopLoss(int $gridId, array $gridData): bool
    {
        try {
            // Verificar se já foi acionado anteriormente
            if (($gridData['stop_loss_triggered'] ?? 'no') === 'yes') {
                return true; // Já está parado, não reprocessar
            }

            $initialCapital = (float)($gridData['initial_capital_usdc'] ?? 0);
            if ($initialCapital <= 0) {
                return false; // Sem dados iniciais, não pode calcular
            }

            $symbol = $gridData['symbol'];
            $currentCapital = $this->calculateCurrentCapital($symbol);

            if ($currentCapital <= 0) {
                $this->log("⚠️ Stop-Loss: capital atual não pode ser calculado", 'WARNING', 'SYSTEM');
                return false;
            }

            $drawdown = ($initialCapital - $currentCapital) / $initialCapital;

            $this->log(
                "📉 Stop-Loss check: Capital inicial=\$" . number_format($initialCapital, 2) .
                    " | Atual=\$" . number_format($currentCapital, 2) .
                    " | Drawdown=" . number_format($drawdown * 100, 2) . "%" .
                    " | Limite=" . number_format(self::MAX_DRAWDOWN_PERCENT * 100, 0) . "%",
                'INFO',
                'SYSTEM'
            );

            if ($drawdown >= self::MAX_DRAWDOWN_PERCENT) {
                if (empty($gridData['pending_shutdown_at'])) {
                    // Primeiro disparo — armar o timer
                    $this->setPendingShutdown($gridId, 'stop_loss');
                    $this->log("⚠️ Drawdown {$drawdown}% — circuit breaker armado (aguardando 10min)", 'WARNING', 'RISK');
                    return false;
                }
                $minutesWaiting = (time() - strtotime($gridData['pending_shutdown_at'])) / 60;
                if ($minutesWaiting < 10) {
                    $this->log("⏳ Circuit breaker aguardando ({$minutesWaiting}min/10min)", 'INFO', 'RISK');
                    return false;
                }
                if ($drawdown >= (self::MAX_DRAWDOWN_PERCENT - 0.02)) {
                    $this->log("🛑 Circuit breaker confirmado — executando shutdown", 'ERROR', 'RISK');
                    $lossPercent = number_format($drawdown * 100, 2);
                    $this->log(
                        "🚨🚨🚨 STOP-LOSS ACIONADO! Grid #$gridId | Perda: {$lossPercent}% " .
                            "(>\$" . number_format(self::MAX_DRAWDOWN_PERCENT * 100, 0) . "%) | " .
                            "Inicial: \$" . number_format($initialCapital, 2) . " → Atual: \$" . number_format($currentCapital, 2),
                        'ERROR',
                        'SYSTEM'
                    );

                    $this->emergencyShutdown($gridId, $symbol, 'stop_loss', [
                        'initial_capital' => $initialCapital,
                        'current_capital' => $currentCapital,
                        'drawdown_percent' => $drawdown,
                        'max_drawdown_allowed' => self::MAX_DRAWDOWN_PERCENT
                    ]);

                    return true;
                }
                // Recuperou — desarmar
                $this->clearPendingShutdown($gridId);
                $this->log("✅ Drawdown recuperou — circuit breaker desarmado", 'INFO', 'RISK');
                return false;
            }
            if (!empty($gridData['pending_shutdown_at'])) {
                $this->clearPendingShutdown($gridId);
            }
            return false;
        } catch (Exception $e) {
            $this->log("Erro no checkStopLoss: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return false; // Em caso de erro, não acionar (conservador)
        }
    }

    private function setPendingShutdown(int $gridId, string $reason): void
    {
        $gridsModel = new grids_model();
        $gridsModel->set_filter(["idx = '{$gridId}'"]);
        $gridsModel->populate([
            'pending_shutdown_at' => date('Y-m-d H:i:s'),
            'pending_shutdown_reason' => $reason
        ]);
        $gridsModel->save();
    }

    private function clearPendingShutdown(int $gridId): void
    {
        $gridsModel = new grids_model();
        $gridsModel->set_filter(["idx = '{$gridId}'"]);
        $gridsModel->populate([
            'pending_shutdown_at' => null,
            'pending_shutdown_reason' => null
        ]);
        $gridsModel->save();
    }

    /**
     * TRAILING STOP: Protege lucros acumulados
     * Ativa somente após lucro >= 10% do capital inicial
     * Aciona shutdown se capital cai 15% do peak atingido
     *
     * @param int $gridId ID do grid
     * @param array $gridData Dados do grid
     * @return bool true se trailing stop foi acionado
     */
    private function checkTrailingStop(int $gridId, array $gridData): bool
    {
        try {
            // Verificar se já foi acionado
            if (($gridData['trailing_stop_triggered'] ?? 'no') === 'yes') {
                return true;
            }

            $initialCapital = (float)($gridData['initial_capital_usdc'] ?? 0);
            $peakCapital = (float)($gridData['peak_capital_usdc'] ?? 0);

            if ($initialCapital <= 0 || $peakCapital <= 0) {
                return false;
            }

            $symbol = $gridData['symbol'];
            $currentCapital = $this->calculateCurrentCapital($symbol);

            if ($currentCapital <= 0) {
                return false;
            }

            // 1. Verificar se atingiu lucro mínimo de 10% para ativar trailing
            $profitPercent = ($currentCapital - $initialCapital) / $initialCapital;

            if ($profitPercent < self::MIN_PROFIT_TO_ACTIVATE_TRAILING) {
                return false; // Ainda não atingiu lucro mínimo para ativar
            }

            $this->notifyTrailingArmed($gridId, $symbol, $initialCapital, $peakCapital, $currentCapital, $profitPercent);

            // 2. Calcular queda do pico
            $dropFromPeak = ($peakCapital - $currentCapital) / $peakCapital;

            $this->log(
                "📊 Trailing Stop check: Inicial=\$" . number_format($initialCapital, 2) .
                    " | Pico=\$" . number_format($peakCapital, 2) .
                    " | Atual=\$" . number_format($currentCapital, 2) .
                    " | Lucro=" . number_format($profitPercent * 100, 2) . "%" .
                    " | Queda do pico=" . number_format($dropFromPeak * 100, 2) . "%",
                'INFO',
                'SYSTEM'
            );

            if ($dropFromPeak >= self::TRAILING_STOP_PERCENT) {
                $preservedProfit = $currentCapital - $initialCapital;
                $preservedROI = ($preservedProfit / $initialCapital) * 100;

                $this->log(
                    "🛡️🛡️🛡️ TRAILING STOP ACIONADO! Grid #$gridId | " .
                        "Pico: \$" . number_format($peakCapital, 2) . " → Atual: \$" . number_format($currentCapital, 2) .
                        " (queda " . number_format($dropFromPeak * 100, 2) . "%) | " .
                        "Lucro preservado: \$" . number_format($preservedProfit, 2) . " (ROI: " . number_format($preservedROI, 2) . "%)",
                    'WARNING',
                    'SYSTEM'
                );

                $this->emergencyShutdown($gridId, $symbol, 'trailing_stop', [
                    'initial_capital' => $initialCapital,
                    'peak_capital' => $peakCapital,
                    'current_capital' => $currentCapital,
                    'drop_from_peak_percent' => $dropFromPeak,
                    'preserved_profit' => $preservedProfit,
                    'preserved_roi_percent' => $preservedROI
                ]);

                return true;
            }

            return false;
        } catch (Exception $e) {
            $this->log("Erro no checkTrailingStop: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return false;
        }
    }

    /**
     * Procedimento de shutdown emergencial
     * Cancela ordens, vende BTC a mercado, marca grid como parado
     *
     * @param int $gridId ID do grid
     * @param string $symbol Par de negociação
     * @param string $reason Motivo: 'stop_loss' ou 'trailing_stop'
     * @param array $metadata Dados adicionais para log
     */
    private function emergencyShutdown(int $gridId, string $symbol, string $reason, array $metadata = []): void
    {
        try {
            $this->log("🚨 Iniciando EMERGENCY SHUTDOWN (motivo: $reason) para grid #$gridId...", 'ERROR', 'SYSTEM');

            // 1. CANCELAR TODAS ORDENS ABERTAS NA BINANCE
            $this->cancelAllGridOrders($gridId);
            $this->log("✅ Ordens canceladas", 'INFO', 'SYSTEM');

            // 2. VENDER TODO BTC RESTANTE A MERCADO
            $baseAsset = str_replace('USDC', '', $symbol);
            $accountInfo = $this->getAccountInfo(true);
            $btcBalance = $this->getBalanceForAsset($accountInfo['balances'], $baseAsset);

            if ($btcBalance > 0.00001) {
                $this->log("🔄 Vendendo $btcBalance $baseAsset a mercado...", 'INFO', 'SYSTEM');
                $this->sellAssetAtMarket($symbol, $btcBalance, $gridId);
            }

            // 3. MARCAR GRID COMO PARADO
            $gridsModel = new grids_model();
            $gridsModel->set_filter(["idx = '{$gridId}'"]);

            $updateData = [
                'status' => 'stopped',
                'is_processing' => 'no'
            ];

            if ($reason === 'stop_loss') {
                $updateData['stop_loss_triggered'] = 'yes';
                $updateData['stop_loss_triggered_at'] = date('Y-m-d H:i:s');
            } elseif ($reason === 'trailing_stop') {
                $updateData['trailing_stop_triggered'] = 'yes';
                $updateData['trailing_stop_triggered_at'] = date('Y-m-d H:i:s');
            }

            $gridsModel->populate($updateData);
            $gridsModel->save();

            // 4. DESATIVAR TODAS ORDENS RELACIONADAS
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter(["grids_id = '{$gridId}'"]);
            $gridsOrdersModel->load_data();

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $model = new grids_orders_model();
                $model->set_filter(["idx = '{$gridOrder['idx']}'"]);
                $model->populate(['active' => 'no']);
                $model->save();
            }

            // 5. LIMPAR CACHE EM MEMÓRIA
            unset($this->activeGrids[$symbol]);

            // 6. SALVAR LOG DO EVENTO
            $this->saveGridLog(
                $gridId,
                "emergency_{$reason}",
                'error',
                "Emergency shutdown: $reason acionado",
                $metadata
            );

            $this->log(
                "🏁 EMERGENCY SHUTDOWN CONCLUÍDO para grid #$gridId (motivo: $reason)",
                'ERROR',
                'SYSTEM'
            );

            $this->notifyBotStopped($gridId, $symbol, $reason, $metadata);
        } catch (Exception $e) {
            $this->log(
                "❌ ERRO CRÍTICO no emergency shutdown: " . $e->getMessage(),
                'ERROR',
                'SYSTEM'
            );
        }
    }

    private function notifyTrailingArmed(
        int $gridId,
        string $symbol,
        float $initialCapital,
        float $peakCapital,
        float $currentCapital,
        float $profitPercent
    ): void {
        $subject = "Driftex: Trailing Stop armado em {$symbol}";
        $body = sprintf(
            "<p>O Trailing Stop do grid #%d foi armado.</p>
            <p><strong>Par:</strong> %s<br>
            <strong>Capital inicial:</strong> US$ %s<br>
            <strong>Capital atual:</strong> US$ %s<br>
            <strong>Pico atual:</strong> US$ %s<br>
            <strong>Lucro atual:</strong> %s%%</p>
            <p>O bot segue operando. Se houver queda de %s%% a partir do pico, o bot será parado automaticamente.</p>",
            $gridId,
            htmlspecialchars($symbol),
            number_format($initialCapital, 2, '.', ','),
            number_format($currentCapital, 2, '.', ','),
            number_format($peakCapital, 2, '.', ','),
            number_format($profitPercent * 100, 2, '.', ','),
            number_format(self::TRAILING_STOP_PERCENT * 100, 0, '.', ',')
        );

        BotAlertService::sendGridAlertOnce(
            $gridId,
            'trailing_stop_armed_email',
            'warning',
            $subject,
            $body,
            [
                'symbol' => $symbol,
                'initial_capital' => $initialCapital,
                'current_capital' => $currentCapital,
                'peak_capital' => $peakCapital,
                'profit_percent' => $profitPercent
            ]
        );
    }

    private function notifyBotStopped(int $gridId, string $symbol, string $reason, array $metadata = []): void
    {
        $reasonLabel = $reason === 'stop_loss' ? 'Stop-Loss' : 'Trailing Stop';
        $subject = "Driftex: {$reasonLabel} acionado em {$symbol}";
        $body = sprintf(
            "<p>O bot foi parado automaticamente por %s.</p>
            <p><strong>Grid:</strong> #%d<br>
            <strong>Par:</strong> %s</p>
            <p><strong>Detalhes:</strong></p>
            <pre style=\"font-family: monospace; white-space: pre-wrap;\">%s</pre>",
            htmlspecialchars($reasonLabel),
            $gridId,
            htmlspecialchars($symbol),
            htmlspecialchars(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
        );

        BotAlertService::sendGridAlertOnce(
            $gridId,
            $reason === 'stop_loss' ? 'stop_loss_email' : 'trailing_stop_triggered_email',
            'error',
            $subject,
            $body,
            array_merge($metadata, [
                'symbol' => $symbol,
                'reason' => $reason
            ])
        );
    }

    public static function getCronStaleAlertMinutes(): int
    {
        return self::CRON_STALE_ALERT_MINUTES;
    }

    /**
     * Calcula o capital total atual: saldo USDC livre + valor do BTC em USDC
     *
     * @param string $symbol Par de negociação
     * @return float Capital total estimado em USDC
     */
    private function calculateCurrentCapital(string $symbol): float
    {
        $snapshot = $this->calculateCurrentCapitalSnapshot($symbol);
        return (float)($snapshot['total'] ?? 0.0);
    }

    /**
     * Calcula o snapshot atual de capital, separando o componente em USDC.
     *
     * @param string $symbol Par de negociação
     * @return array{total: float, usdc: float, asset_value: float}
     */
    private function calculateCurrentCapitalSnapshot(string $symbol): array
    {
        try {
            $baseAsset = str_replace('USDC', '', $symbol);
            $accountInfo = $this->getAccountInfo(true);

            $usdcBalance = 0.0;
            $btcFree = 0.0;
            $btcLocked = 0.0;

            $usdcBalance = $this->getBalanceForAsset($accountInfo['balances'], 'USDC')
                + $this->getBalanceForAsset($accountInfo['balances'], 'USDC', 'locked');
            $btcFree     = $this->getBalanceForAsset($accountInfo['balances'], $baseAsset);
            $btcLocked   = $this->getBalanceForAsset($accountInfo['balances'], $baseAsset, 'locked');

            $currentPrice = $this->getCurrentPrice($symbol);
            $totalBtcValue = ($btcFree + $btcLocked) * $currentPrice;

            return [
                'total' => $usdcBalance + $totalBtcValue,
                'usdc' => $usdcBalance,
                'asset_value' => $totalBtcValue
            ];
        } catch (Exception $e) {
            $this->log("Erro ao calcular capital atual: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return [
                'total' => 0.0,
                'usdc' => 0.0,
                'asset_value' => 0.0
            ];
        }
    }

    /**
     * Atualiza campos de tracking de capital no grid (current e peak)
     *
     * @param int $gridId ID do grid
     * @param float $currentCapital Capital atual calculado
     */
    private function updateCapitalTracking(int $gridId, float $currentCapital): void
    {
        try {
            $gridData = $this->getGridById($gridId);
            if (!$gridData) {
                return;
            }

            $peakCapital = (float)($gridData['peak_capital_usdc'] ?? 0);

            $updateData = [
                'current_capital_usdc' => $currentCapital,
                'last_usdc_balance_usdc' => $this->calculateCurrentCapitalSnapshot((string)($gridData['symbol'] ?? ''))['usdc'] ?? null
            ];

            // Atualizar pico se capital atual é maior
            if ($currentCapital > $peakCapital) {
                $updateData['peak_capital_usdc'] = $currentCapital;
                $this->log(
                    "📈 Novo pico de capital para grid #$gridId: \$" . number_format($currentCapital, 2) .
                        " (anterior: \$" . number_format($peakCapital, 2) . ")",
                    'INFO',
                    'SYSTEM'
                );
            }

            $gridsModel = new grids_model();
            $gridsModel->set_filter(["idx = '{$gridId}'"]);
            $gridsModel->populate($updateData);
            $gridsModel->save();
        } catch (Exception $e) {
            $this->log("Erro ao atualizar tracking de capital: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    private function maybeRecordCapitalSnapshot(int $gridId, string $symbol): void
    {
        try {
            $snapModel = new capital_snapshots_model();
            $snapModel->set_filter(["grids_id = '{$gridId}'"]);
            $snapModel->set_order(["created_at DESC"]);
            $snapModel->set_paginate([1]);
            $snapModel->load_data();

            $lastSnapshot = $snapModel->data[0] ?? null;
            $lastTime = $lastSnapshot ? strtotime($lastSnapshot['created_at']) : 0;
            if (time() - $lastTime < 3600) {
                return; // Último snapshot < 1h
            }

            $capitalSnapshot = $this->calculateCurrentCapitalSnapshot($symbol);
            $totalCapital = (float)($capitalSnapshot['total'] ?? 0.0);
            $usdcBalance = (float)($capitalSnapshot['usdc'] ?? 0.0);
            $btcHolding = (float)($capitalSnapshot['btc'] ?? 0.0);
            $btcPrice = $this->getCurrentPrice($symbol);

            $gridData = $this->getGridById($gridId);
            $accumulatedPnl = (float)($gridData['accumulated_profit_usdc'] ?? 0.0);

            $newSnap = new capital_snapshots_model();
            $newSnap->populate([
                'grids_id' => $gridId,
                'total_capital_usdc' => $totalCapital,
                'usdc_balance' => $usdcBalance,
                'btc_holding' => $btcHolding,
                'btc_price' => $btcPrice,
                'accumulated_spread_pnl' => $accumulatedPnl,
            ]);
            $newSnap->save();
        } catch (Exception $e) {
            $this->log("Erro ao registrar capital snapshot: " . $e->getMessage(), 'WARNING', 'SYSTEM');
        }
    }

    /**
     * Detecta aportes externos quando o capital sobe abruptamente entre ciclos.
     * Recalibra baseline, capital alocado, pico e capital por nível para que
     * a proteção de lucro não trate o aporte como performance.
     */
    private function autoRegisterContributionIfNeeded(int $gridId, array $gridData, float $currentCapital, float $currentUsdcBalance): array
    {
        try {
            $previousCurrent = (float)($gridData['current_capital_usdc'] ?? 0);
            if ($previousCurrent <= 0 || $currentCapital <= $previousCurrent) {
                return $gridData;
            }

            $increaseAmount = $currentCapital - $previousCurrent;
            $increasePercent = $increaseAmount / $previousCurrent;
            $previousUsdcBalance = (float)($gridData['last_usdc_balance_usdc'] ?? 0);
            $usdcIncrease = $currentUsdcBalance - $previousUsdcBalance;

            if ($increasePercent < self::AUTO_CONTRIBUTION_THRESHOLD_PERCENT) {
                return $gridData;
            }

            $minimumUsdcIncreaseToConfirm = $increaseAmount * self::AUTO_CONTRIBUTION_USDC_CONFIRMATION_RATIO;

            if ($previousUsdcBalance <= 0 || $usdcIncrease < $minimumUsdcIncreaseToConfirm) {
                $this->log(
                    "Salto de capital >= 25% ignorado no grid #$gridId: delta de USDC insuficiente para confirmar aporte " .
                        "(anterior \$" . number_format($previousUsdcBalance, 2) .
                        " | atual \$" . number_format($currentUsdcBalance, 2) .
                        " | delta \$" . number_format($usdcIncrease, 2) .
                        " | salto de capital \$" . number_format($increaseAmount, 2) . ").",
                    'INFO',
                    'SYSTEM'
                );
                return $gridData;
            }

            $previousInitial = (float)($gridData['initial_capital_usdc'] ?? 0);
            $previousPeak = (float)($gridData['peak_capital_usdc'] ?? 0);
            $previousAllocated = (float)($gridData['capital_allocated_usdc'] ?? 0);
            $previousCapitalPerLevel = (float)($gridData['capital_per_level'] ?? 0);

            $newInitial = $previousInitial + $increaseAmount;
            $newAllocated = $previousAllocated + $increaseAmount;
            $newPeak = $currentCapital;
            $newCapitalPerLevel = $newInitial / self::GRID_LEVELS;

            $gridsModel = new grids_model();
            $gridsModel->set_filter(["idx = '" . $gridId . "'"]);
            $gridsModel->populate([
                'capital_allocated_usdc' => $newAllocated,
                'initial_capital_usdc' => $newInitial,
                'peak_capital_usdc' => $newPeak,
                'current_capital_usdc' => $currentCapital,
                'last_usdc_balance_usdc' => $currentUsdcBalance,
                'capital_per_level' => $newCapitalPerLevel
            ]);
            $gridsModel->save();

            $this->saveGridLog(
                $gridId,
                'capital_rebased_auto',
                'success',
                'Aporte automático detectado por salto de capital. Baseline, pico e capital_per_level recalibrados.',
                [
                    'symbol' => $gridData['symbol'] ?? '',
                    'threshold_percent' => self::AUTO_CONTRIBUTION_THRESHOLD_PERCENT,
                    'previous_usdc_balance' => $previousUsdcBalance,
                    'current_usdc_balance' => $currentUsdcBalance,
                    'usdc_increase' => $usdcIncrease,
                    'detected_amount' => $increaseAmount,
                    'increase_percent' => $increasePercent,
                    'previous_initial' => $previousInitial,
                    'new_initial' => $newInitial,
                    'previous_peak' => $previousPeak,
                    'new_peak' => $newPeak,
                    'previous_allocated' => $previousAllocated,
                    'new_allocated' => $newAllocated,
                    'previous_current' => $previousCurrent,
                    'new_current' => $currentCapital,
                    'previous_capital_per_level' => $previousCapitalPerLevel,
                    'new_capital_per_level' => $newCapitalPerLevel,
                    'grid_levels' => self::GRID_LEVELS,
                    'detected_at' => date('Y-m-d H:i:s')
                ]
            );

            try {
                $redis = RedisCache::getInstance();
                $redis->deletePattern('*grids*');
                $redis->deletePattern('*dashboard*');
            } catch (Exception $cacheEx) {
                $this->log("Erro ao limpar cache após aporte automático: " . $cacheEx->getMessage(), 'WARNING', 'SYSTEM');
            }

            $this->log(
                "💰 Aporte automático detectado no grid #$gridId: +" .
                    number_format($increaseAmount, 2) . " USDC (" .
                    number_format($increasePercent * 100, 2) .
                    "%). Baseline recalibrada.",
                'WARNING',
                'SYSTEM'
            );

            $gridData['capital_allocated_usdc'] = $newAllocated;
            $gridData['initial_capital_usdc'] = $newInitial;
            $gridData['peak_capital_usdc'] = $newPeak;
            $gridData['current_capital_usdc'] = $currentCapital;
            $gridData['last_usdc_balance_usdc'] = $currentUsdcBalance;
            $gridData['capital_per_level'] = $newCapitalPerLevel;

            return $gridData;
        } catch (Exception $e) {
            $this->log("Erro ao detectar aporte automático: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return $gridData;
        }
    }

    /**
     * RACE CONDITION: Tenta adquirir lock de processamento para um grid
     * Verifica se outro processo já está trabalhando neste grid.
     * Se lock travado há mais de LOCK_TIMEOUT_MINUTES, força liberação (recovery).
     *
     * @param int $gridId ID do grid
     * @return bool true se lock adquirido com sucesso
     */
    private function acquireGridLock(int $gridId): bool
    {
        try {
            $pdo = (new local_pdo())->getPdo();
            $stmt = $pdo->prepare("
                UPDATE grids
                SET    is_processing   = 'yes',
                       last_monitor_at = NOW()
                WHERE  idx = :id
                  AND  (is_processing = 'no'
                        OR last_monitor_at < (NOW() - INTERVAL :timeout MINUTE))
            ");
            $stmt->execute([':id' => $gridId, ':timeout' => self::LOCK_TIMEOUT_MINUTES]);
            $acquired = $stmt->rowCount() === 1;

            if ($acquired) {
                $this->log("🔒 Lock adquirido para grid #$gridId", 'INFO', 'SYSTEM');
            } else {
                $this->log("🔒 Grid #$gridId bloqueado por outra instância (lock atômico).", 'INFO', 'SYSTEM');
            }

            return $acquired;
        } catch (Exception $e) {
            $this->log("Erro ao adquirir lock: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return false;
        }
    }

    /**
     * RACE CONDITION: Libera lock de processamento do grid
     * Deve ser sempre chamado em bloco finally para garantir liberação
     *
     * @param int $gridId ID do grid
     */
    private function releaseGridLock(int $gridId): void
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter(["idx = '{$gridId}'"]);
            $gridsModel->populate([
                'is_processing' => 'no'
            ]);
            $gridsModel->save();

            $this->log("🔓 Lock liberado para grid #$gridId", 'INFO', 'SYSTEM');
        } catch (Exception $e) {
            $this->log("Erro ao liberar lock do grid #$gridId: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * FEE THRESHOLD: Valida se um trade é lucrativo após descontar taxas
     * 
     * Cálculo:
     * - expected_gross_profit = capital × grid_spacing
     * - total_fees = capital × 0.002 (0.1% buy + 0.1% sell)
     * - expected_net_profit = gross - fees
     * - Viável se net_profit >= threshold dinâmico
     *
     * @param float $capitalUsdc Capital em USDC da ordem
     * @param string $symbol Par de negociação
     * @param bool $isOrphanRecovery Se é recuperação de BTC órfão (sempre permite)
     * @return bool true se trade é viável
     */
    private function isTradeViable(float $capitalUsdc, string $symbol): bool
    {
        try {
            // Validar capital mínimo com margem de segurança
            $minCapital = self::MIN_TRADE_USDC * self::SAFETY_MARGIN;
            if ($capitalUsdc < $minCapital) {
                $this->log(
                    "💸 Trade inviável: Capital=\$" . number_format($capitalUsdc, 4) .
                        " abaixo do mínimo com margem (\$" . number_format($minCapital, 2) . ")",
                    'WARNING',
                    'TRADE'
                );
                return false;
            }

            $gridSpacing = $this->getGridSpacing($symbol);
            $expectedGrossProfit = $capitalUsdc * $gridSpacing;
            $totalFees = $capitalUsdc * (self::FEE_PERCENT * 2); // buy fee + sell fee
            $expectedNetProfit = $expectedGrossProfit - $totalFees;

            // Threshold proporcional ao capital
            $worstCaseFee = $capitalUsdc * (self::FEE_PERCENT * 2);
            $minProfit = max($capitalUsdc * 0.001, $worstCaseFee * 1.5);

            if ($expectedNetProfit < $minProfit) {
                $this->log(
                    "💸 Trade inviável: Capital=\$" . number_format($capitalUsdc, 4) .
                        " | Lucro bruto=\$" . number_format($expectedGrossProfit, 6) .
                        " | Fees=\$" . number_format($totalFees, 6) .
                        " | Lucro líquido=\$" . number_format($expectedNetProfit, 6) .
                        " | Mínimo=\$" . number_format($minProfit, 4),
                    'WARNING',
                    'TRADE'
                );
                return false;
            }

            return true;
        } catch (Exception $e) {
            $this->log("Erro na validação de viabilidade: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return true; // Em caso de erro, permitir (conservador)
        }
    }

    /**
     * Rotação de logs: remove logs com mais de LOG_RETENTION_DAYS dias
     * Previne overflow de disco no servidor
     */
    private function rotateOldLogs(): void
    {
        try {
            $basePath = $this->logPath ?: rtrim(sys_get_temp_dir(), '/') . '/';
            $logFiles = [
                $basePath . self::ERROR_LOG,
                $basePath . self::API_LOG,
                $basePath . self::TRADE_LOG
            ];

            $maxAgeSeconds = self::LOG_RETENTION_DAYS * 86400;

            foreach ($logFiles as $logFile) {
                if (!file_exists($logFile)) {
                    continue;
                }

                $fileAge = time() - filemtime($logFile);

                if ($fileAge > $maxAgeSeconds) {
                    // Arquivo mais antigo que o limite → arquivar e limpar
                    $archivePath = $logFile . '.' . date('Y-m-d') . '.bak';
                    @rename($logFile, $archivePath);
                    $this->log(
                        "📁 Log rotacionado: " . basename($logFile) . " → " . basename($archivePath),
                        'INFO',
                        'SYSTEM'
                    );
                }
            }

            // Limpar backups antigos (mais de 2× retention)
            $backupPattern = $basePath . '*.bak';
            $backups = glob($backupPattern);
            if ($backups) {
                foreach ($backups as $backup) {
                    $backupAge = time() - filemtime($backup);
                    if ($backupAge > ($maxAgeSeconds * 2)) {
                        @unlink($backup);
                    }
                }
            }
        } catch (Exception $e) {
            // Silenciar erros de rotação para não impactar o bot
        }
    }

    // ========== MÉTODOS DE BANCO DE DADOS ==========

    /**
     * Obtém um grid ativo (em cache ou do banco)
     */
    private function getActiveGrid(string $symbol): ?array
    {
        if (isset($this->activeGrids[$symbol])) {
            return $this->activeGrids[$symbol];
        }

        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter([
                "active = 'yes'",
                "symbol = '{$symbol}'",
                "status = 'active'"
            ]);
            $gridsModel->load_data();

            if (!empty($gridsModel->data)) {
                $result = $gridsModel->data[0];
                $this->activeGrids[$symbol] = $result;
                return $result;
            }

            return null;
        } catch (Exception $e) {
            $this->log("Erro ao buscar grid ativo: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return null;
        }
    }

    /**
     * Verifica se existe grid cancelado com stop-loss acionado
     * Impede criação de novo grid até que usuário use "Religar Bot"
     *
     * @param string $symbol Símbolo a verificar
     * @return bool true se existe stop-loss ativo (bloqueio)
     */
    private function hasStopLossTriggered(string $symbol): bool
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter([
                "active = 'yes'",
                "symbol = '{$symbol}'",
                "status = 'cancelled'",
                "stop_loss_triggered = 'yes'"
            ]);
            $gridsModel->load_data();

            return !empty($gridsModel->data);
        } catch (Exception $e) {
            $this->log("Erro ao verificar stop-loss: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return false; // Em caso de erro, não bloquear
        }
    }

    /**
     * Obtém um grid por ID
     */
    private function getGridById(int $gridId): ?array
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter([
                "active = 'yes'",
                "idx = '{$gridId}'"
            ]);
            $gridsModel->load_data();

            return !empty($gridsModel->data) ? $gridsModel->data[0] : null;
        } catch (Exception $e) {
            $this->log("Erro ao buscar grid por ID: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return null;
        }
    }

    /**
     * Salva a configuração de um novo grid no banco
     * Inclui campos de proteção: initial_capital, peak_capital, flags de stop
     */
    private function saveGridConfig(
        string $symbol,
        float $gridMin,
        float $gridMax,
        float $centerPrice,
        float $capitalAllocated,
        float $capitalPerLevel,
        float $lastUsdcBalance
    ): int {
        try {
            $usersModel = new users_model();
            $usersModel->set_filter(["active = 'yes'", "enabled = 'yes'"]);
            $usersModel->load_data();

            if (empty($usersModel->data)) {
                throw new Exception("Nenhum usuário ativo encontrado para criar grid");
            }

            $userId = (int)$usersModel->data[0]['idx'];
            if ($userId <= 0) {
                throw new Exception("Usuário inválido para criar grid");
            }

            $gridsModel = new grids_model();
            $gridsModel->populate([
                'users_id' => $userId,
                'symbol' => $symbol,
                'status' => 'active',
                'grid_levels' => self::GRID_LEVELS,
                'lower_price' => $gridMin,
                'upper_price' => $gridMax,
                'grid_spacing_percent' => self::GRID_SPACING_PERCENT,
                'capital_allocated_usdc' => $capitalAllocated,
                'capital_per_level' => $capitalPerLevel,
                'accumulated_profit_usdc' => 0.0,
                'current_price' => $centerPrice,
                'initial_capital_usdc' => $capitalAllocated,
                'peak_capital_usdc' => $capitalAllocated,
                'current_capital_usdc' => $capitalAllocated,
                'last_usdc_balance_usdc' => $lastUsdcBalance,
                'stop_loss_triggered' => 'no',
                'trailing_stop_triggered' => 'no',
                'is_processing' => 'no',
            ]);

            $gridId = $gridsModel->save();

            if (!$gridId) {
                throw new Exception("Falha ao salvar grid config: save() retornou vazio");
            }

            try {
                $redis = RedisCache::getInstance();
                if ($redis) {
                    $redis->deletePattern('*grids*');
                    $redis->deletePattern('*dashboard*');
                }
            } catch (Exception $cacheEx) {
                $this->log("Erro ao limpar cache após criar grid: " . $cacheEx->getMessage(), 'WARNING', 'SYSTEM');
            }

            $this->log(
                "📊 Grid #$gridId criado com proteções: " .
                    "Stop-Loss=" . (self::ENABLE_STOP_LOSS ? 'ON' : 'OFF') . " (" . (self::MAX_DRAWDOWN_PERCENT * 100) . "%) | " .
                    "Trailing=" . (self::ENABLE_TRAILING_STOP ? 'ON' : 'OFF') . " (" . (self::TRAILING_STOP_PERCENT * 100) . "%) | " .
                    "Capital inicial: \$" . number_format($capitalAllocated, 2),
                'INFO',
                'SYSTEM'
            );

            return $gridId;
        } catch (Exception $e) {
            $this->log("Erro ao salvar config de grid: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            throw $e;
        }
    }

    /**
     * Salva uma ordem de grid no banco com relacionamento many-to-many
     */
    private function saveGridOrder(array $orderData): ?int
    {
        try {
            // 1. Salvar ordem na tabela orders
            $ordersModel = new orders_model();
            $ordersModel->populate([
                'binance_order_id' => $orderData['binance_order_id'],
                'binance_client_order_id' => $orderData['binance_client_order_id'],
                'symbol' => $orderData['symbol'],
                'side' => $orderData['side'],
                'type' => $orderData['type'],
                'price' => $orderData['price'],
                'quantity' => $orderData['quantity'],
                'executed_qty' => 0.0,
                'status' => $orderData['status'],
                'order_created_at' => $orderData['order_created_at']
            ]);

            $orderId = $ordersModel->save();

            if (!$orderId) {
                throw new Exception("Falha ao salvar ordem na tabela orders");
            }

            // 2. Criar relacionamento em grids_orders
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersFields = [
                'grids_id' => $orderData['grids_id'],
                'orders_id' => $orderId,
                'grid_level' => $orderData['grid_level'],
                'paired_order_id' => $orderData['paired_order_id'] ?? null,
                'is_processed' => 'no'
            ];
            if (!empty($orderData['is_sliding_level'])) {
                $gridsOrdersFields['is_sliding_level'] = 1;
            }
            if (!empty($orderData['original_cost_price'])) {
                $gridsOrdersFields['original_cost_price'] = $orderData['original_cost_price'];
            }
            $gridsOrdersModel->populate($gridsOrdersFields);

            $gridsOrdersId = $gridsOrdersModel->save();

            if (!$gridsOrdersId) {
                throw new Exception("Falha ao salvar relacionamento em grids_orders");
            }

            return $gridsOrdersId;
        } catch (Exception $e) {
            $this->log("Erro ao salvar ordem de grid: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return null;
        }
    }

    /**
     * Salva log de evento do grid
     */
    private function saveGridLog(
        int $gridId,
        string $eventType,
        string $status,
        string $message,
        array $metadata = []
    ): void {
        try {
            $gridsLogsModel = new grid_logs_model();
            $gridsLogsModel->populate([
                'grids_id' => $gridId,
                'event' => $eventType,
                'log_type' => $status,
                'message' => $message,
                'data' => json_encode($metadata)
            ]);

            $gridsLogsModel->save();
        } catch (Exception $e) {
            $this->log("Erro ao salvar log de grid: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Busca ordens executadas mas ainda não processadas pelo bot
     */
    private function getExecutedUnprocessedOrders(int $gridId): array
    {
        try {
            $gridsOrdersModel = new grids_orders_model();

            // Usar subconsulta no filter para buscar apenas grids_orders deste grid não processadas
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "idx IN (SELECT idx FROM grids_orders WHERE active = 'yes' AND grids_id = '{$gridId}' AND is_processed = 'no')"
            ]);

            // Carregar dados ANTES do join
            $gridsOrdersModel->load_data();

            // JOIN para carregar os dados da tabela orders relacionada
            $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);

            $executedOrders = [];
            foreach ($gridsOrdersModel->data as $gridOrder) {
                // Verificar se a ordem relacionada está FILLED
                if (isset($gridOrder['orders_attach'][0]) && $gridOrder['orders_attach'][0]['status'] === 'FILLED') {
                    $order = $gridOrder['orders_attach'][0];

                    $executedOrders[] = [
                        'idx' => $order['idx'],
                        'grids_orders_idx' => $gridOrder['idx'],
                        'binance_order_id' => $order['binance_order_id'] ?? null,
                        'symbol' => $order['symbol'],
                        'side' => $order['side'],
                        'price' => $order['price'],
                        'executed_qty' => $order['executed_qty'],
                        'grid_level' => $gridOrder['grid_level'],
                        'paired_order_id' => $gridOrder['paired_order_id'],
                        'is_sliding_level' => $gridOrder['is_sliding_level'] ?? 0,
                        'original_cost_price' => $gridOrder['original_cost_price'] ?? null
                    ];
                }
            }

            $this->log("Encontradas " . count($executedOrders) . " ordens FILLED não processadas", 'INFO', 'SYSTEM');
            return $executedOrders;
        } catch (Exception $e) {
            $this->log("Erro ao buscar ordens executadas: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return [];
        }
    }

    /**
     * Marca uma ordem como processada
     */
    private function markOrderAsProcessed(int $gridsOrdersIdx): void
    {
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter(["idx = '{$gridsOrdersIdx}'"]);
            $gridsOrdersModel->populate(['is_processed' => 'yes']);
            $gridsOrdersModel->save();
        } catch (Exception $e) {
            $this->log("Erro ao marcar ordem como processada: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Atualiza o lucro de uma ordem específica
     */
    private function updateOrderProfit(int $gridsOrdersIdx, float $profit): void
    {
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter(["idx = '{$gridsOrdersIdx}'"]);
            $gridsOrdersModel->populate(['profit_usdc' => $profit]);
            $gridsOrdersModel->save();
        } catch (Exception $e) {
            $this->log("Erro ao atualizar lucro da ordem: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Incrementa lucro acumulado do grid
     */
    private function incrementGridProfit(int $gridId, float $profit): void
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter(["idx = '{$gridId}'"]);
            $gridsModel->load_data();

            if (empty($gridsModel->data)) {
                throw new Exception("Grid não encontrado: {$gridId}");
            }

            $currentProfit = (float)($gridsModel->data[0]['accumulated_profit_usdc'] ?? 0);
            $newProfit = $currentProfit + $profit;

            // Resetar o model para fazer UPDATE
            $gridsModel = new grids_model();
            $gridsModel->set_filter(["idx = '{$gridId}'"]);
            $gridsModel->populate(['accumulated_profit_usdc' => $newProfit]);
            $gridsModel->save();
        } catch (Exception $e) {
            $this->log("Erro ao incrementar lucro do grid: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    private function resetAccumulatedProfit(int $gridId): void
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter(["idx = '{$gridId}'"]);
            $gridsModel->populate(['accumulated_profit_usdc' => 0.0]);
            $gridsModel->save();

            $this->saveGridLog($gridId, 'profit_reinvested', 'success', 'Lucro acumulado reinvestido em batch', [
                'reinvestment_threshold' => self::REINVESTMENT_THRESHOLD,
            ]);
        } catch (Exception $e) {
            $this->log("Erro ao zerar lucro acumulado: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Atualiza estatísticas do grid
     */
    private function updateGridStats(int $gridId): void
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter(["idx = '{$gridId}'"]);
            $gridsModel->populate(['last_checked_at' => date('Y-m-d H:i:s')]);
            $gridsModel->save();
        } catch (Exception $e) {
            $this->log("Erro ao atualizar estatísticas: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Atualiza status do grid
     */
    private function updateGridStatus(int $gridId, string $status): void
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter(["idx = '{$gridId}'"]);
            $gridsModel->populate(['status' => $status]);
            $gridsModel->save();
        } catch (Exception $e) {
            $this->log("Erro ao atualizar status do grid: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Cancela todas ordens abertas de um grid
     */
    private function cancelAllGridOrders(int $gridId): void
    {
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "grids_id = '{$gridId}'"
            ]);
            $gridsOrdersModel->attach(['orders']);
            $gridsOrdersModel->load_data();

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders'][0] ?? null;

                if ($order && in_array($order['status'], ['NEW', 'PARTIALLY_FILLED'])) {
                    try {
                        $this->client->deleteOrder($order['symbol'], $order['binance_order_id'], null, null, null, self::BINANCE_RECV_WINDOW);
                        $this->log("Ordem {$order['binance_order_id']} cancelada", 'INFO', 'TRADE');
                    } catch (Exception $e) {
                        $this->log("Erro ao cancelar ordem {$order['binance_order_id']}: " . $e->getMessage(), 'WARNING', 'TRADE');
                    }
                }
            }
        } catch (Exception $e) {
            $this->log("Erro ao cancelar ordens do grid: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Calcula ativo acumulado de compras executadas
     */
    private function getAccumulatedAsset(int $gridId, string $symbol): float
    {
        try {
            $baseAsset = str_replace('USDC', '', $symbol);
            $accountInfo = $this->getAccountInfo(true);

            return $this->getBalanceForAsset($accountInfo['balances'], $baseAsset);
        } catch (Exception $e) {
            $this->log("Erro ao calcular ativo acumulado: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return 0.0;
        }
    }
}
