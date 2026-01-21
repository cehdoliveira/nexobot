#!/usr/bin/env php
<?php
/**
 * Script para Criar TPs Faltantes em Trades Órfãos
 * 
 * Cria automaticamente as ordens de Take Profit que falharam
 * devido a erros de saldo insuficiente.
 * 
 * Uso: php create_missing_tps.php [--trade-id=ID] [--all] [--dry-run]
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
use Binance\Client\Spot\Model\NewOrderRequest;
use Binance\Client\Spot\Model\Side;
use Binance\Client\Spot\Model\OrderType;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║     CRIADOR DE TPs FALTANTES v1.0                              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Parse arguments
$options = getopt("", ["trade-id::", "all", "dry-run"]);
$tradeId = $options['trade-id'] ?? null;
$processAll = isset($options['all']);
$dryRun = isset($options['dry-run']);

if ($dryRun) {
    echo "⚠️  MODO DRY-RUN ATIVADO - Nenhuma ordem será criada\n\n";
}

if (!$tradeId && !$processAll) {
    echo "❌ ERRO: Especifique --trade-id=X ou --all\n";
    echo "\nUso:\n";
    echo "  php create_missing_tps.php --trade-id=12\n";
    echo "  php create_missing_tps.php --all\n";
    echo "  php create_missing_tps.php --all --dry-run\n";
    exit(1);
}

try {
    // Conectar à Binance
    $binanceConfig = BinanceConfig::getActiveCredentials();
    $configurationBuilder = SpotRestApiUtil::getConfigurationBuilder();
    $configurationBuilder->apiKey($binanceConfig['apiKey'])->secretKey($binanceConfig['secretKey']);
    $configurationBuilder->url($binanceConfig['baseUrl']);
    $api = new SpotRestApi($configurationBuilder->build());
    
    echo "✅ Conexão com Binance estabelecida\n\n";

    // Buscar trades
    $con = new local_pdo();
    $filter = "WHERE active = 'yes' AND status = 'open'";
    if ($tradeId) {
        $filter .= " AND idx = " . (int)$tradeId;
    }
    
    $result = $con->select("*", "trades", $filter . " ORDER BY opened_at DESC");
    $trades = $con->results($result);

    // Saldos atuais (free + locked) para usar a quantidade real disponível
    $accountResp = $api->getAccount();
    $accountData = $accountResp->getData();
    $balances = is_array($accountData) ? ($accountData['balances'] ?? []) : $accountData->getBalances();
    $wallet = [];
    foreach ($balances as $b) {
        $asset = is_array($b) ? $b['asset'] : $b->getAsset();
        $free = is_array($b) ? (float)$b['free'] : (float)$b->getFree();
        $locked = is_array($b) ? (float)$b['locked'] : (float)$b->getLocked();
        $wallet[$asset] = ['free' => $free, 'locked' => $locked, 'total' => $free + $locked];
    }

    if (empty($trades)) {
        echo "❌ Nenhum trade encontrado.\n";
        exit(0);
    }

    echo "📊 Processando " . count($trades) . " trade(s)...\n";
    echo "════════════════════════════════════════════════════════════════\n\n";

    $created = 0;
    $skipped = 0;
    $errors = 0;

    foreach ($trades as $trade) {
        $tradeIdx = $trade['idx'];
        $symbol = $trade['symbol'];
        $quantity = (float)$trade['quantity'];
        $asset = str_replace('USDC', '', $symbol);
        $availableQty = $wallet[$asset]['total'] ?? 0;
        $baseQty = min($quantity, $availableQty);
        
        echo "📈 Trade #{$tradeIdx} - {$symbol}\n";

        if ($baseQty <= 0) {
            echo "   ⚠️  Sem saldo disponível na Binance para este ativo. Pulando...\n\n";
            $skipped++;
            continue;
        }
        if ($availableQty < $quantity) {
            echo "   ⚠️  Ajustando quantidade por saldo real. DB: {$quantity} | Binance: {$availableQty}\n";
        }
        
        // Buscar ordens existentes
        $ordersResult = $con->select(
            "*", 
            "orders", 
            "WHERE active = 'yes' AND idx IN (SELECT orders_id FROM orders_trades WHERE active = 'yes' AND trades_id = '{$tradeIdx}')"
        );
        $orders = $con->results($ordersResult);
        
        $hasTP1 = false;
        $hasTP2 = false;
        
        foreach ($orders as $order) {
            if ($order['order_type'] === 'take_profit' && $order['tp_target'] === 'tp1') {
                $hasTP1 = true;
            }
            if ($order['order_type'] === 'take_profit' && $order['tp_target'] === 'tp2') {
                $hasTP2 = true;
            }
        }
        
        $hasTP2Planned = !empty($trade['take_profit_2_price']) && (float)$trade['take_profit_2_price'] > 0;
        
        // Verificar o que está faltando
        $needsTP1 = !$hasTP1;
        $needsTP2 = $hasTP2Planned && !$hasTP2;
        
        if (!$needsTP1 && !$needsTP2) {
            echo "   ✅ Todos os TPs já criados. Pulando...\n\n";
            $skipped++;
            continue;
        }
        
        echo "   Falta criar: ";
        if ($needsTP1) echo "TP1 ";
        if ($needsTP2) echo "TP2 ";
        echo "\n";
        
        try {
            // Obter preço atual
            $response = $api->tickerPrice($symbol);
            $data = $response->getData();
            
            $currentPrice = null;
            if ($data && method_exists($data, 'getTickerPriceResponse1')) {
                $priceData = $data->getTickerPriceResponse1();
                if ($priceData && method_exists($priceData, 'getPrice')) {
                    $currentPrice = (float)$priceData->getPrice();
                }
            }
            
            if (!$currentPrice && $data && method_exists($data, 'getPrice')) {
                $currentPrice = (float)$data->getPrice();
            }
            
            if (!$currentPrice) {
                echo "   ⚠️  Não foi possível obter preço atual. Pulando...\n\n";
                $skipped++;
                continue;
            }
            
            echo "   Preço atual: \${$currentPrice}\n";
            
            // Validar se ainda faz sentido criar os TPs
            if ($needsTP1) {
                $tp1Price = (float)$trade['take_profit_1_price'];
                
                if ($currentPrice >= $tp1Price) {
                    echo "   ⚠️  Preço atual (${currentPrice}) já passou do TP1 (${tp1Price}). Vendendo no mercado...\n";
                    
                    if (!$dryRun) {
                        // Vender tudo no mercado
                        $sellReq = new NewOrderRequest();
                        $sellReq->setSymbol($symbol);
                        $sellReq->setSide(Side::SELL);
                        $sellReq->setType(OrderType::MARKET);
                        $sellReq->setQuantity($baseQty);
                        
                        $sellResp = $api->newOrder($sellReq);
                        $sellOrder = $sellResp->getData();
                        
                        $executedQty = is_array($sellOrder) ? (float)$sellOrder['executedQty'] : (float)$sellOrder->getExecutedQty();
                        $cummulativeQuoteQty = is_array($sellOrder) ? (float)$sellOrder['cummulativeQuoteQty'] : (float)$sellOrder->getCummulativeQuoteQty();
                        
                        // Calcular P/L
                        $investment = (float)$trade['investment'];
                        $profitLoss = $cummulativeQuoteQty - $investment;
                        $profitLossPercent = $investment > 0 ? (($profitLoss / $investment) * 100) : 0;
                        
                        // Fechar trade
                        $updateFields = [
                            "status = 'closed'",
                            "exit_price = '" . $con->real_escape_string((string)$currentPrice) . "'",
                            "exit_type = 'market'",
                            "profit_loss = '" . $con->real_escape_string((string)$profitLoss) . "'",
                            "profit_loss_percent = '" . $con->real_escape_string((string)$profitLossPercent) . "'",
                            "closed_at = NOW()"
                        ];
                        
                        $con->update(
                            implode(", ", $updateFields),
                            "trades",
                            "WHERE active = 'yes' AND idx = '{$tradeIdx}'"
                        );
                        
                        echo "   ✅ Vendido no mercado e trade fechado. P/L: \${$profitLoss} ({$profitLossPercent}%)\n";
                        $created++;
                    } else {
                        echo "   (DRY-RUN: venderia no mercado)\n";
                    }
                    
                    echo "\n";
                    continue;
                }
                
                // Criar TP1
                $tp1Qty = (float)$trade['tp1_executed_qty'] ?: ($baseQty * 0.4); // 40% se não definido
                $tp1Qty = min($tp1Qty, $baseQty);
                
                echo "   📝 Criando TP1...\n";
                echo "      Preço: \${$tp1Price}\n";
                echo "      Quantidade: {$tp1Qty}\n";
                
                if (!$dryRun) {
                    $tp1Req = new NewOrderRequest();
                    $tp1Req->setSymbol($symbol);
                    $tp1Req->setSide(Side::SELL);
                    $tp1Req->setType(OrderType::TAKE_PROFIT);
                    $tp1Req->setQuantity($tp1Qty);
                    $tp1Req->setStopPrice($tp1Price);
                    
                    $tp1Resp = $api->newOrder($tp1Req);
                    $tp1Order = $tp1Resp->getData();
                    
                    $tp1OrderId = is_array($tp1Order) ? $tp1Order['orderId'] : $tp1Order->getOrderId();
                    $tp1ClientOrderId = is_array($tp1Order) ? $tp1Order['clientOrderId'] : $tp1Order->getClientOrderId();
                    
                    // Salvar no banco
                    $ordersModel = new orders_model();
                    $ordersModel->populate([
                        'binance_order_id' => $tp1OrderId,
                        'binance_client_order_id' => $tp1ClientOrderId,
                        'symbol' => $symbol,
                        'side' => 'SELL',
                        'type' => 'TAKE_PROFIT',
                        'order_type' => 'take_profit',
                        'tp_target' => 'tp1',
                        'stop_price' => $tp1Price,
                        'price' => $tp1Price,
                        'quantity' => $tp1Qty,
                        'status' => 'NEW',
                        'order_created_at' => round(microtime(true) * 1000),
                        'api_response' => json_encode(is_array($tp1Order) ? $tp1Order : json_decode(json_encode($tp1Order), true))
                    ]);
                    $tp1OrderIdx = $ordersModel->save();
                    $ordersModel->save_attach(['idx' => $tp1OrderIdx, 'post' => ['trades_id' => $tradeIdx]], ['trades']);
                    
                    echo "   ✅ TP1 criado com sucesso! Ordem #{$tp1OrderId}\n";
                    $created++;
                }
            }
            
            if ($needsTP2) {
                $tp2Price = (float)$trade['take_profit_2_price'];
                
                if ($currentPrice >= $tp2Price) {
                    echo "   ⚠️  Preço atual já passou do TP2. Não criando ordem.\n";
                    echo "\n";
                    continue;
                }
                
                // Criar TP2
                $tp2Qty = (float)$trade['tp2_executed_qty'];
                if (!$tp2Qty) {
                    $tp2Qty = $baseQty - ($tp1Qty ?? 0);
                    if ($tp2Qty <= 0) {
                        $tp2Qty = $baseQty * 0.6; // fallback se não restar nada calculado
                    }
                }
                $tp2Qty = min($tp2Qty, $baseQty);
                if ($tp2Qty <= 0) {
                    echo "   ⚠️  Quantidade insuficiente para criar TP2. Pulando...\n\n";
                    $skipped++;
                    continue;
                }
                
                echo "   📝 Criando TP2...\n";
                echo "      Preço: \${$tp2Price}\n";
                echo "      Quantidade: {$tp2Qty}\n";
                
                if (!$dryRun) {
                    $tp2Req = new NewOrderRequest();
                    $tp2Req->setSymbol($symbol);
                    $tp2Req->setSide(Side::SELL);
                    $tp2Req->setType(OrderType::TAKE_PROFIT);
                    $tp2Req->setQuantity($tp2Qty);
                    $tp2Req->setStopPrice($tp2Price);
                    
                    $tp2Resp = $api->newOrder($tp2Req);
                    $tp2Order = $tp2Resp->getData();
                    
                    $tp2OrderId = is_array($tp2Order) ? $tp2Order['orderId'] : $tp2Order->getOrderId();
                    $tp2ClientOrderId = is_array($tp2Order) ? $tp2Order['clientOrderId'] : $tp2Order->getClientOrderId();
                    
                    // Salvar no banco
                    $ordersModel = new orders_model();
                    $ordersModel->populate([
                        'binance_order_id' => $tp2OrderId,
                        'binance_client_order_id' => $tp2ClientOrderId,
                        'symbol' => $symbol,
                        'side' => 'SELL',
                        'type' => 'TAKE_PROFIT',
                        'order_type' => 'take_profit',
                        'tp_target' => 'tp2',
                        'stop_price' => $tp2Price,
                        'price' => $tp2Price,
                        'quantity' => $tp2Qty,
                        'status' => 'NEW',
                        'order_created_at' => round(microtime(true) * 1000),
                        'api_response' => json_encode(is_array($tp2Order) ? $tp2Order : json_decode(json_encode($tp2Order), true))
                    ]);
                    $tp2OrderIdx = $ordersModel->save();
                    $ordersModel->save_attach(['idx' => $tp2OrderIdx, 'post' => ['trades_id' => $tradeIdx]], ['trades']);
                    
                    echo "   ✅ TP2 criado com sucesso! Ordem #{$tp2OrderId}\n";
                    $created++;
                }
            }
            
            echo "\n";
            
        } catch (Exception $e) {
            echo "   ❌ Erro: " . $e->getMessage() . "\n\n";
            $errors++;
        }
    }

    // Limpar cache
    if (!$dryRun && $created > 0) {
        if (class_exists('RedisCache')) {
            $cache = RedisCache::getInstance();
            if ($cache && $cache->isConnected()) {
                $cache->deletePattern('query:*');
            }
        }
    }

    echo "════════════════════════════════════════════════════════════════\n";
    echo "\n📋 RESUMO:\n";
    echo "   Ordens criadas: {$created}\n";
    echo "   Trades pulados: {$skipped}\n";
    echo "   Erros: {$errors}\n";
    
    if ($dryRun) {
        echo "\n⚠️  MODO DRY-RUN - Nenhuma ordem foi realmente criada\n";
        echo "   Execute sem --dry-run para criar as ordens de verdade\n";
    }
    
    echo "\n✅ Processamento concluído!\n";

} catch (Exception $e) {
    echo "❌ ERRO FATAL: " . $e->getMessage() . "\n";
    echo "\nStack Trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}