<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configurar timezone ANTES de qualquer operação com data/hora
date_default_timezone_set("America/Sao_Paulo");

$_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../public_html/";
$_SERVER["HTTP_HOST"] = "driftex.local";
putenv('SERVER_PORT=80');
putenv('SERVER_PROTOCOL=http');

// $_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../html/";
// putenv('SERVER_PORT=443');
// putenv('SERVER_PROTOCOL=https');

putenv('SERVER_NAME=' . $_SERVER["HTTP_HOST"]);
putenv('SCRIPT_NAME=index.php');
set_include_path($_SERVER["DOCUMENT_ROOT"]  . PATH_SEPARATOR . get_include_path());

try {
    require_once($_SERVER["DOCUMENT_ROOT"] . "../app/inc/main.php");
    AppSettings::set('monitoring', 'verify_entry_started_at', date('Y-m-d H:i:s'));
    $order = new setup_controller();
    $order->display();
    AppSettings::set('monitoring', 'verify_entry_success_at', date('Y-m-d H:i:s'));
    BotAlertService::clearSystemAlert('cron_runtime_failure');
    BotAlertService::clearSystemAlert('cron_stale');
} catch (Throwable $e) {
    // Log APENAS de erros na execução da CRON
    $errorLog = "[" . date('Y-m-d H:i:s') . "] verify_entry.php - ERRO NA EXECUÇÃO\n";
    $errorLog .= "Erro: " . $e->getMessage() . "\n";
    $errorLog .= "Arquivo: " . $e->getFile() . " (linha " . $e->getLine() . ")\n";
    $errorLog .= "Stack Trace: " . $e->getTraceAsString() . "\n";
    $errorLog .= "---\n";
    file_put_contents('/var/log/cron.log', $errorLog, FILE_APPEND);

    if (class_exists('AppSettings')) {
        AppSettings::set('monitoring', 'verify_entry_failed_at', date('Y-m-d H:i:s'));
    }

    if (class_exists('BotAlertService')) {
        $subject = 'Driftex: falha na execução da CRON do bot';
        $body = sprintf(
            "<p>A execução do <code>verify_entry.php</code> falhou.</p>
            <p><strong>Data/Hora:</strong> %s<br>
            <strong>Erro:</strong> %s<br>
            <strong>Arquivo:</strong> %s<br>
            <strong>Linha:</strong> %d</p>",
            date('Y-m-d H:i:s'),
            htmlspecialchars($e->getMessage()),
            htmlspecialchars($e->getFile()),
            (int)$e->getLine()
        );
        BotAlertService::sendSystemAlertOnce('cron_runtime_failure', $subject, $body, [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }

    exit(1); // Sinalizar erro para cron
}
