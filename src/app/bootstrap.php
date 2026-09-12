<?php
declare(strict_types=1);

// クラスの自動読み込み（App\ 名前空間 → app/ フォルダ）
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$config = require dirname(__DIR__) . '/config/config.php';
App\Core\App::boot($config);
