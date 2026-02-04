<?php

use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;
use Binance\Client\Spot\Model\NewOrderRequest;
use Binance\Client\Spot\Model\Side;
use Binance\Client\Spot\Model\OrderType;

/**
 * Grid Trading Strategy Controller
 * 
 * Implementa a estratégia de Grid Trading para BTC/USDC e ETH/USDC
 * Controller independente que não depende do setup_controller
 * 
 * Execução: CRON a cada 5 minutos
 */
class setup_controller
{
    // Configuração de símbolos e grid
    private const SYMBOLS = ['BTCUSDC'];
    private const GRID_LEVELS = 6;              // 5 níveis por grid
    private const GRID_RANGE_PERCENT = 0.05;     // ±5% do preço atual
    private const GRID_SPACING_PERCENT = 0.01;   // 1% entre níveis
    private const REBALANCE_THRESHOLD = 0.05;    // Rebalancear se sair 5% do range
    private const CAPITAL_ALLOCATION = 0.95;     // 70% do capital USDC disponível
    private const MIN_TRADE_USDC = 11;           // Mínimo por trade
    private const MAX_ALGO_ORDERS = 5;           // Limite Binance de ordens algorítmicas

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

    public function __construct()
    {
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
        // FORÇAR uso de temp dir para evitar problemas de permissão
        $this->logPath = rtrim(sys_get_temp_dir(), '/') . '/';
    }

    /**
     * Métodos auxiliares para integração com Binance API
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
            $resp = $this->client->getAccount();
            $accountData = $resp->getData();
            return json_decode(json_encode($accountData), true);
        } catch (Exception $e) {
            throw new Exception("Erro ao obter informações da conta: " . $e->getMessage());
        }
    }

    public function getExchangeInfo($symbol): array
    {
        try {
            $url = "https://api.binance.com/api/v3/exchangeInfo?symbol={$symbol}";
            $response = file_get_contents($url);

            if ($response === false) {
                throw new Exception("Erro ao acessar a API da Binance (exchangeInfo).");
            }

            $exchangeData = json_decode($response, true);
            if (!isset($exchangeData['symbols'][0])) {
                throw new Exception("Símbolo {$symbol} não encontrado na API da Binance.");
            }

            return $exchangeData['symbols'][0];
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

            if ($this->totalCapital < self::MIN_TRADE_USDC) {
                $this->log("Capital insuficiente: {$this->totalCapital} USDC", 'WARNING', 'SYSTEM');
                return;
            }

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
     * Processa um símbolo: verifica se grid existe, monitora ou cria novo
     */
    private function processSymbol(string $symbol): void
    {
        try {
            // 1. Verificar se já existe grid ativo
            $activeGrid = $this->getActiveGrid($symbol);

            if ($activeGrid) {
                // Grid existe → Monitorar e processar ordens
                $this->monitorGrid($activeGrid);
            } else {
                // Grid não existe → Criar novo
                $this->createNewGrid($symbol);
            }
        } catch (Exception $e) {
            throw new Exception("Erro ao processar $symbol: " . $e->getMessage());
        }
    }

