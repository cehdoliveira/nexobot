<?php

class BinanceConfig
{
    private const MODE_DEV = 'dev';
    private const MODE_PROD = 'prod';

    private const DEV_BASE_URL = 'https://demo-api.binance.com';
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

        try {
            $mode = AppSettings::get('binance', 'mode', $defaults['mode']);
            $devKey = AppSettings::get('binance', 'dev_api_key', $defaults['dev_api_key']);
            $devSecret = AppSettings::get('binance', 'dev_api_secret', $defaults['dev_api_secret']);
            $prodKey = AppSettings::get('binance', 'prod_api_key', $defaults['prod_api_key']);
            $prodSecret = AppSettings::get('binance', 'prod_api_secret', $defaults['prod_api_secret']);

            return array_merge($defaults, [
                'mode' => $mode,
                'dev_api_key' => $devKey,
                'dev_api_secret' => $devSecret,
                'prod_api_key' => $prodKey,
                'prod_api_secret' => $prodSecret,
            ]);
        } catch (Exception $e) {
            error_log('BinanceConfig::load DB error: ' . $e->getMessage());
            return $defaults;
        }
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

        try {
            return AppSettings::set('binance', 'mode', $settings['mode']) &&
                   AppSettings::set('binance', 'dev_api_key', $settings['dev_api_key']) &&
                   AppSettings::set('binance', 'dev_api_secret', $settings['dev_api_secret']) &&
                   AppSettings::set('binance', 'prod_api_key', $settings['prod_api_key']) &&
                   AppSettings::set('binance', 'prod_api_secret', $settings['prod_api_secret']);
        } catch (Exception $e) {
            error_log('BinanceConfig::save DB error: ' . $e->getMessage());
            return false;
        }
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
}
