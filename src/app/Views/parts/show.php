<?php
use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
$title = $part['name'] . 'の配合';
$editable = Auth::can('parts');
$materialOptions = \App\Controllers\MaterialController::options();
$total = (float)$qty_sum;
?>
<h1 class="page-title"><?= View::e($part['name']) ?>　<span class="sub">1回の仕込み（1バッチ）の配合</span></h1>
<p class="page-lead"><a href="<?= View::e(App::url('/parts')) ?>">← 部位の一覧へ</a></p>

<?php if ($editable): ?>
<form method="post" action="<?= View::e(App::url('/parts/save')) ?>" class="box">
  <?= Csrf::field() ?>
  <input type="hidden" name="id" value="<?= (int)$part['id'] ?>">
  部位名 <input type="text" name="name" value="<?= View::e($part['name']) ?>" class="w-200" required>
  歩留まり <input type="number" step="0.001" name="yield_rate" value="<?= View::e($part['yield_rate']) ?>" class="w-80">
  <label class="inline"><input type="checkbox" name="round_batch" <?= (int)$part['round_batch'] === 1 ? 'checked' : '' ?>> バッチ数を切り上げる</label>
  <label class="inline"><input type="checkbox" name="is_shared" <?= (int)$part['is_shared'] === 1 ? 'checked' : '' ?>> 複数商品で使い回す</label>
  <button class="btn">この内容で直す</button>
</form>
<?php endif; ?>

<table class="table">
  <thead>
    <tr><th>材料</th><th>メーカー</th><th class="num">1バッチの配合量</th><th class="num">割合</th><?php if ($editable): ?><th></th><?php endif; ?></tr>
  </thead>
  <tbody>
  <?php foreach ($materials as $m): ?>
    <tr>
      <td><?= View::e($m['material_name']) ?><?php if ((int)$m['is_stock_managed'] === 0): ?>
            <span class="badge">在庫管理なし</span><?php endif; ?></td>
      <td><?= View::e($m['maker_name']) ?></td>
      <td class="num"><?= View::e(View::num($m['qty'], 1)) ?><?= View::e($m['unit']) ?></td>
      <td class="num"><?= $total > 0 ? View::e(number_format((float)$m['qty'] / $total * 100, 1)) . '%' : '' ?></td>
      <?php if ($editable): ?>
      <td>
        <form method="post" action="<?= View::e(App::url('/parts/material')) ?>" class="inline-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="part_id" value="<?= (int)$part['id'] ?>">
          <input type="hidden" name="material_id" value="<?= (int)$m['material_id'] ?>">
          <input type="number" step="0.001" name="qty" value="<?= View::e($m['qty']) ?>" class="w-80">
          <button class="btn btn-small">直す</button>
          <button class="btn btn-small btn-danger" name="delete" value="1"
                  onclick="return confirm('この材料を配合から外しますか？');">外す</button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if ($materials === []): ?>
    <tr><td colspan="5" class="text-center">まだ材料が登録されていません。</td></tr>
  <?php endif; ?>
  </tbody>
  <tfoot>
    <tr><th colspan="2">1バッチの合計量</th>
        <th class="num"><?= View::e(View::num($total, 1)) ?><?= View::e($part['unit']) ?></th>
        <th class="num">100%</th><?php if ($editable): ?><th></th><?php endif; ?></tr>
  </tfoot>
</table>

<?php if ($editable): ?>
<form method="post" action="<?= View::e(App::url('/parts/material')) ?>" class="box">
  <?= Csrf::field() ?>
  <input type="hidden" name="part_id" value="<?= (int)$part['id'] ?>">
  <strong>材料を追加する</strong>
  <select name="material_id" required class="select-search">
    <option value="">-- 材料を選ぶ --</option>
    <?php foreach ($materialOptions as $o): ?>
      <option value="<?= (int)$o['id'] ?>"><?= View::e($o['name']) ?></option>
    <?php endforeach; ?>
  </select>
  配合量 <input type="number" step="0.001" name="qty" class="w-80" required> <?= View::e($part['unit']) ?>
  <button class="btn">追加する</button>
</form>
<?php endif; ?>

<h2 class="sec-title">この部位を使っている商品</h2>
<table class="table table-narrow">
  <thead><tr><th>商品名</th><th class="num">充填量</th><th class="num">取り数</th><th class="num">使う個数</th></tr></thead>
  <tbody>
  <?php foreach ($products as $p): ?>
    <tr>
      <td><a href="<?= View::e(App::url('/products/show?id=' . $p['product_id'])) ?>"><?= View::e($p['product_name']) ?></a></td>
      <td class="num"><?= View::e(View::num($p['fill_qty'], 1)) ?><?= View::e($p['fill_unit']) ?></td>
      <td class="num"><?= (int)$p['pieces_per_fill'] ?></td>
      <td class="num"><?= (int)$p['use_pieces'] ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($products === []): ?>
    <tr><td colspan="4" class="text-center">まだどの商品にも使われていません。</td></tr>
  <?php endif; ?>
  </tbody>
</table>
