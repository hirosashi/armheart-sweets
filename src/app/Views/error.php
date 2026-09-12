<?php
use App\Core\App;
use App\Core\Auth;
use App\Core\View;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title ?? 'エラー') ?></title>
<link rel="stylesheet" href="<?= View::e(App::url('/assets/css/app.css')) ?>">
</head>
<body class="login-body">
<div class="login-box">
  <h1 class="login-title"><?= View::e($title ?? 'エラー') ?></h1>
  <p class="page-lead"><?= View::e($message ?? '') ?></p>
  <a class="btn btn-primary btn-wide" href="<?= View::e(App::url(Auth::check() ? '/' : '/login')) ?>">
    <?= Auth::check() ? 'ホームへもどる' : 'ログイン画面へ' ?>
  </a>
</div>
</body>
</html>
