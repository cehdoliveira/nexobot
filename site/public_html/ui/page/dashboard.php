<!-- Dashboard do Tradebot Binance -->
<div class="container py-4" x-data="dashboardController">
    <!-- Título e Informações do Usuário -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
                <div>
                    <h2 class="mb-1"><i class="bi bi-speedometer2"></i> Dashboard</h2>
                    <p class="text-muted mb-0">
                        Bem-vindo, <strong><?php echo $_SESSION[constant("cAppKey")]["credential"]["name"] ?? 'Trader'; ?></strong>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary btn-sm" @click="refreshData()">
                        <i class="bi bi-arrow-clockwise"></i> <span class="d-none d-sm-inline">Atualizar</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4">
        <!-- Total de Ordens Executadas -->
        <div class="col-6 col-md-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center">
                        <div class="flex-shrink-0 mb-2 mb-sm-0">
                            <div class="bg-primary bg-opacity-10 p-2 p-sm-3 rounded">
                                <i class="bi bi-graph-up text-primary" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-sm-3">
                            <p class="text-muted mb-1 small">Total de Trades</p>
                            <h4 class="mb-0 fs-5"><?php echo $dashboardData['stats']['trades']['total_trades'] ?? 0; ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Trades Abertos -->
        <div class="col-6 col-md-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center">
                        <div class="flex-shrink-0 mb-2 mb-sm-0">
                            <div class="bg-info bg-opacity-10 p-2 p-sm-3 rounded">
                                <i class="bi bi-clock-history text-info" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-sm-3">
                            <p class="text-muted mb-1 small">Abertos</p>
                            <h4 class="mb-0 fs-5"><?php echo $dashboardData['stats']['trades']['open_trades'] ?? 0; ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Trades Finalizados -->
        <div class="col-6 col-md-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center">
                        <div class="flex-shrink-0 mb-2 mb-sm-0">
                            <div class="bg-success bg-opacity-10 p-2 p-sm-3 rounded">
                                <i class="bi bi-check2-circle text-success" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-sm-3">
                            <p class="text-muted mb-1 small">Fechados</p>
                            <h4 class="mb-0 fs-5"><?php echo $dashboardData['stats']['trades']['closed_trades'] ?? 0; ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Saldo Total da Carteira -->
        <div class="col-6 col-md-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center">
                        <div class="flex-shrink-0 mb-2 mb-sm-0">
                            <div class="bg-warning bg-opacity-10 p-2 p-sm-3 rounded">
                                <i class="bi bi-wallet2 text-warning" style="font-size: 1.5rem;"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-sm-3">
                            <p class="text-muted mb-1 small">Saldo USDC</p>
                            <h4 class="mb-0 fs-6 fs-sm-5">$<?php echo number_format($dashboardData['wallet_total'] ?? 0, 2, '.', ','); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Card de Crescimento Patrimonial -->
    <?php if (isset($dashboardData['patrimonial_growth']) && $dashboardData['patrimonial_growth']['has_data']): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-graph-up-arrow"></i> Crescimento Patrimonial</h6>
                        <small class="text-muted">
                            <?php
                            $firstDate = new DateTime($dashboardData['patrimonial_growth']['first_snapshot_at']);
                            $lastDate = new DateTime($dashboardData['patrimonial_growth']['last_snapshot_at']);
                            $interval = $firstDate->diff($lastDate);
                            echo $interval->days . ' dias';
                            ?>
                        </small>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <p class="text-muted mb-1 small">Saldo Inicial</p>
                                <h5 class="mb-0 fs-6">
                                    $<?php echo number_format($dashboardData['patrimonial_growth']['initial_balance'], 2, '.', ','); ?>
                                </h5>
                                <small class="text-muted">
                                    <?php echo date('d/m/Y', strtotime($dashboardData['patrimonial_growth']['first_snapshot_at'])); ?>
                                </small>
                            </div>
                            <div class="col-6 col-md-3">
                                <p class="text-muted mb-1 small">Saldo Atual</p>
                                <h5 class="mb-0 fs-6">
                                    $<?php echo number_format($dashboardData['patrimonial_growth']['current_balance'], 2, '.', ','); ?>
                                </h5>
                                <small class="text-muted">
                                    <?php echo date('d/m/Y', strtotime($dashboardData['patrimonial_growth']['last_snapshot_at'])); ?>
                                </small>
                            </div>
                            <div class="col-6 col-md-3">
                                <p class="text-muted mb-1 small">Variação</p>
                                <h5 class="mb-0 fs-6 <?php echo $dashboardData['patrimonial_growth']['difference'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $dashboardData['patrimonial_growth']['difference'] >= 0 ? '+' : ''; ?>$<?php echo number_format($dashboardData['patrimonial_growth']['difference'], 2, '.', ','); ?>
                                </h5>
                            </div>
                            <div class="col-6 col-md-3">
                                <p class="text-muted mb-1 small">Crescimento</p>
                                <h4 class="mb-0 <?php echo $dashboardData['patrimonial_growth']['growth_percent'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <i class="bi bi-<?php echo $dashboardData['patrimonial_growth']['growth_percent'] >= 0 ? 'arrow-up' : 'arrow-down'; ?>-circle-fill"></i>
                                    <?php echo $dashboardData['patrimonial_growth']['growth_percent'] >= 0 ? '+' : ''; ?><?php echo number_format($dashboardData['patrimonial_growth']['growth_percent'], 2); ?>%
                                </h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Estatísticas Financeiras -->
    <?php if (($dashboardData['stats']['trades']['closed_trades'] ?? 0) > 0): ?>
        <div class="row g-3 mb-4">
            <div class="col-12 col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-bar-chart-line"></i> Performance</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6 col-sm-6">
                                <p class="text-muted mb-1 small">Win Rate</p>
                                <h5 class="text-success mb-0 fs-6">
                                    <?php echo number_format($dashboardData['stats']['financial']['win_rate'] ?? 0, 2); ?>%
                                </h5>
                            </div>
                            <div class="col-6 col-sm-6">
                                <p class="text-muted mb-1 small">Lucro Médio</p>
                                <h5 class="<?php echo ($dashboardData['stats']['financial']['avg_profit_percent'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?> mb-0 fs-6">
                                    <?php echo number_format($dashboardData['stats']['financial']['avg_profit_percent'] ?? 0, 2); ?>%
                                </h5>
                            </div>
                            <div class="col-6 col-sm-6">
                                <p class="text-muted mb-1 small">Trades Vencedores</p>
                                <h5 class="text-success mb-0 fs-6">
                                    <?php echo $dashboardData['stats']['financial']['winning_trades'] ?? 0; ?>
                                </h5>
                            </div>
                            <div class="col-6 col-sm-6">
                                <p class="text-muted mb-1 small">Trades Perdedores</p>
                                <h5 class="text-danger mb-0 fs-6">
                                    <?php echo $dashboardData['stats']['financial']['losing_trades'] ?? 0; ?>
                                </h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-currency-dollar"></i> Resultado Financeiro</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6 col-sm-6">
                                <p class="text-muted mb-1 small">Total Investido</p>
                                <h5 class="mb-0 fs-6">
                                    $<?php echo number_format($dashboardData['stats']['financial']['total_invested'] ?? 0, 2, '.', ','); ?>
                                </h5>
                            </div>
                            <div class="col-6 col-sm-6">
                                <p class="text-muted mb-1 small">Lucro/Prejuízo</p>
                                <h5 class="<?php echo ($dashboardData['stats']['financial']['total_profit_loss'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?> mb-0 fs-6">
                                    $<?php echo number_format($dashboardData['stats']['financial']['total_profit_loss'] ?? 0, 2, '.', ','); ?>
                                </h5>
                            </div>
                            <div class="col-6 col-sm-6">
                                <p class="text-muted mb-1 small">Total em Lucros</p>
                                <h5 class="text-success mb-0 fs-6">
                                    $<?php echo number_format($dashboardData['stats']['financial']['total_profit'] ?? 0, 2, '.', ','); ?>
                                </h5>
                            </div>
                            <div class="col-6 col-sm-6">
                                <p class="text-muted mb-1 small">Total em Perdas</p>
                                <h5 class="text-danger mb-0 fs-6">
                                    $<?php echo number_format(abs($dashboardData['stats']['financial']['total_loss'] ?? 0), 2, '.', ','); ?>
                                </h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Trades Abertos -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-clock-history"></i> Trades em Aberto</h6>
                    <span class="badge bg-info"><?php echo count($dashboardData['open_trades'] ?? []); ?></span>
                </div>
                <?php if (!empty($dashboardData['open_trades'])): ?>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-nowrap">Símbolo</th>
                                        <th class="text-nowrap d-none d-md-table-cell">Entrada</th>
                                        <th class="text-nowrap d-none d-lg-table-cell">Quantidade</th>
                                        <th class="text-nowrap">Investimento</th>
                                        <th class="text-nowrap d-none d-sm-table-cell">Take Profit</th>
                                        <th class="text-nowrap d-none d-xl-table-cell">Stop Loss</th>
                                        <th class="text-nowrap d-none d-lg-table-cell">Aberto em</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dashboardData['open_trades'] as $trade): ?>
                                        <tr>
                                            <td class="text-nowrap"><strong><?php echo htmlspecialchars($trade['symbol']); ?></strong></td>
                                            <td class="text-nowrap d-none d-md-table-cell">$<?php echo number_format($trade['entry_price'], 8); ?></td>
                                            <td class="text-nowrap d-none d-lg-table-cell"><?php echo number_format($trade['quantity'], 8); ?></td>
                                            <td class="text-nowrap">$<?php echo number_format($trade['investment'], 2); ?></td>
                                            <td class="text-success text-nowrap d-none d-sm-table-cell">$<?php echo number_format($trade['take_profit_price'] ?? 0, 8); ?></td>
                                            <td class="text-danger text-nowrap d-none d-xl-table-cell">$<?php echo number_format($trade['stop_loss_price'] ?? 0, 8); ?></td>
                                            <td class="text-nowrap d-none d-lg-table-cell"><?php echo date('d/m/Y H:i', strtotime($trade['opened_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card-body text-center text-muted py-5">
                        <i class="bi bi-clock-history" style="font-size: 3rem;"></i>
                        <p class="mt-3 mb-0">Nenhum trade aberto no momento</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Ordens Abertas na Binance -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-list-ul"></i> Ordens Abertas na Binance</h6>
                    <span class="badge bg-warning text-dark"><?php echo count($dashboardData['open_orders'] ?? []); ?></span>
                </div>
                <?php if (!empty($dashboardData['open_orders'])): ?>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-nowrap">Order ID</th>
                                        <th class="text-nowrap">Símbolo</th>
                                        <th class="text-nowrap d-none d-md-table-cell">Lado</th>
                                        <th class="text-nowrap d-none d-lg-table-cell">Tipo</th>
                                        <th class="text-nowrap d-none d-sm-table-cell">Preço</th>
                                        <th class="text-nowrap d-none d-xl-table-cell">Stop Price</th>
                                        <th class="text-nowrap d-none d-lg-table-cell">Quantidade</th>
                                        <th class="text-nowrap d-none d-md-table-cell">Status</th>
                                        <th class="text-nowrap d-none d-xl-table-cell">Data</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dashboardData['open_orders'] as $order): ?>
                                        <tr>
                                            <td class="text-nowrap"><code><?php echo $order['orderId']; ?></code></td>
                                            <td class="text-nowrap"><strong><?php echo htmlspecialchars($order['symbol']); ?></strong></td>
                                            <td class="text-nowrap d-none d-md-table-cell">
                                                <span class="badge bg-<?php echo $order['side'] === 'BUY' ? 'success' : 'danger'; ?>">
                                                    <?php echo $order['side']; ?>
                                                </span>
                                            </td>
                                            <td class="text-nowrap d-none d-lg-table-cell"><?php echo $order['type']; ?></td>
                                            <td class="text-nowrap d-none d-sm-table-cell">$<?php echo number_format((float)$order['price'], 8); ?></td>
                                            <td class="text-nowrap d-none d-xl-table-cell">
                                                <?php echo (float)$order['stopPrice'] > 0 ? '$' . number_format((float)$order['stopPrice'], 8) : '-'; ?>
                                            </td>
                                            <td class="text-nowrap d-none d-lg-table-cell"><?php echo number_format((float)$order['origQty'], 8); ?></td>
                                            <td class="text-nowrap d-none d-md-table-cell">
                                                <span class="badge bg-info"><?php echo $order['status']; ?></span>
                                            </td>
                                            <td class="text-nowrap d-none d-xl-table-cell"><?php echo $order['time']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card-body text-center text-muted py-5">
                        <i class="bi bi-list-ul" style="font-size: 3rem;"></i>
                        <p class="mt-3 mb-0">Nenhuma ordem aberta na Binance</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Trades Finalizados -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-check2-circle"></i> Trades Finalizados</h6>
                    <span class="badge bg-secondary"><?php echo $dashboardData['pagination']['total_records']; ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($dashboardData['closed_trades'])): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-nowrap">Símbolo</th>
                                        <th class="text-nowrap d-none d-md-table-cell">Entrada</th>
                                        <th class="text-nowrap d-none d-lg-table-cell">Saída</th>
                                        <th class="text-nowrap d-none d-lg-table-cell">Investimento</th>
                                        <th class="text-nowrap">Resultado</th>
                                        <th class="text-nowrap">%</th>
                                        <th class="text-nowrap d-none d-xl-table-cell">Fechado em</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dashboardData['closed_trades'] as $trade): ?>
                                        <tr>
                                            <td class="text-nowrap"><strong><?php echo htmlspecialchars($trade['symbol']); ?></strong></td>
                                            <td class="text-nowrap d-none d-md-table-cell">$<?php echo number_format($trade['entry_price'], 8); ?></td>
                                            <td class="text-nowrap d-none d-lg-table-cell">$<?php echo number_format($trade['exit_price'] ?? 0, 8); ?></td>
                                            <td class="text-nowrap d-none d-lg-table-cell">$<?php echo number_format($trade['investment'], 2); ?></td>
                                            <td class="<?php echo ($trade['profit_loss'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?> text-nowrap">
                                                <strong>$<?php echo number_format($trade['profit_loss'] ?? 0, 2); ?></strong>
                                            </td>
                                            <td class="<?php echo ($trade['profit_loss_percent'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?> text-nowrap">
                                                <strong><?php echo number_format($trade['profit_loss_percent'] ?? 0, 2); ?>%</strong>
                                            </td>
                                            <td class="text-nowrap d-none d-xl-table-cell"><?php echo date('d/m/Y H:i', strtotime($trade['closed_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Paginação -->
                        <?php if ($dashboardData['pagination']['total_pages'] > 1): ?>
                            <div class="card-footer bg-white">
                                <nav>
                                    <ul class="pagination pagination-sm justify-content-center mb-0 flex-wrap">
                                        <?php
                                        $currentPage = $dashboardData['pagination']['current_page'];
                                        $totalPages = $dashboardData['pagination']['total_pages'];
                                        ?>

                                        <!-- Primeira página -->
                                        <li class="page-item <?php echo $currentPage == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=1"><span class="d-none d-sm-inline">Primeira</span><span class="d-inline d-sm-none">««</span></a>
                                        </li>

                                        <!-- Página anterior -->
                                        <li class="page-item <?php echo $currentPage == 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $currentPage - 1; ?>"><span class="d-none d-sm-inline">Anterior</span><span class="d-inline d-sm-none">«</span></a>
                                        </li>

                                        <!-- Páginas numeradas -->
                                        <?php
                                        $start = max(1, $currentPage - 2);
                                        $end = min($totalPages, $currentPage + 2);

                                        for ($i = $start; $i <= $end; $i++):
                                        ?>
                                            <li class="page-item <?php echo $i == $currentPage ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>

                                        <!-- Próxima página -->
                                        <li class="page-item <?php echo $currentPage == $totalPages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $currentPage + 1; ?>"><span class="d-none d-sm-inline">Próxima</span><span class="d-inline d-sm-none">»</span></a>
                                        </li>

                                        <!-- Última página -->
                                        <li class="page-item <?php echo $currentPage == $totalPages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $totalPages; ?>"><span class="d-none d-sm-inline">Última</span><span class="d-inline d-sm-none">»»</span></a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="card-body text-center text-muted py-5">
                            <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                            <p class="mt-3 mb-0">Nenhum trade finalizado ainda</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>