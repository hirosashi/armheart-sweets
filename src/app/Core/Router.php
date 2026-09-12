<?php
namespace App\Core;

class Router
{
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'POST' : 'GET';
        $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

        $base = rtrim((string)App::config('base_path', ''), '/');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . trim($uri, '/');

        $handler = $this->routes[$method][$uri] ?? null;
        if ($handler === null) {
            http_response_code(404);
            View::render('error', [
                'title'   => 'ページが見つかりません',
                'message' => 'お探しの画面はありませんでした。メニューから選びなおしてください。',
            ]);
            return;
        }
        $handler();
    }
}
