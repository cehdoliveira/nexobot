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
 * Execução: CRON a cada 5 minutos
 */
class setup_controller
{
    // Configuração de símbolos e grid
    private const SYMBOLS = ['BTCUSDC'];
    private const GRID_LEVELS = 6;              // 6 níveis por grid
    private const GRID_RANGE_PERCENT = 0.05;     // ±5% do preço atual
    private const GRID_SPACING_PERCENT = 0.01;   // 1% entre níveis
    private const REBALANCE_THRESHOLD = 0.05;    // Rebalancear se sair 5% do range
    private const CAPITAL_ALLOCATION = 0.95;     // 95% do capital USDC disponível
    private const MIN_TRADE_USDC = 11;           // Mínimo por trade
    private const MAX_ALGO_ORDERS = 5;           // Limite Binance de ordens algorítmicas
    private const INITIAL_BTC_ALLOCATION = 0.30; // 30% do capital inicial convertido em BTC para ordens de venda superiores

    // Cache TTL
    private const CACHE_TTL_ACCOUNT_INFO = 5;    // 5 segundos para account info
    private const CACHE_TTL_EXCHANGE_INFO = 60;  // 60 segundos para exchange info

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

    public function __construct()
    {
        // Inicializar variáveis de cache para evitar deprecated warnings
        $this->accountInfoCache = null;
        $this->accountInfoCacheTime = 0;
        $this->exchangeInfoCache = [];
        
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
    private function initializeLogger(): void
    {
        // /var/log é mapeado para /opt/gridnexobot/logs no host (volume do Portainer)
        // Mesmo local onde cron.log é gravado pelo verify_entry.php
        $this->logPath = '/var/log/';
    }

    /**
     * Sistema de logging
     */
    private function log(string $message, string $level = 'ERROR', string $type = 'SYSTEM'): void
    {
        $basePath = $this->logPath ?: rtrim(sys_get_temp_dir(), '/') . '/';
        $logFile = match ($type) {
            'API' => $basePath . self::API_LOG,
            'TRADE' => $basePath . self::TRADE_LOG,
            default => $basePath . self::ERROR_LOG
        };

        $this->logBuffer[$logFile][] = sprintf(
            "[%s] [%s] [%s] - %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $type,
            $message
        );

        if (count($this->logBuffer[$logFile] ?? []) >= $this->logBufferSize) {
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

            $resp = $this->client->getAccount();
            $accountData = $resp->getData();
            $this->accountInfoCache = json_decode(json_encode($accountData), true);
            $this->accountInfoCacheTime = $now;
            return $this->accountInfoCache;
        } catch (Exception $e) {
            throw new Exception("Erro ao obter informações da conta: " . $e->getMessage());
        }
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
            
            foreach ($accountInfo['balances'] as $balance) {
                if ($balance['asset'] === 'USDC') {
                    return (float)$balance['free'];
                }
            }
            
            return 0.0;
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
    private function getCapitalForNewBuyOrder(int $gridId, array $gridData): float
    {
        try {
            $baseCapital = (float)$gridData['capital_per_level'];
            $accumulatedProfit = (float)($gridData['accumulated_profit_usdc'] ?? 0.0);
            
            // Distribui o lucro acumulado entre os 6 níveis do grid
            // Assim cada nova ordem recebe 1/6 do lucro total reinvestido
            $profitReinvestmentPerOrder = $accumulatedProfit / self::GRID_LEVELS;
            
            $capitalWithReinvestment = $baseCapital + $profitReinvestmentPerOrder;
            
            if ($profitReinvestmentPerOrder > 0) {
                $this->log(
                    "💰 Capital reinvestido para BUY: \$" . number_format($profitReinvestmentPerOrder, 2) . 
                    " (lucro acumulado: \$" . number_format($accumulatedProfit, 2) . ")",
                    'INFO',
                    'TRADE'
                );
            }
            
            return $capitalWithReinvestment;
        } catch (Exception $e) {
            $this->log("Erro ao calcular capital com reinvestimento: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return (float)$gridData['capital_per_level'];
        }
    }

    public function getExchangeInfo($symbol): array
    {
        try {
            // Cache em memória por CACHE_TTL_EXCHANGE_INFO segundos (padrão 60s)
            if (isset($this->exchangeInfoCache[$symbol])) {
                return $this->exchangeInfoCache[$symbol];
            }

            $url = "https://api.binance.com/api/v3/exchangeInfo?symbol={$symbol}";
            $response = file_get_contents($url);

            if ($response === false) {
                throw new Exception("Erro ao acessar a API da Binance (exchangeInfo).");
            }

            $exchangeData = json_decode($response, true);
            if (!isset($exchangeData['symbols'][0])) {
                throw new Exception("Símbolo {$symbol} não encontrado na API da Binance.");
            }

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

        return [
            $filters['LOT_SIZE']['stepSize'],
            $filters['PRICE_FILTER']['tickSize'],
            $minNotional
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
     * Método principal de execução
     * Ponto de entrada do bot que é chamado via cron
     */
    public function display(): void
    {
        try {
            $startTime = microtime(true);
            $this->log("=== Grid Trading Bot INICIADO ===", 'INFO', 'SYSTEM');

            // 1. Carregar capital USDC disponível
            $this->loadCapitalInfo();

            // 2. Processar cada símbolo
            foreach (self::SYMBOLS as $symbol) {
                try {
                    $this->log("--- Processando $symbol ---", 'INFO', 'TRADE');
                    $this->processSymbol($symbol);
                } catch (Exception $e) {
                    $this->log("Erro ao processar $symbol: " . $e->getMessage(), 'ERROR', 'TRADE');
                    continue;
                }
            }

            // 3. Estatísticas finais
            $execTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->log("=== Grid Trading Bot FINALIZADO em {$execTime}ms ===", 'INFO', 'SYSTEM');
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

            // 3. Desativar ordens no banco
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter(["grids_id = '{$gridId}'"]);
            $gridsOrdersModel->load_data();

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $model = new grids_orders_model();
                $model->set_filter(["idx = '{$gridOrder['idx']}'"]);
                $model->populate(['active' => 'no']);
                $model->save();
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
     */
    private function processSymbol(string $symbol): void
    {
        try {
            // 1. Verificar se já existe grid ativo
            $activeGrid = $this->getActiveGrid($symbol);

            if ($activeGrid) {
                // Grid existe → Sincronizar ordens e depois monitorar
                // (não depende de saldo USDC livre — o capital já está alocado em ordens/BTC)
                $this->syncOrdersWithBinance($activeGrid['idx']);
                $this->monitorGrid($activeGrid);
            } else {
                // Grid não existe → verificar capital ANTES de criar novo
                if ($this->totalCapital < self::MIN_TRADE_USDC) {
                    $this->log(
                        "Capital USDC insuficiente para criar novo grid em $symbol: {$this->totalCapital} USDC (mínimo: " . self::MIN_TRADE_USDC . " USDC)",
                        'WARNING',
                        'SYSTEM'
                    );
                    return;
                }
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
                    $response = $this->client->getOrder($order['symbol'], $order['binance_order_id']);
                    $binanceOrder = $response->getData();

                    $newStatus = method_exists($binanceOrder, 'getStatus')
                        ? $binanceOrder->getStatus()
                        : ($binanceOrder['status'] ?? null);

                    $executedQty = method_exists($binanceOrder, 'getExecutedQty')
                        ? $binanceOrder->getExecutedQty()
                        : ($binanceOrder['executedQty'] ?? 0);

                    // Atualizar ordem se status mudou
                    if ($newStatus && $newStatus !== $order['status']) {
                        $ordersModel = new orders_model();
                        $ordersModel->set_filter(["idx = '{$order['idx']}'"]);
                        $ordersModel->populate([
                            'status' => $newStatus,
                            'executed_qty' => (float)$executedQty
                        ]);
                        $ordersModel->save();

                        $updatedCount++;

                        $this->log(
                            "Ordem {$order['binance_order_id']} atualizada: {$order['status']} → {$newStatus}",
                            'INFO',
                            'API'
                        );
                    }
                } catch (Exception $e) {
                    // Silencioso para evitar spam de logs (ex: timestamp errors)
                    continue;
                }
            }

            if ($updatedCount > 0) {
                $this->log("$updatedCount ordem(ns) sincronizada(s) com a Binance", 'INFO', 'SYSTEM');
            }
        } catch (Exception $e) {
            $this->log("Erro ao sincronizar ordens: " . $e->getMessage(), 'ERROR', 'SYSTEM');
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
            $this->log(
                "[CancelObsoleteOrders] Buscando ordens abertas na Binance para $symbol...",
                'INFO',
                'SYSTEM'
            );

            // Buscar ordens ABERTAS na Binance
            $openOrders = $this->client->openOrders(['symbol' => $symbol]);
            
            if (empty($openOrders)) {
                $this->log(
                    "[CancelObsoleteOrders] Nenhuma ordem aberta encontrada na Binance para $symbol",
                    'INFO',
                    'SYSTEM'
                );
                return;
            }

            $this->log(
                "[CancelObsoleteOrders] Encontradas " . count($openOrders) . " ordens abertas na Binance",
                'INFO',
                'SYSTEM'
            );

            $canceledCount = 0;

            foreach ($openOrders as $binanceOrder) {
                try {
                    $binanceOrderId = $binanceOrder['orderId'];
                    $side = $binanceOrder['side'];
                    $status = $binanceOrder['status'];

                    // Buscar ordem no banco
                    $ordersModel = new orders_model();
                    $ordersModel->set_filter(["binance_order_id = '{$binanceOrderId}'"]);
                    $ordersModel->load_data();

                    if (count($ordersModel->data) === 0) {
                        $this->log(
                            "[CancelObsoleteOrders] Ordem Binance ID $binanceOrderId não encontrada no banco. Cancelando...",
                            'WARNING',
                            'SYSTEM'
                        );
                        
                        // Cancelar ordem órfã na Binance
                        $this->client->cancelOrder([
                            'symbol' => $symbol,
                            'orderId' => $binanceOrderId
                        ]);
                        
                        $canceledCount++;
                        continue;
                    }

                    $dbOrder = $ordersModel->data[0];

                    // Se no banco está FILLED/CANCELED mas na Binance está NEW, cancelar
                    if (in_array($dbOrder['status'], ['FILLED', 'CANCELED', 'EXPIRED']) 
                        && $status === 'NEW') {
                        
                        $this->log(
                            "[CancelObsoleteOrders] Ordem ID={$dbOrder['idx']} (Binance ID=$binanceOrderId) " .
                            "está NEW na Binance mas {$dbOrder['status']} no banco. Cancelando...",
                            'WARNING',
                            'SYSTEM'
                        );

                        // Cancelar na Binance
                        $this->client->cancelOrder([
                            'symbol' => $symbol,
                            'orderId' => $binanceOrderId
                        ]);

                        // Atualizar status no banco
                        $ordersModel->load_byIdx($dbOrder['idx']);
                        $ordersModel->populate(['status' => 'CANCELED']);
                        $ordersModel->save();

                        $canceledCount++;
                    }
                } catch (Exception $e) {
                    $this->log(
                        "[CancelObsoleteOrders] Erro ao processar ordem: " . $e->getMessage(),
                        'ERROR',
                        'SYSTEM'
                    );
                    continue;
                }
            }

            if ($canceledCount > 0) {
                $this->log(
                    "✅ $canceledCount ordem(ns) obsoleta(s) cancelada(s)",
                    'SUCCESS',
                    'SYSTEM'
                );
            } else {
                $this->log(
                    "[CancelObsoleteOrders] Nenhuma ordem obsoleta encontrada",
                    'INFO',
                    'SYSTEM'
                );
            }
        } catch (Exception $e) {
            $this->log(
                "Erro ao cancelar ordens obsoletas: " . $e->getMessage(),
                'ERROR',
                'SYSTEM'
            );
        }
    }

    /**
     * Prepara o capital inicial: 70% USDC (compras) + 30% BTC (vendas superiores)
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

            foreach ($accountInfo['balances'] as $balance) {
                if ($balance['asset'] === 'USDC') {
                    $usdcBalance = (float)$balance['free'];
                }
                if ($balance['asset'] === $baseAsset) {
                    $btcBalance = (float)$balance['free'];
                }
            }

            $currentPrice  = $this->getCurrentPrice($symbol);
            $totalCapital  = $usdcBalance + ($btcBalance * $currentPrice);

            // Quanto BTC devemos ter (30% do capital total)
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
                foreach ($accountInfo['balances'] as $balance) {
                    if ($balance['asset'] === 'USDC') {
                        $usdcBalance = (float)$balance['free'];
                    }
                    if ($balance['asset'] === $baseAsset) {
                        $btcBalance = (float)$balance['free'];
                    }
                }

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
            list($stepSize, $tickSize, $minNotional) = $this->extractFilters($symbolData);

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

            $response  = $this->client->newOrder($orderReq);
            $orderData = $response->getData();

            $executedQty = method_exists($orderData, 'getExecutedQty')
                ? $orderData->getExecutedQty()
                : ($orderData['executedQty'] ?? $adjustedQty);

            $cumulativeQuote = method_exists($orderData, 'getCummulativeQuoteQty')
                ? (float)$orderData->getCummulativeQuoteQty()
                : (float)($orderData['cummulativeQuoteQty'] ?? 0.0);
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
            // 0. PREPARAR CAPITAL INICIAL (30% BTC + 70% USDC)
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
                $capitalPerBuyLevel
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
                        $capitalPerBuyLevel
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
                'total_capital_usd'   => $totalCapital
            ]);
        } catch (Exception $e) {
            throw new Exception("Erro ao criar grid híbrido para $symbol: " . $e->getMessage());
        }
    }

    /**
     * Monitora um grid existente: processa ordens executadas e rebalanceia se necessário
     */
    private function monitorGrid(array $gridData): void
    {
        try {
            $symbol = $gridData['symbol'];
            $gridId = $gridData['idx'];

            // 0. SINCRONIZAR STATUS DAS ORDENS COM A BINANCE
            $this->syncOrdersWithBinance($gridId);

            // 0.5 RECUPERAR BTC ÓRFÃO (sem venda pareada)
            $this->recoverOrphanedBtc($gridId, $symbol, $gridData);

            // 1. BUSCAR ORDENS EXECUTADAS MAS NÃO PROCESSADAS
            $executedOrders = $this->getExecutedUnprocessedOrders($gridId);

            if (count($executedOrders) > 0) {
                $this->log("Processando " . count($executedOrders) . " ordens executadas no grid $gridId", 'INFO', 'TRADE');
            }

            foreach ($executedOrders as $order) {
                try {
                    if ($order['side'] === 'BUY') {
                        // COMPRA EXECUTADA → Criar ordem de VENDA acima
                        $this->handleBuyOrderFilled($gridId, $order);
                    } elseif ($order['side'] === 'SELL') {
                        // VENDA EXECUTADA → Criar ordem de COMPRA abaixo + calcular lucro
                        $this->handleSellOrderFilled($gridId, $order);
                    }

                    // Marcar como processada SOMENTE se não houve exceção
                    // Se handleBuyOrderFilled falhar (ex: minNotional), a exceção
                    // chegará aqui e a ordem NÃO será marcada — será retentada na próxima CRON
                    $this->markOrderAsProcessed($order['grids_orders_idx']);
                } catch (Exception $e) {
                    $this->log(
                        "Erro ao processar ordem {$order['idx']} (será retentada): " . $e->getMessage(),
                        'ERROR',
                        'TRADE'
                    );
                    $this->saveGridLog(
                        $gridId,
                        'order_processing_error',
                        'error',
                        "Erro ao processar ordem (retentando na próxima rodada): " . $e->getMessage()
                    );
                }
            }

            // 2. VERIFICAR SE PREÇO SAIU DO RANGE (REBALANCE)
            $currentPrice = $this->getCurrentPrice($symbol);
            $needsRebalance = $this->checkRebalanceNeeded($gridData, $currentPrice);

            if ($needsRebalance) {
                $this->log("Iniciando rebalanceamento de grid para $symbol", 'WARNING', 'TRADE');
                $this->rebalanceGrid($gridId, $symbol, $currentPrice);
            }

            // 3. ATUALIZAR ESTATÍSTICAS DO GRID
            $this->updateGridStats($gridId);
        } catch (Exception $e) {
            throw new Exception("Erro ao monitorar grid: " . $e->getMessage());
        }
    }

    /**
     * Recupera BTC "órfão": identifica BUYs executadas sem SELL pareada
     * e cria SELLs para elas utilizando o preço de mercado atual
     * 
     * @param int $gridId ID do grid
     * @param string $symbol Par de negociação (ex: BTCUSDC)
     * @param array $gridData Dados atuais do grid
     */
    private function recoverOrphanedBtc(int $gridId, string $symbol, array $gridData): void
    {
        try {
            $baseAsset = str_replace('USDC', '', $symbol);
            
            // 1. BUSCAR COMPRAS EXECUTADAS SEM VENDA PAREADA
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "grids_id = '{$gridId}'"
            ]);
            $gridsOrdersModel->load_data();
            $gridsOrdersModel->join('orders', 'orders', ['idx' => 'orders_id']);

            $totalGridOrders = count($gridsOrdersModel->data);
            $filledBuysCount = 0;
            $alreadyPairedCount = 0;
            $orphanedBuys = [];

            $this->log(
                "[RecoverOrphanedBtc] Analisando {$totalGridOrders} grids_orders do grid $gridId",
                'INFO',
                'SYSTEM'
            );

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders_attach'][0] ?? null;

                if (!$order) {
                    continue;
                }

                // VERIFICAR SE É UMA COMPRA EXECUTADA
                if ($order['side'] === 'BUY' && $order['status'] === 'FILLED') {
                    $filledBuysCount++;
                    $buyQty = (float)$order['executed_qty'];
                    
                    // VERIFICAR SE JÁ EXISTE VENDA PAREADA
                    $pairedSellInfo = $this->getPairedSellInfo($gridOrder['idx']);
                    
                    if ($pairedSellInfo) {
                        $sellQty = (float)$pairedSellInfo['executed_qty'];
                        $sellStatus = $pairedSellInfo['status'];
                        
                        // VERIFICAR SE A SELL PAREADA EXECUTOU MENOS QUE O COMPRADO
                        if (in_array($sellStatus, ['FILLED']) && $sellQty < $buyQty) {
                            $orphanedQty = round($buyQty - $sellQty, 8); // Arredondar para 8 decimais
                            $this->log(
                                "⚠️ BTC órfão detectado: BUY idx={$gridOrder['idx']} comprou $buyQty mas SELL vendeu apenas $sellQty. " .
                                "Órfão: " . number_format($orphanedQty, 8) . " $baseAsset",
                                'WARNING',
                                'SYSTEM'
                            );
                            
                            // BTC ÓRFÃO DETECTADO (diferença entre comprado e vendido)
                            $orphanedBuys[] = [
                                'grids_orders_idx' => $gridOrder['idx'],
                                'grid_level' => $gridOrder['grid_level'],
                                'buy_price' => (float)$order['price'],
                                'executed_qty' => $orphanedQty, // Apenas a diferença (já arredondada)
                                'symbol' => $order['symbol']
                            ];
                            continue;
                        }
                        
                        $alreadyPairedCount++;
                        continue;
                    }

                    // BTC ÓRFÃO ENCONTRADO (compra sem venda pareada)!
                    $orphanedBuys[] = [
                        'grids_orders_idx' => $gridOrder['idx'],
                        'grid_level' => $gridOrder['grid_level'],
                        'buy_price' => (float)$order['price'],
                        'executed_qty' => (float)$order['executed_qty'],
                        'symbol' => $order['symbol']
                    ];
                }
            }

            $this->log(
                "[RecoverOrphanedBtc] {$filledBuysCount} BUY FILLED, {$alreadyPairedCount} já pareadas, " .
                count($orphanedBuys) . " órfãs detectadas",
                'INFO',
                'SYSTEM'
            );

            if (empty($orphanedBuys)) {
                return; // Nenhum BTC órfão
            }

            $this->log(
                "🔍 Recuperando " . count($orphanedBuys) . " BTC órfão(s) sem venda pareada no grid $gridId",
                'WARNING',
                'TRADE'
            );

            // 2. VERIFICAR SALDO REAL DE BTC DISPONÍVEL
            try {
                $accountInfo = $this->getAccountInfo(true);
                $freeBtc = 0.0;
                $lockedBtc = 0.0;

                foreach ($accountInfo['balances'] as $balance) {
                    if ($balance['asset'] === $baseAsset) {
                        $freeBtc = (float)$balance['free'];
                        $lockedBtc = (float)$balance['locked'];
                        break;
                    }
                }

                $totalOrphanedQty = array_sum(array_column($orphanedBuys, 'executed_qty'));

                $this->log(
                    "[RecoverOrphanedBtc] Saldo $baseAsset - Livre: " . number_format($freeBtc, 8) . 
                    " | Bloqueado: " . number_format($lockedBtc, 8) . 
                    " | Órfão necessário: " . number_format($totalOrphanedQty, 8),
                    'INFO',
                    'SYSTEM'
                );

                if ($freeBtc < $totalOrphanedQty) {
                    $this->log(
                        "⚠️ Saldo livre insuficiente! Livre=$freeBtc, Necessário=$totalOrphanedQty. " .
                        "Há " . number_format($lockedBtc, 8) . " $baseAsset bloqueado em outras ordens. " .
                        "Sincronizando ordens abertas com Binance...",
                        'WARNING',
                        'SYSTEM'
                    );
                    
                    // Tentar liberar BTC cancelando ordens obsoletas
                    $this->cancelObsoleteOrders($gridId, $symbol);
                    
                    // Re-verificar saldo após cancelamento
                    $accountInfo = $this->getAccountInfo(true);
                    foreach ($accountInfo['balances'] as $balance) {
                        if ($balance['asset'] === $baseAsset) {
                            $freeBtc = (float)$balance['free'];
                            break;
                        }
                    }
                    
                    $this->log(
                        "[RecoverOrphanedBtc] Saldo após cancelamento: " . number_format($freeBtc, 8) . " $baseAsset",
                        'INFO',
                        'SYSTEM'
                    );
                }
            } catch (Exception $e) {
                $this->log(
                    "❌ Erro ao verificar saldo de $baseAsset: " . $e->getMessage(),
                    'ERROR',
                    'TRADE'
                );
                return;
            }

            // 3. OBTER PREÇO ATUAL
            try {
                $currentPrice = $this->getCurrentPrice($symbol);
                $this->log(
                    "[RecoverOrphanedBtc] Preço atual de $symbol: $" . number_format($currentPrice, 2),
                    'INFO',
                    'SYSTEM'
                );
            } catch (Exception $e) {
                $this->log(
                    "❌ Erro ao obter preço atual de $symbol: " . $e->getMessage(),
                    'ERROR',
                    'TRADE'
                );
                return;
            }

            try {
                $gridSpacing = $this->getGridSpacing($symbol);
                $this->log(
                    "[RecoverOrphanedBtc] Grid spacing: " . ($gridSpacing * 100) . "%",
                    'INFO',
                    'SYSTEM'
                );
            } catch (Exception $e) {
                $this->log(
                    "❌ Erro ao obter grid spacing: " . $e->getMessage(),
                    'ERROR',
                    'TRADE'
                );
                return;
            }

            // 3. CRIAR SELL PARA CADA BTC ÓRFÃO
            $successCount = 0;

            $this->log(
                "[RecoverOrphanedBtc] Iniciando criação de " . count($orphanedBuys) . " ordens de recuperação...",
                'INFO',
                'SYSTEM'
            );

            foreach ($orphanedBuys as $orphan) {
                try {
                    // ARREDONDAR quantity para evitar problemas de precisão flutuante
                    $orphanQty = round((float)$orphan['executed_qty'], 8);

                    $this->log(
                        "[RecoverOrphanedBtc] Processando órfão: grids_orders_idx={$orphan['grids_orders_idx']}, " .
                        "level={$orphan['grid_level']}, qty=$orphanQty",
                        'INFO',
                        'SYSTEM'
                    );

                    // Verificar se temos saldo livre suficiente para ESTA ordem
                    if ($freeBtc < $orphanQty) {
                        $this->log(
                            "⚠️ Saldo livre insuficiente para órfão idx={$orphan['grids_orders_idx']}. " .
                            "Necessário: $orphanQty, Disponível: $freeBtc. Pulando...",
                            'WARNING',
                            'SYSTEM'
                        );
                        continue;
                    }

                    // Calcular preço de venda (acima do preço atual, 1 grid spacing)
                    $sellPrice = $currentPrice * (1 + $gridSpacing);

                    $this->log(
                        "[RecoverOrphanedBtc] Criando SELL: price=$sellPrice, qty=$orphanQty, " .
                        "paired_to={$orphan['grids_orders_idx']}",
                        'INFO',
                        'SYSTEM'
                    );

                    // Criar ordem de venda pareada com o BTC órfão
                    $sellOrderId = $this->placeSellOrder(
                        $gridId,
                        $symbol,
                        $orphan['grid_level'],
                        $sellPrice,
                        $orphanQty,  // Usar valor arredondado
                        $orphan['grids_orders_idx']  // Parear com a BUY órfã
                    );

                    if ($sellOrderId) {
                        $successCount++;
                        $freeBtc -= $orphanQty; // Descontar do saldo disponível
                        
                        $this->log(
                            "✅ Venda de recuperação criada: Nível {$orphan['grid_level']} | " .
                            "Qty: " . number_format($orphanQty, 8) . " $baseAsset @ $" . 
                            number_format($sellPrice, 2) . " | Pareada com BUY idx={$orphan['grids_orders_idx']}",
                            'SUCCESS',
                            'TRADE'
                        );
                    } else {
                        $this->log(
                            "❌ Falha ao criar venda de recuperação para nivel {$orphan['grid_level']} (sellOrderId=null)",
                            'ERROR',
                            'TRADE'
                        );
                    }
                } catch (Exception $e) {
                    $this->log(
                        "❌ Erro ao recuperar BTC órfão (Nível {$orphan['grid_level']}): " . $e->getMessage(),
                        'ERROR',
                        'TRADE'
                    );
                }
            }

            if ($successCount > 0) {
                $this->log(
                    "💰 Recuperação de BTC concluída: $successCount venda(s) de recuperação criada(s)",
                    'SUCCESS',
                    'TRADE'
                );

                $this->saveGridLog(
                    $gridId,
                    'orphaned_btc_recovered',
                    'success',
                    "$successCount BTC órfão(s) recuperado(s) com sucesso",
                    [
                        'orphaned_count' => count($orphanedBuys),
                        'recovered_count' => $successCount,
                        'symbol' => $symbol
                    ]
                );
            }
        } catch (Exception $e) {
            $this->log(
                "Erro ao recuperar BTC órfão: " . $e->getMessage(),
                'ERROR',
                'TRADE'
            );
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
            $availableBtc = 0.0;

            foreach ($accountInfo['balances'] as $balance) {
                if ($balance['asset'] === $baseAsset) {
                    $availableBtc = (float)$balance['free'];
                    break;
                }
            }

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
                    
                    // Se FILLED mas is_processed=no, o BTC foi vendido mas não foi processado
                    // Neste caso, NÃO há BTC órfão (foi vendido), mas precisa processar a SELL
                    if ($order['status'] === 'FILLED' && $gridOrder['is_processed'] === 'no') {
                        $this->log(
                            "[WARN] SELL FILLED mas não processada! order_id={$order['idx']}, " .
                            "paired_to_buy=$buyGridOrderIdx. BTC JÁ FOI VENDIDO.",
                            'WARNING',
                            'SYSTEM'
                        );
                        return true; // BTC não está órfão (foi vendido)
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

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders_attach'][0] ?? null;

                if ($order && $order['side'] === 'SELL') {
                    // Retornar info da ordem SELL
                    return [
                        'grids_orders_idx' => $gridOrder['idx'],
                        'order_id' => $order['idx'],
                        'status' => $order['status'],
                        'price' => $order['price'],
                        'executed_qty' => $order['executed_qty'] ?? 0,
                        'is_processed' => $gridOrder['is_processed']
                    ];
                }
            }

            return null; // Não encontrou SELL pareada
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
                $buyValue  = $executedQty * $buyPrice;
                $sellValue = $executedQty * $sellPrice;
                $buyFee    = $buyValue  * 0.001;
                $sellFee   = $sellValue * 0.001;
                $profit    = $sellValue - $buyValue - $buyFee - $sellFee;

                // Salvar lucro na ordem de venda
                $this->updateOrderProfit($sellOrder['grids_orders_idx'], $profit);

                // Atualizar lucro acumulado do grid
                $this->incrementGridProfit($gridId, $profit);

                $this->log(
                    "PAR COMPLETO em $symbol: Lucro = $" . number_format($profit, 4) . " (Compra: \$$buyPrice | Venda: \$$sellPrice)",
                    'SUCCESS',
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
                    $costValue = $executedQty * $btcCostPrice;
                    $sellValue = $executedQty * $sellPrice;
                    $costFee   = $costValue * 0.001; // fee na compra inicial do BTC
                    $sellFee   = $sellValue * 0.001;
                    $profit    = $sellValue - $costValue - $costFee - $sellFee;

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

            // Calcular capital com reinvestimento de lucros
            $capitalWithReinvestment = $this->getCapitalForNewBuyOrder($gridId, $gridData);

            // Validar se há USDC disponível antes de tentar colocar a ordem
            $availableUsdc = $this->getAvailableUsdcBalance(true);

            if ($availableUsdc < $capitalWithReinvestment) {
                $this->log(
                    "⚠️ Saldo USDC insuficiente para nova ordem BUY no nível {$sellOrder['grid_level']}: " .
                    "disponível $availableUsdc USDC, requerido $capitalWithReinvestment USDC",
                    'WARNING',
                    'TRADE'
                );
            } else {
                $newBuyOrderId = $this->placeBuyOrder(
                    $gridId,
                    $symbol,
                    $sellOrder['grid_level'],
                    $buyPrice,
                    $capitalWithReinvestment
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
     */
    private function rebalanceGrid(int $gridId, string $symbol, float $newCenterPrice): void
    {
        try {
            $this->log("REBALANCEAMENTO iniciado para $symbol (novo centro: $newCenterPrice)", 'WARNING', 'TRADE');

            // 1. CANCELAR TODAS ORDENS ABERTAS DO GRID
            $this->cancelAllGridOrders($gridId);

            // 2. VENDER TODO ATIVO ACUMULADO A MERCADO
            $accumulatedAsset = $this->getAccumulatedAsset($gridId, $symbol);
            if ($accumulatedAsset > 0) {
                $this->sellAssetAtMarket($symbol, $accumulatedAsset, $gridId);
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
     * Coloca uma ordem de compra LIMIT na Binance
     */
    private function placeBuyOrder(
        int $gridId,
        string $symbol,
        int $gridLevel,
        float $price,
        float $capitalUsdc
    ): ?int {
        try {
            // 1. Obter filtros do símbolo
            $symbolData = $this->getExchangeInfo($symbol);
            list($stepSize, $tickSize, $minNotional) = $this->extractFilters($symbolData);

            // 2. Ajustar preço ao tickSize
            $adjustedPrice = $this->adjustPriceToTickSize($price, $tickSize);

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

            $response = $this->client->newOrder($orderReq);
            $orderData = $response->getData();

            $binanceOrderId = method_exists($orderData, 'getOrderId')
                ? $orderData->getOrderId()
                : ($orderData['orderId'] ?? null);

            $binanceClientOrderId = method_exists($orderData, 'getClientOrderId')
                ? $orderData->getClientOrderId()
                : ($orderData['clientOrderId'] ?? null);

            $status = method_exists($orderData, 'getStatus')
                ? $orderData->getStatus()
                : ($orderData['status'] ?? 'UNKNOWN');

            // 6. Salvar ordem no banco
            $orderDbId = $this->saveGridOrder([
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
            ]);

            $this->log("Ordem BUY criada: $symbol @ $adjustedPrice (Qty: $quantity, Nível: $gridLevel)", 'INFO', 'TRADE');

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
        ?int $pairedBuyOrderId = null
    ): ?int {
        try {
            // 1. Obter filtros do símbolo
            $symbolData = $this->getExchangeInfo($symbol);
            list($stepSize, $tickSize, $minNotional) = $this->extractFilters($symbolData);

            // 2. Ajustar preço ao tickSize
            $adjustedPrice = $this->adjustPriceToTickSize($price, $tickSize);

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

            $response = $this->client->newOrder($orderReq);
            $orderData = $response->getData();

            $binanceOrderId = method_exists($orderData, 'getOrderId')
                ? $orderData->getOrderId()
                : ($orderData['orderId'] ?? null);

            $binanceClientOrderId = method_exists($orderData, 'getClientOrderId')
                ? $orderData->getClientOrderId()
                : ($orderData['clientOrderId'] ?? null);

            $status = method_exists($orderData, 'getStatus')
                ? $orderData->getStatus()
                : ($orderData['status'] ?? 'UNKNOWN');

            // 6. Salvar ordem no banco
            $orderDbId = $this->saveGridOrder([
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
            ]);

            $this->log("Ordem SELL criada: $symbol @ $adjustedPrice (Qty: $adjustedQty, Nível: $gridLevel)", 'INFO', 'TRADE');

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
            list($stepSize, $tickSize, $minNotional) = $this->extractFilters($symbolData);
            $stepSizeFloat = (float)$stepSize;
            $decimalPlacesQty = $this->getDecimalPlaces($stepSize);

            $safeQty = floor($quantity / $stepSizeFloat) * $stepSizeFloat;
            if ($safeQty <= 0) {
                $this->log("Quantidade de venda insuficiente para $symbol", 'WARNING', 'TRADE');
                return;
            }

            $sellReq = new NewOrderRequest();
            $sellReq->setSymbol($symbol);
            $sellReq->setSide(Side::SELL);
            $sellReq->setType(OrderType::MARKET);
            $sellReq->setQuantity((float)number_format($safeQty, $decimalPlacesQty, '.', ''));

            $resp = $this->client->newOrder($sellReq);
            $data = $resp->getData();

            $orderId = method_exists($data, 'getOrderId') ? $data->getOrderId() : ($data['orderId'] ?? null);
            $status = method_exists($data, 'getStatus') ? $data->getStatus() : ($data['status'] ?? 'UNKNOWN');

            $this->log("Venda de emergência executada: $symbol (Qty: $safeQty, Status: $status)", 'SUCCESS', 'TRADE');

            $this->saveGridLog(
                $gridId,
                'emergency_sell',
                'success',
                "Venda a mercado durante rebalanceamento",
                [
                    'quantity' => $safeQty,
                    'order_id' => $orderId,
                    'status' => $status
                ]
            );
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

            foreach ($accountInfo['balances'] as $balance) {
                if ($balance['asset'] === 'USDC') {
                    $this->totalCapital = (float)$balance['free'];
                    $this->log("Capital USDC disponível: {$this->totalCapital}", 'INFO', 'SYSTEM');
                    return;
                }
            }

            $this->totalCapital = 0.0;
            $this->log("AVISO: Nenhum saldo USDC encontrado", 'WARNING', 'SYSTEM');
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
     */
    private function saveGridConfig(
        string $symbol,
        float $gridMin,
        float $gridMax,
        float $centerPrice,
        float $capitalAllocated,
        float $capitalPerLevel
    ): int {
        try {
            $usersModel = new users_model();
            $usersModel->set_filter(["active = 'yes'", "enabled = 'yes'"]);
            $usersModel->load_data();

            if (empty($usersModel->data)) {
                throw new Exception("Nenhum usuário ativo encontrado para criar grid");
            }

            $userId = (int)$usersModel->data[0]['idx'];

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
                'current_price' => $centerPrice
            ]);

            $gridId = $gridsModel->save();

            if (!$gridId) {
                throw new Exception("Falha ao salvar grid config: save() retornou vazio");
            }

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
            $gridsOrdersModel->populate([
                'grids_id' => $orderData['grids_id'],
                'orders_id' => $orderId,
                'grid_level' => $orderData['grid_level'],
                'paired_order_id' => $orderData['paired_order_id'] ?? null,
                'is_processed' => 'no'
            ]);

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
                        'symbol' => $order['symbol'],
                        'side' => $order['side'],
                        'price' => $order['price'],
                        'executed_qty' => $order['executed_qty'],
                        'grid_level' => $gridOrder['grid_level'],
                        'paired_order_id' => $gridOrder['paired_order_id']
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
                        $this->client->cancelOrder($order['symbol'], $order['binance_order_id']);
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

            foreach ($accountInfo['balances'] as $balance) {
                if ($balance['asset'] === $baseAsset) {
                    return (float)$balance['free'];
                }
            }

            return 0.0;
        } catch (Exception $e) {
            $this->log("Erro ao calcular ativo acumulado: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return 0.0;
        }
    }
}
