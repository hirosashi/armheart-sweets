<?php
// 環境ごとの設定サンプル。実際は config.local.php / config.sakura.php を作成する。
return [
    'env'       => 'sakura',
    'base_path' => '/armheart.com',
    'app_name'  => 'スイーツ生産管理システム',
    'db' => [
        'host'     => 'localhost',
        'name'     => 'database_name',
        'user'     => 'database_user',
        'pass'     => 'database_password',
        'charset'  => 'utf8mb4',
    ],
    'session_lifetime_min' => 60,
    'login_lock' => ['max_attempts' => 5, 'lock_minutes' => 10],
    'debug' => false,
];
