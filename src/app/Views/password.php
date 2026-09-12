<?php
use App\Core\App;
use App\Core\Csrf;
use App\Core\View;
$title = 'パスワードの変更';
?>
<h1 class="page-title">パスワードの変更</h1>
<p class="page-lead">新しいパスワードは、8文字以上で決めてください。</p>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error">
    <?php foreach ($errors as $e): ?><div><?= View::e($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" action="<?= View::e(App::url('/password')) ?>" class="form-narrow" autocomplete="off">
  <?= Csrf::field() ?>
  <label class="field">
    <span class="field-label">今のパスワード</span>
    <input class="field-input" type="password" name="current_password" required>
  </label>
  <label class="field">
    <span class="field-label">新しいパスワード</span>
    <input class="field-input" type="password" name="new_password" required>
  </label>
  <label class="field">
    <span class="field-label">新しいパスワード（確認）</span>
    <input class="field-input" type="password" name="confirm_password" required>
  </label>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">変更する</button>
    <a class="btn btn-plain" href="<?= View::e(App::url('/')) ?>">やめる</a>
  </div>
</form>
