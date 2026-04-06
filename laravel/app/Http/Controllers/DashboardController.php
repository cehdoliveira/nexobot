<?php

namespace App\Http\Controllers;

use App\Models\Grid;
use App\Models\GridLog;
use App\Models\Order;
use App\Models\Trade;
use App\Models\User;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $stats = [
            'active_grids' => Grid::query()->where('active', 'yes')->where('status', 'active')->count(),
            'open_orders' => Order::query()->where('active', 'yes')->whereIn('status', ['NEW', 'PARTIALLY_FILLED'])->count(),
            'closed_trades' => Trade::query()->where('active', 'yes')->where('status', 'closed')->count(),
            'active_users' => User::query()->where('active', 'yes')->where('enabled', 'yes')->count(),
        ];

        $recentGridLogs = GridLog::query()
            ->where('active', 'yes')
            ->latest('created_at')
            ->limit(10)
            ->get(['event', 'log_type', 'message', 'created_at']);

        $legacyCommands = [
            'php artisan legacy:verify-entry',
            'php artisan legacy:sync-grid-orders',
            'php artisan legacy:sync-trades-auto',
            'php artisan legacy:sync-trades-with-binance',
            'php artisan legacy:check-capital',
            'php artisan legacy:check-cron-status',
        ];

        return view('dashboard', [
            'stats' => $stats,
            'recentGridLogs' => $recentGridLogs,
            'legacyCommands' => $legacyCommands,
        ]);
    }
}
