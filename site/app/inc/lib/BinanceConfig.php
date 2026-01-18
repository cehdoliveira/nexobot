<?php

class BinanceConfig
{
    private const SETTINGS_FILE = 'config/binance_settings.json';
    private const MODE_DEV = 'dev';
    private const MODE_PROD = 'prod';

    private const DEV_BASE_URL = 'https://testnet.binance.vision';
    private const PROD_BASE_URL = 'https://api.binance.com';

    public static function load(): array
    {
        $defaults = [
            'mode' => self::MODE_DEV,
            'dev_api_key' => '',
            'dev_api_secret' => '',
            'prod_api_key' => '',
            'prod_api_secret' => ''
        ];

        // Preferir DB (tabela settings)
        try {
            $mode = AppSettings::get('binance', 'mode', $defaults['mode']);
            $devKey = AppSettings::get('binance', 'dev_api_key', $defaults['dev_api_key']);
            $devSecret = AppSettings::get('binance', 'dev_api_secret', $defaults['dev_api_secret']);
            $prodKey = AppSettings::get('binance', 'prod_api_key', $defaults['prod_api_key']);
            $prodSecret = AppSettings::get('binance', 'prod_api_secret', $defaults['prod_api_secret']);

            $dbData = [
                'mode' => $mode,
                'dev_api_key' => $devKey,
                'dev_api_secret' => $devSecret,
                'prod_api_key' => $prodKey,
                'prod_api_secret' => $prodSecret,
            ];

            // Se banco tem dados, usa eles
            if ($mode !== $defaults['mode'] || $devKey !== '' || $devSecret !== '' || $prodKey !== '' || $prodSecret !== '') {
                return array_merge($defaults, $dbData);
            }
        } catch (Exception $e) {
            error_log('BinanceConfig::load DB error: ' . $e->getMessage());
        }

        // Fallback para arquivo JSON
        $settingsPath = self::getSettingsPath();
        if (file_exists($settingsPath)) {
            $data = json_decode((string) file_get_contents($settingsPath), true);
            if (is_array($data)) {
                return array_merge($defaults, $data);
            }
        }

        return $defaults;
    }

    public static function save(string $mode, string $devKey, string $devSecret, string $prodKey, string $prodSecret): bool
    {
        $mode = in_array($mode, [self::MODE_DEV, self::MODE_PROD], true) ? $mode : self::MODE_DEV;

        $current = self::load();
        $settings = [
            'mode' => $mode,
            'dev_api_key' => $devKey !== '' ? $devKey : ($current['dev_api_key'] ?? ''),
            'dev_api_secret' => $devSecret !== '' ? $devSecret : ($current['dev_api_secret'] ?? ''),
            'prod_api_key' => $prodKey !== '' ? $prodKey : ($current['prod_api_key'] ?? ''),
            'prod_api_secret' => $prodSecret !== '' ? $prodSecret : ($current['prod_api_secret'] ?? '')
        ];

        // Primeiro: salvar no DB
        $ok = true;
        try {
            $ok = AppSettings::set('binance', 'mode', $settings['mode']) &&
                  AppSettings::set('binance', 'dev_api_key', $settings['dev_api_key']) &&
                  AppSettings::set('binance', 'dev_api_secret', $settings['dev_api_secret']) &&
                  AppSettings::set('binance', 'prod_api_key', $settings['prod_api_key']) &&
                  AppSettings::set('binance', 'prod_api_secret', $settings['prod_api_secret']);
        } catch (Exception $e) {
            error_log('BinanceConfig::save DB error: ' . $e->getMessage());
            $ok = false;
        }
        if (!$ok) {
            error_log('BinanceConfig::save falling back to file');
        }

        // Fallback para arquivo (se DB indisponível)
        $settingsPath = self::getWritableSettingsPath();
        $dir = dirname($settingsPath);

        if (!is_dir($dir)) {
            $mkdirResult = @mkdir($dir, 0755, true);
            if (!$mkdirResult && !is_dir($dir)) {
                error_log("BinanceConfig: Failed to create directory: {$dir}");
                return false;
            }
        }

        if (!is_writable($dir)) {
            error_log("BinanceConfig: Directory not writable: {$dir}");
            return false;
        }

        $payload = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            error_log("BinanceConfig: JSON encode failed");
            return false;
        }

        $result = file_put_contents($settingsPath, $payload);
        if ($result === false) {
            error_log("BinanceConfig: Failed to write settings file: {$settingsPath}");
            return false;
        }

        @chmod($settingsPath, 0644);
        return $ok;
    }

    public static function getActiveCredentials(): array
    {
        $settings = self::load();
        $mode = in_array($settings['mode'], [self::MODE_DEV, self::MODE_PROD], true) ? $settings['mode'] : self::MODE_DEV;

        if ($mode === self::MODE_PROD) {
            $prodKey = trim($settings['prod_api_key'] ?? '');
            $prodSecret = trim($settings['prod_api_secret'] ?? '');
            
            if ($prodKey === '' || $prodSecret === '') {
                $mode = self::MODE_DEV;
            }
        }

        if ($mode === self::MODE_PROD) {
            $apiKey = $settings['prod_api_key'] ?? '';
            $secretKey = $settings['prod_api_secret'] ?? '';
            $baseUrl = self::PROD_BASE_URL;
        } else {
            $devKey = trim($settings['dev_api_key'] ?? '');
            $devSecret = trim($settings['dev_api_secret'] ?? '');
            
            if ($devKey === '' || $devSecret === '') {
                $apiKey = binanceAPIKey;
                $secretKey = binanceSecretKey;
            } else {
                $apiKey = $devKey;
                $secretKey = $devSecret;
            }
            $baseUrl = self::DEV_BASE_URL;
        }

        return [
            'mode' => $mode,
            'apiKey' => $apiKey,
            'secretKey' => $secretKey,
            'baseUrl' => $baseUrl,
            'restBaseUrl' => $baseUrl
        ];
    }

    private static function getSettingsPath(): string
    {
        // Preferível: APP/inc/config
        $appRoot = defined('cRootServer_APP') ? rtrim(constant('cRootServer_APP'), '/') : __DIR__;
        $primary = $appRoot . '/' . self::SETTINGS_FILE;

        // Alternativo: diretório de upload (normalmente gravável pelo servidor web)
        $uploadDir = defined('UPLOAD_DIR') ? rtrim(constant('UPLOAD_DIR'), '/') : null;
        $fallback = $uploadDir ? ($uploadDir . '/binance_settings.json') : null;

        // Se o primário não existe, mas o fallback existe, use fallback
        if (!file_exists($primary) && $fallback && file_exists($fallback)) {
            return $fallback;
        }

        return $primary;
    }

    private static function getWritableSettingsPath(): string
    {
        $paths = [];
        $appRoot = defined('cRootServer_APP') ? rtrim(constant('cRootServer_APP'), '/') : __DIR__;
        $paths[] = $appRoot . '/' . self::SETTINGS_FILE;

        $uploadDir = defined('UPLOAD_DIR') ? rtrim(constant('UPLOAD_DIR'), '/') : null;
        if ($uploadDir) {
            $paths[] = $uploadDir . '/binance_settings.json';
        }

        foreach ($paths as $path) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return $path;
            }
        }

        // Último recurso: usar APP mesmo que não seja gravável (vai falhar com log)
        return $paths[0];
    }
}
