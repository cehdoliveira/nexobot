<?php
class BinanceRateLimitGuard {
    private const REDIS_KEY = 'binance:backoff_until';

    public static function isInBackoff(): bool {
        $redis = RedisCache::getInstance();
        $until = $redis->get(self::REDIS_KEY);
        return $until !== false && time() < (int)$until;
    }

    public static function recordRateLimit(int $retryAfterSeconds = 60): void {
        $redis = RedisCache::getInstance();
        $until = time() + $retryAfterSeconds;
        $redis->set(self::REDIS_KEY, $until, $retryAfterSeconds + 10);
    }
}
