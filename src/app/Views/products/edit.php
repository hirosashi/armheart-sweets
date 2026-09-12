<?php
use App\Core\App;
use App\Core\Csrf;
use App\Core\View;
$isNew = empty($product);
$title = $isNew ? '商品の新規登録' : '商品の修正';
$v = static fn(string $key) => View::e($product[$key] ?? '');
?>
<h1 class="page-title"><?= View::e($title) ?></h1>
<p class="page-lead"><a href="<?= View::e(App::url('/products')) ?>">← 商品の一覧へ</a></p>

<form method="post" action="<?= View::e(App::url('/products/save')) ?>" class="box form-vertical">
  <?= Csrf::field() ?>
  <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int)$product['id'] ?>"><?php endif; ?>

  <label>商品名 <span class="req">必須</span>
    <input type="text" name="name" value="<?= $v('name') ?>" maxlength="100" required class="w-400">
  </label>
  <label>販売者<input type="text" name="seller_name" value="<?= $v('seller_name') ?>" class="w-300"></label>
  <label>規格・形状<input type="text" name="spec" value="<?= $v('spec') ?>" class="w-200" placeholder="例：４号ホール"></label>
  <label>賞味期限<input type="text" name="shelf_life" value="<?= $v('shelf_life') ?>" class="w-200"></label>
  <label>解凍後の消費期限<input type="text" name="thaw_shelf_life" value="<?= $v('thaw_shelf_life') ?>" class="w-200"></label>
  <label>導入予定日<input type="date" name="launch_date" value="<?= $v('launch_date') ?>"></label>
  <label>数量（限定数）<input type="text" name="plan_qty_note" value="<?= $v('plan_qty_note') ?>" class="w-200" placeholder="例：3000～8000台"></label>
  <label>1ケース入り数<input type="number" name="case_qty" value="<?= $v('case_qty') ?>" class="w-80"></label>
  <label>含有アレルゲン<input type="text" name="allergens" value="<?= $v('allergens') ?>" class="w-400"></label>
  <label>作成者<input type="text" name="author" value="<?= $v('author') ?>" class="w-200"></label>
  <label>改訂日<input type="date" name="revised_at" value="<?= $v('revised_at') ?>"></label>
  <label>備考<textarea name="note" rows="3" class="w-400"><?= $v('note') ?></textarea></label>

  <div class="form-actions">
    <button class="btn">保存する</button>
    <a class="btn btn-plain" href="<?= View::e(App::url($isNew ? '/products' : '/products/show?id=' . $product['id'])) ?>">やめる</a>
  </div>
</form>
