<?php

use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;
use Binance\Client\Spot\Model\NewOrderRequest;
use Binance\Client\Spot\Model\Side;
use Binance\Client\Spot\Model\OrderType;

class setup_controller
{
    private const TIMEFRAME = "15m"; // Alterado para 15 minutos
    private const QTD_CANDLES = 1000;
    private const BOLLINGER_PERIOD = 20;
    private const BOLLINGER_DEVIATION = 2;

    private const ERROR_LOG = 'error.log';
    private const API_LOG = 'binance_api.log';
    private const TRADE_LOG = 'trading.log';

    private const CACHE_TTL_EXCHANGE_INFO = 3600;
    private const CACHE_TTL_ACCOUNT_INFO = 2;

    private $client;
    private $curlHandle;
    private $exchangeInfoCache = [];
    private $accountInfoCache = null;
    private $accountInfoCacheTime = 0;
    private $logBuffer = [];
    private $logBufferSize = 100;
    private $logPath;

    public function __construct()
    {
        $this->initializeBinanceClient();
        $this->initializeLogger();
        $this->initializeCurlHandle();
        register_shutdown_function([$this, 'flushLogs']);
    }

    public function __destruct()
    {
        $this->flushLogs();
    }

    private function initializeBinanceClient(): void
    {
        $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
        $configurationBuilder->apiKey(binanceAPIKey);
        $configurationBuilder->secretKey(binanceSecretKey);
        $this->client = new SpotRestApi($configurationBuilder->build());
    }