    /**
     * Cria um novo grid para o símbolo fornecido
     */
    private function createNewGrid(string $symbol): void
    {
        try {
            // 1. OBTER PREÇO ATUAL
            $currentPrice = $this->getCurrentPrice($symbol);
            if ($currentPrice <= 0) {
                $this->log("Preço inválido para $symbol", 'ERROR', 'TRADE');
                return;
            }

            // 2. CALCULAR RANGE DO GRID
            $gridMin = $currentPrice * (1 - self::GRID_RANGE_PERCENT);
            $gridMax = $currentPrice * (1 + self::GRID_RANGE_PERCENT);
            $gridRange = $gridMax - $gridMin;

            // 3. CALCULAR CAPITAL ALOCADO PARA ESTE SÍMBOLO
            $symbolAllocation = $this->getSymbolAllocation($symbol);
            $capitalForSymbol = $this->totalCapital * self::CAPITAL_ALLOCATION * $symbolAllocation;

            if ($capitalForSymbol < self::MIN_TRADE_USDC) {
                $this->log("Capital insuficiente para criar grid em $symbol: {$capitalForSymbol} USDC", 'WARNING', 'TRADE');
                return;
            }

            // 4. DEFINIR NÍVEIS DE PREÇO
            $priceStep = $gridRange / self::GRID_LEVELS;
            $buyLevels = [];
            $sellLevels = [];

            for ($i = 0; $i <= self::GRID_LEVELS; $i++) {
                $levelPrice = $gridMin + ($i * $priceStep);

                if ($levelPrice < $currentPrice) {
                    $buyLevels[] = [
                        'level' => $i + 1,
                        'price' => $levelPrice
                    ];
                } elseif ($levelPrice > $currentPrice) {
                    $sellLevels[] = [
                        'level' => $i + 1,
                        'price' => $levelPrice
                    ];
                }
            }

            // 5. CALCULAR CAPITAL POR NÍVEL DE COMPRA
            $numBuyLevels = count($buyLevels);
            if ($numBuyLevels === 0) {
                $this->log("Nenhum nível de compra disponível para $symbol", 'WARNING', 'TRADE');
                return;
            }

            $capitalPerLevel = $capitalForSymbol / $numBuyLevels;

            // 6. SALVAR CONFIGURAÇÃO DO GRID NO BANCO
            $gridId = $this->saveGridConfig(
                $symbol,
                $gridMin,
                $gridMax,
                $currentPrice,
                $capitalForSymbol,
                $capitalPerLevel
            );

            // 7. CRIAR ORDENS LIMIT DE COMPRA
            $successCount = 0;
            foreach ($buyLevels as $level) {
                $orderDbId = $this->placeBuyOrder(
                    $gridId,
                    $symbol,
                    $level['level'],
                    $level['price'],
                    $capitalPerLevel
                );
                if ($orderDbId) {
                    $successCount++;
                }
            }

            $this->log(
                "Grid criado para $symbol com $numBuyLevels níveis de compra ($successCount ordens criadas)",
                'SUCCESS',
                'TRADE'
            );

            // Salvar log
            $this->saveGridLog($gridId, 'grid_created', 'success', "Grid criado com sucesso para $symbol", [
                'grid_min' => $gridMin,
                'grid_max' => $gridMax,
                'center_price' => $currentPrice,
                'buy_levels' => $numBuyLevels,
                'capital_allocated' => $capitalForSymbol
            ]);
        } catch (Exception $e) {
            throw new Exception("Erro ao criar grid para $symbol: " . $e->getMessage());
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
                } catch (Exception $e) {
                    $this->log("Erro ao processar ordem {$order['idx']}: " . $e->getMessage(), 'ERROR', 'TRADE');
                    $this->saveGridLog(
                        $gridId,
                        'order_processing_error',
                        'error',
                        "Erro ao processar ordem: " . $e->getMessage()
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
     * Processa a execução de uma ordem de compra
     * Cria automaticamente uma ordem de venda acima do preço de compra
     */
    private function handleBuyOrderFilled(int $gridId, array $buyOrder): void
    {
        try {
            $symbol = $buyOrder['symbol'];
            $buyPrice = (float)$buyOrder['price'];
            $executedQty = (float)$buyOrder['executed_qty'];

            // Calcular preço de venda (1% acima da compra)
            $sellPrice = $buyPrice * (1 + self::GRID_SPACING_PERCENT);

            // Criar ordem LIMIT de venda
            $sellOrderId = $this->placeSellOrder(
                $gridId,
                $symbol,
                $buyOrder['grid_level'],
                $sellPrice,
                $executedQty,
                $buyOrder['idx'] // paired_order_id
            );

            if ($sellOrderId) {
                $this->log(
                    "Compra executada em $symbol @ $buyPrice (Qty: $executedQty). Venda criada @ $sellPrice",
                    'SUCCESS',
                    'TRADE'
                );
                $this->saveGridLog(
                    $gridId,
                    'buy_order_filled',
                    'success',
                    "Compra executada e venda criada",
                    [
                        'buy_price' => $buyPrice,
                        'sell_price' => $sellPrice,
                        'quantity' => $executedQty
                    ]
                );
            }
        } catch (Exception $e) {
            throw new Exception("Erro ao processar compra preenchida: " . $e->getMessage());
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
                $results = $gridsOrdersModel->load_data();
                if (!empty($results)) {
                    $buyOrder = $results[0];
                }
            }

            $profit = 0.0;

            if ($buyOrder) {
                $buyPrice = (float)$buyOrder['price'];

                // Calcular lucro (desconta fee de 0.1% em cada lado)
                $buyValue = $executedQty * $buyPrice;
                $sellValue = $executedQty * $sellPrice;
                $buyFee = $buyValue * 0.001;
                $sellFee = $sellValue * 0.001;
                $profit = $sellValue - $buyValue - $buyFee - $sellFee;

                // Salvar lucro na ordem de venda
                $this->updateOrderProfit($sellOrder['idx'], $profit);

                // Atualizar lucro acumulado do grid
                $this->incrementGridProfit($gridId, $profit);

                $this->log(
                    "PAR COMPLETO em $symbol: Lucro = $$profit (Compra: $buyPrice | Venda: $sellPrice)",
                    'SUCCESS',
                    'TRADE'
                );

                $this->saveGridLog(
                    $gridId,
                    'sell_order_filled',
                    'success',
                    "Par completo com lucro",
                    [
                        'buy_price' => $buyPrice,
                        'sell_price' => $sellPrice,
                        'quantity' => $executedQty,
                        'profit' => $profit
                    ]
                );
            }

            // Recriar ordem de COMPRA no mesmo nível
            $gridData = $this->getGridById($gridId);
            $buyPrice = $sellPrice * (1 - self::GRID_SPACING_PERCENT);

            $newBuyOrderId = $this->placeBuyOrder(
                $gridId,
                $symbol,
                $sellOrder['grid_level'],
                $buyPrice,
                $gridData['capital_per_level']
            );

            if ($newBuyOrderId) {
                $this->log("Nova ordem de compra criada para nível {$sellOrder['grid_level']}", 'INFO', 'TRADE');
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
        $gridMin = (float)$gridData['grid_min_price'];
        $gridMax = (float)$gridData['grid_max_price'];

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
                    'old_center_price' => (float)$this->getGridById($gridId)['grid_center_price'],
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

            // 4. Criar ordem LIMIT na Binance
            $orderReq = new NewOrderRequest();
            $orderReq->setSymbol($symbol);
            $orderReq->setSide(Side::BUY);
            $orderReq->setType(OrderType::LIMIT);
            $orderReq->setTimeInForce('GTC'); // Good Till Cancel
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

            // 5. Salvar ordem no banco
            $orderDbId = $this->saveGridOrder([
                'grids_id' => $gridId,  // CORRIGIDO: era 'grid_id', agora é 'grids_id'
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

            // 4. Criar ordem LIMIT na Binance
            $orderReq = new NewOrderRequest();
            $orderReq->setSymbol($symbol);
            $orderReq->setSide(Side::SELL);
            $orderReq->setType(OrderType::LIMIT);
            $orderReq->setTimeInForce('GTC'); // Good Till Cancel
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

            // 5. Salvar ordem no banco
            $orderDbId = $this->saveGridOrder([
                'grids_id' => $gridId,  // CORRIGIDO: era 'grid_id', agora é 'grids_id'
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

            // A API retorna objeto com estrutura aninhada
            if ($data && method_exists($data, 'getTickerPriceResponse1')) {
                $priceData = $data->getTickerPriceResponse1();
                if ($priceData && method_exists($priceData, 'getPrice')) {
                    $price = (float)$priceData->getPrice();
                    $this->symbolPrices[$symbol] = $price;
                    return $price;
                }
            }

            // Fallback: tentar acessar diretamente
            if ($data && method_exists($data, 'getPrice')) {
                $price = (float)$data->getPrice();
                $this->symbolPrices[$symbol] = $price;
                return $price;
            }

            // Fallback para array
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
            $accountInfo = $this->getAccountInfo(true); // Force refresh

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
     * Retorna a alocação de capital para cada símbolo (50% BTC, 50% ETH)
     */
    private function getSymbolAllocation(string $symbol): float
    {
        $allocations = [
            'BTCUSDC' => 0.50,  // 50%
            'ETHUSDC' => 0.50   // 50%
        ];

        return $allocations[$symbol] ?? 0.0;
    }

    // ========== MÉTODOS DE BANCO DE DADOS ==========

    /**
     * Obtém um grid ativo (em cache ou do banco)
     */
    private function getActiveGrid(string $symbol): ?array
    {
        // Se já está em cache, retornar
        if (isset($this->activeGrids[$symbol])) {
            return $this->activeGrids[$symbol];
        }

        try {
            // Buscar do banco usando model
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
            $gridsModel = new grids_model();
            $gridsModel->populate([
                'symbol' => $symbol,
                'status' => 'active',
                'active' => 'yes',  // ADICIONADO: campo obrigatório
                'grid_min_price' => $gridMin,
                'grid_max_price' => $gridMax,
                'grid_center_price' => $centerPrice,
                'total_levels' => self::GRID_LEVELS,
                'spacing_percent' => self::GRID_SPACING_PERCENT,
                'capital_allocated_usdc' => $capitalAllocated,
                'capital_per_level' => $capitalPerLevel,
                'accumulated_profit_usdc' => 0.0,
                'total_trades_completed' => 0
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
     * Padrão: Salvar order na tabela orders, depois criar relacionamento em grids_orders
     */
    private function saveGridOrder(array $orderData): int
    {
        try {
            // Separar dados da order e dados específicos do grid
            $ordersData = [
                'binance_order_id' => $orderData['binance_order_id'],
                'binance_client_order_id' => $orderData['binance_client_order_id'],
                'symbol' => $orderData['symbol'],
                'side' => $orderData['side'],
                'type' => $orderData['type'],
                'order_type' => 'entry',  // Grid orders são sempre de entrada/grid
                'tp_target' => 'entry',
                'price' => $orderData['price'],
                'quantity' => $orderData['quantity'],
                'executed_qty' => $orderData['executed_qty'] ?? 0,
                'status' => $orderData['status'],
                'cumulative_quote_qty' => 0,
                'order_created_at' => $orderData['order_created_at']
            ];

            // Salvar order na tabela orders
            $ordersModel = new orders_model();
            $ordersModel->populate($ordersData);
            $orderIdx = $ordersModel->save();

            // Salvar relacionamento em grids_orders (junction table)
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->populate([
                'grids_id' => $orderData['grids_id'],
                'orders_id' => $orderIdx,
                'grid_level' => $orderData['grid_level'],
                'paired_order_id' => $orderData['paired_order_id'] ?? null,
                'profit_usdc' => $orderData['profit_usdc'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => 1
            ]);
            $gridsOrdersModel->save();

            return $orderIdx;
        } catch (Exception $e) {
            $this->log("Erro ao salvar ordem de grid: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            throw $e;
        }
    }

    /**
     * Obtém ordens executadas mas ainda não processadas
     */
    private function getExecutedUnprocessedOrders(int $gridId): array
    {
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "grids_id = '{$gridId}'"
            ]);
            $gridsOrdersModel->attach(['orders']);
            $gridsOrdersModel->load_data();

            // Filtrar orders que foram FILLED mas não processadas
            $result = [];
            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders'][0] ?? null;
                if ($order && in_array($order['status'], ['FILLED', 'PARTIALLY_FILLED'])) {
                    $result[] = array_merge($gridOrder, ['order_data' => $order]);
                }
            }

            return $result;
        } catch (Exception $e) {
            $this->log("Erro ao buscar ordens executadas: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return [];
        }
    }

    /**
     * Busca TODAS as ordens de um grid (independente do status)
     */
    private function getAllGridOrders(int $gridId): array
    {
        try {
            // 1. Buscar grids_orders
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "grids_id = '{$gridId}'"
            ]);
            $gridsOrdersModel->load_data();

            if (empty($gridsOrdersModel->data)) {
                return [];
            }

            // 2. Pegar IDs das ordens
            $orderIds = array_column($gridsOrdersModel->data, 'orders_id');

            if (empty($orderIds)) {
                return [];
            }

            // 3. Carregar ordens diretamente
            $ordersModel = new orders_model();
            $ordersModel->set_filter([
                "active = 'yes'",
                "idx IN (" . implode(',', $orderIds) . ")"
            ]);
            $ordersModel->load_data();

            // 4. Criar mapa de ordens por ID
            $ordersMap = [];
            foreach ($ordersModel->data as $order) {
                $ordersMap[$order['idx']] = $order;
            }

            // 5. Combinar dados
            $result = [];
            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $ordersMap[$gridOrder['orders_id']] ?? null;
                if ($order) {
                    $result[] = array_merge($gridOrder, ['order_data' => $order]);
                }
            }

            return $result;
        } catch (Exception $e) {
            $this->log("Erro ao buscar todas as ordens: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return [];
        }
    }

    /**
     * Atualiza o lucro de uma ordem no grid_orders (junction table)
     */
    private function updateOrderProfit(int $orderDbId, float $profit): void
    {
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "idx = '{$orderDbId}'"
            ]);
            $gridsOrdersModel->populate([
                'profit_usdc' => $profit
            ]);
            $gridsOrdersModel->save();
        } catch (Exception $e) {
            $this->log("Erro ao atualizar lucro da ordem: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Incrementa o lucro acumulado do grid
     */
    private function incrementGridProfit(int $gridId, float $profit): void
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter([
                "active = 'yes'",
                "idx = '{$gridId}'"
            ]);
            $gridsModel->load_data();

            if (!empty($gridsModel->data)) {
                $grid = $gridsModel->data[0];
                $newProfit = (float)($grid['accumulated_profit_usdc'] ?? 0) + $profit;
                $newTradesCount = ((int)($grid['total_trades_completed'] ?? 0)) + 1;

                $gridsModel->populate([
                    'accumulated_profit_usdc' => $newProfit,
                    'total_trades_completed' => $newTradesCount
                ]);
                $gridsModel->save();
            }
        } catch (Exception $e) {
            $this->log("Erro ao incrementar lucro do grid: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Cancela todas as ordens abertas de um grid
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
                try {
                    $order = $gridOrder['orders'][0] ?? null;
                    if ($order && in_array($order['status'], ['NEW', 'PARTIALLY_FILLED'])) {
                        $this->client->cancelOrder($order['symbol'], ['orderId' => $order['binance_order_id']]);

                        // Atualizar status da ordem
                        $ordersModel = new orders_model();
                        $ordersModel->set_filter([
                            "active = 'yes'",
                            "idx = '{$order['idx']}'"
                        ]);
                        $ordersModel->populate([
                            'status' => 'CANCELED'
                        ]);
                        $ordersModel->save();
                    }
                } catch (Exception $e) {
                    $this->log("Erro ao cancelar ordem {$order['binance_order_id']}: " . $e->getMessage(), 'WARNING', 'TRADE');
                }
            }

            $this->log("Cancelamento de ordens concluído para grid $gridId", 'INFO', 'TRADE');
        } catch (Exception $e) {
            $this->log("Erro ao buscar ordens para cancelamento: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Obtém o ativo acumulado no grid (não vendido)
     */
    private function getAccumulatedAsset(int $gridId, string $symbol): float
    {
        try {
            $gridsOrdersModel = new grids_orders_model();
            $gridsOrdersModel->set_filter([
                "active = 'yes'",
                "grids_id = '{$gridId}'"
            ]);
            $gridsOrdersModel->attach(['orders']);
            $gridsOrdersModel->load_data();

            $totalBought = 0.0;
            $totalSold = 0.0;

            foreach ($gridsOrdersModel->data as $gridOrder) {
                $order = $gridOrder['orders'][0] ?? null;
                if ($order && $order['symbol'] === $symbol && $order['status'] === 'FILLED') {
                    if ($order['side'] === 'BUY') {
                        $totalBought += (float)$order['executed_qty'];
                    } else {
                        $totalSold += (float)$order['executed_qty'];
                    }
                }
            }

            return max(0, $totalBought - $totalSold);
        } catch (Exception $e) {
            $this->log("Erro ao obter ativo acumulado: " . $e->getMessage(), 'ERROR', 'SYSTEM');
            return 0.0;
        }
    }

    /**
     * Atualiza o status de um grid
     */
    private function updateGridStatus(int $gridId, string $status): void
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter([
                "active = 'yes'",
                "idx = '{$gridId}'"
            ]);
            $gridsModel->populate([
                'status' => $status,
                'last_rebalance_at' => date('Y-m-d H:i:s')
            ]);
            $gridsModel->save();
        } catch (Exception $e) {
            $this->log("Erro ao atualizar status do grid: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Atualiza estatísticas do grid
     */
    private function updateGridStats(int $gridId): void
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter([
                "active = 'yes'",
                "idx = '{$gridId}'"
            ]);
            $gridsModel->populate([]);  // Update timestamp apenas
            $gridsModel->save();
        } catch (Exception $e) {
            $this->log("Erro ao atualizar estatísticas do grid: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    /**
     * Salva um log de evento do grid
     */
    private function saveGridLog(
        int $gridId,
        string $event,
        string $logType,
        string $message,
        ?array $data = null
    ): void {
        try {
            $gridLogsModel = new grid_logs_model();
            $gridLogsModel->populate([
                'grids_id' => $gridId,
                'event' => $event,
                'log_type' => $logType,
                'message' => $message,
                'data' => $data ? json_encode($data) : null
            ]);
            $gridLogsModel->save();
        } catch (Exception $e) {
            $this->log("Erro ao salvar log de grid: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }
}
