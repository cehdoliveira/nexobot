#!/usr/bin/env php
<?php
/**
 * Script para Identificar Trades Orfãos
 * 
 * Identifica trades que foram comprados mas falharam ao criar TPs
 * devido a erros de saldo insuficiente.
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
echo "║     DETECTOR DE TRADES ORFÃOS v1.0                             ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

try {
    // Conectar à Binance
    $binanceConfig = BinanceConfig::getActiveCredentials();
    $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
    $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
    $configurationBuilder->url($binanceConfig['baseUrl']);
    $api = new SpotRestApi($configurationBuilder->build());
    
    echo "✅ Conexão com Binance estabelecida\n\n";

    // Buscar trades abertos
    $con = new local_pdo();
    $result = $con->select("*", "trades", "WHERE active = 'yes' AND status = 'open' ORDER BY opened_at DESC");
    $trades = $con->results($result);

    if (empty($trades)) {
        echo "✅ Nenhum trade aberto encontrado.\n";
        exit(0);
    }

    echo "📊 Analisando " . count($trades) . " trade(s) aberto(s)...\n";
    echo "════════════════════════════════════════════════════════════════\n\n";

    $orphans = [];
    $partial = [];
    $ok = [];

    foreach ($trades as $trade) {
        $tradeIdx = $trade['idx'];
        $symbol = $trade['symbol'];
        $quantity = (float)$trade['quantity'];
        $investment = (float)$trade['investment'];
        
        echo "🔍 Trade #{$tradeIdx} - {$symbol}\n";
        echo "   Investimento: \${$investment}\n";
        echo "   Quantidade: {$quantity}\n";
        
        // Buscar ordens associadas
        $ordersResult = $con->select(
            "*", 
            "orders", 
            "WHERE active = 'yes' AND idx IN (SELECT orders_id FROM orders_trades WHERE active = 'yes' AND trades_id = '{$tradeIdx}')"
        );
        $orders = $con->results($ordersResult);
        
        $hasBuy = false;
        $hasTP1 = false;
        $hasTP2 = false;
        
        foreach ($orders as $order) {
            if ($order['order_type'] === 'entry' && $order['side'] === 'BUY') {
                $hasBuy = true;
            }
            if ($order['order_type'] === 'take_profit' && $order['tp_target'] === 'tp1') {
                $hasTP1 = true;
            }
            if ($order['order_type'] === 'take_profit' && $order['tp_target'] === 'tp2') {
                $hasTP2 = true;
            }
        }
        
        // Verificar se tem TP2 planejado
        $hasTP2Planned = !empty($trade['take_profit_2_price']) && (float)$trade['take_profit_2_price'] > 0;
        
        // Classificar
        if ($hasBuy && !$hasTP1 && !$hasTP2) {
            echo "   ⚠️  STATUS: ÓRFÃO TOTAL (sem nenhum TP)\n";
            $orphans[] = [
                'trade' => $trade,
                'type' => 'total',
                'missing' => 'TP1 e TP2'
            ];
        } elseif ($hasBuy && $hasTP1 && $hasTP2Planned && !$hasTP2) {
            echo "   ⚠️  STATUS: ÓRFÃO PARCIAL (falta TP2)\n";
            $partial[] = [
                'trade' => $trade,
                'type' => 'partial',
                'missing' => 'TP2'
            ];
        } elseif ($hasBuy && $hasTP1 && (!$hasTP2Planned || $hasTP2)) {
            echo "   ✅ STATUS: OK (todos os TPs configurados)\n";
            $ok[] = $trade;
        } else {
            echo "   ❓ STATUS: Indefinido\n";
        }
        
        echo "\n";
    }

    // Relatório final
    echo "════════════════════════════════════════════════════════════════\n";
    echo "\n📋 RELATÓRIO FINAL:\n\n";

    if (!empty($orphans)) {
        echo "🚨 TRADES ÓRFÃOS TOTAIS: " . count($orphans) . "\n";
        echo "───────────────────────────────────────────────────────────\n";
        foreach ($orphans as $orphan) {
            $t = $orphan['trade'];
            $asset = str_replace('USDC', '', $t['symbol']);
            echo "   • Trade #{$t['idx']} - {$t['symbol']}\n";
            echo "     └─ Quantidade: {$t['quantity']} {$asset}\n";
            echo "     └─ Investimento: \${$t['investment']}\n";
            echo "     └─ SEM ORDENS DE TAKE PROFIT!\n";
        }
        echo "\n";
    }

    if (!empty($partial)) {
        echo "⚠️  TRADES ÓRFÃOS PARCIAIS: " . count($partial) . "\n";
        echo "───────────────────────────────────────────────────────────\n";
        foreach ($partial as $p) {
            $t = $p['trade'];
            $asset = str_replace('USDC', '', $t['symbol']);
            echo "   • Trade #{$t['idx']} - {$t['symbol']}\n";
            echo "     └─ Quantidade: {$t['quantity']} {$asset}\n";
            echo "     └─ Tem TP1 ✅ | Falta TP2 ❌\n";
        }
        echo "\n";
    }

    if (!empty($ok)) {
        echo "✅ TRADES OK: " . count($ok) . "\n\n";
    }

    // Verificar saldos na Binance
    if (!empty($orphans) || !empty($partial)) {
        echo "════════════════════════════════════════════════════════════════\n";
        echo "\n💰 SALDOS NA BINANCE:\n\n";
        
        $accountInfo = $api->getAccount();
        $accountData = $accountInfo->getData();
        $balances = is_array($accountData) ? $accountData['balances'] : $accountData->getBalances();
        
        $symbols = array_unique(array_merge(
            array_column(array_column($orphans, 'trade'), 'symbol'),
            array_column(array_column($partial, 'trade'), 'symbol')
        ));
        
        foreach ($symbols as $symbol) {
            $asset = str_replace('USDC', '', $symbol);
            
            foreach ($balances as $balance) {
                $balanceAsset = is_array($balance) ? $balance['asset'] : $balance->getAsset();
                
                if ($balanceAsset === $asset) {
                    $free = is_array($balance) ? (float)$balance['free'] : (float)$balance->getFree();
                    $locked = is_array($balance) ? (float)$balance['locked'] : (float)$balance->getLocked();
                    
                    if ($free > 0 || $locked > 0) {
                        echo "   {$asset}:\n";
                        echo "      Livre: {$free}\n";
                        echo "      Travado: {$locked}\n";
                    }
                    break;
                }
            }
        }
        
        echo "\n════════════════════════════════════════════════════════════════\n";
        echo "\n💡 AÇÕES RECOMENDADAS:\n\n";
        
        if (!empty($orphans)) {
            echo "Para ÓRFÃOS TOTAIS:\n";
            echo "   1. Vender manualmente na Binance\n";
            echo "   2. Ou usar: php create_missing_tps.php --trade-id=X\n\n";
        }
        
        if (!empty($partial)) {
            echo "Para ÓRFÃOS PARCIAIS:\n";
            echo "   1. Aguardar TP1 executar (você ainda tem chance de lucro)\n";
            echo "   2. Ou vender o restante manualmente\n\n";
        }
    }

    echo "\n✅ Análise concluída!\n";

} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
    echo "\nStack Trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
