<?php

use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;
use Binance\Client\Spot\Model\NewOrderRequest;
use Binance\Client\Spot\Model\Side;
use Binance\Client\Spot\Model\OrderType;

class site_controller
{
    private const BINANCE_RECV_WINDOW = 10000;


    /**
     * Dashboard do Grid Trading
     */
    public function dashboard($info)
    {
        // Verificar login
        if (!auth_controller::check_login()) {
            basic_redir($GLOBALS["login_url"]);
        }

        // Verificar se é uma ação AJAX
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            ob_start();
            header('Content-Type: application/json; charset=utf-8');

            $input = $_POST;
            if (empty($input)) {
                $rawInput = file_get_contents('php://input');
                if (!empty($rawInput)) {
                    $input = json_decode($rawInput, true) ?: [];
                }
            }

            if (!empty($input['action'])) {
                $action = $input['action'];

                // Route AJAX actions
                switch ($action) {
                    case 'refreshGridData':
                        echo json_encode(['success' => true, 'message' => 'Dados atualizados com sucesso']);
                        exit;

                    case 'getCurrentPrice':
                        $this->ajaxGetCurrentPrice();
                        exit;

                    case 'getGridDashboardData':
                        $this->ajaxGetGridDashboardData();
                        exit;

                    case 'clearCache':
                        $this->clearCache();
                        exit;

                    case 'closeAllPositions':
                        $this->ajaxCloseAllGridPositions();
                        exit;

                    case 'stopBot':
                        $this->ajaxStopBot();
                        exit;

                    case 'emergencyShutdown':
                        $this->ajaxEmergencyShutdown();
                        exit;

                    case 'resetGrid':
                        $this->ajaxResetGrid();
                        exit;

                    case 'restartGrid':
                        $this->ajaxRestartGrid();
                        exit;
                    default:
                        echo json_encode(['success' => false, 'message' => 'Ação não encontrada']);
                        exit;
                }
            }
        }

        // === BUSCAR DADOS DOS GRIDS ===
        $gridsModel = new grids_model();
        $gridsModel->set_filter(["active = 'yes'"]);
        $gridsModel->load_data();
        $allGrids = $gridsModel->data;
        usort($allGrids, function ($a, $b) {
            $aActive = (($a['status'] ?? '') === 'active') ? 1 : 0;
            $bActive = (($b['status'] ?? '') === 'active') ? 1 : 0;
            if ($aActive !== $bActive) {
                return $bActive <=> $aActive;
            }
            return ((int)($b['idx'] ?? 0)) <=> ((int)($a['idx'] ?? 0));
        });

        $this->checkCronHealthAndNotify($allGrids[0] ?? null);

        // === BUSCAR ORDENS DE GRID (com relacionamento manual) ===
        $gridsOrdersModel = new grids_orders_model();
        // Carrega todas as grids_orders (ativas e inativas) usando filtro que sempre é verdadeiro
        // para incluir tanto ordens abertas quanto executadas no histórico
        $gridsOrdersModel->set_filter(["1=1"]);
        $gridsOrdersModel->load_data();
        $gridOrdersData = $gridsOrdersModel->data;

        // Carregar ordens relacionadas
        if (!empty($gridOrdersData)) {
            $orderIds = array_column($gridOrdersData, 'orders_id');

            $ordersModel = new orders_model();
            // Carrega todas as ordens (ativas e inativas) para exibir histórico completo
            $ordersModel->set_filter([
                "1=1",
                "idx IN (" . implode(',', array_map('intval', $orderIds)) . ")"
            ]);
            $ordersModel->load_data();

            // Mapear ordens por ID
            $ordersMap = [];
            foreach ($ordersModel->data as $order) {
                $ordersMap[$order['idx']] = $order;
            }

            // Anexar ordens aos grid_orders
            $allGridOrders = [];
            foreach ($gridOrdersData as $gridOrder) {
                if (isset($ordersMap[$gridOrder['orders_id']])) {
                    $gridOrder['orders'] = [$ordersMap[$gridOrder['orders_id']]];
                    $allGridOrders[] = $gridOrder;
                }
            }
        } else {
            $allGridOrders = [];
        }

        // === BUSCAR LOGS DE GRID ===
        $gridLogsModel = new grid_logs_model();
        $gridLogsModel->load_data();
        $gridLogs = $gridLogsModel->data;

        // === PROCESSAR ESTATÍSTICAS ===
        $activeGrids = array_filter($allGrids, fn($g) => $g['status'] === 'active');

        // Ordens abertas = NEW ou PARTIALLY_FILLED
        $openOrders = array_filter($allGridOrders, function ($o) {
            $status = $o['orders'][0]['status'] ?? null;
            return in_array($status, ['NEW', 'PARTIALLY_FILLED']);
        });

        // Ordens fechadas = FILLED ou CANCELED
        $closedOrders = array_filter($allGridOrders, function ($o) {
            $status = $o['orders'][0]['status'] ?? null;
            return in_array($status, ['FILLED', 'CANCELED', 'EXPIRED', 'REJECTED']);
        });

        // TODAS as ordens (abertas + fechadas/executadas) para exibição no dashboard
        // Ordenar pela data de criação mais recente primeiro
        $allOrdersforDisplay = $allGridOrders;
        usort($allOrdersforDisplay, fn($a, $b) =>
            strtotime($b['created_at'] ?? '0') <=> strtotime($a['created_at'] ?? '0')
        );

        // Total de lucro
        $totalProfit = 0;
        foreach ($allGrids as $grid) {
            $totalProfit += (float)($grid['accumulated_profit_usdc'] ?? 0);
        }

        // Capital total alocado
        $totalCapitalAllocated = 0;
        foreach ($activeGrids as $grid) {
            $totalCapitalAllocated += (float)($grid['capital_allocated_usdc'] ?? 0);
        }

        // Estatísticas por símbolo
        $symbolsStats = [];
        foreach ($allGrids as $grid) {
            $symbol = $grid['symbol'];
            if (!isset($symbolsStats[$symbol])) {
                $symbolsStats[$symbol] = [
                    'profit' => 0,
                    'orders' => 0
                ];
            }
            $symbolsStats[$symbol]['profit'] += (float)($grid['accumulated_profit_usdc'] ?? 0);

            // Contar ordens deste símbolo
            $symbolOrders = array_filter(
                $allGridOrders,
                fn($o) =>
                isset($o['orders'][0]) && ($o['orders'][0]['symbol'] ?? '') === $symbol
            );
            $symbolsStats[$symbol]['orders'] += count($symbolOrders);
        }

        // Taxa de sucesso (ordens fechadas com lucro)
        $profitableOrders = 0;
        foreach ($closedOrders as $order) {
            if ((float)($order['profit_usdc'] ?? 0) > 0) {
                $profitableOrders++;
            }
        }
        $successRate = count($closedOrders) > 0 ? ($profitableOrders / count($closedOrders)) * 100 : 0;

        // Lucro médio por ordem
        $avgProfitPerOrder = count($closedOrders) > 0 ? $totalProfit / count($closedOrders) : 0;

        // ROI
        $roiPercent = $totalCapitalAllocated > 0 ? ($totalProfit / $totalCapitalAllocated) * 100 : 0;

