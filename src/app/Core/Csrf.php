<?php
namespace App\Core;

class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verify(): void
    {
        $sent = $_POST['_token'] ?? '';
        if (!is_string($sent) || $sent === '' || !hash_equals(self::token(), $sent)) {
            http_response_code(419);
            View::render('error', [
                'title'   => '時間切れです',
                'message' => '画面を開いたまま時間が経ちすぎたため、送信できませんでした。お手数ですが、もう一度やり直してください。',
            ]);
            exit;
        }
    }
}
