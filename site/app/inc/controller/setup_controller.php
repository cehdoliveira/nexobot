<?php

use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;
use Binance\Client\Spot\Model\NewOrderRequest;
use Binance\Client\Spot\Model\Side;
use Binance\Client\Spot\Model\OrderType;

class setup_controller
{
    private const TIMEFRAME = "15m"; // 15 minutos
    private const QTD_CANDLES = 1000;
    private const BOLLINGER_PERIOD = 20;
    private const BOLLINGER_DEVIATION = 2;

    private const ERROR_LOG = 'error.log';
    private const API_LOG = 'binance_api.log';
    private const TRADE_LOG = 'trading.log';

    private const CACHE_TTL_EXCHANGE_INFO = 60;
    private const CACHE_TTL_ACCOUNT_INFO = 2;

    private $client;
    private $curlHandle;
    private $restBaseUrl = 'https://api.binance.com';
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
        $binanceConfig = BinanceConfig::getActiveCredentials();
        $this->restBaseUrl = $binanceConfig['restBaseUrl'] ?? $this->restBaseUrl;

        $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
        $configurationBuilder->apiKey($binanceConfig['apiKey']);
        $configurationBuilder->secretKey($binanceConfig['secretKey']);
        $configurationBuilder->url($binanceConfig['baseUrl']);
        $this->client = new SpotRestApi($configurationBuilder->build());
    }

    private function initializeLogger(): void
    {
        $primary = defined('LOG_DIR') ? rtrim(constant('LOG_DIR'), '/') . '/' : '/var/log/';
        $fallbacks = ['/var/log/', rtrim(sys_get_temp_dir(), '/') . '/logs/'];

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
        $basePath = $this->logPath ?: (defined('LOG_DIR') ? rtrim(constant('LOG_DIR'), '/') . '/' : '/var/log/');
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
            // $topMoedas = ["BTCUSDC", "ETHUSDC", "BNBUSDC", "SOLUSDC", "XRPUSDC", "ADAUSDC", "DOTUSDC", "AVAXUSDC", "LTCUSDC", "LINKUSDC", "UNIUSDC"];
            $topMoedas = ["BTCUSDC", "ETHUSDC", "BNBUSDC", "SOLUSDC", "XRPUSDC", "ADAUSDC", "LTCUSDC", "LINKUSDC", "AVAXUSDC", "AAVEUSDC"];
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
            $url = "{$this->restBaseUrl}/api/v3/exchangeInfo?symbols=[{$symbolsParam}]";
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
            $url = "{$this->restBaseUrl}/api/v3/klines?symbol={$symbol}&interval={$interval}&limit={$limit}";
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
            $url = "{$this->restBaseUrl}/api/v3/exchangeInfo?symbol={$symbol}";
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

            $walletTotalUsdc = (float)$usdcBalance['free'] + (float)$usdcBalance['locked'];

            $capitalDisponivel = (float)$usdcBalance['free'];
            $investimento = $this->calcularInvestimento($symbol, $capitalDisponivel);

            if ($investimento == 0) {
                return;
            }

            $precoEntrada = $candle1['preco'];

            // TP1: mesmo racional original (mean - 0.5*desvio)
            $takeProfit1 = $bbReferencia1['media'] - (0.5 * $bbReferencia1['desvioPadrao']);

            // TP2: alvo mais distante (mean + 0.5*desvio)
            $takeProfit2 = $bbReferencia1['media'] + (0.5 * $bbReferencia1['desvioPadrao']);

            // Filtro mínimo continua baseado em TP1 (mais conservador)
            $lucroPercentual1 = (($takeProfit1 - $precoEntrada) / $precoEntrada) * 100;
            if ($lucroPercentual1 < 0.30) {
                return;
            }

            $orderConfigs = $this->getOrderDetailsForSymbol($symbol, $precoEntrada, $takeProfit1, $takeProfit2, $investimento);

            $tradesModel = new trades_model();
            $tradesModel->populate([
                'symbol' => $symbol,
                'status' => 'open',
                'timeframe' => '15m',
                'entry_price' => $precoEntrada,
                'quantity' => $orderConfigs["quantity_total"],
                'investment' => $investimento,
                'take_profit_price' => $takeProfit1, // TP principal (deprecated, mantido para compatibilidade)
                'take_profit_1_price' => $takeProfit1, // Alvo conservador
                'take_profit_2_price' => $takeProfit2, // Alvo agressivo
                'tp1_status' => 'pending',
                'tp2_status' => 'pending',
                'tp1_executed_qty' => 0,
                'tp2_executed_qty' => 0,
                'opened_at' => date('Y-m-d H:i:s')
            ]);
            $tradeIdx = $tradesModel->save();

            try {
                $snapshotId = WalletBalanceHelper::snapshotBeforeTrade($tradeIdx, $walletTotalUsdc);
                if ($snapshotId) {
                    $this->log("Snapshot criado antes do trade #{$tradeIdx}: Saldo USDC = {$walletTotalUsdc}", 'INFO', 'TRADE');
                }
            } catch (Exception $e) {
                $this->log("Erro ao criar snapshot before_trade: " . $e->getMessage(), 'ERROR', 'TRADE');
            }

            $tradeLogsModel = new tradelogs_model();
            $tradeLogsModel->populate([
                'trades_id' => $tradeIdx,
                'log_type' => 'info',
                'event' => 'setup_detected',
                'message' => "Setup de Bollinger (multi-TP) detectado para {$symbol}",
                'data' => json_encode([
                    'entry_price' => $precoEntrada,
                    'take_profit_1' => $takeProfit1,
                    'take_profit_2' => $takeProfit2,
                    'profit_percent_tp1' => $lucroPercentual1,
                    'investment' => $investimento,
                    'wallet_balance_usdc' => $walletTotalUsdc
                ])
            ]);
            $tradeLogsModel->save();

            $this->executarOrdens($symbol, $orderConfigs, $precoEntrada, $tradeIdx);
        } catch (Exception $e) {
            $this->log("Erro ao processar entrada para $symbol: " . $e->getMessage(), 'ERROR', 'TRADE');

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

            // Ordem de Compra (MARKET) – mesma lógica, mas usando quantidade total
            $newOrderReq = new NewOrderRequest();
            $newOrderReq->setSymbol($symbol);
            $newOrderReq->setSide(Side::BUY);
            $newOrderReq->setType(OrderType::MARKET);
            $newOrderReq->setQuantity((float)$orderConfigs["quantity_total"]);

            $orderMarketResp = $this->client->newOrder($newOrderReq);
            $orderMarket = $orderMarketResp->getData();

            $orderId = method_exists($orderMarket, 'getOrderId') ? $orderMarket->getOrderId() : ($orderMarket["orderId"] ?? null);
            $executedQty = method_exists($orderMarket, 'getExecutedQty') ? $orderMarket->getExecutedQty() : ($orderMarket["executedQty"] ?? 0);
            $status = method_exists($orderMarket, 'getStatus') ? $orderMarket->getStatus() : ($orderMarket["status"] ?? 'UNKNOWN');
            $clientOrderId = method_exists($orderMarket, 'getClientOrderId') ? $orderMarket->getClientOrderId() : ($orderMarket["clientOrderId"] ?? null);

            $ordersModel->populate([
                'binance_order_id' => $orderId,
                'binance_client_order_id' => $clientOrderId,
                'symbol' => $symbol,
                'side' => 'BUY',
                'type' => 'MARKET',
                'order_type' => 'entry',
                'price' => $precoEntrada,
                'quantity' => $orderConfigs["quantity_total"],
                'executed_qty' => $executedQty,
                'status' => $status,
                'order_created_at' => round(microtime(true) * 1000),
                'api_response' => json_encode(is_object($orderMarket) ? json_decode(json_encode($orderMarket), true) : $orderMarket)
            ]);
            $buyOrderIdx = $ordersModel->save();

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
                // Supondo que a quantidade executada ≈ quantity_total, distribuímos entre TP1 e TP2
                $qtdTotal = (float)$orderConfigs["quantity_total"];
                if ($qtdTotal <= 0) {
                    return;
                }

                $qtdTp1 = (float)$orderConfigs["quantity_tp1"];
                $qtdTp2 = (float)$orderConfigs["quantity_tp2"];

                // Segurança: se algo sair errado, força soma = quantidade executada
                if (abs(($qtdTp1 + $qtdTp2) - $qtdTotal) > 0.0000001) {
                    $qtdTp1 = $qtdTotal * 0.4;
                    $qtdTp2 = $qtdTotal - $qtdTp1;
                }

                // Respeitar limite MAX_NUM_ALGO_ORDERS (5). MARKET não conta, TP/SL contam.
                $maxAlgoOrders = 5;
                $openAlgoOrders = $this->getOpenAlgoOrdersCount();
                $availableAlgoSlots = $maxAlgoOrders - $openAlgoOrders;

                if ($availableAlgoSlots <= 0) {
                    $this->logTradeOperation($symbol, 'ALGO_LIMIT_REACHED', [
                        'maxAlgo' => $maxAlgoOrders,
                        'openAlgo' => $openAlgoOrders,
                        'skipped' => ['tp1', 'tp2']
                    ]);

                    $tradesLogModelLimit = new tradelogs_model();
                    $tradesLogModelLimit->populate([
                        'trades_id' => $tradeIdx,
                        'log_type' => 'warning',
                        'event' => 'algo_limit_reached',
                        'message' => "TPs não criados: limite de ordens algorítmicas atingido (" . $openAlgoOrders . "/" . $maxAlgoOrders . ")"
                    ]);
                    $tradesLogModelLimit->save();

                    return; // Não cria nenhum TP se já está no limite
                }

                $placeTp1 = $availableAlgoSlots >= 1;
                $placeTp2 = $availableAlgoSlots >= 2;

                // TP1 – TAKE_PROFIT (market quando stopPrice for atingido)
                if ($placeTp1) {
                    $tp1Req = new NewOrderRequest();
                    $tp1Req->setSymbol($symbol);
                    $tp1Req->setSide(Side::SELL);
                    $tp1Req->setType(OrderType::TAKE_PROFIT);
                    $tp1Req->setQuantity($qtdTp1);
                    $tp1Req->setStopPrice((float)$orderConfigs["tp1_price"]);

                    $tp1Resp = $this->client->newOrder($tp1Req);
                    $tp1Order = $tp1Resp->getData();

                    $tp1OrderId = method_exists($tp1Order, 'getOrderId') ? $tp1Order->getOrderId() : ($tp1Order["orderId"] ?? null);
                    $tp1ClientOrderId = method_exists($tp1Order, 'getClientOrderId') ? $tp1Order->getClientOrderId() : ($tp1Order["clientOrderId"] ?? null);
                    $tp1Status = method_exists($tp1Order, 'getStatus') ? $tp1Order->getStatus() : ($tp1Order["status"] ?? 'UNKNOWN');

                    $ordersModelTp1 = new orders_model();
                    $ordersModelTp1->populate([
                        'binance_order_id' => $tp1OrderId,
                        'binance_client_order_id' => $tp1ClientOrderId,
                        'symbol' => $symbol,
                        'side' => 'SELL',
                        'type' => 'TAKE_PROFIT',
                        'order_type' => 'take_profit',
                        'tp_target' => 'tp1',
                        'stop_price' => $orderConfigs["tp1_price"],
                        'quantity' => $qtdTp1,
                        'status' => $tp1Status,
                        'order_created_at' => round(microtime(true) * 1000),
                        'api_response' => json_encode(is_object($tp1Order) ? json_decode(json_encode($tp1Order), true) : $tp1Order)
                    ]);
                    $tp1OrderIdx = $ordersModelTp1->save();
                    $ordersModelTp1->save_attach(['idx' => $tp1OrderIdx, 'post' => ['trades_id' => $tradeIdx]], ['trades']);

                    $this->logTradeOperation($symbol, 'TAKE_PROFIT_1_CREATED', [
                        'orderId' => $tp1OrderId,
                        'quantity' => $qtdTp1,
                        'stopPrice' => $orderConfigs["tp1_price"]
                    ]);

                    $tradesLogModelTp1 = new tradelogs_model();
                    $tradesLogModelTp1->populate([
                        'trades_id' => $tradeIdx,
                        'log_type' => 'success',
                        'event' => 'tp1_created',
                        'message' => "Ordem de TP1 criada: $tp1OrderId",
                        'data' => json_encode([
                            'order_id' => $tp1OrderId,
                            'stop_price' => $orderConfigs["tp1_price"],
                            'quantity' => $qtdTp1
                        ])
                    ]);
                    $tradesLogModelTp1->save();
                }

                // TP2 – TAKE_PROFIT_LIMIT (limit quando stopPrice for atingido) [web:12][web:24][web:65]
                if ($placeTp2) {
                    $tp2Req = new NewOrderRequest();
                    $tp2Req->setSymbol($symbol);
                    $tp2Req->setSide(Side::SELL);
                    $tp2Req->setType(OrderType::TAKE_PROFIT_LIMIT);
                    $tp2Req->setTimeInForce('GTC');
                    $tp2Req->setQuantity($qtdTp2);
                    $tp2Req->setStopPrice((float)$orderConfigs["tp2_stop_price"]);
                    $tp2Req->setPrice((float)$orderConfigs["tp2_limit_price"]);

                    $tp2Resp = $this->client->newOrder($tp2Req);
                    $tp2Order = $tp2Resp->getData();

                    $tp2OrderId = method_exists($tp2Order, 'getOrderId') ? $tp2Order->getOrderId() : ($tp2Order["orderId"] ?? null);
                    $tp2ClientOrderId = method_exists($tp2Order, 'getClientOrderId') ? $tp2Order->getClientOrderId() : ($tp2Order["clientOrderId"] ?? null);
                    $tp2Status = method_exists($tp2Order, 'getStatus') ? $tp2Order->getStatus() : ($tp2Order["status"] ?? 'UNKNOWN');

                    $ordersModelTp2 = new orders_model();
                    $ordersModelTp2->populate([
                        'binance_order_id' => $tp2OrderId,
                        'binance_client_order_id' => $tp2ClientOrderId,
                        'symbol' => $symbol,
                        'side' => 'SELL',
                        'type' => 'TAKE_PROFIT_LIMIT',
                        'order_type' => 'take_profit',
                        'tp_target' => 'tp2',
                        'stop_price' => $orderConfigs["tp2_stop_price"],
                        'price' => $orderConfigs["tp2_limit_price"],
                        'quantity' => $qtdTp2,
                        'status' => $tp2Status,
                        'order_created_at' => round(microtime(true) * 1000),
                        'api_response' => json_encode(is_object($tp2Order) ? json_decode(json_encode($tp2Order), true) : $tp2Order)
                    ]);
                    $tp2OrderIdx = $ordersModelTp2->save();
                    $ordersModelTp2->save_attach(['idx' => $tp2OrderIdx, 'post' => ['trades_id' => $tradeIdx]], ['trades']);

                    $this->logTradeOperation($symbol, 'TAKE_PROFIT_2_CREATED', [
                        'orderId' => $tp2OrderId,
                        'quantity' => $qtdTp2,
                        'stopPrice' => $orderConfigs["tp2_stop_price"],
                        'limitPrice' => $orderConfigs["tp2_limit_price"]
                    ]);

                    $tradesLogModelTp2 = new tradelogs_model();
                    $tradesLogModelTp2->populate([
                        'trades_id' => $tradeIdx,
                        'log_type' => 'success',
                        'event' => 'tp2_created',
                        'message' => "Ordem de TP2 criada: $tp2OrderId",
                        'data' => json_encode([
                            'order_id' => $tp2OrderId,
                            'stop_price' => $orderConfigs["tp2_stop_price"],
                            'limit_price' => $orderConfigs["tp2_limit_price"],
                            'quantity' => $qtdTp2
                        ])
                    ]);
                    $tradesLogModelTp2->save();
                } elseif ($placeTp1 && !$placeTp2) {
                    // Só TP1 coube; registrar que TP2 ficou de fora pelo limite
                    $tradesLogModelTp2Skip = new tradelogs_model();
                    $tradesLogModelTp2Skip->populate([
                        'trades_id' => $tradeIdx,
                        'log_type' => 'warning',
                        'event' => 'tp2_skipped_algo_limit',
                        'message' => "TP2 não criado: faltou slot de ordem algorítmica (" . $openAlgoOrders . "/" . $maxAlgoOrders . ")"
                    ]);
                    $tradesLogModelTp2Skip->save();
                }
            }
        } catch (Exception $e) {
            $this->logBinanceError('executarOrdens', $e->getMessage(), [
                'symbol' => $symbol,
                'orderConfigs' => $orderConfigs
            ]);

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

    public function getOrderDetailsForSymbol(
        string $symbol,
        float $currentPrice,
        float $takeProfit1,
        float $takeProfit2,
        float $investimento
    ): array {
        try {
            $symbolData = $this->getExchangeInfo($symbol);
            list($stepSize, $tickSize) = $this->extractFilters($symbolData);

            $stepSizeFloat = (float)$stepSize;

            // Quantidade total
            $quantityTotal = $this->calculateAdjustedQuantity($investimento, $currentPrice, $stepSize);

            $quantityTotalFloat = (float)$quantityTotal;
            if ($quantityTotalFloat <= 0) {
                return [
                    'quantity_total' => '0',
                    'quantity_tp1' => '0',
                    'quantity_tp2' => '0',
                    'tp1_price' => '0',
                    'tp2_stop_price' => '0',
                    'tp2_limit_price' => '0',
                ];
            }

            // Split: 40% TP1, 60% TP2
            $qtdTp1Float = floor(($quantityTotalFloat * 0.4) / $stepSizeFloat) * $stepSizeFloat;
            $qtdTp2Float = $quantityTotalFloat - $qtdTp1Float;

            $decimalPlacesQty = $this->getDecimalPlaces($stepSize);
            $qtdTp1 = number_format($qtdTp1Float, $decimalPlacesQty, '.', '');
            $qtdTp2 = number_format($qtdTp2Float, $decimalPlacesQty, '.', '');

            // Ajuste de preços para tickSize [web:12][web:17]
            $tp1Price = $this->adjustPriceToTickSize($takeProfit1, $tickSize);

            $tp2StopRaw = $takeProfit2;
            $tp2StopPrice = $this->adjustPriceToTickSize($tp2StopRaw, $tickSize);

            // Para limitar slippage, define-se o limitPrice levemente abaixo do stopPrice (para SELL)
            $tickSizeFloat = (float)$tickSize;
            $tp2LimitRaw = ((float)$tp2StopPrice) - $tickSizeFloat;
            $tp2LimitPrice = $this->adjustPriceToTickSize($tp2LimitRaw, $tickSize);

            return [
                'quantity_total' => $quantityTotal,
                'quantity_tp1' => $qtdTp1,
                'quantity_tp2' => $qtdTp2,
                'tp1_price' => $tp1Price,
                'tp2_stop_price' => $tp2StopPrice,
                'tp2_limit_price' => $tp2LimitPrice,
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

    /**
     * Conta ordens algorítmicas abertas para não estourar MAX_NUM_ALGO_ORDERS (5).
     */
    private function getOpenAlgoOrdersCount(): int
    {
        try {
            $resp = $this->client->getOpenOrders();
            $orders = $resp->getData();

            if (is_object($orders)) {
                $orders = json_decode(json_encode($orders), true);
            }

            if (!is_array($orders)) {
                return 0;
            }

            $algoTypes = [
                'STOP_LOSS',
                'STOP_LOSS_LIMIT',
                'TAKE_PROFIT',
                'TAKE_PROFIT_LIMIT',
                'STOP',
                'TAKE_PROFIT_MARKET'
            ];

            $count = 0;
            foreach ($orders as $order) {
                $type = $order['type'] ?? null;
                if ($type && in_array($type, $algoTypes, true)) {
                    $count++;
                }
            }

            return $count;
        } catch (Exception $e) {
            error_log('Erro ao contar ordens algorítmicas: ' . $e->getMessage());
            return 0;
        }
    }
}
