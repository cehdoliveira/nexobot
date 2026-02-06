<?php

use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;

ini_set('display_errors', 0);
error_reporting(0);

date_default_timezone_set("America/Sao_Paulo");

$_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../public_html/";
$_SERVER["HTTP_HOST"] = "nexobot.local";
putenv('SERVER_PORT=80');
putenv('SERVER_PROTOCOL=http');
putenv('SERVER_NAME=' . $_SERVER["HTTP_HOST"]);
putenv('SCRIPT_NAME=index.php');
set_include_path($_SERVER["DOCUMENT_ROOT"] . PATH_SEPARATOR . get_include_path());

try {
    require_once($_SERVER["DOCUMENT_ROOT"] . "../app/inc/main.php");

    // Log message
    $logFile = '/var/log/sync.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    function logMsg($msg)
    {
        global $logFile;
        $timestamp = date('Y-m-d H:i:s');
        $msg = "[{$timestamp}] {$msg}\n";
        @file_put_contents($logFile, $msg, FILE_APPEND);
    }

    logMsg("🔄 Sincronização automática iniciada");

    // Inicializar API Binance
    $binanceConfig = BinanceConfig::getActiveCredentials();
    $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
    $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
    $configurationBuilder->url($binanceConfig['baseUrl']);
    $api = new SpotRestApi($configurationBuilder->build());

    // Buscar trades fechados dos últimos 7 dias
    $con = new local_pdo();
    $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

    $result = $con->select(
        "*",
        "trades",
        "WHERE active = 'yes' AND status = 'closed' AND closed_at > '{$sevenDaysAgo}' ORDER BY closed_at DESC"
    );
    $trades = $con->results($result);

    if (empty($trades)) {
        logMsg("✓ Nenhum trade para sincronizar");
        exit(0);
    }

    logMsg("📊 Processando " . count($trades) . " trade(s)");

    $updated = 0;
    $errors = 0;

    foreach ($trades as $trade) {
        try {
            $tradeIdx = $trade['idx'];
            $tradeSymbol = $trade['symbol'];

            // Buscar ordens de compra
            $ordersResult = $con->select(
                "*",
                "orders",
                "WHERE active = 'yes' AND idx IN (SELECT orders_id FROM orders_trades WHERE active = 'yes' AND trades_id = '{$tradeIdx}') AND side = 'BUY' AND order_type = 'entry'"
            );
            $orders = $con->results($ordersResult);

            if (empty($orders)) {
                continue;
            }

            $buyOrder = $orders[0];
            $binanceOrderId = $buyOrder['binance_order_id'];

            // Buscar ordem na Binance
            $binanceOrder = null;
            try {
                $response = $api->getOrder($tradeSymbol, $binanceOrderId);
                $binanceOrder = $response->getData();

                if (!is_array($binanceOrder)) {
                    $binanceOrder = json_decode(json_encode($binanceOrder), true);
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), '-2013') !== false || strpos($e->getMessage(), '404') !== false) {
                    continue;
                }
                throw $e;
            }

            $executedQty = (float)($binanceOrder['executedQty'] ?? 0);
            $realInvestment = (float)($binanceOrder['cummulativeQuoteQty'] ?? 0);

            if ($executedQty == 0) {
                continue;
            }

            $oldInvestment = (float)$trade['investment'];

            // Buscar e sincronizar ordens de venda
            $sellOrdersResult = $con->select("*", "orders", "WHERE active = 'yes' AND idx IN (SELECT orders_id FROM orders_trades WHERE active = 'yes' AND trades_id = '{$tradeIdx}') AND side = 'SELL' AND order_type = 'take_profit' ORDER BY binance_order_id ASC");
            $sellOrders = $con->results($sellOrdersResult);
            
            $tp1Qty = 0;
            $tp1Revenue = 0;
            $tp2Qty = 0;
            $tp2Revenue = 0;
            $totalSellRevenue = 0;
            
            foreach ($sellOrders as $idx => $sellOrder) {
                try {
                    $sellResponse = $api->getOrder($tradeSymbol, $sellOrder['binance_order_id']);
                    $sellBinanceOrder = $sellResponse->getData();
                    if (!is_array($sellBinanceOrder)) {
                        $sellBinanceOrder = json_decode(json_encode($sellBinanceOrder), true);
                    }
                    
                    $sellQty = (float)($sellBinanceOrder['executedQty'] ?? 0);
                    $sellRevenue = (float)($sellBinanceOrder['cummulativeQuoteQty'] ?? 0);
                    $totalSellRevenue += $sellRevenue;
                    
                    // Primeira ordem = TP1, Segunda = TP2
                    if ($idx == 0) {
                        $tp1Qty = $sellQty;
                        $tp1Revenue = $sellRevenue;
                    } elseif ($idx == 1) {
                        $tp2Qty = $sellQty;
                        $tp2Revenue = $sellRevenue;
                    }
                } catch (Exception $e) {
                    // Ignora erros silenciosamente no modo automático
                }
            }

            // Recalcular P/L com valores reais da Binance
            $totalRevenue = $totalSellRevenue;
            $newProfitLoss = $totalRevenue - $realInvestment;
            $newProfitLossPercent = $realInvestment > 0 ? (($newProfitLoss / $realInvestment) * 100) : 0;
            
            // Calcular preços médios
            $tp1AvgPrice = $tp1Qty > 0 ? ($tp1Revenue / $tp1Qty) : (float)($trade['take_profit_1_price'] ?? 0);
            $tp2AvgPrice = $tp2Qty > 0 ? ($tp2Revenue / $tp2Qty) : (float)($trade['take_profit_2_price'] ?? 0);
            
            // Verificar se há diferença significativa em qualquer valor
            $hasChanges = abs($realInvestment - $oldInvestment) >= 0.001 ||
                         abs($newProfitLoss - (float)$trade['profit_loss']) >= 0.001 ||
                         abs($tp1Qty - (float)($trade['tp1_executed_qty'] ?? 0)) >= 0.001 ||
                         abs($tp2Qty - (float)($trade['tp2_executed_qty'] ?? 0)) >= 0.001;
            
            if (!$hasChanges) {
                continue;
            }

            // Atualizar banco
            $updateFields = [
                "investment = '" . $con->real_escape_string((string)$realInvestment) . "'",
                "profit_loss = '" . $con->real_escape_string((string)$newProfitLoss) . "'",
                "profit_loss_percent = '" . $con->real_escape_string((string)$newProfitLossPercent) . "'",
                "tp1_executed_qty = '" . $con->real_escape_string((string)$tp1Qty) . "'",
                "tp2_executed_qty = '" . $con->real_escape_string((string)$tp2Qty) . "'",
                "take_profit_1_price = '" . $con->real_escape_string((string)$tp1AvgPrice) . "'",
                "take_profit_2_price = '" . $con->real_escape_string((string)$tp2AvgPrice) . "'"
            ];

            $con->update(
                implode(", ", $updateFields),
                "trades",
                "WHERE active = 'yes' AND idx = '{$tradeIdx}'"
            );

            // Limpar cache
            if (class_exists('RedisCache')) {
                $cache = RedisCache::getInstance();
                if ($cache && $cache->isConnected()) {
                    $cache->deletePattern('query:trades:*');
                }
            }

            logMsg("✅ Trade #{$tradeIdx} atualizado");
            $updated++;
        } catch (Exception $e) {
            logMsg("❌ Erro no trade #{$tradeIdx}: " . $e->getMessage());
            $errors++;
        }
    }

    logMsg("✅ Sincronização concluída - {$updated} atualizado(s), {$errors} erro(s)");
} catch (Exception $e) {
    @file_put_contents('/var/log/sync.log', "[" . date('Y-m-d H:i:s') . "] ❌ ERRO FATAL: " . $e->getMessage() . "\n", FILE_APPEND);
    exit(1);
}
