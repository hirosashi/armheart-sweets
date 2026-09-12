<?php
namespace App\Core;

class View
{
    public static function render(string $view, array $data = [], bool $withLayout = true): void
    {
        $file = dirname(__DIR__) . '/Views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('画面ファイルがありません: ' . $view);
        }
        extract($data, EXTR_SKIP);
        $flash = Session::takeFlash();

        if (!$withLayout) {
            require $file;
            return;
        }
        ob_start();
        require $file;
        $content = ob_get_clean();
        require dirname(__DIR__) . '/Views/layout.php';
    }

    /** HTMLエスケープ */
    public static function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /** 日時の表示（日本時間・Clockに一本化） */
    public static function dt(?string $value): string
    {
        return Clock::dt($value);
    }

    /** 日付の表示（日本時間・Clockに一本化） */
    public static function d(?string $value): string
    {
        return Clock::d($value);
    }

    /** 数量の表示（小数の余分なゼロを消す） */
    public static function num($value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $n = (float)$value;
        if ($decimals === 0 && $n != (int)$n) {
            $decimals = 2;
        }
        return number_format($n, $decimals);
    }
}
