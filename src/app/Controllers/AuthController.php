<?php
namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\OperationLog;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;

class AuthController
{
    public static function showLogin(): void
    {
        if (Auth::check()) {
            App::redirect('/');
        }
        View::render('login', ['login_id' => ''], false);
    }

    public static function login(): void
    {
        Csrf::verify();
        $loginId  = trim((string)($_POST['login_id'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($loginId === '' || $password === '') {
            View::render('login', [
                'login_id' => $loginId,
                'error'    => 'IDとパスワードの両方を入力してください。',
            ], false);
            return;
        }

        $error = Auth::attempt($loginId, $password);
        if ($error !== null) {
            View::render('login', ['login_id' => $loginId, 'error' => $error], false);
            return;
        }

        if (!empty(Auth::user()['must_change_pw'])) {
            Session::flash('warn', '最初にパスワードを変更してください。');
            App::redirect('/password');
        }

        $intended = Session::get('intended');
        Session::set('intended', null);
        Session::flash('info', Auth::user()['name'] . ' さん、ようこそ。');
        if (is_string($intended) && $intended !== '') {
            header('Location: ' . $intended);
            exit;
        }
        App::redirect('/');
    }

    public static function logout(): void
    {
        Auth::logout();
        session_start();
        Session::flash('info', 'ログアウトしました。');
        App::redirect('/login');
    }

    public static function showPassword(): void
    {
        Auth::requireLogin();
        View::render('password');
    }

    public static function changePassword(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $errors = [];
        $user = Db::one('SELECT * FROM users WHERE id = ?', [Auth::id()]);
        if (!$user || !password_verify($current, $user['password_hash'])) {
            $errors[] = '今のパスワードがちがいます。';
        }
        if (mb_strlen($new) < 8) {
            $errors[] = '新しいパスワードは8文字以上にしてください。';
        }
        if ($new !== $confirm) {
            $errors[] = '新しいパスワードと確認用の入力が一致しません。';
        }
        if ($errors !== []) {
            View::render('password', ['errors' => $errors]);
            return;
        }

        Db::exec(
            'UPDATE users SET password_hash = ?, must_change_pw = 0, updated_by = ?, updated_at = ? WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), Auth::id(), Clock::now(), Auth::id()]
        );
        OperationLog::write('update', 'users', (string)Auth::id(), 'パスワードを変更しました');

        $u = Auth::user();
        $u['must_change_pw'] = 0;
        Session::set('user', $u);

        Session::flash('info', 'パスワードを変更しました。');
        App::redirect('/');
    }
}
