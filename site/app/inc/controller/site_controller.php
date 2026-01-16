<?php

use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;
use Binance\Client\Spot\Model\NewOrderRequest;
use Binance\Client\Spot\Model\Side;
use Binance\Client\Spot\Model\OrderType;

class site_controller
{
    /**
     * Dashboard principal (home logada)
     */
    public function dashboard($info)
    {
        // Verificar login
        if (!auth_controller::check_login()) {
            basic_redir($GLOBALS["login_url"]);
        }

        // Verificar se é uma ação AJAX (antes de renderizar HTML)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json; charset=utf-8');

            // Obter dados da requisição (JSON ou FormData)
            $input = $_POST;
            if (empty($input)) {
                $rawInput = file_get_contents('php://input');
                if (!empty($rawInput)) {
                    $input = json_decode($rawInput, true) ?: [];
                }
            }

            // Se há action, processar como AJAX
            if (!empty($input['action'])) {
                $this->handleAjaxActions($info, $input);
                exit;
            }
        }

        // === BUSCAR TRADES E ESTATÍSTICAS ===
        $tradesModel = new trades_model();
        $tradesModel->set_filter(["active = 'yes'"]);
        $tradesModel->load_data();
        $allTrades = $tradesModel->data;

        $totalTrades = count($allTrades);
        $openTrades = array_filter($allTrades, fn($t) => $t["status"] === "open");
        $closedTradesData = array_filter($allTrades, fn($t) => $t["status"] === "closed");

        // Calcular estatísticas
        $totalInvested = 0;
        $totalProfitLoss = 0;
        $winningTrades = 0;
        $losingTrades = 0;
        $totalProfit = 0;
        $totalLoss = 0;

        foreach ($closedTradesData as $trade) {
            $totalInvested += (float)($trade["investment"] ?? 0);
            $pl = (float)($trade["profit_loss"] ?? 0);
            $totalProfitLoss += $pl;

            if ($pl > 0) {
                $winningTrades++;
                $totalProfit += $pl;
            } elseif ($pl < 0) {
                $losingTrades++;
                $totalLoss += $pl;
            }
        }

        $totalClosedTrades = count($closedTradesData);
        $winRate = $totalClosedTrades > 0 ? round(($winningTrades / $totalClosedTrades) * 100, 2) : 0;
        $avgProfit = $totalClosedTrades > 0 ? round($totalProfitLoss / $totalInvested * 100, 2) : 0;

        // === ESTATÍSTICAS CONSOLIDADAS ===
        $stats = [
            "trades" => [
                "total_trades" => $totalTrades,
                "open_trades" => count($openTrades),
                "closed_trades" => $totalClosedTrades
            ],
            "financial" => [
                "total_invested" => $totalInvested,
                "total_profit_loss" => $totalProfitLoss,
                "avg_profit_percent" => $avgProfit,
                "total_profit" => $totalProfit,
                "total_loss" => $totalLoss,
                "winning_trades" => $winningTrades,
                "losing_trades" => $losingTrades,
                "win_rate" => $winRate
            ]
        ];

        // === PAGINAÇÃO DE TRADES FECHADOS ===
        $perPage = isset($info["get"]["paginate"]) && (int)$info["get"]["paginate"] > 20 ? $info["get"]["paginate"] : 20;
        $page = isset($info["sr"]) && (int)$info["sr"] > 0 ? (int)$info["sr"] : 1;

        // Usar os dados de closedTradesData já filtrados e ordenados
        usort($closedTradesData, fn($a, $b) => strtotime($b['closed_at'] ?? '0') - strtotime($a['closed_at'] ?? '0'));

        $offset = ($page - 1) * $perPage;
        $closedTrades = array_slice($closedTradesData, $offset, $perPage);
        $totalPages = ceil(count($closedTradesData) / $perPage);

