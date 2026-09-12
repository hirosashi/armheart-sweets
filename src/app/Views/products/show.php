<?php
use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\View;
$title = $product['name'];
$editable = Auth::can('recipe');
$partOptions = Db::all('SELECT id, name, batch_total_qty FROM parts WHERE deleted_at IS NULL ORDER BY name');
$materialOptions = \App\Controllers\MaterialController::options();
?>
<h1 class="page-title"><?= View::e($product['name']) ?></h1>
<p class="page-lead">
  <a href="<?= View::e(App::url('/products')) ?>">← 商品の一覧へ</a>
  <?php if ($editable): ?>
    　<a class="btn btn-plain" href="<?= View::e(App::url('/products/edit?id=' . $product['id'])) ?>">この商品の情報を直す</a>
  <?php endif; ?>
</p>

<table class="table table-narrow">
  <tbody>
    <tr><th>販売者</th><td><?= View::e($product['seller_name']) ?></td>
        <th>規格</th><td><?= View::e($product['spec']) ?></td></tr>
    <tr><th>賞味期限</th><td><?= View::e($product['shelf_life']) ?></td>
        <th>解凍後</th><td><?= View::e($product['thaw_shelf_life']) ?></td></tr>
    <tr><th>導入予定</th><td><?= View::e(View::d($product['launch_date'])) ?></td>
        <th>数量（限定数）</th><td><?= View::e($product['plan_qty_note']) ?></td></tr>
    <tr><th>アレルゲン</th><td colspan="3"><?= View::e($product['allergens']) ?></td></tr>
    <tr><th>作成者／改訂日</th><td colspan="3"><?= View::e($product['author']) ?>　<?= View::e(View::d($product['revised_at'])) ?></td></tr>
    <?php if (!empty($product['note'])): ?>
    <tr><th>備考</th><td colspan="3"><?= nl2br(View::e($product['note'])) ?></td></tr>
    <?php endif; ?>
  </tbody>
</table>

<h2 class="sec-title">この商品に使う部位</h2>
<p class="note">「1台に使う量」＝ 充填量 ÷ 取り数 × 使う個数。この量から必要な仕込み（バッチ）の回数を計算します。</p>
<table class="table">
  <thead>
    <tr><th>部位</th><th class="num">充填量</th><th class="num">取り数</th><th class="num">使う個数</th>
        <th class="num">1台に使う量</th><th class="num">1バッチの合計量</th><th>備考</th><?php if ($editable): ?><th></th><?php endif; ?></tr>
  </thead>
  <tbody>
  <?php if ($parts === []): ?>
    <tr><td colspan="8" class="text-center">まだ部位が登録されていません。</td></tr>
  <?php endif; ?>
  <?php foreach ($parts as $pp): ?>
    <tr>
      <td><a href="<?= View::e(App::url('/parts/show?id=' . $pp['part_id'])) ?>"><?= View::e($pp['part_name']) ?></a>
          <?php if ((int)$pp['is_shared'] === 1): ?><span class="badge">共通</span><?php endif; ?></td>
      <td class="num"><?= View::e(View::num($pp['fill_qty'], 1)) ?><?= View::e($pp['fill_unit']) ?></td>
      <td class="num"><?= (int)$pp['pieces_per_fill'] ?></td>
      <td class="num"><?= (int)$pp['use_pieces'] ?></td>
      <td class="num"><strong><?= View::e(View::num($pp['per_product_qty'], 2)) ?><?= View::e($pp['fill_unit']) ?></strong></td>
      <td class="num"><?= View::e(View::num($pp['batch_total_qty'], 1)) ?><?= View::e($pp['part_unit']) ?></td>
      <td><?= View::e($pp['note']) ?></td>
      <?php if ($editable): ?>
      <td>
        <form method="post" action="<?= View::e(App::url('/products/part')) ?>" class="inline-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
          <input type="hidden" name="part_id" value="<?= (int)$pp['part_id'] ?>">
          <input type="number" step="0.001" name="fill_qty" value="<?= View::e($pp['fill_qty']) ?>" class="w-80" title="充填量">
          <input type="number" name="pieces_per_fill" value="<?= (int)$pp['pieces_per_fill'] ?>" class="w-50" title="取り数">
          <input type="number" name="use_pieces" value="<?= (int)$pp['use_pieces'] ?>" class="w-50" title="使う個数">
          <button class="btn btn-small">直す</button>
          <button class="btn btn-small btn-danger" name="delete" value="1"
                  onclick="return confirm('この部位を外しますか？');">外す</button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($editable): ?>
<form method="post" action="<?= View::e(App::url('/products/part')) ?>" class="box">
  <?= Csrf::field() ?>
  <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
  <strong>部位を追加する</strong>
  <select name="part_id" required>
    <option value="">-- 部位を選ぶ --</option>
    <?php foreach ($partOptions as $o): ?>
      <option value="<?= (int)$o['id'] ?>"><?= View::e($o['name']) ?>（1バッチ <?= View::e(View::num($o['batch_total_qty'], 1)) ?>g）</option>
    <?php endforeach; ?>
  </select>
  充填量 <input type="number" step="0.001" name="fill_qty" class="w-80" required> g
  取り数 <input type="number" name="pieces_per_fill" value="1" class="w-50">
  使う個数 <input type="number" name="use_pieces" value="1" class="w-50">
  備考 <input type="text" name="note" class="w-200">
  <button class="btn">追加する</button>
</form>
<?php endif; ?>

<h2 class="sec-title">部位を通さず、1台に直接使う材料・資材</h2>
<p class="note">クロミフェイス（1枚/台）や外箱・内箱などが対象です。</p>
<table class="table">
  <thead>
    <tr><th>材料・資材</th><th>メーカー</th><th class="num">1台に使う量</th><th>支給品</th><?php if ($editable): ?><th></th><?php endif; ?></tr>
  </thead>
  <tbody>
  <?php if ($materials === []): ?>
    <tr><td colspan="5" class="text-center">登録がありません。</td></tr>
  <?php endif; ?>
  <?php foreach ($materials as $pm): ?>
    <tr>
      <td><?= View::e($pm['material_name']) ?></td>
      <td><?= View::e($pm['maker_name']) ?></td>
      <td class="num"><?= View::e(View::num($pm['qty'], 2)) ?><?= View::e($pm['unit']) ?></td>
      <td><?= (int)$pm['is_supplied'] === 1 ? '支給' : '' ?></td>
      <?php if ($editable): ?>
      <td>
        <form method="post" action="<?= View::e(App::url('/products/material')) ?>" class="inline-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
          <input type="hidden" name="material_id" value="<?= (int)$pm['material_id'] ?>">
          <input type="number" step="0.001" name="qty" value="<?= View::e($pm['qty']) ?>" class="w-80">
          <button class="btn btn-small">直す</button>
          <button class="btn btn-small btn-danger" name="delete" value="1"
                  onclick="return confirm('この材料を外しますか？');">外す</button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($editable): ?>
<form method="post" action="<?= View::e(App::url('/products/material')) ?>" class="box">
  <?= Csrf::field() ?>
  <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
  <strong>材料・資材を追加する</strong>
  <select name="material_id" required class="select-search">
    <option value="">-- 材料を選ぶ --</option>
    <?php foreach ($materialOptions as $o): ?>
      <option value="<?= (int)$o['id'] ?>"><?= View::e($o['name']) ?></option>
    <?php endforeach; ?>
  </select>
  1台に使う量 <input type="number" step="0.001" name="qty" class="w-80" required>
  <button class="btn">追加する</button>
</form>
<?php endif; ?>
