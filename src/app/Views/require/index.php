<?php
use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Csrf;
use App\Core\View;
use App\Services\Requirement;
$title = '必要な材料と足りない分';
$judgeClass = ['short' => 'judge-short', 'tight' => 'judge-tight', 'ok' => 'judge-ok', 'exempt' => 'judge-exempt'];
$showDays   = $days <= 7;   // 日ごとの列は7日までのとき出す（それ以上は合計だけ）
$q = fn(string $d) => $job !== null ? '/require?job=' . (int)$job['id'] : '/require?date=' . $d . '&days=' . $days;
?>
<h1 class="page-title">必要な材料と足りない分</h1>
<?php if ($job !== null): ?>
<p class="page-lead">発注「<?= View::e($job['customer_name'] ?: '得意先なし') ?>　<?= View::e($job['product_name']) ?> <?= (int)$job['qty'] ?>台（納品 <?= View::e(Clock::dayLabel($job['delivery_date'])) ?>）」1件ぶんの必要な材料です。
  <a href="<?= View::e(App::url('/schedule?from=' . Clock::weekStart($date))) ?>">スケジュール</a>に戻る／
  <a href="<?= View::e(App::url('/require?date=' . $date)) ?>">期間全体で見る</a></p>
<?php else: ?>
<p class="page-lead">開始日から「先読み期間」ぶんの発注（つくる予定）をまとめて、材料が何をどれだけ買えばよいかを出します。発注の登録や仕込み日の調整は
  <a href="<?= View::e(App::url('/schedule?from=' . Clock::weekStart($date))) ?>">スケジュール</a>で行います。</p>

<form method="get" action="<?= View::e(App::url('/require')) ?>" class="week-bar">
  <a class="btn btn-plain" href="<?= View::e(App::url($q($prev_date))) ?>">← 前の日</a>
  <strong><?= View::e(Clock::dayLabel($date)) ?> 〜 <?= View::e(Clock::dayLabel($to)) ?>（<?= (int)$days ?>日間）</strong>
  <a class="btn btn-plain" href="<?= View::e(App::url($q($next_date))) ?>">次の日 →</a>
  <span class="range-pick">
    開始日 <input type="date" name="date" value="<?= View::e($date) ?>">
    先読み期間
    <select name="days">
      <?php foreach ([1, 3, 5, 7, 10, 14, 21, 31] as $n): ?>
        <option value="<?= $n ?>" <?= $n === $days ? 'selected' : '' ?>><?= $n ?>日</option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-plain">表示する</button>
  </span>
</form>
<?php endif; ?>

<h2 class="sec-title" id="plan">① この期間に仕上げる発注（つくる予定）</h2>
<table class="table table-narrow">
  <thead><tr><th>仕上げ日</th><th>得意先</th><th>商品</th><th class="num">台数</th><th>納品日</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($day_list as $d): foreach ($plans_by_day[$d] ?? [] as $pl): ?>
    <tr>
      <td><?= View::e(Clock::dayLabel($d)) ?></td>
      <td><?= View::e($pl['customer_name'] ?: '-') ?></td>
      <td><?= View::e($pl['name']) ?><?= $pl['spec'] ? '（' . View::e($pl['spec']) . '）' : '' ?></td>
      <td class="num"><?= (int)$pl['qty'] ?>台</td>
      <td><?= View::e(Clock::dayLabel($pl['delivery_date'])) ?></td>
      <td><?php if ($job === null): ?><a href="<?= View::e(App::url('/require?job=' . (int)$pl['id'])) ?>">この発注だけ見る</a><?php endif; ?></td>
    </tr>
  <?php endforeach; endforeach; ?>
  <?php if ($plans_by_day === []): ?>
    <tr><td colspan="6" class="note">この期間に仕上げる発注はありません（仕込みだけの日は②に出ます）。</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<?php if (!$has_data): ?>
  <p class="alert alert-warn">この期間の発注（つくる予定）がまだありません。<a href="<?= View::e(App::url('/schedule')) ?>">スケジュール</a>で発注を追加してください。</p>
<?php else: ?>

<h2 class="sec-title" id="batch">② 仕込み（バッチ）の回数</h2>
<p class="note">1台に使う量 × 台数 ÷ 歩留まり ÷ 1バッチの合計量 を、日ごとにバッチ単位へ切り上げた回数です。期間の合計はその和です。</p>
<table class="table">
  <thead><tr><th>部位</th><th class="num">必要な量（合計）</th><th class="num">1バッチ</th>
    <?php if ($showDays): foreach ($day_list as $d): ?><th class="num small"><?= View::e(Clock::dayLabel($d)) ?></th><?php endforeach; endif; ?>
    <th class="num">仕込み回数（合計）</th></tr></thead>
  <tbody>
  <?php foreach ($parts as $pt): ?>
    <tr>
      <td><a href="<?= View::e(App::url('/parts/show?id=' . $pt['id'])) ?>"><?= View::e($pt['name']) ?></a></td>
      <td class="num"><?= View::e(View::num($pt['need_qty'], 1)) ?><?= View::e($pt['unit']) ?></td>
      <td class="num"><?= View::e(View::num($pt['batch_total_qty'], 1)) ?><?= View::e($pt['unit']) ?></td>
      <?php if ($showDays): foreach ($day_list as $d): ?>
        <td class="num"><?= isset($pt['days'][$d]) ? View::e(View::num($pt['days'][$d], 0)) : '' ?></td>
      <?php endforeach; endif; ?>
      <td class="num"><strong><?= View::e(View::num($pt['batches'], 0)) ?> 回</strong></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2 class="sec-title" id="short">③ 材料の必要量と足りない分</h2>
