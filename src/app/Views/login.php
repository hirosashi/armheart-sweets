<?php
use App\Core\App;
use App\Core\Csrf;
use App\Core\View;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ログイン｜<?= View::e(App::config('app_name')) ?></title>
<link rel="stylesheet" href="<?= View::e(App::url('/assets/css/app.css')) ?>">
</head>
<body class="login-body">
<div class="login-box">
  <h1 class="login-title"><?= View::e(App::config('app_name')) ?></h1>
  <p class="login-lead">IDとパスワードを入れて、「ログイン」を押してください。</p>

  <?php foreach (($flash ?? []) as $type => $message): ?>
    <div class="alert alert-<?= View::e($type) ?>"><?= View::e($message) ?></div>
  <?php endforeach; ?>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= View::e($error) ?></div>
  <?php endif; ?>

  <form method="post" action="<?= View::e(App::url('/login')) ?>" autocomplete="off">
    <?= Csrf::field() ?>
    <label class="field">
      <span class="field-label">ID</span>
      <input class="field-input" type="text" name="login_id" value="<?= View::e($login_id ?? '') ?>" required autofocus>
    </label>
    <label class="field">
      <span class="field-label">パスワード</span>
      <input class="field-input" type="password" name="password" required>
    </label>
    <button class="btn btn-primary btn-wide" type="submit">ログイン</button>
  </form>

  <p class="login-note">
    パスワードがわからなくなったときは、管理者の方に再発行をお願いしてください。
  </p>
</div>
</body>
</html>
