<?php
use App\Controllers\MaterialController;
use App\Core\App;
use App\Core\Csrf;
use App\Core\View;
$isNew = empty($material);
$title = $isNew ? '材料の新規登録' : '材料の修正';
$v = static fn(string $key) => View::e($material[$key] ?? '');
$checked = static fn(string $key, int $default) => ((int)($material[$key] ?? $default)) === 1 ? 'checked' : '';
$kind = $material['kind'] ?? 'material';
?>
<h1 class="page-title"><?= View::e($title) ?></h1>
<p class="page-lead"><a href="<?= View::e(App::url('/materials')) ?>">← 材料の一覧へ</a></p>

<form method="post" action="<?= View::e(App::url('/materials/save')) ?>" class="box form-vertical">
  <?= Csrf::field() ?>
  <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int)$material['id'] ?>"><?php endif; ?>

  <label>材料名 <span class="req">必須</span>
    <input type="text" name="name" value="<?= $v('name') ?>" maxlength="100" required class="w-400">
  </label>
  <label>別の呼び方（「/」で区切って複数可）
    <input type="text" name="alias_names" value="<?= $v('alias_names') ?>" class="w-400" placeholder="例：液全卵ロカ(未殺菌)/未殺菌液全卵">
  </label>
  <label>種類 <span class="req">必須</span>
    <select name="kind">
      <?php foreach (MaterialController::KIND_LABELS as $k => $label): ?>
        <option value="<?= View::e($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= View::e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>種別<input type="text" name="category" value="<?= $v('category') ?>" class="w-200" placeholder="例：粉類・乳製品・包材"></label>
  <label>メーカー<input type="text" name="maker_name" value="<?= $v('maker_name') ?>" class="w-300"></label>
  <label>アレルゲン<input type="text" name="allergens" value="<?= $v('allergens') ?>" class="w-300" placeholder="例：小麦・卵・乳成分"></label>
  <label>単位（在庫・配合で使う） <span class="req">必須</span>
    <input type="text" name="unit" value="<?= $isNew ? 'g' : $v('unit') ?>" maxlength="20" required class="w-80" placeholder="g／枚／個">
  </label>
  <label>業者（発注先）
    <select name="supplier_id" class="select-search">
      <option value="">-- 未設定 --</option>
      <?php foreach ($suppliers as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= (int)($material['supplier_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= View::e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>仕入単位<input type="text" name="purchase_unit" value="<?= $v('purchase_unit') ?>" class="w-100" placeholder="袋／本／ケース"></label>
  <label>仕入単位あたりの数量<input type="number" step="0.001" name="purchase_qty" value="<?= $v('purchase_qty') ?>" class="w-100" placeholder="例：25000"> <span class="note">例：1袋＝25000g なら 25000</span></label>
  <label>kg単価（円）<input type="number" step="0.01" name="price_per_kg" value="<?= $v('price_per_kg') ?>" class="w-100"></label>
  <label>単価の根拠<input type="text" name="price_source" value="<?= $v('price_source') ?>" class="w-200" placeholder="例：2026.05.25納品書"></label>

  <div class="field">
    <label class="inline"><input type="checkbox" name="is_stock_managed" <?= $checked('is_stock_managed', 1) ?>> 在庫・発注の対象にする（水などは外す）</label>
    <label class="inline"><input type="checkbox" name="has_expiry" <?= $checked('has_expiry', 1) ?>> 賞味期限を管理する</label>
    <label class="inline"><input type="checkbox" name="is_supplied" <?= $checked('is_supplied', 0) ?>> 支給品（取引先から支給される）</label>
  </div>

  <label>備考<textarea name="note" rows="3" class="w-400"><?= $v('note') ?></textarea></label>

  <div class="form-actions">
    <button class="btn">保存する</button>
    <a class="btn btn-plain" href="<?= View::e(App::url('/materials')) ?>">やめる</a>
  </div>
</form>
