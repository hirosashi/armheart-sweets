<?php
use App\Controllers\MaterialController;
use App\Core\App;
use App\Core\Auth;
use App\Core\View;
$title = '材料の一覧';
$editable = Auth::can('material');
?>
<h1 class="page-title">材料の一覧（登録一覧）</h1>
<p class="page-lead">登録されている原材料・資材です。全部で <?= (int)$all ?> 件あります。</p>
<?php if ($editable): ?>
  <p><a class="btn" href="<?= View::e(App::url('/materials/edit')) ?>">＋ 材料を新しく登録する</a></p>
<?php endif; ?>

<form method="get" action="<?= View::e(App::url('/materials')) ?>" class="box">
  材料名・別の呼び方・メーカーでさがす <input type="text" name="q" value="<?= View::e($keyword) ?>" class="w-200">
  <select name="kind">
    <option value="">すべての種類</option>
    <?php foreach (MaterialController::KIND_LABELS as $k => $v): ?>
      <option value="<?= View::e($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= View::e($v) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn">さがす</button>
  <span class="note">該当 <?= (int)$total ?> 件（500件まで表示）</span>
</form>

<table class="table">
  <thead>
    <tr><th>材料名</th><th>別の呼び方</th><th>種類</th><th>メーカー</th><th>仕入先</th>
        <th>在庫管理</th><th class="num">配合で使用</th>
        <?php if ($editable): ?><th></th><?php endif; ?></tr>
  </thead>
  <tbody>
  <?php foreach ($materials as $m): ?>
    <tr>
      <td><a href="<?= View::e(App::url('/stock/show?id=' . $m['id'])) ?>"><?= View::e($m['name']) ?></a>
          <?php if ((int)$m['is_supplied'] === 1): ?><span class="badge">支給品</span><?php endif; ?></td>
      <td class="small"><?= View::e($m['alias_names']) ?></td>
      <td><?= View::e(MaterialController::KIND_LABELS[$m['kind']] ?? $m['kind']) ?></td>
      <td><?= View::e($m['maker_name']) ?></td>
      <td><?= View::e($m['supplier_name']) ?></td>
      <td><?= (int)$m['is_stock_managed'] === 1 ? 'する' : 'しない' ?></td>
      <td class="num"><?= (int)$m['used_parts'] ?></td>
      <?php if ($editable): ?>
        <td><a class="btn btn-small btn-plain" href="<?= View::e(App::url('/materials/edit?id=' . $m['id'])) ?>">直す</a></td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if ($materials === []): ?>
    <tr><td colspan="<?= $editable ? 8 : 7 ?>" class="text-center">材料が見つかりませんでした。</td></tr>
  <?php endif; ?>
  </tbody>
</table>
