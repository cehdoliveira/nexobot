<?php

use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;
use Binance\Client\Spot\Model\NewOrderRequest;
use Binance\Client\Spot\Model\Side;
use Binance\Client\Spot\Model\OrderType;

class site_controller
{

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
            header('Content-Type: application/json; charset=utf-8');

            $input = $_POST;
            if (empty($input)) {
                $rawInput = file_get_contents('php://input');
                if (!empty($rawInput)) {
                    $input = json_decode($rawInput, true) ?: [];
                }
            }

            if (!empty($input['action'])) {
                if ($input['action'] === 'refreshGridData') {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Dados atualizados com sucesso'
                    ]);
                }
                exit;
            }
        }

        // === BUSCAR DADOS DOS GRIDS ===
        $gridsModel = new grids_model();
        $gridsModel->set_filter(["active = 'yes'"]);
        $gridsModel->load_data();
        $allGrids = $gridsModel->data;

        // === BUSCAR ORDENS DE GRID (com relacionamento manual) ===
        $gridsOrdersModel = new grids_orders_model();
        $gridsOrdersModel->load_data();
        $gridOrdersData = $gridsOrdersModel->data;

        // Carregar ordens relacionadas
        if (!empty($gridOrdersData)) {
            $orderIds = array_column($gridOrdersData, 'orders_id');
            
            $ordersModel = new orders_model();
            $ordersModel->set_filter([
                "active = 'yes'",
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

        // Total de lucro
        $totalProfit = 0;
        foreach ($allGrids as $grid) {
            $totalProfit += (float)($grid['accumulated_profit_usdc'] ?? 0);
        }

        // Capital total alocado
        $totalCapitalAllocated = 0;
        foreach ($allGrids as $grid) {
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

        // === PREPARAR NÍVEIS DO GRID ===
        // ESTRATÉGIA: Exibir ordens REAIS que existem no banco,
        // OU gerar níveis planejados se não houver ordens ainda
        $gridsWithLevels = [];
        foreach ($allGrids as &$grid) {  // Usar referência (&) para modificar o array original
            $gridId = $grid['idx'];

            // Buscar ordens deste grid (apenas as que EXISTEM)
            $allGridOrdersForGrid = array_filter($allGridOrders, fn($o) => ($o['grids_id'] ?? 0) == $gridId);

            // Para cada par (grid_level, side) manter apenas a ordem mais recente (maior idx).
            // Isso evita múltiplas entradas "Nível 1" causadas por ordens históricas já executadas
            // no mesmo nível — cada nível deve exibir apenas seu estado atual.
            $latestPerLevelSide = [];
            foreach ($allGridOrdersForGrid as $gridOrder) {
                $order = $gridOrder['orders'][0] ?? null;
                if (!$order) {
                    continue;
                }
                $side = $order['side'] ?? 'UNKNOWN';
                $level = (int)($gridOrder['grid_level'] ?? 0);
                $key = $level . '_' . $side;
                $currentIdx = (int)($gridOrder['idx'] ?? 0);
                if (!isset($latestPerLevelSide[$key]) || $currentIdx > $latestPerLevelSide[$key]['idx']) {
                    $latestPerLevelSide[$key] = $gridOrder;
                }
            }
            $gridOrders = array_values($latestPerLevelSide);

            $buyLevels = [];
            $sellLevels = [];

            // Gerar níveis planejados baseado na configuração do grid
            $lowerPrice = (float)($grid['lower_price'] ?? 0);
            $upperPrice = (float)($grid['upper_price'] ?? 0);
            $currentPrice = (float)($grid['current_price'] ?? 0);
            $totalLevels = (int)($grid['grid_levels'] ?? 3);

            if ($currentPrice > 0 && $totalLevels > 0 && $lowerPrice > 0 && $upperPrice > 0) {
                // Gerar níveis planejados balanceados (mesma quantidade para compra e venda)
                $halfLevels = (int)floor($totalLevels / 2);
                if ($halfLevels < 1) {
                    $halfLevels = 1;
                }

                $buyStep = ($currentPrice - $lowerPrice) / $halfLevels;
                $sellStep = ($upperPrice - $currentPrice) / $halfLevels;
                $capitalPerLevel = (float)($grid['capital_allocated_usdc'] ?? 0) / $halfLevels;

                for ($i = 1; $i <= $halfLevels; $i++) {
                    $buyPrice = $currentPrice - ($buyStep * $i);
                    $buyPrice = max($buyPrice, $lowerPrice);

                    $buyLevelNumber = $halfLevels - $i + 1;
                    $buyQty = $capitalPerLevel > 0 ? $capitalPerLevel / $buyPrice : 0;

                    $buyLevels[] = [
                        'level' => $buyLevelNumber,
                        'price' => $buyPrice,
                        'quantity' => $buyQty,
                        'side' => 'BUY',
                        'status' => 'PLANNED',
                        'order_id' => 0,
                        'has_order' => false,
                        'created_at' => null
                    ];

                    // Sell planejado: 1% acima do buy correspondente
                    // Nivel invertido: Buy nivel 1 (menor preco) -> Sell nivel 3; Buy nivel 3 (maior preco) -> Sell nivel 1
                    $sellLevelNumber = $halfLevels - $buyLevelNumber + 1;
                    $sellPrice = $buyPrice * 1.01;
                    $sellLevels[] = [
                        'level' => $sellLevelNumber,
                        'price' => $sellPrice,
                        'quantity' => $buyQty,
                        'side' => 'SELL',
                        'status' => 'PLANNED',
                        'order_id' => 0,
                        'has_order' => false,
                        'created_at' => null
                    ];
                }
            }

            // Sobrepor níveis com ordens reais (se existirem)
            if (!empty($gridOrders)) {
                foreach ($gridOrders as $gridOrder) {
                    $order = $gridOrder['orders'][0] ?? null;
                    if (!$order) {
                        continue;
                    }

                    $orderSide = $order['side'] ?? 'UNKNOWN';
                    $orderLevel = (int)($gridOrder['grid_level'] ?? 0);
                    $orderPrice = (float)($order['price'] ?? 0);
                    $targetLevels = null;

                    if ($orderSide === 'BUY') {
                        $targetLevels = &$buyLevels;
                    } elseif ($orderSide === 'SELL') {
                        $targetLevels = &$sellLevels;
                    }

                    if ($targetLevels === null) {
                        continue;
                    }

                    $replaced = false;
                    $closestIndex = null;
                    $closestDiff = null;

                    foreach ($targetLevels as $idx => &$levelData) {
                        if ($orderLevel > 0 && (int)($levelData['level'] ?? 0) === $orderLevel) {
                            $closestIndex = $idx;
                            $closestDiff = 0.0;
                            break;
                        }

                        $diff = abs((float)($levelData['price'] ?? 0) - $orderPrice);
                        if ($closestDiff === null || $diff < $closestDiff) {
                            $closestDiff = $diff;
                            $closestIndex = $idx;
                        }
                    }

                    if ($closestIndex !== null) {
                        $levelData = &$targetLevels[$closestIndex];
                        $levelData['price'] = $orderPrice > 0 ? $orderPrice : (float)($levelData['price'] ?? 0);
                        $levelData['quantity'] = (float)($order['quantity'] ?? $levelData['quantity']);
                        $levelData['status'] = $order['status'] ?? 'UNKNOWN';
                        $levelData['order_id'] = (int)($order['idx'] ?? 0);
                        $levelData['has_order'] = true;
                        $levelData['created_at'] = $order['created_at'] ?? null;
                        $replaced = true;
                        unset($levelData);
                    }
                    unset($levelData);
                    unset($levelData);

                    if (!$replaced) {
                        $targetLevels[] = [
                            'level' => $orderLevel,
                            'price' => (float)($order['price'] ?? 0),
                            'quantity' => (float)($order['quantity'] ?? 0),
                            'side' => $orderSide,
                            'status' => $order['status'] ?? 'UNKNOWN',
                            'order_id' => (int)($order['idx'] ?? 0),
                            'has_order' => true,
                            'created_at' => $order['created_at'] ?? null
                        ];
                    }
                }
                unset($targetLevels);
            }

            // Fallback: se nao gerou niveis planejados, montar diretamente das ordens reais
            if (empty($buyLevels)) {
                $buyFromOrders = [];
                $fallbackLevel = 1;
                foreach ($gridOrders as $gridOrder) {
                    $order = $gridOrder['orders'][0] ?? null;
                    if (!$order || ($order['side'] ?? '') !== 'BUY') {
                        continue;
                    }

                    $level = (int)($gridOrder['grid_level'] ?? 0);
                    if ($level <= 0) {
                        $level = $fallbackLevel;
                        $fallbackLevel++;
                    } else {
                        $fallbackLevel = max($fallbackLevel, $level + 1);
                    }

                    $buyFromOrders[] = [
                        'level' => $level,
                        'price' => (float)($order['price'] ?? 0),
                        'quantity' => (float)($order['quantity'] ?? 0),
                        'side' => 'BUY',
                        'status' => $order['status'] ?? 'UNKNOWN',
                        'order_id' => (int)($order['idx'] ?? 0),
                        'has_order' => true,
                        'created_at' => $order['created_at'] ?? null
                    ];
                }
                if (!empty($buyFromOrders)) {
                    $buyLevels = $buyFromOrders;
                }
            }

            if (empty($sellLevels)) {
                $sellFromOrders = [];
                $fallbackLevel = 1;
                foreach ($gridOrders as $gridOrder) {
                    $order = $gridOrder['orders'][0] ?? null;
                    if (!$order || ($order['side'] ?? '') !== 'SELL') {
                        continue;
                    }

                    $level = (int)($gridOrder['grid_level'] ?? 0);
                    if ($level <= 0) {
                        $level = $fallbackLevel;
                        $fallbackLevel++;
                    } else {
                        $fallbackLevel = max($fallbackLevel, $level + 1);
                    }

                    $sellFromOrders[] = [
                        'level' => $level,
                        'price' => (float)($order['price'] ?? 0),
                        'quantity' => (float)($order['quantity'] ?? 0),
                        'side' => 'SELL',
                        'status' => $order['status'] ?? 'UNKNOWN',
                        'order_id' => (int)($order['idx'] ?? 0),
                        'has_order' => true,
                        'created_at' => $order['created_at'] ?? null
                    ];
                }
                if (!empty($sellFromOrders)) {
                    $sellLevels = $sellFromOrders;
                }
            }

            // Se ainda nao houver niveis de venda, derivar 1% acima das compras abertas
            if (empty($sellLevels) && !empty($buyLevels)) {
                $derivedSellLevels = [];
                foreach ($buyLevels as $level) {
                    if (($level['side'] ?? '') !== 'BUY') {
                        continue;
                    }

                    $buyPrice = (float)($level['price'] ?? 0);
                    $buyQty = (float)($level['quantity'] ?? 0);
                    if ($buyPrice <= 0 || $buyQty <= 0) {
                        continue;
                    }

                    $derivedSellLevels[] = [
                        'level' => (int)($level['level'] ?? 0),
                        'price' => $buyPrice * 1.01,
                        'quantity' => $buyQty,
                        'side' => 'SELL',
                        'status' => 'PLANNED',
                        'order_id' => 0,
                        'has_order' => false,
                        'created_at' => null
                    ];
                }

                if (!empty($derivedSellLevels)) {
                    $sellLevels = $derivedSellLevels;
                }
            }

            // Ordenar por preço para garantir ordem consistente
            if (is_array($buyLevels) && !empty($buyLevels)) {
                usort($buyLevels, fn($a, $b) => $b['price'] <=> $a['price']); // Decrescente: alto->baixo (Level 1 é mais próximo do preço atual)
            }
            if (is_array($sellLevels) && !empty($sellLevels)) {
                usort($sellLevels, fn($a, $b) => $a['price'] <=> $b['price']); // Crescente: baixo->alto (Level 1 é mais próximo do preço atual)
            }

            // Contar ordens abertas deste grid
            $gridOpenOrders = array_filter($openOrders, fn($o) => ($o['grids_id'] ?? 0) == $gridId);
            $grid['open_orders'] = count($gridOpenOrders);

            // Sempre adicionar ao array se houver níveis (reais ou planejados)
            // Garantir que são arrays antes de usar
            $buyLevels = is_array($buyLevels) ? $buyLevels : [];
            $sellLevels = is_array($sellLevels) ? $sellLevels : [];
            
            if (!empty($buyLevels) || !empty($sellLevels)) {
                $gridsWithLevels[] = [
                    'grid' => $grid,
                    'buy_levels' => $buyLevels,
                    'sell_levels' => $sellLevels
                ];
            }
        }
        unset($grid); // Limpar referência

        // Ordenar ordens abertas por grid_level (ordem crescente: 1, 2, 3)
        usort($openOrders, fn($a, $b) => ($a['grid_level'] ?? 0) <=> ($b['grid_level'] ?? 0));

        // === PREPARAR DADOS PARA A VIEW ===
        $binanceConfig = BinanceConfig::getActiveCredentials();

        // === BUSCAR SALDO DE USDC DA CARTEIRA ===
        $usdcBalance = 0;
        try {
            $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
            $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
            $configurationBuilder->url($binanceConfig['baseUrl']);
            $api = new SpotRestApi($configurationBuilder->build());

            $accountInfo = $api->getAccount();
            $accountData = $accountInfo->getData();

            if ($accountData && isset($accountData["balances"])) {
                foreach ($accountData["balances"] as $balance) {
                    if ($balance["asset"] === "USDC") {
                        $free = (float)$balance["free"];
                        $locked = (float)$balance["locked"];
                        $usdcBalance = $free + $locked;
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            // Se falhar, manter saldo em 0
            $usdcBalance = 0;
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
            'open_orders' => array_values($openOrders),
            'symbols_stats' => $symbolsStats,
            'logs' => $gridLogs,
            'binance_env' => $binanceConfig['mode'] ?? 'dev',
            'wallet' => [
                'usdc_balance' => $usdcBalance
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

        $binanceConfig = BinanceConfig::getActiveCredentials();

        // === BUSCAR SALDO DA CARTEIRA NA BINANCE ===
        try {
            $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
            $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
            $configurationBuilder->url($binanceConfig['baseUrl']);
            $api = new SpotRestApi($configurationBuilder->build());

            $accountInfo = $api->getAccount();
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
        } catch (Exception $e) {
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

            // Limpar todo o cache
            $flushed = $cache->flush();

            if ($flushed) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Cache limpo com sucesso'
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Erro ao limpar cache'
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
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
                                $api->deleteOrder($symbol, $binanceOrderId);
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
