<?php

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        static $loaded = false;
        static $values = [];

        if (!$loaded) {
            $loaded = true;

            $paths = [
                dirname(dirname(dirname(__DIR__))) . '/.env',
                __DIR__ . '/../.env',
            ];

            foreach ($paths as $path) {
                if (!is_file($path) || !is_readable($path)) {
                    continue;
                }

                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                if ($lines === false) {
                    continue;
                }

                foreach ($lines as $line) {
                    $line = trim($line);

                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }

                    [$name, $value] = explode('=', $line, 2);
                    $name = trim($name);
                    $value = trim($value);

                    if ($name === '') {
                        continue;
                    }

                    $value = trim($value, "\"'");

                    $values[$name] = match (strtolower($value)) {
                        'true', '(true)' => true,
                        'false', '(false)' => false,
                        'null', '(null)' => null,
                        'empty', '(empty)' => '',
                        default => $value,
                    };

                    $_ENV[$name] = $values[$name];
                    $_SERVER[$name] = $values[$name];
                    putenv($name . '=' . (is_bool($values[$name]) ? ($values[$name] ? 'true' : 'false') : (string) ($values[$name] ?? '')));
                }
            }
        }

        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        $envValue = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($envValue === false || $envValue === null || $envValue === '') {
            return $default;
        }

        return $envValue;
    }
}
