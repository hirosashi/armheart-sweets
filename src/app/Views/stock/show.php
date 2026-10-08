<?php
use App\Controllers\StockController;
use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Csrf;
use App\Core\View;
use App\Services\Allocation;
$title = $material['name'] . 'の在庫';
$editable = Auth::can('stock');
$stockSum = array_sum(array_map(static fn($l) => (float)$l['qty'], $lots));
?>
<h1 class="page-title"><?= View::e($material['name']) ?></h1>
<p class="page-lead"><a href="<?= View::e(App::url('/stock')) ?>">← 在庫の一覧へ</a></p>

<table class="table table-narrow">
  <tbody>
    <tr><th>メーカー</th><td><?= View::e($material['maker_name']) ?></td>
        <th>単位</th><td><?= View::e($material['unit']) ?></td></tr>
    <tr><th>荷姿</th><td><?php if ($material['purchase_qty'] !== null): ?>
            <?= View::e(View::num($material['purchase_qty'], 0)) ?><?= View::e($material['unit']) ?>／<?= View::e($material['purchase_unit']) ?>
        <?php endif; ?></td>
        <th>アレルゲン</th><td><?= View::e($material['allergens']) ?></td></tr>
    <tr><th>今ある量（合計）</th><td colspan="3"><strong><?= View::e(View::num($stockSum, 1)) ?><?= View::e($material['unit']) ?></strong></td></tr>
  </tbody>
</table>

<h2 class="sec-title" id="plan">これからの見込み（今ある量 ＋ 納品予定 − 使う予定）</h2>
<?php if ((int)$material['is_stock_managed'] === 0): ?>
  <p class="note">この材料は在庫管理なしです。</p>
<?php elseif ($proj['needs'] === [] && $proj['incoming'] === []): ?>
  <p class="note">進行中の発注で使う予定も、納品待ちの材料の発注もありません。</p>