    private function initializeLogger(): void
    {
        $primary = defined('LOG_DIR') ? rtrim(constant('LOG_DIR'), '/') . '/' : '/var/log/tradebot-binance/';
        $fallbacks = ['/var/log/tradebot-binance/', rtrim(sys_get_temp_dir(), '/') . '/tradebot-binance/logs/'];

        $candidates = array_unique(array_merge([$primary], $fallbacks));

        foreach ($candidates as $dir) {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0777, true)) {
                    continue;
                }
            }
            if (@is_writable($dir)) {
                $this->logPath = rtrim($dir, '/') . '/';
                break;
            }
        }

        if (empty($this->logPath)) {
            $this->logPath = rtrim(sys_get_temp_dir(), '/') . '/';
            error_log('Logger sem diretório gravável; usando sys temp.');
        }
    }

    private function initializeCurlHandle(): void
    {
        $this->curlHandle = curl_init();
        curl_setopt_array($this->curlHandle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_TCP_FASTOPEN => true,
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_FRESH_CONNECT => false,
        ]);
    }

    private function log(string $message, string $level = 'ERROR', string $type = 'SYSTEM'): void
    {
        $basePath = $this->logPath ?: (defined('LOG_DIR') ? rtrim(constant('LOG_DIR'), '/') . '/' : '/var/log/tradebot-binance/');
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

        if ($level === 'ERROR') {
            error_log($message);
        }

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
            file_put_contents($logFile, implode('', $this->logBuffer[$logFile]), FILE_APPEND | LOCK_EX);
            $this->logBuffer[$logFile] = [];
        } catch (Exception $e) {
            error_log("Erro ao escrever no log: " . $e->getMessage());
        }
    }

    public function flushLogs(): void
    {
        foreach (array_keys($this->logBuffer) as $logFile) {
            $this->flushLogFile($logFile);
        }
    }

    private function logBinanceError(string $method, string $error, array $params = []): void
    {
        $message = "Método: {$method} | Erro: {$error}";
        if (!empty($params)) {
            $message .= " | Parâmetros: " . json_encode($params);
        }
        $this->log($message, 'ERROR', 'API');
    }

    private function logTradeOperation(string $symbol, string $operation, array $details): void
    {
        $message = "Symbol: {$symbol} | Operação: {$operation} | Detalhes: " . json_encode($details);
        $this->log($message, 'INFO', 'TRADE');
    }

    public function display(): void
    {
        try {
            $topMoedas = ["BTCUSDC", "ETHUSDC", "BNBUSDC", "SOLUSDC", "XRPUSDC", "ADAUSDC", "DOTUSDC", "AVAXUSDC", "LTCUSDC", "LINKUSDC", "UNIUSDC"];
            $this->processSymbols($topMoedas);
        } catch (Exception $e) {
            $this->log("Erro no método display: " . $e->getMessage(), 'ERROR', 'SYSTEM');
        }
    }

    private function getAccountInfo(): array
    {
        $now = time();
        if ($this->accountInfoCache && ($now - $this->accountInfoCacheTime) < self::CACHE_TTL_ACCOUNT_INFO) {
            return $this->accountInfoCache;
        }

        try {
            $resp = $this->client->getAccount();
            $accountData = $resp->getData();
            // Converter objeto GetAccountResponse para array
            $this->accountInfoCache = json_decode(json_encode($accountData), true);
            $this->accountInfoCacheTime = $now;
            return $this->accountInfoCache;
        } catch (Exception $e) {
            throw new Exception("Erro ao obter informações da conta: " . $e->getMessage());
        }
    }

    private function processSymbols(array $symbols): void
    {
        $this->preloadExchangeInfo($symbols);

        foreach ($symbols as $symbol) {
            try {
                $openOrdersResp = $this->client->getOpenOrders($symbol);
                $openOrders = $openOrdersResp->getData()->getItems();

                if (count($openOrders) >= 5) {
                    continue;
                }

                $precos = $this->getBinanceData($symbol, self::TIMEFRAME, self::QTD_CANDLES);
                if (empty($precos)) {
                    continue;
                }

                $bollinger = $this->calcularBandasBollinger($precos);
                $this->estrategiaFechouForaFechouDentro($precos, $bollinger, $symbol);
            } catch (Exception $e) {
                $this->log("Erro ao processar $symbol: " . $e->getMessage(), 'ERROR', 'SYSTEM');
                continue;
            }
        }
    }

    private function preloadExchangeInfo(array $symbols): void
    {
        try {
            $symbolsParam = implode(',', array_map(fn($s) => '"' . $s . '"', $symbols));
            $url = "https://api.binance.com/api/v3/exchangeInfo?symbols=[{$symbolsParam}]";
            $response = $this->fetchApiData($url);
            $data = json_decode($response, true);

            if (isset($data['symbols'])) {
                foreach ($data['symbols'] as $symbolData) {
                    $this->exchangeInfoCache[$symbolData['symbol']] = $symbolData;
                }
            }
        } catch (Exception $e) {
        }
    }

    private function fetchApiData(string $url): string
    {
        curl_setopt($this->curlHandle, CURLOPT_URL, $url);
        $response = curl_exec($this->curlHandle);

        if (curl_errno($this->curlHandle)) {
            throw new Exception("Erro ao acessar API: " . curl_error($this->curlHandle));
        }

        return $response;
    }

    private function getBinanceData(string $symbol, string $interval, int $limit): array
    {
        try {
            $url = "https://api.binance.com/api/v3/klines?symbol={$symbol}&interval={$interval}&limit={$limit}";
            $response = $this->fetchApiData($url);

            if ($response === false) {
                $this->logBinanceError('getBinanceData', 'API retornou false', [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'limit' => $limit
                ]);
                return [];
            }

            $data = json_decode($response, true);
            if (!is_array($data)) {
                return [];
            }

            $result = [];
            foreach ($data as $candle) {
                $result[] = [
                    'timestamp' => $candle[0],
                    'abertura'  => (float)$candle[1],
                    'preco'     => (float)$candle[4],
                    'high'      => (float)$candle[2],
                    'low'       => (float)$candle[3]
                ];
            }

            return $result;
        } catch (Exception $e) {
            $this->logBinanceError('getBinanceData', $e->getMessage(), [
                'symbol' => $symbol,
                'interval' => $interval,
                'limit' => $limit
            ]);
            return [];
        }
    }

    public function getExchangeInfo($symbol): array
    {
        if (isset($this->exchangeInfoCache[$symbol])) {
            return $this->exchangeInfoCache[$symbol];
        }

        try {
            $url = "https://api.binance.com/api/v3/exchangeInfo?symbol={$symbol}";
            $response = $this->fetchApiData($url);

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

    private function calcularBandasBollinger(array $dados, int $periodo = self::BOLLINGER_PERIOD, int $desvio = self::BOLLINGER_DEVIATION): array
    {
        $count = count($dados);
        if ($count < $periodo) {
            return [];
        }

        $precos = [];
        foreach ($dados as $d) {
            $precos[] = $d['preco'];
        }

        $bollinger = [];
        $soma = 0;
        $somaQuadrados = 0;

        for ($j = 0; $j < $periodo; $j++) {
            $p = $precos[$j];
            $soma += $p;
            $somaQuadrados += $p * $p;
        }

        for ($i = $periodo; $i <= $count; $i++) {
            $media = $soma / $periodo;
            $variancia = ($somaQuadrados / $periodo) - ($media * $media);
            $desvioPadrao = sqrt(max(0, $variancia));

            $bollinger[] = [
                'media'          => $media,
                'banda_superior' => $media + ($desvio * $desvioPadrao),
                'banda_inferior' => $media - ($desvio * $desvioPadrao),
                'desvioPadrao'   => $desvioPadrao
            ];

            if ($i < $count) {
                $precoNovo = $precos[$i];
                $precoVelho = $precos[$i - $periodo];
                $soma += $precoNovo - $precoVelho;
                $somaQuadrados += ($precoNovo * $precoNovo) - ($precoVelho * $precoVelho);
            }
        }

        return $bollinger;
    }

    private function estrategiaFechouForaFechouDentro(array $dados, array $bollinger, string $symbol): void
    {
        if (count($dados) < 3 || count($bollinger) < 3) {
            return;
        }

        $ultimo = count($dados) - 1;
        $candle1 = $dados[$ultimo];
        $candle2 = $dados[$ultimo - 1];
        $candle3 = $dados[$ultimo - 2];

        $bbReferencia3 = $bollinger[$ultimo - 21];
        $bbReferencia2 = $bollinger[$ultimo - 20];
        $bbReferencia1 = $bollinger[$ultimo - 19];

        if (!isset($candle3['preco'], $candle2['preco'], $bbReferencia3['banda_inferior'], $bbReferencia2['banda_inferior'])) {
            return;
        }

        if (
            $candle3['preco'] < $bbReferencia3['banda_inferior'] &&
            $candle2['preco'] > $bbReferencia2['banda_inferior'] &&
            $candle2["preco"] > $candle2["abertura"]
        ) {
            $this->processarEntrada($symbol, $candle1, $bbReferencia1);
        }
    }

    private function processarEntrada(string $symbol, array $candle1, array $bbReferencia1): void
    {
        try {
            $accountInfo = $this->getAccountInfo();

            $usdcBalance = null;
            foreach ($accountInfo['balances'] as $balance) {
                if ($balance['asset'] === 'USDC') {
                    $usdcBalance = $balance;
                    break;
                }
            }

            if (!$usdcBalance || (float)$usdcBalance['free'] <= 0) {
                return;
            }

            // Capturar saldo total da carteira ANTES de abrir o trade
            $walletTotalUsdc = (float)$usdcBalance['free'] + (float)$usdcBalance['locked'];

            $capitalDisponivel = (float)$usdcBalance['free'];
            $investimento = $this->calcularInvestimento($symbol, $capitalDisponivel);

            if ($investimento == 0) {
                return;
            }

            $precoEntrada = $candle1['preco'];
            $takeProfit = $bbReferencia1['media'] - (0.5 * $bbReferencia1['desvioPadrao']);

            $lucroPercentual = (($takeProfit - $precoEntrada) / $precoEntrada) * 100;

            if ($lucroPercentual < 0.30) {
                return;
            }

            $orderConfigs = $this->getOrderDetailsForSymbol($symbol, $precoEntrada, $takeProfit, $investimento);

            // Criar trade no banco ANTES de executar as ordens
            $tradesModel = new trades_model();
            $tradesModel->populate([
                'symbol' => $symbol,
                'status' => 'open',
                'timeframe' => '15m',
                'entry_price' => $precoEntrada,
                'quantity' => $orderConfigs["quantity"],
                'investment' => $investimento,
                'take_profit_price' => $takeProfit,
                'opened_at' => date('Y-m-d H:i:s')
            ]);
            $tradeIdx = $tradesModel->save();

            // Salvar snapshot do saldo ANTES de abrir o trade
            try {
                $snapshotId = WalletBalanceHelper::snapshotBeforeTrade($tradeIdx, $walletTotalUsdc);
                if ($snapshotId) {
                    $this->log("Snapshot criado antes do trade #{$tradeIdx}: Saldo USDC = {$walletTotalUsdc}", 'INFO', 'TRADE');
                }
            } catch (Exception $e) {
                $this->log("Erro ao criar snapshot before_trade: " . $e->getMessage(), 'ERROR', 'TRADE');
            }

            // Log do setup detectado
            $tradeLogsModel = new tradelogs_model();
            $tradeLogsModel->populate([
                'trades_id' => $tradeIdx,
                'log_type' => 'info',
                'event' => 'setup_detected',
                'message' => "Setup de Bollinger detectado para {$symbol}",
                'data' => json_encode([
                    'entry_price' => $precoEntrada,
                    'take_profit' => $takeProfit,
                    'profit_percent' => $lucroPercentual,
                    'investment' => $investimento,
                    'wallet_balance_usdc' => $walletTotalUsdc
                ])
            ]);
            $tradeLogsModel->save();

            // Executar ordens na Binance
            $this->executarOrdens($symbol, $orderConfigs, $precoEntrada, $tradeIdx);
        } catch (Exception $e) {
            $this->log("Erro ao processar entrada para $symbol: " . $e->getMessage(), 'ERROR', 'TRADE');

            // Se criou o trade, registrar erro no log
            if (isset($tradeIdx) && $tradeIdx > 0) {
                $tradeLogsModel = new tradelogs_model();
                $tradeLogsModel->populate([
                    'trades_id' => $tradeIdx,
                    'log_type' => 'error',
                    'event' => 'processing_error',
                    'message' => "Erro ao processar entrada: " . $e->getMessage()
                ]);
                $tradeLogsModel->save();
            }
        }
    }

    private function calcularInvestimento(string $symbol, float $capitalDisponivel): float
    {
        $percentuais = [
            'BTC' => 0.30,
            'ETH' => 0.20
        ];

        $asset = str_replace('USDC', '', $symbol);
        $percentual = $percentuais[$asset] ?? 0.10;
        $investimento = $capitalDisponivel * $percentual;

        if ($investimento < 11) {
            if ($capitalDisponivel >= 11) {
                return 11;
            } else {
                return 0;
            }
        }

        return $investimento;
    }

    private function executarOrdens(string $symbol, array $orderConfigs, float $precoEntrada, int $tradeIdx): void
    {
        try {
            $tradesLogModel = new tradelogs_model();
            $ordersModel = new orders_model();

            // Ordem de Compra (MARKET)
            $newOrderReq = new NewOrderRequest();
            $newOrderReq->setSymbol($symbol);
            $newOrderReq->setSide(Side::BUY);
            $newOrderReq->setType(OrderType::MARKET);
            $newOrderReq->setQuantity((float)$orderConfigs["quantity"]); // Convertido para float

            $orderMarketResp = $this->client->newOrder($newOrderReq);
            $orderMarket = $orderMarketResp->getData();

            // Extrair dados da ordem (compatível com objeto ou array)
            $orderId = method_exists($orderMarket, 'getOrderId') ? $orderMarket->getOrderId() : ($orderMarket["orderId"] ?? null);
            $executedQty = method_exists($orderMarket, 'getExecutedQty') ? $orderMarket->getExecutedQty() : ($orderMarket["executedQty"] ?? 0);
            $status = method_exists($orderMarket, 'getStatus') ? $orderMarket->getStatus() : ($orderMarket["status"] ?? 'UNKNOWN');
            $clientOrderId = method_exists($orderMarket, 'getClientOrderId') ? $orderMarket->getClientOrderId() : ($orderMarket["clientOrderId"] ?? null);

            // Salvar ordem de compra no banco
            $ordersModel->populate([
                'binance_order_id' => $orderId,
                'binance_client_order_id' => $clientOrderId,
                'symbol' => $symbol,
                'side' => 'BUY',
                'type' => 'MARKET',
                'order_type' => 'entry',
                'price' => $precoEntrada,
                'quantity' => $orderConfigs["quantity"],
                'executed_qty' => $executedQty,
                'status' => $status,
                'order_created_at' => round(microtime(true) * 1000),
                'api_response' => json_encode(is_object($orderMarket) ? json_decode(json_encode($orderMarket), true) : $orderMarket)
            ]);
            $buyOrderIdx = $ordersModel->save();

            // Relacionar ordem com trade usando save_attach
            $ordersModel->save_attach(['idx' => $buyOrderIdx, 'post' => ['trades_id' => $tradeIdx]], ['trades']);

            $this->logTradeOperation($symbol, 'MARKET_BUY_SUCCESS', [
                'orderId' => $orderId,
                'quantity' => $executedQty,
                'status' => $status,
                'price' => $precoEntrada
            ]);

            $tradesLogModel->populate([
                'trades_id' => $tradeIdx,
                'log_type' => 'success',
                'event' => 'order_executed',
                'message' => "Ordem de compra executada: $orderId",
                'data' => json_encode([
                    'order_id' => $orderId,
                    'quantity' => $executedQty,
                    'status' => $status
                ])
            ]);
            $tradesLogModel->save();

            $statusFilled = $status === 'FILLED';
            if ($orderId && $statusFilled) {
                $qtdProfit = $this->getAvailableBalance($symbol);

                // Ordem de Take Profit
                $tpOrderReq = new NewOrderRequest();
                $tpOrderReq->setSymbol($symbol);
                $tpOrderReq->setSide(Side::SELL);
                $tpOrderReq->setType(OrderType::TAKE_PROFIT);
                $tpOrderReq->setQuantity((float)$qtdProfit); // Convertido para float
                $tpOrderReq->setStopPrice((float)$orderConfigs["profit"]); // Convertido para float

                $takeProfitResp = $this->client->newOrder($tpOrderReq);
                $takeProfitOrder = $takeProfitResp->getData();

                $tpOrderId = method_exists($takeProfitOrder, 'getOrderId') ? $takeProfitOrder->getOrderId() : ($takeProfitOrder["orderId"] ?? null);
                $tpClientOrderId = method_exists($takeProfitOrder, 'getClientOrderId') ? $takeProfitOrder->getClientOrderId() : ($takeProfitOrder["clientOrderId"] ?? null);
                $tpStatus = method_exists($takeProfitOrder, 'getStatus') ? $takeProfitOrder->getStatus() : ($takeProfitOrder["status"] ?? 'UNKNOWN');

                // Salvar ordem de take profit no banco
                $ordersModel2 = new orders_model();
                $ordersModel2->populate([
                    'binance_order_id' => $tpOrderId,
                    'binance_client_order_id' => $tpClientOrderId,
                    'symbol' => $symbol,
                    'side' => 'SELL',
                    'type' => 'TAKE_PROFIT',
                    'order_type' => 'take_profit',
                    'stop_price' => $orderConfigs["profit"],
                    'quantity' => $qtdProfit,
                    'status' => $tpStatus,
                    'order_created_at' => round(microtime(true) * 1000),
                    'api_response' => json_encode(is_object($takeProfitOrder) ? json_decode(json_encode($takeProfitOrder), true) : $takeProfitOrder)
                ]);
                $tpOrderIdx = $ordersModel2->save();

                // Relacionar ordem com trade usando save_attach
                $ordersModel2->save_attach(['idx' => $tpOrderIdx, 'post' => ['trades_id' => $tradeIdx]], ['trades']);

                $this->logTradeOperation($symbol, 'TAKE_PROFIT_SUCCESS', [
                    'orderId' => $tpOrderId,
                    'quantity' => $qtdProfit,
                    'stopPrice' => $orderConfigs["profit"]
                ]);

                $tradesLogModel2 = new tradelogs_model();
                $tradesLogModel2->populate([
                    'trades_id' => $tradeIdx,
                    'log_type' => 'success',
                    'event' => 'order_executed',
                    'message' => "Ordem de take profit criada: $tpOrderId",
                    'data' => json_encode([
                        'order_id' => $tpOrderId,
                        'stop_price' => $orderConfigs["profit"],
                        'quantity' => $qtdProfit
                    ])
                ]);
                $tradesLogModel2->save();
            }
        } catch (Exception $e) {
            $this->logBinanceError('executarOrdens', $e->getMessage(), [
                'symbol' => $symbol,
                'orderConfigs' => $orderConfigs
            ]);

            // Registrar erro no log do trade
            if (isset($tradeIdx)) {
                $tradesLogModel = new tradelogs_model();
                $tradesLogModel->populate([
                    'trades_id' => $tradeIdx,
                    'log_type' => 'error',
                    'event' => 'execution_error',
                    'message' => "Erro ao executar ordens: " . $e->getMessage()
                ]);
                $tradesLogModel->save();
            }

            throw new Exception("Erro ao executar ordens: " . $e->getMessage());
        }
    }

    public function getOrderDetailsForSymbol(string $symbol, float $currentPrice, float $takeProfit, float $investimento): array
    {
        try {
            $symbolData = $this->getExchangeInfo($symbol);
            list($stepSize, $tickSize) = $this->extractFilters($symbolData);

            $takeProfit = (float) $takeProfit;
            $quantity = $this->calculateAdjustedQuantity($investimento, $currentPrice, $stepSize);
            $takeProfit = $this->adjustPriceToTickSize($takeProfit, $tickSize);

            return [
                'quantity' => $quantity,
                'profit' => $takeProfit,
            ];
        } catch (Exception $e) {
            throw new Exception("Erro ao calcular detalhes da ordem: " . $e->getMessage());
        }
    }

    private function extractFilters(array $symbolData): array
    {
        $filters = array_column($symbolData['filters'], null, 'filterType');
        if (!isset($filters['LOT_SIZE'], $filters['PRICE_FILTER'])) {
            throw new Exception("Filtros não encontrados nos dados do símbolo.");
        }
        return [$filters['LOT_SIZE']['stepSize'], $filters['PRICE_FILTER']['tickSize']];
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

    public function getAvailableBalance(string $symbol): string
    {
        try {
            $symbolData = $this->getExchangeInfo($symbol);
            list($stepSize, $tickSize) = $this->extractFilters($symbolData);

            $stepSizeFloat = (float)$stepSize;
            $decimalPlacesQty = $this->getDecimalPlaces($stepSize);

            $accountInfo = $this->getAccountInfo();
            $asset = str_replace('USDC', '', $symbol);

            $wallet = null;
            foreach ($accountInfo['balances'] as $balance) {
                if ($balance['asset'] === $asset && (float)$balance['free'] > 0) {
                    $wallet = $balance;
                    break;
                }
            }

            if (!$wallet) {
                return '0';
            }

            $qtdProfit = floor((float)$wallet["free"] / $stepSizeFloat) * $stepSizeFloat;
            return number_format($qtdProfit, $decimalPlacesQty, '.', '');
        } catch (Exception $e) {
            throw new Exception("Erro ao obter saldo disponível: " . $e->getMessage());
        }
    }

    private function getDecimalPlaces(string $value): int
    {
        $trimmed = rtrim($value, '0');
        $dotPos = strpos($trimmed, '.');
        return $dotPos !== false ? strlen($trimmed) - $dotPos - 1 : 0;
    }
}
