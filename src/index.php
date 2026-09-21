<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Auth;
use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\MaterialController;
use App\Controllers\OrderController;
use App\Controllers\PartController;
use App\Controllers\ProductController;
use App\Controllers\ProgressController;
use App\Controllers\RequireController;
use App\Controllers\ScheduleController;
use App\Controllers\StockController;

$router = new Router();

$router->get('/login',  [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/',        [HomeController::class, 'index']);
$router->get('/menu',    [HomeController::class, 'index']);

// パスワード変更
$router->get('/password',  [AuthController::class, 'showPassword']);
$router->post('/password', [AuthController::class, 'changePassword']);

// 商品と配合
$router->get('/products',           [ProductController::class, 'index']);
$router->get('/products/show',      [ProductController::class, 'show']);
$router->get('/products/edit',      [ProductController::class, 'edit']);
$router->post('/products/save',     [ProductController::class, 'save']);
$router->post('/products/part',     [ProductController::class, 'savePart']);
$router->post('/products/material', [ProductController::class, 'saveMaterial']);

// 部位（パーツ）と配合
$router->get('/parts',           [PartController::class, 'index']);
$router->get('/parts/show',      [PartController::class, 'show']);
$router->post('/parts/save',     [PartController::class, 'save']);
$router->post('/parts/material', [PartController::class, 'saveMaterial']);

// スケジュール（週カレンダー）
$router->get('/schedule',       [ScheduleController::class, 'index']);
$router->post('/schedule/plan', [ScheduleController::class, 'savePlan']);

// 部位の進み具合
$router->get('/progress',       [ProgressController::class, 'index']);
$router->post('/progress/save', [ProgressController::class, 'save']);

// 必要な材料と足りない分
$router->get('/require',        [RequireController::class, 'index']);
$router->post('/require/plan',  [RequireController::class, 'savePlan']);
$router->post('/require/order', [RequireController::class, 'createOrders']);

// 発注
$router->get('/orders',        [OrderController::class, 'index']);
$router->get('/orders/show',   [OrderController::class, 'show']);
$router->get('/orders/print',  [OrderController::class, 'print']);
$router->post('/orders/save',  [OrderController::class, 'save']);
$router->post('/orders/status', [OrderController::class, 'updateStatus']);
$router->post('/orders/receive', [OrderController::class, 'receive']);

// 材料の在庫・棚卸し
$router->get('/stock',         [StockController::class, 'index']);
$router->get('/stock/show',    [StockController::class, 'show']);
$router->post('/stock/adjust', [StockController::class, 'adjust']);

// 材料の一覧
$router->get('/materials',       [MaterialController::class, 'index']);
$router->get('/materials/edit',  [MaterialController::class, 'edit']);
$router->post('/materials/save', [MaterialController::class, 'save']);

try {
    $router->dispatch();
} catch (Throwable $e) {
    error_log($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    App\Core\View::render('error', [
        'title'   => 'エラーが発生しました',
        'message' => App::config('debug')
            ? $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')'
            : 'システムの内部でエラーが発生しました。時間をおいてやり直してください。',
    ]);
}
