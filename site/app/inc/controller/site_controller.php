<?php

use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;

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

        $tradesModelClosed = new trades_model();
        $tradesModelClosed->set_filter(["active = 'yes'", "status = 'closed'"]);
        $tradesModelClosed->set_order(["closed_at DESC"]);
        $tradesModelClosed->set_paginate([$page, $perPage]);
        $tradesModelClosed->load_data();

        // Paginação manual
        $allClosedTrades = $tradesModelClosed->data;
        $offset = ($page - 1) * $perPage;
        $closedTrades = array_slice($allClosedTrades, $offset, $perPage);
        $totalPages = ceil(count($allClosedTrades) / $perPage);

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
                    $tradeIdx = $trade["idx"];
                    $symbol = $trade["symbol"];

                    // // Buscar ordens deste trade (take_profit e stop_loss) usando JOIN
                    // $sql = "SELECT o.* FROM orders o 
                    //         INNER JOIN orders_trades ot ON ot.orders_id = o.idx 
                    //         WHERE o.active = 'yes' 
                    //         AND ot.active = 'yes' ,
                    //         AND ot.trades_id = '{$tradeIdx}' 
                    //         AND o.order_type IN ('take_profit', 'stop_loss')";
                    // $ordersModel->set_direct_query($sql);

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
                            error_log("Erro ao buscar ordem #{$binanceOrderId}: " . $e->getMessage());
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

            // Buscar ordem de TAKE_PROFIT deste trade no banco usando JOIN
            $ordersModel = new orders_model();
            $sql = "SELECT o.* FROM orders o 
                    INNER JOIN orders_trades ot ON ot.orders_id = o.idx 
                    WHERE o.active = 'yes' 
                    AND ot.active = 'yes' 
                    AND ot.trades_id = '{$tradeIdx}' 
                    AND o.order_type = 'take_profit'";
            $ordersModel->set_direct_query($sql);
            $ordersModel->load_data();

            if (empty($ordersModel->data)) {
                continue;
            }

            $takeProfitOrder = $ordersModel->data[0];
            $binanceOrderId = $takeProfitOrder['binance_order_id'];

            try {
                // Consultar status da ordem na Binance API
                $response = $api->getOrder($symbol, $binanceOrderId);
                $orderData = $response->getData();

                // Extrair status (pode ser array ou objeto)
                $status = null;
                if (is_array($orderData)) {
                    $status = $orderData['status'] ?? null;
                } elseif (is_object($orderData)) {
                    if (method_exists($orderData, 'getStatus')) {
                        $status = $orderData->getStatus();
                    } elseif (property_exists($orderData, 'status')) {
                        $status = $orderData->status;
                    }
                }

                if (!$status) {
                    continue;
                }

                // Se FILLED, fechar o trade
                if ($status === 'FILLED') {

                    // Converter objeto em array se necessário
                    $orderDataArray = is_array($orderData) ? $orderData : json_decode(json_encode($orderData), true);

                    $this->closeTradeWithFilledTakeProfit($tradeIdx, $symbol, $orderDataArray, $currentWalletBalance, $trade, $takeProfitOrder);
                }
            } catch (Exception $e) {
                error_log("Erro ao consultar ordem #{$binanceOrderId} da Binance: " . $e->getMessage());
            }
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
    private function closeTradeWithFilledTakeProfit(
        int $tradeIdx,
        string $symbol,
        array $filledOrder,
        float $walletBalance,
        array $tradeData,
        array $takeProfitOrder
    ): void {
        try {
            // Extrair dados da ordem FILLED
            $sellPrice = (float)($filledOrder['price'] ?? 0);
            $executedQty = (float)($filledOrder['executedQty'] ?? 0);

            // Se price for 0, usar stop_price
            if ($sellPrice == 0) {
                $sellPrice = (float)($takeProfitOrder['stop_price'] ?? 0);
            }

            // Calcular profit/loss
            $buyPrice = (float)($tradeData['entry_price'] ?? 0);
            $investment = (float)($tradeData['investment'] ?? 0);

            $profitLoss = ($sellPrice - $buyPrice) * $executedQty;
            $profitLossPercent = $investment > 0 ? (($profitLoss / $investment) * 100) : 0;

            // Atualizar trade no banco como fechado
            $tradesModel = new trades_model();
            $tradesModel->set_filter(["idx = '{$tradeIdx}'"]);
            $tradesModel->populate([
                'status' => 'closed',
                'exit_price' => $sellPrice,
                'profit_loss' => $profitLoss,
                'profit_loss_percent' => $profitLossPercent,
                'closed_at' => date('Y-m-d H:i:s')
            ]);
            $tradesModel->save();

            // Atualizar status da ordem no banco
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

            // Criar snapshot AFTER_TRADE
            $snapshotId = WalletBalanceHelper::snapshotAfterTrade($tradeIdx, $walletBalance);

            // Registrar log
            $tradeLogsModel = new trade_logs_model();
            $tradeLogsModel->populate([
                'trades_id' => $tradeIdx,
                'log_type' => 'success',
                'event' => 'trade_closed',
                'message' => "Trade fechado - Ordem FILLED na Binance",
                'data' => json_encode([
                    'binance_order_id' => $takeProfitOrder['binance_order_id'],
                    'exit_price' => $sellPrice,
                    'executed_qty' => $executedQty,
                    'profit_loss' => $profitLoss,
                    'profit_loss_percent' => $profitLossPercent,
                    'wallet_balance' => $walletBalance,
                    'snapshot_id' => $snapshotId
                ])
            ]);
            $tradeLogsModel->save();

            error_log("✅ Trade #{$tradeIdx} ({$symbol}) fechado. P/L: $" . number_format($profitLoss, 2) . " USDC ({$profitLossPercent}%)");
        } catch (Exception $e) {
            error_log("❌ Erro ao fechar trade #{$tradeIdx}: " . $e->getMessage());

            // Registrar erro no log
            try {
                $tradeLogsModel = new trade_logs_model();
                $tradeLogsModel->populate([
                    'trades_id' => $tradeIdx,
                    'log_type' => 'error',
                    'event' => 'close_error',
                    'message' => "Erro ao fechar trade: " . $e->getMessage()
                ]);
                $tradeLogsModel->save();
            } catch (Exception $e2) {
                // Ignore
            }
        }
    }
}
