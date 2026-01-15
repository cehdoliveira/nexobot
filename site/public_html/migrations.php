<?php
/**
 * Migration Interface
 * 
 * Acesso:
 * - Ver status: http://nexo.local/migrations.php
 * - Executar: http://nexo.local/migrations.php?run=1
 */

define('APP_PATH', realpath(__DIR__ . '/../app'));

require_once APP_PATH . '/inc/kernel.php';
require_once APP_PATH . '/inc/lib/local_pdo.php';
require_once APP_PATH . '/inc/lib/MigrationRunner.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = new local_pdo();
    $runner = new MigrationRunner($pdo);
    
    $shouldRun = isset($_GET['run']) && $_GET['run'] === '1';
    
    if ($shouldRun) {
        $results = $runner->run();
        $executed = $results['executed'];
        $skipped = $results['skipped'];
        $failed = $results['failed'];
    } else {
        $executed = [];
        $skipped = [];
        $failed = [];
    }
    
    $status = $runner->status();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migrations</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #666;
            font-size: 14px;
        }
        
        .controls {
            margin: 20px 0;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #0056b3;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }
        
        .stat.success .stat-value {
            color: #28a745;
        }
        
        .stat.warning .stat-value {
            color: #ffc107;
        }
        
        .stat.danger .stat-value {
            color: #dc3545;
        }
        
        .table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            width: 100%;
            border-collapse: collapse;
        }
        
        .table thead {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        
        .table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            color: #666;
        }
        
        .table td {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge.success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge.pending {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert.danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .margin-top {
            margin-top: 20px;
        }
        
        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔄 Migrations</h1>
            <p class="subtitle">Gerenciador de migrações de banco de dados</p>
        </header>
        
        <div class="controls">
            <a href="?run=1" class="btn">Executar Migrations</a>
            <a href="?" class="btn">Atualizar Status</a>
        </div>
        
        <?php if ($shouldRun): ?>
            <div class="stats">
                <div class="stat success">
                    <div class="stat-label">Executadas</div>
                    <div class="stat-value"><?php echo count($executed); ?></div>
                </div>
                <div class="stat warning">
                    <div class="stat-label">Ignoradas</div>
                    <div class="stat-value"><?php echo count($skipped); ?></div>
                </div>
                <div class="stat danger">
                    <div class="stat-label">Falhas</div>
                    <div class="stat-value"><?php echo count($failed); ?></div>
                </div>
            </div>
            
            <?php if (!empty($executed)): ?>
                <div class="alert success">
                    <strong>✅ Migrations executadas:</strong> <?php echo implode(', ', $executed); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($failed)): ?>
                <div class="alert danger">
                    <strong>❌ Migrations falharam:</strong> <?php echo implode(', ', $failed); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="margin-top">
            <h3>📋 Status de Migrations</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Status</th>
                        <th>Arquivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($status as $name => $info): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($name); ?></td>
                            <td>
                                <?php if ($info['executed']): ?>
                                    <span class="badge success">✓ Executada</span>
                                <?php else: ?>
                                    <span class="badge pending">Pendente</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo htmlspecialchars($info['file']); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

<?php
} catch (Exception $e) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Erro</title>
        <style>
            body { font-family: sans-serif; margin: 40px; }
            .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; }
        </style>
    </head>
    <body>
        <div class="error">
            <h2>❌ Erro</h2>
            <p><?php echo htmlspecialchars($e->getMessage()); ?></p>
        </div>
    </body>
    </html>
    <?php
}