        // === ESTATÍSTICAS DE SLIDING GRID ===
        $totalSlides = 0;
        $slidesDown  = 0;
        $slidesUp    = 0;
        foreach ($allGrids as $grid) {
            $totalSlides += (int)($grid['slide_count']      ?? 0);
            $slidesDown  += (int)($grid['slide_count_down'] ?? 0);
            $slidesUp    += (int)($grid['slide_count_up']   ?? 0);
        }

        // === PREPARAR NÍVEIS DO GRID ===
        // ESTRATÉGIA: Exibir APENAS ordens ativas (NEW/PARTIALLY_FILLED) de cada grid,
        // ordenadas por proximidade do preço atual e renumeradas dinamicamente.
        // Executadas/canceladas não aparecem no ladder — apenas na tabela "Ordens do Grid".
        $gridsWithLevels = [];
        foreach ($allGrids as &$grid) {
            $gridId = $grid['idx'];

            $buyLevels = [];
            $sellLevels = [];

            // Buscar APENAS ordens ativas (NEW/PARTIALLY_FILLED) deste grid para o ladder.
            // Inclui todas as ordens independente de paired_order_id (Violão cria ordens pareadas).
            $activeGridOrders = array_filter($allGridOrders, function($o) use ($gridId) {
                $status = $o['orders'][0]['status'] ?? '';
                return ($o['grids_id'] ?? 0) == $gridId
                    && in_array($status, ['NEW', 'PARTIALLY_FILLED']);
            });

            foreach ($activeGridOrders as $gridOrder) {
                $order = $gridOrder['orders'][0] ?? null;
                if (!$order) continue;
                $side = $order['side'] ?? '';
                $entry = [
                    'level'      => 0, // será atribuído após ordenação
                    'price'      => (float)($order['price'] ?? 0),
                    'quantity'   => (float)($order['quantity'] ?? 0),
                    'side'       => $side,
                    'status'     => $order['status'] ?? 'UNKNOWN',
                    'order_id'   => (int)($order['idx'] ?? 0),
                    'has_order'           => true,
                    'created_at'          => $order['created_at'] ?? null,
                    'is_sliding'          => (int)($gridOrder['is_sliding_level'] ?? 0) === 1,
                    'original_cost_price' => (float)($gridOrder['original_cost_price'] ?? 0),
                ];
                if ($side === 'BUY')  $buyLevels[]  = $entry;
                if ($side === 'SELL') $sellLevels[] = $entry;
            }

            // BUYs: maior preço primeiro (mais próximo do centro = Nível 1)
            usort($buyLevels, fn($a, $b) => $b['price'] <=> $a['price']);
            foreach ($buyLevels as $i => &$lvl) { $lvl['level'] = $i + 1; }
            unset($lvl);

            // SELLs: menor preço primeiro (mais próximo do centro = Nível 1)
            usort($sellLevels, fn($a, $b) => $a['price'] <=> $b['price']);
            foreach ($sellLevels as $i => &$lvl) { $lvl['level'] = $i + 1; }
            unset($lvl);

            // Contar ordens abertas deste grid
            $gridOpenOrders = array_filter($openOrders, fn($o) => ($o['grids_id'] ?? 0) == $gridId);
            $grid['open_orders'] = count($gridOpenOrders);

            // Sempre adicionar ao array se houver níveis (reais ou planejados)
            // Garantir que são arrays antes de usar
            $buyLevels = is_array($buyLevels) ? $buyLevels : [];
            $sellLevels = is_array($sellLevels) ? $sellLevels : [];

            // Adicionar ao array se houver níveis (reais ou planejados)
            // NOTA: Mostrar TODAS as ordens, não apenas pendentes
            if (!empty($buyLevels) || !empty($sellLevels)) {
                $gridsWithLevels[] = [
                    'grid' => $grid,
                    'buy_levels' => array_values($buyLevels),
                    'sell_levels' => array_values($sellLevels)
                ];
            }
        }
        unset($grid); // Limpar referência

        // === PREPARAR PAGINAÇÃO DAS ORDENS ===
        $itemsPerPage = 6;
        $currentPage = isset($_GET['orders_page']) ? max(1, (int)$_GET['orders_page']) : 1;
        $totalOrders = count($allOrdersforDisplay);
        $totalPages = ceil($totalOrders / $itemsPerPage);
        $currentPage = min($currentPage, max(1, $totalPages)); // Ajustar página se exceder total
        
        $startIndex = ($currentPage - 1) * $itemsPerPage;
        $ordersForDisplay = array_slice($allOrdersforDisplay, $startIndex, $itemsPerPage);

        $binanceConfig = BinanceConfig::getActiveCredentials();

        // === BUSCAR SALDO DE USDC DA CARTEIRA ===
        $usdcBalance = 0;
        $btcBalance = 0;
        try {
            $api = $this->createBinanceApiClient(true);

            $accountInfo = $api->getAccount(null, self::BINANCE_RECV_WINDOW);
            $accountData = $accountInfo->getData();

            if ($accountData && isset($accountData["balances"])) {
                foreach ($accountData["balances"] as $balance) {
                    if ($balance["asset"] === "USDC") {
                        $free   = (float)$balance["free"];
                        $locked = (float)$balance["locked"];
                        $usdcBalance = $free + $locked;
                    }
                    if ($balance["asset"] === "BTC") {
                        $btcFree   = (float)$balance["free"];
                        $btcLocked = (float)$balance["locked"];
                        $btcBalance = $btcFree + $btcLocked;
                    }
                }
            }
        } catch (Throwable $e) {
            // Se falhar, manter saldo em 0
            $usdcBalance = 0;
            $btcBalance  = 0;
            error_log("site_controller::dashboard wallet sync error: " . $e->getMessage());
        }

