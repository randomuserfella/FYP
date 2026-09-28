<?php
/**
 * config/secrets_loader.php
 * Reads a secret from (1) an environment variable, then (2) config/secrets.php.
 * Missing secrets return '' so the core app still runs; only the matching
 * integration (email, Telegram, web push) stays disabled.
 */
if (!function_exists('pt_secret')) {
    function pt_secret(string $key, string $default = ''): string {
        static $file = null;
        if ($file === null) {
            $path = __DIR__ . '/secrets.php';
            $file = is_file($path) ? (array) require $path : [];
        }
        $env = getenv($key);
        if ($env !== false && $env !== '') return $env;
        return isset($file[$key]) ? (string) $file[$key] : $default;
    }
}
