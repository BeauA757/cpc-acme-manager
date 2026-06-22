<?php
namespace Cpc\ITop\AcmeManager\Service;

class Config
{
    private static ?array $cache = null;
    private static ?int $cacheMtime = null;
    private static string $cachePath = '';

    /**
     * Load configuration with in-memory caching and filemtime validation.
     * Supports APCu/opcache when available for cross-request caching.
     */
    public static function load(string $path = '/var/opt/cert-manager/config.json'): array
    {
        $mtime = is_file($path) ? filemtime($path) : 0;

        if (self::$cache !== null && self::$cachePath === $path && self::$cacheMtime === $mtime) {
            return self::$cache;
        }

        // Cross-request cache via APCu if available (PHP 5.5+)
        if (function_exists('apcu_fetch')) {
            $apcuKey = 'cpc_acme_config_' . md5($path);
            $cached = apcu_fetch($apcuKey, $success);
            if ($success && is_array($cached) && ($cached['mtime'] ?? 0) === $mtime) {
                self::$cache = $cached['data'];
                self::$cacheMtime = $mtime;
                self::$cachePath = $path;
                return self::$cache;
            }
        }

        if (!is_file($path)) {
            throw new \RuntimeException('Missing config.json at ' . $path);
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('Unable to read config.json at ' . $path);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid config.json at ' . $path . ' (JSON decode failed: ' . json_last_error_msg() . ')');
        }

        self::$cache = $data;
        self::$cacheMtime = $mtime;
        self::$cachePath = $path;

        if (function_exists('apcu_store')) {
            apcu_store('cpc_acme_config_' . md5($path), ['mtime' => $mtime, 'data' => $data], 60);
        }

        return self::$cache;
    }

    /**
     * Clear all cached config entries (in-memory and APCu).
     */
    public static function clearCache(): void
    {
        self::$cache = null;
        self::$cacheMtime = null;
        self::$cachePath = '';
        if (function_exists('apcu_delete') && function_exists('apcu_enabled') && apcu_enabled() && class_exists('APCUIterator', false)) {
            apcu_delete(new \APCUIterator('^cpc_acme_config_'));
        }
    }

    /**
     * Get a nested config value with dot-notation and optional default.
     */
    public static function get(string $path, string $configFile = '/var/opt/cert-manager/config.json', $default = null)
    {
        $data = self::load($configFile);
        $keys = explode('.', $path);
        foreach ($keys as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return $default;
            }
            $data = $data[$key];
        }
        return $data;
    }
}
