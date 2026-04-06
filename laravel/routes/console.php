<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;

$legacyScripts = [
    'legacy:verify-entry' => 'verify_entry.php',
    'legacy:sync-grid-orders' => 'sync_grid_orders.php',
    'legacy:sync-trades-auto' => 'sync_trades_auto.php',
    'legacy:sync-trades-with-binance' => 'sync_trades_with_binance.php',
    'legacy:check-capital' => 'check_capital.php',
    'legacy:check-cron-status' => 'check_cron_status.php',
];

foreach ($legacyScripts as $signature => $script) {
    Artisan::command($signature.' {--timeout=300}', function () use ($script) {
        $runner = app(\App\Support\LegacyScriptRunner::class);
        $process = $runner->run(
            $script,
            (int) $this->option('timeout'),
            function (string $type, string $buffer): void {
                $this->output->write($buffer);
            }
        );

        if (! $process->isSuccessful()) {
            $this->error('Legacy script failed: '.$script);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    })->purpose('Run '.$script.' through Laravel.');
}

Schedule::command('legacy:verify-entry --timeout=300')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
