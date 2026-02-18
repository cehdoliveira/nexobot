#!/usr/bin/env php
<?php
/**
 * Script para sincronizar ordens do Grid Trading com a Binance
 * Executa: php sync_grid_orders.php
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set("America/Sao_Paulo");

$_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../public_html/";
$_SERVER["HTTP_HOST"] = "nexobot.local";
putenv('SERVER_PORT=80');
putenv('SERVER_PROTOCOL=http');
putenv('SERVER_NAME=' . $_SERVER["HTTP_HOST"]);
putenv('SCRIPT_NAME=index.php');
set_include_path($_SERVER["DOCUMENT_ROOT"] . PATH_SEPARATOR . get_include_path());
require_once($_SERVER["DOCUMENT_ROOT"] . "../app/inc/main.php");

use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║     SINCRONIZADOR DE ORDENS DO GRID TRADING                    ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

try {
    // Inicializar API Binance
    $binanceConfig = BinanceConfig::getActiveCredentials();
    if (empty($binanceConfig['apiKey']) || empty($binanceConfig['secretKey'])) {
        echo "❌ ERRO: Credenciais da Binance não configuradas!\n";
        exit(1);
    }
    
   $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
    $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
    $configurationBuilder->url($binanceConfig['baseUrl']);
    $client = new SpotRestApi($configurationBuilder->build());
    
    echo "✅ Conexão com Binance (" . $binanceConfig['mode'] . ") estabelecida\n\n";

    // Buscar grids ativos
    $gridsModel = new grids_model();
    $gridsModel->set_filter(["active = 'yes'", "status = 'active'"]);
    $gridsModel->load_data();

    if (empty($gridsModel->data)) {
        echo "⚠️  Nenhum grid ativo encontrado\n";
        exit(0);
    }

    echo "📊 Grids ativos: " . count($gridsModel->data) . "\n";
    echo "════════════════════════════════════════════════════════════════\n\n";

    $totalUpdated = 0;
    $totalProcessed = 0;

    foreach ($gridsModel->data as $grid) {
        $gridId = $grid['idx'];
        $symbol = $grid['symbol'];
        
        echo "🔍 Grid ID: {$gridId} | Símbolo: {$symbol}\n";
        echo "─────────────────────────────────────────────────────────────\n";

        // Buscar ordens do grid com JOIN
        $con = new local_pdo();
        $result = $con->select(
            "go.idx as grids_orders_idx, go.grid_level, go.is_processed, go.paired_order_id, " .
            "o.idx as order_id, o.binance_order_id, o.symbol, o.side, o.status, o.price, o.quantity, o.executed_qty",
            "grids_orders go JOIN orders o ON go.orders_id = o.idx",
            "WHERE go.active = 'yes' AND go.grids_id = '{$gridId}'"
        );
        $orders = $con->results($result);

        if (empty($orders)) {
            echo "⚠️  Nenhuma ordem encontrada\n\n";
            continue;
        }

        echo "📋 Total de ordens: " . count($orders) . "\n\n";

        foreach ($orders as $order) {

            $totalProcessed++;

            // Só sincronizar ordens que ainda não foram finalizadas
            if (!in_array($order['status'], ['NEW', 'PARTIALLY_FILLED'])) {
                echo "   ⏩ Ordem {$order['binance_order_id']} já finalizada ({$order['status']})\n";
                continue;
            }

            try {
                echo "   🔄 Sincronizando ordem {$order['binance_order_id']}... ";

                // Consultar status na Binance
                $response = $client->getOrder($order['symbol'], $order['binance_order_id']);
                $binanceOrder = $response->getData();

                $newStatus = method_exists($binanceOrder, 'getStatus')
                    ? $binanceOrder->getStatus()
                    : ($binanceOrder['status'] ?? null);

                $executedQty = method_exists($binanceOrder, 'getExecutedQty')
                    ? $binanceOrder->getExecutedQty()
                    : ($binanceOrder['executedQty'] ?? 0);

                // Atualizar ordem se status mudou
                if ($newStatus && $newStatus !== $order['status']) {
                    $ordersModel = new orders_model();
                    $ordersModel->set_filter(["idx = '{$order['order_id']}'"]);
                    $ordersModel->populate([
                        'status' => $newStatus,
                        'executed_qty' => (float)$executedQty
                    ]);
                    $ordersModel->save();

                    $totalUpdated++;

                    echo "✅ {$order['status']} → {$newStatus}";
                    if ($newStatus === 'FILLED') {
                        echo " (Executada: {$executedQty})";
                    }
                    echo "\n";
                } else {
                    echo "➡️  Sem alteração ({$newStatus})\n";
                }
            } catch (Exception $e) {
                echo "❌ Erro: " . $e->getMessage() . "\n";
                continue;
            }
        }

        echo "\n";
    }

    echo "════════════════════════════════════════════════════════════════\n";
    echo "📊 Resumo:\n";
    echo "   Ordens processadas: {$totalProcessed}\n";
    echo "   Ordens atualizadas: {$totalUpdated}\n";
    echo "════════════════════════════════════════════════════════════════\n";

    if ($totalUpdated > 0) {
        echo "\n✅ Sincronização concluída! Execute o bot para processar as ordens executadas.\n";
    } else {
        echo "\n✅ Todas as ordens já estão sincronizadas.\n";
    }

} catch (Exception $e) {
    echo "\n❌ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
