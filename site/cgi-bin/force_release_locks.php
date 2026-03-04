<?php
/**
 * SCRIPT DE MANUTENÇÃO: Força liberação de locks travados
 * Execute: php force_release_locks.php
 * 
 * ⚠️  Use com cuidado! Pause a CRON antes de executar.
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configurar timezone ANTES de qualquer operação com data/hora
date_default_timezone_set("America/Sao_Paulo");

$_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../public_html/";
$_SERVER["HTTP_HOST"] = "nexobot.local";
putenv('SERVER_PORT=80');
putenv('SERVER_PROTOCOL=http');
putenv('SERVER_NAME=' . $_SERVER["HTTP_HOST"]);
putenv('SCRIPT_NAME=index.php');
set_include_path($_SERVER["DOCUMENT_ROOT"] . PATH_SEPARATOR . get_include_path());

require_once($_SERVER["DOCUMENT_ROOT"] . "../app/inc/main.php");

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
