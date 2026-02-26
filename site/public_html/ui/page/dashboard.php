<?php
/**
 * Grid Trading Dashboard - Enhanced
 * Mobile-first, responsive, real-time
 *
 * Data provided by site_controller::dashboard() via $gridDashboardData
 */

// === Extract data ===
$stats        = $gridDashboardData['stats'] ?? [];
$grids        = $gridDashboardData['grids'] ?? [];
$gridsLevels  = $gridDashboardData['grids_with_levels'] ?? [];
$openOrders   = $gridDashboardData['open_orders'] ?? [];
$pagination   = $gridDashboardData['orders_pagination'] ?? [];
$symbolsStats = $gridDashboardData['symbols_stats'] ?? [];
$logs         = $gridDashboardData['logs'] ?? [];
$wallet       = $gridDashboardData['wallet'] ?? [];
$binanceEnv   = $gridDashboardData['binance_env'] ?? 'dev';

$activeGrids    = (int)($stats['grids']['active_grids'] ?? 0);
$openOrdersCnt  = (int)($stats['orders']['open_orders'] ?? 0);
$closedOrders   = (int)($stats['orders']['closed_orders'] ?? 0);
$totalProfit    = (float)($stats['profit']['total_profit'] ?? 0);
$totalAllocated = (float)($stats['capital']['total_allocated'] ?? 0);
$successRate    = (float)($stats['performance']['success_rate'] ?? 0);
$avgProfit      = (float)($stats['performance']['avg_profit_per_order'] ?? 0);
$roiPercent     = (float)($stats['performance']['roi_percent'] ?? 0);
$usdcBalance    = (float)($wallet['usdc_balance'] ?? 0);
$btcBalance     = (float)($wallet['btc_balance'] ?? 0);

// First active grid for header price display
$firstGrid     = $grids[0] ?? null;
$currentPrice  = $firstGrid ? (float)($firstGrid['current_price'] ?? 0) : 0;
$gridSymbol    = $firstGrid ? ($firstGrid['symbol'] ?? 'BTCUSDC') : 'BTCUSDC';
$gridStatus    = $firstGrid ? ($firstGrid['status'] ?? 'inactive') : 'inactive';

// Protection fields
$initialCapital   = $firstGrid ? (float)($firstGrid['initial_capital_usdc'] ?? 0) : 0;
$currentCapital   = $firstGrid ? (float)($firstGrid['current_capital_usdc'] ?? 0) : 0;
$peakCapital      = $firstGrid ? (float)($firstGrid['peak_capital_usdc'] ?? 0) : 0;
$stopLossTriggered  = $firstGrid ? ($firstGrid['stop_loss_triggered'] ?? 'no') : 'no';
$trailingTriggered  = $firstGrid ? ($firstGrid['trailing_stop_triggered'] ?? 'no') : 'no';
$lastMonitor        = $firstGrid ? ($firstGrid['last_monitor_at'] ?? null) : null;

$drawdownPct = ($peakCapital > 0) ? (($peakCapital - $currentCapital) / $peakCapital) * 100 : 0;
$drawdownPct = max(0, $drawdownPct);

// Calculate buy/sell order counts
$buyOrders = 0;
$sellOrders = 0;
foreach ($openOrders as $o) {
    $side = $o['orders'][0]['side'] ?? '';
    $st = $o['orders'][0]['status'] ?? '';
    if (in_array($st, ['NEW', 'PARTIALLY_FILLED'])) {
        if ($side === 'BUY') $buyOrders++;
        if ($side === 'SELL') $sellOrders++;
    }
}

// Pagination values
$currentPage  = (int)($pagination['current_page'] ?? 1);
$totalPages   = (int)($pagination['total_pages'] ?? 1);
$totalItems   = (int)($pagination['total_items'] ?? 0);

// JSON for Alpine.js hydration
$dashboardJson = json_encode([
    'currentPrice' => $currentPrice,
    'symbol' => $gridSymbol
], JSON_UNESCAPED_UNICODE);

$userName = $_SESSION[constant("cAppKey")]["credential"]["name"] ?? 'Trader';
?>

<!-- JSON data for Alpine.js hydration -->
<script type="application/json" id="dashboardData"><?php echo $dashboardJson; ?></script>