<p class="summary">
  <span class="judge-short">足りない <?= (int)$summary['short'] ?></span>
  <span class="judge-tight">あぶない <?= (int)$summary['tight'] ?></span>
  <span class="judge-ok">足りている <?= (int)$summary['ok'] ?></span>
  <span class="judge-exempt">対象外 <?= (int)$summary['exempt'] ?></span>
  　
  <?php if ($only): ?>
    <a href="<?= View::e(App::url($q($date))) ?>">すべて表示する</a>
  <?php else: ?>
    <a href="<?= View::e(App::url($q($date) . '&only=short')) ?>">足りない・あぶないだけ表示する</a>
  <?php endif; ?>
</p>

<form method="post" action="<?= View::e(App::url('/require/order')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="date" value="<?= View::e($date) ?>">
  <input type="hidden" name="days" value="<?= (int)$days ?>">
  <?php if ($job !== null): ?><input type="hidden" name="job" value="<?= (int)$job['id'] ?>"><?php endif; ?>
  <table class="table">
    <thead>
      <tr>
        <th class="w-30">発注</th><th>材料・資材</th><th>メーカー</th>
        <?php if ($showDays): foreach ($day_list as $d): ?><th class="num small"><?= View::e(Clock::dayLabel($d)) ?></th><?php endforeach; endif; ?>
        <th class="num">必要な量（合計）</th><th class="num">今ある量</th><th class="num">過不足</th>
        <th>判定</th><th class="num">発注数</th><th>仕入先</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($materials as $m): ?>
      <tr class="<?= View::e($judgeClass[$m['judge']]) ?>-row">
        <td class="text-center">
          <?php if ($m['order_qty'] !== null && (float)$m['order_qty'] > 0): ?>
            <input type="checkbox" name="material_id[]" value="<?= (int)$m['id'] ?>"
                   <?= $m['judge'] === 'short' ? 'checked' : '' ?>>
          <?php endif; ?>
        </td>
        <td><a href="<?= View::e(App::url('/stock/show?id=' . $m['id'])) ?>"><?= View::e($m['name']) ?></a>
            <?php if ((int)$m['is_supplied'] === 1): ?><span class="badge">支給品</span><?php endif; ?></td>
        <td><?= View::e($m['maker_name']) ?></td>
        <?php if ($showDays): foreach ($day_list as $d): ?>
          <td class="num small"><?= isset($need_by_day[(int)$m['id']][$d]) ? View::e(View::num($need_by_day[(int)$m['id']][$d], 1)) : '' ?></td>
        <?php endforeach; endif; ?>
        <td class="num"><?= View::e(View::num($m['need_qty'], 1)) ?><?= View::e($m['unit']) ?></td>
        <td class="num"><?= View::e(View::num($m['stock_qty'], 1)) ?><?= View::e($m['unit']) ?></td>
        <td class="num"><?= View::e(View::num($m['diff_qty'], 1)) ?><?= View::e($m['unit']) ?></td>
        <td class="<?= View::e($judgeClass[$m['judge']]) ?>"><?= View::e(Requirement::JUDGE_LABELS[$m['judge']]) ?></td>
        <td class="num"><?php if ($m['order_qty'] !== null && (float)$m['order_qty'] > 0): ?>
            <strong><?= View::e(View::num($m['order_qty'], 0)) ?><?= View::e($m['purchase_unit']) ?></strong>
        <?php endif; ?></td>
        <td><?= View::e($m['supplier_name']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($materials === []): ?>
      <tr><td colspan="<?= 9 + ($showDays ? $days : 0) ?>" class="text-center">表示する材料がありません。</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <?php if (Auth::can('order')): ?>
    <p><button class="btn">チェックした材料を発注（未発注）に追加する</button>
       <span class="note">仕入先ごとに1件の発注をつくります。発注には「<?= View::e(Clock::dayLabel($date)) ?>〜<?= View::e(Clock::dayLabel($to)) ?>のぶん」<?= $job !== null ? 'と、どの発注（つくる予定）のぶんか' : '' ?>が記録され、スケジュールの「材料の発注」行に出ます。</span></p>
  <?php endif; ?>
</form>
<?php endif; ?>
