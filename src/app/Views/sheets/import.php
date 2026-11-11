<?php
use App\Core\App;
use App\Core\Csrf;
use App\Core\View;
$title = 'Excelから取り込む';
$n = static fn($v) => $v === null ? '' : View::num($v);
?>
<div class="easy-band">かんたん画面（Excelと同じ並び）　<a href="<?= View::e(App::url('/sheets')) ?>">原材料発注一覧へ戻る</a></div>
<h1 class="page-title">Excelから取り込む（確認）</h1>
<p class="page-lead">「<?= View::e($imp['file']) ?>」のシート「<?= View::e($imp['sheet']) ?>」を読みました。まだ何も登録していません。内容を確かめて、下の「取り込む」を押してください。</p>

<?php if ($plan['warn'] !== []): ?>
<div class="alert alert-warn">
  確かめてほしいこと
  <ul><?php foreach ($plan['warn'] as $w): ?><li><?= View::e($w) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<h2 class="sec-title">部位と原材料</h2>
<table class="table">
  <thead><tr><th>部位</th><th>原材料</th><th class="num">1B配合量</th><th>発注先</th><th>荷姿</th><th class="num">発注単位</th><th>保管配送</th><th class="num">発注</th></tr></thead>
  <tbody>
  <?php foreach ($imp['parts'] as $pi => $part): ?>
    <?php foreach ($part['rows'] as $ri => $r): ?>
    <tr>
      <?php if ($ri === 0): ?>
      <td rowspan="<?= count($part['rows']) ?>">
        <?= View::e($part['name']) ?><?= $plan['parts'][$pi]['exists'] ? '' : ' <span class="note">（新しく登録）</span>' ?><br>
        <span class="note">1Bでできる数 <?= View::e($n($part['pieces'])) ?>／<?= (int)$plan['parts'][$pi]['batches'] ?>B</span>
      </td>
      <?php endif; ?>
      <td><?= View::e($r['name']) ?><?= in_array($r['name'], $plan['new_materials'], true) ? ' <span class="note">（新しく登録）</span>' : '' ?></td>
      <td class="num"><?= View::e($n($r['batch'])) ?></td>
      <td><?= View::e($r['vendor']) ?></td>
      <td><?= View::e($r['unit']) ?></td>
      <td class="num"><?= View::e($n($r['per'])) ?></td>
      <td><?= View::e($r['storage']) ?></td>
      <td class="num">
        <?php foreach ($r['orders'] as $o): ?>
          <?= View::e($n($o['qty'])) ?><?= $o['dd'] ? '（' . View::e(\App\Core\Clock::d($o['dd'])) . '納品）' : '' ?><?= $o['done'] ? ' 済' : '' ?><br>
        <?php endforeach; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($imp['packs'] !== []): ?>
<h2 class="sec-title">資材関連</h2>
<table class="table">
  <thead><tr><th>資材</th><th>発注先</th><th>使用用途</th><th class="num">使用数</th><th class="num">発注</th></tr></thead>
  <tbody>
  <?php foreach ($imp['packs'] as $r): ?>
    <tr>
      <td><?= View::e($r['name']) ?><?= in_array($r['name'], $plan['new_materials'], true) ? ' <span class="note">（新しく登録）</span>' : '' ?></td>
      <td><?= View::e($r['vendor']) ?></td>
      <td><?= View::e($r['purpose']) ?></td>
      <td class="num"><?= View::e($n($r['use'])) ?></td>
      <td class="num">
        <?php foreach ($r['orders'] as $o): ?>
          <?= View::e($n($o['qty'])) ?><?= $o['dd'] ? '（' . View::e(\App\Core\Clock::d($o['dd'])) . '納品）' : '' ?><?= $o['done'] ? ' 済' : '' ?><br>
        <?php endforeach; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($plan['stocks'] !== []): ?>
<h2 class="sec-title">在庫（<?= View::e($imp['stock_label'] ?: '在庫') ?>）</h2>
<table class="table">
  <thead><tr><th>材料</th><th>Excelの在庫</th><th class="num">今の在庫</th><th class="num">取り込んだあと</th><th>単位</th></tr></thead>
  <tbody>
  <?php foreach ($plan['stocks'] as $s): ?>
    <tr><td><?= View::e($s['name']) ?></td><td><?= View::e($s['text']) ?></td>
        <td class="num"><?= View::e($n($s['now'])) ?></td><td class="num"><?= View::e($n($s['after'])) ?></td><td><?= View::e($s['unit']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($canEdit): ?>
<h2 class="sec-title">取り込む</h2>
<form method="post" action="<?= View::e(App::url('/sheets/import')) ?>" class="stack-form">
  <?= Csrf::field() ?>
  <p>商品
    <select name="product_id" class="select-search w-300">
      <option value="0">-- 新しい商品として登録 --</option>
      <?php foreach ($products as $p): ?>
        <option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $match ? ' selected' : '' ?>><?= View::e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    新しい商品名 <input type="text" name="product_name" value="<?= $match ? '' : View::e($imp['product_name']) ?>" class="w-300" maxlength="200">
  </p>
  <p>製造数 <input type="number" name="qty" min="0" step="1" class="w-100" value="<?= (int)$imp['qty'] ?>"></p>
  <?php if ($plan['stocks'] !== []): ?>
  <p><label><input type="checkbox" name="with_stock" value="1" checked> 在庫もExcelの数に合わせる（棚卸しとして記録します）</label></p>
  <?php endif; ?>
  <p class="note">原材料・部位は、名前が同じものがあればそれを使います（発注先・保管配送・荷姿はExcelの値で更新）。ほかの商品で使っている部位は「部位名（商品名）」として別に作ります。発注 <?= (int)$plan['orders'] ?> 件を新しく作ります。</p>
  <button class="btn">取り込む</button>
  <a class="btn btn-sub" href="<?= View::e(App::url('/sheets')) ?>">やめる</a>
</form>
<?php endif; ?>
