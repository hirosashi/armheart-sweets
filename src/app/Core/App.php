<?php
namespace App\Core;

class App
{
    private static array $config = [];

    public static function boot(array $config): void
    {
        self::$config = $config;
        Clock::init();
        mb_internal_encoding('UTF-8');

        if (!empty($config['debug'])) {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        }
        ini_set('log_errors', '1');
        ini_set('error_log', dirname(__DIR__, 2) . '/storage/logs/php_error.log');

        Session::start((int)($config['session_lifetime_min'] ?? 60));
    }

    public static function config(string $key, $default = null)
    {
        $value = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** 画面のURLを組み立てる */
    public static function url(string $path = ''): string
    {
        $base = rtrim((string)self::config('base_path', ''), '/');
        $path = '/' . ltrim($path, '/');
        return $base . ($path === '/' ? '/' : $path);
    }

    public static function redirect(string $path): void
    {
        header('Location: ' . self::url($path));
        exit;
    }
}
