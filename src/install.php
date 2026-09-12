<?php
/**
 * 初回のみ使うDB構築ページ。
 * ブラウザから「install.php?key=（下のINSTALL_KEY）」で開く。
 * 構築が終わったら、このファイルはサーバから削除する。
 */
declare(strict_types=1);

const INSTALL_KEY = 'sweets-setup-2026';

require __DIR__ . '/app/bootstrap.php';

use App\Core\Db;

if (($_GET['key'] ?? '') !== INSTALL_KEY) {
    http_response_code(403);
    exit('key がちがいます。');
}

header('Content-Type: text/html; charset=UTF-8');
$messages = [];
$errors   = [];

function runSqlFile(string $path, array &$messages): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('SQLファイルが読めません: ' . $path);
    }
    // コメント行を除去してから「;」で分割する
    $sql   = str_replace(["\r\n", "\r"], "\n", $sql);
    $lines = [];
    foreach (explode("\n", $sql) as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $lines[] = $line;
    }
    $pdo = Db::conn();
    foreach (array_filter(array_map('trim', explode(';', implode("\n", $lines)))) as $stmt) {
        $pdo->exec($stmt);
    }
    $messages[] = basename($path) . ' を実行しました。';
}

$action = $_GET['action'] ?? '';

try {
    $tables = Db::all('SHOW TABLES');
    $tableCount = count($tables);

    if ($action === 'schema') {
        if ($tableCount > 0) {
            $errors[] = 'すでにテーブルがあるため中止しました。作り直す場合は action=reset を使ってください。';
        } else {
            runSqlFile(__DIR__ . '/db/schema.sql', $messages);
        }
    } elseif ($action === 'reset') {
        $pdo = Db::conn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            $pdo->exec('DROP TABLE IF EXISTS `' . array_values($t)[0] . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $messages[] = '既存のテーブルを削除しました。';
        runSqlFile(__DIR__ . '/db/schema.sql', $messages);
    } elseif ($action === 'admin') {
        $loginId  = trim((string)($_GET['id'] ?? 'admin'));
        $password = (string)($_GET['pw'] ?? '');
        if (mb_strlen($password) < 8) {
            $errors[] = 'pw は8文字以上を指定してください。例: install.php?key=...&action=admin&id=admin&pw=xxxxxxxx';
        } else {
            $exists = Db::value('SELECT id FROM users WHERE login_id = ?', [$loginId]);
            if ($exists) {
                Db::exec('UPDATE users SET password_hash = ?, role = ?, is_active = 1, must_change_pw = 1, deleted_at = NULL WHERE id = ?',
                    [password_hash($password, PASSWORD_DEFAULT), 'admin', $exists]);
                Db::exec('DELETE FROM login_attempts WHERE login_id = ?', [$loginId]);
                $messages[] = "管理者「{$loginId}」のパスワードを設定しなおしました。最初のログイン後にパスワード変更を求めます。";
            } else {
                Db::exec('INSERT INTO users (login_id, password_hash, name, role, must_change_pw) VALUES (?,?,?,?,1)',
                    [$loginId, password_hash($password, PASSWORD_DEFAULT), '管理者', 'admin']);
                $messages[] = "管理者「{$loginId}」を登録しました。最初のログイン後にパスワード変更を求めます。";
            }
        }
    } elseif ($action === 'real') {
        runSqlFile(__DIR__ . '/db/seed_real.sql', $messages);
    }

    $tables = Db::all('SHOW TABLES');
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head><meta charset="UTF-8"><title>初期設定</title>
<link rel="stylesheet" href="assets/css/app.css"></head>
<body>
<div class="content">
  <h1 class="page-title">初期設定</h1>
  <?php foreach ($errors as $m): ?><div class="alert alert-error"><?= htmlspecialchars($m) ?></div><?php endforeach; ?>
  <?php foreach ($messages as $m): ?><div class="alert alert-info"><?= htmlspecialchars($m) ?></div><?php endforeach; ?>

  <h2 class="sec-title">今のテーブル（<?= count($tables ?? []) ?>本）</h2>
  <p class="note"><?= htmlspecialchars(implode(', ', array_map(fn($t) => array_values($t)[0], $tables ?? []))) ?></p>

  <h2 class="sec-title">できる操作</h2>
  <ul>
    <li><a href="?key=<?= INSTALL_KEY ?>&action=schema">テーブルを作る</a></li>
    <li><a href="?key=<?= INSTALL_KEY ?>&action=reset" onclick="return confirm('すべてのテーブルを削除して作り直します。よろしいですか？')">作り直す（データは消えます）</a></li>
    <li><a href="?key=<?= INSTALL_KEY ?>&action=real">いただいた資料のデータを入れる（原材料・部位・商品）</a></li>
    <li>管理者を登録：<code>install.php?key=<?= INSTALL_KEY ?>&amp;action=admin&amp;id=admin&amp;pw=（8文字以上）</code></li>
  </ul>
  <p class="note">※ 設定が終わったら、このファイル（install.php）はサーバから削除してください。</p>
</div>
</body>
</html>
