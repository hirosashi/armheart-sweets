<?php
use App\Core\App;
use App\Core\Clock;
use App\Core\View;
$total = 0.0;
foreach ($items as $i) {
    $total += (float)$i['qty'];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>発注書 <?= View::e($order['order_no']) ?></title>
<link rel="stylesheet" href="<?= View::e(App::url('/assets/css/print.css')) ?>">
</head>
<body>
<div class="sheet">
  <div class="no-print toolbar">
    <button onclick="window.print();">この発注書を印刷する（PDF保存もできます）</button>
    <a href="<?= View::e(App::url('/orders/show?id=' . $order['id'])) ?>">← 内容の画面へもどる</a>
  </div>

  <h1 class="doc-title">発 注 書</h1>
  <div class="doc-head">
    <div class="doc-left">
      <div class="to"><?= View::e($order['supplier_name']) ?>　御中</div>
      <table class="head-table">
        <tr><th>希望納期</th><td><?= View::e(Clock::d($order['desired_date'])) ?></td></tr>
        <tr><th>納品場所</th><td><?= View::e($order['delivery_place']) ?></td></tr>
      </table>
      <p class="greeting">下記の通り発注申し上げます。</p>
    </div>
    <div class="doc-right">
      <table class="head-table">
        <tr><th>発注日</th><td><?= View::e(Clock::d($order['order_date'])) ?></td></tr>
        <tr><th>発注番号</th><td><?= View::e($order['order_no']) ?></td></tr>
      </table>
      <div class="from">
        <div class="from-name"><?= View::e($order['company_name']) ?></div>
        <?php if (!empty($order['company_zip'])): ?><div>〒<?= View::e($order['company_zip']) ?></div><?php endif; ?>
        <div><?= View::e($order['company_address']) ?></div>
        <div>TEL <?= View::e($order['company_tel']) ?>　FAX <?= View::e($order['company_fax']) ?></div>
      </div>
    </div>
  </div>

  <table class="items">
    <thead>
      <tr><th class="c-no">No</th><th class="c-name">商品名</th><th class="c-spec">規格</th>
          <th class="c-qty">数量</th><th class="c-unit">単位</th><th class="c-note">備考</th></tr>
    </thead>
    <tbody>
    <?php $no = 0; foreach ($items as $i): $no++; ?>
      <tr>
        <td class="c-no"><?= $no ?></td>
        <td><?= View::e($i['item_name'] ?? $i['material_name']) ?></td>
        <td><?= View::e($i['spec']) ?></td>
        <td class="c-qty"><?= View::e(View::num($i['qty'], 0)) ?></td>
        <td class="c-unit"><?= View::e($i['unit']) ?></td>
        <td><?= View::e($i['note']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php for ($r = $no; $r < 12; $r++): ?>
      <tr><td class="c-no"><?= $r + 1 ?></td><td></td><td></td><td></td><td></td><td></td></tr>
    <?php endfor; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="3">合計</th><th class="c-qty"><?= View::e(View::num($total, 0)) ?></th><th colspan="2"></th></tr>
    </tfoot>
  </table>

  <?php if (!empty($order['note'])): ?>
    <div class="remarks"><span>備考</span><?= View::e($order['note']) ?></div>
  <?php endif; ?>
</div>
</body>
</html>
