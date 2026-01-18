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

        $settingsPath = self::getSettingsPath();
        if (!file_exists($settingsPath)) {
            return $defaults;
        }

        $data = json_decode((string) file_get_contents($settingsPath), true);
        if (!is_array($data)) {
            return $defaults;
        }

        return array_merge($defaults, $data);
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

        $settingsPath = self::getSettingsPath();
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
        return true;
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
        $root = defined('cRootServer_APP') ? rtrim(constant('cRootServer_APP'), '/') : __DIR__;
        return $root . self::SETTINGS_FILE;
    }
}
