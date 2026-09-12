<?php
namespace App\Core;

class Session
{
    public static function start(int $lifetimeMinutes): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => !empty($_SERVER['HTTPS']),
            'samesite' => 'Lax',
        ]);
        session_name('SWEETSPM');
        session_start();

        // 無操作が続いた場合は破棄する
        $limit = $lifetimeMinutes * 60;
        if (isset($_SESSION['last_activity']) && (Clock::timestamp() - $_SESSION['last_activity']) > $limit) {
            self::destroy();
            session_start();
            $_SESSION['flash']['warn'] = '一定時間操作がなかったため、自動的にログアウトしました。';
        }
        $_SESSION['last_activity'] = Clock::timestamp();
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', Clock::timestamp() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][$type] = $message;
    }

    public static function takeFlash(): array
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $flash;
    }
}
