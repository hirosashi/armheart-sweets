<?php
// ローカル動作確認用（PHP内蔵サーバ）。実在ファイルはそのまま返し、それ以外は index.php へ。
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/src' . $path;
if ($path !== '/' && is_file($file)) {
    $types = [
        'css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
        readfile($file);
        return true;
    }
    return false;
}
require __DIR__ . '/src/index.php';
