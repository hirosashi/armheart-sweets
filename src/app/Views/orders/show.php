<?php
use App\Controllers\OrderController;
use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
$title = '発注 ' . $order['order_no'];
$editable = Auth::can('order');
$next = ['draft' => 'ordered', 'ordered' => 'delivered'];
?>
<h1 class="page-title">発注 <?= View::e($order['order_no']) ?>
  <span class="badge"><?= View::e(OrderController::STATUS_LABELS[$order['status']]) ?></span></h1>
<p class="page-lead">
  <a href="<?= View::e(App::url('/orders')) ?>">← 発注の一覧へ</a>
  　<a class="btn" href="<?= View::e(App::url('/orders/print?id=' . $order['id'])) ?>" target="_blank">発注書を印刷する</a>
  <span class="note">印刷画面から「PDFに保存」も選べます。</span>
</p>

<form method="post" action="<?= View::e(App::url('/orders/save')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">

  <table class="table table-narrow">
    <tbody>
      <tr><th>発注先</th><td colspan="3"><?= View::e($order['supplier_name']) ?></td></tr>
      <tr>
        <th>発注元（自社）</th>
        <td>
          <select name="company_id" <?= $editable ? '' : 'disabled' ?>>
            <option value="">-- 選ぶ --</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)$order['company_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                <?= View::e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <th>納品場所</th>
        <td><input type="text" name="delivery_place" value="<?= View::e($order['delivery_place']) ?>" class="w-300" <?= $editable ? '' : 'readonly' ?>></td>
      </tr>
      <tr>
        <th>発注日</th><td><input type="date" name="order_date" value="<?= View::e($order['order_date']) ?>" <?= $editable ? '' : 'readonly' ?>></td>
        <th>希望納期</th><td><input type="date" name="desired_date" value="<?= View::e($order['desired_date']) ?>" <?= $editable ? '' : 'readonly' ?>></td>
      </tr>
      <tr><th>備考</th><td colspan="3"><input type="text" name="note" value="<?= View::e($order['note']) ?>" class="w-400" <?= $editable ? '' : 'readonly' ?>></td></tr>
    </tbody>
  </table>

  <h2 class="sec-title">発注する品</h2>
  <table class="table">
    <thead><tr><th>品名</th><th>材料</th><th class="num">数量</th><th>単位</th><th>備考</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i): ?>
      <tr>
        <td><input type="text" name="item_name[<?= (int)$i['id'] ?>]" value="<?= View::e($i['item_name'] ?? $i['material_name']) ?>" class="w-300" <?= $editable ? '' : 'readonly' ?>></td>
        <td><?= View::e($i['material_name']) ?></td>
        <td class="num"><input type="number" step="0.001" name="item_qty[<?= (int)$i['id'] ?>]" value="<?= View::e($i['qty']) ?>" class="w-100" <?= $editable ? '' : 'readonly' ?>></td>
        <td><?= View::e($i['unit']) ?></td>
        <td><input type="text" name="item_note[<?= (int)$i['id'] ?>]" value="<?= View::e($i['note']) ?>" class="w-200" <?= $editable ? '' : 'readonly' ?>></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?>
      <tr><td colspan="5" class="text-center">品目がありません。</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <p class="note">数量を0にして保存すると、その品を発注から外します。</p>

  <?php if ($editable): ?>
    <p><button class="btn">内容を保存する</button></p>
  <?php endif; ?>
</form>

<?php if ($editable): ?>
<div class="box">
  <strong>状態を変える</strong>
  <?php foreach (OrderController::STATUS_LABELS as $key => $label): ?>
    <?php if ($key === $order['status']) { continue; } ?>
    <form method="post" action="<?= View::e(App::url('/orders/status')) ?>" class="inline-form">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
      <input type="hidden" name="status" value="<?= View::e($key) ?>">
      <button class="btn <?= ($next[$order['status']] ?? '') === $key ? '' : 'btn-plain' ?>"><?= View::e($label) ?>にする</button>
    </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>