<?php else: ?>
<table class="table table-narrow proj">
  <thead><tr><th>日</th><th class="num">納品予定</th><th class="num">使う予定</th><th class="num">残りの見込み</th></tr></thead>
  <tbody>
  <?php foreach ($proj['days'] as $i => $d): ?>
    <?php if ($i > 0 && $d['in'] == 0 && $d['use'] == 0) { continue; } ?>
    <tr>
      <td><?= View::e(Clock::dayLabel($d['date'])) ?><?= $i === 0 ? '（今日。過ぎた予定も含む）' : '' ?></td>
      <td class="num"><?= $d['in'] > 0 ? View::e(View::num($d['in'], 1)) : '' ?></td>
      <td class="num"><?= $d['use'] > 0 ? View::e(View::num($d['use'], 1)) : '' ?></td>
      <td class="num<?= $d['balance'] < 0 ? ' minus' : '' ?>"><?= View::e(View::num($d['balance'], 1)) ?><?= View::e($material['unit']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($proj['later']['in'] > 0 || $proj['later']['use'] > 0): ?>
    <tr><td>それより後</td><td class="num"><?= View::e(View::num($proj['later']['in'], 1)) ?></td>
      <td class="num"><?= View::e(View::num($proj['later']['use'], 1)) ?></td><td></td></tr>
  <?php endif; ?>
  </tbody>
</table>

<h3 class="sec-title">使う予定の割り当て（納品日の早い発注から順）</h3>
<table class="table">
  <thead><tr><th>発注</th><th>商品・部位</th><th>使う日</th><th class="num">使う量</th><th class="num">在庫から</th><th class="num">納品待ちから</th><th class="num">足りない分</th><th>判定</th></tr></thead>
  <tbody>
  <?php foreach ($proj['needs'] as $k => $n): ?>
    <tr>
      <td><a href="<?= View::e(App::url('/schedule?job=' . (int)$n['job_id'])) ?>"><?= View::e($uses[$k]['job']) ?></a></td>
      <td><?= View::e($uses[$k]['what']) ?></td>
      <td><?= View::e(Clock::dayLabel($n['use_date'])) ?></td>
      <td class="num"><?= View::e(View::num($n['qty'], 1)) ?></td>
      <td class="num"><?= View::e(View::num($n['from_stock'], 1)) ?></td>
      <td class="num"><?= $n['from_po'] > 0 ? View::e(View::num($n['from_po'], 1)) . '（' . View::e(Clock::dayLabel((string)$n['arrival'])) . '）' : '' ?></td>
      <td class="num<?= $n['short'] > 0 ? ' judge-short' : '' ?>"><?= $n['short'] > 0 ? View::e(View::num($n['short'], 1)) : '' ?></td>
      <td><?= View::e(Allocation::JUDGE_LABELS[$n['judge']]) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if ($proj['incoming'] !== []): ?>
<p class="note">納品待ち：
  <?php foreach ($proj['incoming'] as $r): ?>
    <a href="<?= View::e(App::url('/orders/show?id=' . (int)$r['order_id'])) ?>"><?= View::e($r['order_no']) ?></a>
    <?= View::e(View::num($r['qty'], 1)) ?><?= View::e($material['unit']) ?>（<?= View::e($r['arrival'] !== null ? Clock::dayLabel($r['arrival']) : '納品日未定') ?>）
  <?php endforeach; ?>
  ／<a href="<?= View::e(App::url('/require#short')) ?>">足りない材料を発注する</a></p>
<?php else: ?>
<p class="note"><a href="<?= View::e(App::url('/require#short')) ?>">足りない材料を発注する</a></p>
<?php endif; ?>
<?php endif; ?>

<h2 class="sec-title">在庫の内訳</h2>
<table class="table">
  <thead><tr><th>ロット</th><th class="num">数量</th><th>賞味期限</th><th>保管場所</th><?php if ($editable): ?><th>数を直す（棚卸し）</th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($lots as $l): ?>
    <tr>
      <td><?= View::e($l['lot_no']) ?></td>
      <td class="num"><?= View::e(View::num($l['qty'], 1)) ?><?= View::e($material['unit']) ?></td>
      <td><?= View::e(View::d($l['expiry_date'])) ?></td>
      <td><?= View::e($l['location']) ?></td>
      <?php if ($editable): ?>
      <td>
        <form method="post" action="<?= View::e(App::url('/stock/adjust')) ?>" class="inline-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="material_id" value="<?= (int)$material['id'] ?>">
          <input type="hidden" name="inventory_id" value="<?= (int)$l['id'] ?>">
          <input type="number" step="0.001" name="after_qty" value="<?= View::e($l['qty']) ?>" class="w-100" required>
          <input type="date" name="expiry_date" value="<?= View::e($l['expiry_date']) ?>">
          <input type="text" name="location" value="<?= View::e($l['location']) ?>" class="w-100" placeholder="保管場所">
          <select name="reason">
            <?php foreach (StockController::REASONS as $k => $v): ?>
              <option value="<?= View::e($k) ?>"><?= View::e($v) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-small">直す</button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if ($lots === []): ?>
    <tr><td colspan="5" class="text-center">在庫の登録がありません。</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<?php if ($editable): ?>
<form method="post" action="<?= View::e(App::url('/stock/adjust')) ?>" class="box">
  <?= Csrf::field() ?>
  <input type="hidden" name="material_id" value="<?= (int)$material['id'] ?>">
  <strong>在庫を新しく登録する</strong>
  数量 <input type="number" step="0.001" name="after_qty" class="w-100" required> <?= View::e($material['unit']) ?>
  賞味期限 <input type="date" name="expiry_date">
  ロット <input type="text" name="lot_no" class="w-100">
  保管場所 <input type="text" name="location" class="w-100">
  理由 <select name="reason">
    <?php foreach (StockController::REASONS as $k => $v): ?>
      <option value="<?= View::e($k) ?>"><?= View::e($v) ?></option>
    <?php endforeach; ?>
  </select>
  メモ <input type="text" name="note" class="w-200">
  <button class="btn">登録する</button>
</form>
<?php endif; ?>

<h2 class="sec-title">製造で使った内訳</h2>
<p class="note">部位の進み具合で「できあがり」にしたときに、回数 × 1バッチの配合量 で自動で引いた量です。</p>
<table class="table table-narrow">
  <thead><tr><th>日</th><th>部位</th><th class="num">できた回数</th><th class="num">使った量</th><th class="num">在庫から引いた量</th><th>日時</th></tr></thead>
  <tbody>
  <?php foreach ($consumed as $c): ?>
    <tr>
      <td><a href="<?= View::e(App::url('/progress?date=' . $c['target_date'])) ?>"><?= View::e(View::d($c['target_date'])) ?></a></td>
      <td><?= View::e($c['part_name']) ?></td>
      <td class="num"><?= View::e(View::num($c['batches'], 1)) ?> 回</td>
      <td class="num"><?= View::e(View::num($c['qty'], 1)) ?><?= View::e($material['unit']) ?></td>
      <td class="num"><?= View::e(View::num($c['applied_qty'], 1)) ?><?= View::e($material['unit']) ?>
        <?php if ((float)$c['applied_qty'] + 0.0005 < (float)$c['qty']): ?>
          <span class="note judge-short">（在庫不足）</span>
        <?php endif; ?></td>
      <td><?= View::e(View::dt($c['created_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($consumed === []): ?>
    <tr><td colspan="6" class="text-center">まだ製造での使用はありません。</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<h2 class="sec-title">直した記録</h2>
<table class="table table-narrow">
  <thead><tr><th>日時</th><th>理由</th><th class="num">前</th><th class="num">後</th><th>メモ</th><th>担当</th></tr></thead>
  <tbody>
  <?php foreach ($history as $h): ?>
    <tr>
      <td><?= View::e(View::dt($h['created_at'])) ?></td>
      <td><?= View::e(StockController::REASONS[$h['reason']] ?? $h['reason']) ?></td>
      <td class="num"><?= $h['before_qty'] === null ? '' : View::e(View::num($h['before_qty'], 1)) ?></td>
      <td class="num"><?= View::e(View::num($h['after_qty'], 1)) ?></td>
      <td><?= View::e($h['note']) ?></td>
      <td><?= View::e($h['user_name']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($history === []): ?>
    <tr><td colspan="6" class="text-center">まだ記録はありません。</td></tr>
  <?php endif; ?>
  </tbody>
</table>
