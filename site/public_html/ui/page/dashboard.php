<!-- Grid Trading Dashboard -->
<div class="container py-2 py-md-4" x-data="gridDashboardController">
    <!-- Título e Informações do Usuário -->
    <div class="row mb-3 mb-md-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2 gap-md-3">
                <div class="flex-grow-1">
                    <h2 class="mb-1 fs-4 fs-md-3"><i class="bi bi-diagram-2"></i> <span class="d-none d-sm-inline">Grid Trading Dashboard</span><span class="d-sm-none">Grid Trading</span></h2>
                    <p class="text-muted mb-0 small">
                        Bem-vindo, <strong><?php echo $_SESSION[constant("cAppKey")]["credential"]["name"] ?? 'Trader'; ?></strong>
                    </p>
                </div>
                <div class="d-flex flex-column flex-sm-row align-items-end align-items-sm-center gap-2">
                    <span class="badge <?php echo ($gridDashboardData['binance_env'] ?? 'dev') === 'prod' ? 'bg-danger' : 'bg-secondary'; ?>" style="font-size: 0.75rem;">
                        Ambiente: <?php echo strtoupper($gridDashboardData['binance_env'] ?? 'dev'); ?>
                    </span>
                    <button class="btn btn-outline-secondary btn-sm" id="themeToggle" type="button" style="font-size: 0.8rem;">
                        <i class="bi bi-moon-stars"></i> <span class="d-none d-sm-inline">Tema escuro</span>
                    </button>
                    <button class="btn btn-outline-primary btn-sm" @click="refreshData()" style="font-size: 0.8rem;">
                        <i class="bi bi-arrow-clockwise"></i> <span class="d-none d-sm-inline">Atualizar</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cards de Estatísticas de Grids -->
    <div class="row g-2 g-md-3 mb-4">
        <!-- Total de Grids Ativos -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-2 p-md-3">
                    <div class="d-flex flex-column align-items-center text-center">
                        <div class="flex-shrink-0 mb-2">
                            <div class="bg-primary bg-opacity-10 p-2 rounded">
                                <i class="bi bi-diagram-2 text-primary" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                        <p class="text-muted mb-1 small">Grids Ativos</p>
                        <h4 class="mb-0 fs-5"><?php echo $gridDashboardData['stats']['grids']['active_grids'] ?? 0; ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total de Ordens Grid -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-2 p-md-3">
                    <div class="d-flex flex-column align-items-center text-center">
                        <div class="flex-shrink-0 mb-2">
                            <div class="bg-info bg-opacity-10 p-2 rounded">
                                <i class="bi bi-list-ul text-info" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                        <p class="text-muted mb-1 small">Ordens Abertas</p>
                        <h4 class="mb-0 fs-5"><?php echo $gridDashboardData['stats']['orders']['open_orders'] ?? 0; ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Saldo USDC em Carteira -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-2 p-md-3">
                    <div class="d-flex flex-column align-items-center text-center">
                        <div class="flex-shrink-0 mb-2">
                            <div class="bg-primary bg-opacity-10 p-2 rounded">
                                <i class="bi bi-wallet2 text-primary" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                        <p class="text-muted mb-1 small">Saldo USDC</p>
                        <h4 class="mb-0 fs-5 text-primary">$<?php echo number_format($gridDashboardData['wallet']['usdc_balance'] ?? 0, 2, '.', ','); ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Capital Alocado -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-2 p-md-3">
                    <div class="d-flex flex-column align-items-center text-center">
                        <div class="flex-shrink-0 mb-2">
                            <div class="bg-warning bg-opacity-10 p-2 rounded">
                                <i class="bi bi-wallet2 text-warning" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                        <p class="text-muted mb-1 small">Capital Alocado</p>
                        <h4 class="mb-0 fs-6">$<?php echo number_format($gridDashboardData['stats']['capital']['total_allocated'] ?? 0, 2, '.', ','); ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Lucro Acumulado - Destaque -->
    <div class="row g-2 g-md-3 mb-4">
        <div class="col-12 col-md-6 col-lg-4 offset-md-3 offset-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-2 p-md-3">
                    <div class="d-flex flex-column align-items-center text-center">
                        <div class="flex-shrink-0 mb-2">
                            <div class="bg-success bg-opacity-10 p-2 rounded">
                                <i class="bi bi-cash-coin text-success" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                        <p class="text-muted mb-1 small">Lucro Acumulado</p>
                        <h4 class="mb-0 fs-4 text-success">$<?php echo number_format($gridDashboardData['stats']['profit']['total_profit'] ?? 0, 2, '.', ','); ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Resumo de Performance -->
    <?php if (($gridDashboardData['stats']['orders']['closed_orders'] ?? 0) > 0): ?>
        <div class="row g-3 mb-4">
            <div class="col-12 col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-bar-chart-line"></i> Performance por Símbolo</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php if (!empty($gridDashboardData['symbols_stats'])): ?>
                                <?php foreach ($gridDashboardData['symbols_stats'] as $symbol => $stats): ?>
                                    <div class="col-6 col-sm-6">
                                        <div class="p-2 bg-light rounded">
                                            <p class="text-muted mb-1 small"><strong><?php echo htmlspecialchars($symbol); ?></strong></p>
                                            <h5 class="mb-1 fs-6">
                                                <span class="<?php echo ($stats['profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo ($stats['profit'] ?? 0) >= 0 ? '+' : ''; ?>$<?php echo number_format($stats['profit'] ?? 0, 2, '.', ','); ?>
                                                </span>
                                            </h5>
                                            <small class="text-muted"><?php echo $stats['orders'] ?? 0; ?> ordens</small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12">
                                    <p class="text-muted text-center mb-0">Nenhum dado disponível</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-percent"></i> Estatísticas Gerais</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6 col-sm-6">
                                <p class="text-muted mb-1 small">Ordens Executadas</p>
                                <h5 class="mb-0 fs-6">
                                    <?php echo $gridDashboardData['stats']['orders']['closed_orders'] ?? 0; ?>
                                </h5>
                            </div>
                            <div class="col-6 col-sm-6">
                                <p class="text-muted mb-1 small">Taxa de Sucesso</p>
                                <h5 class="mb-0 fs-6 text-success">
                                    <?php echo number_format($gridDashboardData['stats']['performance']['success_rate'] ?? 0, 2); ?>%
                                </h5>
                            </div>
                            <div class="col-6 col-sm-6">
                                <p class="text-muted mb-1 small">Lucro Médio por Ordem</p>
                                <h5 class="mb-0 fs-6">
                                    $<?php echo number_format($gridDashboardData['stats']['performance']['avg_profit_per_order'] ?? 0, 2, '.', ','); ?>
                                </h5>
                            </div>
                            <div class="col-6 col-sm-6">
                                <p class="text-muted mb-1 small">ROI Alocado</p>
                                <h5 class="mb-0 fs-6 <?php echo ($gridDashboardData['stats']['performance']['roi_percent'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo ($gridDashboardData['stats']['performance']['roi_percent'] ?? 0) >= 0 ? '+' : ''; ?><?php echo number_format($gridDashboardData['stats']['performance']['roi_percent'] ?? 0, 2); ?>%
                                </h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Grids Detalhados -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0 fs-6"><i class="bi bi-diagram-2"></i> <span class="d-none d-sm-inline">Grids Configurados</span><span class="d-sm-none">Grids</span></h6>
                    <span class="badge bg-primary"><?php echo count($gridDashboardData['grids'] ?? []); ?></span>
                </div>
                <?php if (!empty($gridDashboardData['grids'])): ?>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 fs-7">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-nowrap">Símbolo</th>
                                        <th class="text-nowrap d-none d-md-table-cell">Status</th>
                                        <th class="text-nowrap d-none d-md-table-cell">Preço Min</th>
                                        <th class="text-nowrap d-none d-md-table-cell">Preço Max</th>
                                        <th class="text-nowrap d-none d-lg-table-cell">Ordens Abertas</th>
                                        <th class="text-nowrap d-none d-xl-table-cell">Lucro Acumulado</th>
                                        <th class="text-nowrap d-none d-lg-table-cell">Data Criação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($gridDashboardData['grids'] as $grid): ?>
                                        <tr>
                                            <td class="text-nowrap">
                                                <span class="badge bg-info"><?php echo htmlspecialchars($grid['symbol']); ?></span>
                                            </td>
                                            <td class="text-nowrap d-none d-md-table-cell">
                                                <span class="badge <?php echo $grid['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo ucfirst(htmlspecialchars($grid['status'])); ?>
                                                </span>
                                            </td>
                                            <td class="text-nowrap d-none d-md-table-cell">
                                                $<?php echo number_format((float)$grid['lower_price'], 2, '.', ','); ?>
                                            </td>
                                            <td class="text-nowrap d-none d-md-table-cell">
                                                $<?php echo number_format((float)$grid['upper_price'], 2, '.', ','); ?>
                                            </td>
                                            <td class="text-nowrap d-none d-lg-table-cell">
                                                <span class="badge bg-warning text-dark"><?php echo $grid['open_orders'] ?? 0; ?></span>
                                            </td>
                                            <td class="text-nowrap d-none d-xl-table-cell">
                                                <span class="<?php echo ((float)$grid['accumulated_profit_usdc'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo ((float)$grid['accumulated_profit_usdc'] ?? 0) >= 0 ? '+' : ''; ?>$<?php echo number_format((float)$grid['accumulated_profit_usdc'] ?? 0, 2, '.', ','); ?>
                                                </span>
                                            </td>
                                            <td class="text-nowrap d-none d-lg-table-cell">
                                                <small class="text-muted"><?php echo isset($grid['created_at']) && $grid['created_at'] ? date('d/m/Y H:i', strtotime($grid['created_at'])) : date('d/m/Y H:i'); ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card-body text-center text-muted py-5">
                        <i class="bi bi-diagram-2" style="font-size: 3rem;"></i>
                        <p class="mt-3 mb-0">Nenhum grid configurado no momento</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Visualização dos Níveis do Grid -->
    <?php if (!empty($gridDashboardData['grids_with_levels'])): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-bar-chart-steps"></i> Níveis de Compra e Venda dos Grids</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach ($gridDashboardData['grids_with_levels'] as $gridData): ?>
                            <?php
                            $grid = $gridData['grid'];
                            $buyLevels = $gridData['buy_levels'];
                            $sellLevels = $gridData['sell_levels'];

                            // Ordenar níveis para exibição correta
                            usort($buyLevels, fn($a, $b) => $a['level'] <=> $b['level']); // Nível 1, 2, 3
                            usort($sellLevels, fn($a, $b) => $a['level'] <=> $b['level']); // Nível 1, 2, 3
                            ?>
                            <div class="mb-5">
                                <div class="alert alert-info alert-dismissible fade show mb-3" role="alert">
                                    <i class="bi bi-info-circle"></i> <strong>Legenda:</strong>
                                    Planejado = nível calculado; Aguardando = ordem aberta na Binance; Executada = ordem concluída.
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                                <div class="d-flex justify-content-between align-items-start align-items-md-center gap-2 mb-3 flex-wrap">
                                    <h6 class="mb-0">
                                        <span class="badge bg-info"><?php echo htmlspecialchars($grid['symbol']); ?></span>
                                        <span class="badge bg-<?php echo $grid['status'] === 'active' ? 'success' : 'secondary'; ?> ms-1">
                                            <?php echo ucfirst($grid['status']); ?>
                                        </span>
                                    </h6>
                                    <small class="text-muted fs-7">
                                        <i class="bi bi-graph-up"></i> $<?php echo number_format((float)$grid['lower_price'], 2); ?> - $<?php echo number_format((float)$grid['upper_price'], 2); ?>
                                    </small>
                                </div>

                                <div class="row g-2 g-md-3">
                                    <!-- Níveis de Compra -->
                                    <div class="col-12 col-md-6">
                                        <div class="card border-success h-100">
                                            <div class="card-header bg-success bg-opacity-10 py-2">
                                                <h6 class="mb-0 text-success fs-7">
                                                    <i class="bi bi-arrow-down-circle"></i> <span class="d-none d-sm-inline">Pontos de Entrada (Compra)</span><span class="d-sm-none">Entrada</span>
                                                </h6>
                                                <small class="text-muted" style="font-size: 0.7rem;">Preço precisa CAIR para compra</small>
                                            </div>
                                            <div class="card-body p-0">
                                                <?php if (!empty($buyLevels)): ?>
                                                    <div class="list-group list-group-flush">
                                                        <?php foreach ($buyLevels as $level): ?>
                                                            <?php
                                                            // Determinar status
                                                            if (!$level['has_order']) {
                                                                $statusLabel = '📋 Planejado';
                                                                $statusBadge = 'bg-secondary';
                                                            } else {
                                                                $statusLabel = match ($level['status']) {
                                                                    'FILLED' => '✅ Executada',
                                                                    'PARTIALLY_FILLED' => '⏳ Parcial',
                                                                    'CANCELED', 'CANCELLED' => '❌ Cancelada',
                                                                    default => '🎯 Aguardando Preço Cair'
                                                                };
                                                                $statusBadge = match ($level['status']) {
                                                                    'FILLED' => 'bg-success',
                                                                    'PARTIALLY_FILLED' => 'bg-info',
                                                                    'CANCELED', 'CANCELLED' => 'bg-secondary',
                                                                    default => 'bg-warning text-dark'
                                                                };
                                                            }
                                                            ?>
                                                            <div class="list-group-item py-2">
                                                                <div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-1">
                                                                    <div>
                                                                        <span class="badge bg-success me-1" style="font-size: 0.75rem;">Nível <?php echo $level['level']; ?></span>
                                                                        <strong class="text-success fs-7">$<?php echo number_format($level['price'], 2, '.', ','); ?></strong>
                                                                    </div>
                                                                    <span class="badge <?php echo $statusBadge; ?>" style="font-size: 0.7rem;">
                                                                        <?php echo $statusLabel; ?>
                                                                    </span>
                                                                </div>
                                                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                                                                    <small class="text-muted" style="font-size: 0.7rem;">
                                                                        <i class="bi bi-coin"></i> <?php echo number_format($level['quantity'], 6); ?> un
                                                                    </small>
                                                                    <small class="text-muted" style="font-size: 0.7rem;">
                                                                        <i class="bi bi-cash"></i> ~$<?php echo number_format($level['price'] * $level['quantity'], 2); ?>
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-center text-muted p-3">
                                                        <small>Nenhum nível de compra</small>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Níveis de Venda -->
                                    <div class="col-12 col-md-6">
                                        <div class="card border-danger h-100">
                                            <div class="card-header bg-danger bg-opacity-10 py-2">
                                                <h6 class="mb-0 text-danger fs-7">
                                                    <i class="bi bi-arrow-up-circle"></i> <span class="d-none d-sm-inline">Pontos de Saída (Venda)</span><span class="d-sm-none">Saída</span>
                                                </h6>
                                                <small class="text-muted" style="font-size: 0.7rem;">Preço precisa SUBIR para venda</small>
                                            </div>
                                            <div class="card-body p-0">
                                                <?php if (!empty($sellLevels)): ?>
                                                    <div class="list-group list-group-flush">
                                                        <?php foreach ($sellLevels as $level): ?>
                                                            <?php
                                                            // Determinar status
                                                            if (!$level['has_order']) {
                                                                $statusLabel = '📋 Planejado';
                                                                $statusBadge = 'bg-secondary';
                                                            } else {
                                                                $statusLabel = match ($level['status']) {
                                                                    'FILLED' => '✅ Executada',
                                                                    'PARTIALLY_FILLED' => '⏳ Parcial',
                                                                    'CANCELED', 'CANCELLED' => '❌ Cancelada',
                                                                    default => '🎯 Aguardando Preço Subir'
                                                                };
                                                                $statusBadge = match ($level['status']) {
                                                                    'FILLED' => 'bg-success',
                                                                    'PARTIALLY_FILLED' => 'bg-info',
                                                                    'CANCELED', 'CANCELLED' => 'bg-secondary',
                                                                    default => 'bg-warning text-dark'
                                                                };
                                                            }
                                                            ?>
                                                            <div class="list-group-item py-2">
                                                                <div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-1">
                                                                    <div>
                                                                        <span class="badge bg-danger me-1" style="font-size: 0.75rem;">Nível <?php echo $level['level']; ?></span>
                                                                        <strong class="text-danger fs-7">$<?php echo number_format($level['price'], 2, '.', ','); ?></strong>
                                                                    </div>
                                                                    <span class="badge <?php echo $statusBadge; ?>" style="font-size: 0.7rem;">
                                                                        <?php echo $statusLabel; ?>
                                                                    </span>
                                                                </div>
                                                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                                                                    <small class="text-muted" style="font-size: 0.7rem;">
                                                                        <i class="bi bi-coin"></i> <?php echo number_format($level['quantity'], 6); ?> un
                                                                    </small>
                                                                    <small class="text-muted" style="font-size: 0.7rem;">
                                                                        <i class="bi bi-cash"></i> ~$<?php echo number_format($level['price'] * $level['quantity'], 2); ?>
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-center text-muted p-3">
                                                        <small>Nenhum nível de venda</small>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Informações do Grid e Explicação -->
                                <div class="row g-3 mt-2">
                                    <div class="col-12 col-lg-8">
                                        <div class="card border-0 bg-light">
                                            <div class="card-body p-3">
                                                <div class="row g-3 small">
                                                    <div class="col-6 col-md-3">
                                                        <div class="text-muted">Preço Central</div>
                                                        <strong>$<?php echo number_format((float)$grid['current_price'], 2, '.', ','); ?></strong>
                                                    </div>
                                                    <div class="col-6 col-md-3">
                                                        <div class="text-muted">Total de Níveis</div>
                                                        <strong><?php echo $grid['grid_levels'] ?? 0; ?></strong>
                                                    </div>
                                                    <div class="col-6 col-md-3">
                                                        <div class="text-muted">Espaçamento</div>
                                                        <strong><?php echo number_format((float)($grid['grid_spacing_percent'] ?? 0) * 100, 2); ?>%</strong>
                                                    </div>
                                                    <div class="col-6 col-md-3">
                                                        <div class="text-muted">Capital por Nível</div>
                                                        <strong><?php echo ($grid['grid_levels'] ?? 0) > 0 ? number_format((float)($grid['capital_allocated_usdc'] ?? 0) / (float)($grid['grid_levels'] ?? 1), 2, '.', ',') : '0.00'; ?></strong>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12 col-lg-4">
                                        <div class="card border-0 bg-info bg-opacity-10">
                                            <div class="card-body p-3">
                                                <h6 class="text-info mb-2"><i class="bi bi-info-circle"></i> Como Funciona</h6>
                                                <small class="text-muted">
                                                    <strong class="text-success">Compras:</strong> Executam quando preço <strong>cair</strong> aos níveis<br>
                                                    <strong class="text-danger">Vendas:</strong> Executam quando preço <strong>subir</strong> aos níveis
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php if (next($gridDashboardData['grids_with_levels']) !== false): ?>
                                <hr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Ordens Abertas -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0 fs-6"><i class="bi bi-list-ul"></i> <span class="d-none d-sm-inline">Ordens Abertas do Grid</span><span class="d-sm-none">Ordens</span></h6>
                    <span class="badge bg-warning text-dark"><?php echo count($gridDashboardData['open_orders'] ?? []); ?></span>
                </div>
                <?php if (!empty($gridDashboardData['open_orders'])): ?>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 fs-7">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-nowrap">Símbolo</th>
                                        <th class="text-nowrap d-none d-md-table-cell">Lado</th>
                                        <th class="text-nowrap">Preço</th>
                                        <th class="text-nowrap d-none d-md-table-cell">Quantidade</th>
                                        <th class="text-nowrap d-none d-lg-table-cell">Nível</th>
                                        <th class="text-nowrap d-none d-xl-table-cell">Data Abertura</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($gridDashboardData['open_orders'] as $order): ?>
                                        <?php $orderData = $order['orders'][0] ?? []; ?>
                                        <tr>
                                            <td class="text-nowrap">
                                                <span class="badge bg-info"><?php echo htmlspecialchars($orderData['symbol'] ?? 'N/A'); ?></span>
                                            </td>
                                            <td class="text-nowrap d-none d-md-table-cell">
                                                <span class="badge <?php echo ($orderData['side'] ?? '') === 'BUY' ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo htmlspecialchars($orderData['side'] ?? 'N/A'); ?>
                                                </span>
                                            </td>
                                            <td class="text-nowrap">
                                                $<?php echo number_format((float)($orderData['price'] ?? 0), 2, '.', ','); ?>
                                            </td>
                                            <td class="text-nowrap d-none d-md-table-cell">
                                                <?php echo number_format((float)($orderData['quantity'] ?? 0), 8, '.', ''); ?>
                                            </td>
                                            <td class="text-nowrap d-none d-lg-table-cell">
                                                <span class="badge bg-secondary"><?php echo $order['grid_level'] ?? 'N/A'; ?></span>
                                            </td>
                                            <td class="text-nowrap d-none d-xl-table-cell">
                                                <small class="text-muted">
                                                    <?php
                                                    $createdAt = $order['created_at'] ?? null;
                                                    echo $createdAt ? date('d/m/Y H:i', strtotime($createdAt)) : 'N/A';
                                                    ?>
                                                </small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card-body text-center text-muted py-5">
                        <i class="bi bi-list-ul" style="font-size: 3rem;"></i>
                        <p class="mt-3 mb-0">Nenhuma ordem aberta no momento</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Histórico de Logs -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0 fs-6"><i class="bi bi-file-text"></i> <span class="d-none d-sm-inline">Histórico de Eventos</span><span class="d-sm-none">Eventos</span></h6>
                    <span class="badge bg-secondary"><?php echo count($gridDashboardData['logs'] ?? []); ?></span>
                </div>
                <?php if (!empty($gridDashboardData['logs'])): ?>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm mb-0 fs-7">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th class="text-nowrap">Data/Hora</th>
                                        <th class="text-nowrap d-none d-md-table-cell">Tipo</th>
                                        <th>Evento</th>
                                        <th class="text-nowrap d-none d-lg-table-cell">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_reverse($gridDashboardData['logs']) as $log): ?>
                                        <tr>
                                            <td class="text-nowrap">
                                                <small class="text-muted"><?php echo isset($log['created_at']) && $log['created_at'] ? date('d/m/Y H:i:s', strtotime($log['created_at'])) : date('d/m/Y H:i:s'); ?></small>
                                            </td>
                                            <td class="text-nowrap d-none d-md-table-cell">
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($log['log_type'] ?? 'N/A'); ?></span>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($log['event'] ?? 'N/A'); ?></small>
                                            </td>
                                            <td class="text-nowrap d-none d-lg-table-cell">
                                                <span class="badge <?php echo ($log['log_type'] ?? '') === 'error' ? 'bg-danger' : 'bg-success'; ?>">
                                                    <?php echo $log['log_type'] === 'error' ? 'Erro' : 'Ok'; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card-body text-center text-muted py-5">
                        <i class="bi bi-file-text" style="font-size: 3rem;"></i>
                        <p class="mt-3 mb-0">Nenhum evento registrado</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>