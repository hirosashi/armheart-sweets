<?php
use App\Core\App;
use App\Core\Clock;
use App\Core\View;
$title = '材料の在庫';
?>
<h1 class="page-title">材料の在庫（今ある材料）</h1>
<p class="page-lead">在庫の数と、いちばん近い賞味期限を確認できます。数を直すときは材料名を押してください。<br>
  「今週使った量」は、<a href="<?= View::e(App::url('/progress?week=' . $week)) ?>">部位の進み具合</a>で「できあがり」にしたぶんから自動で引いた量です（<?= View::e(View::d($week)) ?>（月）の週）。</p>

<form method="get" action="<?= View::e(App::url('/stock')) ?>" class="box">
  材料名・メーカーでさがす <input type="text" name="q" value="<?= View::e($keyword) ?>" class="w-200">
  <select name="filter">
    <option value="">すべて</option>
    <option value="instock" <?= $filter === 'instock' ? 'selected' : '' ?>>在庫があるものだけ</option>
    <option value="expiring" <?= $filter === 'expiring' ? 'selected' : '' ?>>期限が近いものだけ</option>
  </select>
  <button class="btn">さがす</button>
  <span class="note">在庫を管理する材料は全部で <?= (int)$total ?> 件（多いときは300件まで表示）</span>
</form>

<table class="table">
  <thead>
    <tr><th>材料</th><th>メーカー</th><th class="num">在庫</th><th class="num">今週使った量</th><th>いちばん近い賞味期限</th><th class="num">仕入単位</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($materials as $m): ?>
    <?php
      $days = $m['nearest_expiry'] ? Clock::daysUntil($m['nearest_expiry']) : null;
      $rowClass = $days !== null && $days <= (int)$m['expiry_alert_days'] ? 'judge-short-row' : '';
    ?>
    <tr class="<?= View::e($rowClass) ?>">
      <td><a href="<?= View::e(App::url('/stock/show?id=' . $m['id'])) ?>"><?= View::e($m['name']) ?></a></td>
      <td><?= View::e($m['maker_name']) ?></td>
      <td class="num"><?= View::e(View::num($m['stock_qty'], 1)) ?><?= View::e($m['unit']) ?></td>
      <td class="num"><?php $u = $used[(int)$m['id']] ?? null; ?>
        <?php if ($u): ?>
          <?= View::e(View::num($u['qty'], 1)) ?><?= View::e($m['unit']) ?>
          <?php if ($u['applied_qty'] + 0.0005 < $u['qty']): ?>
            <br><span class="note judge-short">在庫不足で <?= View::e(View::num($u['qty'] - $u['applied_qty'], 1)) ?> 引けていません</span>
          <?php endif; ?>
        <?php endif; ?></td>
      <td><?= View::e(View::d($m['nearest_expiry'])) ?>
          <?php if ($days !== null): ?>
            <span class="note">（<?= $days >= 0 ? 'あと' . $days . '日' : (-$days) . '日すぎています' ?>）</span>
          <?php endif; ?></td>
      <td class="num"><?php if ($m['purchase_qty'] !== null): ?>
          <?= View::e(View::num($m['purchase_qty'], 0)) ?><?= View::e($m['unit']) ?>／<?= View::e($m['purchase_unit']) ?>
      <?php endif; ?></td>
      <td><a class="btn btn-plain" href="<?= View::e(App::url('/stock/show?id=' . $m['id'])) ?>">数を直す</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($materials === []): ?>
    <tr><td colspan="7" class="text-center">材料が見つかりませんでした。</td></tr>
  <?php endif; ?>
  </tbody>
</table>
