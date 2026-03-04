<?php
/**
 * SCRIPT DE DIAGNÓSTICO: Verificar CRONs rodando
 * Execute: php check_cron_status.php
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

echo "═══════════════════════════════════════════════\n";
echo "  DIAGNÓSTICO DE CRON - NEXOBOT\n";
echo "═══════════════════════════════════════════════\n\n";

// 1. Verificar processos PHP rodando
echo "🔍 PROCESSOS PHP ATIVOS:\n";
echo "─────────────────────────────────────────────────\n";

exec("ps aux | grep 'sync_trades_auto.php' | grep -v grep", $output1);
exec("ps aux | grep 'sync_grid_orders.php' | grep -v grep", $output2);
exec("ps aux | grep 'verify_entry.php' | grep -v grep", $output3);

$processes = array_merge($output1, $output2, $output3);

if (empty($processes)) {
    echo "✅ Nenhum processo CRON ativo no momento.\n";
} else {
    echo "⚠️  Encontrados " . count($processes) . " processos:\n\n";
    foreach ($processes as $proc) {
        echo "  " . $proc . "\n";
    }
    
    if (count($processes) > 1) {
        echo "\n❌ PROBLEMA: Múltiplos processos detectados!\n";
        echo "   Isso causa conflito de locks e race conditions.\n";
        echo "   📌 AÇÃO: Verifique se há múltiplas CRONs configuradas.\n";
    }
}

echo "\n";

// 2. Verificar configuração do CRONTAB
echo "📋 CONFIGURAÇÃO DO CRONTAB:\n";
echo "─────────────────────────────────────────────────\n";

exec("crontab -l 2>/dev/null | grep -E '(sync_trades|sync_grid|verify_entry)'", $cronEntries);

if (empty($cronEntries)) {
    echo "⚠️  Nenhuma entrada CRON encontrada para o usuário atual.\n";
    echo "   Se estiver rodando via root ou outro usuário, execute:\n";
    echo "   sudo crontab -l | grep sync\n";
} else {
    foreach ($cronEntries as $entry) {
        echo "  " . $entry . "\n";
    }
    
    // Contar quantas vezes cada script aparece
    $syncTradesCount = 0;
    $syncGridCount = 0;
    
    foreach ($cronEntries as $entry) {
        if (strpos($entry, 'sync_trades_auto.php') !== false) $syncTradesCount++;
        if (strpos($entry, 'sync_grid_orders.php') !== false) $syncGridCount++;
    }
    
    echo "\n";
    if ($syncTradesCount > 1) {
        echo "❌ PROBLEMA: sync_trades_auto.php aparece $syncTradesCount vezes!\n";
        echo "   Múltiplas instâncias causam conflito de locks.\n";
        echo "   📌 AÇÃO: Remova duplicatas com 'crontab -e'\n";
    }
    
    if ($syncGridCount > 1) {
        echo "❌ PROBLEMA: sync_grid_orders.php aparece $syncGridCount vezes!\n";
        echo "   📌 AÇÃO: Remova duplicatas com 'crontab -e'\n";
    }
}

echo "\n";

// 3. Verificar locks travados no banco
echo "🔒 LOCKS NO BANCO DE DADOS:\n";
echo "─────────────────────────────────────────────────\n";

require_once($_SERVER["DOCUMENT_ROOT"] . "../app/inc/main.php");

try {
    $gridsModel = new grids_model();
    $gridsModel->set_filter(["active = 'yes'"]);
    $gridsModel->load_data();
    
    if (empty($gridsModel->data)) {
        echo "ℹ️  Nenhum grid ativo encontrado.\n";
    } else {
        foreach ($gridsModel->data as $grid) {
            $gridId = $grid['idx'];
            $symbol = $grid['symbol'];
            $isProcessing = $grid['is_processing'] ?? 'no';
            $lastMonitor = $grid['last_monitor_at'] ?? 'nunca';
            
            echo "Grid #$gridId ($symbol):\n";
            echo "  Status:         {$grid['status']}\n";
            echo "  Em processo:    $isProcessing\n";
            echo "  Último monitor: $lastMonitor\n";
            
            if ($isProcessing === 'yes') {
                if ($lastMonitor !== 'nunca') {
                    $elapsed = time() - strtotime($lastMonitor);
                    $minutes = round($elapsed / 60, 1);
                    
                    if ($minutes > 3) {
                        echo "  ❌ LOCK TRAVADO! ($minutes minutos)\n";
                        echo "     📌 AÇÃO 1: Pare a CRON temporariamente\n";
                        echo "     📌 AÇÃO 2: Execute: php force_release_locks.php\n";
                        echo "     📌 AÇÃO 3: Reinicie a CRON\n";
                    } else {
                        echo "  ✅ Lock legítimo ($minutes minutos)\n";
                    }
                } else {
                    echo "  ⚠️  Lock sem timestamp (anômalo)\n";
                }
            } else {
                echo "  ✅ Sem lock ativo\n";
            }
            
            echo "\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Erro ao verificar locks: " . $e->getMessage() . "\n";
}

echo "═══════════════════════════════════════════════\n";
