<?php
/**
 * 管理者パスワードを指定値に戻し、ログイン失敗によるロックを解除する。
 * サーバ上で php tools/reset_admin_pw.php <ログインID> <パスワード> として実行する。
 */
declare(strict_types=1);

$loginId  = $argv[1] ?? 'admin';
$password = $argv[2] ?? '';
if ($password === '') {
    fwrite(STDERR, "パスワードを指定してください\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/config/config.php';
$db     = $config['db'];
$dsn    = 'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4';
$pdo    = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec("SET time_zone = '+09:00'");

$st = $pdo->prepare(
    'UPDATE users SET password_hash = ?, must_change_pw = 0, is_active = 1, updated_at = NOW() WHERE login_id = ?'
);
$st->execute([password_hash($password, PASSWORD_DEFAULT), $loginId]);
$updated = $st->rowCount();

$pdo->prepare('DELETE FROM login_attempts WHERE login_id = ?')->execute([$loginId]);

echo $updated > 0 ? "更新しました: {$loginId}\n" : "該当ユーザーがありません: {$loginId}\n";