        $gridDashboardData = [
            'stats' => [
                'grids' => [
                    'active_grids' => count($activeGrids),
                    'total_grids' => count($allGrids)
                ],
                'orders' => [
                    'open_orders' => count($openOrders),
                    'closed_orders' => count($closedOrders),
                    'total_orders' => count($allGridOrders)
                ],
                'profit' => [
                    'total_profit' => $totalProfit,
                    'profitable_orders' => $profitableOrders
                ],
                'capital' => [
                    'total_allocated' => $totalCapitalAllocated
                ],
                'performance' => [
                    'success_rate' => $successRate,
                    'avg_profit_per_order' => $avgProfitPerOrder,
                    'roi_percent' => $roiPercent
                ]
            ],
            'grids' => $allGrids,
            'grids_with_levels' => $gridsWithLevels,
            'open_orders' => array_values($ordersForDisplay),
            'orders_pagination' => [
                'current_page' => $currentPage,
                'total_pages' => $totalPages,
                'total_items' => $totalOrders,
                'items_per_page' => $itemsPerPage
            ],
            'symbols_stats' => $symbolsStats,
            'logs' => $gridLogs,
            'binance_env' => $binanceConfig['mode'] ?? 'dev',
            'sliding' => [
                'total_slides' => $totalSlides,
                'slides_down'  => $slidesDown,
                'slides_up'    => $slidesUp,
            ],
            'wallet' => [
                'usdc_balance' => $usdcBalance,
                'btc_balance'  => $btcBalance ?? 0
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
     * Dashboard principal (home logada)
     */
    public function dashboard_Old($info)
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
        $perPage = isset($info["get"]["paginate"]) && (int)$info["get"]["paginate"] > 3 ? $info["get"]["paginate"] : 3;
        $page = isset($info["get"]["page"]) && (int)$info["get"]["page"] > 0 ? (int)$info["get"]["page"] : 1;

        // Usar os dados de closedTradesData já filtrados e ordenados
        usort($closedTradesData, fn($a, $b) => strtotime($b['closed_at'] ?? '0') - strtotime($a['closed_at'] ?? '0'));

        $offset = ($page - 1) * $perPage;
        $closedTrades = array_slice($closedTradesData, $offset, $perPage);
        $totalPages = ceil(count($closedTradesData) / $perPage);
        // === BUSCAR SALDO DA CARTEIRA NA BINANCE ===
        try {
            $api = $this->createBinanceApiClient(true);

            $accountInfo = $api->getAccount(null, self::BINANCE_RECV_WINDOW);
            $accountData = $accountInfo->getData();

            $walletTotal = 0;
            $usdcBalance = 0;
            $walletBalances = [];

            if ($accountData && isset($accountData["balances"])) {
                foreach ($accountData["balances"] as $balance) {
                    $free = (float)$balance["free"];
                    $locked = (float)$balance["locked"];
                    $total = $free + $locked;

                    if ($total > 0) {
                        $asset = $balance["asset"];
                        $valueInUsdc = $total;

                        // Guardar saldo de USDC separadamente
                        if ($asset === "USDC") {
                            $usdcBalance = $total;
                        }

                        // Se não for USDC, converter para USDC usando preço atual da Binance
                        if ($asset !== "USDC") {
                            try {
                                // Tentar buscar preço do par ASSET/USDC
                                $symbol = $asset . "USDC";
                                $priceResponse = $api->tickerPrice($symbol);
                                $priceData = $priceResponse->getData();

                                // A API retorna um objeto TickerPriceResponse
                                if ($priceData && method_exists($priceData, 'getTickerPriceResponse1')) {
                                    $tickerData = $priceData->getTickerPriceResponse1();
                                    $price = $tickerData && method_exists($tickerData, 'getPrice') ? (float)$tickerData->getPrice() : 0;
                                } elseif (is_array($priceData) && isset($priceData['price'])) {
                                    $price = (float)$priceData['price'];
                                } else {
                                    $price = 0;
                                }

                                if ($price > 0) {
                                    $valueInUsdc = $total * $price;
                                } else {
                                    // Se não conseguir o preço, não contabilizar no total
                                    $valueInUsdc = 0;
                                }
                            } catch (Exception $priceError) {
                                // Se não conseguir buscar o preço (par não existe), não contabilizar
                                $valueInUsdc = 0;
                            }
                        }

                        $walletBalances[] = [
                            "asset" => $asset,
                            "free" => $free,
                            "locked" => $locked,
                            "total" => $total,
                            "value_usdc" => $valueInUsdc
                        ];

                        // Contabilizar TODOS os ativos no total (em USDC)
                        $walletTotal += $valueInUsdc;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("Erro ao buscar saldo da carteira: " . $e->getMessage());
            $walletTotal = 0;
            $usdcBalance = 0;
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
                    $ordersModel->set_filter(["active = 'yes'", "idx IN (SELECT orders_id FROM orders_trades WHERE active = 'yes' AND trades_id = '{$trade['idx']}')"]);
                    $ordersModel->attach(["trades"]);
                    $ordersModel->load_data();

                    foreach ($ordersModel->data as $dbOrder) {
                        $binanceOrderId = $dbOrder['binance_order_id'];

                        try {
                            // Buscar status atual na Binance
                            $orderResp = $api->getOrder($symbol, $binanceOrderId, null, self::BINANCE_RECV_WINDOW);
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
        $patrimonialGrowth = WalletBalanceHelper::getTotalGrowth($walletTotal);

        // === PREPARAR DADOS PARA A VIEW ===
        $dashboardData = [
            'stats' => $stats,
            'wallet_total' => $walletTotal,
            'usdc_balance' => $usdcBalance,
            'wallet_balances' => $walletBalances,
            'patrimonial_growth' => $patrimonialGrowth,
            'open_trades' => array_values($openTrades),
            'open_orders' => $ordensAbertas,
            'closed_trades' => $closedTrades,
            'closed_trades_all' => $closedTradesData,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'per_page' => $perPage,
                'total_records' => $totalClosedTrades
            ],
            'binance_env' => $binanceConfig['mode'] ?? 'dev',
            'binance_base_url' => $binanceConfig['baseUrl'] ?? ''
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
     * Endpoint JSON com métricas avançadas do grid (Sharpe, Sortino, drawdown, etc)
     */
    public function gridMetrics($info)
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!auth_controller::check_login()) {
            echo json_encode(['success' => false, 'message' => 'Não autenticado']);
            return;
        }

        $gridId = (int)($_GET['grid_id'] ?? 0);
        if ($gridId <= 0) {
            echo json_encode(['success' => false, 'message' => 'grid_id inválido']);
            return;
        }

        $cacheKey = "metrics:grid:{$gridId}";
        $redis = RedisCache::getInstance();
        $cached = $redis->get($cacheKey);
        if ($cached !== false) {
            echo $cached;
            return;
        }

        // Buscar snapshots horários dos últimos 30 dias
        $snapModel = new capital_snapshots_model();
        $snapModel->set_filter([
            "grids_id = '{$gridId}'",
            "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        ]);
        $snapModel->set_order(["created_at ASC"]);
        $snapModel->load_data();
        $snapshots = $snapModel->data;

        $returns = [];
        $maxDrawdown = 0.0;
        $peak = 0.0;
        $totalCapitalChange = 0.0;
        $btcMtm = 0.0;

        if (count($snapshots) > 1) {
            $firstCap = (float)($snapshots[0]['total_capital_usdc'] ?? 0);
            $lastCap = (float)($snapshots[count($snapshots) - 1]['total_capital_usdc'] ?? 0);
            $totalCapitalChange = $firstCap > 0 ? ($lastCap - $firstCap) / $firstCap : 0;

            for ($i = 1; $i < count($snapshots); $i++) {
                $prev = (float)($snapshots[$i - 1]['total_capital_usdc'] ?? 0);
                $curr = (float)($snapshots[$i]['total_capital_usdc'] ?? 0);
                if ($prev > 0) {
                    $ret = ($curr - $prev) / $prev;
                    $returns[] = $ret;
                    if ($curr > $peak) $peak = $curr;
                    $dd = $peak > 0 ? ($peak - $curr) / $peak : 0;
                    if ($dd > $maxDrawdown) $maxDrawdown = $dd;
                }
            }
        }

        $sharpe = 0.0;
        $sortino = 0.0;
        if (count($returns) > 1) {
            $mean = array_sum($returns) / count($returns);
            $variance = array_sum(array_map(fn($r) => pow($r - $mean, 2), $returns)) / count($returns);
            $stdDev = sqrt($variance);
            $annualFactor = sqrt(24 * 365);
            $sharpe = $stdDev > 0 ? ($mean * $annualFactor) / ($stdDev * $annualFactor) : 0;

            $downsideReturns = array_filter($returns, fn($r) => $r < 0);
            $downsideVariance = count($downsideReturns) > 0 ? array_sum(array_map(fn($r) => pow($r, 2), $downsideReturns)) / count($downsideReturns) : 0;
            $downsideDev = sqrt($downsideVariance);
            $sortino = $downsideDev > 0 ? ($mean * $annualFactor) / ($downsideDev * $annualFactor) : 0;
        }

        // Win rate e profit factor usando grid_logs
        $logsModel = new grid_logs_model();
        $logsModel->set_filter(["grids_id = '{$gridId}'", "event_type = 'pair_closed'"]);
        $logsModel->load_data();
        $pairLogs = $logsModel->data;
        $wins = 0;
        $losses = 0;
        $totalProfit = 0.0;
        $totalLoss = 0.0;
        foreach ($pairLogs as $log) {
            $data = json_decode($log['data'] ?? '{}', true);
            $profit = (float)($data['profit'] ?? 0);
            if ($profit > 0) {
                $wins++;
                $totalProfit += $profit;
            } else {
                $losses++;
                $totalLoss += abs($profit);
            }
        }
        $totalTrades = $wins + $losses;
        $winRate = $totalTrades > 0 ? ($wins / $totalTrades) * 100 : 0;
        $profitFactor = $totalLoss > 0 ? $totalProfit / $totalLoss : ($totalProfit > 0 ? INF : 0);

        // Maker ratio
        $ordersModel = new orders_model();
        $ordersModel->set_filter([
            "grids_id = '{$gridId}'",
            "status = 'FILLED'",
            "is_maker IS NOT NULL"
        ]);
        $ordersModel->load_data();
        $filledOrders = $ordersModel->data;
        $makerCount = 0;
        foreach ($filledOrders as $o) {
            if ((int)($o['is_maker'] ?? 0) === 1) $makerCount++;
        }
        $makerRatio = count($filledOrders) > 0 ? ($makerCount / count($filledOrders)) * 100 : 0;

        // Fills por dia (últimos 7 dias)
        $ordersModel2 = new orders_model();
        $ordersModel2->set_filter([
            "grids_id = '{$gridId}'",
            "status = 'FILLED'",
            "order_created_at >= " . (round(microtime(true) * 1000) - 7 * 86400 * 1000)
        ]);
        $ordersModel2->load_data();
        $fillsPerDay = count($ordersModel2->data) / 7.0;

        // Spread PnL total
        $spreadPnl = array_sum(array_column($snapshots, 'accumulated_spread_pnl'));

        $result = [
            'success' => true,
            'grid_id' => $gridId,
            'spread_pnl_total' => (float)($snapshots[count($snapshots) - 1]['accumulated_spread_pnl'] ?? 0),
            'btc_mtm' => $btcMtm,
            'total_capital_change' => $totalCapitalChange,
            'sharpe_ratio' => $sharpe,
            'sortino_ratio' => $sortino,
            'max_drawdown' => $maxDrawdown,
            'win_rate' => $winRate,
            'profit_factor' => is_finite($profitFactor) ? $profitFactor : null,
            'fills_per_day' => round($fillsPerDay, 2),
            'maker_ratio' => $makerRatio,
        ];

        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $redis->set($cacheKey, $json, 60);
        echo $json;
    }

    /**
     * Endpoint JSON com histórico de capital do grid (últimos 30 dias, agrupado por hora)
     */
    public function gridCapitalHistory($info)
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!auth_controller::check_login()) {
            echo json_encode(['success' => false, 'message' => 'Não autenticado']);
            return;
        }

        $gridId = (int)($_GET['grid_id'] ?? 0);
        if ($gridId <= 0) {
            echo json_encode(['success' => false, 'message' => 'grid_id inválido']);
            return;
        }

        $snapModel = new capital_snapshots_model();
        $snapModel->set_filter([
            "grids_id = '{$gridId}'",
            "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        ]);
        $snapModel->set_order(["created_at ASC"]);
        $snapModel->load_data();

        $grouped = [];
        foreach ($snapModel->data as $row) {
            $hour = date('Y-m-d H:00:00', strtotime($row['created_at']));
            if (!isset($grouped[$hour]) || (float)$row['total_capital_usdc'] > $grouped[$hour]['total_capital_usdc']) {
                $grouped[$hour] = [
                    'hour' => $hour,
                    'total_capital_usdc' => (float)$row['total_capital_usdc'],
                    'usdc_balance' => (float)$row['usdc_balance'],
                    'btc_holding' => (float)$row['btc_holding'],
                    'btc_price' => (float)$row['btc_price'],
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'grid_id' => $gridId,
            'data' => array_values($grouped)
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

            // Verificar TP2 (apenas se o trade tiver TP2)
            $hasTP2 = !empty($trade['take_profit_2_price']) && (float)$trade['take_profit_2_price'] > 0;
            if ($hasTP2 && ($trade['tp2_status'] ?? 'pending') !== 'filled' && ($trade['tp2_status'] ?? 'pending') !== 'cancelled') {
                $this->checkAndExecuteTargetProfit($tradeIdx, $symbol, 'tp2', $trade, $api, $currentWalletBalance);
            }

            // Recarregar trade para obter status atualizado
            $tradesModel = new trades_model();
            $tradesModel->set_filter(["active = 'yes'", "idx = '{$tradeIdx}'"]);
            $tradesModel->load_data();

            if (empty($tradesModel->data)) {
                continue;
            }

            $updatedTrade = $tradesModel->data[0];

            // Se tem TP2: fechar quando ambos forem executados
            // Se não tem TP2: fechar quando TP1 for executado
            if ($hasTP2) {
                if (($updatedTrade['tp1_status'] ?? 'pending') === 'filled' && ($updatedTrade['tp2_status'] ?? 'pending') === 'filled') {
                    $this->finalizeTradeAfterBothTPs($tradeIdx, $symbol, $updatedTrade, $currentWalletBalance);
                }
            } else {
                if (($updatedTrade['tp1_status'] ?? 'pending') === 'filled') {
                    $this->finalizeTradeWithSingleTP($tradeIdx, $symbol, $updatedTrade, $currentWalletBalance);
                }
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
            $ordersModel->set_filter(["active = 'yes'", "idx IN (SELECT orders_id FROM orders_trades WHERE active = 'yes' AND trades_id = '{$tradeIdx}') AND order_type = 'take_profit' AND tp_target = '{$tpTarget}'"]);
            $ordersModel->attach(["trades"]);
            $ordersModel->load_data();

            if (empty($ordersModel->data)) {
                return;
            }

            $takeProfitOrder = $ordersModel->data[0];
            $binanceOrderId = $takeProfitOrder['binance_order_id'];

            // Consultar status na Binance
            try {
                $response = $api->getOrder($symbol, $binanceOrderId, null, self::BINANCE_RECV_WINDOW);
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

            // Se FILLED, processar imediatamente (sem validação de preço)
            if ($status === 'FILLED') {
                $orderDataArray = is_array($orderData) ? $orderData : json_decode(json_encode($orderData), true);
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
            $tradesModel->set_filter(["active = 'yes'", "idx = '{$tradeIdx}'"]);
            $updateData = [
                $tpStatusColumn => 'filled',
                $tpExecutedColumn => $executedQty
            ];
            $tradesModel->populate($updateData);
            $tradesModel->save();

            // Atualizar ordem
            $ordersModel = new orders_model();
            $ordersModel->set_filter([
                "active = 'yes'",
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
            $tradesModel->set_filter(["active = 'yes'", "idx = '{$tradeIdx}'"]);
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
     * Finaliza o trade quando apenas TP1 foi preenchido (não existe TP2)
     */
    private function finalizeTradeWithSingleTP(int $tradeIdx, string $symbol, array $trade, float $walletBalance): void
    {
        try {
            $buyPrice = (float)($trade['entry_price'] ?? 0);
            $investment = (float)($trade['investment'] ?? 0);
            $quantity = (float)($trade['quantity'] ?? 0);

            $tp1Qty = (float)($trade['tp1_executed_qty'] ?? 0);
            $tp1Price = (float)($trade['take_profit_1_price'] ?? 0);

            // Calcular P/L do TP1 único
            $profitLoss = ($tp1Qty * $tp1Price) - $investment;
            $profitLossPercent = $investment > 0 ? (($profitLoss / $investment) * 100) : 0;

            // Fechar trade
            $tradesModel = new trades_model();
            $tradesModel->set_filter(["active = 'yes'", "idx = '{$tradeIdx}'"]);
            $tradesModel->populate([
                'status' => 'closed',
                'exit_price' => $tp1Price,
                'exit_type' => 'take_profit',
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
                'event' => 'trade_closed_single_tp',
                'message' => "Trade finalizado - TP1 único executado",
                'data' => json_encode([
                    'tp1_qty' => $tp1Qty,
                    'tp1_price' => $tp1Price,
                    'exit_price' => $tp1Price,
                    'profit_loss' => $profitLoss,
                    'profit_loss_percent' => $profitLossPercent,
                    'wallet_balance' => $walletBalance,
                    'snapshot_id' => $snapshotId
                ])
            ]);
            $tradeLogsModel->save();

            error_log("✅ Trade #{$tradeIdx} ({$symbol}) FINALIZADO (TP único). P/L: $" . number_format($profitLoss, 2) . " ({$profitLossPercent}%)");
        } catch (Exception $e) {
            error_log("❌ Erro ao finalizar trade #{$tradeIdx} (TP único): " . $e->getMessage());
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
            case 'clearCache':
                $this->clearCache();
                break;
            case 'closeAllPositions':
                $this->closeAllPositions();
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Ação não encontrada'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        exit;
    }

    /**
     * Limpa completamente o cache do Redis
     */
    private function clearCache()
    {
        try {
            $cache = RedisCache::getInstance();

            if (!$cache || !$cache->isConnected()) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Redis não está conectado'
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Limpar apenas chaves de cache de queries (preservar sessões!)
            // O DOLModel usa o padrão 'query:{tabela}:{hash}' para cache
            $deleted = $cache->deletePattern('query:*');

            echo json_encode([
                'success' => true,
                'message' => "Cache limpo com sucesso ($deleted chaves removidas)"
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            error_log("Erro ao limpar cache: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao limpar cache: ' . $e->getMessage()
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            $binanceConfig = BinanceConfig::getActiveCredentials();
            $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
            $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
            $configurationBuilder->url($binanceConfig['baseUrl']);
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
                $ordersModel->set_filter(["active = 'yes'", "idx IN (SELECT orders_id FROM orders_trades WHERE active = 'yes' AND trades_id = '{$tradeIdx}')"]);
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
                            $response = $api->getOrder($symbol, $binanceOrderId, null, self::BINANCE_RECV_WINDOW);
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
                            $ordersModel->set_filter(["active = 'yes'", "idx = '{$order['idx']}'"]);
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
                            $ordersModel->set_filter(["active = 'yes'", "idx = '{$order['idx']}'"]);
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
                                $api->deleteOrder($symbol, $binanceOrderId, null, null, null, self::BINANCE_RECV_WINDOW);
                            } catch (Exception $cancelEx) {
                                // Código -2011 = "Unknown order sent" = ordem já foi FILLED ou deletada
                                if (strpos($cancelEx->getMessage(), '-2011') === false && !isBinanceOrderNotFoundError($cancelEx)) {
                                    throw $cancelEx;
                                }
                            }

                            // Cancelar no banco de dados
                            $ordersModel->set_filter(["active = 'yes'", "idx = '{$order['idx']}'"]);
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
                    $accountInfo = $api->getAccount(null, self::BINANCE_RECV_WINDOW);
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

                                    $response = $api->newOrder($this->applyRecvWindowToOrderRequest($newOrderReq));

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
                    $tradeModel->set_filter(["active = 'yes'", "idx = '{$trade['idx']}'"]);
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
                                        $orderResp = $api->getOrder($symbol, $filledOrder['orderId'], null, self::BINANCE_RECV_WINDOW);
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

    // ====================================================
    // AJAX ENDPOINTS - Dashboard Enhancement
    // ====================================================

    /**
     * Retorna o preço atual do primeiro grid ativo (para price ticker)
     */
    private function ajaxGetCurrentPrice(): void
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter(["active = 'yes'", "status = 'active'"]);
            $gridsModel->load_data();

            $symbol = 'BTCUSDC';
            if (!empty($gridsModel->data)) {
                $symbol = $gridsModel->data[0]['symbol'] ?? 'BTCUSDC';
            }

            $binanceConfig = BinanceConfig::getActiveCredentials();
            $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
            $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
            $configurationBuilder->url($binanceConfig['baseUrl']);
            $api = new SpotRestApi($configurationBuilder->build());

            $priceResponse = $api->tickerPrice($symbol);
            $priceData = $priceResponse->getData();

            $price = 0;
            if ($priceData && method_exists($priceData, 'getTickerPriceResponse1')) {
                $tickerData = $priceData->getTickerPriceResponse1();
                $price = $tickerData && method_exists($tickerData, 'getPrice') ? (float)$tickerData->getPrice() : 0;
            } elseif (is_array($priceData) && isset($priceData['price'])) {
                $price = (float)$priceData['price'];
            }

            echo json_encode([
                'success' => true,
                'price' => $price,
                'symbol' => $symbol,
                'timestamp' => time()
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao buscar preço: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Retorna dados completos do dashboard em formato JSON (para AJAX polling)
     */
    private function checkCronHealthAndNotify(?array $grid = null): void
    {
        try {
            $lastSuccessAt = AppSettings::get('monitoring', 'verify_entry_success_at');
            if (!$lastSuccessAt) {
                return;
            }

            $lastSuccessTs = strtotime($lastSuccessAt);
            if (!$lastSuccessTs) {
                return;
            }

            $elapsedMinutes = (time() - $lastSuccessTs) / 60;
            if ($elapsedMinutes <= setup_controller::getCronStaleAlertMinutes()) {
                BotAlertService::clearSystemAlert('cron_stale');
                return;
            }

            $symbol = $grid['symbol'] ?? 'BTCUSDC';
            $subject = "Driftex: CRON sem monitoramento recente";
            $body = sprintf(
                "<p>A rotina de monitoramento do bot está sem execução bem-sucedida recente.</p>
                <p><strong>Par:</strong> %s<br>
                <strong>Último sucesso:</strong> %s<br>
                <strong>Sem execução há:</strong> %.1f minutos</p>
                <p>O dashboard pode ficar desatualizado e o bot pode deixar de recriar ou monitorar o grid.</p>",
                htmlspecialchars($symbol),
                htmlspecialchars($lastSuccessAt),
                $elapsedMinutes
            );

            BotAlertService::sendSystemAlertOnce('cron_stale', $subject, $body, [
                'symbol' => $symbol,
                'last_success_at' => $lastSuccessAt,
                'elapsed_minutes' => $elapsedMinutes
            ]);
        } catch (Throwable $e) {
            error_log('site_controller::checkCronHealthAndNotify error: ' . $e->getMessage());
        }
    }

    private function ajaxGetGridDashboardData(): void
    {
        try {
            // Buscar grids
            $gridsModel = new grids_model();
            $gridsModel->set_filter(["active = 'yes'"]);
            $gridsModel->load_data();
            $allGrids = $gridsModel->data;
            usort($allGrids, function ($a, $b) {
                $aActive = (($a['status'] ?? '') === 'active') ? 1 : 0;
                $bActive = (($b['status'] ?? '') === 'active') ? 1 : 0;
                if ($aActive !== $bActive) {
                    return $bActive <=> $aActive;
                }
                return ((int)($b['idx'] ?? 0)) <=> ((int)($a['idx'] ?? 0));
            });

            $activeGrids = array_filter($allGrids, fn($g) => $g['status'] === 'active');
            $firstGrid = $allGrids[0] ?? null;

            $this->checkCronHealthAndNotify($firstGrid);

            // Estatísticas rápidas
            $totalProfit = 0;
            $totalAllocated = 0;
            foreach ($allGrids as $grid) {
                $totalProfit += (float)($grid['accumulated_profit_usdc'] ?? 0);
            }
            foreach ($activeGrids as $grid) {
                $totalAllocated += (float)($grid['capital_allocated_usdc'] ?? 0);
            }

            // Buscar preço atual
            $currentPrice = 0;
            $symbol = $firstGrid['symbol'] ?? 'BTCUSDC';
            try {
                $binanceConfig = BinanceConfig::getActiveCredentials();
                $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
                $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
                $configurationBuilder->url($binanceConfig['baseUrl']);
                $api = new SpotRestApi($configurationBuilder->build());

                $priceResponse = $api->tickerPrice($symbol);
                $priceData = $priceResponse->getData();

                if ($priceData && method_exists($priceData, 'getTickerPriceResponse1')) {
                    $tickerData = $priceData->getTickerPriceResponse1();
                    $currentPrice = $tickerData && method_exists($tickerData, 'getPrice') ? (float)$tickerData->getPrice() : 0;
                } elseif (is_array($priceData) && isset($priceData['price'])) {
                    $currentPrice = (float)$priceData['price'];
                }
            } catch (Exception $e) {
                // Use grid stored price as fallback
                $currentPrice = $firstGrid ? (float)($firstGrid['current_price'] ?? 0) : 0;
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'currentPrice' => $currentPrice,
                    'symbol' => $symbol,
                    'activeGrids' => count($activeGrids),
                    'totalProfit' => $totalProfit,
                    'totalAllocated' => $totalAllocated,
                    'gridStatus' => $firstGrid['status'] ?? 'inactive',
                    'timestamp' => time()
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao buscar dados: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Para o bot: marca grid como 'stopped' sem cancelar ordens
     */
    private function ajaxStopBot(): void
    {
        try {
            $gridsModel = new grids_model();
            $gridsModel->set_filter(["active = 'yes'", "status = 'active'"]);
            $gridsModel->load_data();

            $stoppedCount = 0;
            foreach ($gridsModel->data as $grid) {
                $gridsModel->load_byIdx($grid['idx']);
                $gridsModel->populate(['status' => 'stopped']);
                $gridsModel->save();
                $stoppedCount++;

                // Log
                $logModel = new grid_logs_model();
                $logModel->populate([
                    'grids_id' => $grid['idx'],
                    'log_type' => 'bot_stopped',
                    'event' => 'Bot parado via dashboard',
                    'message' => 'Grid parado manualmente. Ordens existentes foram mantidas.',
                    'data' => json_encode(['stopped_at' => date('Y-m-d H:i:s')])
                ]);
                $logModel->save();
            }

            echo json_encode([
                'success' => true,
                'message' => "$stoppedCount grid(s) parado(s). Ordens existentes mantidas na Binance."
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao parar bot: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Desligamento de emergência: cancela todas as ordens e marca grids como cancelled
     */
    private function ajaxEmergencyShutdown(): void
    {
        $this->executeGridLiquidation(true);
    }

    /**
     * Reset do grid: cancela ordens e marca grid como cancelled
     */
    private function ajaxResetGrid(): void
    {
        $this->ajaxEmergencyShutdown();
    }

    /**
     * Encerra posições: cancela ordens, vende o ativo remanescente e deixa o bot
     * livre para recriar o grid automaticamente no próximo ciclo.
     */
    private function ajaxCloseAllGridPositions(): void
    {
        $this->executeGridLiquidation(false);
    }

    /**
     * Executa liquidação do grid.
     *
     * - stopBot=true  => comportamento de emergência: liquida e bloqueia recriação automática.
     * - stopBot=false => encerra posições, mas deixa o bot apto a recriar o grid no próximo ciclo.
     */
    private function executeGridLiquidation(bool $stopBot): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $actionLabel = $stopBot ? 'ajaxEmergencyShutdown' : 'ajaxCloseAllGridPositions';
        $logIcon = $stopBot ? '🔴' : '🟡';

        try {
            error_log("{$logIcon} [{$actionLabel}] Iniciando liquidação do grid");

            $binanceConfig = BinanceConfig::getActiveCredentials();
            $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
            $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
            $configurationBuilder->url($binanceConfig['baseUrl']);
            $api = new SpotRestApi($configurationBuilder->build());

            $gridsModel = new grids_model();
            $gridsModel->set_filter(["active = 'yes'", "status IN ('active', 'stopped')"]);
            $gridsModel->load_data();

            if (empty($gridsModel->data)) {
                $message = $stopBot
                    ? 'Nenhum grid em funcionamento para desligamento de emergência'
                    : 'Nenhum grid em funcionamento para encerrar posições';

                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'cancelled_orders' => [],
                    'sold_assets' => [],
                    'errors' => []
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $cancelledOrders = [];
            $soldAssets = [];
            $errors = [];

            foreach ($gridsModel->data as $grid) {
                $gridId = (int)$grid['idx'];
                $symbol = (string)$grid['symbol'];
                $asset = str_replace('USDC', '', $symbol);

                error_log("{$logIcon} [{$actionLabel}] Processando grid #{$gridId} ({$symbol})");

                try {
                    $openOrdersResp = $api->getOpenOrders($symbol);
                    $openOrdersData = $openOrdersResp->getData();

                    $orders = [];
                    if (is_array($openOrdersData)) {
                        $orders = $openOrdersData;
                    } elseif (is_object($openOrdersData) && method_exists($openOrdersData, 'getItems')) {
                        $orders = $openOrdersData->getItems();
                    }

                    foreach ($orders as $binanceOrder) {
                        if (!is_array($binanceOrder)) {
                            $binanceOrder = json_decode(json_encode($binanceOrder), true);
                        }

                        $orderId = $binanceOrder['orderId'] ?? null;
                        if (!$orderId) {
                            continue;
                        }

                        try {
                            $api->deleteOrder($symbol, $orderId, null, null, null, self::BINANCE_RECV_WINDOW);
                            $cancelledOrders[] = [
                                'grid_id' => $gridId,
                                'symbol' => $symbol,
                                'order_id' => $orderId
                            ];

                            $ordersModel = new orders_model();
                            $ordersModel->set_filter(["binance_order_id = '{$orderId}'"]);
                            $ordersModel->populate(['status' => 'CANCELED', 'active' => 'no']);
                            $ordersModel->save();
                        } catch (Exception $ce) {
                            if (strpos($ce->getMessage(), '-2011') === false) {
                                $errors[] = "Erro ao cancelar ordem {$orderId}: " . $ce->getMessage();
                            }
                        }
                    }
                } catch (Exception $e) {
                    $errors[] = "Erro ao buscar ordens de {$symbol}: " . $e->getMessage();
                }

                try {
                    $accountInfo = $api->getAccount(null, self::BINANCE_RECV_WINDOW);
                    $accountData = $accountInfo->getData();

                    if (!$accountData || !isset($accountData['balances'])) {
                        throw new Exception('Não foi possível obter saldos da conta');
                    }

                    $assetBalance = null;
                    foreach ($accountData['balances'] as $balance) {
                        if (($balance['asset'] ?? '') === $asset) {
                            $assetBalance = $balance;
                            break;
                        }
                    }

                    if ($assetBalance) {
                        $free = (float)($assetBalance['free'] ?? 0);

                        if ($free >= 0.00001) {
                            $exchangeInfo = $this->getExchangeInfo($symbol);
                            $lotSizeFilter = $this->extractLotSizeFilter($exchangeInfo);
                            $finalQty = $this->adjustQuantityToStepSize($free, $lotSizeFilter['stepSize']);
                            $finalQtyFloat = (float)$finalQty;

                            if ($finalQtyFloat >= (float)$lotSizeFilter['minQty']) {
                                $newOrderReq = new NewOrderRequest();
                                $newOrderReq->setSymbol($symbol);
                                $newOrderReq->setSide(Side::SELL);
                                $newOrderReq->setType(OrderType::MARKET);
                                $newOrderReq->setQuantity($finalQty);

                                $response = $api->newOrder($this->applyRecvWindowToOrderRequest($newOrderReq));
                                $orderData = $response->getData();
                                $sellOrderId = method_exists($orderData, 'getOrderId')
                                    ? $orderData->getOrderId()
                                    : ($orderData['orderId'] ?? null);

                                $soldAssets[] = [
                                    'grid_id' => $gridId,
                                    'symbol' => $symbol,
                                    'asset' => $asset,
                                    'quantity' => $finalQty,
                                    'quantity_original' => $free,
                                    'order_id' => $sellOrderId
                                ];
                            }
                        }
                    }
                } catch (Exception $e) {
                    $errors[] = "Erro ao vender {$asset}: " . $e->getMessage();
                }

                $gridUpdate = $stopBot
                    ? [
                        'status' => 'cancelled',
                        'stop_loss_triggered' => 'yes',
                        'stop_loss_triggered_at' => date('Y-m-d H:i:s'),
                        'is_processing' => 'no'
                    ]
                    : [
                        'status' => 'cancelled',
                        'stop_loss_triggered' => 'no',
                        'trailing_stop_triggered' => 'no',
                        'is_processing' => 'no'
                    ];

                $gridUpdateModel = new grids_model();
                $gridUpdateModel->set_filter(["idx = '{$gridId}'"]);
                $gridUpdateModel->populate($gridUpdate);
                $gridUpdateModel->save();

                $gridsOrdersModel = new grids_orders_model();
                $gridsOrdersModel->set_filter(["grids_id = '{$gridId}'", "active = 'yes'"]);
                $gridsOrdersModel->populate(['active' => 'no']);
                $gridsOrdersModel->save();

                $logModel = new grid_logs_model();
                $logModel->populate([
                    'grids_id' => $gridId,
                    'log_type' => $stopBot ? 'emergency_shutdown' : 'positions_closed',
                    'event' => $stopBot
                        ? 'Desligamento de emergência via dashboard'
                        : 'Encerramento de posições via dashboard',
                    'message' => $stopBot
                        ? 'Ordens canceladas, ativos liquidados e bot bloqueado até uso de Religar Bot.'
                        : 'Ordens canceladas e ativos liquidados. O bot poderá montar novo grid automaticamente no próximo ciclo.',
                    'data' => json_encode([
                        'cancelled_orders' => array_values(array_filter($cancelledOrders, fn($o) => (int)$o['grid_id'] === $gridId)),
                        'sold_assets' => array_values(array_filter($soldAssets, fn($o) => (int)$o['grid_id'] === $gridId)),
                        'errors' => $errors,
                        'liquidated_at' => date('Y-m-d H:i:s'),
                        'stop_bot' => $stopBot
                    ])
                ]);
                $logModel->save();
            }

            try {
                $redis = RedisCache::getInstance();
                $redis->deletePattern('*grids*');
                $redis->deletePattern('*orders*');
                $redis->deletePattern('*dashboard*');
            } catch (Exception $cacheEx) {
                error_log("⚠️ [{$actionLabel}] Erro ao limpar cache: " . $cacheEx->getMessage());
            }

            $message = $stopBot
                ? 'Desligamento de emergência executado'
                : 'Posições encerradas com sucesso';
            $message .= ': ' . count($cancelledOrders) . ' ordens canceladas';
            if (!empty($soldAssets)) {
                $message .= ', ' . count($soldAssets) . ' ativo(s) vendido(s)';
            }
            if ($stopBot) {
                $message .= '. Bot bloqueado até uso de Religar Bot.';
            } else {
                $message .= '. O bot seguirá apto a recriar o grid automaticamente.';
            }
            if (!empty($errors)) {
                $message .= ' | ' . count($errors) . ' erro(s)';
            }

            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => $message,
                'cancelled_orders' => $cancelledOrders,
                'sold_assets' => $soldAssets,
                'errors' => $errors,
                'stop_bot' => $stopBot
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            error_log("❌ [ajaxCloseAllGridPositions] Erro: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            ob_clean();
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao encerrar posições: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    private function ajaxRestartGrid(): void
    {
        try {
            error_log("🟢 [ajaxRestartGrid] Iniciando religamento do bot");

            $gridsModel = new grids_model();
            $gridsModel->set_filter([
                "active = 'yes'",
                "status IN ('cancelled', 'stopped')"
            ]);
            $gridsModel->load_data();

            error_log("🟢 [ajaxRestartGrid] Grids parados encontrados: " . count($gridsModel->data));

            if (empty($gridsModel->data)) {
                error_log("⚠️ [ajaxRestartGrid] Nenhum grid encontrado para religar");
                echo json_encode([
                    'success' => false,
                    'message' => 'Nenhum grid parado/cancelado encontrado para religar'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $reactivatedGrids = [];

            foreach ($gridsModel->data as $grid) {
                $gridId = (int)$grid['idx'];

                error_log("🟢 [ajaxRestartGrid] Liberando recriação automática do grid #{$gridId} (status: {$grid['status']})");

                $gridUpdateModel = new grids_model();
                $gridUpdateModel->set_filter(["idx = '{$gridId}'"]);
                $gridUpdateModel->populate([
                    'status' => 'cancelled',
                    'stop_loss_triggered' => 'no',
                    'trailing_stop_triggered' => 'no',
                    'is_processing' => 'no'
                ]);
                $gridUpdateModel->save();

                try {
                    $logModel = new grid_logs_model();
                    $logModel->populate([
                        'grids_id' => $gridId,
                        'log_type' => 'restart_request',
                        'event' => 'Bot religado via dashboard',
                        'message' => "Grid #{$gridId} liberado. Novo grid será criado automaticamente na próxima execução da CRON.",
                        'data' => json_encode([
                            'restarted_at' => date('Y-m-d H:i:s'),
                            'old_status' => $grid['status']
                        ])
                    ]);
                    $logModel->save();
                } catch (Exception $logEx) {
                    error_log("⚠️ [ajaxRestartGrid] Erro ao salvar log: " . $logEx->getMessage());
                }

                $reactivatedGrids[] = [
                    'grid_id' => $gridId,
                    'symbol' => $grid['symbol'],
                    'old_status' => $grid['status']
                ];
            }

            try {
                $redis = RedisCache::getInstance();
                $redis->deletePattern('*grids*');
                $redis->deletePattern('*dashboard*');
            } catch (Exception $cacheEx) {
                error_log("⚠️ [ajaxRestartGrid] Erro ao limpar cache: " . $cacheEx->getMessage());
            }

            error_log("✅ [ajaxRestartGrid] Bot religado com sucesso. Grids liberados: " . count($reactivatedGrids));

            echo json_encode([
                'success' => true,
                'message' => '✅ Bot religado! Aguardando a próxima execução da CRON para criar o novo grid.',
                'reactivated_grids' => $reactivatedGrids
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            error_log("❌ [ajaxRestartGrid] Erro: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao religar bot: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    private function createBinanceApiClient(bool $requireAuth = false): SpotRestApi
    {
        $binanceConfig = BinanceConfig::getActiveCredentials();
        $apiKey = trim((string)($binanceConfig['apiKey'] ?? ''));
        $secretKey = trim((string)($binanceConfig['secretKey'] ?? ''));
        $baseUrl = trim((string)($binanceConfig['baseUrl'] ?? ''));

        if ($baseUrl === '') {
            throw new Exception('URL da Binance não configurada');
        }

        if ($requireAuth && ($apiKey === '' || $secretKey === '')) {
            throw new Exception('Credenciais da Binance não configuradas');
        }

        $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
        if ($apiKey !== '') {
            $configurationBuilder->apiKey($apiKey);
        }
        if ($secretKey !== '') {
            $configurationBuilder->secretKey($secretKey);
        }
        $configurationBuilder->url($baseUrl);

        return new SpotRestApi($configurationBuilder->build());
    }
    private function getBalanceForAsset(array $balances, string $asset, string $field = 'free'): float
    {
        foreach ($balances as $balance) {
            if (($balance['asset'] ?? '') === $asset) {
                return (float)($balance[$field] ?? 0.0);
            }
        }

        return 0.0;
    }

    private function calculateGridCurrentCapital(string $symbol, SpotRestApi $api): float
    {
        $snapshot = $this->calculateGridCapitalSnapshot($symbol, $api);
        return (float)($snapshot['total'] ?? 0.0);
    }

    private function calculateGridCapitalSnapshot(string $symbol, SpotRestApi $api): array
    {
        $baseAsset = str_replace('USDC', '', $symbol);

        $accountResponse = $api->getAccount(null, self::BINANCE_RECV_WINDOW);
        $accountData = $accountResponse->getData();
        $accountInfo = json_decode(json_encode($accountData), true);

        $usdcBalance = $this->getBalanceForAsset($accountInfo['balances'] ?? [], 'USDC')
            + $this->getBalanceForAsset($accountInfo['balances'] ?? [], 'USDC', 'locked');
        $btcFree = $this->getBalanceForAsset($accountInfo['balances'] ?? [], $baseAsset);
        $btcLocked = $this->getBalanceForAsset($accountInfo['balances'] ?? [], $baseAsset, 'locked');

        $currentPrice = $this->getCurrentPrice($symbol, $api);
        if ($currentPrice === null) {
            throw new Exception('Não foi possível obter o preço atual para recalibrar o capital');
        }

        return [
            'total' => $usdcBalance + (($btcFree + $btcLocked) * $currentPrice),
            'usdc' => $usdcBalance
        ];
    }

    /**
     * Helper: Obtém exchangeInfo da Binance com cache
     */
    private function getExchangeInfo(string $symbol): array
    {
        static $cache = [];
        
        if (isset($cache[$symbol])) {
            return $cache[$symbol];
        }

        try {
            $url = "https://api.binance.com/api/v3/exchangeInfo?symbol={$symbol}";
            $response = file_get_contents($url);

            if ($response === false) {
                throw new Exception("Erro ao acessar exchangeInfo da Binance");
            }

            $data = json_decode($response, true);
            if (!isset($data['symbols'][0])) {
                throw new Exception("Símbolo {$symbol} não encontrado");
            }

            $cache[$symbol] = $data['symbols'][0];
            return $cache[$symbol];
        } catch (Exception $e) {
            error_log("❌ [getExchangeInfo] Erro: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Helper: Extrai filtros LOT_SIZE e PRICE_FILTER
     */
    private function extractLotSizeFilter(array $symbolData): array
    {
        $filters = array_column($symbolData['filters'], null, 'filterType');
        
        if (!isset($filters['LOT_SIZE'])) {
            throw new Exception("LOT_SIZE filter não encontrado");
        }

        return [
            'stepSize' => $filters['LOT_SIZE']['stepSize'],
            'minQty' => $filters['LOT_SIZE']['minQty'] ?? '0.00001',
            'maxQty' => $filters['LOT_SIZE']['maxQty'] ?? '9000'
        ];
    }

    /**
     * Helper: Calcula casas decimais de um stepSize
     */
    private function getDecimalPlaces(string $value): int
    {
        $parts = explode('.', $value);
        return isset($parts[1]) ? strlen(rtrim($parts[1], '0')) : 0;
    }

    /**
     * Helper: Ajusta quantidade ao stepSize da Binance
     */
    private function adjustQuantityToStepSize(float $quantity, string $stepSize): string
    {
        $stepSizeFloat = (float)$stepSize;
        
        // Arredondar para baixo (floor) para garantir que não exceda saldo disponível
        $adjustedQty = floor($quantity / $stepSizeFloat) * $stepSizeFloat;
        
        // Formatear com casas decimais corretas
        $decimalPlaces = $this->getDecimalPlaces($stepSize);
        
        return number_format($adjustedQty, $decimalPlaces, '.', '');
    }
}
