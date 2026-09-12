<?php
// 実行環境を自動判定して設定を読み込む。
$host = $_SERVER['HTTP_HOST'] ?? php_uname('n');
$file = str_contains($host, 'sakura') ? __DIR__ . '/config.sakura.php' : __DIR__ . '/config.local.php';
if (!is_file($file)) {
    http_response_code(500);
    exit('設定ファイルがありません: ' . basename($file));
}
return require $file;