        // === BUSCAR SALDO DA CARTEIRA NA BINANCE ===
        try {
            $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
            $configurationBuilder->apiKey(binanceAPIKey)->secretKey(binanceSecretKey);
            $api = new SpotRestApi($configurationBuilder->build());

            $accountInfo = $api->getAccount();
            $accountData = $accountInfo->getData();

            $walletTotal = 0;
            $walletBalances = [];

            if ($accountData && isset($accountData["balances"])) {
                foreach ($accountData["balances"] as $balance) {
                    $free = (float)$balance["free"];
                    $locked = (float)$balance["locked"];
                    $total = $free + $locked;

                    if ($total > 0) {
                        $walletBalances[] = [
                            "asset" => $balance["asset"],
                            "free" => $free,
                            "locked" => $locked,
                            "total" => $total
                        ];

                        // Contabilizar USDC no total
                        if (in_array($balance["asset"], ["USDC"])) {
                            $walletTotal += $total;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Erro ao buscar saldo da carteira: " . $e->getMessage());
            $walletTotal = 0;
            $walletBalances = [];
        }

        // === BUSCAR ORDENS ABERTAS (TAKE_PROFIT E STOP_LOSS DOS TRADES ATIVOS) ===
        $ordensAbertas = [];
        try {
            // Buscar ordens do banco de dados que estão associadas a trades abertos
            if (!empty($openTrades)) {
                $ordersModel = new orders_model();

                foreach ($openTrades as $trade) {
                    $symbol = $trade["symbol"];
                    $ordersModel->set_filter(["idx IN (SELECT orders_id FROM orders_trades WHERE active = 'yes' AND trades_id = '{$trade['idx']}')"]);
                    $ordersModel->attach(["trades"]);
                    $ordersModel->load_data();

                    foreach ($ordersModel->data as $dbOrder) {
                        $binanceOrderId = $dbOrder['binance_order_id'];

                        try {
                            // Buscar status atual na Binance
                            $orderResp = $api->getOrder($symbol, $binanceOrderId);
                            $orderData = $orderResp->getData();

                            // Converter para array se necessário
                            if (!is_array($orderData)) {
                                $orderData = json_decode(json_encode($orderData), true);
                            }

                            // Se a ordem ainda está ativa (NEW, PARTIALLY_FILLED, etc)
                            $status = $orderData['status'] ?? 'N/A';
                            if (in_array($status, ['NEW', 'PARTIALLY_FILLED'])) {
                                $ordensAbertas[] = [
                                    'orderId' => $orderData['orderId'] ?? $binanceOrderId,
                                    'symbol' => $orderData['symbol'] ?? $symbol,
                                    'side' => $orderData['side'] ?? strtoupper($dbOrder['side']),
                                    'type' => $orderData['type'] ?? strtoupper($dbOrder['order_type']),
                                    'price' => $orderData['price'] ?? '0',
                                    'stopPrice' => $orderData['stopPrice'] ?? ($dbOrder['stop_price'] ?? '0'),
                                    'origQty' => $orderData['origQty'] ?? ($dbOrder['quantity'] ?? '0'),
                                    'executedQty' => $orderData['executedQty'] ?? '0',
                                    'status' => $status,
                                    'time' => isset($orderData['time']) ? date('d/m/Y H:i', (int)($orderData['time'] / 1000)) : 'N/A',
                                ];
                            }
                        } catch (Exception $e) {
                            // Silenciar erro de ordem não encontrada (esperado para ordens antigas da testnet)
                            if (!isBinanceOrderNotFoundError($e)) {
                                error_log("Erro ao buscar ordem #{$binanceOrderId}: " . $e->getMessage());
                            }
                        }
                    }
                }
            }

            // Verificar se algum trade foi executado e precisa ser fechado
            $this->checkAndCloseCompletedTrades($openTrades, [], $api, $walletTotal);

            // Recarregar trades após possível fechamento
            $tradesModel->set_filter(["active = 'yes'"]);
            $tradesModel->load_data();
            $allTrades = $tradesModel->data;
            $openTrades = array_filter($allTrades, fn($t) => $t['status'] === 'open');
        } catch (Exception $e) {
            error_log("Erro ao buscar ordens abertas: " . $e->getMessage());
        }

        // === BUSCAR CRESCIMENTO PATRIMONIAL ===
        $patrimonialGrowth = WalletBalanceHelper::getTotalGrowth();

        // === PREPARAR DADOS PARA A VIEW ===
        $dashboardData = [
            'stats' => $stats,
            'wallet_total' => $walletTotal,
            'wallet_balances' => $walletBalances,
            'patrimonial_growth' => $patrimonialGrowth,
            'open_trades' => array_values($openTrades),
            'open_orders' => $ordensAbertas,
            'closed_trades' => $closedTrades,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'per_page' => $perPage,
                'total_records' => $totalClosedTrades
            ]
        ];

        // Renderizar view
        $alpineControllers = ['dashboard'];
        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/dashboard.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    /**
     * Redireciona para o dashboard (página padrão)
     */
    public function display($info)
    {
        basic_redir($GLOBALS["home_url"]);
    }

    /**
     * Verifica trades abertos e fecha automaticamente aqueles cujo TAKE_PROFIT foi executado
     * Suporta múltiplos TPs (TP1 e TP2)
     * 
     * @param array $openTrades Lista de trades com status 'open'
     * @param array $binanceOrders (não utilizado - mantido por compatibilidade)
     * @param SpotRestApi $api Instância da API Binance
     * @param float $currentWalletBalance Saldo atual da carteira
     */
    private function checkAndCloseCompletedTrades(array $openTrades, $binanceOrders, $api, float $currentWalletBalance): void
    {
        if (empty($openTrades)) {
            return;
        }

        foreach ($openTrades as $trade) {
            $tradeIdx = $trade['idx'];
            $symbol = $trade['symbol'];

            // Verificar TP1
            if (($trade['tp1_status'] ?? 'pending') !== 'filled' && ($trade['tp1_status'] ?? 'pending') !== 'cancelled') {
                $this->checkAndExecuteTargetProfit($tradeIdx, $symbol, 'tp1', $trade, $api, $currentWalletBalance);
            }

            // Verificar TP2
            if (($trade['tp2_status'] ?? 'pending') !== 'filled' && ($trade['tp2_status'] ?? 'pending') !== 'cancelled') {
                $this->checkAndExecuteTargetProfit($tradeIdx, $symbol, 'tp2', $trade, $api, $currentWalletBalance);
            }

            // Se ambos os TPs foram preenchidos, fechar o trade
            if (($trade['tp1_status'] ?? 'pending') === 'filled' && ($trade['tp2_status'] ?? 'pending') === 'filled') {
                $this->finalizeTradeAfterBothTPs($tradeIdx, $symbol, $trade, $currentWalletBalance);
            }
        }
    }

    /**
     * Verifica e executa um target de TP específico
     * 
     * @param int $tradeIdx ID do trade
     * @param string $symbol Par de trading
     * @param string $tpTarget 'tp1' ou 'tp2'
     * @param array $trade Dados do trade
     * @param SpotRestApi $api API Binance
     * @param float $walletBalance Saldo atual da carteira
     */
    private function checkAndExecuteTargetProfit(int $tradeIdx, string $symbol, string $tpTarget, array $trade, $api, float $walletBalance): void
    {
        try {
            $tpPriceColumn = $tpTarget === 'tp1' ? 'take_profit_1_price' : 'take_profit_2_price';
            $tpStatusColumn = $tpTarget === 'tp1' ? 'tp1_status' : 'tp2_status';
            $tpTargetPrice = (float)($trade[$tpPriceColumn] ?? 0);

            if ($tpTargetPrice == 0) {
                return;
            }

            // Buscar ordem de TP deste trade
            $ordersModel = new orders_model();
            $ordersModel->set_filter(["idx IN (SELECT orders_id FROM orders_trades WHERE active = 'yes' AND trades_id = '{$tradeIdx}') AND order_type = 'take_profit' AND tp_target = '{$tpTarget}'"]);
            $ordersModel->attach(["trades"]);
            $ordersModel->load_data();

            if (empty($ordersModel->data)) {
                return;
            }

            $takeProfitOrder = $ordersModel->data[0];
            $binanceOrderId = $takeProfitOrder['binance_order_id'];

            // Consultar status na Binance
            try {
                $response = $api->getOrder($symbol, $binanceOrderId);
                $orderData = $response->getData();
            } catch (Exception $apiEx) {
                // Ordem não existe mais na Binance (comum em testnet)
                if (isBinanceOrderNotFoundError($apiEx)) {
                    return;
                }
                throw $apiEx; // Re-lançar outros erros
            }

            $status = is_array($orderData) ? ($orderData['status'] ?? null) : (method_exists($orderData, 'getStatus') ? $orderData->getStatus() : null);

            if (!$status) {
                return;
            }

            // Se FILLED, validar se o preço realmente atingiu o target
            if ($status === 'FILLED') {
                $orderDataArray = is_array($orderData) ? $orderData : json_decode(json_encode($orderData), true);

                // Obter stop price da ordem
                $stopPrice = (float)($orderDataArray['stopPrice'] ?? 0);

                if ($stopPrice == 0) {
                    error_log("⚠️ Ordem {$binanceOrderId} marcada como FILLED mas sem stopPrice definido");
                    return;
                }

                // Verificar se o preço atual realmente atingiu o target
                $currentPrice = $this->getCurrentPrice($symbol, $api);

                if ($currentPrice === null) {
                    error_log("⚠️ Não foi possível obter preço atual de {$symbol} para validar {$tpTarget}");
                    return;
                }

                // Validar se o preço atingiu o target (com tolerância de 0.1%)
                $tolerance = 0.001; // 0.1%
                $priceReached = $currentPrice >= ($stopPrice * (1 - $tolerance));

                if (!$priceReached) {
                    error_log("⚠️ {$tpTarget} do trade #{$tradeIdx} ({$symbol}) marcado como FILLED na Binance, mas preço atual (\${$currentPrice}) não atingiu target (\${$stopPrice}). IGNORANDO.");
                    return;
                }

                // Preço validado, processar
                $this->processTakeProfitFilled($tradeIdx, $symbol, $tpTarget, $orderDataArray, $takeProfitOrder, $trade, $walletBalance);
            }
        } catch (Exception $e) {
            error_log("Erro ao verificar {$tpTarget} para trade #{$tradeIdx}: " . $e->getMessage());
        }
    }

    /**
     * Obtém o preço atual de um símbolo na Binance
     */
    private function getCurrentPrice(string $symbol, $api): ?float
    {
        try {
            $response = $api->tickerPrice($symbol);
            $data = $response->getData();

            // A API retorna objeto com estrutura aninhada
            if ($data && method_exists($data, 'getTickerPriceResponse1')) {
                $priceData = $data->getTickerPriceResponse1();
                if ($priceData && method_exists($priceData, 'getPrice')) {
                    return (float)$priceData->getPrice();
                }
            }

            // Fallback: tentar acessar diretamente
            if ($data && method_exists($data, 'getPrice')) {
                return (float)$data->getPrice();
            }

            return null;
        } catch (Exception $e) {
            error_log("Erro ao obter preço atual de {$symbol}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Processa um TP que foi executado (FILLED)
     */
    private function processTakeProfitFilled(int $tradeIdx, string $symbol, string $tpTarget, array $filledOrder, array $takeProfitOrder, array $tradeData, float $walletBalance): void
    {
        try {
            $tpStatusColumn = $tpTarget === 'tp1' ? 'tp1_status' : 'tp2_status';
            $tpExecutedColumn = $tpTarget === 'tp1' ? 'tp1_executed_qty' : 'tp2_executed_qty';

            $sellPrice = (float)($filledOrder['price'] ?? 0);
            $executedQty = (float)($filledOrder['executedQty'] ?? 0);

            if ($sellPrice == 0) {
                $sellPrice = (float)($takeProfitOrder['price'] ?? 0);
            }

            // Atualizar status do TP
            $tradesModel = new trades_model();
            $tradesModel->set_filter(["idx = '{$tradeIdx}'"]);
            $updateData = [
                $tpStatusColumn => 'filled',
                $tpExecutedColumn => $executedQty
            ];
            $tradesModel->populate($updateData);
            $tradesModel->save();

            // Atualizar ordem
            $ordersModel = new orders_model();
            $ordersModel->set_filter([
                "binance_order_id = '{$takeProfitOrder['binance_order_id']}'"
            ]);
            $ordersModel->populate([
                'status' => 'FILLED',
                'executed_qty' => $executedQty,
                'order_updated_at' => round(microtime(true) * 1000)
            ]);
            $ordersModel->save();

            // Log
            $formattedSellPrice = number_format($sellPrice, 2);
            error_log("✅ {$tpTarget} do trade #{$tradeIdx} ({$symbol}) FILLED - Qtd: {$executedQty} @ \$" . $formattedSellPrice);
        } catch (Exception $e) {
            error_log("❌ Erro ao processar {$tpTarget} FILLED para trade #{$tradeIdx}: " . $e->getMessage());
        }
    }

    /**
     * Finaliza o trade após ambos os TPs serem preenchidos
     */
    private function finalizeTradeAfterBothTPs(int $tradeIdx, string $symbol, array $trade, float $walletBalance): void
    {
        try {
            $buyPrice = (float)($trade['entry_price'] ?? 0);
            $investment = (float)($trade['investment'] ?? 0);
            $quantity = (float)($trade['quantity'] ?? 0);

            $tp1Qty = (float)($trade['tp1_executed_qty'] ?? 0);
            $tp2Qty = (float)($trade['tp2_executed_qty'] ?? 0);
            $tp1Price = (float)($trade['take_profit_1_price'] ?? 0);
            $tp2Price = (float)($trade['take_profit_2_price'] ?? 0);

            // Calcular P/L total
            $profitLoss = ($tp1Qty * $tp1Price) + ($tp2Qty * $tp2Price) - $investment;
            $profitLossPercent = $investment > 0 ? (($profitLoss / $investment) * 100) : 0;

            // Determinar preço de saída médio
            $totalExited = $tp1Qty + $tp2Qty;
            $avgExitPrice = $totalExited > 0 ? (($tp1Qty * $tp1Price) + ($tp2Qty * $tp2Price)) / $totalExited : 0;

            // Fechar trade
            $tradesModel = new trades_model();
            $tradesModel->set_filter(["idx = '{$tradeIdx}'"]);
            $tradesModel->populate([
                'status' => 'closed',
                'exit_price' => $avgExitPrice,
                'profit_loss' => $profitLoss,
                'profit_loss_percent' => $profitLossPercent,
                'closed_at' => date('Y-m-d H:i:s')
            ]);
            $tradesModel->save();

            // Snapshot
            $snapshotId = WalletBalanceHelper::snapshotAfterTrade($tradeIdx, $walletBalance);

            // Log
            $tradeLogsModel = new tradelogs_model();
            $tradeLogsModel->populate([
                'trades_id' => $tradeIdx,
                'log_type' => 'success',
                'event' => 'trade_closed_both_tps',
                'message' => "Trade finalizado - Ambos os TPs executados",
                'data' => json_encode([
                    'tp1_qty' => $tp1Qty,
                    'tp1_price' => $tp1Price,
                    'tp2_qty' => $tp2Qty,
                    'tp2_price' => $tp2Price,
                    'avg_exit_price' => $avgExitPrice,
                    'profit_loss' => $profitLoss,
                    'profit_loss_percent' => $profitLossPercent,
                    'wallet_balance' => $walletBalance,
                    'snapshot_id' => $snapshotId
                ])
            ]);
            $tradeLogsModel->save();

            error_log("✅ Trade #{$tradeIdx} ({$symbol}) FINALIZADO. P/L: $" . number_format($profitLoss, 2) . " ({$profitLossPercent}%)");
        } catch (Exception $e) {
            error_log("❌ Erro ao finalizar trade #{$tradeIdx}: " . $e->getMessage());
        }
    }

    /**
     * Fecha um trade quando sua ordem de TAKE_PROFIT foi executada (status FILLED)
     * 
     * Atualiza o trade como 'closed', calcula profit/loss, atualiza a ordem no banco
     * e cria um snapshot do saldo da carteira após o fechamento.
     * 
     * @param int $tradeIdx ID do trade
     * @param string $symbol Símbolo do par (ex: BTCUSDT)
     * @param array $filledOrder Dados da ordem executada retornados pela API Binance
     * @param float $walletBalance Saldo atual da carteira
     * @param array $tradeData Dados completos do trade
     * @param array $takeProfitOrder Dados da ordem de take profit do banco
     */

    /**
     * Manipula ações AJAX do dashboard
     */
    private function handleAjaxActions($info, $input = [])
    {
        // Verificar login
        if (!auth_controller::check_login()) {
            echo json_encode(['success' => false, 'message' => 'Não autenticado'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Obter dados da requisição se não fornecidos
        if (empty($input)) {
            $input = $_POST;
            if (empty($input)) {
                $rawInput = file_get_contents('php://input');
                if (!empty($rawInput)) {
                    $input = json_decode($rawInput, true) ?: [];
                }
            }
        }

        $action = $input['action'] ?? null;

        switch ($action) {
            case 'closeAllPositions':
                $this->closeAllPositions();
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Ação não encontrada'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        exit;
    }

    /**
     * Encerra todas as posições abertas na Binance
     * - Busca as ordens do banco de dados associadas aos trades abertos
     * - Verifica o status REAL de cada ordem na Binance
     * - Se FILLED: atualiza banco de dados
     * - Se ABERTA: cancela
     * - Vende os ativos dos trades abertos
     */
    private function closeAllPositions()
    {
        try {
            // Inicializar API Binance
            $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
            $configurationBuilder->apiKey(binanceAPIKey)->secretKey(binanceSecretKey);
            $api = new SpotRestApi($configurationBuilder->build());

            $cancelledOrders = [];
            $filledOrders = [];
            $soldPositions = [];
            $errors = [];
            $symbolsToSell = []; // Guardar símbolos dos trades abertos

            // 1. Buscar TODOS os trades abertos no banco de dados
            $tradesModel = new trades_model();
            $tradesModel->set_filter(["active = 'yes'", "status = 'open'"]);
            $tradesModel->load_data();
            $openTrades = $tradesModel->data;

            if (empty($openTrades)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Nenhum trade aberto para encerrar',
                    'cancelled_orders' => [],
                    'filled_orders' => [],
                    'sold_positions' => [],
                    'errors' => []
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 2. Para cada trade aberto, buscar e processar as ordens associadas
            foreach ($openTrades as $trade) {
                $tradeIdx = $trade['idx'];
                $symbol = $trade['symbol'];
                $quantity = (float)($trade['quantity'] ?? 0);

                $symbolsToSell[$symbol] = $quantity; // Guardar para venda posterior

                // Buscar ordens deste trade no banco de dados
                $ordersModel = new orders_model();
                $ordersModel->set_filter(["idx IN (SELECT orders_id FROM orders_trades WHERE active = 'yes' AND trades_id = '{$tradeIdx}')"]);
                $ordersModel->attach(['trades']);
                $ordersModel->load_data();

                // Processar cada ordem
                foreach ($ordersModel->data as $order) {
                    try {
                        $binanceOrderId = $order['binance_order_id'];

                        // Consultar status REAL na Binance
                        $actualStatus = null;
                        $actualOrderData = null;

                        try {
                            $response = $api->getOrder($symbol, $binanceOrderId);
                            $actualOrderData = $response->getData();
                            $actualStatus = is_array($actualOrderData) ? ($actualOrderData['status'] ?? null) : $actualOrderData->getStatus();
                        } catch (Exception $apiEx) {
                            if (isBinanceOrderNotFoundError($apiEx)) {
                                $actualStatus = 'NOT_FOUND';
                            } else {
                                throw $apiEx;
                            }
                        }

                        // Processar baseado no status
                        if ($actualStatus === 'FILLED') {
                            // Ordem já foi preenchida - atualizar no banco de dados
                            $ordersModel->set_filter(["idx = '{$order['idx']}'"]);
                            $ordersModel->populate([
                                'status' => 'FILLED',
                                'executed_qty' => $actualOrderData['executedQty'] ?? $actualOrderData->getExecutedQty() ?? $order['quantity']
                            ]);
                            $ordersModel->save();

                            $filledOrders[] = [
                                'symbol' => $symbol,
                                'orderId' => $binanceOrderId,
                                'type' => $order['order_type'] ?? 'N/A',
                                'status' => 'FILLED (já estava preenchida)'
                            ];
                        } elseif ($actualStatus === 'NOT_FOUND' || $actualStatus === 'CANCELLED') {
                            // Ordem já foi cancelada na Binance

                            // Apenas marcar como cancelada no banco
                            $ordersModel->set_filter(["idx = '{$order['idx']}'"]);
                            $ordersModel->populate(['active' => 'no', 'status' => 'CANCELLED']);
                            $ordersModel->save();

                            $cancelledOrders[] = [
                                'symbol' => $symbol,
                                'orderId' => $binanceOrderId,
                                'type' => $order['order_type'] ?? 'N/A',
                                'status' => 'Já estava cancelada'
                            ];
                        } else {
                            // Ordem está aberta (NEW, PARTIALLY_FILLED, etc) - CANCELAR
                            try {
                                $api->deleteOrder($symbol, $binanceOrderId);
                            } catch (Exception $cancelEx) {
                                // Código -2011 = "Unknown order sent" = ordem já foi FILLED ou deletada
                                if (strpos($cancelEx->getMessage(), '-2011') === false && !isBinanceOrderNotFoundError($cancelEx)) {
                                    throw $cancelEx;
                                }
                            }

                            // Cancelar no banco de dados
                            $ordersModel->set_filter(["idx = '{$order['idx']}'"]);
                            $ordersModel->populate(['active' => 'no', 'status' => 'CANCELLED']);
                            $ordersModel->save();

                            $cancelledOrders[] = [
                                'symbol' => $symbol,
                                'orderId' => $binanceOrderId,
                                'type' => $order['order_type'] ?? 'N/A'
                            ];
                        }
                    } catch (Exception $e) {
                        $errors[] = "Erro ao processar ordem #{$order['binance_order_id']}: " . $e->getMessage();
                    }
                }
            }

            // 3. Vender os ativos dos trades abertos
            foreach ($symbolsToSell as $symbol => $quantity) {
                if (!$symbol || $quantity <= 0) {
                    continue;
                }

                try {
                    // Extrair o asset do símbolo (ex: "SOLUSDC" -> "SOL")
                    $asset = str_replace('USDC', '', $symbol);

                    // Buscar saldo atual da conta
                    $accountInfo = $api->getAccount();
                    $accountData = $accountInfo->getData();

                    $assetFound = false;
                    if ($accountData && isset($accountData["balances"])) {
                        foreach ($accountData["balances"] as $balance) {
                            if ($balance["asset"] === $asset) {
                                $assetFound = true;
                                $free = (float)($balance["free"] ?? 0);

                                if ($free > 0) {
                                    // Vender tudo do ativo

                                    $newOrderReq = new NewOrderRequest();
                                    $newOrderReq->setSymbol($symbol);
                                    $newOrderReq->setSide(Side::SELL);
                                    $newOrderReq->setType(OrderType::MARKET);
                                    $newOrderReq->setQuantity($free);

                                    $response = $api->newOrder($newOrderReq);

                                    $orderData = $response->getData();
                                    $orderId = method_exists($orderData, 'getOrderId') ? $orderData->getOrderId() : ($orderData['orderId'] ?? null);

                                    $soldPositions[] = [
                                        'symbol' => $symbol,
                                        'quantity' => $free,
                                        'orderId' => $orderId
                                    ];
                                }
                                break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    $asset = str_replace('USDC', '', $symbol);
                    $errors[] = "Erro ao vender {$asset}: " . $e->getMessage();
                }
            }

            // Montar mensagem de sucesso
            $totalProcessed = count($cancelledOrders) + count($filledOrders);
            $message = [];
            $message[] = "Ordens processadas: {$totalProcessed}";
            if (!empty($cancelledOrders)) {
                $message[] = "Canceladas: " . count($cancelledOrders);
            }
            if (!empty($filledOrders)) {
                $message[] = "Já preenchidas: " . count($filledOrders);
            }
            $message[] = "Posições vendidas: " . count($soldPositions);

            if (!empty($errors)) {
                $message[] = "Erros: " . count($errors);
            }

            // ========================================
            // 4. FECHAR OS TRADES NO BANCO DE DADOS
            // ========================================
            $closedTradesCount = 0;

            foreach ($openTrades as $trade) {
                try {
                    // Criar nova instância do model para cada trade
                    $tradeModel = new trades_model();
                    $tradeModel->set_filter(["idx = '{$trade['idx']}'"]);
                    $tradeModel->load_data();

                    if (!empty($tradeModel->data)) {
                        $tradeData = $tradeModel->data[0];

                        // Calcular o preço de saída médio baseado nas vendas realizadas
                        $exitPrice = 0;
                        $symbol = $tradeData['symbol'];

                        // Se vendemos este símbolo, usar o preço de venda
                        if (isset($soldPositions[$symbol])) {
                            $exitPrice = $soldPositions[$symbol]['price'];
                        } else {
                            // Se não vendemos, é porque já estava vendido (TP executado)
                            // Buscar o preço da última ordem FILLED deste trade
                            $exitPrice = null;

                            // Procurar ordens FILLED deste trade
                            foreach ($filledOrders as $filledOrder) {
                                if ($filledOrder['symbol'] === $symbol) {
                                    // Buscar detalhes da ordem FILLED na Binance
                                    try {
                                        $orderResp = $api->getOrder($symbol, $filledOrder['orderId']);
                                        $orderData = $orderResp->getData();

                                        if (!is_array($orderData)) {
                                            $orderData = json_decode(json_encode($orderData), true);
                                        }

                                        // Preço médio de execução
                                        $exitPrice = floatval($orderData['price'] ?? $orderData['avgPrice'] ?? 0);

                                        if ($exitPrice > 0) {
                                            break; // Encontrou o preço
                                        }
                                    } catch (Exception $priceEx) {
                                        // Silenciar erro de busca de preço
                                    }
                                }
                            }

                            // Se ainda não encontrou o preço, usar entry_price (assume break-even)
                            if (!$exitPrice || $exitPrice <= 0) {
                                $exitPrice = floatval($tradeData['entry_price']);
                            }
                        }

                        // Calcular P/L
                        $entryPrice = floatval($tradeData['entry_price']);
                        $quantity = floatval($tradeData['quantity']);
                        $profitLoss = ($exitPrice - $entryPrice) * $quantity;
                        $profitLossPercent = (($exitPrice - $entryPrice) / $entryPrice) * 100;

                        // Atualizar o trade como fechado
                        $tradeModel->populate([
                            'status' => 'closed',
                            'exit_type' => 'emergency_close',
                            'exit_price' => $exitPrice,
                            'profit_loss' => $profitLoss,
                            'profit_loss_percent' => $profitLossPercent,
                            'closed_at' => date('Y-m-d H:i:s')
                        ]);

                        $tradeModel->save(); // DOLModel vai limpar cache automaticamente
                        $closedTradesCount++;
                    }
                } catch (Exception $closeEx) {
                    $errors[] = "Erro ao fechar trade #{$trade['idx']}: " . $closeEx->getMessage();
                }
            }

            // ========================================
            // 5. LIMPAR CACHE DO REDIS ANTES DE RETORNAR
            // ========================================
            try {
                $redis = RedisCache::getInstance();
                $redis->deletePattern('*trades*');
                $redis->deletePattern('*orders*');
                $redis->deletePattern('*walletbalances*');
                $redis->deletePattern('*dashboard*');
            } catch (Exception $cacheEx) {
                error_log("⚠️ Erro ao limpar cache do Redis: " . $cacheEx->getMessage());
            }

            echo json_encode([
                'success' => true,
                'message' => implode(' | ', $message),
                'cancelled_orders' => $cancelledOrders,
                'filled_orders' => $filledOrders,
                'sold_positions' => $soldPositions,
                'closed_trades' => $closedTradesCount,
                'errors' => $errors
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            error_log("❌ Erro ao encerrar posições: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao encerrar posições: ' . $e->getMessage()
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
}