<!-- Dashboard Wrapper -->
<div class="dashboard-wrapper" x-data="gridDashboardController">

    <!-- === Connection Warning Banner === -->
    <div class="connection-banner" :class="{ 'show': !isConnected }">
        <i class="bi bi-wifi-off"></i> Conexão perdida. Tentando reconectar...
    </div>

    <!-- === SECTION A: Dashboard Header (Sticky) === -->
    <div class="dash-header">
        <div class="container-fluid px-3 px-md-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <!-- Left: Symbol + Price -->
                <div class="d-flex align-items-center gap-2 gap-md-3">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-white fw-bold" style="font-size: 0.9rem;">
                                <?php echo str_replace('USDC', '/USDC', $gridSymbol); ?>
                            </span>
                            <span class="badge <?php echo $binanceEnv === 'prod' ? 'bg-danger' : 'bg-secondary'; ?>" style="font-size: 0.6rem;">
                                <?php echo strtoupper($binanceEnv); ?>
                            </span>
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <span class="price-ticker text-white"
                                  :class="{ 'value-flash-up': priceDirection === 'up', 'value-flash-down': priceDirection === 'down' }"
                                  x-text="currentPrice > 0 ? formatPrice(currentPrice) : '<?php echo $currentPrice > 0 ? '$' . number_format($currentPrice, 2, '.', ',') : '--'; ?>'">
                                <?php echo $currentPrice > 0 ? '$' . number_format($currentPrice, 2, '.', ',') : '--'; ?>
                            </span>
                            <span class="d-flex align-items-center gap-1">
                                <span class="status-dot" :class="isConnected ? 'connected' : 'disconnected'"></span>
                                <small class="text-white-50" style="font-size: 0.65rem;" x-text="isConnected ? 'Online' : 'Offline'">Online</small>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Right: Controls -->
                <div class="d-flex align-items-center gap-2">
                    <small class="last-update d-none d-sm-block" x-text="'Atualizado ' + lastUpdateFormatted"></small>

                    <!-- Theme toggle -->
                    <button class="btn btn-sm btn-outline-light border-0" id="themeToggle" type="button"
                            title="Alternar tema" style="min-width:36px; min-height:36px;">
                        <i class="bi bi-moon-stars"></i>
                    </button>

                    <!-- Settings link -->
                    <a class="btn btn-sm btn-outline-light border-0 d-none d-md-inline-flex" href="<?php echo $GLOBALS['config_url']; ?>"
                       title="Configurações" style="min-width:36px; min-height:36px;">
                        <i class="bi bi-gear"></i>
                    </a>

                    <!-- Refresh -->
                    <button class="btn btn-sm btn-outline-light border-0" @click="refreshData()"
                            :disabled="isRefreshing" title="Atualizar dados"
                            style="min-width:36px; min-height:36px;">
                        <i class="bi bi-arrow-clockwise" :class="{ 'spin': isRefreshing }"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- === Main Dashboard Content === -->
    <div class="dashboard-main">

        <!-- Welcome + Desktop Control Toolbar -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
            <div>
                <h1 class="fs-5 fw-bold mb-0" style="color: var(--dash-text);">
                    <i class="bi bi-grid-3x3-gap-fill"></i> Grid Trading Dashboard
                </h1>
                <p class="mb-0 small" style="color: var(--dash-text-muted);">
                    Bem-vindo, <strong><?php echo htmlspecialchars($userName); ?></strong>
                </p>
            </div>

            <!-- Desktop Control Toolbar -->
            <div class="control-toolbar d-none d-md-flex">
                <button class="ctrl-btn-desktop btn-refresh" @click="refreshData()" :disabled="isRefreshing">
                    <template x-if="isRefreshing"><span class="btn-spinner"></span></template>
                    <template x-if="!isRefreshing"><i class="bi bi-arrow-clockwise"></i></template>
                    <span class="d-none d-lg-inline">Atualizar</span>
                </button>
                <button class="ctrl-btn-desktop" @click="toggleAutoRefresh()">
                    <i class="bi" :class="autoRefresh ? 'bi-pause-circle' : 'bi-play-circle'"></i>
                    <span class="d-none d-lg-inline" x-text="autoRefresh ? 'Pausar Auto' : 'Auto Refresh'"></span>
                </button>
                <?php if ($gridStatus === 'active'): ?>
                <button class="ctrl-btn-desktop btn-stop" @click="cancelAllOrders()"
                        :disabled="actionLoading === 'closeAllPositions'">
                    <i class="bi bi-x-circle"></i>
                    <span class="d-none d-lg-inline">Encerrar Posições</span>
                </button>
                <button class="ctrl-btn-desktop btn-emergency" @click="emergencyShutdown()"
                        :disabled="actionLoading === 'emergencyShutdown'">
                    <i class="bi bi-exclamation-triangle"></i>
                    <span class="d-none d-lg-inline">Emergência</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- === SECTION B: Grid Status Overview (Metric Cards) === -->
        <section aria-label="Métricas do Grid">
            <div class="metrics-grid">
                <!-- Active Grids -->
                <div class="metric-card metric-info">
                    <div class="d-flex align-items-start gap-2">
                        <div class="metric-icon icon-info"><i class="bi bi-diagram-2"></i></div>
                        <div>
                            <div class="metric-label">Grids Ativos</div>
                            <div class="metric-value"><?php echo $activeGrids; ?></div>
                            <div class="metric-sub"><?php echo $buyOrders; ?> BUY / <?php echo $sellOrders; ?> SELL</div>
                        </div>
                    </div>
                </div>

                <!-- Open Orders -->
                <div class="metric-card metric-warning">
                    <div class="d-flex align-items-start gap-2">
                        <div class="metric-icon icon-warning"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <div class="metric-label">Ordens Abertas</div>
                            <div class="metric-value"><?php echo $openOrdersCnt; ?></div>
                            <div class="metric-sub"><?php echo $closedOrders; ?> executadas</div>
                        </div>
                    </div>
                </div>

                <!-- Total Profit -->
                <div class="metric-card metric-success">
                    <div class="d-flex align-items-start gap-2">
                        <div class="metric-icon icon-success"><i class="bi bi-cash-coin"></i></div>
                        <div>
                            <div class="metric-label">Lucro Total</div>
                            <div class="metric-value <?php echo $totalProfit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo ($totalProfit >= 0 ? '+' : '') . '$' . number_format($totalProfit, 2, '.', ','); ?>
                            </div>
                            <div class="metric-sub">ROI: <?php echo ($roiPercent >= 0 ? '+' : '') . number_format($roiPercent, 2); ?>%</div>
                        </div>
                    </div>
                </div>

                <!-- USDC Balance -->
                <div class="metric-card metric-primary">
                    <div class="d-flex align-items-start gap-2">
                        <div class="metric-icon icon-primary"><i class="bi bi-wallet2"></i></div>
                        <div>
                            <div class="metric-label">Saldo USDC</div>
                            <div class="metric-value">$<?php echo number_format($usdcBalance, 2, '.', ','); ?></div>
                            <div class="metric-sub font-mono"><?php echo number_format($btcBalance, 8); ?> BTC</div>
                        </div>
                    </div>
                </div>

                <!-- Capital Allocated -->
                <div class="metric-card metric-warning">
                    <div class="d-flex align-items-start gap-2">
                        <div class="metric-icon icon-warning"><i class="bi bi-piggy-bank"></i></div>
                        <div>
                            <div class="metric-label">Capital Alocado</div>
                            <div class="metric-value">$<?php echo number_format($totalAllocated, 2, '.', ','); ?></div>
                            <div class="metric-sub">
                                <?php if ($firstGrid): ?>
                                    <?php echo $firstGrid['grid_levels'] ?? 0; ?> níveis
                                <?php else: ?>
                                    --
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Price Range -->
                <div class="metric-card metric-info">
                    <div class="d-flex align-items-start gap-2">
                        <div class="metric-icon icon-info"><i class="bi bi-arrows-expand"></i></div>
                        <div>
                            <div class="metric-label">Range do Grid</div>
                            <?php if ($firstGrid): ?>
                            <div class="metric-value" style="font-size: 0.85rem;">
                                $<?php echo number_format((float)$firstGrid['lower_price'], 0, '.', ','); ?> — $<?php echo number_format((float)$firstGrid['upper_price'], 0, '.', ','); ?>
                            </div>
                            <div class="metric-sub">
                                Espaçamento: <?php echo number_format((float)($firstGrid['grid_spacing_percent'] ?? 0) * 100, 1); ?>%
                            </div>
                            <?php else: ?>
                            <div class="metric-value">--</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- === SECTION C: Performance Metrics + Protection Status === -->
        <section aria-label="Performance e Proteções">
            <div class="section-grid">
                <!-- Performance Metrics -->
                <div class="dash-card">
                    <div class="card-header-custom">
                        <h6><i class="bi bi-bar-chart-line"></i> Performance</h6>
                        <?php if ($closedOrders > 0): ?>
                        <span class="badge bg-success" style="font-size: 0.65rem;"><?php echo $closedOrders; ?> trades</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body-custom">
                        <?php if ($closedOrders > 0): ?>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="metric-label">Taxa de Sucesso</div>
                                <div class="fw-bold <?php echo $successRate >= 50 ? 'text-buy' : 'text-sell'; ?>" style="font-size: 1.125rem;">
                                    <?php echo number_format($successRate, 1); ?>%
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="metric-label">Lucro Médio/Ordem</div>
                                <div class="fw-bold font-mono" style="font-size: 1.125rem;">
                                    $<?php echo number_format($avgProfit, 4); ?>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="metric-label">Ciclos Completos</div>
                                <div class="fw-bold" style="font-size: 1.125rem;">
                                    <?php echo (int)($stats['profit']['profitable_orders'] ?? 0); ?>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="metric-label">ROI</div>
                                <div class="fw-bold <?php echo $roiPercent >= 0 ? 'text-buy' : 'text-sell'; ?>" style="font-size: 1.125rem;">
                                    <?php echo ($roiPercent >= 0 ? '+' : '') . number_format($roiPercent, 2); ?>%
                                </div>
                            </div>
                            <?php if (!empty($symbolsStats)): ?>
                            <div class="col-12">
                                <hr class="my-2" style="border-color: var(--dash-border);">
                                <div class="metric-label mb-2">Performance por Símbolo</div>
                                <?php foreach ($symbolsStats as $sym => $st): ?>
                                <div class="d-flex justify-content-between align-items-center py-1">
                                    <span class="badge bg-info" style="font-size: 0.7rem;"><?php echo htmlspecialchars($sym); ?></span>
                                    <span class="font-mono fw-bold <?php echo ($st['profit'] ?? 0) >= 0 ? 'text-buy' : 'text-sell'; ?>" style="font-size: 0.85rem;">
                                        <?php echo ($st['profit'] ?? 0) >= 0 ? '+' : ''; ?>$<?php echo number_format($st['profit'] ?? 0, 2); ?>
                                    </span>
                                    <small class="text-dim"><?php echo $st['orders'] ?? 0; ?> ordens</small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-bar-chart-line d-block"></i>
                            <p>Nenhuma ordem executada ainda</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Protection Status -->
                <div class="dash-card">
                    <div class="card-header-custom">
                        <h6><i class="bi bi-shield-check"></i> Proteções</h6>
                        <?php if ($gridStatus === 'active'): ?>
                        <span class="protection-pill pill-active"><i class="bi bi-circle-fill" style="font-size: 0.5rem;"></i> Ativo</span>
                        <?php else: ?>
                        <span class="protection-pill pill-inactive">Inativo</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body-custom">
                        <!-- Stop-Loss -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="metric-label mb-0">Stop-Loss (20%)</span>
                                <span class="protection-pill <?php echo $stopLossTriggered === 'yes' ? 'pill-triggered' : 'pill-active'; ?>">
                                    <?php echo $stopLossTriggered === 'yes' ? 'ATIVADO' : 'Monitorando'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Trailing Stop -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="metric-label mb-0">Trailing Stop (15%)</span>
                                <span class="protection-pill <?php echo $trailingTriggered === 'yes' ? 'pill-triggered' : 'pill-active'; ?>">
                                    <?php echo $trailingTriggered === 'yes' ? 'ATIVADO' : 'Monitorando'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Drawdown Progress Bar -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="metric-label mb-0">Drawdown Atual</span>
                                <span class="font-mono fw-bold <?php echo $drawdownPct < 10 ? 'text-buy' : ($drawdownPct < 15 ? '' : 'text-sell'); ?>" style="font-size: 0.8rem;">
                                    <?php echo number_format($drawdownPct, 2); ?>%
                                </span>
                            </div>
                            <div class="drawdown-bar">
                                <div class="drawdown-fill <?php echo $drawdownPct < 10 ? 'safe' : ($drawdownPct < 15 ? 'caution' : 'critical'); ?>"
                                     style="width: <?php echo min(100, ($drawdownPct / 20) * 100); ?>%;"></div>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <small class="text-dim" style="font-size: 0.65rem;">0%</small>
                                <small class="text-dim" style="font-size: 0.65rem;">Stop-Loss 20%</small>
                            </div>
                        </div>

                        <!-- Capital Tracking -->
                        <div class="row g-2">
                            <div class="col-4">
                                <div class="metric-label">Capital Inicial</div>
                                <div class="fw-bold font-mono" style="font-size: 0.8rem;">
                                    $<?php echo number_format($initialCapital, 2, '.', ','); ?>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="metric-label">Pico</div>
                                <div class="fw-bold font-mono" style="font-size: 0.8rem;">
                                    $<?php echo number_format($peakCapital, 2, '.', ','); ?>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="metric-label">Atual</div>
                                <div class="fw-bold font-mono <?php echo $currentCapital >= $initialCapital ? 'text-buy' : 'text-sell'; ?>" style="font-size: 0.8rem;">
                                    $<?php echo number_format($currentCapital, 2, '.', ','); ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($lastMonitor): ?>
                        <div class="mt-2">
                            <small class="text-dim" style="font-size: 0.65rem;">
                                <i class="bi bi-clock"></i> Último monitoramento: <?php echo date('d/m H:i:s', strtotime($lastMonitor)); ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- === SECTION D: Grid Visualization (Price Ladder) === -->
        <?php if (!empty($gridsLevels)): ?>
        <section aria-label="Visualização do Grid">
            <?php foreach ($gridsLevels as $gridData): ?>
            <?php
            $grid = $gridData['grid'];
            $buyLevels = $gridData['buy_levels'];
            $sellLevels = $gridData['sell_levels'];
            usort($buyLevels, fn($a, $b) => $b['price'] <=> $a['price']);
            usort($sellLevels, fn($a, $b) => $b['price'] <=> $a['price']);
            $gridPrice = (float)($grid['current_price'] ?? 0);
            ?>
            <div class="dash-card">
                <div class="card-header-custom">
                    <h6>
                        <i class="bi bi-bar-chart-steps"></i>
                        <span class="d-none d-sm-inline">Níveis do Grid</span>
                        <span class="d-sm-none">Grid</span>
                        <span class="badge bg-info ms-1" style="font-size: 0.65rem;"><?php echo htmlspecialchars($grid['symbol']); ?></span>
                    </h6>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge <?php echo $grid['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>" style="font-size: 0.6rem;">
                            <?php echo ucfirst($grid['status']); ?>
                        </span>
                        <span class="badge bg-warning text-dark" style="font-size: 0.6rem;">
                            <i class="bi bi-arrow-left-right"></i> Híbrido
                        </span>
                    </div>
                </div>
                <div class="card-body-custom p-0">
                    <div class="grid-ladder custom-scroll" style="max-height: 500px;">
                        <!-- SELL Levels (highest price first) -->
                        <?php foreach ($sellLevels as $level): ?>
                        <?php
                        $statusLabel = !$level['has_order'] ? 'Planejado' : match($level['status'] ?? '') {
                            'FILLED' => 'Executada', 'PARTIALLY_FILLED' => 'Parcial',
                            'CANCELED', 'CANCELLED' => 'Cancelada', default => 'Aguardando'
                        };
                        $statusClass = !$level['has_order'] ? 'badge-canceled' : match($level['status'] ?? '') {
                            'FILLED' => 'badge-filled', 'PARTIALLY_FILLED' => 'badge-partial',
                            'CANCELED', 'CANCELLED' => 'badge-canceled', default => 'badge-new'
                        };
                        ?>
                        <div class="ladder-level level-sell">
                            <span class="level-badge badge-sell">
                                <i class="bi bi-arrow-up-short"></i>S<?php echo $level['level']; ?>
                            </span>
                            <span class="level-price text-sell">$<?php echo number_format($level['price'], 2, '.', ','); ?></span>
                            <span class="level-qty d-none d-sm-inline"><?php echo number_format($level['quantity'], 6); ?> un</span>
                            <span class="level-qty d-none d-md-inline">~$<?php echo number_format($level['price'] * $level['quantity'], 2); ?></span>
                            <span class="level-status badge-status <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                        </div>
                        <?php endforeach; ?>

                        <!-- Current Price Marker -->
                        <?php if ($gridPrice > 0): ?>
                        <div class="ladder-level level-current-price">
                            <span class="level-badge" style="background: var(--dash-info-bg); color: var(--dash-info);">
                                <i class="bi bi-cursor"></i>
                            </span>
                            <span class="level-price" style="color: var(--dash-info);"
                                  x-text="currentPrice > 0 ? formatPrice(currentPrice) : '<?php echo '$' . number_format($gridPrice, 2, '.', ','); ?>'">
                                $<?php echo number_format($gridPrice, 2, '.', ','); ?>
                            </span>
                            <span class="level-qty"></span>
                            <span class="level-qty d-none d-md-inline"></span>
                            <span class="level-status badge-status" style="background: var(--dash-info-bg); color: var(--dash-info);">Preço Atual</span>
                        </div>
                        <?php endif; ?>

                        <!-- BUY Levels (highest price first = closest to current) -->
                        <?php foreach ($buyLevels as $level): ?>
                        <?php
                        $statusLabel = !$level['has_order'] ? 'Planejado' : match($level['status'] ?? '') {
                            'FILLED' => 'Executada', 'PARTIALLY_FILLED' => 'Parcial',
                            'CANCELED', 'CANCELLED' => 'Cancelada', default => 'Aguardando'
                        };
                        $statusClass = !$level['has_order'] ? 'badge-canceled' : match($level['status'] ?? '') {
                            'FILLED' => 'badge-filled', 'PARTIALLY_FILLED' => 'badge-partial',
                            'CANCELED', 'CANCELLED' => 'badge-canceled', default => 'badge-new'
                        };
                        ?>
                        <div class="ladder-level level-buy">
                            <span class="level-badge badge-buy">
                                <i class="bi bi-arrow-down-short"></i>B<?php echo (count($buyLevels) + 1 - $level['level']); ?>
                            </span>
                            <span class="level-price text-buy">$<?php echo number_format($level['price'], 2, '.', ','); ?></span>
                            <span class="level-qty d-none d-sm-inline"><?php echo number_format($level['quantity'], 6); ?> un</span>
                            <span class="level-qty d-none d-md-inline">~$<?php echo number_format($level['price'] * $level['quantity'], 2); ?></span>
                            <span class="level-status badge-status <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Grid Info Bar -->
                    <div class="d-flex flex-wrap gap-3 px-3 py-2" style="background: var(--dash-card-border); font-size: 0.75rem;">
                        <span class="text-dim"><i class="bi bi-graph-up"></i> Spacing: <?php echo number_format((float)($grid['grid_spacing_percent'] ?? 0) * 100, 1); ?>%</span>
                        <span class="text-dim"><i class="bi bi-layers"></i> <?php echo $grid['grid_levels'] ?? 0; ?> níveis</span>
                        <span class="text-dim"><i class="bi bi-cash"></i> $<?php echo number_format((float)($grid['capital_allocated_usdc'] ?? 0), 2); ?> total</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <!-- === SECTION E: Active Orders === -->
        <section aria-label="Ordens do Grid">
            <div class="dash-card">
                <div class="card-header-custom">
                    <h6>
                        <i class="bi bi-list-ul"></i>
                        <span class="d-none d-sm-inline">Ordens do Grid</span>
                        <span class="d-sm-none">Ordens</span>
                    </h6>
                    <span class="badge bg-warning text-dark" style="font-size: 0.65rem;"><?php echo $totalItems; ?></span>
                </div>

                <?php if (!empty($openOrders)): ?>
                <div class="card-body-custom p-0">
                    <!-- Mobile: Card-based layout -->
                    <div class="d-md-none">
                        <?php foreach ($openOrders as $order): ?>
                        <?php
                        $od = $order['orders'][0] ?? [];
                        $oSide = $od['side'] ?? 'N/A';
                        $oStatus = $od['status'] ?? 'UNKNOWN';
                        $oPrice = (float)($od['price'] ?? 0);
                        $oQty = (float)($od['quantity'] ?? 0);
                        $oLevel = $order['grid_level'] ?? 'N/A';
                        $isOpen = in_array($oStatus, ['NEW', 'PARTIALLY_FILLED']);
                        ?>
                        <div class="order-card-mobile <?php echo !$isOpen ? 'opacity-75' : ''; ?>">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="order-side <?php echo $oSide === 'BUY' ? 'side-buy' : 'side-sell'; ?>"><?php echo $oSide; ?></span>
                                    <span class="badge bg-secondary" style="font-size: 0.65rem;">Nível <?php echo $oLevel; ?></span>
                                </div>
                                <span class="badge-status <?php
                                    echo match($oStatus) {
                                        'NEW' => 'badge-new', 'FILLED' => 'badge-filled',
                                        'CANCELED', 'CANCELLED' => 'badge-canceled',
                                        'PARTIALLY_FILLED' => 'badge-partial', default => 'badge-canceled'
                                    };
                                ?>">
                                    <?php echo match($oStatus) {
                                        'NEW' => 'Aguardando', 'FILLED' => 'Executada',
                                        'CANCELED', 'CANCELLED' => 'Cancelada',
                                        'PARTIALLY_FILLED' => 'Parcial', default => $oStatus
                                    }; ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between align-items-end">
                                <div>
                                    <div class="order-price">$<?php echo number_format($oPrice, 2, '.', ','); ?></div>
                                    <div class="order-detail">
                                        <i class="bi bi-coin"></i> <?php echo number_format($oQty, 6); ?> un
                                        · ~$<?php echo number_format($oPrice * $oQty, 2); ?>
                                    </div>
                                </div>
                                <small class="text-dim" style="font-size: 0.65rem;">
                                    <?php
                                    $createdAt = $order['created_at'] ?? null;
                                    echo $createdAt ? date('d/m H:i', strtotime($createdAt)) : '';
                                    ?>
                                </small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Desktop: Table layout -->
                    <div class="d-none d-md-block">
                        <div class="table-responsive custom-scroll" style="max-height: 400px;">
                            <table class="orders-table-desktop">
                                <thead>
                                    <tr>
                                        <th>Símbolo</th>
                                        <th>Lado</th>
                                        <th>Preço</th>
                                        <th>Quantidade</th>
                                        <th>Valor</th>
                                        <th>Nível</th>
                                        <th>Status</th>
                                        <th class="d-none d-lg-table-cell">Data</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($openOrders as $order): ?>
                                    <?php
                                    $od = $order['orders'][0] ?? [];
                                    $oSide = $od['side'] ?? 'N/A';
                                    $oStatus = $od['status'] ?? 'UNKNOWN';
                                    $oPrice = (float)($od['price'] ?? 0);
                                    $oQty = (float)($od['quantity'] ?? 0);
                                    $isOpen = in_array($oStatus, ['NEW', 'PARTIALLY_FILLED']);
                                    ?>
                                    <tr <?php echo !$isOpen ? 'style="opacity: 0.6;"' : ''; ?>>
                                        <td><span class="badge bg-info" style="font-size: 0.7rem;"><?php echo htmlspecialchars($od['symbol'] ?? 'N/A'); ?></span></td>
                                        <td><span class="<?php echo $oSide === 'BUY' ? 'badge-buy' : 'badge-sell'; ?>"><?php echo $oSide; ?></span></td>
                                        <td class="mono fw-bold">$<?php echo number_format($oPrice, 2, '.', ','); ?></td>
                                        <td class="mono"><?php echo number_format($oQty, 8); ?></td>
                                        <td class="mono">$<?php echo number_format($oPrice * $oQty, 2); ?></td>
                                        <td><span class="badge bg-secondary" style="font-size: 0.65rem;"><?php echo $order['grid_level'] ?? 'N/A'; ?></span></td>
                                        <td>
                                            <span class="badge-status <?php
                                                echo match($oStatus) {
                                                    'NEW' => 'badge-new', 'FILLED' => 'badge-filled',
                                                    'CANCELED', 'CANCELLED' => 'badge-canceled',
                                                    'PARTIALLY_FILLED' => 'badge-partial', default => 'badge-canceled'
                                                };
                                            ?>">
                                                <?php echo match($oStatus) {
                                                    'NEW' => 'Aguardando', 'FILLED' => 'Executada',
                                                    'CANCELED', 'CANCELLED' => 'Cancelada',
                                                    'PARTIALLY_FILLED' => 'Parcial', default => $oStatus
                                                }; ?>
                                            </span>
                                        </td>
                                        <td class="d-none d-lg-table-cell">
                                            <small class="text-dim"><?php
                                                $createdAt = $order['created_at'] ?? null;
                                                echo $createdAt ? date('d/m/Y H:i', strtotime($createdAt)) : 'N/A';
                                            ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div style="padding: 0.75rem 1rem; border-top: 1px solid var(--dash-border);">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <nav aria-label="Paginação de ordens">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?orders_page=<?php echo max(1, $currentPage - 1); ?>">&laquo;</a>
                                </li>
                                <?php
                                $startP = max(1, $currentPage - 2);
                                $endP = min($totalPages, $currentPage + 2);
                                for ($i = $startP; $i <= $endP; $i++):
                                ?>
                                <li class="page-item <?php echo $i === $currentPage ? 'active' : ''; ?>">
                                    <a class="page-link" href="?orders_page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?orders_page=<?php echo min($totalPages, $currentPage + 1); ?>">&raquo;</a>
                                </li>
                            </ul>
                        </nav>
                        <small class="text-dim"><?php echo count($openOrders); ?> de <?php echo $totalItems; ?></small>
                    </div>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-inbox d-block"></i>
                    <p>Nenhuma ordem registrada</p>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- === SECTION F: Activity Log === -->
        <section aria-label="Histórico de Eventos">
            <div class="dash-card">
                <div class="card-header-custom">
                    <h6>
                        <i class="bi bi-journal-text"></i>
                        <span class="d-none d-sm-inline">Histórico de Eventos</span>
                        <span class="d-sm-none">Eventos</span>
                    </h6>
                    <span class="badge bg-secondary" style="font-size: 0.65rem;"><?php echo count($logs); ?></span>
                </div>

                <?php if (!empty($logs)): ?>
                <?php $reversedLogs = array_reverse($logs); ?>

                <!-- Mobile: Timeline view -->
                <div class="card-body-custom d-md-none custom-scroll" style="max-height: 400px;">
                    <div class="activity-timeline">
                        <?php foreach (array_slice($reversedLogs, 0, 20) as $log): ?>
                        <div class="timeline-item">
                            <div class="timeline-dot <?php
                                echo match($log['log_type'] ?? '') {
                                    'error' => 'dot-danger', 'success' => 'dot-success',
                                    'warning' => 'dot-warning', default => 'dot-info'
                                };
                            ?>"></div>
                            <div class="timeline-content">
                                <div class="timeline-time">
                                    <?php echo isset($log['created_at']) ? date('d/m H:i:s', strtotime($log['created_at'])) : '--'; ?>
                                    <span class="badge bg-secondary ms-1" style="font-size: 0.55rem;"><?php echo htmlspecialchars($log['log_type'] ?? ''); ?></span>
                                </div>
                                <div class="timeline-event"><?php echo htmlspecialchars($log['event'] ?? 'N/A'); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Desktop: Table view -->
                <div class="card-body-custom p-0 d-none d-md-block">
                    <div class="table-responsive custom-scroll" style="max-height: 400px;">
                        <table class="orders-table-desktop">
                            <thead>
                                <tr>
                                    <th style="width: 140px;">Data/Hora</th>
                                    <th style="width: 100px;">Tipo</th>
                                    <th>Evento</th>
                                    <th style="width: 80px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($reversedLogs, 0, 50) as $log): ?>
                                <tr>
                                    <td class="mono">
                                        <small><?php echo isset($log['created_at']) ? date('d/m/Y H:i:s', strtotime($log['created_at'])) : '--'; ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary" style="font-size: 0.6rem;"><?php echo htmlspecialchars($log['log_type'] ?? 'N/A'); ?></span>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars($log['event'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo ($log['log_type'] ?? '') === 'error' ? 'badge-canceled' : 'badge-filled'; ?>" style="font-size: 0.6rem;">
                                            <?php echo ($log['log_type'] ?? '') === 'error' ? 'Erro' : 'Ok'; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-journal d-block"></i>
                    <p>Nenhum evento registrado</p>
                </div>
                <?php endif; ?>
            </div>
        </section>

    </div><!-- /dashboard-main -->

    <!-- === SECTION G: Mobile Bottom Control Bar === -->
    <div class="control-bar-mobile d-md-none" role="toolbar" aria-label="Controles do bot">
        <button class="ctrl-btn ctrl-primary" @click="refreshData()" :disabled="isRefreshing" aria-label="Atualizar dados">
            <i class="bi bi-arrow-clockwise" :class="{ 'spin': isRefreshing }"></i>
            <span>Atualizar</span>
        </button>
        <button class="ctrl-btn" @click="toggleAutoRefresh()" aria-label="Alternar auto-refresh">
            <i class="bi" :class="autoRefresh ? 'bi-pause-circle-fill' : 'bi-play-circle'"></i>
            <span x-text="autoRefresh ? 'Pausar' : 'Auto'"></span>
        </button>
        <a class="ctrl-btn" href="<?php echo $GLOBALS['config_url']; ?>" aria-label="Configurações">
            <i class="bi bi-gear"></i>
            <span>Config</span>
        </a>
        <?php if ($gridStatus === 'active'): ?>
        <button class="ctrl-btn ctrl-danger" @click="emergencyShutdown()" aria-label="Desligamento de emergência">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>Parar</span>
        </button>
        <?php endif; ?>
    </div>

</div><!-- /dashboard-wrapper -->
