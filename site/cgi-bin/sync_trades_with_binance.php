#!/usr/bin/env php
<?php
/**
 * Script de Sincronização de Trades com Binance
 * 
 * Este script busca os dados reais das ordens na Binance e atualiza:
 * - Investimento real (baseado no que foi realmente gasto)
 * - Profit/Loss recalculado com valores exatos
 * 
 * Execute: php sync_trades_with_binance.php [--trade-id=ID] [--symbol=SYMBOL] [--dry-run]
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set("America/Sao_Paulo");

$_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../public_html/";
$_SERVER["HTTP_HOST"] = "gridnexobot.local";
putenv('SERVER_PORT=80');
putenv('SERVER_PROTOCOL=http');
putenv('SERVER_NAME=' . $_SERVER["HTTP_HOST"]);
putenv('SCRIPT_NAME=index.php');
set_include_path($_SERVER["DOCUMENT_ROOT"] . PATH_SEPARATOR . get_include_path());
require_once($_SERVER["DOCUMENT_ROOT"] . "../app/inc/main.php");

use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║     SINCRONIZADOR DE TRADES COM BINANCE v1.0                   ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Parse arguments
$options = getopt("", ["trade-id::", "symbol::", "dry-run"]);
$tradeId = $options['trade-id'] ?? null;
$symbol = $options['symbol'] ?? null;
$dryRun = isset($options['dry-run']);

if ($dryRun) {
    echo "⚠️  MODO DRY-RUN ATIVADO - Nenhuma alteração será salva\n\n";
}

try {
    // Inicializar API Binance
    $binanceConfig = BinanceConfig::getActiveCredentials();
    if (empty($binanceConfig['apiKey']) || empty($binanceConfig['secretKey'])) {
        echo "❌ ERRO: Credenciais da Binance não configuradas!\n";
        echo "   Configure as credenciais no Dashboard > Configurações\n";
        exit(1);
    }
    
    $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
    $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
    $configurationBuilder->url($binanceConfig['baseUrl']);
    $api = new SpotRestApi($configurationBuilder->build());
    
    echo "✅ Conexão com Binance (" . $binanceConfig['mode'] . ") estabelecida\n\n";

    // Buscar trades fechados
    $con = new local_pdo();
    $filter = "WHERE active = 'yes' AND status = 'closed'";
    if ($tradeId) {
        $filter .= " AND idx = " . (int)$tradeId;
    }
    if ($symbol) {
        $filter .= " AND symbol = '" . $con->real_escape_string($symbol) . "'";
    }
    
    $result = $con->select("*", "trades", $filter . " ORDER BY closed_at DESC");
    $trades = $con->results($result);

    if (empty($trades)) {
        echo "❌ Nenhum trade fechado encontrado com os filtros especificados.\n";
        exit(0);
    }

    echo "📊 Processando " . count($trades) . " trade(s)...\n";
    echo "════════════════════════════════════════════════════════════════\n\n";

    $totalUpdated = 0;
    $totalErrors = 0;

    foreach ($trades as $trade) {
        $tradeIdx = $trade['idx'];
        $tradeSymbol = $trade['symbol'];
        
        echo "📈 Trade ID: {$tradeIdx} | Símbolo: {$tradeSymbol}\n";
        echo "─────────────────────────────────────────────────────────────\n";

        try {
            // Buscar ordens de compra (BUY/ENTRY)
            $ordersResult = $con->select("*", "orders", "WHERE active = 'yes' AND idx IN (SELECT orders_id FROM orders_trades WHERE active = 'yes' AND trades_id = '{$tradeIdx}' AND trades_id = '{$tradeIdx}') AND side = 'BUY' AND order_type = 'entry'");
            $orders = $con->results($ordersResult);

            if (empty($orders)) {
                echo "⚠️  Nenhuma ordem de compra encontrada no banco.\n\n";
                continue;
            }

            $buyOrder = $orders[0];
            $binanceOrderId = $buyOrder['binance_order_id'];
            
            echo "🔍 Buscando ordem #{$binanceOrderId} na Binance...\n";

            // Buscar detalhes da ordem na Binance
            $binanceOrder = null;
            try {
                $response = $api->getOrder($tradeSymbol, $binanceOrderId);
                $binanceOrder = $response->getData();
                
                // Converter para array se necessário
                if (!is_array($binanceOrder)) {
                    $binanceOrder = json_decode(json_encode($binanceOrder), true);
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), '-2013') !== false || strpos($e->getMessage(), '404') !== false) {
                    echo "⚠️  Ordem não encontrada na Binance (pode ser de outra session)\n\n";
                    continue;
                }
                throw $e;
            }

            // Extrair dados da ordem de compra
            $executedQty = (float)($binanceOrder['executedQty'] ?? 0);
            $executedPrice = (float)($binanceOrder['cummulativeQuoteQty'] ?? 0);
            
            if ($executedQty == 0) {
                echo "⚠️  Ordem de compra não foi executada (quantidade = 0)\n\n";
                continue;
            }

            // Calcular investimento real
            $realInvestment = $executedPrice;
            $oldInvestment = (float)$trade['investment'];
            $difference = abs($realInvestment - $oldInvestment);

            echo "   Investimento bancado: $" . number_format($oldInvestment, 8) . "\n";
            echo "   Investimento real:    $" . number_format($realInvestment, 8) . "\n";
            
            if ($difference > 0.01) {
                echo "   Diferença: $" . number_format($difference, 8) . " ⚠️ \n";
            } else {
                echo "   ✓ Valores iguais\n";
            }

            // Buscar ordens de venda (TP1 e TP2) e sincronizar com Binance
            echo "\n🔍 Sincronizando ordens de venda...\n";
            
            // Buscar todas as ordens SELL (take_profit) do trade
            $sellOrdersResult = $con->select("*", "orders", "WHERE active = 'yes' AND idx IN (SELECT orders_id FROM orders_trades WHERE active = 'yes' AND trades_id = '{$tradeIdx}') AND side = 'SELL' AND order_type = 'take_profit' ORDER BY binance_order_id ASC");
            $sellOrders = $con->results($sellOrdersResult);
            
            echo "   📋 Ordens de venda encontradas: " . count($sellOrders) . "\n";
            
            $tp1Qty = 0;
            $tp1Revenue = 0;
            $tp2Qty = 0;
            $tp2Revenue = 0;
            $totalSellRevenue = 0;
            
            foreach ($sellOrders as $idx => $sellOrder) {
                $sellBinanceId = $sellOrder['binance_order_id'];
                $orderNum = $idx + 1;
                
                try {
                    $sellResponse = $api->getOrder($tradeSymbol, $sellBinanceId);
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
                    
                    echo "   ✅ Ordem SELL #{$orderNum} (Binance: {$sellBinanceId}): " . number_format($sellQty, 8) . " un = $" . number_format($sellRevenue, 8) . "\n";
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), '-2013') === false && strpos($e->getMessage(), '404') === false) {
                        echo "   ⚠️  Erro ao buscar ordem #{$sellBinanceId}: " . $e->getMessage() . "\n";
                    } else {
                        echo "   ⚠️  Ordem #{$sellBinanceId} não encontrada na Binance\n";
                    }
                }
            }

            // Recalcular profit/loss com valores reais da Binance
            $totalRevenue = $totalSellRevenue;
            $newProfitLoss = $totalRevenue - $realInvestment;
            $newProfitLossPercent = $realInvestment > 0 ? (($newProfitLoss / $realInvestment) * 100) : 0;
            
            // Calcular preços médios para atualizar no banco
            $tp1AvgPrice = $tp1Qty > 0 ? ($tp1Revenue / $tp1Qty) : (float)($trade['take_profit_1_price'] ?? 0);
            $tp2AvgPrice = $tp2Qty > 0 ? ($tp2Revenue / $tp2Qty) : (float)($trade['take_profit_2_price'] ?? 0);
            
            echo "\n   💰 Receita total: $" . number_format($totalRevenue, 8) . "\n";
            echo "   💵 Investimento:  $" . number_format($realInvestment, 8) . "\n";


            echo "\n   P/L Antigo: $" . number_format((float)$trade['profit_loss'], 8) . " (" . number_format((float)$trade['profit_loss_percent'], 2) . "%)\n";
            echo "   P/L Novo:  $" . number_format($newProfitLoss, 8) . " (" . number_format($newProfitLossPercent, 2) . "%)\n";

            // Atualizar banco de dados
            if ($difference > 0.001 || abs($newProfitLoss - (float)$trade['profit_loss']) > 0.001 || abs($tp1Qty - (float)$trade['tp1_executed_qty']) > 0.001 || abs($tp2Qty - (float)$trade['tp2_executed_qty']) > 0.001) {
                echo "\n   💾 Atualizando banco de dados...\n";

                if (!$dryRun) {
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
                    
                    echo "   ✅ Trade #{$tradeIdx} atualizado com sucesso!\n";
                    $totalUpdated++;
                } else {
                    echo "   (DRY-RUN: alterações não foram salvas)\n";
                }
            } else {
                echo "\n   ✓ Nenhuma alteração necessária\n";
            }

            echo "\n";

        } catch (Exception $e) {
            echo "❌ Erro ao processar trade #{$tradeIdx}: " . $e->getMessage() . "\n\n";
            $totalErrors++;
        }
    }

    echo "════════════════════════════════════════════════════════════════\n";
    echo "\n📋 RESUMO:\n";
    echo "   Trades atualizados: {$totalUpdated}\n";
    echo "   Erros encontrados:  {$totalErrors}\n";
    echo "\n✅ Sincronização concluída!\n";

} catch (Exception $e) {
    echo "❌ ERRO FATAL: " . $e->getMessage() . "\n";
    echo "\nStack Trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
