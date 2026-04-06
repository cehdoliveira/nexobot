<?php

$repoRoot = dirname(base_path());

return [
    'repo_root' => env('LEGACY_REPO_ROOT', $repoRoot),
    'cgi_bin_path' => env('LEGACY_CGI_BIN_PATH', $repoRoot.'/site/cgi-bin'),
    'document_root' => env('LEGACY_DOCUMENT_ROOT', $repoRoot.'/site/public_html'),
    'http_host' => env('LEGACY_HTTP_HOST', 'nexobot.local'),
];
