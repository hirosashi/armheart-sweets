<?php
use App\Controllers\OrderController;
use App\Core\App;
use App\Core\View;
$title = '発注の管理';
$statusClass = ['draft' => 'judge-short', 'ordered' => 'judge-tight', 'delivered' => 'judge-ok', 'canceled' => 'judge-exempt'];
?>
<h1 class="page-title">発注の管理</h1>
<p class="page-lead">「必要な材料と足りない分」から追加した発注が並びます。内容を確認して発注書を印刷できます。</p>

<p class="filter-bar">
  <a class="btn btn-plain<?= $status === '' ? ' active' : '' ?>" href="<?= View::e(App::url('/orders')) ?>">すべて</a>
  <?php foreach (OrderController::STATUS_LABELS as $key => $label): ?>
    <a class="btn btn-plain<?= $status === $key ? ' active' : '' ?>"
       href="<?= View::e(App::url('/orders?status=' . $key)) ?>"><?= View::e($label) ?></a>
  <?php endforeach; ?>
</p>

<table class="table">
  <thead>
    <tr><th>発注番号</th><th>発注先</th><th>発注元</th><th>発注日</th><th>希望納期</th>
        <th class="num">品目数</th><th>状態</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td><a href="<?= View::e(App::url('/orders/show?id=' . $o['id'])) ?>"><?= View::e($o['order_no']) ?></a></td>
      <td><?= View::e($o['supplier_name']) ?></td>
      <td><?= View::e($o['company_name']) ?></td>
      <td><?= View::e(View::d($o['order_date'])) ?></td>
      <td><?= View::e(View::d($o['desired_date'])) ?></td>
      <td class="num"><?= (int)$o['item_count'] ?></td>
      <td class="<?= View::e($statusClass[$o['status']] ?? '') ?>"><?= View::e(OrderController::STATUS_LABELS[$o['status']] ?? $o['status']) ?></td>
      <td>
        <a class="btn btn-plain" href="<?= View::e(App::url('/orders/show?id=' . $o['id'])) ?>">内容</a>
        <a class="btn btn-plain" href="<?= View::e(App::url('/orders/print?id=' . $o['id'])) ?>" target="_blank">発注書</a>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if ($orders === []): ?>
    <tr><td colspan="8" class="text-center">発注はまだありません。</td></tr>
  <?php endif; ?>
  </tbody>
</table>
