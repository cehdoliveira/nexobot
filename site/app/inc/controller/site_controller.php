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
                    $symbol = $trade["symbol"];

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
            $sql = "SELECT o.* FROM orders o 
                    INNER JOIN orders_trades ot ON ot.orders_id = o.idx 
                    WHERE o.active = 'yes' 
                    AND ot.active = 'yes' 
                    AND ot.trades_id = '{$tradeIdx}' 
                    AND o.order_type = 'take_profit'
                    AND o.tp_target = '{$tpTarget}'";
            $ordersModel->set_direct_query($sql);
            $ordersModel->load_data();

            if (empty($ordersModel->data)) {
                return;
            }

            $takeProfitOrder = $ordersModel->data[0];
            $binanceOrderId = $takeProfitOrder['binance_order_id'];

            // Consultar status na Binance
            $response = $api->getOrder($symbol, $binanceOrderId);
            $orderData = $response->getData();

            $status = is_array($orderData) ? ($orderData['status'] ?? null) : (method_exists($orderData, 'getStatus') ? $orderData->getStatus() : null);

            if (!$status) {
                return;
            }

            // Se FILLED, processar
            if ($status === 'FILLED') {
                $orderDataArray = is_array($orderData) ? $orderData : json_decode(json_encode($orderData), true);
                $this->processTakeProfitFilled($tradeIdx, $symbol, $tpTarget, $orderDataArray, $takeProfitOrder, $trade, $walletBalance);
            }
        } catch (Exception $e) {
            error_log("Erro ao verificar {$tpTarget} para trade #{$tradeIdx}: " . $e->getMessage());
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
}
