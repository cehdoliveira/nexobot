<?php
/**
 * SCRIPT DE DIAGNÓSTICO: Verificar distribuição de capital
 * Execute: php check_capital.php
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

use Binance\Spot\SpotRestApi;
use Binance\Spot\SpotRestApiUtil;

echo "═══════════════════════════════════════════════\n";
echo "  DIAGNÓSTICO DE CAPITAL - NEXOBOT\n";
echo "═══════════════════════════════════════════════\n\n";

try {
    // 1. BUSCAR CREDENCIAIS BINANCE
    $binanceConfig = BinanceConfig::getActiveCredentials();
    $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
    $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
    $configurationBuilder->url($binanceConfig['baseUrl']);
    $api = new SpotRestApi($configurationBuilder->build());

    // 2. BUSCAR SALDOS DA CONTA
    echo "📊 SALDOS NA BINANCE:\n";
    echo "─────────────────────────────────────────────\n";
    
    $accountInfo = $api->getAccount();
    $accountData = $accountInfo->getData();
    
    $usdcFree = 0;
    $usdcLocked = 0;
    $btcFree = 0;
    $btcLocked = 0;
    
    foreach ($accountData['balances'] as $balance) {
        if ($balance['asset'] === 'USDC') {
            $usdcFree = (float)$balance['free'];
            $usdcLocked = (float)$balance['locked'];
        }
        if ($balance['asset'] === 'BTC') {
            $btcFree = (float)$balance['free'];
            $btcLocked = (float)$balance['locked'];
        }
    }
    
    // Buscar preço BTC
    $priceResp = $api->tickerPrice('BTCUSDC');
    $priceData = $priceResp->getData();
    $btcPrice = 0;
    
    if ($priceData && method_exists($priceData, 'getTickerPriceResponse1')) {
        $btcPrice = (float)$priceData->getTickerPriceResponse1()->getPrice();
    } elseif ($priceData && method_exists($priceData, 'getPrice')) {
        $btcPrice = (float)$priceData->getPrice();
    }
    
    $btcValueFree = $btcFree * $btcPrice;
    $btcValueLocked = $btcLocked * $btcPrice;
    $totalCapital = $usdcFree + $usdcLocked + $btcValueFree + $btcValueLocked;
    
    echo "USDC Livre:      $" . number_format($usdcFree, 2) . "\n";
    echo "USDC Bloqueado:  $" . number_format($usdcLocked, 2) . " (ordens abertas)\n";
    echo "USDC TOTAL:      $" . number_format($usdcFree + $usdcLocked, 2) . "\n\n";
    
    echo "BTC Livre:       " . number_format($btcFree, 5) . " BTC ($" . number_format($btcValueFree, 2) . ")\n";
    echo "BTC Bloqueado:   " . number_format($btcLocked, 5) . " BTC ($" . number_format($btcValueLocked, 2) . ")\n";
    echo "BTC TOTAL:       " . number_format($btcFree + $btcLocked, 5) . " BTC ($" . number_format($btcValueFree + $btcValueLocked, 2) . ")\n\n";
    
    echo "💰 CAPITAL TOTAL: $" . number_format($totalCapital, 2) . "\n";
    echo "📈 Preço BTC:     $" . number_format($btcPrice, 2) . "\n\n";
    
    // 3. BUSCAR ORDENS ABERTAS
    echo "📋 ORDENS ABERTAS:\n";
    echo "─────────────────────────────────────────────\n";
    
    $openOrders = $api->getOpenOrders('BTCUSDC');
    $openOrdersData = $openOrders->getData();
    
    $orders = [];
    if (is_array($openOrdersData)) {
        $orders = $openOrdersData;
    } elseif (is_object($openOrdersData) && method_exists($openOrdersData, 'getItems')) {
        $orders = $openOrdersData->getItems();
    }
    
    $totalBuyOrders = 0;
    $totalSellOrders = 0;
    $totalUsdcInBuyOrders = 0;
    $totalBtcInSellOrders = 0;
    
    foreach ($orders as $order) {
        if (!is_array($order)) {
            $order = json_decode(json_encode($order), true);
        }
        
        $side = $order['side'] ?? '';
        $price = (float)($order['price'] ?? 0);
        $origQty = (float)($order['origQty'] ?? 0);
        $orderId = $order['orderId'] ?? '';
        
        if ($side === 'BUY') {
            $totalBuyOrders++;
            $totalUsdcInBuyOrders += ($price * $origQty);
            echo "  🟢 BUY  #$orderId: $" . number_format($price, 2) . " × " . number_format($origQty, 5) . " BTC = $" . number_format($price * $origQty, 2) . "\n";
        } else {
            $totalSellOrders++;
            $totalBtcInSellOrders += $origQty;
            echo "  🔴 SELL #$orderId: $" . number_format($price, 2) . " × " . number_format($origQty, 5) . " BTC = $" . number_format($price * $origQty, 2) . "\n";
        }
    }
    
    echo "\nResumo ordens:\n";
    echo "  BUY orders:  $totalBuyOrders (USDC bloqueado: $" . number_format($totalUsdcInBuyOrders, 2) . ")\n";
    echo "  SELL orders: $totalSellOrders (BTC bloqueado: " . number_format($totalBtcInSellOrders, 5) . " BTC)\n\n";
    
    // 4. BUSCAR GRID NO BANCO
    echo "🎯 GRID ATIVO:\n";
    echo "─────────────────────────────────────────────\n";
    
    $gridsModel = new grids_model();
    $gridsModel->set_filter(["active = 'yes'", "symbol = 'BTCUSDC'"]);
    $gridsModel->load_data();
    
    if (!empty($gridsModel->data)) {
        $grid = $gridsModel->data[0];
        echo "Grid ID:              #{$grid['idx']}\n";
        echo "Status:               {$grid['status']}\n";
        echo "Capital por nível:    $" . number_format($grid['capital_per_level'], 2) . "\n";
        echo "Capital inicial:      $" . number_format($grid['initial_capital_usdc'], 2) . "\n";
        echo "Lucro acumulado:      $" . number_format($grid['accumulated_profit_usdc'] ?? 0, 2) . "\n";
        echo "Stop-Loss acionado:   " . ($grid['stop_loss_triggered'] ?? 'no') . "\n";
        echo "Em processamento:     " . ($grid['is_processing'] ?? 'no') . "\n";
        
        if ($grid['is_processing'] === 'yes') {
            $lastMonitor = $grid['last_monitor_at'] ?? 'nunca';
            echo "Último monitor:       $lastMonitor\n";
            
            if ($lastMonitor !== 'nunca') {
                $elapsed = time() - strtotime($lastMonitor);
                echo "⚠️  LOCK TRAVADO! Último monitor há " . round($elapsed / 60, 1) . " minutos\n";
            }
        }
    } else {
        echo "❌ Nenhum grid ativo encontrado.\n";
    }
    
    echo "\n";
    
    // 5. ANÁLISE E RECOMENDAÇÕES
    echo "💡 ANÁLISE:\n";
    echo "═══════════════════════════════════════════════\n";
    
    $minCapitalNeeded = 120; // 3 BUYs × $40
    
    if ($totalCapital < $minCapitalNeeded) {
        echo "❌ CAPITAL INSUFICIENTE!\n";
        echo "   Capital disponível: $" . number_format($totalCapital, 2) . "\n";
        echo "   Capital ideal:      $" . number_format($minCapitalNeeded, 2) . "\n";
        echo "   Falta:              $" . number_format($minCapitalNeeded - $totalCapital, 2) . "\n\n";
        echo "   📌 AÇÃO: Deposite mais USDC na Binance OU reduza o capital_per_level no grid.\n";
    } else {
        echo "✅ Capital total OK ($" . number_format($totalCapital, 2) . ")\n\n";
        
        if ($usdcFree < 40 && $btcFree > 0.001) {
            echo "⚠️  PROBLEMA: Muito BTC livre ($" . number_format($btcValueFree, 2) . "), pouco USDC livre ($" . number_format($usdcFree, 2) . ")\n";
            echo "   📌 AÇÃO: Venda parte do BTC para rebalancear ou use 'Encerrar Posições' e recrie o grid.\n";
        }
    }
    
    if ($totalBuyOrders < 3) {
        echo "\n⚠️  Grid desbalanceado: apenas $totalBuyOrders BUYs (ideal: 3-5)\n";
        echo "   Causa provável: USDC livre insuficiente para recriar ordens BUY após vendas.\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
}

echo "\n═══════════════════════════════════════════════\n";
