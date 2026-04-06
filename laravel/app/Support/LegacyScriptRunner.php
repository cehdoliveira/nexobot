<?php

namespace App\Support;

use Symfony\Component\Process\Process;

class LegacyScriptRunner
{
    public function run(string $script, int $timeout = 300, ?callable $output = null): Process
    {
        $scriptPath = $this->resolveScriptPath($script);

        $process = new Process([PHP_BINARY, $scriptPath], config('legacy.repo_root'));
        $process->setTimeout($timeout);
        $process->setEnv([
            'APP_ENV' => app()->environment(),
            'LEGACY_HTTP_HOST' => config('legacy.http_host'),
            'LEGACY_DOCUMENT_ROOT' => config('legacy.document_root'),
        ]);

        $process->run($output);

        return $process;
    }

    private function resolveScriptPath(string $script): string
    {
        $script = str_ends_with($script, '.php') ? $script : $script.'.php';
        $path = rtrim(config('legacy.cgi_bin_path'), '/').'/'.$script;

        if (! is_file($path)) {
            throw new \RuntimeException("Legacy script not found: {$path}");
        }

        return $path;
    }
}
