#!/usr/bin/env php
<?php

// Script CLI para rodar migrations
// Uso: php run-migrations.php

define('APP_PATH', realpath(__DIR__ . '/site/app'));

// Carregar kernel
require_once APP_PATH . '/inc/kernel.php';
require_once APP_PATH . '/inc/lib/local_pdo.php';
require_once APP_PATH . '/inc/lib/MigrationRunner.php';

try {
    // Conectar ao banco
    $pdo = local_pdo::getInstance();
    
    // Executar migrations
    $runner = new MigrationRunner($pdo);
    
    echo "\n========================================\n";
    echo "🚀 Executando Migrations\n";
    echo "========================================\n\n";
    
    $results = $runner->run();
    
    echo "\n========================================\n";
    echo "📊 Resumo:\n";
    echo "  ✅ Executadas: " . count($results['executed']) . "\n";
    echo "  ⏭️  Ignoradas: " . count($results['skipped']) . "\n";
    echo "  ❌ Falhas: " . count($results['failed']) . "\n";
    echo "========================================\n\n";
    
    exit(count($results['failed']) > 0 ? 1 : 0);
    
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
