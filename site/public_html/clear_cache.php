<?php
/**
 * Clear PHP OPCache
 * Delete this file after use
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== PHP Cache Clear Tool ===\n\n";

// Clear OPCache
if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "✅ OPCache cleared successfully\n";
    } else {
        echo "❌ Failed to clear OPCache\n";
    }
    
    $status = opcache_get_status();
    echo "OPCache Stats:\n";
    echo "  - Memory Used: " . round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) . " MB\n";
    echo "  - Cached Scripts: " . $status['opcache_statistics']['num_cached_scripts'] . "\n";
} else {
    echo "ℹ️  OPCache is not enabled\n";
}

// Clear APCu cache (if available)
if (function_exists('apcu_clear_cache')) {
    if (apcu_clear_cache()) {
        echo "✅ APCu cache cleared\n";
    }
} else {
    echo "ℹ️  APCu is not enabled\n";
}

// Clear stat cache
clearstatcache(true);
echo "✅ Stat cache cleared\n";

echo "\n🎯 Caches cleared! Reload your dashboard now.\n";
echo "\n⚠️  DELETE THIS FILE AFTER USE FOR SECURITY!\n";
