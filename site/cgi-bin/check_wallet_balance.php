#!/usr/bin/env php
<?php
/**
 * Script para Verificar Saldos Reais na Carteira
 */

ini_set('display_errors', 1);
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
echo "║     VERIFICAÇÃO DE SALDOS NA CARTEIRA                          ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

try {
    // Conectar à Binance
    $binanceConfig = BinanceConfig::getActiveCredentials();
    $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
    $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
    $configurationBuilder->url($binanceConfig['baseUrl']);
    $api = new SpotRestApi($configurationBuilder->build());
    
    // Buscar informações da conta
    $accountResp = $api->account();
    $accountData = $accountResp->getData();
    
    $balances = is_array($accountData) ? $accountData['balances'] : $accountData->getBalances();
    
    // Buscar trades abertos do banco
    $con = new local_pdo();
    $result = $con->select("idx, symbol, quantity, status", "trades", "WHERE active = 'yes' AND status = 'open' ORDER BY opened_at DESC");
    $trades = $con->results($result);
    
    echo "📊 TRADES ABERTOS NO BANCO:\n";
    echo "════════════════════════════════════════════════════════════════\n";
    
    $symbols = [];
    foreach ($trades as $trade) {
        $symbol = $trade['symbol'];
        $asset = str_replace('USDC', '', $symbol);
        $dbQty = (float)$trade['quantity'];
        $symbols[$asset] = ['trade_id' => $trade['idx'], 'symbol' => $symbol, 'db_qty' => $dbQty];
        
        echo sprintf("Trade #%-3s %-10s Qtd DB: %s\n", $trade['idx'], $symbol, $dbQty);
    }
    
    echo "\n📦 SALDOS NA BINANCE:\n";
    echo "════════════════════════════════════════════════════════════════\n";
    
    foreach ($balances as $balance) {
        $asset = is_array($balance) ? $balance['asset'] : $balance->getAsset();
        $free = is_array($balance) ? (float)$balance['free'] : (float)$balance->getFree();
        $locked = is_array($balance) ? (float)$balance['locked'] : (float)$balance->getLocked();
        $total = $free + $locked;
        
        if ($total > 0.00001 && isset($symbols[$asset])) {
            $info = $symbols[$asset];
            $diff = $total - $info['db_qty'];
            $status = abs($diff) < 0.00001 ? "✅" : ($diff > 0 ? "⚠️  MAIS" : "❌ MENOS");
            
            echo sprintf(
                "%s %-6s | Livre: %-12s | Locked: %-12s | Total: %-12s | DB: %-12s | Diff: %+.8f\n",
                $status,
                $asset,
                $free,
                $locked,
                $total,
                $info['db_qty'],
                $diff
            );
            
            unset($symbols[$asset]);
        }
    }
    
    if (!empty($symbols)) {
        echo "\n❌ TRADES NO BANCO SEM SALDO NA BINANCE:\n";
        echo "════════════════════════════════════════════════════════════════\n";
        foreach ($symbols as $asset => $info) {
            echo sprintf("Trade #%-3s %-10s - Qtd DB: %s (NÃO ENCONTRADO NA CARTEIRA!)\n", 
                $info['trade_id'], $info['symbol'], $info['db_qty']);
        }
    }
    
    echo "\n✅ Verificação concluída!\n";

} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
