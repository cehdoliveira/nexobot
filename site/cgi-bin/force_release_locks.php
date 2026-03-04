<?php
/**
 * SCRIPT DE MANUTENÇÃO: Força liberação de locks travados
 * Execute: php force_release_locks.php
 * 
 * ⚠️  Use com cuidado! Pause a CRON antes de executar.
 */

require_once dirname(__DIR__) . '/app/inc/kernel.php';

echo "═══════════════════════════════════════════════\n";
echo "  FORÇA LIBERAÇÃO DE LOCKS - NEXOBOT\n";
echo "═══════════════════════════════════════════════\n\n";

echo "⚠️  IMPORTANTE: Certifique-se de que a CRON esteja PARADA!\n";
echo "   Execute: sudo systemctl stop cron (ou pause manualmente)\n\n";

echo "Deseja continuar? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim(strtolower($line)) !== 'y') {
    echo "❌ Operação cancelada.\n";
    exit(0);
}

echo "\n🔓 Liberando todos os locks...\n\n";

try {
    $gridsModel = new grids_model();
    $gridsModel->set_filter(["active = 'yes'", "is_processing = 'yes'"]);
    $gridsModel->load_data();
    
    if (empty($gridsModel->data)) {
        echo "✅ Nenhum lock ativo encontrado.\n";
    } else {
        $count = 0;
        foreach ($gridsModel->data as $grid) {
            $gridId = $grid['idx'];
            $symbol = $grid['symbol'];
            $lastMonitor = $grid['last_monitor_at'] ?? 'nunca';
            
            // Liberar lock
            $model = new grids_model();
            $model->set_filter(["idx = '$gridId'"]);
            $model->populate(['is_processing' => 'no']);
            $model->save();
            
            echo "✅ Grid #$gridId ($symbol) - Lock liberado\n";
            echo "   Último monitor: $lastMonitor\n\n";
            $count++;
        }
        
        echo "═══════════════════════════════════════════════\n";
        echo "✅ $count lock(s) liberado(s) com sucesso!\n\n";
        echo "📌 PRÓXIMOS PASSOS:\n";
        echo "   1. Reinicie a CRON: sudo systemctl start cron\n";
        echo "   2. Monitore os logs: tail -f /var/log/cron.log\n";
        echo "   3. Se locks travarem novamente:\n";
        echo "      - Verifique se há múltiplas CRONs (crontab -l)\n";
        echo "      - Verifique timeouts PHP (max_execution_time)\n";
        echo "      - Verifique memória PHP (memory_limit)\n";
    }
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
}

echo "\n═══════════════════════════════════════════════\n";
