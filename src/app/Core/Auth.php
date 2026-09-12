<?php
namespace App\Core;

class Auth
{
    public const ROLES = [
        'admin'      => '管理者',
        'production' => '製造担当',
        'purchase'   => '発注担当',
        'viewer'     => '閲覧のみ',
    ];

    /** 画面ごとに編集できる役割 */
    private const EDIT_PERMISSIONS = [
        'recipe'     => ['admin'],
        'parts'      => ['admin', 'production'],
        'progress'   => ['admin', 'production'],
        'require'    => ['admin', 'purchase'],
        'order'      => ['admin', 'purchase'],
        'stock'      => ['admin', 'purchase'],
        'material'   => ['admin', 'purchase'],
        'user'       => ['admin'],
    ];

    public static function user(): ?array
    {
        return Session::get('user');
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            Session::set('intended', $_SERVER['REQUEST_URI'] ?? null);
            Session::flash('warn', 'ログインしてください。');
            App::redirect('/login');
        }
        // 初回ログイン時などはパスワード変更を済ませるまで他の画面へ進めない
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        if (!empty(self::user()['must_change_pw']) && !str_ends_with(rtrim($path, '/'), '/password')) {
            Session::flash('warn', '最初にパスワードを変更してください。');
            App::redirect('/password');
        }
    }

    public static function can(string $area): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        return in_array($u['role'], self::EDIT_PERMISSIONS[$area] ?? [], true);
    }

    public static function roleLabel(?string $role = null): string
    {
        $role = $role ?? (self::user()['role'] ?? '');
        return self::ROLES[$role] ?? '-';
    }

    /**
     * ログインを試みる。成功なら null、失敗なら画面に出すメッセージを返す。
     */
    public static function attempt(string $loginId, string $password): ?string
    {
        $max  = (int)App::config('login_lock.max_attempts', 5);
        $lock = (int)App::config('login_lock.lock_minutes', 10);
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '';

        $failed = (int)Db::value(
            'SELECT COUNT(*) FROM login_attempts
              WHERE login_id = ? AND succeeded = 0 AND attempted_at > ?',
            [$loginId, Clock::minutesAgo($lock)]
        );
        if ($failed >= $max) {
            return "パスワードを{$max}回まちがえたため、{$lock}分間ログインできません。しばらく待ってからやり直してください。";
        }

        $user = Db::one(
            'SELECT * FROM users WHERE login_id = ? AND is_active = 1 AND deleted_at IS NULL',
            [$loginId]
        );
        $ok = $user && password_verify($password, $user['password_hash']);

        Db::exec(
            'INSERT INTO login_attempts (login_id, ip_address, succeeded, attempted_at) VALUES (?,?,?,?)',
            [$loginId, $ip, $ok ? 1 : 0, Clock::now()]
        );

        if (!$ok) {
            $rest = $max - ($failed + 1);
            $msg  = 'IDまたはパスワードがちがいます。';
            if ($rest > 0 && $rest <= 2) {
                $msg .= "（あと{$rest}回まちがえると、しばらくログインできなくなります）";
            }
            return $msg;
        }

        session_regenerate_id(true);
        Session::set('user', [
            'id'    => (int)$user['id'],
            'name'  => $user['name'],
            'role'  => $user['role'],
            'login_id' => $user['login_id'],
            'must_change_pw' => (int)$user['must_change_pw'],
        ]);
        Db::exec('UPDATE users SET last_login_at = ? WHERE id = ?', [Clock::now(), $user['id']]);
        Db::exec(
            'DELETE FROM login_attempts WHERE login_id = ? AND succeeded = 0',
            [$loginId]
        );
        OperationLog::write('login', 'users', (string)$user['id'], 'ログインしました');
        return null;
    }

    public static function logout(): void
    {
        if (self::check()) {
            OperationLog::write('logout', 'users', (string)self::id(), 'ログアウトしました');
        }
        Session::destroy();
    }
}
